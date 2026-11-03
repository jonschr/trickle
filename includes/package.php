<?php
defined('ABSPATH') || exit;

/** Small, independently verified gzip frames; saved cursors never depend on a PHP process surviving. */
final class CSM_Package {
    public static function chain(string $hash, string $record): string { return hash('sha256', $hash . $record); }
    public static function put($fp, string $bytes): void {
        foreach (str_split($bytes, 262144) as $part) {
            CSM_Support::check_source_budget();
            $gzip = gzencode($part, 3);
            CSM_Support::write($fp, pack('N', strlen($gzip)) . hash('sha256', $gzip, true) . $gzip);
        }
    }

    public static function build(string $path, string $kind, array $selection): array {
        $checkpoint = $path . '.build';
        $state = is_file($checkpoint) ? json_decode(file_get_contents($checkpoint), true, 512, JSON_THROW_ON_ERROR) : ['offset'=>0,'cursor'=>[],'components'=>['database'=>0,'plugins'=>0,'themes'=>0,'media'=>0]];
        if (!empty($state['complete']) && is_file($path) && filesize($path)===$state['offset']) { return ['format'=>2,'size'=>$state['offset'],'sha256'=>CSM_Support::hash_file($path),'components'=>$state['components']]; }
        if ($state['offset']>0 && (!is_file($path.'.part') || filesize($path.'.part')<$state['offset'])) { throw new RuntimeException('Source package checkpoint is missing or truncated.'); }
        $fp = fopen($path . '.part', 'c+b');
        if (!$fp || !ftruncate($fp, $state['offset']) || fseek($fp, $state['offset']) !== 0) { throw new RuntimeException('Cannot resume source packaging.'); }
        CSM_Support::step();
        try {
            if ($kind === 'database') {
                // Keep one consistent InnoDB transaction. Its hard budget fails safely rather than mixing snapshots from different requests.
                $state = ['offset'=>0,'cursor'=>[],'components'=>['database'=>0,'plugins'=>0,'themes'=>0,'media'=>0]];
                ftruncate($fp, 0); rewind($fp);
                $buffer='';
                foreach (CSM_Database::stream($selection,true) as $bytes) { $buffer.=$bytes; $state['components']['database']+=strlen($bytes); while (strlen($buffer)>=262144) { self::put($fp,substr($buffer,0,262144)); $buffer=substr($buffer,262144); } }
                if ($buffer!=='') { self::put($fp,$buffer); }
                $done = true;
            } else {
                $generator = CSM_Files::stream_step($selection, $state['cursor'], $state['components']);
                foreach ($generator as $bytes) {
                    self::put($fp, $bytes);
                    // Generator cursor changes correspond to this exact emitted unit.
                    fflush($fp); $state['offset'] = ftell($fp);
                    if (CSM_Support::due()) { CSM_Support::atomic_json($checkpoint, $state); return ['continue'=>true,'packaged'=>$state['offset'],'components'=>$state['components']]; }
                }
                $done = !empty($state['cursor']['done']);
                if (!$done) { fflush($fp); $state['offset']=ftell($fp); CSM_Support::atomic_json($checkpoint,$state); return ['continue'=>true,'packaged'=>$state['offset'],'components'=>$state['components']]; }
            }
            fflush($fp); $state['offset'] = ftell($fp); $state['complete']=true; CSM_Support::atomic_json($checkpoint, $state);
        } finally { fclose($fp); }
        if ($done && !rename($path . '.part', $path)) { throw new RuntimeException('Cannot publish source snapshot.'); }
        return ['format'=>2,'size'=>$state['offset'],'sha256'=>CSM_Support::hash_file($path),'components'=>$state['components']];
    }

    public static function unpack(string $path, array $bundle): bool {
        $checkpoint = $path . '.unpack';
        $state = is_file($checkpoint) ? json_decode(file_get_contents($checkpoint), true, 32, JSON_THROW_ON_ERROR) : ['in'=>0,'out'=>0];
        if ($state['out']>0 && (!is_file($path.'.raw') || filesize($path.'.raw')<$state['out'])) { throw new RuntimeException('Verified frame checkpoint is missing or truncated.'); }
        if ($state['in']===$bundle['size']) {
            if (!empty($state['verified'])) { return true; }
            CSM_Progress::update(['phase'=>'Verifying snapshot checksum','component'=>'','count'=>0,'total'=>$bundle['size'],'unit'=>'bytes','bytes'=>0],true);
            if (!hash_equals($bundle['sha256'],CSM_Support::hash_file($path,static fn($bytes)=>CSM_Progress::update(['count'=>$bytes,'bytes'=>$bytes])))) { throw new RuntimeException('Completed snapshot checksum mismatch.'); }
            $state['verified']=true; CSM_Support::atomic_json($checkpoint,$state); return false;
        }
        $in = fopen($path, 'rb'); $out = fopen($path . '.raw', 'c+b');
        if (!$in || !$out || fseek($in, $state['in']) !== 0 || !ftruncate($out, $state['out']) || fseek($out, $state['out']) !== 0) { throw new RuntimeException('Cannot resume frame verification.'); }
        CSM_Support::step();
        try {
            while ($state['in'] < $bundle['size']) {
                CSM_Support::check_cancel();
                $header = fread($in, 36);
                if (strlen($header) !== 36) { throw new RuntimeException('Truncated frame header.'); }
                $length = unpack('N', substr($header, 0, 4))[1];
                if ($length < 1 || $length > 300000 || $state['in']+36+$length > $bundle['size']) { throw new RuntimeException('Invalid frame length.'); }
                $gzip = ''; while (strlen($gzip) < $length) { $part = fread($in, $length-strlen($gzip)); if ($part === false || $part === '') { throw new RuntimeException('Truncated frame.'); } $gzip .= $part; }
                if (!hash_equals(substr($header,4), hash('sha256',$gzip,true))) { throw new RuntimeException('Frame checksum mismatch.'); }
                $raw = gzdecode($gzip, 262144);
                if ($raw === false) { throw new RuntimeException('Invalid compressed frame.'); }
                CSM_Support::write($out,$raw); $state = ['in'=>ftell($in),'out'=>ftell($out)];
                CSM_Progress::update(['phase'=>'Verifying snapshot frames','component'=>'','count'=>$state['in'],'total'=>$bundle['size'],'unit'=>'bytes']);
                if (CSM_Support::due()) { break; }
            }
            fflush($out); CSM_Support::atomic_json($checkpoint,$state);
            return false;
        } finally { fclose($in); fclose($out); }
    }
}
