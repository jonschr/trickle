<?php
defined('ABSPATH') || exit;

final class CSM_Updates {
    public static function assert_version($version): void {
        if (!is_string($version) || $version !== CSM_VERSION) {
            throw new RuntimeException('Trickle versions must match before transferring. This site: ' . CSM_VERSION . '; connected site: ' . (is_string($version) && $version !== '' ? $version : 'unknown') . '. Update the older installation, then refresh versions.');
        }
    }

    public static function assert_build(array $remote): void {
        if (!empty($remote['remote_build_update']) && (!is_string($remote['trickle_build'] ?? null) || !hash_equals(self::fingerprint(),$remote['trickle_build']))) {
            throw new RuntimeException('Trickle builds must match before transferring. Install the local Trickle build on the remote site.');
        }
    }

    public static function assert_job(array $record): void {
        if (($record['plugin_version'] ?? '') !== CSM_VERSION) { throw new RuntimeException('Trickle changed since this transfer was prepared. Discard and prepare again. Existing rollback and recovery controls remain available.'); }
    }

    public static function capabilities(): array {
        return ['remote_update' => 1, 'remote_build_update'=>1, 'trickle_build'=>self::fingerprint(), 'remote_updates_allowed' => true,
            'can_update_trickle' => !isset($GLOBALS['_csm_credential']) || !empty($GLOBALS['_csm_credential']['write'])];
    }

    private static function checker() {
        $checker = $GLOBALS['trickle_update_checker'] ?? null;
        if (!$checker) { throw new RuntimeException('Trickle JSON updater is unavailable. Reinstall Trickle manually.'); }
        return $checker;
    }

    private static function release() {
        $info = @self::checker()->requestInfo();
        if (!$info || !is_string($info->version ?? null) || !is_string($info->download_url ?? null) || wp_parse_url($info->download_url, PHP_URL_SCHEME) !== 'https' || !wp_parse_url($info->download_url, PHP_URL_HOST)) {
            throw new RuntimeException('The configured Trickle JSON feed did not return a usable HTTPS release. Try again after the release is published.');
        }
        return $info;
    }

    public static function info(): array {
        $info = self::release(); $reason = '';
        try { self::preflight(); } catch (Throwable $e) { $reason = $e->getMessage(); }
        return ['ready' => $reason === '', 'blocked_reason' => $reason] + self::capabilities() + ['installed' => CSM_VERSION, 'release' => $info->version,
            'file_modifications' => wp_is_file_mod_allowed('trickle'), 'requires_php' => $info->requires_php ?? '', 'requires_wp' => $info->requires ?? ''];
    }

    private static function preflight(): void {
        CSM_Segments::prune_sources();
        if (get_option('_csm_control') || get_option('_csm_active_job') || glob(CSM_Support::private_dir() . '/segment-*.source.owner')) { throw new RuntimeException('Finish and discard retained transfers on this site and its connected controllers before updating Trickle.'); }
        if (!CSM_Plugin::loader_ready()) { throw new RuntimeException('Open the remote Trickle dashboard once to refresh its recovery loader before updating.'); }
        if (!wp_is_file_mod_allowed('trickle')) { throw new RuntimeException('WordPress disallows file modifications on the remote site.'); }
        if (is_link(WP_PLUGIN_DIR . '/' . dirname(plugin_basename(CSM_FILE))) || CSM_Files::git_folders(['plugins' => [basename(dirname(CSM_FILE))]])) { throw new RuntimeException('Trickle is inside a Git working directory. Update that checkout manually.'); }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        if (get_filesystem_method([], WP_PLUGIN_DIR) !== 'direct') { throw new RuntimeException('Remote updates require direct filesystem access; update through the remote WordPress dashboard instead.'); }
    }

    public static function install(string $target, string $from): array {
        if ($from !== CSM_VERSION) { throw new RuntimeException('The remote Trickle version changed. Refresh versions before updating.'); }
        if (!preg_match('/^[0-9][0-9A-Za-z.+_-]{0,39}$/D', $target) || version_compare($target, CSM_VERSION, '<=')) { throw new RuntimeException('Remote updates must be upgrades. Update this controlling site first when the remote is newer.'); }
        self::preflight();
        $info = self::release();
        if ($info->version !== $target) { throw new RuntimeException('The JSON feed currently publishes Trickle ' . $info->version . ', but the controlling site requires ' . $target . '. Publish the matching release or update both installations manually.'); }
        if ((!empty($info->requires_php) && version_compare(PHP_VERSION, $info->requires_php, '<')) || (!empty($info->requires) && version_compare(get_bloginfo('version'), $info->requires, '<'))) { throw new RuntimeException('The published Trickle release requires a newer PHP or WordPress version on this site.'); }
        return self::apply_package($target, $info->download_url);
    }

    private static function runtime_files(string $root): array {
        $files = ['trickle.php'=>$root.'/trickle.php'];
        foreach (['includes','assets','vendor'] as $directory) {
            $base=$root.'/'.$directory;
            if (!is_dir($base) || is_link($base)) { throw new RuntimeException('Trickle runtime directory is missing or linked: '.$directory); }
            $filtered=new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), static function($entry) use ($root) {
                if (CSM_Files::excluded(substr($entry->getPathname(),strlen($root)+1))) { return false; }
                if ($entry->isLink()) { throw new RuntimeException('Trickle runtime contains a symbolic link.'); }
                return true;
            });
            $iterator=new RecursiveIteratorIterator($filtered);
            foreach ($iterator as $entry) {
                $relative=substr($entry->getPathname(),strlen($root)+1);
                if (CSM_Files::excluded($relative)) { continue; }
                if ($entry->isLink()) { throw new RuntimeException('Trickle runtime contains a symbolic link.'); }
                if ($entry->isFile()) { $files[$relative]=$entry->getPathname(); }
            }
        }
        ksort($files, SORT_STRING); return $files;
    }

    public static function fingerprint(?string $root = null): string {
        $root=rtrim($root ?? dirname(CSM_FILE),'/'); $hash=hash_init('sha256');
        foreach (self::runtime_files($root) as $relative=>$path) {
            $digest=hash_file('sha256',$path);
            if ($digest===false) { throw new RuntimeException('Cannot read the local Trickle build.'); }
            hash_update($hash,$relative."\n".$digest."\n");
        }
        return hash_final($hash);
    }

    public static function package(): array {
        $root=dirname(CSM_FILE);$path=CSM_Support::private_dir().'/trickle-build-'.bin2hex(random_bytes(8)).'.zip';
        try {
            $build=self::fingerprint();$files=self::runtime_files($root);
            if (class_exists('ZipArchive')) {
                $zip=new ZipArchive();if ($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) { throw new RuntimeException('Cannot create the local Trickle package.'); }
                try { foreach($files as$relative=>$file) { if(!$zip->addFile($file,'trickle/'.$relative))throw new RuntimeException('Cannot package '.$relative); } }
                finally { if(!$zip->close())throw new RuntimeException('Cannot finish the local Trickle package.'); }
            } else {
                require_once ABSPATH.'wp-admin/includes/class-pclzip.php';$zip=new PclZip($path);
                if (!$zip->create(array_values($files),PCLZIP_OPT_REMOVE_PATH,$root,PCLZIP_OPT_ADD_PATH,'trickle')) { throw new RuntimeException('Cannot create the local Trickle package.'); }
            }
            if(filesize($path)>700000)throw new RuntimeException('This Trickle build is too large for direct installation. Upload its ZIP manually.');
            if(!hash_equals($build,self::fingerprint()))throw new RuntimeException('Local Trickle files changed during packaging. Retry the update.');
            return ['target'=>CSM_VERSION,'build'=>$build,'sha256'=>hash_file('sha256',$path),'package'=>base64_encode(file_get_contents($path))];
        } finally { if(is_file($path))unlink($path); }
    }

    public static function build_info(): array {
        $reason='';try{self::preflight();}catch(Throwable $e){$reason=$e->getMessage();}
        return ['ready'=>$reason==='', 'blocked_reason'=>$reason, 'installed'=>CSM_VERSION, 'file_modifications'=>wp_is_file_mod_allowed('trickle')] + self::capabilities();
    }

    private static function validate_archive(string $path): void {
        require_once ABSPATH.'wp-admin/includes/class-pclzip.php';
        $zip=new PclZip($path);$entries=$zip->listContent();
        if(!is_array($entries)||count($entries)>500)throw new RuntimeException('Invalid or oversized Trickle archive.');
        $expanded=0;
        foreach($entries as$entry){
            $name=$entry['filename'];
            if(!str_starts_with($name,'trickle/'))throw new RuntimeException('The package contains files outside Trickle.');
            $relative=rtrim(substr($name,8),'/');if($relative==='')continue;
            CSM_Files::safe_relative($relative);
            if(CSM_Files::excluded($relative)||($relative!=='trickle.php'&&!preg_match('#^(includes|assets|vendor)(/|$)#',$relative)))throw new RuntimeException('The package contains unrelated or protected files.');
            $expanded+=(int)$entry['size'];if($expanded>8*1024*1024)throw new RuntimeException('Expanded Trickle package exceeds its size limit.');
        }
    }

    public static function install_build(array $data): array {
        self::preflight();
        if (($data['from'] ?? null)!==CSM_VERSION || !is_string($data['from_build'] ?? null) || !hash_equals(self::fingerprint(),$data['from_build'])) { throw new RuntimeException('Remote Trickle changed. Refresh and retry.'); }
        $target=$data['target']??'';$build=$data['build']??'';$digest=$data['sha256']??'';
        if (!is_string($target)||!preg_match('/^[0-9][0-9A-Za-z.+_-]{0,39}$/D',$target)||!is_string($build)||!preg_match('/^[a-f0-9]{64}$/D',$build)||!is_string($digest)||!preg_match('/^[a-f0-9]{64}$/D',$digest)||!is_string($data['package']??null)||strlen($data['package'])>940000) { throw new RuntimeException('Invalid local Trickle package metadata.'); }
        $bytes=base64_decode($data['package'],true);
        if($bytes===false||strlen($bytes)>700000||!hash_equals($digest,hash('sha256',$bytes)))throw new RuntimeException('Local Trickle package checksum mismatch.');
        $path=CSM_Support::private_dir().'/trickle-upload-'.bin2hex(random_bytes(8)).'.zip';
        try { CSM_Support::atomic_bytes($path,$bytes);self::validate_archive($path);return self::apply_package($target,$path,$build); }
        finally { if(is_file($path))unlink($path); }
    }

    private static function apply_package(string $target, string $package, ?string $build = null): array {
        $plugin = plugin_basename(CSM_FILE);
        // The recovery loader filters this option during RPCs; preserve the complete real list.
        $isolation = $GLOBALS['_csm_active_plugins_filter'] ?? null;
        if (defined('CSM_ORIGINAL_ACTIVE_PLUGINS') && !$isolation) { throw new RuntimeException('Refresh the remote recovery loader before updating Trickle.'); }
        $active = defined('CSM_ORIGINAL_ACTIVE_PLUGINS') ? CSM_ORIGINAL_ACTIVE_PLUGINS : get_option('active_plugins', []);
        $publish = static function ($updates) use ($plugin, $target, $package) {
            if (!is_object($updates)) { $updates = (object)['response' => [], 'no_update' => [], 'checked' => []]; }
            $updates->response[$plugin] = (object)['slug' => basename(dirname(CSM_FILE)), 'plugin' => $plugin, 'new_version' => $target, 'package' => $package, 'url' => 'https://github.com/jonschr/trickle'];
            return $updates;
        };
        $validate = static function ($source, $remote_source, $upgrader, $extra) use ($plugin, $target, $build) {
            if (is_wp_error($source) || ($extra['plugin'] ?? '') !== $plugin) { return $source; }
            $main = trailingslashit($source) . basename(CSM_FILE);
            $header = is_file($main) ? get_file_data($main, ['name' => 'Plugin Name', 'version' => 'Version', 'php' => 'Requires PHP', 'wp' => 'Requires at least']) : [];
            if (basename(untrailingslashit($source)) !== basename(dirname(CSM_FILE)) || ($header['name'] ?? '') !== 'Trickle' || ($header['version'] ?? '') !== $target) { return new WP_Error('trickle_package', 'The downloaded ZIP does not contain the expected Trickle version and directory.'); }
            if ((!empty($header['php']) && version_compare(PHP_VERSION, $header['php'], '<')) || (!empty($header['wp']) && version_compare(get_bloginfo('version'), $header['wp'], '<'))) { return new WP_Error('trickle_requirements', 'The downloaded Trickle package is incompatible with this site.'); }
            if ($build !== null) {
                try {
                    if (!hash_equals($build,self::fingerprint(untrailingslashit($source)))) { return new WP_Error('trickle_build','The uploaded package does not match the local Trickle build.'); }
                    foreach (self::runtime_files(untrailingslashit($source)) as $relative=>$path) {
                        if (str_ends_with($relative,'.php')) { try { token_get_all(file_get_contents($path),TOKEN_PARSE); } catch (ParseError $error) { return new WP_Error('trickle_php',$relative.' has a PHP parse error on PHP '.PHP_VERSION.': '.$error->getMessage()); } }
                    }
                }
                catch (Throwable $error) { return new WP_Error('trickle_build',$error->getMessage()); }
            }
            return $source;
        };
        add_filter('site_transient_update_plugins', $publish, PHP_INT_MAX);
        add_filter('upgrader_source_selection', $validate, PHP_INT_MAX, 4);
        ignore_user_abort(true);
        if ($isolation) { remove_filter('option_active_plugins', $isolation, PHP_INT_MAX); }
        try {
            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            $result = $upgrader->upgrade($plugin);
            if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
            if (is_wp_error($upgrader->skin->result)) { throw new RuntimeException($upgrader->skin->result->get_error_message()); }
            if (!$result) { throw new RuntimeException('WordPress could not install the Trickle update. Check the remote diagnostics and filesystem permissions.'); }
            $header = get_file_data(CSM_FILE, ['version' => 'Version']);
            if ($header['version'] !== $target) { throw new RuntimeException('The installed Trickle version does not match the requested release. Verify the remote installation manually.'); }
            CSM_Plugin::install_loader();
            return ['installed' => $target, 'updated' => true] + ($build !== null ? ['trickle_build'=>$build] : []);
        } finally {
            update_option('active_plugins', $active);
            if ($isolation) { add_filter('option_active_plugins', $isolation, PHP_INT_MAX); }
            remove_filter('site_transient_update_plugins', $publish, PHP_INT_MAX);
            remove_filter('upgrader_source_selection', $validate, PHP_INT_MAX);
            if (function_exists('opcache_reset')) { opcache_reset(); }
        }
    }
}
