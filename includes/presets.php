<?php
defined('ABSPATH') || exit;

final class CSM_Presets {
    public static function catalog(): array {
        return [
            'pull-db' => ['id' => 'pull-db', 'name' => 'Pull database', 'direction' => 'pull', 'description' => 'All eligible WordPress tables; no files or media.'],
            'pull-db-missing' => ['id' => 'pull-db-missing', 'name' => 'Pull database and missing plugins', 'direction' => 'pull', 'description' => 'All eligible WordPress tables and plugin folders absent from the destination; no themes or media.'],
            'push-git' => ['id' => 'push-git', 'name' => 'Push version-controlled code', 'direction' => 'push', 'description' => 'Plugin/theme folders containing Git repositories; no database tables or media.'],
        ];
    }

    public static function version_controlled(): array {
        $inventory = CSM_Files::inventory(); $selection = ['media' => 'none'];
        foreach (['plugins', 'themes'] as $kind) { $selection[$kind] = array_column(array_filter($inventory[$kind], static fn($item) => empty($item['protected'])), 'id'); }
        $folders = CSM_Files::git_folders($selection); $result = ['plugins' => [], 'themes' => []];
        foreach (['plugins', 'themes'] as $kind) {
            foreach ($selection[$kind] as $id) {
                foreach ($folders as $folder) {
                    if (CSM_Files::beneath($folder, [$kind . '/' . $id])) { $result[$kind][] = $id; break; }
                }
            }
        }
        return $result;
    }

    public static function resolve(string $name, array $inventory): array {
        $preset = self::catalog()[$name] ?? null;
        if (!$preset) { throw new RuntimeException('Unknown preset. Use wp trickle presets to list supported workflows.'); }
        $source = $inventory[$preset['direction'] === 'push' ? 'local' : 'remote'];
        $destination = $inventory[$preset['direction'] === 'push' ? 'remote' : 'local'];
        $flags = ['plugins' => '', 'themes' => '', 'tables' => '', 'media' => 'none'];
        if ($name === 'push-git') {
            $code = self::version_controlled();
            if (!$code['plugins'] && !$code['themes']) { throw new RuntimeException('No Git-backed plugin/theme folders found. Inspect wp trickle inventory or use an explicit push selection.'); }
            foreach (['plugins', 'themes'] as $kind) { $flags[$kind] = implode(',', $code[$kind]); }
        } else {
            $flags['tables'] = implode(',', array_column($source['tables'], 'id'));
            if ($name === 'pull-db-missing') {
                $existing = array_column($destination['plugins'], 'id');
                $flags['plugins'] = implode(',', array_column(array_filter($source['plugins'], static fn($item) => empty($item['protected']) && !in_array($item['id'], $existing, true)), 'id'));
            }
        }
        return $flags;
    }

    public static function plan(string $name, array $peer, array $inventory, array $selection, array $confirmed): array {
        $direction = self::catalog()[$name]['direction'];
        $source = $inventory[$direction === 'push' ? 'local' : 'remote'];
        $destination = $inventory[$direction === 'push' ? 'remote' : 'local'];
        $blockers = []; $review = ['git_folders' => [], 'skip_paths' => [], 'git_overrides' => []];
        if ($inventory['local']['plugin_version'] !== $inventory['remote']['plugin_version']) { $blockers[] = ['code' => 'version_mismatch', 'message' => 'Both sites must run the same Trickle version.']; }
        if ($direction === 'push' && empty($inventory['remote']['can_receive'])) { $blockers[] = ['code' => 'writes_disabled', 'message' => 'Enable incoming writes and use an administrator or read/write remote key.']; }
        if (($selection['plugins'] || $selection['themes']) && $inventory['local']['plugin_version'] === $inventory['remote']['plugin_version']) { $review = CSM_Controller::execute('review', ['site' => $peer['id'], 'direction' => $direction, 'selection' => $selection, 'git_confirmed' => $confirmed]); }
        if (($selection['plugins'] || $selection['themes']) && isset($destination['file_modifications']) && !$destination['file_modifications']) { $blockers[] = ['code' => 'file_modifications_disabled', 'message' => 'WordPress disallows destination file modifications.']; }
        $selection += ['skip_paths' => $review['skip_paths']];
        if (!CSM_Plugin::kinds($selection)) { $blockers[] = ['code' => 'empty_selection', 'message' => 'This preset has no transferable components.']; }
        $tables = array_values(array_filter($source['tables'], static fn($table) => in_array($table['id'], $selection['tables'], true)));
        foreach ($tables as $table) { if (strtoupper($table['engine']) !== 'INNODB') { $blockers[] = ['code' => 'unsupported_table', 'message' => 'InnoDB required: ' . $table['id']]; } }
        $themes = array_column($source['themes'], null, 'id'); $available_themes = array_column($destination['themes'], 'id');
        foreach ($selection['themes'] as $id) {
            if (!CSM_Files::skipped('themes/' . $id, $selection)) { $available_themes[] = $id; }
        }
        foreach ($selection['themes'] as $id) {
            if (CSM_Files::skipped('themes/' . $id, $selection)) { continue; }
            $parent = $themes[$id]['parent'] ?? '';
            if ($parent && !in_array($parent, $available_themes, true)) { $blockers[] = ['code' => 'missing_parent_theme', 'message' => 'Install the unselected parent theme first: ' . $parent]; }
        }
        if (in_array($source['prefix'] . 'options', $selection['tables'], true)) {
            foreach ($source['themes'] as $theme) { if (!empty($theme['active']) && !in_array($theme['id'], $available_themes, true)) { $blockers[] = ['code' => 'missing_active_theme', 'message' => 'Database import needs this source theme on the destination: ' . $theme['id']]; } }
        }
        $activation = ['mode' => $selection['sync_active_plugins'] ? 'source_full_list' : 'keep_destination', 'activate' => [], 'deactivate' => [], 'missing' => []];
        if ($selection['sync_active_plugins'] && isset($source['active_plugins'], $destination['active_plugins'])) {
            $job = ['selection' => ['sync_active_plugins' => true]]; CSM_Activation::source($job, ['active_plugins' => $source['active_plugins'], 'trickle_plugin' => $source['trickle_plugin']]);
            $desired = array_map(static fn($file) => $file === plugin_basename(CSM_FILE) ? $destination['trickle_plugin'] : $file, $job['source_active_plugins']); $installed = $destination['installed_plugins'];
            $selected = static fn($file) => in_array(str_contains($file, '/') ? explode('/', $file)[0] : $file, $selection['plugins'], true) && !CSM_Files::skipped('plugins/' . $file, $selection);
            $installed = array_values(array_filter($installed, static fn($file) => !$selected($file)));
            $installed = array_merge($installed, array_values(array_filter($source['installed_plugins'], $selected)));
            $activation['missing'] = array_values(array_diff($desired, $installed));
            $desired = array_values(array_intersect($desired, $installed));
            $activation['activate'] = array_values(array_diff($desired, $destination['active_plugins']));
            $activation['deactivate'] = array_values(array_diff($destination['active_plugins'], $desired));
        }
        $session = CSM_Controller::session();
        if ($session) {
            $retained = CSM_Controller::execute('status', ['id' => $session['id']]);
            if (!in_array($retained['status'], ['applied', 'restored'], true)) { $blockers[] = ['code' => 'active_transfer', 'message' => 'Resume or recover the existing transfer before starting another.']; }
        }
        return ['ok' => true, 'schema_version' => 1, 'action' => 'plan', 'preset' => $name, 'direction' => $direction, 'source' => $source['url'], 'destination' => $destination['url'],
            'can_run' => !$blockers, 'blockers' => $blockers, 'selection' => $selection, 'estimated_database_bytes' => array_sum(array_column($tables, 'bytes')),
            'activation' => $activation, 'git_folders' => $review['git_folders'], 'git_confirmed' => $confirmed,
            'excluded' => ['.git', '.gitignore', 'node_modules', '.env*', '*.env', '*.env[._~-]*'], 'file_changes' => 'Verified after staging; this plan selects components, not individual file deltas.',
            'requires_yes' => true];
    }
}
