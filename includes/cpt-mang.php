<?php
/**
 * Match CPT (mang) and its meta box.
 *
 * Loaded by the SK DUO Core plugin.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	register_post_type(
		'mang',
		[
			'labels' => [
				'name'          => __( 'Mängud', 'skduo' ),
				'singular_name' => __( 'Mäng', 'skduo' ),
				'add_new_item'  => __( 'Lisa mäng', 'skduo' ),
				'edit_item'     => __( 'Muuda mängu', 'skduo' ),
				'menu_name'     => __( 'Mängud', 'skduo' ),
			],
			'public'       => true,
			'has_archive'  => 'kalender',
			'rewrite'      => [ 'slug' => 'mang' ],
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-calendar-alt',
			'menu_position' => 5,
			'supports'     => [ 'title', 'editor', 'thumbnail' ],
		]
	);
} );

add_action( 'add_meta_boxes', function () {
	add_meta_box(
		'skduo_match_details',
		__( 'Mängu info', 'skduo' ),
		'skduo_render_match_meta_box',
		'mang',
		'normal',
		'high'
	);
} );

function skduo_render_match_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'skduo_match_meta', 'skduo_match_nonce' );

	$fields = [
		'home_team'    => [ 'Kodumeeskond',  'text' ],
		'away_team'    => [ 'Külalismeeskond', 'text' ],
		'match_date'   => [ 'Kuupäev ja kellaaeg', 'datetime-local' ],
		'venue'        => [ 'Mängu koht', 'text' ],
		'league'       => [ 'Liiga / kategooria', 'text' ],
		'dataproject_url' => [ 'DataProjecti mängu statistika link', 'url' ],
		'ticket_url'   => [ 'Pileti link', 'url' ],
		'home_score'   => [ 'Kodumeeskonna skoor', 'number' ],
		'away_score'   => [ 'Külalismeeskonna skoor', 'number' ],
		'status'       => [ 'Olek (upcoming / live / finished)', 'text' ],
	];

	echo '<table class="form-table"><tbody>';
	foreach ( $fields as $key => [ $label, $type ] ) {
		$value = get_post_meta( $post->ID, '_' . $key, true );
		// Free entry checkbox sits next to the ticket link: when checked, no ticket CTA is shown.
		$extra = 'ticket_url' === $key
			? sprintf(
				'<p><label><input type="checkbox" name="skduo_free_entry" value="1" %s /> %s</label></p>',
				checked( '1', get_post_meta( $post->ID, '_free_entry', true ), false ),
				esc_html__( 'Tasuta sissepääs (pileti nuppu ei kuvata)', 'skduo' )
			)
			: '';
		printf(
			'<tr><th><label for="skduo_%1$s">%2$s</label></th><td><input type="%3$s" id="skduo_%1$s" name="skduo_%1$s" value="%4$s" class="regular-text" />%5$s</td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( (string) $value ),
			$extra
		);
	}
	echo '</tbody></table>';
}

add_action( 'save_post_mang', function ( int $post_id ): void {
	if ( ! isset( $_POST['skduo_match_nonce'] ) || ! wp_verify_nonce( $_POST['skduo_match_nonce'], 'skduo_match_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$keys = [ 'home_team', 'away_team', 'match_date', 'venue', 'league', 'ticket_url', 'dataproject_url', 'home_score', 'away_score', 'status' ];
	foreach ( $keys as $key ) {
		if ( ! isset( $_POST[ 'skduo_' . $key ] ) ) {
			continue;
		}
		$raw = $_POST[ 'skduo_' . $key ];
		$value = match ( $key ) {
			'ticket_url', 'dataproject_url' => esc_url_raw( $raw ),
			'home_score', 'away_score' => absint( $raw ),
			default                    => sanitize_text_field( $raw ),
		};
		update_post_meta( $post_id, '_' . $key, $value );
	}

	// Unchecked checkboxes are not posted, so absence means "not free".
	update_post_meta( $post_id, '_free_entry', empty( $_POST['skduo_free_entry'] ) ? '' : '1' );

	// Bust match listing transients.
	delete_transient( 'skduo_next_match' );
	delete_transient( 'skduo_upcoming_matches_4' );
} );
