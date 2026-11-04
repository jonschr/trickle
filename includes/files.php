<?php
defined('ABSPATH') || exit;

final class CSM_Files {
    public static function excluded(string $relative): bool {
        foreach (explode('/', strtolower($relative)) as $part) {
            if (in_array($part, ['.git', '.gitignore', 'node_modules'], true) || str_starts_with($part, '.env') || preg_match('/\.env($|[._~-])/', $part)) { return true; }
        }
        return false;
    }

    public static function beneath(string $path, array $folders): bool {
        foreach ($folders as $folder) { if ($path === $folder || str_starts_with($path, rtrim($folder, '/') . '/')) { return true; } }
        return false;
    }

    private static function git_ancestor(string $path): bool {
        while (true) {
            if (file_exists($path . '/.git') || is_link($path . '/.git')) { return true; }
            $parent = dirname($path); if ($parent === $path) { return false; } $path = $parent;
        }
    }

    public static function git_folders(array $selection): array {
        $result = []; $deadline=hrtime(true)/1e9+45;
        $scan = static function (string $path, string $relative) use (&$scan, &$result,$deadline): void {
            if (hrtime(true)/1e9>=$deadline) { throw new RuntimeException('Git folder scan exceeded its 45-second limit. Narrow the selection.'); }
            CSM_Support::check_source_budget();
            if (self::excluded($relative) || is_link($path) || !is_dir($path)) { return; }
            // A .git file is a worktree/submodule marker, just as a .git directory is a repository marker.
            if (file_exists($path . '/.git') || is_link($path . '/.git')) { $result[] = $relative; }
            foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $file) { if ($file->isDir() && !$file->isLink()) { $scan($file->getPathname(), $relative . '/' . $file->getFilename()); } }
        };
        foreach (['plugins', 'themes'] as $kind) {
            foreach ($selection[$kind] ?? [] as $id) {
                self::safe_relative($id);
                if (str_contains($id, '/')) { throw new RuntimeException('Invalid component ID.'); }
                $root = self::root($kind, $id);
                if (self::git_ancestor($root)) { $result[] = $kind; }
                $scan($root . '/' . $id, $kind . '/' . $id);
            }
        }
        if (($selection['media'] ?? 'none') !== 'none') { if (self::git_ancestor(self::root('uploads'))) { $result[] = 'uploads'; } $scan(self::root('uploads'), 'uploads'); }
        $result = array_values(array_unique($result)); sort($result, SORT_STRING); return $result;
    }

    public static function skipped(string $relative, array $selection): bool {
        return self::excluded($relative) || self::beneath($relative, $selection['skip_paths'] ?? []);
    }

    private static function assert_git_destination(string $relative, string $path, string $root, array $selection): void {
        $kind = explode('/', $relative)[0];
        if (self::git_ancestor(dirname($root)) && !in_array($kind, $selection['git_overrides'] ?? [], true)) { throw new RuntimeException('Git working directory protected: ' . $kind . '. Review and explicitly include it before transferring.'); }
        $walk = is_dir($path) ? $path : dirname($path);
        while (strlen($walk) >= strlen($root)) {
            if (file_exists($walk . '/.git') || is_link($walk . '/.git')) {
                $folder = explode('/', $relative)[0] . ($walk === $root ? '' : '/' . substr($walk, strlen($root) + 1));
                if (!in_array($folder, $selection['git_overrides'] ?? [], true)) { throw new RuntimeException('Git working directory protected: ' . $folder . '. Review and explicitly include it before transferring.'); }
            }
            if ($walk === $root) { break; }
            $walk = dirname($walk);
        }
    }

    public static function inventory(bool $refresh = false, bool $media = true): array {
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
        return ['plugins' => array_values($plugins), 'themes' => $themes] + ($media?self::media():[]);
    }

    public static function media(int $page = 1, array $ids = []): array {
        if ($page < 1 || $page > 100000 || count($ids) > 10000) { throw new RuntimeException('Invalid media page or attachment selection.'); }
        foreach ($ids as $id) { if (!is_int($id) || $id < 1) { throw new RuntimeException('Invalid attachment ID.'); } }
        $args = ['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 60, 'offset' => ($page - 1) * 60, 'orderby' => 'date ID', 'order' => 'DESC'];
        if ($ids) { $args['post__in'] = $ids; $args['numberposts'] = count($ids); $args['offset'] = 0; }
        $media = [];
        foreach (get_posts($args) as $post) {
            $image = wp_attachment_is_image($post->ID) ? wp_get_attachment_image_src($post->ID, 'thumbnail') : false;
            $uploaded = $post->post_date_gmt !== '0000-00-00 00:00:00' ? str_replace(' ', 'T', $post->post_date_gmt) . 'Z' : null;
            $media[] = ['id' => $post->ID, 'name' => $post->post_title, 'file' => get_post_meta($post->ID, '_wp_attached_file', true),
                'date' => $post->post_modified_gmt, 'uploaded' => $uploaded, 'preview' => $image ? esc_url_raw($image[0], ['http', 'https']) : '', 'mime' => get_post_mime_type($post)];
        }
        $total = (int)wp_count_posts('attachment')->inherit;
        return ['media' => $media, 'media_total' => $total, 'media_page' => $page, 'media_more' => !$ids && $page * 60 < $total];
    }

    public static function selection(array $selection): array {
        $inventory = self::inventory(false,false);
        foreach (['plugins', 'themes'] as $kind) {
            $allowed = array_column($inventory[$kind], null, 'id');
            $selection[$kind] = array_values(array_unique($selection[$kind] ?? []));
            foreach ($selection[$kind] as $id) {
                if (!isset($allowed[$id]) || !empty($allowed[$id]['protected'])) { throw new RuntimeException('Unknown or protected ' . $kind . ' selection.'); }
            }
        }
        return self::media_selection($selection);
    }

    public static function compare_media(array $selection): bool {
        return in_array($selection['media'] ?? 'none', ['all', 'compare'], true) && empty($selection['media_force']);
    }

    public static function media_selection(array $selection): array {
        $mode = $selection['media'] ?? 'none';
        if (!in_array($mode, ['none', 'all', 'changed', 'selected', 'uploaded', 'compare'], true)) { throw new RuntimeException('Unknown media selection.'); }
        $selection['media'] = $mode;
        if (isset($selection['media_force']) && !is_bool($selection['media_force'])) { throw new RuntimeException('Force media retransfer must be true or false.'); }
        $selection['media_force'] = in_array($mode, ['all', 'compare'], true) && !empty($selection['media_force']);
        $selection['media_verify'] = self::compare_media($selection) && !empty($selection['media_verify']);
        $selection['since'] = max(0, (int)($selection['since'] ?? 0));
        if ($mode === 'changed' && !$selection['since']) { throw new RuntimeException('Choose a starting date or complete an initial media transfer first.'); }
        $selection['media_ids'] = array_values(array_unique(array_map('intval', $selection['media_ids'] ?? [])));
        foreach (['media_after', 'media_before'] as $field) {
            $date = $selection[$field] ?? '';
            if (!is_string($date)) { throw new RuntimeException('Upload dates must use YYYY-MM-DD.'); }
            if ($date !== '') {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
                if (!$parsed || $parsed->format('Y-m-d') !== $date) { throw new RuntimeException('Upload dates must use valid YYYY-MM-DD dates.'); }
            }
            $selection[$field] = $date;
        }
        if ($mode === 'uploaded') {
            if ($selection['media_after'] === '' && $selection['media_before'] === '') { throw new RuntimeException('Choose at least one upload-date boundary.'); }
            if ($selection['media_after'] && $selection['media_before'] && $selection['media_after'] > $selection['media_before']) { throw new RuntimeException('The upload end date must be on or after the start date.'); }
        }
        return $selection;
    }

    private static function attachment_ids(array $selection): Generator {
        if ($selection['media'] === 'selected') { yield from $selection['media_ids']; return; }
        $query = ['column' => 'post_date_gmt', 'inclusive' => true];
        if ($selection['media_after']) { $query['after'] = $selection['media_after'] . ' 00:00:00'; }
        if ($selection['media_before']) { $query['before'] = $selection['media_before'] . ' 23:59:59'; }
        $offset = 0;
        do {
            $ids = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 250, 'offset' => $offset, 'fields' => 'ids', 'date_query' => [$query], 'orderby' => 'ID', 'order' => 'ASC']);
            yield from $ids; $offset += count($ids);
        } while (count($ids) === 250);
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

    public static function destination(string $relative, array $selection, bool $check_git = true): string {
        self::safe_relative($relative);
        if (self::skipped($relative, $selection)) { throw new RuntimeException('Excluded file or protected development folder: ' . $relative); }
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
        if ($check_git) { self::assert_git_destination($relative, $path, $root, $selection); }
        return $path;
    }

    public static function assert_writable(string $target, string $relative): void {
        $ancestor = dirname($target);
        while (!file_exists($ancestor)) { $ancestor = dirname($ancestor); }
        if (!is_writable($ancestor) || (file_exists($target) && (!is_file($target) || !is_writable($target) || !is_writable(dirname($target))))) {
            throw new RuntimeException('Destination is not writable: ' . $relative);
        }
    }

    public static function scan(string $path, string $relative = '', array $selection = [], ?float $deadline = null): Generator {
        $deadline ??= hrtime(true)/1e9+45;
        if (hrtime(true)/1e9>=$deadline) { throw new RuntimeException('File traversal exceeded its 45-second limit. Narrow the selection.'); }
        CSM_Support::check_source_budget();
        if (self::skipped($relative, $selection)) { return; }
        if (!empty($selection['protect_source_git']) && is_dir($path) && (file_exists($path . '/.git') || is_link($path . '/.git')) && !in_array($relative, $selection['git_overrides'] ?? [], true)) { return; }
        if (is_link($path)) { throw new RuntimeException('Symbolic links are not transferable: ' . basename($path)); }
        if (is_file($path)) { yield $path; return; }
        if (!is_dir($path)) { return; }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $file) { yield from self::scan($file->getPathname(), $relative . '/' . $file->getFilename(), $selection,$deadline); }
    }

    public static function paths(array $selection): Generator {
        foreach (['plugins', 'themes'] as $kind) {
            foreach ($selection[$kind] as $id) {
                if (self::skipped($kind . '/' . $id, $selection)) { continue; }
                CSM_Progress::update(['phase' => 'Reading source component', 'component' => $kind . '/' . $id]);
                $root = self::root($kind, $id); $count = 0;
                if (!file_exists($root . '/' . $id)) { throw new RuntimeException('Selected source component is missing: ' . $id); }
                foreach (self::scan($root . '/' . $id, $kind . '/' . $id, $selection) as $file) {
                    $relative = $kind . '/' . substr($file, strlen($root) + 1);
                    self::safe_relative($relative);
                    yield [$relative, $file]; $count++;
                }
                if (!$count) { throw new RuntimeException('Selected source component has no files: ' . $id); }
            }
        }
        $root = self::root('uploads');
        if ($selection['media'] === 'compare') { foreach (CSM_Media::paths($selection['media_delta'] ?? '', $selection) as $entry) { if (!self::skipped($entry[0], $selection)) { yield $entry; } } return; }
        if (in_array($selection['media'], ['selected', 'uploaded'], true)) {
            $seen = [];
            foreach (self::attachment_ids($selection) as $id) {
                if (get_post_type($id) !== 'attachment') { throw new RuntimeException('Unknown attachment.'); }
                $file = get_post_meta($id, '_wp_attached_file', true);
                $metadata = wp_get_attachment_metadata($id);
                $paths = [$file];
                foreach ($metadata['sizes'] ?? [] as $size) { $paths[] = dirname($file) . '/' . $size['file']; }
                if (!empty($metadata['original_image'])) { $paths[] = dirname($file) . '/' . $metadata['original_image']; }
                foreach ($paths as $tail) {
                    if (!$tail || isset($seen[$tail])) { continue; }
                    $relative = 'uploads/' . ltrim(preg_replace('#^\./#', '', $tail), '/');
                    if (self::skipped($relative, $selection)) { continue; }
                    $path = self::destination($relative, $selection, !empty($selection['protect_source_git']));
                    if (!is_file($path)) { throw new RuntimeException('Missing attachment file: ' . $tail); }
                    $seen[$tail] = true; yield [$relative, $path];
                }
            }
        } elseif (in_array($selection['media'], ['all', 'changed'], true)) {
            foreach (self::scan($root, 'uploads', $selection) as $file) {
                $relative = 'uploads/' . substr($file, strlen($root) + 1);
                if ($selection['media'] === 'changed' && filemtime($file) < $selection['since']) { continue; }
                // Exclude executables, hidden server configuration and common backup/cache directories.
                if (preg_match('#(^|/)(cache|backups?|ai1wm-backups|wpvividbackups|\.git)(/|$)#i', $relative)) { continue; }
                try { self::destination($relative, $selection, !empty($selection['protect_source_git'])); } catch (RuntimeException $e) { continue; }
                yield [$relative, $file];
            }
        }
    }

    public static function stream(array $selection, ?array &$component_sizes = null): Generator {
        $selection = self::selection($selection);
        $component_sizes = ['plugins' => 0, 'themes' => 0, 'media' => 0];
        $started = time(); $count = 0; $hash = hash_init('sha256');
        $same = CSM_Components::unchanged($selection);
        $parents = [];
        foreach ($selection['themes'] as $theme) { $parents[$theme] = wp_get_theme($theme)->get('Template'); }
        yield CSM_Support::json(['type' => 'header', 'version' => 1, 'kind' => 'files', 'started' => $started, 'theme_parents' => $parents, 'active_plugins' => CSM_Activation::current(), 'trickle_plugin' => plugin_basename(CSM_FILE)]);
        foreach (self::paths($selection) as $entry) {
            [$relative, $path] = $entry;
            if (isset($same[$relative])) {
                if (!CSM_Components::verify($path, $same[$relative])) { throw new RuntimeException('Source file changed after comparison: ' . $relative . '. Retry preparation.'); }
                $line = CSM_Support::json(['type' => 'file-same', 'path' => $relative, 'sha256' => $same[$relative]['hash'], 'size' => filesize($path), 'mtime' => filemtime($path)]);
                hash_update($hash, $line); yield $line; $count++; continue;
            }
            CSM_Progress::update(['phase' => 'Exporting file', 'component' => $relative, 'count' => $count, 'total' => null, 'unit' => 'files', 'bytes' => 0]);
            $fp = fopen($path, 'rb');
            if (!$fp) { throw new RuntimeException('Cannot read file: ' . $relative); }
            try {
                $stat = fstat($fp); $size = $stat['size'];
                $component_sizes[str_starts_with($relative, 'uploads/') ? 'media' : explode('/', $relative)[0]] += $size;
                $line = CSM_Support::json(['type' => 'file', 'path' => $relative, 'size' => $size, 'mtime' => $stat['mtime'], 'mode' => ($stat['mode'] & 0111) ? 0755 : 0644]);
                hash_update($hash, $line); yield $line;
                $filehash = hash_init('sha256'); $remaining = $size;
                while ($remaining > 0) {
                    $bytes = fread($fp, min(65536, $remaining));
                    if ($bytes === false || $bytes === '') { throw new RuntimeException('File changed or became unreadable during transfer.'); }
                    $remaining -= strlen($bytes); hash_update($filehash, $bytes); yield $bytes;
                    CSM_Progress::update(['bytes' => $size - $remaining]);
                }
                if (fread($fp, 1) !== '' || fstat($fp)['mtime'] !== $stat['mtime']) { throw new RuntimeException('File changed during transfer: ' . $relative); }
                $checksum = hash_final($filehash);
                if (isset($entry[2]) && !hash_equals($entry[2], $checksum)) { throw new RuntimeException('Media contents changed after comparison: ' . $relative); }
                $line = CSM_Support::json(['type' => 'file-end', 'sha256' => $checksum]);
                hash_update($hash, $line); yield $line; $count++;
                CSM_Progress::update(['count' => $count]);
            } finally { fclose($fp); }
        }
        yield CSM_Support::json(['type' => 'end', 'files' => $count, 'started' => $started, 'sha256' => hash_final($hash)]);
    }

    public static function stream_step(array $selection, array &$cursor, array &$sizes): Generator {
        $cursor += ['header'=>false,'index'=>0,'offset'=>0,'hash'=>'','done'=>false];
        if ($cursor['done']) { return; }
        if (empty($cursor['planned'])) {
            $cursor['plan'] ??= [];
            foreach (self::paths($selection) as $entry) {
                if (isset($cursor['plan'][$entry[0]])) { continue; }
                $stat=stat($entry[1]); $cursor['plan'][$entry[0]]=[$entry[1],[$stat['size'],$stat['mtime'],$stat['ctime'],$stat['ino']],$entry[2] ?? null];
                if (CSM_Support::due()) { return; }
            }
            $cursor['planned']=true; $cursor['plan']=array_map(static fn($relative,$entry)=>[$relative,...$entry],array_keys($cursor['plan']),array_values($cursor['plan']));
        }
        if (!$cursor['header']) {
            $parents = []; foreach ($selection['themes'] as $theme) { $parents[$theme] = wp_get_theme($theme)->get('Template'); }
            $cursor['started'] = time(); $cursor['header'] = true;
            yield CSM_Support::json(['type'=>'header','version'=>2,'kind'=>'files','started'=>$cursor['started'],'theme_parents'=>$parents,'active_plugins'=>CSM_Activation::current(),'trickle_plugin'=>plugin_basename(CSM_FILE)]);
        }
        $same = CSM_Components::unchanged($selection); $index = 0;
        foreach ($cursor['plan'] as [$relative,$path,$planned_stamp,$expected_hash]) {
            CSM_Support::check_cancel();
            if ($index++ < $cursor['index']) { continue; }
            clearstatcache(true,$path); $stat = stat($path);
            if ([$stat['size'],$stat['mtime'],$stat['ctime'],$stat['ino']]!==$planned_stamp) { throw new RuntimeException('Source file changed after planning: '.$relative); }
            if (!$cursor['offset']) {
                $cursor['stamp'] = [$stat['size'],$stat['mtime'],$stat['ctime'],$stat['ino']];
                if (isset($same[$relative])) {
                    if (!CSM_Components::verify($path,$same[$relative])) { throw new RuntimeException('Source file changed after comparison: '.$relative); }
                    $line = CSM_Support::json(['type'=>'file-same','path'=>$relative,'sha256'=>$same[$relative]['hash'],'size'=>$stat['size'],'mtime'=>$stat['mtime']]);
                    $cursor['hash'] = CSM_Package::chain($cursor['hash'],$line); $cursor['index']++; yield $line; continue;
                }
                $line = CSM_Support::json(['type'=>'file','path'=>$relative,'size'=>$stat['size'],'mtime'=>$stat['mtime'],'mode'=>($stat['mode']&0111)?0755:0644]);
                $cursor['hash'] = CSM_Package::chain($cursor['hash'],$line);
                // offset=-1 means the header is durable, including for empty files.
                $cursor['offset'] = -1; yield $line;
            }
            if ($cursor['stamp'] !== [$stat['size'],$stat['mtime'],$stat['ctime'],$stat['ino']]) { throw new RuntimeException('Source file changed during packaging: '.$relative); }
            $fp = fopen($path,'rb'); $offset = max(0,$cursor['offset']);
            if (!$fp || fseek($fp,$offset)!==0) { throw new RuntimeException('Cannot resume source file: '.$relative); }
            try {
                while ($offset < $stat['size']) {
                    $bytes = fread($fp,min(262144,$stat['size']-$offset));
                    if ($bytes === false || $bytes === '') { throw new RuntimeException('Source file is truncated: '.$relative); }
                    $offset += strlen($bytes); $cursor['offset'] = $offset;
                    $component = str_starts_with($relative,'uploads/')?'media':explode('/',$relative)[0];
                    $sizes[$component] += strlen($bytes);
                    CSM_Progress::update(['phase'=>'Packaging file','component'=>$relative,'count'=>$index-1,'bytes'=>$offset]);
                    yield $bytes;
                }
            } finally { fclose($fp); }
            clearstatcache(true,$path); $after = stat($path);
            if ($cursor['stamp'] !== [$after['size'],$after['mtime'],$after['ctime'],$after['ino']]) { throw new RuntimeException('Source file changed during packaging: '.$relative); }
            $digest = CSM_Support::hash_file($path);
            if ($expected_hash!==null && !hash_equals($expected_hash,$digest)) { throw new RuntimeException('Media changed after comparison: '.$relative); }
            $line = CSM_Support::json(['type'=>'file-end','sha256'=>$digest]);
            $cursor['hash'] = CSM_Package::chain($cursor['hash'],$line); $cursor['index']++; $cursor['offset']=0; yield $line;
        }
        $cursor['done'] = true;
        yield CSM_Support::json(['type'=>'end','files'=>$cursor['index'],'started'=>$cursor['started'],'sha256'=>$cursor['hash']]);
    }

    public static function stage_step($fp, array &$job): bool {
        $state = $job['imports']['files'] ?? null;
        if (!$state) {
            $header = CSM_Support::line($fp);
            if (($header['type'] ?? '') !== 'header' || ($header['version'] ?? 0) !== 2 || ($header['kind'] ?? '') !== 'files') { throw new RuntimeException('Invalid file stream.'); }
            CSM_Activation::source($job,$header);
            foreach ($header['theme_parents'] ?? [] as $parent) {
                if ($parent && (!in_array($parent,$job['selection']['themes'],true) || self::skipped('themes/'.$parent,$job['selection'])) && !wp_get_theme($parent)->exists()) { throw new RuntimeException('Include the required parent theme: '.$parent); }
            }
            $state = ['header'=>$header,'offset'=>ftell($fp),'hash'=>'','seen'=>[],'components'=>[],'current'=>null];
            $job['files'] = [];
        }
        $dir=CSM_Support::private_dir().'/'.$job['id'];
        if (!is_dir($dir) && !mkdir($dir,0700)) { throw new RuntimeException('Cannot create transfer staging directory.'); }
        if (fseek($fp,$state['offset'])!==0) { throw new RuntimeException('Cannot resume file import.'); }
        CSM_Support::step();
        do {
            CSM_Support::check_cancel();
            if (!$state['current']) {
                $record=CSM_Support::line($fp); $type=$record['type'] ?? '';
                if ($type==='end') {
                    if (count($job['files'])!==$record['files'] || !hash_equals($state['hash'],$record['sha256']) || fread($fp,1)!=='') { throw new RuntimeException('Incomplete file stream or checksum failure.'); }
                    foreach (['plugins','themes'] as $kind) { foreach ($job['selection'][$kind] as $component) { if (!self::skipped($kind.'/'.$component,$job['selection']) && empty($state['components'][$kind.'/'.$component])) { throw new RuntimeException('Selected component was omitted from the file stream.'); } } }
                    $job['media_watermark']=$state['header']['started']; unset($job['imports']['files']); return true;
                }
                if (!in_array($type,['file','file-same'],true) || !is_string($record['path'] ?? null) || isset($state['seen'][$record['path']])) { throw new RuntimeException('Invalid or duplicate file header.'); }
                $target=self::destination($record['path'],$job['selection']);
                $state['seen'][$record['path']]=true; $parts=explode('/',$record['path']); $state['components'][$parts[0].'/'.$parts[1]]=true;
                $state['hash']=CSM_Package::chain($state['hash'],CSM_Support::json($record));
                if ($type==='file-same') {
                    if (!is_string($record['sha256'] ?? null) || !is_file($target) || !hash_equals($record['sha256'],CSM_Support::hash_file($target))) { throw new RuntimeException('Unchanged destination file failed verification: '.$record['path']); }
                    $job['files'][]=['path'=>$record['path'],'sha256'=>$record['sha256'],'size'=>filesize($target),'unchanged'=>true];
                } else {
                    if (!is_int($record['size'] ?? null) || $record['size']<0) { throw new RuntimeException('Invalid file size.'); }
                    $state['current']=['record'=>$record,'written'=>0,'stage'=>$dir.'/'.count($job['files'])];
                }
            }
            if ($current=$state['current']) {
                $record=$current['record']; if ($current['written']>0 && (!is_file($current['stage']) || filesize($current['stage'])<$current['written'])) { throw new RuntimeException('Staged file checkpoint is missing or truncated.'); } $out=fopen($current['stage'],'c+b');
                if (!$out || !ftruncate($out,$current['written']) || fseek($out,$current['written'])!==0) { throw new RuntimeException('Cannot resume staged file.'); }
                try {
                    do {
                        $remaining=$record['size']-$current['written'];
                        if ($remaining<=0) { break; }
                        $bytes=fread($fp,min(262144,$remaining));
                        if ($bytes===false || $bytes==='') { throw new RuntimeException('Truncated file stream.'); }
                        CSM_Support::write($out,$bytes); $current['written']+=strlen($bytes);
                        CSM_Progress::update(['phase'=>'Staging file','component'=>$record['path'],'count'=>count($job['files']),'bytes'=>$current['written']]);
                    } while (!CSM_Support::due());
                    fflush($out);
                } finally { fclose($out); }
                $state['current']=$current;
                if ($current['written']===$record['size']) {
                    $end=CSM_Support::line($fp);
                    if (($end['type'] ?? '')!=='file-end' || !hash_equals(CSM_Support::hash_file($current['stage']),$end['sha256'] ?? '')) { throw new RuntimeException('File checksum mismatch.'); }
                    $state['hash']=CSM_Package::chain($state['hash'],CSM_Support::json($end));
                    $job['files'][]=['path'=>$record['path'],'mtime'=>(int)$record['mtime'],'size'=>$record['size'],'mode'=>!str_starts_with($record['path'],'uploads/') && (($record['mode'] ?? 0644)&0111)?0755:0644,'stage'=>$current['stage'],'sha256'=>$end['sha256']];
                    $state['current']=null;
                }
            }
            $state['offset']=ftell($fp);
        } while (!CSM_Support::due());
        $job['imports']['files']=$state; CSM_Jobs::save($job); return false;
    }

    public static function stage($fp, array &$job): void {
        $header = CSM_Support::line($fp);
        if (($header['type'] ?? '') !== 'header' || ($header['version'] ?? 0) !== 1 || ($header['kind'] ?? '') !== 'files') { throw new RuntimeException('Invalid file stream.'); }
        CSM_Activation::source($job, $header);
        foreach ($header['theme_parents'] ?? [] as $parent) {
            if ($parent && (!in_array($parent, $job['selection']['themes'], true) || self::skipped('themes/' . $parent, $job['selection'])) && !wp_get_theme($parent)->exists()) { throw new RuntimeException('Include the required parent theme: ' . $parent); }
        }
        $dir = CSM_Support::private_dir() . '/' . $job['id'];
        if (!is_dir($dir) && !mkdir($dir, 0700)) { throw new RuntimeException('Cannot create transfer staging directory.'); }
        $files = []; $seen = []; $components = []; $hash = hash_init('sha256');
        while (true) {
            $record = CSM_Support::line($fp);
            if (($record['type'] ?? '') === 'end') {
                if (count($files) !== $record['files'] || !hash_equals(hash_final($hash), $record['sha256']) || fread($fp, 1) !== '') { throw new RuntimeException('Incomplete file stream or checksum failure.'); }
                foreach (['plugins', 'themes'] as $kind) {
                    foreach ($job['selection'][$kind] as $component) { if (!self::skipped($kind . '/' . $component, $job['selection']) && empty($components[$kind . '/' . $component])) { throw new RuntimeException('Selected component was omitted from the file stream.'); } }
                }
                $job['files'] = $files; $job['media_watermark'] = $header['started']; return;
            }
            if (($record['type'] ?? '') === 'file-same') {
                $path = $record['path'] ?? ''; $target = self::destination($path, $job['selection']);
                if (isset($seen[$path]) || !is_string($record['sha256'] ?? null) || !is_file($target) || !hash_equals($record['sha256'], CSM_Support::hash_file($target))) { throw new RuntimeException('Unchanged destination file failed verification: ' . $path); }
                $parts = explode('/', $path); $components[$parts[0] . '/' . $parts[1]] = true; $seen[$path] = true;
                hash_update($hash, CSM_Support::json($record));
                $files[] = ['path' => $path, 'sha256' => $record['sha256'], 'size' => filesize($target), 'unchanged' => true]; continue;
            }
            if (($record['type'] ?? '') !== 'file' || !is_int($record['size']) || $record['size'] < 0 || isset($seen[$record['path']])) { throw new RuntimeException('Invalid file header.'); }
            self::destination($record['path'], $job['selection']);
            CSM_Progress::update(['phase' => 'Receiving and verifying file', 'component' => $record['path'], 'count' => count($files), 'total' => null, 'unit' => 'files', 'bytes' => 0]);
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
                    CSM_Progress::update(['bytes' => $record['size'] - $remaining]);
                }
            } finally { fclose($out); }
            $end = CSM_Support::line($fp);
            if (($end['type'] ?? '') !== 'file-end' || !hash_equals(hash_final($filehash), $end['sha256'])) { throw new RuntimeException('File checksum mismatch.'); }
            hash_update($hash, CSM_Support::json($end));
            $files[] = ['path' => $record['path'], 'mtime' => (int)$record['mtime'], 'size' => $record['size'], 'mode' => !str_starts_with($record['path'], 'uploads/') && (($record['mode'] ?? 0644) & 0111) ? 0755 : 0644, 'stage' => $staged, 'sha256' => $end['sha256']];
            CSM_Progress::update(['count' => count($files)]);
        }
    }
}
