<?php
/**
 * SK DUO REST API endpoints.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function (): void {
	register_rest_route( 'skduo/v1', '/next-match', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'skduo_rest_get_next_match',
		'permission_callback' => '__return_true',
	] );

	register_rest_route( 'skduo/v1', '/matches', [
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'skduo_rest_get_matches',
			'permission_callback' => '__return_true',
			'args'                => [
				'limit'    => [
					'default'           => 10,
					'sanitize_callback' => 'absint',
					'validate_callback' => fn ( mixed $value ): bool => absint( $value ) >= 1 && absint( $value ) <= 50,
				],
				'upcoming' => [
					'default'           => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
				],
			],
		],
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'skduo_rest_upsert_match',
			'permission_callback' => 'skduo_rest_can_sync_matches',
			'args'                => skduo_rest_match_args(),
		],
	] );
} );

function skduo_rest_get_next_match(): WP_REST_Response {
	$match = skduo_get_next_match();

	return rest_ensure_response( [
		'data' => $match ? skduo_rest_match_payload( $match ) : null,
	] );
}

function skduo_rest_get_matches( WP_REST_Request $request ): WP_REST_Response {
	$limit    = min( 50, max( 1, absint( $request->get_param( 'limit' ) ?: 10 ) ) );
	$upcoming = rest_sanitize_boolean( $request->get_param( 'upcoming' ) );

	$args = [
		'post_type'      => 'mang',
		'post_status'    => 'publish',
		'posts_per_page' => $limit,
		'meta_key'       => '_match_date',
		'orderby'        => 'meta_value',
		'order'          => 'ASC',
	];

	if ( $upcoming ) {
		$args['meta_query'] = [
			[
				'key'     => '_match_date',
				'value'   => current_time( 'mysql' ),
				'compare' => '>=',
				'type'    => 'DATETIME',
			],
		];
	}

	$matches = get_posts( $args );

	return rest_ensure_response( [
		'data' => array_map( 'skduo_rest_match_payload', $matches ),
	] );
}

function skduo_rest_can_sync_matches( WP_REST_Request $request ): bool|WP_Error {
	$match_id = absint( $request->get_param( 'id' ) );

	if ( $match_id ) {
		return current_user_can( 'edit_post', $match_id )
			? true
			: new WP_Error( 'skduo_forbidden', __( 'You are not allowed to update this match.', 'skduo' ), [ 'status' => 403 ] );
	}

	return current_user_can( 'edit_posts' )
		? true
		: new WP_Error( 'skduo_forbidden', __( 'You are not allowed to create matches.', 'skduo' ), [ 'status' => 403 ] );
}

function skduo_rest_match_args(): array {
	return [
		'id'         => [
			'sanitize_callback' => 'absint',
		],
		'title'      => [
			'required'          => false,
			'sanitize_callback' => 'sanitize_text_field',
		],
		'home'       => [
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
		],
		'away'       => [
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
		],
		'date'       => [
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => fn ( mixed $value ): bool => skduo_rest_normalize_match_date( (string) $value ) !== '',
		],
		'venue'      => [
			'sanitize_callback' => 'sanitize_text_field',
		],
		'league'     => [
			'sanitize_callback' => 'sanitize_text_field',
		],
		'ticket_url' => [
			'sanitize_callback' => 'esc_url_raw',
		],
		'home_score' => [
			'sanitize_callback' => 'absint',
		],
		'away_score' => [
			'sanitize_callback' => 'absint',
		],
		'status'     => [
			'default'           => 'upcoming',
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => fn ( mixed $value ): bool => in_array( (string) $value, [ 'upcoming', 'live', 'finished' ], true ),
		],
	];
}

function skduo_rest_upsert_match( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$match_id = absint( $request->get_param( 'id' ) );
	$date     = skduo_rest_normalize_match_date( (string) $request->get_param( 'date' ) );

	if ( '' === $date ) {
		return new WP_Error( 'skduo_invalid_date', __( 'Invalid match date.', 'skduo' ), [ 'status' => 400 ] );
	}

	$title = trim( (string) $request->get_param( 'title' ) );
	if ( '' === $title ) {
		$title = sprintf(
			'%s vs %s',
			(string) $request->get_param( 'home' ),
			(string) $request->get_param( 'away' )
		);
	}

	$post_data = [
		'post_type'   => 'mang',
		'post_title'  => $title,
		'post_status' => 'publish',
	];

	if ( $match_id ) {
		$post_data['ID'] = $match_id;
		$result          = wp_update_post( wp_slash( $post_data ), true );
	} else {
		$result = wp_insert_post( wp_slash( $post_data ), true );
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$match_id = (int) $result;
	$fields   = [
		'home_team'  => (string) $request->get_param( 'home' ),
		'away_team'  => (string) $request->get_param( 'away' ),
		'match_date' => $date,
		'venue'      => (string) $request->get_param( 'venue' ),
		'league'     => (string) $request->get_param( 'league' ),
		'ticket_url' => (string) $request->get_param( 'ticket_url' ),
		'home_score' => absint( $request->get_param( 'home_score' ) ),
		'away_score' => absint( $request->get_param( 'away_score' ) ),
		'status'     => (string) ( $request->get_param( 'status' ) ?: 'upcoming' ),
	];

	foreach ( $fields as $key => $value ) {
		update_post_meta( $match_id, '_' . $key, $value );
	}

	skduo_rest_bust_match_cache();

	return new WP_REST_Response( [
		'data' => skduo_rest_match_payload( get_post( $match_id ) ),
	], $request->get_param( 'id' ) ? 200 : 201 );
}

function skduo_rest_match_payload( ?WP_Post $post ): ?array {
	if ( ! $post ) {
		return null;
	}

	$match = skduo_match_data( $post );

	return [
		'id'         => $match->id,
		'title'      => $match->title,
		'permalink'  => $match->permalink,
		'home'       => $match->home,
		'away'       => $match->away,
		'date'       => $match->date,
		'date_ts'    => $match->date_ts,
		'venue'      => $match->venue,
		'league'     => $match->league,
		'ticket_url' => $match->ticket_url,
		'free_entry' => $match->free_entry,
		'dataproject_url' => $match->dataproject_url,
		'home_score' => $match->home_score,
		'away_score' => $match->away_score,
		'status'     => $match->status,
		'is_home'    => $match->is_home,
	];
}

function skduo_rest_normalize_match_date( string $date ): string {
	$date = trim( $date );
	if ( '' === $date ) {
		return '';
	}

	$timestamp = strtotime( $date );
	if ( false === $timestamp ) {
		return '';
	}

	return wp_date( 'Y-m-d H:i:s', $timestamp );
}

function skduo_rest_bust_match_cache(): void {
	delete_transient( 'skduo_next_match' );

	for ( $limit = 1; $limit <= 50; $limit++ ) {
		delete_transient( 'skduo_upcoming_matches_' . $limit );
	}
}
