<?php
// Exercise preference validation and persistence without booting or changing a site.
define('ABSPATH', __DIR__);
$options = [];
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; }
function wp_cache_delete(...$args){}
require __DIR__ . '/../includes/class-csm-controller.php';
$operate = new ReflectionMethod(CSM_Controller::class, 'operate');
$check = static function ($ok) { if (!$ok) throw new RuntimeException('Preference check failed'); };
$defaults = $operate->invoke(null, 'transfer_settings', ['transport' => 'chunks', 'file_verify' => true]);
$check($defaults === ['transport' => 'chunks']);
$check(get_option('_csm_transfer_defaults') === $defaults);
foreach (['invalid', [], null] as $transport) {
    try { $operate->invoke(null, 'transfer_settings', ['transport' => $transport]); throw new LogicException('Accepted invalid transport'); }
    catch (RuntimeException $e) { $check(get_option('_csm_transfer_defaults') === $defaults); }
}
$defaults=$operate->invoke(null,'transfer_settings',['transport'=>'stream','request_interval'=>0,'setup_request_interval'=>.5]);
$check($defaults===['transport'=>'stream','request_interval'=>0.0,'setup_request_interval'=>.5]);
$check($operate->invoke(null,'transfer_settings',['transport'=>'chunks'])===array_replace($defaults,['transport'=>'chunks']));
$defaults=get_option('_csm_transfer_defaults');
foreach(['request_interval','setup_request_interval']as$key)foreach([-1,61,[],true,'bad',INF]as$value){
 try{$operate->invoke(null,'transfer_settings',['transport'=>'stream',$key=>$value]);throw new LogicException('Invalid spacing accepted');}
 catch(RuntimeException $e){$check(get_option('_csm_transfer_defaults')===$defaults);}
}
$options['_csm_control'] = ['id' => 'active'];
try { $operate->invoke(null, 'transfer_settings', ['transport' => 'stream']); throw new LogicException('Changed preferences during transfer'); }
catch (RuntimeException $e) { $check(get_option('_csm_transfer_defaults') === $defaults); }
$selection=['transport'=>'stream','tables'=>['wp_posts']];
$options['_csm_control']['selection']=$selection;
$changed=$operate->invoke(null,'transfer_settings',['request_interval'=>2,'setup_request_interval'=>1]);
$check($changed===array_replace($defaults,['request_interval'=>2.0,'setup_request_interval'=>1.0]));
$check($options['_csm_control']['selection']===$selection);
echo "Preference checks passed: saved global defaults, retained-job spacing updates and protected transfer method/selection.\n";
