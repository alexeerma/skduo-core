<?php
/**
 * Youth coaches and training groups.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function (): void {
	register_post_type(
		'treener',
		[
			'labels' => [
				'name'          => __( 'Treenerid', 'skduo' ),
				'singular_name' => __( 'Treener', 'skduo' ),
				'add_new_item'  => __( 'Lisa treener', 'skduo' ),
				'edit_item'     => __( 'Muuda treenerit', 'skduo' ),
				'menu_name'     => __( 'Treenerid', 'skduo' ),
			],
			'public'        => true,
			'has_archive'   => false,
			'rewrite'       => [ 'slug' => 'treener' ],
			'show_in_rest'  => true,
			'menu_icon'     => 'dashicons-businessperson',
			'menu_position' => 9,
			'supports'      => [ 'title', 'editor', 'thumbnail', 'page-attributes' ],
		]
	);

	register_post_type(
		'treeninggrupp',
		[
			'labels' => [
				'name'          => __( 'Treeninggrupid', 'skduo' ),
				'singular_name' => __( 'Treeninggrupp', 'skduo' ),
				'add_new_item'  => __( 'Lisa treeninggrupp', 'skduo' ),
				'edit_item'     => __( 'Muuda treeninggruppi', 'skduo' ),
				'menu_name'     => __( 'Treeninggrupid', 'skduo' ),
			],
			'public'        => true,
			'has_archive'   => false,
			'rewrite'       => [ 'slug' => 'treeninggrupp' ],
			'show_in_rest'  => true,
			'menu_icon'     => 'dashicons-groups',
			'menu_position' => 10,
			'supports'      => [ 'title', 'editor', 'thumbnail', 'page-attributes' ],
		]
	);

	foreach ( [ 'role', 'phone', 'email' ] as $key ) {
		register_post_meta(
			'treener',
			'_' . $key,
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'email' === $key ? 'sanitize_email' : 'sanitize_text_field',
				'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
			]
		);
	}

	register_post_meta(
		'treeninggrupp',
		'_coach_id',
		[
			'type'          => 'integer',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => fn() => current_user_can( 'edit_posts' ),
		]
	);

	foreach ( [ 'age_group', 'schedule', 'contact' ] as $key ) {
		register_post_meta(
			'treeninggrupp',
			'_' . $key,
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_textarea_field',
				'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
			]
		);
	}
} );

add_action( 'add_meta_boxes', function (): void {
	add_meta_box(
		'skduo_coach_details',
		__( 'Treeneri info', 'skduo' ),
		'skduo_render_coach_meta_box',
		'treener',
		'normal',
		'high'
	);

	add_meta_box(
		'skduo_training_group_details',
		__( 'Treeninggrupi info', 'skduo' ),
		'skduo_render_training_group_meta_box',
		'treeninggrupp',
		'normal',
		'high'
	);
} );

function skduo_render_coach_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'skduo_coach_meta', 'skduo_coach_nonce' );

	$fields = [
		'role'  => [ 'Roll', 'text', 'Noortetreener' ],
		'phone' => [ 'Telefon', 'text', '' ],
		'email' => [ 'E-post', 'email', '' ],
	];

	echo '<table class="form-table"><tbody>';
	foreach ( $fields as $key => [ $label, $type, $placeholder ] ) {
		printf(
			'<tr><th><label for="skduo_%1$s">%2$s</label></th><td><input type="%3$s" id="skduo_%1$s" name="skduo_%1$s" value="%4$s" placeholder="%5$s" class="regular-text" /></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( (string) get_post_meta( $post->ID, '_' . $key, true ) ),
			esc_attr( $placeholder )
		);
	}
	echo '</tbody></table>';
}

function skduo_render_training_group_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'skduo_training_group_meta', 'skduo_training_group_nonce' );

	$coach_id = (int) get_post_meta( $post->ID, '_coach_id', true );
	$coaches  = get_posts( [
		'post_type'      => 'treener',
		'posts_per_page' => -1,
		'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
		'post_status'    => 'any',
	] );

	echo '<table class="form-table"><tbody>';
	echo '<tr><th><label for="skduo_coach_id">' . esc_html__( 'Treener', 'skduo' ) . '</label></th><td>';
	echo '<select id="skduo_coach_id" name="skduo_coach_id">';
	echo '<option value="0">' . esc_html__( 'Vali treener', 'skduo' ) . '</option>';
	foreach ( $coaches as $coach ) {
		printf(
			'<option value="%1$d" %2$s>%3$s</option>',
			(int) $coach->ID,
			selected( $coach_id, (int) $coach->ID, false ),
			esc_html( get_the_title( $coach ) )
		);
	}
	echo '</select></td></tr>';

	$fields = [
		'age_group' => [ 'Vanusegrupp', 'text', 'U-14 tüdrukud (2012-2013)' ],
		'schedule'  => [ 'Treeningajad', 'textarea', 'Üks treeningaeg rea kohta.' ],
		'contact'   => [ 'Kontakt', 'textarea', 'Üks kontaktirea kohta.' ],
	];

	foreach ( $fields as $key => [ $label, $type, $placeholder ] ) {
		$value = (string) get_post_meta( $post->ID, '_' . $key, true );
		echo '<tr><th><label for="skduo_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'textarea' === $type ) {
			printf(
				'<textarea id="skduo_%1$s" name="skduo_%1$s" rows="5" class="large-text" placeholder="%3$s">%2$s</textarea>',
				esc_attr( $key ),
				esc_textarea( $value ),
				esc_attr( $placeholder )
			);
		} else {
			printf(
				'<input type="%1$s" id="skduo_%2$s" name="skduo_%2$s" value="%3$s" placeholder="%4$s" class="regular-text" />',
				esc_attr( $type ),
				esc_attr( $key ),
				esc_attr( $value ),
				esc_attr( $placeholder )
			);
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';
}

add_action( 'save_post_treener', function ( int $post_id ): void {
	if ( ! isset( $_POST['skduo_coach_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['skduo_coach_nonce'] ) ), 'skduo_coach_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	foreach ( [ 'role', 'phone', 'email' ] as $key ) {
		if ( ! isset( $_POST[ 'skduo_' . $key ] ) ) {
			continue;
		}

		$value = 'email' === $key
			? sanitize_email( wp_unslash( $_POST[ 'skduo_' . $key ] ) )
			: sanitize_text_field( wp_unslash( $_POST[ 'skduo_' . $key ] ) );
		update_post_meta( $post_id, '_' . $key, $value );
	}
} );

add_action( 'save_post_treeninggrupp', function ( int $post_id ): void {
	if ( ! isset( $_POST['skduo_training_group_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['skduo_training_group_nonce'] ) ), 'skduo_training_group_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	update_post_meta( $post_id, '_coach_id', isset( $_POST['skduo_coach_id'] ) ? absint( $_POST['skduo_coach_id'] ) : 0 );

	foreach ( [ 'age_group', 'schedule', 'contact' ] as $key ) {
		if ( ! isset( $_POST[ 'skduo_' . $key ] ) ) {
			continue;
		}

		$value = in_array( $key, [ 'schedule', 'contact' ], true )
			? sanitize_textarea_field( wp_unslash( $_POST[ 'skduo_' . $key ] ) )
			: sanitize_text_field( wp_unslash( $_POST[ 'skduo_' . $key ] ) );
		update_post_meta( $post_id, '_' . $key, $value );
	}
} );
