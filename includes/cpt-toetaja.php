<?php
/**
 * Sponsor CPT (toetaja) and its meta box.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	register_post_type(
		'toetaja',
		[
			'labels' => [
				'name'          => __( 'Toetajad', 'skduo' ),
				'singular_name' => __( 'Toetaja', 'skduo' ),
				'add_new_item'  => __( 'Lisa toetaja', 'skduo' ),
				'edit_item'     => __( 'Muuda toetajat', 'skduo' ),
				'menu_name'     => __( 'Toetajad', 'skduo' ),
			],
			'public'        => false,
			'show_ui'       => true,
			'show_in_rest'  => true,
			'menu_icon'     => 'dashicons-awards',
			'menu_position' => 8,
			'supports'      => [ 'title', 'thumbnail', 'page-attributes' ],
		]
	);
} );

add_action( 'add_meta_boxes', function () {
	add_meta_box(
		'skduo_sponsor_details',
		__( 'Toetaja info', 'skduo' ),
		'skduo_render_sponsor_meta_box',
		'toetaja',
		'normal',
		'high'
	);
} );

function skduo_render_sponsor_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'skduo_sponsor_meta', 'skduo_sponsor_nonce' );

	$allowed_tiers = [ 'nimisponsor', 'sponsor', 'partner' ];
	$tier          = (string) get_post_meta( $post->ID, '_tier', true );
	$tier          = in_array( $tier, $allowed_tiers, true ) ? $tier : 'sponsor';
	$website       = (string) get_post_meta( $post->ID, '_website', true );
	$desc          = (string) get_post_meta( $post->ID, '_description', true );
	$partner_label = (string) get_post_meta( $post->ID, '_partner_label', true );

	$tiers = [
		'nimisponsor' => __( 'Nimisponsor', 'skduo' ),
		'sponsor'     => __( 'Sponsor', 'skduo' ),
		'partner'     => __( 'Partner', 'skduo' ),
	];

	echo '<table class="form-table"><tbody>';

	echo '<tr><th><label for="skduo_tier">' . esc_html__( 'Tase', 'skduo' ) . '</label></th><td>';
	echo '<select id="skduo_tier" name="skduo_tier">';
	foreach ( $tiers as $value => $label ) {
		printf(
			'<option value="%1$s" %2$s>%3$s</option>',
			esc_attr( $value ),
			selected( $tier, $value, false ),
			esc_html( $label )
		);
	}
	echo '</select></td></tr>';

	printf(
		'<tr><th><label for="skduo_partner_label">%1$s</label></th><td><input type="text" id="skduo_partner_label" name="skduo_partner_label" value="%2$s" class="regular-text" placeholder="%3$s" /><p class="description">%4$s</p></td></tr>',
		esc_html__( 'Partneri tüüp', 'skduo' ),
		esc_attr( $partner_label ),
		esc_attr__( 'nt Varustuse partner, Vee partner', 'skduo' ),
		esc_html__( 'Kasutatakse ainult "Partner" taseme puhul, et täpsustada partnerluse liiki.', 'skduo' )
	);

	printf(
		'<tr><th><label for="skduo_website">%1$s</label></th><td><input type="url" id="skduo_website" name="skduo_website" value="%2$s" class="regular-text" /></td></tr>',
		esc_html__( 'Veebileht (URL)', 'skduo' ),
		esc_attr( $website )
	);

	printf(
		'<tr><th><label for="skduo_description">%1$s</label></th><td><textarea id="skduo_description" name="skduo_description" rows="3" class="large-text">%2$s</textarea><p class="description">%3$s</p></td></tr>',
		esc_html__( 'Lühikirjeldus', 'skduo' ),
		esc_textarea( $desc ),
		esc_html__( 'Kuvatakse ainult nimisponsori kaardil.', 'skduo' )
	);

	echo '</tbody></table>';
}

add_action( 'save_post_toetaja', function ( int $post_id ): void {
	if ( ! isset( $_POST['skduo_sponsor_nonce'] ) || ! wp_verify_nonce( $_POST['skduo_sponsor_nonce'], 'skduo_sponsor_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$allowed_tiers = [ 'nimisponsor', 'sponsor', 'partner' ];
	$tier          = isset( $_POST['skduo_tier'] ) && in_array( $_POST['skduo_tier'], $allowed_tiers, true )
		? $_POST['skduo_tier']
		: 'sponsor';

	update_post_meta( $post_id, '_tier', $tier );
	update_post_meta( $post_id, '_partner_label', isset( $_POST['skduo_partner_label'] ) ? sanitize_text_field( $_POST['skduo_partner_label'] ) : '' );
	update_post_meta( $post_id, '_website', isset( $_POST['skduo_website'] ) ? esc_url_raw( $_POST['skduo_website'] ) : '' );
	update_post_meta( $post_id, '_description', isset( $_POST['skduo_description'] ) ? sanitize_textarea_field( $_POST['skduo_description'] ) : '' );

	delete_transient( 'skduo_sponsors' );
} );
