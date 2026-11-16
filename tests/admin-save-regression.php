<?php
if ('cli' !== PHP_SAPI) { http_response_code(404); exit; }
if (($argv[3] ?? '') !== '--allow-local-option-test' || !in_array($argv[1] ?? '', ['bvl','evf'], true) || !in_array($argv[2] ?? '', ['initial','season','unchanged'], true)) { fwrite(STDERR, "Local only: temporarily changes settings, then restores them. Usage: php admin-save-regression.php bvl initial --allow-local-option-test\n"); exit(2); }
define('WP_USE_THEMES',false);
require dirname(__DIR__, 4) . '/wp-load.php';
$source=$argv[1];$scenario=$argv[2];
$settings_key=skduo_dp_option('settings',$source);$discovery_key=skduo_dp_option('discovery',$source);$log_key=skduo_dp_option('log',$source);
$original=[ $settings_key=>get_option($settings_key),$discovery_key=>get_option($discovery_key),$log_key=>get_option($log_key) ];
$other=$source==='bvl'?'evf':'bvl';$other_before=get_option(skduo_dp_option('settings',$other));
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);wp_set_current_user($admins[0]);
$expected=['code'=>strtoupper($source),'season_id'=>10,'competition_id'=>$source==='bvl'?29:100,'team_id'=>$source==='bvl'?213:777];
$before=skduo_dp_settings($source);
if($scenario==='initial') {$before['code']='';$before['season_id']=$before['competition_id']=$before['team_id']=0;}
elseif($scenario==='season') {$before['season_id']=9;}
update_option($settings_key,$before,false);
register_shutdown_function(function() use($source,$scenario,$expected,$settings_key,$original,$other,$other_before,$admins){
 $saved=get_option($settings_key);$passed=true;
 foreach($expected as $key=>$value)if($saved[$key]!==$value)$passed=false;
 if(get_option(skduo_dp_option('settings',$other))!==$other_before)$passed=false;
 foreach($original as $key=>$value) {if(false===$value)delete_option($key);else update_option($key,$value,false);}
 delete_transient('skduo_dp_notice_'.$admins[0]);
 echo ($passed?'PASS':'FAIL').": $source $scenario: submitted IDs preserved, other connection unchanged\n";
 if(!$passed)exit(1);
});
$_SERVER['REQUEST_METHOD']='POST';
$_POST=['operation'=>'save','source'=>$source,'code'=>$expected['code'],'season_id'=>'10','competition_id'=>(string)$expected['competition_id'],'team_id'=>(string)$expected['team_id'],'timezone'=>'Europe/Tallinn','token'=>'','_wpnonce'=>wp_create_nonce('skduo_dp_action')];
$_REQUEST=$_POST;
do_action('admin_post_skduo_dp_action');
