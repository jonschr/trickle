<?php
// On-demand metadata sizing on idle QA-C only; no migration or cron dispatch.
if (realpath(ABSPATH)!==realpath('/Users/jonschroeder/Cove/Sites/trickle-qa-c.localhost/public') || get_option('_csm_control') || get_option('_csm_active_job')) { throw new RuntimeException('Idle QA-C required.'); }
$dir=CSM_Support::private_dir().'/on-demand-size-fixture-'.bin2hex(random_bytes(5));
$filter=static function($u) use ($dir) { $u['basedir']=$dir; $u['error']=false; return $u; };
$checks=0;$check=static function($ok,$label) use (&$checks) { if (!$ok) { throw new RuntimeException($label); } $checks++;echo 'PASS '.$label."\n"; };
$finish=static function($r) { for($i=0;$i<20&&(!$r['complete']||!empty($r['refreshing']));$i++) { $r=CSM_Estimates::read(true); } return $r; };
try {
    mkdir($dir.'/2026/11',0700,true);
    for($i=0;$i<21000;$i++) { file_put_contents($dir.'/2026/11/'.$i.'.jpg','x'); }
    add_filter('upload_dir',$filter);
    $cold=CSM_Estimates::read();
    $check(!$cold['complete'] && $cold['bytes']['media']===0,'Reading without a scan does not start activation or background work');
    $start=microtime(true);$first=CSM_Estimates::read(true);
    $check(!$first['complete'] && microtime(true)-$start<3,'A large first connection yields with a checkpoint instead of blocking the request');
    $ready=$finish($first);
    $check($ready['complete'] && $ready['bytes']['media']===21000 && $ready['items']['media'][2026]===21000,'Follow-up UI requests finish size estimates before any pull is started');
    file_put_contents($dir.'/2026/11/extra.jpg',str_repeat('n',40));$during=CSM_Estimates::read(true,true);
    $check(!empty($during['refreshing']) && $during['bytes']['media']===21000,'Last completed totals stay visible while an on-demand refresh runs');
    $fresh=$finish($during);
    $check(empty($fresh['refreshing']) && $fresh['bytes']['media']===21040,'Completed refresh publishes the new totals');
    $check(!get_option('_csm_control')&&!get_option('_csm_active_job')&&!wp_next_scheduled('trickle_scan_sizes')&&!wp_next_scheduled('trickle_refresh_sizes'),'Sizing starts no transfer and schedules no background jobs');
    echo $checks." on-demand estimate checks passed.\n";
} finally { remove_filter('upload_dir',$filter);CSM_Support::remove_tree($dir); }
