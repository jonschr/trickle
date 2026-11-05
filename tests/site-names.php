<?php
// Actual controller JSON boundaries with isolated WordPress/transport doubles.
define('ABSPATH','/fixture/');$options=[];
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;}
function site_url(){return 'https://local.example';}function home_url(){return site_url();}
function wp_parse_url($url){return parse_url($url);}function wp_salt($scheme){return 'fixture';}
class CSM_Transport {static function url($url){return rtrim($url,'/');}static function request(...$args){return $GLOBALS['remote'];}}
class CSM_Plugin {static function manifest(...$args){return $GLOBALS['local'];}}
require __DIR__.'/../includes/controller.php';
$raw='O&#039;Reilly &quot;Lab&quot; &amp; Co &#8217; &lt;img src=x onerror=alert(1)&gt;';
$plain='O\'Reilly "Lab" & Co ’ <img src=x onerror=alert(1)>';
$peer=['id'=>'remote','url'=>'https://remote.example','name'=>$raw];$options['_csm_peers']=['remote'=>$peer];$options['_csm_recents']=[['remote_url'=>$peer['url'],'remote_name'=>$raw,'direction'=>'push'],['remote_url'=>$peer['url'],'remote_name'=>$raw,'direction'=>'pull']];
$local=['url'=>site_url(),'name'=>$raw,'plugins'=>[],'themes'=>[]];$remote=['url'=>$peer['url'],'name'=>$raw,'plugins'=>[],'themes'=>[]];
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$r=CSM_Controller::inventory($peer);check($r['local']['name']===$plain&&$r['remote']['name']===$plain,'Both source and destination inventories expose plain-text names');
$state=CSM_Controller::state();check($state['connections'][0]['name']===$plain,'Saved connection labels are decoded for menus, form values and push buttons');
check(array_column($state['recents'],'remote_name')===[$plain,$plain],'Push and pull history use the same plain-text names');
check($options['_csm_peers']['remote']['name']===$raw,'Display normalization does not rewrite stored names or credentials');
$local['name']=$remote['name']=$plain;$options['_csm_peers']['remote']['name']=$plain;$r=CSM_Controller::inventory($peer);check($r['local']['name']===$plain&&$r['remote']['name']===$plain&&CSM_Controller::state()['connections'][0]['name']===$plain,'Already plain quotes, Unicode and angle brackets remain literal text');
