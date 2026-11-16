<?php
/** Local-only integration checks: creates draft fixtures and removes them in finally. */
if ( 'cli' !== PHP_SAPI ) { http_response_code(404); exit; }
if ( '--allow-local-draft-test' !== ($argv[1] ?? '') ) { fwrite(STDERR,"Usage: php match-admin-test.php --allow-local-draft-test (local only)\n"); exit(2); }
define('WP_USE_THEMES',false);
require dirname(__DIR__,4).'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH.'wp-admin/includes/screen.php';
set_current_screen('edit-mang');$GLOBALS['pagenow']='edit.php';
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);wp_set_current_user($admins[0]);
$ids=[];$checks=0;
function verify_match_list($condition,$description){global $checks;++$checks;if(!$condition)throw new RuntimeException('FAIL: '.$description);}
function list_test_matches($ids,$get=[],$args=[]){
 $_GET=$get;
 $q=new WP_Query();$GLOBALS['wp_the_query']=$q;
 return $q->query(array_merge(['post_type'=>'mang','post_status'=>'draft','post__in'=>$ids,'posts_per_page'=>-1,'fields'=>'ids'], $args));
}
try {
 foreach(['2026-11-02 09:00:00','2026-11-02T18:00','2026-11-03 23:59:59',null,''] as $date){
  $id=wp_insert_post(['post_type'=>'mang','post_status'=>'draft','post_title'=>'SK DUO date filter test'],true);
  if(is_wp_error($id))throw new RuntimeException('Fixture failed');$ids[]=$id;
  if(null!==$date)update_post_meta($id,'_match_date',$date);
 }
 verify_match_list(list_test_matches($ids)===$ids,'Default chronological order; both datetime formats; undated last');
 verify_match_list(list_test_matches($ids,[],['orderby'=>'skduo_match_date','order'=>'DESC'])===[$ids[2],$ids[1],$ids[0],$ids[4],$ids[3]],'Reverse order retains undated last');
 verify_match_list(list_test_matches($ids,['skduo_match_from'=>'2026-11-02','skduo_match_to'=>'2026-11-02'])===[$ids[0],$ids[1]],'Single day includes morning and evening');
 verify_match_list(list_test_matches($ids,['skduo_match_from'=>'2026-11-03'])===[$ids[2]],'From-only filter');
 verify_match_list(list_test_matches($ids,['skduo_match_to'=>'2026-11-02'])===[$ids[0],$ids[1]],'To-only filter');
 verify_match_list(list_test_matches($ids,['skduo_match_from'=>'2026-11-02','skduo_match_to'=>'2026-11-03'])===array_slice($ids,0,3),'Upper bound includes 23:59:59');
 verify_match_list(list_test_matches($ids,['skduo_match_from'=>'2026-11-03','skduo_match_to'=>'2026-11-02'])===[],'Reversed range returns no accidental full list');
 verify_match_list(list_test_matches($ids,['skduo_match_from'=>"2026-11-02' OR 1=1"])===[],'Invalid date cannot broaden query');
 verify_match_list(list_test_matches($ids,['skduo_match_from'=>['bad']])===[],'Array parameter rejected');
 verify_match_list(skduo_match_admin_date('2026-02-30')==='' && skduo_match_admin_date('2028-02-29')==='2028-02-29','Real calendar validation');
 $q=new WP_Query(['post_type'=>'mang','post_status'=>'draft','post__in'=>$ids,'fields'=>'ids']);
 verify_match_list(!$q->get('skduo_match_admin_list'),'Secondary queries untouched');
 $cols=apply_filters('manage_mang_posts_columns',['cb'=>'','title'=>'Title','date'=>'Date']);
 verify_match_list(array_keys($cols)===['cb','title','skduo_match_date','date'],'Date column appears next to title');
 $q=new WP_Query();$GLOBALS['wp_the_query']=$q;$_GET=[];
 $q->query(['post_type'=>'mang','post_status'=>'draft','post__in'=>$ids,'fields'=>'ids','orderby'=>'title','order'=>'DESC']);
 verify_match_list($q->get('orderby')==='title','Explicit title sorting remains available');
 echo "PASS: $checks real WordPress date-list checks\n";
}finally{foreach($ids as $id)wp_delete_post($id,true);$_GET=[];}
