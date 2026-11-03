<?php
defined('ABSPATH') || exit;

final class CSM_Progress {
    private static ?array $current = null;
    private static float $written = 0;

    private static function path(string $id): string {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new RuntimeException('Invalid transfer ID.'); }
        return CSM_Support::private_dir() . '/' . $id . '-progress.json';
    }

    public static function run(): ?string { return self::$current['run'] ?? null; }

    public static function read(string $id): array {
        $path = self::path($id);
        return is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    }

    public static function start(string $id, string $phase, ?string $run = null): void {
        CSM_Support::work($id,null);
        $run ??= self::$current && self::$current['id'] === $id && self::$current['state'] === 'running' ? self::$current['run'] : bin2hex(random_bytes(8));
        if (!preg_match('/^[a-f0-9]{16}$/D', $run)) { throw new RuntimeException('Invalid progress operation.'); }
        $events = self::read($id)['events'] ?? [];
        self::$current = ['id' => $id, 'run' => $run, 'started' => microtime(true), 'state' => 'running', 'phase' => '', 'component' => '', 'count' => 0, 'total' => null, 'unit' => '', 'bytes' => 0, 'events' => $events];
        self::update(['phase' => $phase], true);
        register_shutdown_function(static function () use ($id) {
            $error = error_get_last();
            if (self::$current && self::$current['id'] === $id && self::$current['state'] === 'running' && $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::fail('The PHP process stopped unexpectedly. Check the server’s PHP log.');
            }
        });
    }

    public static function update(array $detail, bool $force = false): void {
        if (($detail['state'] ?? '') !== 'failed') { CSM_Support::check_source_budget(); }
        if (!self::$current) { return; }
        $changed = false;
        foreach (['phase', 'component', 'state'] as $key) { if (isset($detail[$key]) && $detail[$key] !== self::$current[$key]) { $changed = true; } }
        self::$current = array_replace(self::$current, $detail, ['updated' => microtime(true)]);
        if ($changed || (!self::$current['events'] && $force)) {
            $event = array_intersect_key(self::$current, array_flip(['updated', 'phase', 'component', 'state', 'failure']));
            $event['id'] = substr(hash('sha256', CSM_Support::json($event)), 0, 20);
            self::$current['events'][] = $event;
            self::$current['events'] = array_slice(self::$current['events'], -12);
        }
        if (!$force && microtime(true) - self::$written < .25) { return; }
        // A separate atomic file stays readable while the transfer owns the mutation lock or swaps wp_options.
        $path = self::path(self::$current['id']);
        $temp = $path . '.' . bin2hex(random_bytes(4));
        // Reporting must not interrupt an apply or prevent rollback when storage is full.
        if (@file_put_contents($temp, CSM_Support::json(self::$current), LOCK_EX) === false) { @unlink($temp); return; }
        @chmod($temp, 0600);
        if (!@rename($temp, $path)) { @unlink($temp); return; }
        self::$written = microtime(true);
    }

    public static function fail(string $error): void {
        if (!self::$current) { return; }
        CSM_Log::write('error', 'Transfer component failed', ['transfer_id' => self::$current['id'], 'phase' => self::$current['phase'], 'component' => self::$current['component'], 'run'=>self::$current['run'], 'count'=>self::$current['count'], 'total'=>self::$current['total'], 'unit'=>self::$current['unit'], 'error' => $error]);
        self::update(['state' => 'failed', 'failure' => self::$current['failure'] ?? ['phase' => self::$current['phase'], 'component' => self::$current['component'], 'error' => $error]], true);
    }

    public static function finish(string $phase): void {
        if (!self::$current) { return; }
        self::update(['state' => isset(self::$current['failure']) ? 'failed' : 'complete', 'phase' => $phase], true);
    }

    public static function remove(string $id): void {
        $path = self::path($id);
        if (is_file($path)) { unlink($path); }
    }
}
