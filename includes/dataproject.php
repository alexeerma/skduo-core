<?php
/**
 * Read-only DataProject API client and match importer.
 * Contract: DocWebApi_ENG.pdf, Season -> Competition -> Phase -> Championship -> Leg -> Match.
 */

defined( 'ABSPATH' ) || exit;

function skduo_dp_source( string $source ): string {
	return 'evf' === $source ? 'evf' : 'bvl';
}

function skduo_dp_option( string $name, string $source = 'bvl' ): string {
	return 'skduo_dp_' . $name . ( 'evf' === skduo_dp_source( $source ) ? '_evf' : '' );
}

function skduo_dp_token_constant( string $source ): string {
	return 'evf' === skduo_dp_source( $source ) ? 'SKDUO_DATAPROJECT_EVF_TOKEN' : 'SKDUO_DATAPROJECT_TOKEN';
}

function skduo_dp_settings( string $source = 'bvl' ): array {
	return array_merge( [
		'code' => '', 'season_id' => 0, 'competition_id' => 0, 'team_id' => 0,
		'timezone' => 'Europe/Tallinn', 'automatic' => false, 'source' => skduo_dp_source( $source ),
	], (array) get_option( skduo_dp_option( 'settings', $source ), [] ), [ 'source' => skduo_dp_source( $source ) ] );
}

function skduo_dp_token( string $source = 'bvl' ): string {
	$name = skduo_dp_token_constant( $source );
	if ( defined( $name ) ) {
		$token = trim( (string) constant( $name ) );
		return skduo_dp_valid_token( $token ) ? $token : '';
	}
	$stored = (string) get_option( skduo_dp_option( 'token', $source ), '' );
	if ( '' === $stored ) { return ''; }
	if ( str_starts_with( $stored, 'skduo:' ) ) {
		return skduo_dp_decrypt_token( $stored, $source );
	}
	// Migrate existing plaintext credentials without changing the API token.
	$token = trim( $stored );
	return is_wp_error( skduo_dp_save_token( $token, $source ) ) ? '' : $token;
}

/** Never forward the Bearer token to a redirect or include response bodies in errors. */
function skduo_dp_get( string $resource, array $query = [], ?array $settings = null ): array|WP_Error {
	$settings ??= skduo_dp_settings();
	$token = skduo_dp_token( $settings['source'] ?? 'bvl' );
	if ( ! is_string( $settings['code'] ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,32}$/D', $settings['code'] ) || '' === $token ) {
		return new WP_Error( 'dp_credentials', 'Sisesta organisatsioonikood ja Bearer token. Kasutajanimi ei asenda token’it.' );
	}
	if ( ! preg_match( '#^(Season|Team|Competition|Phase|Championship|Leg|Match|Stadium)(/[1-9][0-9]*)?$#D', $resource ) ) {
		return new WP_Error( 'dp_resource', 'Lubamatu API ressurss.' );
	}
	$source = $settings['source'] ?? 'bvl';
	$allowed_filters = [ 'Season' => [ 'seasontype' ], 'Team' => [ 'seasonID' ], 'Competition' => [ 'seasonID' ], 'Phase' => [ 'competitionID' ], 'Championship' => [ 'phaseID' ], 'Leg' => [ 'championshipID' ], 'Match' => [ 'legID' ] ];
	foreach ( $query as $key => $value ) {
		if ( ! in_array( $key, $allowed_filters[ $resource ] ?? [], true ) || ! skduo_dp_positive_id( $value ) ) {
			return new WP_Error( 'dp_query', 'API päringu filter on vigane.' );
		}
	}
	if ( get_transient( skduo_dp_option( 'cooldown', $source ) ) ) {
		return new WP_Error( 'dp_cooldown', 'API päringupiirang: oota enne uuesti proovimist.' );
	}
	$url = 'https://dataprojectserviceswebapi.azurewebsites.net/v1/VO/' . rawurlencode( $settings['code'] ) . '/' . $resource;
	$started = microtime( true );
	$response = wp_safe_remote_get( add_query_arg( $query, $url ), [
		'timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 4 * MB_IN_BYTES,
		'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ],
	] );
	$details = [ 'resource' => $resource, 'query' => $query, 'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ) ];
	if ( is_wp_error( $response ) ) {
		skduo_dp_log( $source, 'request', $details + [ 'result' => 'error', 'error' => 'dp_network' ] );
		return new WP_Error( 'dp_network', 'DataProjecti ühendus ebaõnnestus. Kontrolli võrguühendust ja proovi uuesti.' );
	}
	$status = wp_remote_retrieve_response_code( $response );
	$details['http'] = $status;
	if ( 200 !== $status ) {
		if ( 429 === $status ) {
			$seconds = skduo_dp_retry_seconds( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
			set_transient( skduo_dp_option( 'cooldown', $source ), true, $seconds );
		}
		skduo_dp_log( $source, 'request', $details + [ 'result' => 'error', 'error' => 'dp_http' ] );
		$message = 401 === $status || 403 === $status
			? 'Token või organisatsioonikood ei sobi või ligipääs sellele organisatsioonile puudub.'
			: 'DataProject ei tagastanud oodatud vastust.';
		return new WP_Error( 'dp_http', $message . ' HTTP ' . $status . ' (' . $resource . ').' );
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
		skduo_dp_log( $source, 'request', $details + [ 'result' => 'error', 'error' => 'dp_json' ] );
		return new WP_Error( 'dp_json', 'DataProjecti vastus ei ole oodatud JSON (' . $resource . ').' );
	}
	skduo_dp_log( $source, 'request', $details + [ 'result' => 'ok', 'rows' => array_is_list( $data ) ? count( $data ) : 1 ] );
	return $data;
}

/** Documented list endpoints return JSON arrays, not undocumented envelopes. */
function skduo_dp_list( string $resource, array $query, string $id_key, ?array $settings = null ): array|WP_Error {
	$rows = skduo_dp_get( $resource, $query, $settings );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	if ( ! array_is_list( $rows ) ) {
		return new WP_Error( 'dp_shape', 'API loendi vorming on ootamatu (' . $resource . ').' );
	}
	$seen = [];
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || ! skduo_dp_positive_id( $row[ $id_key ] ?? null ) || isset( $seen[ $row[ $id_key ] ] ) ) {
			return new WP_Error( 'dp_shape', 'API loendis on puuduv, vigane või korduv ID (' . $resource . ').' );
		}
		$seen[ $row[ $id_key ] ] = true;
	}
	return $rows;
}

function skduo_dp_positive_id( mixed $value ): bool {
	return ( is_int( $value ) || is_string( $value ) ) && preg_match( '/^[1-9][0-9]*$/D', (string) $value );
}

/** Build the whole import before writing; failed later rounds cannot cause a partial API import. */
function skduo_dp_collect( array $settings ): array|WP_Error {
	foreach ( [ 'season_id', 'competition_id', 'team_id' ] as $key ) {
		if ( ! skduo_dp_positive_id( $settings[ $key ] ) ) {
			return new WP_Error( 'dp_selection', 'Vali hooaeg, võistlus ja meeskond.' );
		}
	}
	$competition = skduo_dp_get( 'Competition/' . $settings['competition_id'], [], $settings );
	$team = skduo_dp_get( 'Team/' . $settings['team_id'], [], $settings );
	foreach ( [ $competition, $team ] as $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( (int) ( $result['SeasonID'] ?? 0 ) !== (int) $settings['season_id'] ) {
			return new WP_Error( 'dp_scope', 'Valitud meeskond või võistlus ei kuulu valitud hooaega.' );
		}
	}
	if ( (int) ( $competition['CompetitionID'] ?? 0 ) !== (int) $settings['competition_id'] || (int) ( $team['TeamID'] ?? 0 ) !== (int) $settings['team_id'] ) {
		return new WP_Error( 'dp_scope', 'API tagastas teise meeskonna või võistluse.' );
	}
	$phases = skduo_dp_list( 'Phase', [ 'competitionID' => $settings['competition_id'] ], 'PhaseID', $settings );
	if ( is_wp_error( $phases ) ) {
		return $phases;
	}
	$matches = [];
	$stadiums = [];
	foreach ( $phases as $phase ) {
		if ( (int) ( $phase['CompetitionID'] ?? 0 ) !== (int) $settings['competition_id'] ) {
			return new WP_Error( 'dp_scope', 'API tagastas teise võistluse faasi.' );
		}
		$championships = skduo_dp_list( 'Championship', [ 'phaseID' => $phase['PhaseID'] ], 'ChampionshipID', $settings );
		if ( is_wp_error( $championships ) ) {
			return $championships;
		}
		foreach ( $championships as $championship ) {
			if ( (int) ( $championship['CompetitionPhaseID'] ?? 0 ) !== (int) $phase['PhaseID'] ) {
				return new WP_Error( 'dp_scope', 'API tagastas teise faasi alagrupi.' );
			}
			$legs = skduo_dp_list( 'Leg', [ 'championshipID' => $championship['ChampionshipID'] ], 'LegID', $settings );
			if ( is_wp_error( $legs ) ) {
				return $legs;
			}
			foreach ( $legs as $leg ) {
				if ( (int) ( $leg['ChampionshipID'] ?? 0 ) !== (int) $championship['ChampionshipID'] ) {
					return new WP_Error( 'dp_scope', 'API tagastas teise alagrupi vooru.' );
				}
				$rows = skduo_dp_list( 'Match', [ 'legID' => $leg['LegID'] ], 'MatchID', $settings );
				if ( is_wp_error( $rows ) ) {
					return $rows;
				}
				foreach ( $rows as $row ) {
					if ( (int) ( $row['CompetitionID'] ?? 0 ) !== (int) $settings['competition_id'] || (int) ( $row['SeasonID'] ?? 0 ) !== (int) $settings['season_id'] || (int) ( $row['ChampionshipLegID'] ?? 0 ) !== (int) $leg['LegID'] || (int) ( $row['ChampionshipID'] ?? 0 ) !== (int) $championship['ChampionshipID'] ) {
						return new WP_Error( 'dp_scope', 'API tagastas teise hooaja, võistluse või vooru mängu.' );
					}
					if ( (int) ( $row['HomeTeamID'] ?? 0 ) !== (int) $settings['team_id'] && (int) ( $row['GuestTeamID'] ?? 0 ) !== (int) $settings['team_id'] ) {
						continue;
					}
					if ( empty( $row['Stadium'] ) && skduo_dp_positive_id( $row['StadiumID'] ?? null ) ) {
						$stadium_id = (int) $row['StadiumID'];
						if ( ! isset( $stadiums[ $stadium_id ] ) ) {
							$stadium = skduo_dp_get( 'Stadium/' . $stadium_id, [], $settings );
							if ( is_wp_error( $stadium ) ) {
								return $stadium;
							}
							if ( (int) ( $stadium['StadiumID'] ?? 0 ) !== $stadium_id || ! is_string( $stadium['StadiumName'] ?? null ) ) {
								return new WP_Error( 'dp_stadium', 'API saali andmed on vigased.' );
							}
							$stadiums[ $stadium_id ] = $stadium['StadiumName'];
						}
						$row['Stadium'] = $stadiums[ $stadium_id ];
					}
					$match = skduo_dp_normalize_match( $row, $settings, (string) ( $competition['Description'] ?? '' ) );
					if ( is_wp_error( $match ) ) {
						return $match;
					}
					if ( isset( $matches[ $row['MatchID'] ] ) ) {
						return new WP_Error( 'dp_duplicate', 'Sama mäng esineb mitmes voorus. Import peatati.' );
					}
					$matches[ $row['MatchID'] ] = $match;
				}
			}
		}
	}
	return array_values( $matches );
}

function skduo_dp_normalize_match( array $row, array $settings, string $league ): array|WP_Error {
	foreach ( [ 'MatchID', 'HomeTeamID', 'GuestTeamID' ] as $key ) {
		if ( ! skduo_dp_positive_id( $row[ $key ] ?? null ) ) {
			return new WP_Error( 'dp_match', 'Mängu ID või meeskonna ID on vigane.' );
		}
	}
	if ( $row['HomeTeamID'] == $row['GuestTeamID'] || ! is_string( $row['HomeTeam'] ?? null ) || ! is_string( $row['GuestTeam'] ?? null ) || '' === trim( $row['HomeTeam'] ) || '' === trim( $row['GuestTeam'] ) ) {
		return new WP_Error( 'dp_match', 'Mängu meeskondade andmed on vigased.' );
	}
	if ( ! is_bool( $row['Finalized'] ?? null ) ) {
		return new WP_Error( 'dp_match', 'Mängu lõppolek on puudu või vigane.' );
	}
	$date = '';
	$raw_date = $row['MatchDateTime'] ?? null;
	if ( null !== $raw_date && '' !== $raw_date ) {
		if ( ! is_string( $raw_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})?$/D', $raw_date ) ) {
			return new WP_Error( 'dp_date', 'Mängu kuupäeva vorming on ootamatu.' );
		}
		try {
			$dt = new DateTimeImmutable( $raw_date, new DateTimeZone( $settings['timezone'] ) );
			$errors = DateTimeImmutable::getLastErrors();
			if ( false !== $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) {
				return new WP_Error( 'dp_date', 'Mängu kuupäev on vigane.' );
			}
			if ( (int) $dt->format( 'Y' ) < 2000 ) {
				return new WP_Error( 'dp_date', 'Mängu kuupäev vajab kontrollimist.' );
			}
			$date = $dt->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return new WP_Error( 'dp_date', 'Kuupäeva või lähteandmete ajavööndi lugemine ebaõnnestus.' );
		}
	}
	$scores = [];
	foreach ( [ 'Final_Home', 'Final_Guest' ] as $key ) {
		$value = $row[ $key ] ?? null;
		if ( ! ( is_int( $value ) || is_string( $value ) ) || ! preg_match( '/^[0-9]+$/D', (string) $value ) || (int) $value > 5 ) {
			return new WP_Error( 'dp_score', 'Mängu geimide skoor on puudu või vigane.' );
		}
		$scores[] = (int) $value;
	}
	return [
		'source_key' => $settings['code'] . ':' . $row['MatchID'],
		'title' => sanitize_text_field( $row['HomeTeam'] . ' vs ' . $row['GuestTeam'] ),
		'meta' => [
			'_home_team' => sanitize_text_field( $row['HomeTeam'] ),
			'_away_team' => sanitize_text_field( $row['GuestTeam'] ),
			'_match_date' => $date, '_venue' => sanitize_text_field( (string) ( $row['Stadium'] ?? '' ) ),
			'_league' => sanitize_text_field( $league ), '_home_score' => $scores[0], '_away_score' => $scores[1],
			'_status' => $row['Finalized'] ? 'finished' : 'upcoming',
			'_dataproject_url' => skduo_dp_match_url( $settings['code'], (int) $settings['competition_id'], (int) $row['MatchID'] ),
			'_skduo_dp_is_home' => (int) $row['HomeTeamID'] === (int) $settings['team_id'] ? '1' : '0',
			'_skduo_dp_source' => $settings['code'] . ':' . $row['MatchID'],
			'_skduo_dp_season_id' => (int) $settings['season_id'],
			'_skduo_dp_competition_id' => (int) $settings['competition_id'],
			'_skduo_dp_team_id' => (int) $settings['team_id'],
			'_skduo_dp_status' => sanitize_text_field( (string) ( $row['Status'] ?? '' ) ),
			'_skduo_dp_date_note' => sanitize_text_field( (string) ( $row['MatchDateTimeEmpty'] ?? '' ) ),
		],
	];
}

/** Public statistics page; API credentials never form part of this URL. */
function skduo_dp_match_url( string $code, int $competition_id, int $match_id ): string {
	$host = [ 'BVL' => 'bvl-web.dataproject.com', 'EVF' => 'evf-web.dataproject.com' ][ strtoupper( $code ) ] ?? '';
	return $host && $competition_id > 0 && $match_id > 0
		? 'https://' . $host . '/MatchStatistics.aspx?ID=' . $competition_id . '&mID=' . $match_id : '';
}

function skduo_dp_compare_value( string $key, mixed $value ): string {
	$value = (string) $value;
	if ( '_match_date' === $key && preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?$/D', $value ) ) {
		$value = str_replace( 'T', ' ', $value );
		if ( 16 === strlen( $value ) ) { $value .= ':00'; }
	}
	return $value;
}

/** Three-way merge: only replace editable fields still equal to the previous API value. */
function skduo_dp_import_changes( int $id, array $match ): array {
	$incoming = $match['meta'];
	$snapshot = [];
	foreach ( $incoming as $key => $value ) { $snapshot[ $key ] = skduo_dp_compare_value( $key, $value ); }
	$previous = $id ? get_post_meta( $id, '_skduo_dp_snapshot', true ) : [];
	$previous = is_array( $previous ) ? $previous : [];
	$editable = [ '_home_team', '_away_team', '_match_date', '_venue', '_league', '_dataproject_url' ];
	$changes = [];
	foreach ( $incoming as $key => $value ) {
		$current = $id ? get_post_meta( $id, $key, true ) : '';
		if ( $id && in_array( $key, $editable, true ) && '' !== (string) $current ) {
			if ( ! array_key_exists( $key, $previous ) || skduo_dp_compare_value( $key, $current ) !== skduo_dp_compare_value( $key, $previous[ $key ] ) ) {
				continue;
			}
		}
		// A deliberately cleared field also counts as a manual edit after a recorded API value.
		if ( $id && in_array( $key, $editable, true ) && array_key_exists( $key, $previous ) && skduo_dp_compare_value( $key, $current ) !== skduo_dp_compare_value( $key, $previous[ $key ] ) ) {
			continue;
		}
		if ( ! $id || skduo_dp_compare_value( $key, $current ) !== skduo_dp_compare_value( $key, $value ) ) {
			$changes[ $key ] = $value;
		}
	}
	$date = array_key_exists( '_match_date', $changes ) ? $changes['_match_date'] : ( $id ? get_post_meta( $id, '_match_date', true ) : $incoming['_match_date'] );
	$status = $id ? get_post_status( $id ) : 'draft';
	$auto_draft = $id ? get_post_meta( $id, '_skduo_dp_auto_draft', true ) : '';
	$desired = ! $id || '1' === $auto_draft ? ( $date ? 'publish' : 'draft' ) : $status;
	if ( ! $date && 'publish' === $status ) { $desired = 'draft'; }
	$next_auto_draft = ! $date && ( ! $id || 'publish' === $status || '1' === $auto_draft ) ? '1' : '';
	if ( (string) $auto_draft !== $next_auto_draft ) { $changes['_skduo_dp_auto_draft'] = $next_auto_draft; }
	$visible_changes = ! empty( $changes ) || $status !== $desired;
	if ( $previous !== $snapshot ) { $changes['_skduo_dp_snapshot'] = $snapshot; }
	$post = [ 'ID' => $id, 'meta_input' => $changes ];
	if ( ! $id ) {
		$post += [ 'post_type' => 'mang', 'post_title' => $match['title'], 'post_status' => $desired ];
	} elseif ( $status !== $desired ) {
		$post['post_status'] = $desired;
	}
	return [ 'post' => $post, 'changed' => $visible_changes, 'undated' => ! $date ];
}

/** Atomic lock prevents cron and manual runs from creating duplicates. */
function skduo_dp_sync( string $source = 'bvl' ): array|WP_Error {
	if ( ! add_option( 'skduo_dp_lock', time(), '', false ) ) {
		return new WP_Error( 'dp_locked', 'Sünkroonimine juba käib. Kui varasem töö katkes, vabasta lukk halduses.' );
	}
	try {
		$matches = skduo_dp_collect( skduo_dp_settings( $source ) );
		if ( is_wp_error( $matches ) ) {
			skduo_dp_record_result( $matches, $source );
			return $matches;
		}
		// Preflight local source IDs as well, before any writes.
		$plan = [];
		foreach ( $matches as $match ) {
			$ids = get_posts( [
				'post_type' => 'mang', 'post_status' => array_keys( get_post_stati() ),
				'posts_per_page' => 2, 'fields' => 'ids',
				'meta_key' => '_skduo_dp_source', 'meta_value' => $match['source_key'],
			] );
			if ( count( $ids ) > 1 ) {
				$error = new WP_Error( 'dp_local_duplicate', 'Sama API mänguga on seotud mitu kohalikku mängu. Paranda duplikaadid enne importi.' );
				skduo_dp_record_result( $error, $source );
				return $error;
			}
			$plan[] = [ 'id' => (int) ( $ids[0] ?? 0 ), 'match' => $match ];
		}
		$counts = [ 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'undated' => 0, 'trashed' => 0 ];
		foreach ( $plan as $item ) {
			$id = $item['id'];
			$match = $item['match'];
			if ( $id && 'trash' === get_post_status( $id ) ) {
				++$counts['trashed'];
				continue;
			}
			$change = skduo_dp_import_changes( $id, $match );
			$post = $change['post'];
			if ( $change['undated'] ) { ++$counts['undated']; }
			if ( ! $id ) {
				$result = wp_insert_post( wp_slash( $post ), true );
			} else {
				// Metadata-only updates must not rewrite the title, content or modified date.
				$result = isset( $post['post_status'] )
					? wp_update_post( [ 'ID' => $id, 'post_status' => $post['post_status'] ], true ) : $id;
				if ( ! is_wp_error( $result ) ) {
					foreach ( $post['meta_input'] as $key => $value ) {
						if ( false === update_post_meta( $id, $key, wp_slash( $value ) ) && get_post_meta( $id, $key, true ) != $value ) {
							$result = new WP_Error( 'dp_write', 'Metaandmete salvestamine ebaõnnestus.' );
							break;
						}
					}
				}
			}
			if ( is_wp_error( $result ) ) {
				$error = new WP_Error( 'dp_write', 'Mängu salvestamine ebaõnnestus. Osa mängudest võib olla salvestatud; korduv sünkroonimine jätkab samu ID-sid kasutades.' );
				skduo_dp_record_result( $error, $source );
				return $error;
			}
			++$counts[ ! $id ? 'created' : ( $change['changed'] ? 'updated' : 'unchanged' ) ];
		}
		skduo_dp_record_result( $counts, $source );
		return $counts;
	} catch ( Throwable $e ) {
		$error = new WP_Error( 'dp_exception', 'Sünkroonimine katkes ootamatu vea tõttu. Kontrolli serveri olekut ja proovi uuesti.' );
		skduo_dp_record_result( $error, $source );
		return $error;
	} finally {
		skduo_rest_bust_match_cache();
		delete_option( 'skduo_dp_lock' );
	}
}

function skduo_dp_record_result( array|WP_Error $result, string $source = 'bvl' ): void {
	skduo_dp_log( $source, 'sync', is_wp_error( $result ) ? [ 'result' => 'error', 'error' => $result->get_error_code() ] : $result + [ 'result' => 'ok' ] );
	update_option( skduo_dp_option( 'last_result', $source ), [
		'time' => time(), 'error' => is_wp_error( $result ),
		'message' => is_wp_error( $result ) ? $result->get_error_message() : sprintf(
			'Lisatud: %d. Uuendatud: %d. Muutumatud: %d. Kuupäevata mustandid: %d. Prügikastis, vahele jäetud: %d.',
			$result['created'], $result['updated'], $result['unchanged'] ?? 0, $result['undated'], $result['trashed']
		),
	], false );
}

function skduo_dp_connection_enabled( string $source ): bool {
	$s = skduo_dp_settings( $source );
	return $s['automatic'] && $s['code'] && skduo_dp_token( $source ) && $s['season_id'] && $s['competition_id'] && $s['team_id'];
}

function skduo_dp_sync_enabled_sources(): void {
	foreach ( [ 'bvl', 'evf' ] as $source ) {
		if ( skduo_dp_connection_enabled( $source ) ) {
			skduo_dp_sync( $source );
		}
	}
}

add_action( 'skduo_dp_hourly_sync', 'skduo_dp_sync_enabled_sources' );
add_action( 'init', function (): void {
	$enabled = skduo_dp_connection_enabled( 'bvl' ) || skduo_dp_connection_enabled( 'evf' );
	if ( $enabled && ! wp_next_scheduled( 'skduo_dp_hourly_sync' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'skduo_dp_hourly_sync' );
	} elseif ( ! $enabled && wp_next_scheduled( 'skduo_dp_hourly_sync' ) ) {
		wp_clear_scheduled_hook( 'skduo_dp_hourly_sync' );
	}
} );
