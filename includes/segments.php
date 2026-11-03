<?php
defined('ABSPATH') || exit;

/** Immutable gzip snapshots with resumable streaming, bounded chunks and durable byte offsets. */
final class CSM_Segments {
    public const SIZE = 524288;
    public const UPLOAD_SIZE = 16 * 1024 * 1024;

    private static function path(string $id, string $kind, string $suffix): string {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || !in_array($kind, ['files', 'database'], true)) { throw new RuntimeException('Invalid segmented transfer.'); }
        return CSM_Support::private_dir() . '/segment-' . $id . '-' . $kind . $suffix;
    }

    private static function write_json(string $path, array $value): void {
        $raw = CSM_Support::json($value); $temp = $path . '.tmp';
        if (file_put_contents($temp, $raw, LOCK_EX) !== strlen($raw)) { throw new RuntimeException('Could not save transfer checkpoint.'); }
        chmod($temp, 0600); if (!rename($temp, $path)) { throw new RuntimeException('Could not save transfer checkpoint.'); }
    }

    public static function lease(string $id): void {
        $path=self::path($id,'files','.source.owner'); $lock=fopen(self::path($id,'files','.source.lock'),'c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) fclose($lock); throw new CSM_Busy_Error('Source preparation is busy.'); }
        try {
            if (is_file($path)) { CSM_Access::assert_owner(json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR)); @touch($path); }
            else { self::write_json($path,['credential'=>CSM_Access::owner(),'created'=>time()]); }
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    public static function bundle(string $id, string $kind, array $selection): array {
        $path = self::path($id, $kind, '.source'); $meta = $path . '.json'; $digest = hash('sha256', CSM_Support::json($selection));
        $lock = fopen($path . '.lock', 'c');
        if (!$lock) { throw new RuntimeException('Cannot open source snapshot lock.'); }
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new CSM_Busy_Error('Source stream is still being built. Retry preparation shortly.'); }
        try {
            if (is_file($meta)) {
                $info = json_decode(file_get_contents($meta), true, 32, JSON_THROW_ON_ERROR);
                CSM_Access::assert_owner($info);
                if (!hash_equals($info['selection'], $digest)) { throw new RuntimeException('This stream is pinned to a different selection. Discard and prepare again.'); }
                if (is_file($path) && filesize($path) === $info['size']) { @touch($path.'.owner'); return $info; }
                throw new RuntimeException('Source checkpoint is missing. Discard and prepare again.');
            }
            if (is_file($path . '.owner')) { $owner=json_decode(file_get_contents($path . '.owner'),true,32,JSON_THROW_ON_ERROR); CSM_Access::assert_owner($owner); if (isset($owner['selection']) && !hash_equals($owner['selection'],$digest)) { throw new RuntimeException('This package is pinned to a different selection.'); } }
            self::write_json($path . '.owner', ['credential' => CSM_Access::owner(),'selection'=>$digest,'touched'=>time()]);
            ignore_user_abort(true);
            CSM_Support::begin_source_budget();
            $previous_limit = (int)ini_get('max_execution_time');
            @set_time_limit(50);
            try {
                $selection = CSM_Files::selection($selection); CSM_Database::validate($selection['tables'] ?? []);
                $built = CSM_Package::build($path, $kind, $selection);
                if (!empty($built['continue'])) { return $built; }
                $info = $built + ['credential'=>CSM_Access::owner(),'selection'=>$digest,'created'=>time()];
                self::write_json($meta, $info); return $info;
            } catch (Throwable $error) {
                if ($kind === 'database') { @unlink($path . '.part'); }
                throw $error;
            } finally {
                CSM_Support::end_source_budget();
                @set_time_limit($previous_limit);
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function chunk(string $id, string $kind, int $offset): string {
        $path = self::path($id, $kind, '.source');
        if ($offset < 0 || !is_file($path . '.json') || !is_file($path) || $offset >= filesize($path)) { throw new RuntimeException('Invalid source checkpoint offset.'); }
        CSM_Access::assert_owner(json_decode(file_get_contents($path . '.json'), true, 32, JSON_THROW_ON_ERROR));
        @touch($path.'.owner'); $fp = fopen($path, 'rb'); if (!$fp || fseek($fp, $offset) !== 0) { throw new RuntimeException('Could not read source checkpoint.'); }
        try { $bytes = fread($fp, self::SIZE); if ($bytes === false || $bytes === '') { throw new RuntimeException('Source checkpoint is unreadable.'); } return $bytes; }
        finally { fclose($fp); }
    }

    public static function source(string $id, string $kind, int $offset, array $bundle, ?int $length = null): Generator {
        self::validate_bundle($bundle); $path = self::path($id, $kind, '.source');
        $meta = is_file($path . '.json') ? json_decode(file_get_contents($path . '.json'), true, 32, JSON_THROW_ON_ERROR) : [];
        CSM_Access::assert_owner($meta);
        if ($offset < 0 || $offset > $bundle['size'] || ($meta['size'] ?? null) !== $bundle['size'] || !hash_equals($meta['sha256'] ?? '', $bundle['sha256']) || !is_file($path) || filesize($path) !== $bundle['size']) { throw new RuntimeException('Source snapshot or offset does not match.'); }
        $length ??= $bundle['size'] - $offset;
        if ($length < 0 || $length > $bundle['size'] - $offset) { throw new RuntimeException('Invalid source window length.'); }
        @touch($path.'.owner'); $fp = fopen($path, 'rb');
        if (!$fp || fseek($fp, $offset) !== 0) { throw new RuntimeException('Cannot open source snapshot.'); }
        try {
            while ($length > 0 && !feof($fp)) {
                CSM_Support::check_cancel();
                $bytes = fread($fp, min(self::SIZE, $length));
                if ($bytes === false) { throw new RuntimeException('Cannot read source snapshot.'); }
                if ($bytes !== '') { $length -= strlen($bytes); yield $bytes; }
            }
        } finally { fclose($fp); }
    }

    public static function pull(array $peer, array $job, string $kind, array $bundle): array {
        $state = self::state($job, $kind, $bundle); $path = self::path($job['id'], $kind, '.incoming');
        if ($state['offset'] < $bundle['size']) {
            self::write_json($path . '.json', $bundle);
            $fp = fopen($path, 'c+b');
            if (!$fp || fseek($fp, $state['offset']) !== 0) { throw new RuntimeException('Cannot resume destination stream.'); }
            chmod($path, 0600);
            // Partial bytes survive a failed request; the complete snapshot digest is checked before parsing.
            try { CSM_Transport::request($peer, 'bundle_stream', ['id'=>$job['id'], 'kind'=>$kind, 'offset'=>$state['offset'], 'bundle'=>$bundle], null, $fp, 50, $bundle['size'] - $state['offset']); }
            finally { fflush($fp); fclose($fp); }
        }
        clearstatcache(true, $path); $next = is_file($path) ? filesize($path) : 0;
        if ($next !== $bundle['size']) { throw new CSM_Network_Error('Connection failed: Incomplete snapshot stream. Resume from the saved offset.'); }
        return self::receive($job,$kind,$bundle,$next,'',hash('sha256',''),$state['offset']<$bundle['size']);
    }

    public static function receive_stream(array $job, string $kind, array $bundle, int $offset, $input, ?int $length = null): array {
        $state = self::state($job, $kind, $bundle); $path = self::path($job['id'], $kind, '.incoming');
        if ($offset !== $state['offset']) { throw new RuntimeException('Destination offset changed. Refresh its checkpoint before resuming.'); }
        if ($length !== null && ($length < 0 || $length > self::UPLOAD_SIZE || $length > $bundle['size'] - $offset || ($length === 0 && $offset !== $bundle['size']))) { throw new RuntimeException('Invalid upload window length.'); }
        $end = $offset + ($length ?? ($bundle['size'] - $offset));
        self::write_json($path . '.json', $bundle);
        $fp = fopen($path, 'c+b');
        if (!$fp || fseek($fp, $offset) !== 0) { throw new RuntimeException('Cannot resume destination stream.'); }
        chmod($path, 0600);
        try {
            while (!feof($input)) {
                CSM_Support::check_cancel();
                $bytes = fread($input, self::SIZE);
                if ($bytes === false || ($bytes === '' && !feof($input))) { throw new CSM_Network_Error('Connection failed: Incoming stream interrupted.'); }
                if ($offset + strlen($bytes) > $end) { throw new RuntimeException('Snapshot stream exceeds its declared size.'); }
                CSM_Support::write($fp, $bytes); $offset += strlen($bytes); fflush($fp);
                CSM_Progress::update(['phase'=>'Receiving resumable stream', 'component'=>$kind, 'bytes'=>$offset, 'count'=>$offset, 'total'=>$bundle['size'], 'unit'=>'bytes']);
            }
        } finally { fclose($fp); }
        if ($offset !== $end) { throw new CSM_Network_Error('Connection failed: Incoming stream interrupted. Resume from the saved offset.'); }
        return self::receive($job,$kind,$bundle,$offset,'',hash('sha256',''),$end>$state['offset']);
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

    public static function receive(array $job, string $kind, array $bundle, int $offset, string $bytes, string $checksum, bool $defer = false): array {
        $state = self::state($job, $kind, $bundle); $path = self::path($job['id'], $kind, '.incoming');
        if (strlen($bytes) > self::SIZE || !hash_equals(hash('sha256', $bytes), $checksum) || $offset < 0 || $offset > $state['offset'] || $offset + strlen($bytes) > $bundle['size']) { throw new RuntimeException('Segment checksum or offset mismatch.'); }
        if (isset($job['received'][$kind])) { return CSM_Plugin::summary($job) + ['continue' => false, 'checkpoint' => $state]; }
        if ($offset < $state['offset']) {
            @touch($path.'.owner'); $fp = fopen($path, 'rb'); fseek($fp, $offset); $old = fread($fp, strlen($bytes)); fclose($fp);
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
        if ($next === $bundle['size'] && !$defer && $bytes==='') {
            if (($bundle['format'] ?? 1) === 2) {
                if (CSM_Package::unpack($path,$bundle)) {
                    $fp = fopen($path.'.raw','rb');
                    try { $job = CSM_Plugin::receive_step($job,$kind,$fp); }
                    finally { fclose($fp); }
                }
            } else {
                if (!hash_equals($bundle['sha256'], CSM_Support::hash_file($path))) { throw new RuntimeException('Completed stream checksum mismatch. Discard and prepare again.'); }
                @touch($path.'.owner'); $fp = fopen($path, 'rb');
                try { $job = CSM_Plugin::receive($job, $kind, $fp, CSM_Progress::run()); }
                finally { fclose($fp); }
            }
        }
        return CSM_Plugin::summary($job) + ['continue' => empty($job['received'][$kind]), 'checkpoint' => ['offset' => $next, 'size' => $bundle['size']]];
    }

    public static function received_bytes(string $id, string $kind): int {
        $path = self::path($id, $kind, '.incoming'); clearstatcache(true, $path);
        return is_file($path) ? filesize($path) : 0;
    }

    public static function assert_progress_owner(string $id): void {
        $job = get_option('_csm_job_' . $id);
        if ($job) { CSM_Access::assert_owner($job); return; }
        foreach (['files', 'database'] as $kind) {
            $path = self::path($id, $kind, '.source.owner');
            if (is_file($path)) { CSM_Access::assert_owner(json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR)); return; }
        }
    }

    public static function cancel(string $id): void {
        $found = false;
        if ($job = get_option('_csm_job_'.$id)) { CSM_Access::assert_owner($job); $found=true; }
        foreach (['database','files'] as $kind) {
            $path=self::path($id,$kind,'.source.owner');
            if (is_file($path)) { CSM_Access::assert_owner(json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR)); $found=true; }
        }
        CSM_Support::cancel($id);
    }

    public static function prune_sources(): void {
        CSM_Support::prune_job_records();
        foreach (glob(CSM_Support::private_dir().'/media-compare-*.json') ?: [] as $cache) { if (filemtime($cache)<time()-DAY_IN_SECONDS) { @unlink($cache); } }
        foreach (glob(CSM_Support::private_dir().'/*.comparison-*.json') ?: [] as $cache) { if (filemtime($cache)<time()-DAY_IN_SECONDS) { @unlink($cache); } }
        foreach (glob(CSM_Support::private_dir().'/*.build-*') ?: [] as $cache) { if (filemtime($cache)<time()-DAY_IN_SECONDS) { @unlink($cache); } }
        foreach (glob(CSM_Support::private_dir().'/component-folder-*.json') ?: [] as $cache) { if (filemtime($cache)<time()-30*DAY_IN_SECONDS) { @unlink($cache); } }
        foreach (array_merge(glob(CSM_Support::private_dir().'/component-snapshot-*.json') ?: [],glob(CSM_Support::private_dir().'/media-snapshot-*.json') ?: []) as $cache) { if (filemtime($cache)<time()-7*DAY_IN_SECONDS) { @unlink($cache); } }
        foreach (glob(CSM_Support::private_dir().'/segment-*.source.owner') ?: [] as $owner) {
            if (filemtime($owner)>time()-DAY_IN_SECONDS || !preg_match('/segment-([a-f0-9]{32})-(database|files)\.source\.owner$/D',$owner,$m)) { continue; }
            $lock=fopen(substr($owner,0,-6).'.lock','c');
            if (!$lock) { continue; }
            if (flock($lock,LOCK_EX|LOCK_NB)) {
                try {
                    clearstatcache(true,$owner);
                    if (is_file($owner) && filemtime($owner)<=time()-DAY_IN_SECONDS) {
                        foreach (['','.json','.part','.owner','.build'] as $suffix) { CSM_Support::remove_tree(substr($owner,0,-6).$suffix); }
                        CSM_Log::write('info','Expired source package removed',['transfer_id'=>$m[1]]);
                    }
                } finally { flock($lock,LOCK_UN); }
            }
            fclose($lock);
        }
    }

    public static function cleanup_source(string $id): void {
        foreach (['database', 'files'] as $kind) {
            $path = self::path($id, $kind, '.source'); $lock = fopen($path . '.lock', 'c');
            if (!$lock) { throw new RuntimeException('Cannot open source snapshot lock.'); }
            if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new CSM_Busy_Error('Source stream is still being built. Retry cleanup shortly.'); }
            try {
                if (is_file($path . '.owner')) { $owner=json_decode(file_get_contents($path . '.owner'),true,32,JSON_THROW_ON_ERROR); CSM_Access::assert_owner($owner); }
                elseif (is_file($path . '.json')) { CSM_Access::assert_owner(json_decode(file_get_contents($path . '.json'), true, 32, JSON_THROW_ON_ERROR)); }
                foreach (['', '.json', '.part', '.owner', '.build'] as $suffix) { CSM_Support::remove_tree($path . $suffix); }
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    public static function cleanup(string $id): void {
        self::cleanup_source($id);
        foreach (['database', 'files'] as $kind) {
            foreach (['.incoming', '.incoming.json', '.incoming.json.tmp','.incoming.raw','.incoming.unpack'] as $suffix) { CSM_Support::remove_tree(self::path($id, $kind, $suffix)); }
        }
    }
}
