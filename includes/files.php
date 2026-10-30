<?php
defined('ABSPATH') || exit;

final class CSM_Files {
    public static function inventory(bool $refresh = false): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        if ($refresh) { wp_update_plugins(); wp_update_themes(); }
        $plugin_updates = get_site_transient('update_plugins');
        $theme_updates = get_site_transient('update_themes');
        $active_plugins = defined('CSM_ORIGINAL_ACTIVE_PLUGINS') ? CSM_ORIGINAL_ACTIVE_PLUGINS : get_option('active_plugins', []);
        $active_themes = defined('CSM_ORIGINAL_TEMPLATE') ? [CSM_ORIGINAL_TEMPLATE, CSM_ORIGINAL_STYLESHEET] : [get_template(), get_stylesheet()];
        $plugins = [];
        foreach (get_plugins() as $file => $data) {
            $id = str_contains($file, '/') ? explode('/', $file)[0] : $file;
            if (isset($plugins[$id])) { continue; }
            $icons = ($plugin_updates->response[$file] ?? $plugin_updates->no_update[$file] ?? null)->icons ?? [];
            $plugins[$id] = ['id' => $id, 'name' => $data['Name'], 'version' => $data['Version'], 'update' => $plugin_updates->response[$file]->new_version ?? null, 'icon' => esc_url_raw($icons['svg'] ?? $icons['2x'] ?? $icons['1x'] ?? '', ['http', 'https']), 'active' => in_array($file, $active_plugins, true), 'protected' => $id === basename(dirname(CSM_FILE))];
        }
        $themes = [];
        foreach (wp_get_themes() as $id => $theme) {
            $themes[] = ['id' => $id, 'name' => $theme->get('Name'), 'version' => $theme->get('Version'), 'update' => $theme_updates->response[$id]['new_version'] ?? null, 'icon' => esc_url_raw($theme->get_screenshot() ?: '', ['http', 'https']), 'parent' => $theme->get('Template'), 'active' => in_array($id, $active_themes, true)];
        }
        $media = [];
        foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 250, 'orderby' => 'ID', 'order' => 'DESC']) as $post) {
            $media[] = ['id' => $post->ID, 'name' => $post->post_title, 'file' => get_post_meta($post->ID, '_wp_attached_file', true), 'date' => $post->post_modified_gmt];
        }
        return ['plugins' => array_values($plugins), 'themes' => $themes, 'media' => $media, 'media_total' => (int)wp_count_posts('attachment')->inherit];
    }

    public static function selection(array $selection): array {
        $inventory = self::inventory();
        foreach (['plugins', 'themes'] as $kind) {
            $allowed = array_column($inventory[$kind], null, 'id');
            $selection[$kind] = array_values(array_unique($selection[$kind] ?? []));
            foreach ($selection[$kind] as $id) {
                if (!isset($allowed[$id]) || !empty($allowed[$id]['protected'])) { throw new RuntimeException('Unknown or protected ' . $kind . ' selection.'); }
            }
        }
        $mode = $selection['media'] ?? 'none';
        if (!in_array($mode, ['none', 'all', 'changed', 'selected'], true)) { throw new RuntimeException('Unknown media selection.'); }
        $selection['media'] = $mode;
        $selection['since'] = max(0, (int)($selection['since'] ?? 0));
        if ($mode === 'changed' && !$selection['since']) { throw new RuntimeException('Choose a starting date or complete an initial media transfer first.'); }
        $selection['media_ids'] = array_values(array_unique(array_map('intval', $selection['media_ids'] ?? [])));
        return $selection;
    }

    public static function root(string $kind, string $component = ''): string {
        if ($kind === 'plugins') { return WP_PLUGIN_DIR; }
        if ($kind === 'themes') { return $component && wp_get_theme($component)->exists() ? wp_get_theme($component)->get_theme_root() : WP_CONTENT_DIR . '/themes'; }
        $uploads = wp_upload_dir(null, false);
        if ($uploads['error']) { throw new RuntimeException($uploads['error']); }
        return $uploads['basedir'];
    }

    public static function safe_relative(string $path): string {
        if (!$path || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('#(^|/)\.\.?(/|$)|:#', $path)) {
            throw new RuntimeException('Unsafe file path.');
        }
        return $path;
    }

    public static function destination(string $relative, array $selection): string {
        self::safe_relative($relative);
        [$kind, $tail] = array_pad(explode('/', $relative, 2), 2, '');
        if (!in_array($kind, ['plugins', 'themes', 'uploads'], true) || !$tail) { throw new RuntimeException('Unsupported file location.'); }
        $first = explode('/', $tail)[0];
        if ($kind === 'uploads') {
            if (($selection['media'] ?? 'none') === 'none' || preg_match('/\.(php\d*|phtml|phar|cgi|pl|sh|htaccess|user\.ini)$/i', $tail) || str_starts_with(basename($tail), '.')) {
                throw new RuntimeException('Executable or unselected media file blocked.');
            }
        } elseif (!in_array($first, $selection[$kind] ?? [], true) || ($kind === 'plugins' && $first === basename(dirname(CSM_FILE)))) {
            throw new RuntimeException('File outside the selected components.');
        }
        $root = self::root($kind, $first);
        $path = $root . '/' . $tail;
        $walk = $path;
        while (strlen($walk) >= strlen($root)) {
            if (is_link($walk)) { throw new RuntimeException('Symbolic links are not transferable.'); }
            if ($walk === $root) { break; }
            $walk = dirname($walk);
        }
        return $path;
    }

    private static function scan(string $path): Generator {
        if (is_link($path)) { throw new RuntimeException('Symbolic links are not transferable: ' . basename($path)); }
        if (is_file($path)) { yield $path; return; }
        if (!is_dir($path)) { return; }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $file) { yield from self::scan($file->getPathname()); }
    }

    public static function paths(array $selection): Generator {
        foreach (['plugins', 'themes'] as $kind) {
            foreach ($selection[$kind] as $id) {
                $root = self::root($kind, $id); $count = 0;
                if (!file_exists($root . '/' . $id)) { throw new RuntimeException('Selected source component is missing: ' . $id); }
                foreach (self::scan($root . '/' . $id) as $file) {
                    $relative = $kind . '/' . substr($file, strlen($root) + 1);
                    self::safe_relative($relative);
                    yield [$relative, $file]; $count++;
                }
                if (!$count) { throw new RuntimeException('Selected source component has no files: ' . $id); }
            }
        }
        $root = self::root('uploads');
        if ($selection['media'] === 'selected') {
            $seen = [];
            foreach ($selection['media_ids'] as $id) {
                if (get_post_type($id) !== 'attachment') { throw new RuntimeException('Unknown attachment.'); }
                $file = get_post_meta($id, '_wp_attached_file', true);
                $metadata = wp_get_attachment_metadata($id);
                $paths = [$file];
                foreach ($metadata['sizes'] ?? [] as $size) { $paths[] = dirname($file) . '/' . $size['file']; }
                if (!empty($metadata['original_image'])) { $paths[] = dirname($file) . '/' . $metadata['original_image']; }
                foreach ($paths as $tail) {
                    if (!$tail || isset($seen[$tail])) { continue; }
                    $relative = 'uploads/' . ltrim(preg_replace('#^\./#', '', $tail), '/');
                    $path = self::destination($relative, $selection);
                    if (!is_file($path)) { throw new RuntimeException('Missing attachment file: ' . $tail); }
                    $seen[$tail] = true; yield [$relative, $path];
                }
            }
        } elseif (in_array($selection['media'], ['all', 'changed'], true)) {
            foreach (self::scan($root) as $file) {
                $relative = 'uploads/' . substr($file, strlen($root) + 1);
                if ($selection['media'] === 'changed' && filemtime($file) < $selection['since']) { continue; }
                // Exclude executables, hidden server configuration and common backup/cache directories.
                if (preg_match('#(^|/)(cache|backups?|ai1wm-backups|wpvividbackups|\.git)(/|$)#i', $relative)) { continue; }
                try { self::destination($relative, $selection); } catch (RuntimeException $e) { continue; }
                yield [$relative, $file];
            }
        }
    }

    public static function stream(array $selection): Generator {
        $selection = self::selection($selection);
        $started = time(); $count = 0; $hash = hash_init('sha256');
        $parents = [];
        foreach ($selection['themes'] as $theme) { $parents[$theme] = wp_get_theme($theme)->get('Template'); }
        yield CSM_Support::json(['type' => 'header', 'version' => 1, 'kind' => 'files', 'started' => $started, 'theme_parents' => $parents]);
        foreach (self::paths($selection) as [$relative, $path]) {
            $fp = fopen($path, 'rb');
            if (!$fp) { throw new RuntimeException('Cannot read file: ' . $relative); }
            try {
                $stat = fstat($fp); $size = $stat['size'];
                $line = CSM_Support::json(['type' => 'file', 'path' => $relative, 'size' => $size, 'mtime' => $stat['mtime'], 'mode' => ($stat['mode'] & 0111) ? 0755 : 0644]);
                hash_update($hash, $line); yield $line;
                $filehash = hash_init('sha256'); $remaining = $size;
                while ($remaining > 0) {
                    $bytes = fread($fp, min(65536, $remaining));
                    if ($bytes === false || $bytes === '') { throw new RuntimeException('File changed or became unreadable during transfer.'); }
                    $remaining -= strlen($bytes); hash_update($filehash, $bytes); yield $bytes;
                }
                if (fread($fp, 1) !== '' || fstat($fp)['mtime'] !== $stat['mtime']) { throw new RuntimeException('File changed during transfer: ' . $relative); }
                $line = CSM_Support::json(['type' => 'file-end', 'sha256' => hash_final($filehash)]);
                hash_update($hash, $line); yield $line; $count++;
            } finally { fclose($fp); }
        }
        yield CSM_Support::json(['type' => 'end', 'files' => $count, 'started' => $started, 'sha256' => hash_final($hash)]);
    }

    public static function stage($fp, array &$job): void {
        $header = CSM_Support::line($fp);
        if (($header['type'] ?? '') !== 'header' || ($header['version'] ?? 0) !== 1 || ($header['kind'] ?? '') !== 'files') { throw new RuntimeException('Invalid file stream.'); }
        foreach ($header['theme_parents'] ?? [] as $parent) {
            if ($parent && !in_array($parent, $job['selection']['themes'], true) && !wp_get_theme($parent)->exists()) { throw new RuntimeException('Include the required parent theme: ' . $parent); }
        }
        $dir = CSM_Support::private_dir() . '/' . $job['id'];
        if (!is_dir($dir) && !mkdir($dir, 0700)) { throw new RuntimeException('Cannot create transfer staging directory.'); }
        $files = []; $seen = []; $components = []; $hash = hash_init('sha256');
        while (true) {
            $record = CSM_Support::line($fp);
            if (($record['type'] ?? '') === 'end') {
                if (count($files) !== $record['files'] || !hash_equals(hash_final($hash), $record['sha256']) || fread($fp, 1) !== '') { throw new RuntimeException('Incomplete file stream or checksum failure.'); }
                foreach (['plugins', 'themes'] as $kind) {
                    foreach ($job['selection'][$kind] as $component) { if (empty($components[$kind . '/' . $component])) { throw new RuntimeException('Selected component was omitted from the file stream.'); } }
                }
                $job['files'] = $files; $job['media_watermark'] = $header['started']; return;
            }
            if (($record['type'] ?? '') !== 'file' || !is_int($record['size']) || $record['size'] < 0 || isset($seen[$record['path']])) { throw new RuntimeException('Invalid file header.'); }
            self::destination($record['path'], $job['selection']);
            $parts = explode('/', $record['path']); $components[$parts[0] . '/' . $parts[1]] = true;
            $seen[$record['path']] = true; hash_update($hash, CSM_Support::json($record));
            $index = count($files); $staged = $dir . '/' . $index;
            $out = fopen($staged, 'xb');
            if (!$out) { throw new RuntimeException('Cannot stage incoming file.'); }
            $remaining = $record['size']; $filehash = hash_init('sha256');
            try {
                while ($remaining > 0) {
                    $bytes = fread($fp, min(65536, $remaining));
                    if ($bytes === false || $bytes === '') { throw new RuntimeException('Truncated file stream.'); }
                    CSM_Support::write($out, $bytes); hash_update($filehash, $bytes); $remaining -= strlen($bytes);
                }
            } finally { fclose($out); }
            $end = CSM_Support::line($fp);
            if (($end['type'] ?? '') !== 'file-end' || !hash_equals(hash_final($filehash), $end['sha256'])) { throw new RuntimeException('File checksum mismatch.'); }
            hash_update($hash, CSM_Support::json($end));
            $files[] = ['path' => $record['path'], 'mtime' => (int)$record['mtime'], 'size' => $record['size'], 'mode' => !str_starts_with($record['path'], 'uploads/') && (($record['mode'] ?? 0644) & 0111) ? 0755 : 0644, 'stage' => $staged, 'sha256' => $end['sha256']];
        }
    }
}
