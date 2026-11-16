<?php
/** Offline contract/integration tests. No real database, API credentials or imported matches. */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ );
define( 'MB_IN_BYTES', 1048576 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
class WP_Error {
	public function __construct( public string $code, public string $message ) {}
	public function get_error_message(): string { return $this->message; }
	public function get_error_code(): string { return $this->code; }
}
class WP_Post {
	public function __construct( public int $ID ) {}
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action( ...$args ) { $GLOBALS['test_actions'][$args[0]][]=$args[1]; }
function get_option( $k, $d = false ) { return $GLOBALS['options'][$k] ?? $d; }
function update_option( $k, $v, ...$args ) { $GLOBALS['options'][$k] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][$k] ); }
function add_option( $k, $v, ...$args ) { if ( array_key_exists( $k, $GLOBALS['options'] ) ) return false; $GLOBALS['options'][$k] = $v; return true; }
function sanitize_text_field( $v ) { return strip_tags( $v ); }
function wp_timezone() { return new DateTimeZone( 'Europe/Tallinn' ); }
function add_query_arg( $query, $url ) { return $url . ( $query ? '?' . http_build_query( $query ) : '' ); }
function wp_remote_retrieve_response_code( $r ) { return $r['status']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_safe_remote_get( $url, $args ) {
	$GLOBALS['calls'][] = [ $url, $args ];
	preg_match( '#/v1/VO/([^/]+)/(.*)$#', $url, $parts );
	$path = $parts[2] ?? 'invalid';
	if ( ( $GLOBALS['fail_code'] ?? null ) === ( $parts[1] ?? '' ) ) return new WP_Error( 'network', 'fixture failure' );
	$r = $GLOBALS['responses'][$path] ?? new WP_Error( 'fixture', 'Missing fixture: '.$path );
	return is_wp_error( $r ) ? $r : [ 'status' => $r['status'] ?? 200, 'headers' => $r['headers'] ?? [], 'body' => $r['body'] ?? json_encode( $r['data'] ) ];
}
function wp_remote_retrieve_header( $r, $key ) { return $r['headers'][$key] ?? ''; }
function wp_salt( $key ) { return $GLOBALS['test_salt'] ?? 'fixture-local-salt'; }
function get_transient( $k ) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['transients'][$k]=$v; $GLOBALS['ttls'][$k]=$ttl; }
function delete_transient( $k ) { unset($GLOBALS['transients'][$k]); }
function get_post_stati() { return [ 'publish' => 'publish', 'draft' => 'draft', 'trash' => 'trash' ]; }
function get_posts( $args ) {
	return array_slice( array_keys( array_filter( $GLOBALS['posts'], fn ( $p ) => ( $p['meta_input'][ $args['meta_key'] ] ?? null ) === $args['meta_value'] ) ), 0, $args['posts_per_page'] );
}
function get_post_status( $id ) { return $GLOBALS['posts'][$id]['post_status']; }
function wp_slash( $v ) { return $v; }
function wp_insert_post( $post, $error ) {
	if ( $GLOBALS['fail_write'] ) return new WP_Error( 'write', 'simulated failure' );
	$id = $post['ID'] ?: count( $GLOBALS['posts'] ) + 1;
	$old = $GLOBALS['posts'][$id] ?? [];
	$post['meta_input'] = array_merge( $old['meta_input'] ?? [], $post['meta_input'] );
	$GLOBALS['posts'][$id] = array_merge( $old, $post );
	return $id;
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['meta_writes'] = ($GLOBALS['meta_writes'] ?? 0) + 1;
	if($GLOBALS['fail_write']) return false;
	$GLOBALS['posts'][$id]['meta_input'][$key]=$value; return true;
}
function wp_update_post( $post, $error ) {
	$GLOBALS['post_writes'] = ($GLOBALS['post_writes'] ?? 0) + 1;
	if($GLOBALS['fail_write']) return new WP_Error('write','simulated failure');
	$GLOBALS['posts'][$post['ID']]=array_merge($GLOBALS['posts'][$post['ID']],$post);return $post['ID'];
}
function skduo_rest_bust_match_cache() { ++$GLOBALS['cache_clears']; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['posts'][$id]['meta_input'][$key] ?? ''; }
function get_the_title( $p ) { return $GLOBALS['posts'][$p->ID]['post_title']; }
function get_permalink( $id ) { return '/mang/' . ( $id instanceof WP_Post ? $id->ID : $id ); }
require __DIR__ . '/../includes/dataproject-security.php';
require __DIR__ . '/../includes/dataproject.php';
require __DIR__ . '/../includes/helpers.php';
$assertions = 0;
function check( $condition, $description ) {
	global $assertions;
	++$assertions;
	if ( ! $condition ) throw new RuntimeException( 'FAIL: ' . $description );
}
function fixture_match( $id = 10, $leg = 6, $home = 7, $guest = 8 ) {
	return [ 'MatchID'=>$id, 'ChampionshipID'=>5, 'ChampionshipLegID'=>$leg, 'CompetitionID'=>3, 'SeasonID'=>2,
		'HomeTeamID'=>$home, 'GuestTeamID'=>$guest, 'HomeTeam'=>'Fixture home', 'GuestTeam'=>'Fixture guest',
		'MatchDateTime'=>'2026-10-20T19:00:00', 'Final_Home'=>0, 'Final_Guest'=>0,
		'Finalized'=>false, 'Status'=>1, 'Stadium'=>'Fixture hall' ];
}
function reset_fixture() {
	$GLOBALS['transients']=[]; $GLOBALS['ttls']=[]; $GLOBALS['test_salt']='fixture-local-salt';
	$GLOBALS['options'] = [ 'skduo_dp_token'=>'fixture-secret', 'skduo_dp_settings'=>[
		'code'=>'test', 'season_id'=>2, 'competition_id'=>3, 'team_id'=>7, 'timezone'=>'Europe/Tallinn', 'automatic'=>false,
	] ];
	$GLOBALS['fail_code'] = null; $GLOBALS['posts'] = []; $GLOBALS['calls'] = []; $GLOBALS['fail_write'] = false; $GLOBALS['cache_clears'] = 0;
	$GLOBALS['responses'] = [
		'Competition/3'=>['data'=>['CompetitionID'=>3, 'SeasonID'=>2, 'Description'=>'Fixture league']],
		'Team/7'=>['data'=>['TeamID'=>7, 'SeasonID'=>2, 'Name'=>'Fixture team']],
		'Phase?competitionID=3'=>['data'=>[['PhaseID'=>4,'CompetitionID'=>3]]],
		'Championship?phaseID=4'=>['data'=>[['ChampionshipID'=>5,'CompetitionPhaseID'=>4]]],
		'Leg?championshipID=5'=>['data'=>[['LegID'=>6,'ChampionshipID'=>5],['LegID'=>9,'ChampionshipID'=>5]]],
		'Match?legID=6'=>['data'=>[fixture_match(),fixture_match(11,6,8,7),fixture_match(12,6,8,9)]],
		'Match?legID=9'=>['data'=>[]],
	];
}
reset_fixture();
$GLOBALS['responses']['Stadium/888']=['data'=>['StadiumID'=>888,'StadiumName'=>'Resolved fixture hall']];
foreach([0,1] as $index) {
 $GLOBALS['responses']['Match?legID=6']['data'][$index]['Stadium']='';
 $GLOBALS['responses']['Match?legID=6']['data'][$index]['StadiumID']=888;
}
$r=skduo_dp_sync();
check(!is_wp_error($r) && $GLOBALS['posts'][1]['meta_input']['_venue']==='Resolved fixture hall','Stadium ID resolves missing venue');
check(count(array_filter($GLOBALS['calls'],fn($call)=>str_ends_with($call[0],'/Stadium/888')))===1,'Same stadium fetched only once per sync');
reset_fixture();
$GLOBALS['responses']['Match?legID=6']['data'][0]['Stadium']='';
$GLOBALS['responses']['Match?legID=6']['data'][0]['StadiumID']=888;
$GLOBALS['responses']['Stadium/888']=['status'=>500,'body'=>'error'];
check(is_wp_error(skduo_dp_sync()) && !$GLOBALS['posts'],'Stadium failure prevents partial import');
reset_fixture();
$r=skduo_dp_sync();
check( !is_wp_error($r) && $r['created']===2, 'Only home/away matches of selected team are imported' );
check( count($GLOBALS['posts'])===2, 'Other teams excluded' );
check( skduo_match_data(new WP_Post(1))->is_home === true && skduo_match_data(new WP_Post(2))->is_home === false, 'Home/away uses IDs even with arbitrary team names' );
check( skduo_match_data(new WP_Post(1))->date_ts === (new DateTimeImmutable('2026-10-20T19:00:00',wp_timezone()))->getTimestamp(), 'Calendar timestamp uses site timezone' );
check( !isset($GLOBALS['options']['skduo_dp_lock']), 'Lock released on success' );
check( $GLOBALS['cache_clears']===1, 'Calendar caches cleared' );
foreach($GLOBALS['calls'] as [$url,$args]) {
 check( str_starts_with($url,'https://dataprojectserviceswebapi.azurewebsites.net/v1/VO/test/') && $args['redirection']===0, 'HTTPS fixed host; token cannot follow redirects' );
 check( $args['headers']['Authorization']==='Bearer fixture-secret', 'Bearer authorization' );
}
$GLOBALS['posts'][1]['meta_input']['_ticket_url']='https://tickets.example/fixture';
$GLOBALS['posts'][1]['post_content']='Editorial content';
$GLOBALS['responses']['Match?legID=6']['data'][0]['MatchDateTime']='2026-10-21T20:00:00';
$GLOBALS['responses']['Match?legID=6']['data'][0]['Finalized']=true;
$GLOBALS['responses']['Match?legID=6']['data'][0]['Final_Home']=3;
$GLOBALS['responses']['Match?legID=6']['data'][0]['Final_Guest']=1;
$r=skduo_dp_sync();
check( $r['updated']===1 && $r['unchanged']===1 && count($GLOBALS['posts'])===2, 'Repeat import does not duplicate' );
check( $GLOBALS['posts'][1]['meta_input']['_match_date']==='2026-10-21 20:00:00', 'Rescheduled time updated' );
check( $GLOBALS['posts'][1]['meta_input']['_status']==='finished' && $GLOBALS['posts'][1]['meta_input']['_home_score']===3, 'Final result updated' );
check( $GLOBALS['posts'][1]['meta_input']['_ticket_url']==='https://tickets.example/fixture' && $GLOBALS['posts'][1]['post_content']==='Editorial content', 'Tickets and editorial content preserved' );
$GLOBALS['responses']['Match?legID=6']['data'][0]['MatchDateTime']=null;
$r=skduo_dp_sync();
check( $r['undated']===1 && $GLOBALS['posts'][1]['post_status']==='draft' && $GLOBALS['posts'][1]['meta_input']['_match_date']==='', 'Withdrawn date removes stale calendar date' );
$GLOBALS['responses']['Match?legID=6']['data'][0]['MatchDateTime']='2026-10-22T18:00:00';
$r=skduo_dp_sync();
check( $GLOBALS['posts'][1]['post_status']==='publish', 'Confirmed date publishes draft' );
$GLOBALS['posts'][1]['post_status']='trash';
$r=skduo_dp_sync();
check( $r['trashed']===1 && $GLOBALS['posts'][1]['post_status']==='trash', 'Trash is never restored by sync' );
reset_fixture();
$GLOBALS['responses']['Match?legID=9']=['status'=>401,'body'=>'fixture-secret'];
$r=skduo_dp_sync();
check( is_wp_error($r) && count($GLOBALS['posts'])===0, 'Failure in final round prevents all writes' );
check( !str_contains($r->get_error_message(),'fixture-secret') && str_contains($r->get_error_message(),'401'), 'Auth error is useful without leaking response/token' );
check( !isset($GLOBALS['options']['skduo_dp_lock']), 'Lock released after API failure' );
foreach([
 ['Competition/3','SeasonID',99], ['Team/7','TeamID',99],
 ['Phase?competitionID=3','CompetitionID',99], ['Championship?phaseID=4','CompetitionPhaseID',99],
 ['Leg?championshipID=5','ChampionshipID',99], ['Match?legID=6','CompetitionID',99], ['Match?legID=6','SeasonID',99], ['Match?legID=6','ChampionshipLegID',99],
] as [$path,$key,$value]) {
 reset_fixture();
 if(array_is_list($GLOBALS['responses'][$path]['data'])) $GLOBALS['responses'][$path]['data'][0][$key]=$value;
 else $GLOBALS['responses'][$path]['data'][$key]=$value;
 check( is_wp_error(skduo_dp_sync()) && !$GLOBALS['posts'], 'Wrong scope rejected: '.$path.'/'.$key );
}
reset_fixture();
$GLOBALS['responses']['Match?legID=6']['data'][]=fixture_match();
check(is_wp_error(skduo_dp_sync()) && !$GLOBALS['posts'],'Duplicate API ID rejected before writes');
reset_fixture();
$GLOBALS['responses']['Match?legID=6']=['body'=>'{"items":[]}'];
check(is_wp_error(skduo_dp_sync()) && !$GLOBALS['posts'],'Undocumented envelope rejected');
reset_fixture();
$GLOBALS['responses']['Match?legID=6']=['body'=>'<html>error</html>'];
check(is_wp_error(skduo_dp_sync()) && !$GLOBALS['posts'],'Invalid JSON rejected');
reset_fixture();
$GLOBALS['options']['skduo_dp_token']='';
check(is_wp_error(skduo_dp_sync()) && !$GLOBALS['calls'],'Missing token fails without sending requests');
reset_fixture();
$GLOBALS['options']['skduo_dp_settings']['code']='../external';
check(is_wp_error(skduo_dp_sync()) && !$GLOBALS['calls'],'Invalid organization cannot modify URL');
reset_fixture();
$GLOBALS['options']['skduo_dp_lock']=time();
check(is_wp_error(skduo_dp_sync()) && !$GLOBALS['calls'],'Concurrent sync rejected');
reset_fixture();
$GLOBALS['posts'][1]=['post_status'=>'publish','meta_input'=>['_skduo_dp_source'=>'test:10']];
$GLOBALS['posts'][2]=$GLOBALS['posts'][1];
check(is_wp_error(skduo_dp_sync()) && count($GLOBALS['posts'])===2,'Local duplicate IDs fail before writes');
reset_fixture();
$GLOBALS['fail_write']=true;
check(is_wp_error(skduo_dp_sync()) && !isset($GLOBALS['options']['skduo_dp_lock']) && $GLOBALS['cache_clears']===1,'Database failure releases lock and clears cache');
reset_fixture();
$settings=skduo_dp_settings();
foreach(['2026-02-30T19:00:00','garbage','2026-10-20T99:00:00'] as $date) {
 $m=fixture_match(); $m['MatchDateTime']=$date;
 check(is_wp_error(skduo_dp_normalize_match($m,$settings,'Fixture')),'Invalid date rejected: '.$date);
}
$m=fixture_match(); $m['MatchDateTime']='2026-10-20T16:00:00Z';
check(skduo_dp_normalize_match($m,$settings,'Fixture')['meta']['_match_date']==='2026-10-20 19:00:00','UTC converts to Tallinn summer time');
$m['MatchDateTime']='2026-10-27T16:00:00Z';
check(skduo_dp_normalize_match($m,$settings,'Fixture')['meta']['_match_date']==='2026-10-27 18:00:00','UTC converts to Tallinn winter time');
$m['Finalized']='false';
check(is_wp_error(skduo_dp_normalize_match($m,$settings,'Fixture')),'String false cannot mark match finalized');
$m=fixture_match(); $m['Final_Home']=-1;
check(is_wp_error(skduo_dp_normalize_match($m,$settings,'Fixture')),'Negative score rejected');
$m=fixture_match(); $m['Status']=99;
check(skduo_dp_normalize_match($m,$settings,'Fixture')['meta']['_status']==='upcoming','Undocumented numeric status is not guessed as live/finished');
reset_fixture();
skduo_dp_sync();
$GLOBALS['responses']['Match?legID=6']['data']=[];
$r=skduo_dp_sync();
check(!is_wp_error($r) && count($GLOBALS['posts'])===2,'Empty response never deletes existing matches');
reset_fixture();
$legacy = skduo_dp_settings();
check($legacy['source']==='bvl' && skduo_dp_token()==='fixture-secret','Legacy BVL settings and token retained');
$evf=$legacy; $evf['source']='evf'; $evf['code']='EVF';
$GLOBALS['options']['skduo_dp_settings_evf']=$evf;
check(is_wp_error(skduo_dp_get('Season',[],skduo_dp_settings('evf'))),'Missing EVF token cannot fall back to BVL credential');
$GLOBALS['options']['skduo_dp_token_evf']='fixture-evf-secret';
$GLOBALS['responses']['Season']=['data'=>[]];
skduo_dp_get('Season',[],skduo_dp_settings('evf'));
check(end($GLOBALS['calls'])[1]['headers']['Authorization']==='Bearer fixture-evf-secret','EVF request uses only EVF token');
check(skduo_dp_token()==='fixture-secret','EVF setup leaves BVL token untouched');
$bvl=skduo_dp_sync(); $cup=skduo_dp_sync('evf');
check(!is_wp_error($bvl) && !is_wp_error($cup) && count($GLOBALS['posts'])===4,'Same numeric MatchIDs from different organizations do not collide');
check($GLOBALS['posts'][1]['meta_input']['_skduo_dp_source']==='test:10' && $GLOBALS['posts'][3]['meta_input']['_skduo_dp_source']==='EVF:10','Each match retains organization namespace');
check(skduo_dp_sync('evf')['unchanged']===2 && count($GLOBALS['posts'])===4,'Repeat cup import keeps BVL and cup records');
check(isset($GLOBALS['options']['skduo_dp_last_result'],$GLOBALS['options']['skduo_dp_last_result_evf']),'Sync results stored separately');
$GLOBALS['options']['skduo_dp_settings']['automatic']=true;
$GLOBALS['options']['skduo_dp_settings_evf']['automatic']=true;
$GLOBALS['fail_code']='test';
skduo_dp_sync_enabled_sources();
check($GLOBALS['options']['skduo_dp_last_result']['error']===true && $GLOBALS['options']['skduo_dp_last_result_evf']['error']===false,'Cron continues EVF when BVL fails');
$GLOBALS['options']['skduo_dp_settings']['automatic']=false;
check(!skduo_dp_connection_enabled('bvl') && skduo_dp_connection_enabled('evf'),'Automatic sync can be enabled independently');
reset_fixture();
check(skduo_dp_token()==='fixture-secret' && str_starts_with($GLOBALS['options']['skduo_dp_token'],'skduo:v1:'),'Legacy plaintext migrates to encrypted storage');
check(!str_contains($GLOBALS['options']['skduo_dp_token'],'fixture-secret'),'Encrypted database value does not contain credential');
$first=skduo_dp_encrypt_token('fixture-secret','bvl');$second=skduo_dp_encrypt_token('fixture-secret','bvl');
check($first!==$second && skduo_dp_decrypt_token($first,'bvl')==='fixture-secret','Random IV and decrypt round-trip');
check(skduo_dp_decrypt_token($first,'evf')==='','Encrypted token is bound to its source');
$raw=base64_decode(substr($first,9));$raw[30]=chr(ord($raw[30])^1);
check(skduo_dp_decrypt_token('skduo:v1:'.base64_encode($raw),'bvl')==='','Modified ciphertext fails authentication');
$GLOBALS['test_salt']='different-salt';
check(skduo_dp_decrypt_token($first,'bvl')==='','Changed salt fails closed');
reset_fixture();
foreach(["abc\r\nInjected: x",'Bearer token','',str_repeat('a',8193)] as $token) {
 check(is_wp_error(skduo_dp_save_token($token,'bvl')),'Invalid Bearer token rejected');
}
$GLOBALS['options']['skduo_dp_token']='skduo:v1:broken';
check(skduo_dp_token()==='','Corrupt encrypted token cannot become a request credential');
reset_fixture();
$GLOBALS['options']['skduo_dp_settings']['source']='evf';
check(skduo_dp_settings('bvl')['source']==='bvl','Stored settings cannot switch credential source');
reset_fixture();
$GLOBALS['responses']['Season']=['status'=>429,'body'=>'fixture-secret','headers'=>['retry-after'=>'120']];
$r=skduo_dp_get('Season');$count=count($GLOBALS['calls']);
check(is_wp_error($r) && get_transient('skduo_dp_cooldown')===true && $GLOBALS['ttls']['skduo_dp_cooldown']===120,'HTTP 429 honors Retry-After');
check(is_wp_error(skduo_dp_get('Season')) && count($GLOBALS['calls'])===$count,'Cooldown prevents repeat network calls');
check(skduo_dp_retry_seconds('999999')===3600 && skduo_dp_retry_seconds('bad-header')===60,'Untrusted Retry-After is bounded');
check(skduo_dp_retry_seconds(gmdate('D, d M Y H:i:s',time()+180).' GMT')>=179,'HTTP-date Retry-After handled');
$log=skduo_dp_log_history('bvl');
check($log[0]['http']===429 && $log[0]['error']==='dp_http','Failed API request has diagnostic log entry');
check(!str_contains(json_encode($log),'fixture-secret'),'Response body never leaks into log');
reset_fixture();
check(is_wp_error(skduo_dp_get('Season',['token'=>'fixture-secret'])) && !$GLOBALS['calls'],'Unknown query fields rejected before network');
check(is_wp_error(skduo_dp_get('Season',['seasontype'=>['bad']])) && !$GLOBALS['calls'],'Structured query parameter rejected safely');
check(is_wp_error(skduo_dp_get('https://evil.example')) && !$GLOBALS['calls'],'Arbitrary resource URL rejected');
$GLOBALS['responses']['Season']=['status'=>302,'body'=>'secret redirect'];
check(is_wp_error(skduo_dp_get('Season')) && count($GLOBALS['calls'])===1 && $GLOBALS['calls'][0][1]['redirection']===0 && $GLOBALS['calls'][0][1]['sslverify']===true,'Redirect refused and TLS verification explicit');
reset_fixture();
skduo_dp_sync();
$log=skduo_dp_log_history('bvl');
check($log[0]['kind']==='sync' && $log[0]['created']===2,'Import counts appear in history');
check(isset($log[1]['http'],$log[1]['duration_ms'],$log[1]['rows']),'Request metadata logged');
skduo_dp_log('bvl','request',['resource'=>'Season','query'=>['seasontype'=>2,'Authorization'=>'fixture-secret'],'headers'=>['Authorization'=>'fixture-secret'],'body'=>'fixture-secret','message'=>'fixture-secret']);
check(!str_contains(json_encode(skduo_dp_log_history('bvl')),'fixture-secret'),'Logger ignores headers, bodies, messages and unapproved filters');
$GLOBALS['options']['skduo_dp_log']=[['time'=>time()-8*DAY_IN_SECONDS,'kind'=>'sync']];
skduo_dp_log('bvl','sync',['result'=>'ok']);
check(count(skduo_dp_log_history('bvl'))===1,'History expires after seven days');
for($i=0;$i<205;$i++)skduo_dp_log('bvl','sync',['result'=>'ok']);
check(count(skduo_dp_log_history('bvl'))===200,'History bounded to 200 events');
check(skduo_dp_log_history('evf')===[],'Diagnostic history isolated per source');
reset_fixture();
skduo_dp_sync();
$GLOBALS['meta_writes']=0;$GLOBALS['post_writes']=0;
$r=skduo_dp_sync();
check($r['updated']===0 && $r['unchanged']===2,'Unchanged API matches counted without updates');
check($GLOBALS['meta_writes']===0 && $GLOBALS['post_writes']===0,'Unchanged reimport performs no metadata or post writes');
$GLOBALS['posts'][1]['post_title']='Editorial match title';
$GLOBALS['posts'][1]['post_content']='Custom information';
$GLOBALS['posts'][1]['meta_input']['_free_entry']='1';
$GLOBALS['posts'][1]['meta_input']['_ticket_url']='https://tickets.example/manual';
$GLOBALS['posts'][1]['meta_input']['_venue']='Manual hall';
$GLOBALS['posts'][1]['meta_input']['_league']='Manual league';
$GLOBALS['posts'][1]['meta_input']['_match_date']='2026-10-21 18:00:00';
$GLOBALS['responses']['Match?legID=6']['data'][0]['Stadium']='API renamed hall';
$GLOBALS['responses']['Match?legID=6']['data'][0]['MatchDateTime']='2026-10-22T18:00:00';
$GLOBALS['responses']['Match?legID=6']['data'][0]['Final_Home']=3;
$GLOBALS['responses']['Match?legID=6']['data'][0]['Final_Guest']=2;
$GLOBALS['responses']['Match?legID=6']['data'][0]['Finalized']=true;
$r=skduo_dp_sync();$p=$GLOBALS['posts'][1];
check($p['post_title']==='Editorial match title' && $p['post_content']==='Custom information','Manual title and content preserved');
check($p['meta_input']['_free_entry']==='1' && $p['meta_input']['_ticket_url']==='https://tickets.example/manual','Free entry and ticket URL preserved alongside results');
check($p['meta_input']['_venue']==='Manual hall' && $p['meta_input']['_league']==='Manual league' && $p['meta_input']['_match_date']==='2026-10-21 18:00:00','Manual editable fields win over changed API values');
check($p['meta_input']['_home_score']===3 && $p['meta_input']['_away_score']===2 && $p['meta_input']['_status']==='finished','Results still update when editorial fields are protected');
check($r['updated']===1 && $r['unchanged']===1,'Only the changed match is counted as updated');
$GLOBALS['meta_writes']=0;$GLOBALS['post_writes']=0;
check(skduo_dp_sync()['unchanged']===2 && $GLOBALS['meta_writes']===0 && $GLOBALS['post_writes']===0,'Repeated final result causes no writes');
$GLOBALS['posts'][1]['meta_input']['_venue']='';
$GLOBALS['responses']['Match?legID=6']['data'][0]['Stadium']='Yet another API hall';
skduo_dp_sync();
check($GLOBALS['posts'][1]['meta_input']['_venue']==='','Deliberately cleared manual field stays cleared');
reset_fixture();skduo_dp_sync();
$GLOBALS['responses']['Match?legID=6']['data'][0]['Stadium']='API new hall';
skduo_dp_sync();
check($GLOBALS['posts'][1]['meta_input']['_venue']==='API new hall','Unmodified API venue follows API changes');
$GLOBALS['posts'][1]['post_status']='draft';
skduo_dp_sync();
check($GLOBALS['posts'][1]['post_status']==='draft','Manually drafted match is not republished');
reset_fixture();skduo_dp_sync();
unset($GLOBALS['posts'][1]['meta_input']['_skduo_dp_snapshot']);
$GLOBALS['posts'][1]['meta_input']['_venue']='Legacy manual hall';
$GLOBALS['responses']['Match?legID=6']['data'][0]['Stadium']='New API hall';
skduo_dp_sync();
check($GLOBALS['posts'][1]['meta_input']['_venue']==='Legacy manual hall','Legacy imports without baseline preserve nonempty editable fields');
check(skduo_dp_match_url('BVL',29,1617)==='https://bvl-web.dataproject.com/MatchStatistics.aspx?ID=29&mID=1617','BVL public game URL uses competition and internal MatchID');
check(skduo_dp_match_url('EVF',100,3612)==='https://evf-web.dataproject.com/MatchStatistics.aspx?ID=100&mID=3612','EVF public game URL stays on EVF domain');
check(skduo_dp_match_url('unknown',29,1)==='' && skduo_dp_match_url('BVL',0,1)==='','Unknown source and invalid IDs cannot create public links');
reset_fixture();
$GLOBALS['options']['skduo_dp_settings']['code']='BVL';
skduo_dp_sync();
check($GLOBALS['posts'][1]['meta_input']['_dataproject_url']==='https://bvl-web.dataproject.com/MatchStatistics.aspx?ID=3&mID=10','Import saves generated public link');
$GLOBALS['posts'][1]['meta_input']['_dataproject_url']='https://example.org/custom-stats';
skduo_dp_sync();
check($GLOBALS['posts'][1]['meta_input']['_dataproject_url']==='https://example.org/custom-stats','Manual statistics link preserved');
reset_fixture();skduo_dp_sync();
$GLOBALS['posts'][1]['meta_input']['_match_date']='2026-10-20T19:00';
$GLOBALS['responses']['Match?legID=6']['data'][0]['MatchDateTime']='2026-10-22T19:00:00';
skduo_dp_sync();
check($GLOBALS['posts'][1]['meta_input']['_match_date']==='2026-10-22 19:00:00','Equivalent datetime-local representation is not mistaken for an editorial override');
class DP_Test_Redirect extends Exception {}
function current_user_can($cap) { return true; }
function check_admin_referer($action) {}
function wp_unslash($value) { return $value; }
function sanitize_key($value) { return $value; }
function admin_url($url) { return $url; }
function get_current_user_id() { return 1; }
function wp_safe_redirect($url) { throw new DP_Test_Redirect($url); }
require __DIR__ . '/../includes/dataproject-admin.php';
reset_fixture();
$handler=end($GLOBALS['test_actions']['admin_post_skduo_dp_action']);
$_SERVER['REQUEST_METHOD']='POST';
$_POST=['source'=>'bvl','operation'=>'discover'];
try { $handler(); } catch(DP_Test_Redirect $e) {}
check(get_transient('skduo_dp_admin_rate_discover')===true,'Selector discovery gets its own button throttle');
$_POST=['source'=>'bvl','operation'=>'sync'];
try { $handler(); } catch(DP_Test_Redirect $e) {}
check(count($GLOBALS['posts'])===2 && get_option('skduo_dp_last_result')['error']===false,'Sync immediately after discovery imports matches');
check(get_transient('skduo_dp_admin_rate_sync')===true,'Sync retains an independent throttle');
$count=count($GLOBALS['calls']);
try { $handler(); } catch(DP_Test_Redirect $e) {}
check(count($GLOBALS['calls'])===$count,'Repeated sync within 60 seconds makes no API calls');
check(str_contains(get_transient('skduo_dp_notice_1')['message'],'Sama toimingut'),'Repeat-button throttle gives a specific notice');
echo "PASS: $assertions offline assertions\n";
