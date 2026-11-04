<?php
defined('ABSPATH') || exit;

/** Owns job persistence; transfer phases never choose an inline or file-backed representation. */
final class CSM_Jobs {
    private const INLINE_BYTES = 262144;
    private const MAX_RECORD_BYTES = 268435456;

    public static function get(string $id): array {
        self::require_id($id);
        $record = get_option('_csm_job_' . $id);
        if (!$record) { throw new RuntimeException('Transfer not found.'); }
        $job = isset($record['_csm_blob']) ? self::read_state($id, $record) : $record;
        if (in_array($job['status'], ['staging', 'backup_copy'], true)) {
            $cursor = self::read_cursor($id);
            if (($cursor['status'] ?? '') === $job['status']) { $job = array_replace($job, $cursor); }
        }
        return $job;
    }

    private static function require_id(string $id): void {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new RuntimeException('Invalid transfer ID.'); }
    }

    private static function private_directory($dir): string {
        if (!is_string($dir) || is_link($dir) || CSM_Support::public_path($dir)) {
            throw new RuntimeException('Unsafe transfer checkpoint storage.');
        }
        return $dir;
    }

    private static function read_state(string $id, array $record): array {
        $digest = $record['_csm_blob'];
        if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            throw new RuntimeException('Invalid transfer checkpoint reference.');
        }
        $dir = self::private_directory($record['_csm_blob_dir'] ?? CSM_Support::private_dir() . '/job-records/' . $id);
        $raw = @file_get_contents($dir . '/' . $digest . '.json.gz');
        if ($raw === false || !hash_equals($digest, hash('sha256', $raw))) {
            throw new RuntimeException('Transfer checkpoint is missing or changed.');
        }
        $decoded = @gzdecode($raw, self::MAX_RECORD_BYTES);
        $job = $decoded === false ? null : json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($job) || ($job['id'] ?? null) !== $id || ($job['status'] ?? null) !== $record['status']) {
            throw new RuntimeException('Invalid transfer checkpoint.');
        }
        return $job;
    }

    private static function read_cursor(string $id): array {
        global $wpdb;
        // Transactional writes bypass the options cache, including its negative cache.
        $sql = $wpdb->prepare('SELECT option_value FROM ' . CSM_Support::ident($wpdb->options) . ' WHERE option_name=%s', '_csm_cursor_' . $id);
        $value = $wpdb->get_var($sql);
        if ($wpdb->last_error !== '') { throw new RuntimeException('Could not read transfer checkpoint: ' . $wpdb->last_error); }
        if ($value === null) { return []; }
        $cursor = maybe_unserialize($value);
        if (!is_array($cursor) || !isset($cursor['status'])) { throw new RuntimeException('Invalid transactional transfer checkpoint.'); }
        return $cursor;
    }

    public static function save(array $job): void {
        self::require_id($job['id'] ?? '');
        @touch(CSM_Support::private_dir() . '/' . $job['id'] . '-heartbeat');
        $record = self::encode_record($job);
        $key = '_csm_job_' . $job['id'];
        if (!update_option($key, $record, false)) {
            wp_cache_delete($key, 'options');
            if (get_option($key) !== $record) {
                throw new RuntimeException('Could not persist transfer checkpoint. Check database storage and packet limits.');
            }
        }
        delete_option('_csm_cursor_' . $job['id']);
    }

    private static function encode_record(array $job): array {
        if (strlen(maybe_serialize($job)) <= self::INLINE_BYTES) { return $job; }
        $json = CSM_Support::json($job);
        if (strlen($json) > self::MAX_RECORD_BYTES) {
            throw new RuntimeException('Transfer inventory exceeds its checkpoint limit. Narrow the selection.');
        }
        $raw = gzencode($json, 3);
        unset($json);
        if ($raw === false) { throw new RuntimeException('Could not encode transfer checkpoint.'); }
        $digest = hash('sha256', $raw);
        $dir = isset($job['backup_dir']) ? $job['backup_dir'] . '/job-records' : CSM_Support::private_dir() . '/job-records/' . $job['id'];
        self::private_directory($dir);
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) { throw new RuntimeException('Cannot create transfer checkpoint storage.'); }
        $path = $dir . '/' . $digest . '.json.gz';
        if (!is_file($path)) { CSM_Support::atomic_bytes($path, $raw); }
        $record = array_intersect_key($job, array_flip(['id', 'status', 'credential', 'created', 'plugin_version', 'applied']));
        return $record + ['_csm_blob' => $digest, '_csm_blob_dir' => $dir];
    }

    public static function save_transactional(array $job): void {
        self::require_id($job['id'] ?? '');
        @touch(CSM_Support::private_dir() . '/' . $job['id'] . '-heartbeat');
        global $wpdb;
        $key = '_csm_cursor_' . $job['id'];
        $cursor = array_intersect_key($job, array_flip(['status', 'imports', 'rows', 'backup_copy']));
        $sql = $wpdb->prepare('INSERT INTO ' . CSM_Support::ident($wpdb->options) . ' (option_name,option_value,autoload) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)', $key, maybe_serialize($cursor), 'off');
        CSM_Support::query($sql);
        wp_cache_delete($key, 'options');
    }

    public static function forget(string $id): void {
        self::require_id($id);
        CSM_Support::remove_tree(CSM_Support::private_dir() . '/job-records/' . $id);
        delete_option('_csm_cursor_' . $id);
        delete_option('_csm_job_' . $id);
    }

    public static function prune(): void {
        $dirs = [];
        foreach (glob(CSM_Support::private_dir() . '/job-records/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { continue; }
            $dirs[$dir] = get_option('_csm_job_' . $id, [])['_csm_blob'] ?? '';
        }
        foreach (array_filter(array_merge(get_option('_csm_backups', []), [get_option('_csm_active_job')])) as $id) {
            $record = get_option('_csm_job_' . $id, []);
            if (!empty($record['_csm_blob_dir']) && !is_link($record['_csm_blob_dir']) && !CSM_Support::public_path($record['_csm_blob_dir'])) {
                $dirs[$record['_csm_blob_dir']] = $record['_csm_blob'] ?? '';
            }
        }
        foreach ($dirs as $dir => $current) {
            foreach (glob($dir . '/*.json.gz') ?: [] as $path) {
                // Retain old immutable versions long enough for concurrent status readers.
                if (basename($path) !== $current . '.json.gz' && filemtime($path) < time() - 3600) { @unlink($path); }
            }
        }
    }
}
