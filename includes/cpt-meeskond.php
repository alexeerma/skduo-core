<?php
/**
 * Team CPT (meeskond) and its meta box.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	register_post_type(
		'meeskond',
		[
			'labels' => [
				'name'          => __( 'Meeskonnad', 'skduo' ),
				'singular_name' => __( 'Meeskond', 'skduo' ),
				'add_new_item'  => __( 'Lisa meeskond', 'skduo' ),
				'edit_item'     => __( 'Muuda meeskonda', 'skduo' ),
				'menu_name'     => __( 'Meeskonnad', 'skduo' ),
			],
			'public'        => true,
			'has_archive'   => false,
			'rewrite'       => [ 'slug' => 'meeskond' ],
			'show_in_rest'  => true,
			'menu_icon'     => 'dashicons-groups',
			'menu_position' => 6,
			'supports'      => [ 'title', 'editor', 'thumbnail', 'page-attributes' ],
		]
	);
} );

add_action( 'add_meta_boxes', function () {
	add_meta_box(
		'skduo_team_details',
		__( 'Meeskonna info', 'skduo' ),
		'skduo_render_team_meta_box',
		'meeskond',
		'normal',
		'high'
	);
} );

function skduo_render_team_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'skduo_team_meta', 'skduo_team_nonce' );

	$fields = [
		'level'   => [ 'Tase', 'text', 'Näiteks: Esindusmeeskond' ],
		'league'  => [ 'Liiga', 'text', 'Näiteks: Cronimet Liiga' ],
		'record'  => [ 'Hooaja märge', 'text', 'Näiteks: Hooaeg 2024/25' ],
		'accent'  => [ 'Aktsentvärv', 'select', '' ],
		'summary' => [ 'Lühikirjeldus', 'textarea', '' ],
	];

	$accent_options = [
		'green-deep' => 'Tumeroheline',
		'green-mid'  => 'Keskmine roheline',
		'lime'       => 'Lime',
	];

	echo '<table class="form-table"><tbody>';
	foreach ( $fields as $key => [ $label, $type, $placeholder ] ) {
		$value = get_post_meta( $post->ID, '_' . $key, true );
		echo '<tr><th><label for="skduo_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';

		if ( 'textarea' === $type ) {
			printf(
				'<textarea id="skduo_%1$s" name="skduo_%1$s" rows="4" class="large-text">%2$s</textarea>',
				esc_attr( $key ),
				esc_textarea( (string) $value )
			);
		} elseif ( 'select' === $type ) {
			echo '<select id="skduo_' . esc_attr( $key ) . '" name="skduo_' . esc_attr( $key ) . '">';
			foreach ( $accent_options as $option_value => $option_label ) {
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( $option_value ),
					selected( $value ?: 'green-deep', $option_value, false ),
					esc_html( $option_label )
				);
			}
			echo '</select>';
		} else {
			printf(
				'<input type="%1$s" id="skduo_%2$s" name="skduo_%2$s" value="%3$s" placeholder="%4$s" class="regular-text" />',
				esc_attr( $type ),
				esc_attr( $key ),
				esc_attr( (string) $value ),
				esc_attr( $placeholder )
			);
		}

		echo '</td></tr>';
	}
	echo '</tbody></table>';
}

add_action( 'save_post_meeskond', function ( int $post_id ): void {
	if ( ! isset( $_POST['skduo_team_nonce'] ) || ! wp_verify_nonce( $_POST['skduo_team_nonce'], 'skduo_team_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$keys = [ 'level', 'league', 'record', 'summary', 'accent' ];
	foreach ( $keys as $key ) {
		if ( ! isset( $_POST[ 'skduo_' . $key ] ) ) {
			continue;
		}

		$value = 'summary' === $key
			? sanitize_textarea_field( $_POST[ 'skduo_' . $key ] )
			: sanitize_text_field( $_POST[ 'skduo_' . $key ] );

		update_post_meta( $post_id, '_' . $key, $value );
	}

	delete_transient( 'skduo_teams_with_players' );
} );

add_action( 'deleted_post', 'skduo_clear_team_roster_cache' );
add_action( 'trashed_post', 'skduo_clear_team_roster_cache' );
add_action( 'untrashed_post', 'skduo_clear_team_roster_cache' );

function skduo_clear_team_roster_cache( int $post_id ): void {
	if ( in_array( get_post_type( $post_id ), [ 'meeskond', 'mangija' ], true ) ) {
		delete_transient( 'skduo_teams_with_players' );
	}
}
