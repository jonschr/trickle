<?php
defined('ABSPATH') || exit;

/** Build complete components privately, then journal two directory renames. */
final class CSM_Directories {
    public static function backup_bytes(array $paths, array $selection, ?array &$state, bool $bounded = true): ?int {
        if ($state === null) {
            $roots = [];
            foreach ($paths as $relative=>$path) {
                $parts=explode('/', $relative, 3);
                $root=in_array($parts[0], ['plugins','themes'], true)?$parts[0].'/'.$parts[1]:$relative;
                $roots[$root]=CSM_Files::destination($root,$selection);
            }
            $state=['pending'=>array_values($roots),'bytes'=>0];
        }
        while ($state['pending']) {
            $path=array_pop($state['pending']);
            if (is_link($path)) { throw new RuntimeException('Destination component contains a symbolic link.'); }
            if (is_dir($path)) {
                $names=scandir($path);
                if ($names===false) { throw new RuntimeException('Cannot measure component backup.'); }
                foreach (array_diff($names,['.','..']) as $name) { $state['pending'][]=$path.'/'.$name; }
            } elseif (is_file($path)) { $state['bytes']+=filesize($path); }
            if ($bounded && CSM_Support::due()) { return null; }
        }
        return $state['bytes'];
    }

    public static function prepare(array &$job): bool {
        $work = &$job['apply_work'];
        if (!isset($work['directories'])) {
            $roots = [];
            foreach ($job['file_plan']['paths'] as $relative => $target) {
                [$kind, $component] = explode('/', $relative, 3);
                if (in_array($kind, ['plugins', 'themes'], true)) { $roots[$kind . '/' . $component] = true; }
            }
            $work['directories'] = ['roots'=>array_keys($roots), 'ops'=>[], 'index'=>0];
            CSM_Jobs::save($job);
        }
        $state = &$work['directories'];
        while ($state['index'] < count($state['roots'])) {
            $relative = $state['roots'][$state['index']];
            $target = CSM_Files::destination($relative, $job['selection']);
            // Single-file plugins use the same whole-component rename protocol.
            if (!isset($state['building'])) {
                $dir = $job['backup_dir'] . '/component-' . $state['index'];
                $copy_backend = self::storage($dir, $target);
                $state['building'] = ['path'=>$relative, 'directory'=>true, 'backup'=>$dir.'/original', 'stage'=>$dir.'/incoming', 'journal'=>$dir.'/swap.json', 'existed'=>file_exists($target), 'copy_backend'=>$copy_backend, 'manifest'=>[], 'incoming'=>[]];
                $expected = [];
                foreach ($job['file_plan']['signature'] ?? [] as $file=>$signature) {
                    if ($file===$relative || str_starts_with($file,$relative.'/')) { $expected[$file]=$signature[1]; }
                }
                $state['building']['expected']=$expected;
                CSM_Jobs::save($job);
            }
            $op = &$state['building'];
            if (!self::build($op, $target, $job['file_plan']['new'], $job['selection'], $state['build'])) { CSM_Jobs::save($job); return false; }
            if (!empty($op['copy_backend']) && !self::snapshot($op,$target,$state['snapshot'])) { CSM_Jobs::save($job); return false; }
            $state['ops'][] = $op;
            unset($state['building'], $state['build'], $state['snapshot']);
            $state['index']++;
            CSM_Jobs::save($job);
            if (CSM_Support::due()) { return false; }
        }
        return true;
    }

    private static function storage(string $dir, string $target): bool {
        if (CSM_Support::public_path($dir) || is_link($dir)) { throw new RuntimeException('Component staging must be outside the public directory.'); }
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) { throw new RuntimeException('Cannot create private component staging.'); }
        $parent = dirname($target);
        if (!is_dir($parent) || !is_writable($parent)) { throw new RuntimeException('Component parent directory is not writable.'); }
        // Bind mounts can report the same device while still rejecting directory renames.
        $probe=$dir.'/probe-'.bin2hex(random_bytes(8)); $outside=$parent.'/.trickle-probe-'.bin2hex(random_bytes(8));
        if (!mkdir($probe,0700)) { throw new RuntimeException('Cannot test component replacement storage.'); }
        if (@rename($probe,$outside)) {
            if (!rmdir($outside)) { throw new RuntimeException('Cannot clean up empty component storage probe.'); }
            return false;
        }
        $error=error_get_last(); rmdir($probe);
        if (!str_contains(strtolower($error['message'] ?? ''),'cross-device')) { throw new RuntimeException('Cannot prepare component replacement: '.($error['message'] ?? 'Directory rename failed.')); }
        return true;
    }

    private static function snapshot(array $op, string $target, ?array &$state): bool {
        if (!$op['existed']) { return true; }
        return self::copy_tree($op['manifest'],$op['path'],$target,$op['backup'],$state);
    }

    private static function copy_tree(array $manifest, string $relative, string $source, string $target, ?array &$state, ?callable $checkpoint = null): bool {
        $state ??= ['cursor'=>0,'file'=>null]; $paths=array_keys($manifest);
        while ($state['cursor']<count($paths)) {
            CSM_Support::check_cancel();
            $path=$paths[$state['cursor']]; $file=$manifest[$path]; $suffix=substr($path,strlen($relative));
            if (is_link($source.$suffix)) { throw new RuntimeException('Component snapshot contains a symbolic link: '.$path); }
            if (!empty($file['dir'])) {
                if (!is_dir($source.$suffix)) { throw new RuntimeException('Component snapshot folder is missing: '.$path); }
                if (!is_dir($target.$suffix) && !wp_mkdir_p($target.$suffix)) { throw new RuntimeException('Cannot create component folder: '.$path); }
                if (!chmod($target.$suffix,$file['mode']&0777)) { throw new RuntimeException('Cannot set component folder permissions.'); }
            } elseif ($checkpoint) {
                if (!CSM_Plugin::install_file_step($source.$suffix,$target.$suffix,$file['mtime'],$file['mode'],$state['file'],static function($file_state)use(&$state,$checkpoint){$state['file']=$file_state;$checkpoint($state);})) { $checkpoint($state); return false; }
                if (!hash_equals($file['sha256'],CSM_Support::hash_file($target.$suffix))) { throw new RuntimeException('Installed component checksum failed: '.$path); }
                $state['file']=null;
            } elseif (!self::copy($source.$suffix,$target.$suffix,$file,$state['file'])) { return false; }
            $state['cursor']++;
            if ($checkpoint) { $checkpoint($state); }
            if (CSM_Support::due()) { return false; }
        }
        return true;
    }

    private static function remove_tree(array $op, string $target, array &$state): bool {
        $paths=array_reverse(array_keys($op['manifest'])); $state['remove_cursor'] ??= 0;
        while ($state['remove_cursor']<count($paths)) {
            CSM_Support::check_cancel();
            $path=$target.substr($paths[$state['remove_cursor']],strlen($op['path']));
            clearstatcache(true,$path);
            if (is_link($path)) { throw new RuntimeException('Destination component acquired a symbolic link.'); }
            if (is_dir($path)) { if (!rmdir($path)) { throw new RuntimeException('Cannot remove old component folder.'); } }
            elseif (file_exists($path) && !unlink($path)) { throw new RuntimeException('Cannot remove old component file.'); }
            $state['remove_cursor']++;
            CSM_Support::atomic_json($op['journal'],$state);
            if (CSM_Support::due()) { return false; }
        }
        if (file_exists($target)) { throw new RuntimeException('Old component directory remains after removal.'); }
        return true;
    }

    private static function preserved(string $relative, string $path, array $selection): bool {
        return CSM_Files::skipped($relative, $selection) || (is_dir($path) && (file_exists($path.'/.git') || is_link($path.'/.git')) && !in_array($relative, $selection['git_overrides'] ?? [], true));
    }

    private static function copy(string $source, string $target, array $file, ?array &$state): bool {
        if (!is_dir(dirname($target)) && !wp_mkdir_p(dirname($target))) { throw new RuntimeException('Cannot create staged component directory.'); }
        if (!CSM_Plugin::copy_step($source, $target, $state)) { return false; }
        if (!hash_equals($file['sha256'], CSM_Support::hash_file($target))) { throw new RuntimeException('Component copy checksum failed: '.basename($source)); }
        if (!chmod($target, $file['mode'] & 0777) || !touch($target, $file['mtime'])) { throw new RuntimeException('Cannot set staged component metadata.'); }
        $state = null;
        return true;
    }

    /** Walk every destination entry, retaining excluded paths, then fill the source snapshot. */
    private static function build(array &$op, string $target, array $new, array $selection, ?array &$state): bool {
        $state ??= ['pending'=>file_exists($target)?[[$target, $op['path'], false]]:[], 'phase'=>'destination', 'index'=>0];
        if ($state['phase'] === 'destination') {
            while ($state['pending']) {
                CSM_Support::check_cancel();
                [$path, $relative, $preserved] = end($state['pending']);
                if (is_link($path)) { throw new RuntimeException('Destination component contains a symbolic link: '.$relative); }
                $preserved = $preserved || self::preserved($relative, $path, $selection);
                $suffix = substr($relative, strlen($op['path']));
                if (is_dir($path)) {
                    $op['manifest'][$relative] = ['dir'=>true, 'size'=>0, 'mtime'=>filemtime($path), 'mode'=>fileperms($path)&0777, 'preserved'=>$preserved];
                    $names = scandir($path);
                    if ($names === false) { throw new RuntimeException('Cannot list destination component: '.$relative); }
                    if ($preserved && !is_dir($op['stage'].$suffix) && !wp_mkdir_p($op['stage'].$suffix)) { throw new RuntimeException('Cannot preserve destination folder.'); }
                    if ($preserved) { $op['incoming'][$relative]=$op['manifest'][$relative]; }
                    array_pop($state['pending']);
                    foreach (array_reverse(array_diff($names, ['.', '..'])) as $name) { $state['pending'][] = [$path.'/'.$name, $relative.'/'.$name, $preserved]; }
                } elseif (is_file($path)) {
                    $file = $op['manifest'][$relative] ?? ['sha256'=>CSM_Support::hash_file($path), 'size'=>filesize($path), 'mtime'=>filemtime($path), 'mode'=>fileperms($path)&0777, 'preserved'=>$preserved];
                    if (array_key_exists($relative,$op['expected'] ?? []) && $op['expected'][$relative] !== $file['sha256']) { throw new RuntimeException('Destination changed while staging component: '.$relative); }
                    $op['manifest'][$relative] = $file;
                    if ($preserved && !self::copy($path, $op['stage'].$suffix, $file, $state['copy'])) { return false; }
                    if ($preserved) { $op['incoming'][$relative]=$file; }
                    array_pop($state['pending']);
                } else { throw new RuntimeException('Destination component entry is missing or unsupported: '.$relative); }
                if (CSM_Support::due()) { return false; }
            }
            foreach ($op['expected'] ?? [] as $relative=>$hash) {
                if ($hash !== null && !isset($op['manifest'][$relative])) { throw new RuntimeException('Destination file disappeared while staging component: '.$relative); }
            }
            $state['phase'] = 'source';
            $state['files'] = array_keys(array_filter($new, static fn($file, $relative)=>$relative===$op['path'] || str_starts_with($relative,$op['path'].'/'), ARRAY_FILTER_USE_BOTH));
        }
        while ($state['index'] < count($state['files'])) {
            CSM_Support::check_cancel();
            $relative = $state['files'][$state['index']]; $file = $new[$relative];
            $destination = CSM_Files::destination($relative, $selection);
            $source = !empty($file['unchanged']) ? $destination : $file['stage'];
            $target_file = $op['stage'].substr($relative, strlen($op['path']));
            CSM_Progress::update(['phase'=>'Building replacement component', 'component'=>$relative, 'count'=>$state['index'], 'total'=>count($state['files']), 'unit'=>'files']);
            // Unchanged entries still belong in a complete component snapshot.
            $file['mtime'] ??= filemtime($source); $file['mode'] ??= fileperms($source)&0777;
            if (!empty($file['dir'])) {
                if (!is_dir($target_file) && !wp_mkdir_p($target_file)) { throw new RuntimeException('Cannot restore component directory.'); }
                if (!chmod($target_file,$file['mode']&0777)) { throw new RuntimeException('Cannot restore directory permissions.'); }
            } elseif (!self::copy($source, $target_file, $file, $state['copy'])) { return false; }
            $op['incoming'][$relative]=$file;
            $state['index']++;
            if (CSM_Support::due()) { return false; }
        }
        if (!file_exists($op['stage']) && !mkdir($op['stage'], 0755)) { throw new RuntimeException('Cannot create empty replacement component.'); }
        if (is_dir($op['stage'])) { $op['incoming']=[$op['path']=>['dir'=>true,'mode'=>fileperms($op['stage'])&0777,'mtime'=>filemtime($op['stage'])]]+($op['incoming'] ?? []); }
        return true;
    }

    public static function replace(array $op, array $selection): bool {
        CSM_Support::check_cancel();
        $target = CSM_Files::destination($op['path'], $selection);
        $state = self::journal($op);
        if ($state['phase'] === 'ready') {
            if (file_exists($target) !== $op['existed']) { throw new RuntimeException('Destination component changed before replacement: '.$op['path']); }
            $state=['phase'=>'moving']; CSM_Support::atomic_json($op['journal'],$state);
        }
        if ($state['phase'] === 'moving') {
            if (!empty($op['copy_backend'])) {
                if ($op['existed'] && !file_exists($op['backup'])) { throw new RuntimeException('Complete component backup is missing: '.$op['path']); }
                if (!self::remove_tree($op,$target,$state)) { return false; }
            } elseif ($op['existed'] && !file_exists($op['backup']) && !rename($target,$op['backup'])) { throw new RuntimeException('Cannot move destination component to backup: '.$op['path']); }
            $state=['phase'=>'installing']; CSM_Support::atomic_json($op['journal'],$state);
        }
        if ($state['phase'] === 'installing') {
            if (empty($op['remove_only'])) {
                if (!empty($op['copy_backend'])) {
                    if (!self::copy_tree($op['incoming'],$op['path'],$op['stage'],$target,$state['copy'],static function($copy)use(&$state,$op){$state['copy']=$copy;CSM_Support::atomic_json($op['journal'],$state);})) { return false; }
                } elseif (file_exists($op['stage'])) {
                    if (file_exists($target) || !rename($op['stage'],$target)) { throw new RuntimeException('Cannot install replacement component: '.$op['path']); }
                } elseif (!file_exists($target)) { throw new RuntimeException('Replacement component is missing: '.$op['path']); }
            }
            if (function_exists('opcache_reset')) { opcache_reset(); }
            CSM_Support::atomic_json($op['journal'],['phase'=>'done']);
        }
        return true;
    }

    private static function journal(array $op): array {
        if (!is_file($op['journal'])) { return ['phase'=>'ready']; }
        $data=json_decode(file_get_contents($op['journal']),true,512,JSON_THROW_ON_ERROR);
        $phase=$data['phase'] ?? '';
        if (!in_array($phase,['ready','moving','installing','done'],true)) { throw new RuntimeException('Invalid component recovery journal: '.$op['path']); }
        return $data;
    }

    public static function restore(array &$job, array $op, int $index): bool {
        $target = CSM_Files::destination($op['path'], $job['selection']);
        // No rename occurred: there is nothing to undo, including a not-yet-installed new component.
        if (!is_file($op['journal'])) {
            if (file_exists($op['backup']) || !file_exists($op['stage']) || (!$op['existed'] && file_exists($target))) { throw new RuntimeException('Component recovery journal is missing: '.$op['path']); }
            return true;
        }
        $journal=self::journal($op); $phase=$journal['phase'];
        $temp=$journal['copy']['file']['temp'] ?? null;
        if ($temp && is_file($temp) && !unlink($temp)) { throw new RuntimeException('Cannot remove interrupted component copy.'); }
        if ($phase === 'ready' || ($phase === 'moving' && !file_exists($op['backup']))) { return true; }
        if ($op['existed'] && !file_exists($op['backup'])) { throw new RuntimeException('Component backup is missing: '.$op['path']); }
        $dir = dirname($op['backup']).'/restore-'.$job['id'].'-'.$index;
        if (!isset($job['directory_restore'])) {
            $copy_backend = self::storage($dir, $target);
            $job['directory_restore'] = ['op'=>['path'=>$op['path'], 'directory'=>true, 'backup'=>$dir.'/original', 'stage'=>$dir.'/incoming', 'journal'=>$dir.'/swap.json', 'existed'=>file_exists($target), 'copy_backend'=>$copy_backend, 'manifest'=>[], 'incoming'=>[]], 'build'=>null];
            CSM_Jobs::save($job);
        }
        $restore = &$job['directory_restore'];
        if (empty($restore['built'])) {
            $files = [];
            foreach ($op['manifest'] as $relative=>$file) {
                if (!empty($file['preserved']) || CSM_Files::skipped($relative, $job['selection'])) { continue; }
                $files[$relative] = $file + ['stage'=>$op['backup'].substr($relative, strlen($op['path']))];
            }
            if (!self::build($restore['op'], $target, $files, $job['selection'], $restore['build'])) { CSM_Jobs::save($job); return false; }
            if (!empty($restore['op']['copy_backend']) && !self::snapshot($restore['op'],$target,$restore['snapshot'])) { CSM_Jobs::save($job); return false; }
            $restore['built'] = true; CSM_Jobs::save($job);
        }
        $preserved = array_filter($restore['op']['manifest'], static fn($file)=>!empty($file['preserved']));
        $restore['op']['remove_only']=!$op['existed'] && !$preserved;
        if (!self::replace($restore['op'],$job['selection'])) { CSM_Jobs::save($job); return false; }
        unset($job['directory_restore']);
        return true;
    }
}
