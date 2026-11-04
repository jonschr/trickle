<?php
defined('ABSPATH') || exit;

/** Cached media hashes, compared strictly in year → month → file order. */
final class CSM_Media {
    private static function path(): string {
        return CSM_Support::private_dir() . '/media-hashes-' . substr(hash('sha256', CSM_Files::root('uploads')), 0, 16) . '.json';
    }

    private static function cached(): array {
        $raw = @file_get_contents(self::path());
        $index = $raw === false ? [] : (json_decode($raw, true) ?: []);
        return isset($index['years'], $index['token']) && is_array($index['years']) && is_string($index['token']) && hash_equals($index['token'], self::digest($index['years'])) ? $index : [];
    }

    private static function stamp(array $stat): string {
        return implode(':', array_map(static fn($key) => $stat[$key], ['size', 'mtime', 'ctime', 'ino', 'dev', 'mode']));
    }

    private static function digest(array $items): string {
        ksort($items, SORT_STRING);
        return hash('sha256', CSM_Support::json($items));
    }

    private static function build(bool $verify, array $selection = []): array {
        $previous = self::cached(); $groups = []; $now = time();
        $work_id=$selection['comparison_id'] ?? '';
        $build_path=self::path().'.build-'.(preg_match('/^[a-f0-9]{32}$/D',$work_id)?$work_id:'').($verify?'-verify':''); $build=is_file($build_path)?json_decode(file_get_contents($build_path),true):[]; CSM_Support::step();
        $stats = ['scanned_files' => 0, 'hashed_files' => 0, 'years_reused' => 0, 'years_rebuilt' => 0, 'months_reused' => 0, 'months_rebuilt' => 0];
        foreach (CSM_Files::paths(array_intersect_key($selection, array_flip(['skip_paths', 'git_overrides', 'protect_source_git'])) + ['plugins' => [], 'themes' => [], 'media' => 'all']) as [$relative, $path]) {
            $tail = substr($relative, 8); $parts = explode('/', $tail);
            $year = count($parts) > 1 ? $parts[0] : ''; $month = count($parts) > 2 ? $parts[1] : '';
            clearstatcache(true, $path); $stat = lstat($path);
            if (!$stat || !is_file($path)) { throw new RuntimeException('Cannot inspect media file: ' . $tail); }
            $groups[$year][$month][$tail] = ['stamp' => self::stamp($stat), 'size' => $stat['size'], 'mtime' => $stat['mtime'], 'ctime' => $stat['ctime']];
            $stats['scanned_files']++;
            CSM_Progress::update(['phase'=>'Scanning media metadata','component'=>$relative,'count'=>$stats['scanned_files'],'total'=>null,'unit'=>'files']);
        }
        ksort($groups, SORT_STRING); $years = [];
        foreach ($groups as $year => $months) {
            ksort($months, SORT_STRING); $year_stamps = []; $stable_year = true;
            foreach ($months as $month => &$files) {
                ksort($files, SORT_STRING);
                foreach ($files as $tail => $file) {
                    $year_stamps[$tail] = $file['stamp'];
                    if (max($file['mtime'], $file['ctime']) >= $now - 2) { $stable_year = false; }
                }
            }
            unset($files);
            $fingerprint = self::digest($year_stamps); $old = $previous['years'][$year] ?? [];
            if (!$verify && $stable_year && ($old['fingerprint'] ?? '') === $fingerprint) {
                $years[$year] = $old; $stats['years_reused']++; continue;
            }
            $stats['years_rebuilt']++; $result = []; $year_hashes = [];
            foreach ($months as $month => $files) {
                $stamps = array_map(static fn($file) => $file['stamp'], $files);
                $month_fingerprint = self::digest($stamps); $old_month = $old['months'][$month] ?? [];
                $stable_month = true;
                foreach ($files as $file) { if (max($file['mtime'], $file['ctime']) >= $now - 2) { $stable_month = false; break; } }
                if (!$verify && $stable_month && ($old_month['fingerprint'] ?? '') === $month_fingerprint) {
                    $result[$month] = $old_month; $year_hashes[$month] = $old_month['hash']; $stats['months_reused']++; continue;
                }
                $stats['months_rebuilt']++; $hashes = [];
                foreach ($files as $tail => &$file) {
                    $cached = $build[$tail] ?? $old_month['files'][$tail] ?? [];
                    if ((isset($build[$tail]) || (!$verify && max($file['mtime'], $file['ctime']) < $now - 2)) && ($cached['stamp'] ?? '') === $file['stamp']) {
                        $file['hash'] = $cached['hash'];
                    } else {
                        $path = CSM_Files::root('uploads') . '/' . $tail;
                        CSM_Progress::update(['phase'=>'Comparing media checksums','component'=>'uploads/'.$tail,'count'=>$stats['hashed_files'],'total'=>null,'unit'=>'files']);
                        $file['hash'] = CSM_Support::hash_file($path); $stats['hashed_files']++;
                        clearstatcache(true, $path); $after = lstat($path);
                        if (!$file['hash'] || !$after || self::stamp($after) !== $file['stamp']) { throw new RuntimeException('Media changed while checksumming: ' . $tail); }
                    }
                    $resumed=isset($build[$tail]); $hashes[$tail] = $file['hash']; $build[$tail]=$file;
                    if (CSM_Support::due() && !$resumed) { CSM_Support::atomic_json($build_path,$build); throw new CSM_Yield('Media checksums checkpointed.'); }
                }
                unset($file);
                $hash = self::digest($hashes);
                $result[$month] = ['fingerprint' => $month_fingerprint, 'hash' => $hash, 'files' => $files]; $year_hashes[$month] = $hash;
            }
            $years[$year] = ['fingerprint' => $fingerprint, 'hash' => self::digest($year_hashes), 'months' => $result];
        }
        $index = ['years' => $years]; $index['token'] = self::digest($years);
        CSM_Support::atomic_json(self::path(), $index);
        CSM_Support::atomic_json(CSM_Support::private_dir().'/media-snapshot-'.$index['token'].'.json',$index); @unlink($build_path);
        return $index + ['stats' => $stats];
    }

    private static function snapshot(string $token): array {
        $snapshot=preg_match('/^[a-f0-9]{64}$/D',$token)?CSM_Support::private_dir().'/media-snapshot-'.$token.'.json':'';
        $index=$snapshot && is_file($snapshot)?json_decode(file_get_contents($snapshot),true):self::cached();
        if (!preg_match('/^[a-f0-9]{64}$/D', $token) || !is_array($index) || !is_array($index['years'] ?? null) || !hash_equals($index['token'] ?? '', $token) || !hash_equals($token,self::digest($index['years']))) { throw new RuntimeException('Media checksum inventory changed or expired. Retry the file stream to compare again.'); }
        return $index;
    }

    public static function inventory(array $query): array {
        $level = $query['level'] ?? '';
        if ($level === 'years') {
            $id=$query['selection']['comparison_id'] ?? '';
            if (!is_string($id) || ($id!=='' && !preg_match('/^[a-f0-9]{32}$/D',$id))) { throw new RuntimeException('Invalid media checksum work ID.'); }
            $result_path=$id!==''?self::path().'.comparison-'.$id.'.json':'';
            if ($result_path && is_file($result_path)) { return json_decode(file_get_contents($result_path),true,512,JSON_THROW_ON_ERROR); }
            $index = self::build(!empty($query['verify']), $query['selection'] ?? []); $items = [];
            foreach ($index['years'] as $year => $item) { $items[] = ['name' => (string)$year, 'hash' => $item['hash']]; }
            $result=['token' => $index['token'], 'items' => $items, 'stats' => $index['stats']];
            if ($result_path) { CSM_Support::atomic_json($result_path,$result); }
            return $result;
        }
        $index = self::snapshot($query['token'] ?? ''); $years = $query['years'] ?? [];
        if (!is_array($years) || !array_is_list($years) || count($years) > 2000) { throw new RuntimeException('Invalid media year selection.'); }
        foreach ($years as $year) { if (!is_string($year) || !isset($index['years'][$year])) { throw new RuntimeException('Unknown media year.'); } }
        if ($level === 'months') {
            $items = [];
            foreach ($years as $year) { foreach ($index['years'][$year]['months'] as $month => $item) { $items[] = ['year' => $year, 'month' => (string)$month, 'hash' => $item['hash'], 'files' => count($item['files'])]; } }
            return ['items' => $items];
        }
        if ($level !== 'files' || !is_string($query['year'] ?? null) || !is_string($query['month'] ?? null)) { throw new RuntimeException('Invalid media checksum query.'); }
        $files = $index['years'][$query['year']]['months'][$query['month']]['files'] ?? null;
        $offset = $query['offset'] ?? 0;
        if ($files === null || !is_int($offset) || $offset < 0) { throw new RuntimeException('Unknown media month or invalid page.'); }
        $items = [];
        foreach (array_slice($files, $offset, 500, true) as $path => $file) { $items[] = ['path' => (string)$path, 'hash' => $file['hash']]; }
        return ['items' => $items, 'more' => count($files) > $offset + 500];
    }

    public static function manifest(array $query): array {
        if (!is_string($query['year'] ?? null) || !is_string($query['month'] ?? null)) { throw new RuntimeException('Invalid media month.'); }
        $index=self::snapshot($query['token'] ?? ''); $files=$index['years'][$query['year']]['months'][$query['month']]['files'] ?? null;
        if (!is_array($files)) { throw new RuntimeException('Unknown media month.'); }
        return ['token'=>$index['token'],'files'=>array_map(static fn($file)=>$file['hash'],$files)];
    }

    public static function compare(array $peer, bool $pull, bool $verify, array $selection = []): array {
        $id=$selection['comparison_id'] ?? ''; $path=preg_match('/^[a-f0-9]{32}$/D',$id)?CSM_Support::private_dir().'/media-compare-'.$id.'.json':'';
        $work=$path && is_file($path)?json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR):null;
        if (isset($work['result'])) { return $work['result']; }
        $remote=static function($query) use ($peer,$selection) {
            $result=CSM_Transport::request($peer,'media_hashes',$query+['selection'=>$selection,'progress_run'=>CSM_Progress::run()],null,null,15);
            if (!empty($result['continue'])) { throw new CSM_Yield('Remote media checksums checkpointed.'); } return $result;
        };
        $local=static fn($query)=>self::inventory($query+['selection'=>$selection]);
        $source=$pull?$remote:$local; $destination=$pull?$local:$remote;
        CSM_Support::step();
        if (!$work) {
            $src=$source(['level'=>'years','verify'=>$verify]); $dst=$destination(['level'=>'years','verify'=>$verify]);
            $stats=['years_compared'=>0,'years_skipped'=>0,'months_compared'=>0,'months_skipped'=>0,'files_compared'=>0,'files_selected'=>0,'source_hashing'=>$src['stats'],'destination_hashing'=>$dst['stats']];
            $dst_years=array_column($dst['items'],'hash','name'); $different=[];
            foreach ($src['items'] as $year) { $stats['years_compared']++; if (($dst_years[$year['name']] ?? null)===$year['hash']) { $stats['years_skipped']++; } else { $different[]=$year['name']; } }
            $src_months=$different?$source(['level'=>'months','token'=>$src['token'],'years'=>$different]):['items'=>[]];
            $present=array_values(array_filter($different,static fn($year)=>isset($dst_years[$year])));
            $dst_months=$present?$destination(['level'=>'months','token'=>$dst['token'],'years'=>$present]):['items'=>[]]; $months=[];
            foreach ($dst_months['items'] as $month) { $months[$month['year'].'/'.$month['month']]=$month['hash']; }
            $work=['src'=>$src['token'],'dst'=>$dst['token'],'months'=>$src_months['items'],'destination'=>$months,'index'=>0,'buckets'=>[],'paths'=>[],'stats'=>$stats];
            if ($path) { CSM_Support::atomic_json($path,$work); }
            if ($path && CSM_Support::due() && $work['months']) { throw new CSM_Yield('Media year comparison checkpointed.'); }
        }
        $read=static function(array $query,bool $remote) use ($peer): array {
            if (!$remote) { return self::manifest($query)['files']; }
            $fp=fopen('php://temp','w+b');
            try {
                CSM_Transport::request($peer,'media_manifest',$query,null,$fp,15,32*1024*1024); rewind($fp);
                $raw=gzdecode(stream_get_contents($fp),32*1024*1024); $data=$raw===false?null:json_decode($raw,true,512,JSON_THROW_ON_ERROR);
                if (!is_array($data) || ($data['token'] ?? '')!==$query['token'] || !is_array($data['files'] ?? null)) { throw new RuntimeException('Invalid media checksum manifest.'); }
                return $data['files'];
            } finally { fclose($fp); }
        };
        CSM_Support::step();
        while ($work['index']<count($work['months'])) {
            CSM_Support::check_cancel(); $month=$work['months'][$work['index']]; $bucket=$month['year'].'/'.$month['month']; $stats=&$work['stats'];
            $stats['months_compared']++;
            if (($work['destination'][$bucket] ?? null)===$month['hash']) { $stats['months_skipped']++; }
            elseif (!isset($work['destination'][$bucket])) { $work['buckets'][]=[$month['year'],$month['month']]; $stats['files_selected']+=$month['files']; }
            else {
                $query=['year'=>$month['year'],'month'=>$month['month']];
                $src_files=$read($query+['token'=>$work['src']],$pull); $dst_files=$read($query+['token'=>$work['dst']],!$pull);
                foreach ($src_files as $file=>$hash) { $stats['files_compared']++; if (($dst_files[$file] ?? null)!==$hash) { $work['paths'][]=(string)$file; $stats['files_selected']++; } }
            }
            $work['index']++;
            CSM_Progress::update(['phase'=>'Comparing media months','component'=>$bucket,'count'=>$work['index'],'total'=>count($work['months']),'unit'=>'months']);
            if ($path) { CSM_Support::atomic_json($path,$work); }
            if (CSM_Support::due()) {
                if ($path) { throw new CSM_Yield('Media month comparison checkpointed.'); }
                throw new RuntimeException('Media comparison exceeded its work budget. Narrow the selection.');
            }
        }
        $delta=base64_encode(gzencode(CSM_Support::json(['token'=>$work['src'],'buckets'=>$work['buckets'],'paths'=>$work['paths']]),3));
        if (strlen($delta)>700000) { throw new RuntimeException('Media comparison is too large. Use Force retransfer or narrow the selection.'); }
        $result=['delta'=>$delta,'stats'=>$work['stats']]; if ($path) { CSM_Support::atomic_json($path,['result'=>$result]); } return $result;
    }

    public static function paths(string $encoded, array $selection = []): Generator {
        if (strlen($encoded) > 700000) { throw new RuntimeException('Invalid media comparison plan.'); }
        $compressed = base64_decode($encoded, true); $raw = $compressed === false ? false : @gzdecode($compressed, 32 * 1024 * 1024);
        $plan = $raw === false ? null : json_decode($raw, true);
        if (!is_array($plan) || !is_array($plan['buckets'] ?? null) || !array_is_list($plan['buckets']) || !is_array($plan['paths'] ?? null) || !array_is_list($plan['paths'])) { throw new RuntimeException('Invalid media comparison plan.'); }
        $index = self::snapshot($plan['token'] ?? ''); $selected = [];
        foreach ($plan['buckets'] as $bucket) {
            if (!is_array($bucket) || count($bucket) !== 2 || !is_string($bucket[0] ?? null) || !is_string($bucket[1] ?? null) || !isset($index['years'][$bucket[0]]['months'][$bucket[1]])) { throw new RuntimeException('Unknown media comparison folder.'); }
            $selected += $index['years'][$bucket[0]]['months'][$bucket[1]]['files'];
        }
        foreach ($plan['paths'] as $path) {
            if (!is_string($path)) { throw new RuntimeException('Invalid media comparison file.'); }
            $parts = explode('/', $path); $year = count($parts) > 1 ? $parts[0] : ''; $month = count($parts) > 2 ? $parts[1] : '';
            $file = $index['years'][$year]['months'][$month]['files'][$path] ?? null;
            if (!$file) { throw new RuntimeException('Unknown media comparison file.'); }
            $selected[$path] = $file;
        }
        ksort($selected, SORT_STRING);
        foreach ($selected as $tail => $file) {
            $relative = 'uploads/' . $tail; $path = CSM_Files::destination($relative, $selection + ['media' => 'compare'], !empty($selection['protect_source_git']));
            clearstatcache(true, $path); $stat = @lstat($path);
            if (!$stat || self::stamp($stat) !== $file['stamp']) { throw new RuntimeException('Media changed after comparison: ' . $tail . '. Retry the file stream.'); }
            yield [$relative, $path, $file['hash']];
        }
    }
}
