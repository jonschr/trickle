#!/usr/bin/env python3
"""Offline regression checks using the real loader/auth/storage code; no site writes."""
import json, os, pathlib, subprocess, tempfile
REPO = pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='trickle-security-') as tmp:
    base = pathlib.Path(tmp); public = base/'public'; wp = public/'wordpress'
    for directory in [wp/'wp-admin', wp/'wp-includes', wp/'wp-content/plugins/trickle', public/'tmp', base/'private']:
        directory.mkdir(parents=True, exist_ok=True)
    for name in ['index.php', 'wp-login.php', 'wp-admin/tools.php', 'wp-admin/admin-ajax.php', 'wp-admin/users.php']:
        (wp/name).touch()
    (wp/'wp-content/plugins/trickle/includes').symlink_to(REPO/'includes', target_is_directory=True)
    (wp/'wp-includes/pluggable.php').write_text('''<?php
function current_user_can($cap) { if (!defined('AUTH_COOKIE')) throw new RuntimeException('Cookie constants missing at MU bootstrap'); return !empty($GLOBALS['scenario']['admin']); }
function wp_verify_nonce($nonce,$action) { return $nonce==='valid' ? 1 : false; }
''')
    runner = base/'runner.php'
    runner.write_text('''<?php
$scenario=json_decode($argv[1],true); $wp=$argv[2]; $repo=$argv[3];
define('ABSPATH',$wp.'/');define('WPINC','wp-includes');define('WP_PLUGIN_DIR',$wp.'/wp-content/plugins');
define('CSM_VERSION','0.1.15');define('__CSM_PLUGIN__','trickle/trickle.php');
if (!empty($scenario['ajax'])) define('DOING_AJAX',true);
if (!empty($scenario['cron'])) define('DOING_CRON',true);
$options=['active_plugins'=>['trickle/trickle.php','security/security.php'],'template'=>'theme','stylesheet'=>'theme','_csm_key'=>str_repeat('a',64)];
if (!empty($scenario['cron'])) {$options['_csm_active_job']='job';$options['_csm_job_job']=['status'=>'applying'];}
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
function add_option($k,$v,$unused='',$autoload=false){if(isset($GLOBALS['options'][$k]))return false;$GLOBALS['options'][$k]=$v;return true;}
function wp_cookie_constants(){if(!defined('AUTH_COOKIE'))define('AUTH_COOKIE','test-auth');}
function is_admin(){return !empty($GLOBALS['scenario']['is_admin']);}
function wp_salt($scheme){return 'isolated-test-salt';}
function add_filter($k,$fn,$priority){$GLOBALS['filters'][$k][]=$fn;}
$_GET=$scenario['get']??[];$_POST=$scenario['post']??[];$_REQUEST=$scenario['request']??array_merge($_GET,$_POST);
$_SERVER['SCRIPT_FILENAME']=$wp.'/'.($scenario['script']??'wp-login.php');
$_SERVER['REQUEST_METHOD']=$scenario['method']??'GET';$_SERVER['DOCUMENT_ROOT']=dirname($wp);
require $repo.'/includes/support.php';require $repo.'/includes/access.php';require $repo.'/includes/transport.php';
if (isset($scenario['signed'])) {
 $action=!empty($scenario['auth'])?'stream_data':'status';
 foreach(CSM_Transport::headers($action,str_repeat('a',64),!empty($scenario['auth'])?'stream':hash('sha256','')) as $h){if(!str_contains($h,': '))continue;[$k,$v]=explode(': ',$h,2);$_SERVER['HTTP_'.str_replace('-','_',strtoupper($k))]=$v;}
 if($scenario['signed']==='bad')$_SERVER['HTTP_X_CSM_SIGNATURE']=str_repeat('0',64);
 if($scenario['signed']==='expired')$_SERVER['HTTP_X_CSM_TIME']='1';
 if($scenario['signed']==='disabled')$options['_csm_settings']=['push'=>false,'pull'=>false];
}
if (!empty($scenario['storage'])) {
 try{$path=$scenario['storage']==='backup'?CSM_Support::backup_dir():CSM_Support::private_dir();echo json_encode(['accepted'=>true,'used_backup'=>str_contains($path,'/.trickle-backups/')]);}
 catch(RuntimeException $e){echo json_encode(['accepted'=>false,'error'=>$e->getMessage()]);}exit;
}
if (!empty($scenario['auth'])) {
 class CSM_Updates {public static function assert_version($v){if($v!==CSM_VERSION)throw new RuntimeException('version mismatch');}}
 CSM_Transport::signed_credential('stream_data');
 CSM_Transport::authenticate('stream_data');
 try {CSM_Transport::authenticate('stream_data');throw new LogicException('Replay accepted');}
 catch(RuntimeException $e){echo json_encode(['replay_rejected'=>true]);}exit;
}
require $repo.'/includes/loader.php';
$active=$options['active_plugins'];foreach($GLOBALS['filters']['option_active_plugins']??[] as $fn)$active=$fn($active);
echo json_encode(['isolated'=>$active===['trickle/trickle.php'],'nonces'=>count(array_filter(array_keys($options),fn($k)=>str_starts_with($k,'_csm_nonce_')))]);
''')
    def run(scenario, temp=None, home=None):
        args=['php']
        if temp: args+=['-d','sys_temp_dir='+str(temp)]
        p=subprocess.run(args+[str(runner),json.dumps(scenario),str(wp),str(REPO)],capture_output=True,text=True,env={**os.environ, **({'HOME':str(home)} if home else {})})
        assert p.returncode==0,p.stderr
        return json.loads(p.stdout)
    cases=[
        ('login POST spoof',{'method':'POST','post':{'action':'csm_admin','op':'status'}},False),
        ('login page spoof',{'get':{'page':'trickle'},'is_admin':True},False),
        ('unrelated admin page',{'script':'wp-admin/users.php','get':{'page':'trickle'},'admin':True},False),
        ('login signed RPC spoof',{'method':'POST','get':{'trickle':'status'},'signed':'valid'},False),
        ('unsigned RPC',{'script':'index.php','method':'POST','get':{'trickle':'status'}},False),
        *[(kind+' RPC',{'script':'index.php','method':'POST','get':{'trickle':'status'},'signed':kind},kind=='valid') for kind in ['bad','expired','disabled','valid']],
        ('RPC GET',{'script':'index.php','get':{'trickle':'status'},'signed':'valid'},False),
        ('anonymous dashboard',{'script':'wp-admin/tools.php','get':{'page':'trickle'}},False),
        ('administrator dashboard',{'script':'wp-admin/tools.php','get':{'page':'trickle'},'admin':True},True),
        ('anonymous AJAX',{'script':'wp-admin/admin-ajax.php','ajax':True,'method':'POST','post':{'action':'csm_admin','op':'status','nonce':'valid'}},False),
        ('bad nonce AJAX',{'script':'wp-admin/admin-ajax.php','ajax':True,'method':'POST','admin':True,'post':{'action':'csm_admin','op':'status','nonce':'bad'}},False),
        ('authenticated AJAX',{'script':'wp-admin/admin-ajax.php','ajax':True,'method':'POST','admin':True,'post':{'action':'csm_admin','op':'status','nonce':'valid'}},True),
        ('conflicting AJAX action',{'script':'wp-admin/admin-ajax.php','ajax':True,'method':'POST','admin':True,'request':{'action':'other'},'post':{'action':'csm_admin','op':'status','nonce':'valid'}},False),
        ('recovery cron',{'cron':True},True),
    ]
    for label,scenario,expected in cases:
        result=run(scenario);assert result['isolated']==expected,(label,result)
        assert result['nonces']==0,'Early authentication must not consume the nonce'
        print('PASS '+label)
    assert run({'auth':True,'signed':'valid'})['replay_rejected'],'Early verification broke replay protection'
    print('PASS early signature validation followed by final authentication and replay rejection')
    assert not run({'storage':True},public/'tmp')['accepted'],'Public sibling staging accepted'
    assert not run({'storage':True},wp)['accepted'],'WordPress staging accepted'
    assert run({'storage':True},base/'private')['accepted'],'Private staging rejected'
    (public/'linked-tmp').symlink_to(base/'private', target_is_directory=True)
    assert not run({'storage':True},public/'linked-tmp')['accepted'],'Public symlink staging accepted'
    print('PASS storage: document-root sibling rejected, WordPress root rejected, private storage allowed, public symlink rejected')
    assert run({'storage':'backup'},base/'private',base/'private')['used_backup'],'Safe backup home rejected'
    assert not run({'storage':'backup'},base/'private',public)['used_backup'],'Public backup home accepted'
    assert not run({'storage':'backup'},base/'private',public/'linked-tmp')['used_backup'],'Public symlink backup home accepted'
    print('PASS backups: private home allowed, public and symlink homes fall back to private staging')
