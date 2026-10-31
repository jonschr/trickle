<?php
defined('ABSPATH') || exit;

/** Immutable gzip streams with bounded, checksummed HTTP segments and durable offsets. */
final class CSM_Segments {
    public const SIZE = 524288;

    private static function path(string $id, string $kind, string $suffix): string {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || !in_array($kind, ['files', 'database'], true)) { throw new RuntimeException('Invalid segmented transfer.'); }
        return CSM_Support::private_dir() . '/segment-' . $id . '-' . $kind . $suffix;
    }

    private static function write_json(string $path, array $value): void {
        $raw = CSM_Support::json($value); $temp = $path . '.tmp';
        if (file_put_contents($temp, $raw, LOCK_EX) !== strlen($raw)) { throw new RuntimeException('Could not save transfer checkpoint.'); }
        chmod($temp, 0600); if (!rename($temp, $path)) { throw new RuntimeException('Could not save transfer checkpoint.'); }
    }

    public static function bundle(string $id, string $kind, array $selection): array {
        $path = self::path($id, $kind, '.source'); $meta = $path . '.json'; $digest = hash('sha256', CSM_Support::json($selection));
        $lock = fopen($path . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Source stream is still being built. Retry preparation shortly.'); }
        try {
            if (is_file($meta)) {
                $info = json_decode(file_get_contents($meta), true, 32, JSON_THROW_ON_ERROR);
                CSM_Access::assert_owner($info);
                if (!hash_equals($info['selection'], $digest)) { throw new RuntimeException('This stream is pinned to a different selection. Discard and prepare again.'); }
                if (is_file($path) && filesize($path) === $info['size']) { return $info; }
                throw new RuntimeException('Source checkpoint is missing. Discard and prepare again.');
            }
            if (is_file($path . '.owner')) { CSM_Access::assert_owner(json_decode(file_get_contents($path . '.owner'), true, 32, JSON_THROW_ON_ERROR)); }
            self::write_json($path . '.owner', ['credential' => CSM_Access::owner()]);
            ignore_user_abort(true);
            $selection = CSM_Files::selection($selection); CSM_Database::validate($selection['tables'] ?? []);
            $generator = $kind === 'database' ? CSM_Database::stream($selection) : CSM_Files::stream($selection);
            $fp = fopen($path . '.part', 'wb'); if (!$fp) { throw new RuntimeException('Cannot build private source stream.'); }
            chmod($path . '.part', 0600); $hash = hash_init('sha256'); $size = 0;
            try {
                foreach (CSM_Support::gzip($generator) as $bytes) { CSM_Support::write($fp, $bytes); hash_update($hash, $bytes); $size += strlen($bytes); }
            } finally { fclose($fp); }
            if (!rename($path . '.part', $path)) { throw new RuntimeException('Cannot retain source stream.'); }
            $info = ['credential' => CSM_Access::owner(), 'size' => $size, 'sha256' => hash_final($hash), 'selection' => $digest, 'created' => time()];
            self::write_json($meta, $info); return $info;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function chunk(string $id, string $kind, int $offset): string {
        $path = self::path($id, $kind, '.source');
        if ($offset < 0 || !is_file($path . '.json') || !is_file($path) || $offset >= filesize($path)) { throw new RuntimeException('Invalid source checkpoint offset.'); }
        CSM_Access::assert_owner(json_decode(file_get_contents($path . '.json'), true, 32, JSON_THROW_ON_ERROR));
        $fp = fopen($path, 'rb'); if (!$fp || fseek($fp, $offset) !== 0) { throw new RuntimeException('Could not read source checkpoint.'); }
        try { $bytes = fread($fp, self::SIZE); if ($bytes === false || $bytes === '') { throw new RuntimeException('Source checkpoint is unreadable.'); } return $bytes; }
        finally { fclose($fp); }
    }

    private static function validate_bundle(array $bundle): void {
        if (!is_int($bundle['size'] ?? null) || $bundle['size'] < 1 || !is_string($bundle['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $bundle['sha256'])) { throw new RuntimeException('Invalid source checkpoint metadata.'); }
    }

    public static function state(array $job, string $kind, array $bundle): array {
        CSM_Updates::assert_job($job);
        self::validate_bundle($bundle); $path = self::path($job['id'], $kind, '.incoming');
        if ($job['status'] !== 'staging' || !in_array($kind, CSM_Plugin::kinds($job['selection']), true)) { throw new RuntimeException('Transfer is not awaiting this stream.'); }
        $raw = @file_get_contents($path . '.json');
        if ($raw !== false) {
            $old = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if ($old['size'] !== $bundle['size'] || !hash_equals($old['sha256'], $bundle['sha256'])) { throw new RuntimeException('Source stream changed. Discard and prepare again.'); }
        }
        $offset = is_file($path) ? filesize($path) : 0;
        if ($offset > $bundle['size']) { throw new RuntimeException('Destination checkpoint is damaged.'); }
        return ['offset' => $offset, 'size' => $bundle['size'], 'sha256' => $bundle['sha256']];
    }

    public static function receive(array $job, string $kind, array $bundle, int $offset, string $bytes, string $checksum): array {
        $state = self::state($job, $kind, $bundle); $path = self::path($job['id'], $kind, '.incoming');
        if (strlen($bytes) > self::SIZE || !hash_equals(hash('sha256', $bytes), $checksum) || $offset < 0 || $offset > $state['offset'] || $offset + strlen($bytes) > $bundle['size']) { throw new RuntimeException('Segment checksum or offset mismatch.'); }
        if (isset($job['received'][$kind])) { return CSM_Plugin::summary($job) + ['continue' => false, 'checkpoint' => $state]; }
        if ($offset < $state['offset']) {
            $fp = fopen($path, 'rb'); fseek($fp, $offset); $old = fread($fp, strlen($bytes)); fclose($fp);
            if ($offset + strlen($bytes) > $state['offset'] || !hash_equals($old, $bytes)) { throw new RuntimeException('Conflicting retry at an acknowledged offset.'); }
        } elseif ($bytes !== '') {
            self::write_json($path . '.json', $bundle);
            $fp = fopen($path, 'c+b'); if (!$fp || fseek($fp, $offset) !== 0) { throw new RuntimeException('Cannot write destination checkpoint.'); }
            chmod($path, 0600);
            try { CSM_Support::write($fp, $bytes); fflush($fp); }
            finally { fclose($fp); }
        }
        clearstatcache(true, $path); $next = is_file($path) ? filesize($path) : 0;
        CSM_Progress::update(['phase' => 'Receiving resumable stream', 'component' => $kind, 'bytes' => $next, 'count' => $next, 'total' => $bundle['size'], 'unit' => 'bytes']);
        if ($next === $bundle['size']) {
            if (!hash_equals($bundle['sha256'], hash_file('sha256', $path))) { throw new RuntimeException('Completed stream checksum mismatch. Discard and prepare again.'); }
            $fp = fopen($path, 'rb');
            try { $job = CSM_Plugin::receive($job, $kind, $fp, CSM_Progress::run()); }
            finally { fclose($fp); }
        }
        return CSM_Plugin::summary($job) + ['continue' => empty($job['received'][$kind]), 'checkpoint' => ['offset' => $next, 'size' => $bundle['size']]];
    }

    public static function assert_progress_owner(string $id): void {
        $job = get_option('_csm_job_' . $id);
        if ($job) { CSM_Access::assert_owner($job); return; }
        foreach (['files', 'database'] as $kind) {
            $path = self::path($id, $kind, '.source.owner');
            if (is_file($path)) { CSM_Access::assert_owner(json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR)); return; }
        }
    }

    public static function cleanup_source(string $id): void {
        foreach (['database', 'files'] as $kind) {
            $path = self::path($id, $kind, '.source'); $lock = fopen($path . '.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Source stream is still being built. Retry cleanup shortly.'); }
            try {
                if (is_file($path . '.owner')) { CSM_Access::assert_owner(json_decode(file_get_contents($path . '.owner'), true, 32, JSON_THROW_ON_ERROR)); }
                elseif (is_file($path . '.json')) { CSM_Access::assert_owner(json_decode(file_get_contents($path . '.json'), true, 32, JSON_THROW_ON_ERROR)); }
                foreach (['', '.json', '.part', '.owner'] as $suffix) { CSM_Support::remove_tree($path . $suffix); }
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    public static function cleanup(string $id): void {
        self::cleanup_source($id);
        foreach (['database', 'files'] as $kind) {
            foreach (['.incoming', '.incoming.json', '.incoming.json.tmp'] as $suffix) { CSM_Support::remove_tree(self::path($id, $kind, $suffix)); }
        }
    }
}
