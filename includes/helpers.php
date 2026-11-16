<?php
/**
 * Shared data helpers for SK DUO content.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mängija (player) position options, grouped for the admin dropdown.
 * The "Personal" group is staff/coaches and renders separately from players on the teams page.
 */
if ( ! function_exists( 'skduo_mangija_position_groups' ) ) {
function skduo_mangija_position_groups(): array {
	return [
		'Mängupositsioon' => [ 'Nurgaründaja', 'Sidemängija', 'Libero', 'Diagonaalründaja', 'Temporündaja' ],
		'Personal'        => [ 'Treener', 'Abitreener', 'Füsioterapeut', 'Manager' ],
	];
}
}

if ( ! function_exists( 'skduo_mangija_staff_positions' ) ) {
function skduo_mangija_staff_positions(): array {
	return skduo_mangija_position_groups()['Personal'];
}
}

if ( ! function_exists( 'skduo_mangija_is_staff_position' ) ) {
function skduo_mangija_is_staff_position( string $position ): bool {
	return in_array( $position, skduo_mangija_staff_positions(), true );
}
}

/**
 * Get next upcoming match.
 * Cached for 5 minutes, busted on match save.
 */
if ( ! function_exists( 'skduo_get_next_match' ) ) {
function skduo_get_next_match(): ?WP_Post {
	$cached = get_transient( 'skduo_next_match' );
	if ( false !== $cached ) {
		return '__none__' === $cached ? null : get_post( (int) $cached );
	}

	$matches = get_posts( [
		'post_type'      => 'mang',
		'posts_per_page' => 1,
		'meta_key'       => '_match_date',
		'orderby'        => 'meta_value',
		'order'          => 'ASC',
		'meta_query'     => [
			[
				'key'     => '_match_date',
				'value'   => current_time( 'mysql' ),
				'compare' => '>=',
				'type'    => 'DATETIME',
			],
		],
	] );

	$next = $matches[0] ?? null;
	set_transient(
		'skduo_next_match',
		$next ? $next->ID : '__none__',
		5 * MINUTE_IN_SECONDS
	);

	return $next;
}
}

/**
 * Get next N upcoming matches.
 */
if ( ! function_exists( 'skduo_get_upcoming_matches' ) ) {
function skduo_get_upcoming_matches( int $limit = 4 ): array {
	$cache_key = 'skduo_upcoming_matches_' . $limit;
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$matches = get_posts( [
		'post_type'      => 'mang',
		'posts_per_page' => $limit,
		'meta_key'       => '_match_date',
		'orderby'        => 'meta_value',
		'order'          => 'ASC',
		'meta_query'     => [
			[
				'key'     => '_match_date',
				'value'   => current_time( 'mysql' ),
				'compare' => '>=',
				'type'    => 'DATETIME',
			],
		],
	] );

	set_transient( $cache_key, $matches, 5 * MINUTE_IN_SECONDS );

	return $matches;
}
}

/**
 * Get all match meta as an object.
 */
if ( ! function_exists( 'skduo_match_data' ) ) {
function skduo_match_data( WP_Post $post ): object {
	$date = (string) get_post_meta( $post->ID, '_match_date', true );
	try {
		$date_ts = $date ? ( new DateTimeImmutable( $date, wp_timezone() ) )->getTimestamp() : false;
	} catch ( Exception $e ) {
		$date_ts = false;
	}
	$imported_home = get_post_meta( $post->ID, '_skduo_dp_is_home', true );
	return (object) [
		'id'         => $post->ID,
		'title'      => get_the_title( $post ),
		'permalink'  => get_permalink( $post ),
		'home'       => (string) get_post_meta( $post->ID, '_home_team', true ),
		'away'       => (string) get_post_meta( $post->ID, '_away_team', true ),
		'date'       => $date,
		'date_ts'    => $date_ts,
		'venue'      => (string) get_post_meta( $post->ID, '_venue', true ),
		'league'     => (string) get_post_meta( $post->ID, '_league', true ),
		'dataproject_url' => (string) get_post_meta( $post->ID, '_dataproject_url', true ),
		'ticket_url' => (string) get_post_meta( $post->ID, '_ticket_url', true ),
		'free_entry' => '1' === get_post_meta( $post->ID, '_free_entry', true ),
		'home_score' => (int) get_post_meta( $post->ID, '_home_score', true ),
		'away_score' => (int) get_post_meta( $post->ID, '_away_score', true ),
		'status'     => (string) get_post_meta( $post->ID, '_status', true ) ?: 'upcoming',
		'is_home'    => '' !== $imported_home ? '1' === $imported_home : (
		               str_contains( strtolower( (string) get_post_meta( $post->ID, '_home_team', true ) ), 'tartu' )
		             || str_contains( strtolower( (string) get_post_meta( $post->ID, '_home_team', true ) ), 'bigbank' )
		             || str_contains( strtolower( (string) get_post_meta( $post->ID, '_home_team', true ) ), 'duo' ) ),
	];
}
}

/**
 * Get teams with their players.
 */
if ( ! function_exists( 'skduo_get_teams_with_players' ) ) {
function skduo_get_teams_with_players(): array {
	$team_posts = get_posts( [
		'post_type'      => 'meeskond',
		'posts_per_page' => -1,
		'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
		'order'          => 'ASC',
	] );

	$teams = array_map( 'skduo_team_data', $team_posts );

	return $teams;
}
}

/**
 * Get all team meta and players as an object.
 */
if ( ! function_exists( 'skduo_team_data' ) ) {
function skduo_team_data( WP_Post $post ): object {
	$players = get_posts( [
		'post_type'      => 'mangija',
		'posts_per_page' => -1,
		'orderby'        => [ 'menu_order' => 'ASC', 'meta_value_num' => 'ASC', 'title' => 'ASC' ],
		'meta_key'       => '_number',
		'order'          => 'ASC',
		'meta_query'     => [
			[
				'key'     => '_team_id',
				'value'   => $post->ID,
				'compare' => '=',
				'type'    => 'NUMERIC',
			],
		],
	] );

	$accent = (string) get_post_meta( $post->ID, '_accent', true );
	$player_data = array_map( 'skduo_player_data', $players );

	$roster = [ 'players' => [], 'staff' => [] ];
	foreach ( $player_data as $player ) {
		$roster[ skduo_mangija_is_staff_position( $player->position ) ? 'staff' : 'players' ][] = $player;
	}

	$season_map = [];

	foreach ( $player_data as $player ) {
		foreach ( $player->seasons as $season ) {
			if ( ! empty( $season['slug'] ) && ! empty( $season['name'] ) ) {
				$season_map[ $season['slug'] ] = $season['name'];
			}
		}
	}

	$season_slugs = array_keys( $season_map );
	usort( $season_slugs, function ( string $a, string $b ) use ( $season_map ): int {
		return strnatcasecmp( $season_map[ $b ], $season_map[ $a ] );
	} );

	$seasons = array_map( function ( string $slug ) use ( $season_map ): array {
		return [
			'slug' => $slug,
			'name' => $season_map[ $slug ],
		];
	}, $season_slugs );

	return (object) [
		'id'        => $post->ID,
		'name'      => get_the_title( $post ),
		'permalink' => get_permalink( $post ),
		'level'     => (string) get_post_meta( $post->ID, '_level', true ),
		'league'    => (string) get_post_meta( $post->ID, '_league', true ),
		'record'    => (string) get_post_meta( $post->ID, '_record', true ),
		'summary'   => (string) get_post_meta( $post->ID, '_summary', true ) ?: wp_strip_all_tags( get_the_excerpt( $post ) ),
		'accent'    => in_array( $accent, [ 'green-deep', 'green-mid', 'lime' ], true ) ? $accent : 'green-deep',
		'seasons'   => $seasons,
		'players'   => $roster['players'],
		'staff'     => $roster['staff'],
	];
}
}

/**
 * Get all player meta as an object.
 */
if ( ! function_exists( 'skduo_player_data' ) ) {
function skduo_player_data( WP_Post $post ): object {
	$name = get_the_title( $post );
	$season_terms = get_the_terms( $post, 'mangija_hooaeg' );
	$seasons = [];
	$season_image_ids = get_post_meta( $post->ID, '_season_images', true );
	$season_image_ids = is_array( $season_image_ids ) ? $season_image_ids : [];
	$season_images = [];

	if ( is_array( $season_terms ) ) {
		foreach ( $season_terms as $season ) {
			$image_id = skduo_get_player_season_image_id( $season_image_ids, $season->slug );
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium_large' ) : '';

			$seasons[] = [
				'slug' => $season->slug,
				'name' => $season->name,
			];

			if ( $image_url ) {
				$season_images[ $season->slug ] = $image_url;
			}
		}
	}

	$default_image = get_the_post_thumbnail_url( $post, 'medium_large' ) ?: '';
	$first_season_image = ! empty( $season_images ) ? reset( $season_images ) : '';

	return (object) [
		'id'          => $post->ID,
		'name'        => $name,
		'permalink'   => get_permalink( $post ),
		'number'      => (string) get_post_meta( $post->ID, '_number', true ),
		'position'    => (string) get_post_meta( $post->ID, '_position', true ),
		'height'      => (string) get_post_meta( $post->ID, '_height', true ),
		'weight'      => (string) get_post_meta( $post->ID, '_weight', true ),
		'spike'       => (string) get_post_meta( $post->ID, '_spike_height', true ),
		'block'       => (string) get_post_meta( $post->ID, '_block_height', true ),
		'year'        => (string) get_post_meta( $post->ID, '_birth_year', true ),
		'profile_url' => (string) get_post_meta( $post->ID, '_profile_url', true ),
		'stats_url'   => (string) get_post_meta( $post->ID, '_stats_url', true ),
		'seasons'     => $seasons,
		'season_slugs' => implode( ' ', array_map( function ( array $season ): string {
			return $season['slug'];
		}, $seasons ) ),
		'season_images' => $season_images,
		'team_id'     => (int) get_post_meta( $post->ID, '_team_id', true ),
		'team'        => (int) get_post_meta( $post->ID, '_team_id', true )
			? get_the_title( (int) get_post_meta( $post->ID, '_team_id', true ) )
			: '',
		'image'       => $default_image ?: $first_season_image,
		'image_large' => get_the_post_thumbnail_url( $post, 'large' ) ?: '',
		'description' => apply_filters( 'the_content', $post->post_content ),
		'initials'    => skduo_initials( $name ),
	];
}
}

if ( ! function_exists( 'skduo_get_player_season_image_id' ) ) {
function skduo_get_player_season_image_id( array $season_images, string $season_slug ): int {
	$keys = array_unique( array_filter( [
		$season_slug,
		sanitize_title( $season_slug ),
		str_replace( '/', '-', $season_slug ),
		str_replace( '-', '', $season_slug ),
	] ) );

	foreach ( $keys as $key ) {
		if ( isset( $season_images[ $key ] ) && absint( $season_images[ $key ] ) ) {
			return absint( $season_images[ $key ] );
		}
	}

	return 0;
}
}

/**
 * Build initials for player cards.
 */
if ( ! function_exists( 'skduo_initials' ) ) {
function skduo_initials( string $name ): string {
	$parts   = preg_split( '/\s+/', trim( $name ) ) ?: [];
	$letters = array_map(
		fn ( string $part ): string => function_exists( 'mb_substr' ) ? mb_substr( $part, 0, 1 ) : substr( $part, 0, 1 ),
		array_filter( $parts )
	);

	return strtoupper( implode( '', $letters ) );
}
}
