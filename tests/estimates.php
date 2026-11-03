<?php
// Native metadata-only size estimates. Restricted to idle QA-C; no migration.
if (realpath(ABSPATH)!==realpath('/Users/jonschroeder/Cove/Sites/trickle-qa-c.localhost/public') || get_option('_csm_control') || get_option('_csm_active_job')) { throw new RuntimeException('Idle QA-C required.'); }
$name='trickle-size-fixture'; $plugin=WP_PLUGIN_DIR.'/'.$name; $theme=WP_CONTENT_DIR.'/themes/'.$name; $media=CSM_Support::private_dir().'/'.$name;
foreach ([$plugin,$theme,$media] as $dir) { if (file_exists($dir)) { throw new RuntimeException('Fixture already exists.'); } }
$checks=0;
$check=static function($ok,$label) use (&$checks) { if (!$ok) { throw new RuntimeException($label); } $checks++; echo 'PASS '.$label."\n"; };
$read=static function() { $r=CSM_Estimates::read(true,true); for($i=0;$i<200&&(!$r['complete']||!empty($r['refreshing']));$i++) { $r=CSM_Estimates::read(true); } if (!$r['complete']) { throw new RuntimeException('Estimate did not finish.'); } return $r; };
$filter=static function($upload) use ($media) { $upload['basedir']=$media; $upload['error']=false; return $upload; };
CSM_Support::lock();
try {
    foreach ([$plugin,$theme,$media.'/2026/11',$media.'/2025/01',$media.'/misc',$media.'/cache',$plugin.'/node_modules'] as $dir) { if (!is_dir($dir)) { mkdir($dir,0700,true); } }
    file_put_contents($plugin.'/code.txt',str_repeat('p',123)); file_put_contents($plugin.'/.env','private'); file_put_contents($plugin.'/node_modules/skip.txt','skip');
    file_put_contents($theme.'/style.css',str_repeat('t',456)); file_put_contents($media.'/2026/11/a.jpg',str_repeat('a',1000)); file_put_contents($media.'/2026/11/b.jpg',str_repeat('b',200));
    file_put_contents($media.'/2025/01/c.jpg',str_repeat('c',300)); file_put_contents($media.'/misc/d.jpg',str_repeat('d',40)); file_put_contents($media.'/root.jpg',str_repeat('r',10));
    file_put_contents($media.'/cache/skip.jpg','skip'); file_put_contents($media.'/2026/11/skip.php','skip'); file_put_contents($media.'/2026/11/.env','private');
    add_filter('upload_dir',$filter); $r=$read();
    $check($r['items']['plugins'][$name]===123 && $r['items']['themes'][$name]===456,'Per-plugin and per-theme sizes exclude development and environment files');
    $check($r['items']['media'][2026]===1200 && $r['items']['media'][2025]===300 && $r['items']['media']['_other']===50,'Media sizes group years and non-year files without reading contents');
    foreach (['plugins','themes','media'] as $kind) { $check(array_sum($r['items'][$kind])===$r['bytes'][$kind],ucfirst($kind).' component sizes sum to the overall total'); }
    $check($r['bytes']['database']===array_sum(array_column(CSM_Database::inventory(),'bytes')),'Overall database allocation equals the table estimates');
    $check(!isset($r['queue'],$r['active']) && !str_contains(wp_json_encode($r),$media),'Public estimates contain no private paths or scan state');
    unlink($media.'/2026/11/b.jpg'); file_put_contents($media.'/2025/01/new.jpg',str_repeat('n',90)); $r=$read();
    $check($r['items']['media'][2026]===1000 && $r['items']['media'][2025]===390 && $r['bytes']['media']===1440,'Refreshing estimates notices additions and deletions');
    echo $checks." estimate checks passed.\n";
} finally {
    remove_filter('upload_dir',$filter);
    foreach ([$plugin,$theme,$media] as $dir) { CSM_Support::remove_tree($dir); }
    CSM_Support::unlock();
}
