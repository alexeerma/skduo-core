<?php
/**
 * Schema.org SportsEvent JSON-LD for matches.
 *
 * Data comes from the match helpers; the theme can enrich the organizer
 * through the `skduo_schema_organizer` filter.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Organizer node shared by all match events.
 */
function skduo_schema_organizer(): array {
	$organizer = [
		'@type' => 'SportsOrganization',
		'name'  => get_bloginfo( 'name' ),
		'url'   => home_url( '/' ),
	];

	return (array) apply_filters( 'skduo_schema_organizer', $organizer );
}

/**
 * Build a SportsEvent node for one match, or null when the match has no usable date.
 */
function skduo_match_event_schema( WP_Post $post ): ?array {
	$match = skduo_match_data( $post );

	if ( '' === $match->date ) {
		return null;
	}

	try {
		$start = new DateTimeImmutable( $match->date, wp_timezone() );
	} catch ( Exception $e ) {
		return null;
	}

	$home = '' !== $match->home ? $match->home : '';
	$away = '' !== $match->away ? $match->away : '';
	$name = ( '' !== $home && '' !== $away ) ? $home . ' vs ' . $away : $match->title;

	$event = [
		'@type'               => 'SportsEvent',
		'name'                => $name,
		'url'                 => $match->permalink,
		'startDate'           => $start->format( 'c' ),
		'eventStatus'         => 'https://schema.org/EventScheduled',
		'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
		'sport'               => 'Volleyball',
		'organizer'           => skduo_schema_organizer(),
	];

	if ( '' !== $match->league ) {
		$event['description'] = $match->league;
	}

	if ( '' !== $match->venue ) {
		$event['location'] = [
			'@type'   => 'Place',
			'name'    => $match->venue,
			'address' => $match->venue,
		];
	}

	if ( '' !== $home ) {
		$event['homeTeam'] = [ '@type' => 'SportsTeam', 'name' => $home ];
	}

	if ( '' !== $away ) {
		$event['awayTeam'] = [ '@type' => 'SportsTeam', 'name' => $away ];
	}

	$image = get_the_post_thumbnail_url( $post, 'large' );
	if ( $image ) {
		$event['image'] = $image;
	}

	if ( $match->free_entry ) {
		$event['isAccessibleForFree'] = true;
	} elseif ( '' !== $match->ticket_url ) {
		$event['offers'] = [
			'@type'        => 'Offer',
			'url'          => $match->ticket_url,
			'availability' => 'https://schema.org/InStock',
		];
	}

	return (array) apply_filters( 'skduo_match_event_schema', $event, $post, $match );
}

/**
 * SportsEvent nodes for the current request: the viewed match on a single match page,
 * upcoming matches on the front page and the calendar page.
 */
function skduo_current_event_schema(): array {
	$posts = [];

	if ( is_singular( 'mang' ) ) {
		$posts = [ get_queried_object() ];
	} elseif ( is_front_page() || is_page( 'kalender' ) || is_post_type_archive( 'mang' ) ) {
		$posts = skduo_get_upcoming_matches( 5 );
	}

	$events = [];
	foreach ( $posts as $post ) {
		if ( $post instanceof WP_Post ) {
			$event = skduo_match_event_schema( $post );
			if ( $event ) {
				$events[] = $event;
			}
		}
	}

	return $events;
}

add_action( 'wp_head', function (): void {
	if ( is_admin() || is_feed() ) {
		return;
	}

	$events = skduo_current_event_schema();
	if ( empty( $events ) ) {
		return;
	}

	$data = 1 === count( $events )
		? array_merge( [ '@context' => 'https://schema.org' ], $events[0] )
		: [ '@context' => 'https://schema.org', '@graph' => $events ];

	$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG );
	if ( $json ) {
		echo '<script type="application/ld+json">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}, 30 );
