<?php
defined('ABSPATH') || exit;

/** Cached content hashes for repeat plugin and theme transfers. */
final class CSM_Components {
    private static function cache_path(array $selection): string {
        return CSM_Support::private_dir() . '/components-' . substr(hash('sha256', CSM_Support::json([$selection['plugins'] ?? [], $selection['themes'] ?? [], $selection['skip_paths'] ?? [], $selection['git_overrides'] ?? []])), 0, 24) . '.json';
    }

    private static function stamp(string $path): string {
        clearstatcache(true, $path); $s = lstat($path);
        if (!$s || !is_file($path)) { throw new RuntimeException('Component file disappeared during comparison.'); }
        return implode(':', array_map(static fn($key) => $s[$key], ['size', 'mtime', 'ctime', 'ino', 'dev', 'mode']));
    }

    private static function read(array $selection, string $token = ''): array {
        return self::read_cache($token !== '' && preg_match('/^[a-f0-9]{64}$/D',$token) ? CSM_Support::private_dir().'/component-snapshot-'.$token.'.json' : self::cache_path($selection));
    }

    private static function read_cache(string $path): array {
        $raw = @file_get_contents($path);
        $cache = $raw === false ? [] : json_decode($raw, true);
        if (!is_array($cache) || !isset($cache['files'], $cache['token']) || !hash_equals($cache['token'], hash('sha256', CSM_Support::json($cache['files'])))) { return []; }
        return $cache;
    }

    private static function folder_path(string $kind,string $id,array $selection): string {
        return CSM_Support::private_dir().'/component-folder-'.hash('sha256',CSM_Support::json([CSM_Files::root($kind,$id),$kind,$id,$selection['skip_paths'] ?? [],$selection['git_overrides'] ?? [],!empty($selection['protect_source_git'])])).'.json';
    }

    public static function inventory(array $query): array {
        $selection = $query['selection'] ?? []; $selection['media'] = 'none';
        $path = self::cache_path($selection);
        if (($query['level'] ?? '') === 'files') {
            $cache = self::read($selection,$query['token'] ?? ''); $offset = $query['offset'] ?? 0;
            if (!is_int($offset) || $offset < 0 || !is_string($query['token'] ?? null) || !hash_equals($cache['token'] ?? '', $query['token'])) { throw new RuntimeException('Component inventory changed. Retry preparation.'); }
            return ['files' => array_slice($cache['files'], $offset, 500, true), 'more' => $offset + 500 < count($cache['files'])];
        }
        $work_id=$selection['comparison_id'] ?? '';
        if (!is_string($work_id) || ($work_id!=='' && !preg_match('/^[a-f0-9]{32}$/D',$work_id))) { throw new RuntimeException('Invalid checksum work ID.'); }
        $result_path=$work_id!==''?$path.'.comparison-'.$work_id.'.json':'';
        if ($result_path && is_file($result_path)) { return json_decode(file_get_contents($result_path),true,512,JSON_THROW_ON_ERROR); }
        $old = self::read($selection); $files = []; $groups = []; $hashed = 0;
        $build_path=$path.'.build-'.$work_id.(!empty($query['verify'])?'-verify':''); $build=is_file($build_path)?json_decode(file_get_contents($build_path),true):[];
        CSM_Support::step();
        foreach (['plugins', 'themes'] as $kind) {
            foreach ($selection[$kind] ?? [] as $id) {
                CSM_Files::safe_relative($id);
                if (str_contains($id, '/')) { throw new RuntimeException('Invalid component ID.'); }
                $root = CSM_Files::root($kind, $id);
                $folder_path=self::folder_path($kind,$id,$selection); $folder=self::read_cache($folder_path); $component_files=[];
                foreach (CSM_Files::scan($root . '/' . $id, $kind . '/' . $id, $selection) as $file) {
                    $relative = $kind . '/' . substr($file, strlen($root) + 1); $stamp = self::stamp($file);
                    CSM_Progress::update(['phase'=>'Comparing plugin and theme checksums','component'=>$relative,'count'=>count($files),'total'=>null,'unit'=>'files']);
                    $cached = $build[$relative] ?? $folder['files'][$relative] ?? $old['files'][$relative] ?? [];
                    if ((isset($build[$relative]) || (empty($query['verify']) && max(filemtime($file), filectime($file)) < time() - 2)) && ($cached['stamp'] ?? '') === $stamp) { $hash = $cached['hash']; }
                    else { $hash = CSM_Support::hash_file($file); $hashed++; if (!$hash || self::stamp($file) !== $stamp) { throw new RuntimeException('Component changed while comparing: ' . $relative); } }
                    $files[$relative] = ['hash' => $hash, 'stamp' => $stamp];
                    $component_files[$relative]=$files[$relative];
                    if (CSM_Support::due() && !isset($build[$relative])) { CSM_Support::atomic_json($build_path,$files+$build); throw new CSM_Yield('Component checksums checkpointed.'); }
                }
                ksort($component_files,SORT_STRING);
                if (($folder['files'] ?? null)!==$component_files) { CSM_Support::atomic_json($folder_path,['files'=>$component_files,'token'=>hash('sha256',CSM_Support::json($component_files))]); }
                else { @touch($folder_path); }
            }
        }
        ksort($files, SORT_STRING);
        foreach ($files as $relative => $file) { $parts = explode('/', $relative); $groups[$parts[0] . '/' . $parts[1]][$relative] = $file['hash']; }
        $hashes = []; foreach ($groups as $id => $items) { $hashes[$id] = hash('sha256', CSM_Support::json($items)); }
        $cache = ['files' => $files, 'token' => hash('sha256', CSM_Support::json($files))];
        $raw = CSM_Support::json($cache); $temp = $path . '.' . bin2hex(random_bytes(8));
        if (file_put_contents($temp, $raw, LOCK_EX) !== strlen($raw)) { @unlink($temp); throw new RuntimeException('Could not save component checksum cache.'); }
        chmod($temp, 0600); if (!rename($temp, $path)) { @unlink($temp); throw new RuntimeException('Could not save component checksum cache.'); }
        CSM_Support::atomic_json(CSM_Support::private_dir().'/component-snapshot-'.$cache['token'].'.json',$cache); @unlink($build_path);
        $result=['token' => $cache['token'], 'components' => $hashes, 'component_counts' => array_map('count', $groups), 'component_groups' => 1, 'files' => count($files), 'hashed' => $hashed];
        if ($result_path) { CSM_Support::atomic_json($result_path,$result); }
        return $result;
    }

    public static function compare(array $peer, bool $pull, array $selection): array {
        $remote = static function($query) use ($peer) { $result=CSM_Transport::request($peer,'component_hashes',$query+['progress_run'=>CSM_Progress::run()],null,null,15); if (!empty($result['continue'])) { throw new CSM_Yield('Remote component checksums checkpointed.'); } return $result; };
        $local = static fn($query) => self::inventory($query);
        $source = $pull ? $remote : $local; $destination = $pull ? $local : $remote;
        $src = $source(['selection' => $selection, 'verify' => !empty($selection['file_verify'])]); $dst = $destination(['selection' => $selection, 'verify' => !empty($selection['file_verify'])]);
        // Matching component digests let the source reuse its pinned inventory without paging it through the controller.
        if ($src['components'] === $dst['components']) {
            $plan = base64_encode(gzencode(CSM_Support::json(['component_token' => $src['token']]), 3));
            return ['same' => $plan, 'folders' => $plan, 'matched' => $src['files'], 'fallback' => false];
        }
        $common = array_keys(array_intersect_key($src['components'], $dst['components'])); $same = []; $matching = [];
        if (!empty($src['component_groups'])) {
            foreach ($src['components'] as $id => $hash) { if (($dst['components'][$id] ?? null) === $hash) { $matching[] = $id; } }
            $common = array_values(array_diff($common, $matching));
        }
        $matched = array_sum(array_intersect_key($src['component_counts'] ?? [], array_flip($matching)));
        $encode = static fn(array $files) => base64_encode(gzencode(CSM_Support::json($matching ? ['component_token'=>$src['token'], 'components'=>$matching, 'files'=>$files] : $files), 3));
        $folders = $matching ? $encode([]) : '';
        $counts = static fn(array $index) => isset($index['component_counts']) ? array_sum(array_intersect_key($index['component_counts'], array_flip($common))) : $index['files'];
        // Never drop matching folders when differing folders require a full-transfer fallback.
        if (!$common || max($counts($src), $counts($dst)) > 100000) { return ['same'=>$folders, 'folders'=>$folders, 'matched'=>$matched, 'fallback'=>(bool)$common]; }
        $query = ['selection'=>$selection, 'components'=>$common];
        $read = static function (array $index) use ($peer, $query): array {
            $fp = fopen('php://temp', 'w+b');
            try {
                CSM_Transport::request($peer, 'component_manifest', $query + ['token'=>$index['token']], null, $fp, 0, 32 * 1024 * 1024);
                rewind($fp); $raw = gzdecode(stream_get_contents($fp), 32 * 1024 * 1024);
                $data = $raw === false ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($data) || ($data['token'] ?? '') !== $index['token'] || !is_array($data['files'] ?? null)) { throw new RuntimeException('Invalid component checksum manifest.'); }
                return $data['files'];
            } finally { fclose($fp); }
        };
        $local_files = self::manifest($query + ['token'=>($pull ? $dst : $src)['token']])['files'];
        $remote_files = $read($pull ? $src : $dst);
        $dest = $pull ? $local_files : $remote_files; $source_files = $pull ? $remote_files : $local_files;
        foreach ($source_files as $path => $file) { if (($dest[$path]['hash'] ?? null) === $file['hash']) { $same[$path] = $file; } }
        $plan = $encode($same);
        // shortcut: oversized per-file plans resend differing folders; matching folder tokens remain compact.
        if (strlen($plan) > 600000) { return ['same' => $folders, 'folders' => $folders, 'matched' => $matched, 'fallback' => true]; }
        return ['same' => $plan, 'folders' => $folders, 'matched' => $matched + count($same), 'fallback' => false];
    }

    public static function selection(array $selection, array $comparison): array {
        $selection['component_same'] = $comparison['same'];
        // Large media plans may share this request; keep folder skips when per-file detail will not fit.
        if (strlen(CSM_Support::json($selection)) > 950000) { $selection['component_same'] = $comparison['folders'] ?? ''; }
        return $selection;
    }

    public static function manifest(array $query): array {
        $selection = $query['selection'] ?? []; $cache = self::read($selection,$query['token'] ?? '');
        if (!is_string($query['token'] ?? null) || !hash_equals($cache['token'] ?? '', $query['token'])) { throw new RuntimeException('Component inventory changed. Retry preparation.'); }
        $components = $query['components'] ?? null; $allowed = [];
        foreach (['plugins','themes'] as $kind) { foreach ($selection[$kind] ?? [] as $id) { $allowed[] = $kind . '/' . $id; } }
        if (!is_array($components) || !array_is_list($components) || array_filter($components, static fn($id)=>!is_string($id)) || array_diff($components, $allowed)) { throw new RuntimeException('Invalid component manifest selection.'); }
        $files = array_filter($cache['files'], static fn($path)=>in_array(implode('/', array_slice(explode('/', $path), 0, 2)), $components, true), ARRAY_FILTER_USE_KEY);
        return ['token'=>$cache['token'], 'files'=>$files];
    }

    public static function unchanged(array $selection): array {
        $plan = $selection['component_same'] ?? '';
        if ($plan === '') { return []; }
        if (!is_string($plan) || strlen($plan) > 600000) { throw new RuntimeException('Invalid unchanged-file plan.'); }
        $bytes = base64_decode($plan, true); $raw = $bytes === false ? false : @gzdecode($bytes, 32 * 1024 * 1024);
        $files = $raw === false ? null : json_decode($raw, true);
        if (!is_array($files)) { throw new RuntimeException('Invalid unchanged-file plan.'); }
        if (array_key_exists('component_token', $files)) {
            if (array_diff(array_keys($files), ['component_token','components','files']) || !is_string($files['component_token']) || !preg_match('/^[a-f0-9]{64}$/D', $files['component_token'])) { throw new RuntimeException('Invalid component inventory token.'); }
            $cache = self::read($selection,$files['component_token']);
            if (!hash_equals($cache['token'] ?? '', $files['component_token'])) { throw new RuntimeException('Component inventory changed. Retry preparation.'); }
            if (count($files) === 1) { return $cache['files']; }
            $components = $files['components'] ?? null; $extra = $files['files'] ?? null; $allowed = [];
            foreach (['plugins','themes'] as $kind) { foreach ($selection[$kind] ?? [] as $id) { $allowed[] = $kind . '/' . $id; } }
            if (!is_array($components) || !array_is_list($components) || array_filter($components, static fn($id)=>!is_string($id)) || array_diff($components, $allowed) || !is_array($extra)) { throw new RuntimeException('Invalid unchanged component selection.'); }
            foreach ($extra as $path => $file) { if (!is_string($path) || !isset($cache['files'][$path]) || $cache['files'][$path] !== $file) { throw new RuntimeException('Unchanged file does not match its source inventory.'); } }
            return array_filter($cache['files'], static fn($path)=>in_array(implode('/', array_slice(explode('/', $path), 0, 2)), $components, true), ARRAY_FILTER_USE_KEY) + $extra;
        }
        return $files;
    }

    public static function verify(string $path, array $file): bool {
        return is_string($file['hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $file['hash']) && self::stamp($path) === ($file['stamp'] ?? '');
    }
}
