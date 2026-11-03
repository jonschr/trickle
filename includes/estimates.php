<?php
defined('ABSPATH') || exit;

/** Cached allocation estimates: metadata only, never read or hash file contents. */
final class CSM_Estimates {
    public static function read(bool $advance = false, bool $refresh = false): array {
        $scope=hash('sha256',CSM_Support::json([CSM_Files::root('plugins'),CSM_Files::root('themes'),CSM_Files::root('uploads')]));
        $path=CSM_Support::private_dir().'/size-estimates-'.substr($scope,0,16).'.json';
        $lock=fopen($path.'.lock','c');
        if (!$lock) { throw new RuntimeException('Cannot open size estimate cache.'); }
        $locked=flock($lock,LOCK_EX|LOCK_NB);
        try {
            $state=is_file($path)?json_decode(file_get_contents($path),true):null;
            if (!is_array($state) || ($state['version'] ?? 0)!==2 || !isset($state['queue'],$state['bytes'],$state['items'],$state['complete'],$state['updated'])) { $state=null; }
            if ($locked && (!$state || $refresh || ($state['complete'] && $state['updated']<time()-300))) {
                $previous=!empty($state['complete']) ? array_intersect_key($state,array_flip(['updated','complete','bytes','items','unreadable'])) : ($state['previous'] ?? null);
                $queue=[];
                foreach (['plugins','themes'] as $kind) {
                    $root=CSM_Files::root($kind);
                    if (!is_dir($root)) { continue; }
                    foreach (new FilesystemIterator($root) as $entry) {
                        if ($entry->isLink() || CSM_Files::excluded($entry->getFilename()) || ($kind==='plugins' && $entry->getFilename()===basename(dirname(CSM_FILE)))) { continue; }
                        $queue[]=[$entry->getPathname(),$kind,$entry->getFilename(),$kind.'/'.$entry->getFilename()];
                    }
                }
                $queue[]=[CSM_Files::root('uploads'),'media','','uploads'];
                $state=['version'=>2,'updated'=>time(),'complete'=>false,'bytes'=>['database'=>0,'plugins'=>0,'themes'=>0,'media'=>0],'items'=>['plugins'=>[],'themes'=>[],'media'=>[]],'queue'=>$queue,'active'=>null,'unreadable'=>0];
                if ($previous) { $state['previous']=$previous; }
            }
            if ($locked && $advance && !$state['complete']) {
                $deadline=hrtime(true)/1e9+1; $limit=20000; $count=0;
                do {
                    if (!$state['active']) {
                        $next=array_pop($state['queue']);
                        if (!$next) { $state['complete']=true; break; }
                        [$file,$kind,$component,$relative]=$next;
                        if (!file_exists($file) || is_link($file) || self::excluded($relative,$kind)) { continue; }
                        if (is_file($file)) { self::add($state,$kind,$component,filesize($file),$relative); continue; }
                        $state['active']=['entry'=>$next,'offset'=>0];
                    }
                    [$dir,$kind,$component,$relative]=$state['active']['entry'];
                    try { $iterator=new DirectoryIterator($dir); $iterator->seek($state['active']['offset']); }
                    catch (Throwable $e) { $state['unreadable']++; $state['active']=null; continue; }
                    while ($iterator->valid()) {
                        $entry=$iterator->current(); $offset=$iterator->key()+1;
                        if (!$entry->isDot() && !$entry->isLink() && !self::excluded($relative.'/'.$entry->getFilename(),$kind)) {
                            if ($entry->isDir()) { $state['queue'][]=[$entry->getPathname(),$kind,$component,$relative.'/'.$entry->getFilename()]; }
                            elseif ($entry->isFile()) { self::add($state,$kind,$component,$entry->getSize(),$relative.'/'.$entry->getFilename()); }
                        }
                        $state['active']['offset']=$offset; $iterator->next(); $count++;
                        if ($count>=$limit || hrtime(true)/1e9>=$deadline) { break; }
                    }
                    if (!$iterator->valid()) { $state['active']=null; }
                } while ($count<$limit && hrtime(true)/1e9<$deadline);
                if ($state['complete']) { unset($state['previous']); }
                $state['updated']=time(); CSM_Support::atomic_json($path,$state);
            }
            $result=array_intersect_key($state ?? [],array_flip(['updated','complete','bytes','items','unreadable']));
            if (empty($state['complete']) && !empty($state['previous'])) { $result=$state['previous']+['refreshing'=>true]; }
            $result['bytes']['database']=array_sum(array_column(CSM_Database::inventory(),'bytes'));
            return $result;
        } finally { if ($locked) { flock($lock,LOCK_UN); } fclose($lock); }
    }

    private static function excluded(string $path,string $kind): bool {
        if (CSM_Files::excluded($path)) { return true; }
        return $kind==='media' && (preg_match('#(^|/)(cache|backups?|ai1wm-backups|wpvividbackups)(/|$)#i',$path) || str_starts_with(basename($path),'.') || preg_match('/\.(php\d*|phtml|phar|cgi|pl|sh|htaccess|user\.ini)$/i',$path));
    }

    private static function add(array &$state,string $kind,string $component,int $bytes,string $relative): void {
        $state['bytes'][$kind]+=$bytes;
        if ($kind==='media') {
            $parts=explode('/',$relative,3);
            $component=isset($parts[2]) && preg_match('/^[0-9]{4}$/D',$parts[1]) ? $parts[1] : '_other';
        }
        if ($component!=='') { $state['items'][$kind][$component]=($state['items'][$kind][$component] ?? 0)+$bytes; }
    }
}
