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

    private static function build(bool $verify): array {
        $previous = self::cached(); $groups = []; $now = time();
        $stats = ['scanned_files' => 0, 'hashed_files' => 0, 'years_reused' => 0, 'years_rebuilt' => 0, 'months_reused' => 0, 'months_rebuilt' => 0];
        foreach (CSM_Files::paths(['plugins' => [], 'themes' => [], 'media' => 'all']) as [$relative, $path]) {
            $tail = substr($relative, 8); $parts = explode('/', $tail);
            $year = count($parts) > 1 ? $parts[0] : ''; $month = count($parts) > 2 ? $parts[1] : '';
            clearstatcache(true, $path); $stat = lstat($path);
            if (!$stat || !is_file($path)) { throw new RuntimeException('Cannot inspect media file: ' . $tail); }
            $groups[$year][$month][$tail] = ['stamp' => self::stamp($stat), 'size' => $stat['size'], 'mtime' => $stat['mtime'], 'ctime' => $stat['ctime']];
            $stats['scanned_files']++;
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
                    $cached = $old_month['files'][$tail] ?? [];
                    if (!$verify && max($file['mtime'], $file['ctime']) < $now - 2 && ($cached['stamp'] ?? '') === $file['stamp']) {
                        $file['hash'] = $cached['hash'];
                    } else {
                        $path = CSM_Files::root('uploads') . '/' . $tail;
                        $file['hash'] = hash_file('sha256', $path); $stats['hashed_files']++;
                        clearstatcache(true, $path); $after = lstat($path);
                        if (!$file['hash'] || !$after || self::stamp($after) !== $file['stamp']) { throw new RuntimeException('Media changed while checksumming: ' . $tail); }
                    }
                    $hashes[$tail] = $file['hash'];
                }
                unset($file);
                $hash = self::digest($hashes);
                $result[$month] = ['fingerprint' => $month_fingerprint, 'hash' => $hash, 'files' => $files]; $year_hashes[$month] = $hash;
            }
            $years[$year] = ['fingerprint' => $fingerprint, 'hash' => self::digest($year_hashes), 'months' => $result];
        }
        $index = ['years' => $years]; $index['token'] = self::digest($years);
        $raw = CSM_Support::json($index); $path = self::path(); $temp = $path . '.' . bin2hex(random_bytes(8));
        if (file_put_contents($temp, $raw, LOCK_EX) !== strlen($raw)) { @unlink($temp); throw new RuntimeException('Cannot save media checksums.'); }
        chmod($temp, 0600);
        if (!rename($temp, $path)) { @unlink($temp); throw new RuntimeException('Cannot save media checksums.'); }
        return $index + ['stats' => $stats];
    }

    private static function snapshot(string $token): array {
        $index = self::cached();
        if (!preg_match('/^[a-f0-9]{64}$/D', $token) || !hash_equals($index['token'] ?? '', $token)) { throw new RuntimeException('Media checksum inventory changed or expired. Retry the file stream to compare again.'); }
        return $index;
    }

    public static function inventory(array $query): array {
        $level = $query['level'] ?? '';
        if ($level === 'years') {
            $index = self::build(!empty($query['verify'])); $items = [];
            foreach ($index['years'] as $year => $item) { $items[] = ['name' => (string)$year, 'hash' => $item['hash']]; }
            return ['token' => $index['token'], 'items' => $items, 'stats' => $index['stats']];
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

    public static function compare(array $peer, bool $pull, bool $verify): array {
        $remote = static fn($query) => CSM_Transport::request($peer, 'media_hashes', $query);
        $local = static fn($query) => self::inventory($query);
        $source = $pull ? $remote : $local; $destination = $pull ? $local : $remote;
        $src = $source(['level' => 'years', 'verify' => $verify]); $dst = $destination(['level' => 'years', 'verify' => $verify]);
        $stats = ['years_compared' => 0, 'years_skipped' => 0, 'months_compared' => 0, 'months_skipped' => 0, 'files_compared' => 0, 'files_selected' => 0, 'source_hashing' => $src['stats'], 'destination_hashing' => $dst['stats']];
        $dst_years = array_column($dst['items'], 'hash', 'name'); $different = [];
        foreach ($src['items'] as $year) {
            $stats['years_compared']++;
            if (($dst_years[$year['name']] ?? null) === $year['hash']) { $stats['years_skipped']++; }
            else { $different[] = $year['name']; }
        }
        $buckets = []; $paths = [];
        if ($different) {
            $src_months = $source(['level' => 'months', 'token' => $src['token'], 'years' => $different]);
            $dst_present = array_values(array_filter($different, static fn($year) => isset($dst_years[$year])));
            $dst_months = $dst_present ? $destination(['level' => 'months', 'token' => $dst['token'], 'years' => $dst_present]) : ['items' => []];
            $months = [];
            foreach ($dst_months['items'] as $month) { $months[$month['year'] . '/' . $month['month']] = $month['hash']; }
            foreach ($src_months['items'] as $month) {
                $bucket = $month['year'] . '/' . $month['month']; $stats['months_compared']++;
                if (($months[$bucket] ?? null) === $month['hash']) { $stats['months_skipped']++; continue; }
                if (!isset($months[$bucket])) { $buckets[] = [$month['year'], $month['month']]; $stats['files_selected'] += $month['files']; continue; }
                $read = static function ($get, $token) use ($month): array {
                    $files = []; $offset = 0;
                    do {
                        $page = $get(['level' => 'files', 'token' => $token, 'year' => $month['year'], 'month' => $month['month'], 'offset' => $offset]);
                        foreach ($page['items'] as $file) { $files[$file['path']] = $file['hash']; }
                        $offset += 500;
                    } while ($page['more']);
                    return $files;
                };
                $src_files = $read($source, $src['token']); $dst_files = $read($destination, $dst['token']);
                foreach ($src_files as $path => $hash) {
                    $stats['files_compared']++;
                    if (($dst_files[$path] ?? null) !== $hash) { $paths[] = (string)$path; $stats['files_selected']++; }
                }
            }
        }
        $delta = base64_encode(gzencode(CSM_Support::json(['token' => $src['token'], 'buckets' => $buckets, 'paths' => $paths]), 3));
        if (strlen($delta) > 700000) { throw new RuntimeException('The changed-media selection is too large for one control request. Transfer all media or narrow the selection.'); }
        return ['delta' => $delta, 'stats' => $stats];
    }

    public static function paths(string $encoded): Generator {
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
            $relative = 'uploads/' . $tail; $path = CSM_Files::destination($relative, ['media' => 'compare']);
            clearstatcache(true, $path); $stat = @lstat($path);
            if (!$stat || self::stamp($stat) !== $file['stamp']) { throw new RuntimeException('Media changed after comparison: ' . $tail . '. Retry the file stream.'); }
            yield [$relative, $path, $file['hash']];
        }
    }
}
