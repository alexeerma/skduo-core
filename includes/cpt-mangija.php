<?php
/**
 * Player CPT (mangija) and its meta box.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	register_post_type(
		'mangija',
		[
			'labels' => [
				'name'          => __( 'Mängijad', 'skduo' ),
				'singular_name' => __( 'Mängija', 'skduo' ),
				'add_new_item'  => __( 'Lisa mängija', 'skduo' ),
				'edit_item'     => __( 'Muuda mängijat', 'skduo' ),
				'menu_name'     => __( 'Mängijad', 'skduo' ),
			],
			'public'        => true,
			'has_archive'   => false,
			'rewrite'       => [ 'slug' => 'mangija' ],
			'show_in_rest'  => true,
			'menu_icon'     => 'dashicons-id',
			'menu_position' => 7,
			'supports'      => [ 'title', 'editor', 'thumbnail', 'page-attributes' ],
		]
	);

	register_taxonomy(
		'mangija_voistkond',
		[ 'mangija' ],
		[
			'labels' => [
				'name'          => __( 'Võistkonna tüübid', 'skduo' ),
				'singular_name' => __( 'Võistkonna tüüp', 'skduo' ),
				'search_items'  => __( 'Otsi võistkonna tüüpe', 'skduo' ),
				'all_items'     => __( 'Kõik võistkonna tüübid', 'skduo' ),
				'edit_item'     => __( 'Muuda võistkonna tüüpi', 'skduo' ),
				'update_item'   => __( 'Uuenda võistkonna tüüpi', 'skduo' ),
				'add_new_item'  => __( 'Lisa võistkonna tüüp', 'skduo' ),
				'new_item_name' => __( 'Uus võistkonna tüüp', 'skduo' ),
				'menu_name'     => __( 'Võistkonna tüübid', 'skduo' ),
			],
			'hierarchical'      => true,
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => [ 'slug' => 'mangija-voistkond' ],
		]
	);

	register_taxonomy(
		'mangija_hooaeg',
		[ 'mangija' ],
		[
			'labels' => [
				'name'          => __( 'Hooajad', 'skduo' ),
				'singular_name' => __( 'Hooaeg', 'skduo' ),
				'search_items'  => __( 'Otsi hooaegu', 'skduo' ),
				'all_items'     => __( 'Kõik hooajad', 'skduo' ),
				'edit_item'     => __( 'Muuda hooaega', 'skduo' ),
				'update_item'   => __( 'Uuenda hooaega', 'skduo' ),
				'add_new_item'  => __( 'Lisa hooaeg', 'skduo' ),
				'new_item_name' => __( 'Uus hooaeg', 'skduo' ),
				'menu_name'     => __( 'Hooajad', 'skduo' ),
			],
			'hierarchical'      => false,
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => [ 'slug' => 'mangija-hooaeg' ],
		]
	);

	foreach (
		[
			'meeste-voistkond' => __( 'Meeste võistkond', 'skduo' ),
			'naiste-voistkond' => __( 'Naiste võistkond', 'skduo' ),
		] as $slug => $name
	) {
		if ( ! term_exists( $slug, 'mangija_voistkond' ) ) {
			wp_insert_term( $name, 'mangija_voistkond', [ 'slug' => $slug ] );
		}
	}

	if ( get_option( 'skduo_mangija_rewrite_version' ) !== '20260723_player_seasons' ) {
		flush_rewrite_rules( false );
		update_option( 'skduo_mangija_rewrite_version', '20260723_player_seasons', false );
	}
} );

add_action( 'add_meta_boxes', function () {
	add_meta_box(
		'skduo_player_details',
		__( 'Mängija info', 'skduo' ),
		'skduo_render_player_meta_box',
		'mangija',
		'normal',
		'high'
	);
} );

add_action( 'admin_enqueue_scripts', function ( string $hook ): void {
	if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( $screen && 'mangija' === $screen->post_type ) {
		wp_enqueue_media();
	}
} );

function skduo_render_player_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'skduo_player_meta', 'skduo_player_nonce' );

	$team_id = (int) get_post_meta( $post->ID, '_team_id', true );
	$season_images = get_post_meta( $post->ID, '_season_images', true );
	$season_images = is_array( $season_images ) ? $season_images : [];
	$seasons = get_terms( [
		'taxonomy'   => 'mangija_hooaeg',
		'hide_empty' => false,
		'orderby'    => 'name',
		'order'      => 'DESC',
	] );
	$teams   = get_posts(
		[
			'post_type'      => 'meeskond',
			'posts_per_page' => -1,
			'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
			'post_status'    => 'any',
		]
	);

	$fields = [
		'number'       => [ 'Number', 'number' ],
		'position'     => [ 'Positsioon', 'text' ],
		'height'       => [ 'Pikkus', 'text' ],
		'weight'       => [ 'Kaal', 'text' ],
		'spike_height' => [ 'Rünnakukõrgus', 'text' ],
		'block_height' => [ 'Blokikõrgus', 'text' ],
		'birth_year'   => [ 'Sünniaasta', 'number' ],
		'profile_url'  => [ 'Mängija ajaloo / profiili link', 'url' ],
		'stats_url'    => [ 'Dataweb statistika link', 'url' ],
	];

	echo '<table class="form-table"><tbody>';
	echo '<tr><th><label for="skduo_team_id">' . esc_html__( 'Meeskond', 'skduo' ) . '</label></th><td>';
	echo '<select id="skduo_team_id" name="skduo_team_id">';
	echo '<option value="0">' . esc_html__( 'Vali meeskond', 'skduo' ) . '</option>';
	foreach ( $teams as $team ) {
		printf(
			'<option value="%1$d" %2$s>%3$s</option>',
			(int) $team->ID,
			selected( $team_id, (int) $team->ID, false ),
			esc_html( get_the_title( $team ) )
		);
	}
	echo '</select></td></tr>';

	if ( ! is_wp_error( $seasons ) && ! empty( $seasons ) ) {
		echo '<tr><th>' . esc_html__( 'Hooaja pildid', 'skduo' ) . '</th><td>';
		echo '<p class="description">' . esc_html__( 'Vali soovi korral erinev mängija pilt iga hooaja jaoks. Kui hooaja pilt puudub, kasutatakse esiletõstetud pilti.', 'skduo' ) . '</p>';
		echo '<div class="skduo-season-images" style="display:grid;gap:10px;max-width:720px;">';

		foreach ( $seasons as $season ) {
			$slug = (string) $season->slug;
			$image_id = isset( $season_images[ $slug ] ) ? absint( $season_images[ $slug ] ) : 0;
			$image = $image_id ? wp_get_attachment_image( $image_id, 'thumbnail', false, [ 'style' => 'width:64px;height:64px;object-fit:cover;' ] ) : '';
			$fallback_image = get_the_post_thumbnail( $post, 'thumbnail', [ 'style' => 'width:64px;height:64px;object-fit:cover;opacity:.72;' ] );
			$empty_preview = $fallback_image
				? $fallback_image . '<span style="font-size:11px;color:#646970;">' . esc_html__( 'Esiletõstetud pilt', 'skduo' ) . '</span>'
				: '<span style="display:grid;width:64px;height:64px;place-items:center;background:#f0f0f1;color:#646970;font-size:11px;">' . esc_html__( 'Puudub', 'skduo' ) . '</span>';

			echo '<div class="skduo-season-image-row" style="display:grid;grid-template-columns:150px 74px auto;gap:10px;align-items:center;">';
			echo '<strong>' . esc_html( $season->name ) . '</strong>';
			echo '<div class="skduo-season-image-preview" data-fallback="' . esc_attr( $empty_preview ) . '">' . ( $image ?: $empty_preview ) . '</div>';
			printf(
				'<input type="hidden" class="skduo-season-image-id" name="skduo_season_images[%1$s]" value="%2$d">',
				esc_attr( $slug ),
				$image_id
			);
			echo '<div>';
			echo '<button type="button" class="button skduo-select-season-image">' . esc_html__( 'Vali pilt', 'skduo' ) . '</button> ';
			echo '<button type="button" class="button skduo-remove-season-image">' . esc_html__( 'Eemalda', 'skduo' ) . '</button>';
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
		echo '<script>
			(function () {
				function initSeasonImages() {
					document.querySelectorAll(".skduo-season-image-row").forEach(function (row) {
						var input = row.querySelector(".skduo-season-image-id");
						var preview = row.querySelector(".skduo-season-image-preview");
						var selectButton = row.querySelector(".skduo-select-season-image");
						var removeButton = row.querySelector(".skduo-remove-season-image");

						if (!input || !preview || !selectButton || !removeButton || selectButton.dataset.skduoReady) {
							return;
						}

						selectButton.dataset.skduoReady = "1";

						selectButton.addEventListener("click", function () {
							if (!window.wp || !window.wp.media) {
								window.alert("' . esc_js( __( 'WordPressi meediateek ei ole veel valmis. Palun värskenda lehte ja proovi uuesti.', 'skduo' ) ) . '");
								return;
							}

							var frame = window.wp.media({
								title: "' . esc_js( __( 'Vali hooaja pilt', 'skduo' ) ) . '",
								button: { text: "' . esc_js( __( 'Kasuta pilti', 'skduo' ) ) . '" },
								library: { type: "image" },
								multiple: false
							});

							frame.on("select", function () {
								var attachment = frame.state().get("selection").first().toJSON();
								var thumb = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
								input.value = attachment.id;
								preview.innerHTML = "<img src=\"" + thumb + "\" alt=\"\" style=\"width:64px;height:64px;object-fit:cover;\">";
							});

							frame.open();
						});

						removeButton.addEventListener("click", function () {
							input.value = "";
							preview.innerHTML = preview.getAttribute("data-fallback") || "<span style=\"display:grid;width:64px;height:64px;place-items:center;background:#f0f0f1;color:#646970;font-size:11px;\">' . esc_js( __( 'Puudub', 'skduo' ) ) . '</span>";
						});
					});
				}

				if (document.readyState === "loading") {
					document.addEventListener("DOMContentLoaded", initSeasonImages);
				} else {
					initSeasonImages();
				}
			})();
		</script>';
		echo '</td></tr>';
	}

	foreach ( $fields as $key => [ $label, $type ] ) {
		$value = get_post_meta( $post->ID, '_' . $key, true );

		if ( 'position' === $key ) {
			echo '<tr><th><label for="skduo_position">' . esc_html( $label ) . '</label></th><td>';
			echo '<select id="skduo_position" name="skduo_position">';
			echo '<option value="">' . esc_html__( 'Vali positsioon', 'skduo' ) . '</option>';
			foreach ( skduo_mangija_position_groups() as $group_label => $options ) {
				echo '<optgroup label="' . esc_attr( $group_label ) . '">';
				foreach ( $options as $option ) {
					printf(
						'<option value="%1$s" %2$s>%1$s</option>',
						esc_attr( $option ),
						selected( (string) $value, $option, false )
					);
				}
				echo '</optgroup>';
			}
			echo '</select></td></tr>';
			continue;
		}

		printf(
			'<tr><th><label for="skduo_%1$s">%2$s</label></th><td><input type="%3$s" id="skduo_%1$s" name="skduo_%1$s" value="%4$s" class="regular-text" /></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( (string) $value )
		);
	}
	echo '</tbody></table>';
}

add_action( 'save_post_mangija', function ( int $post_id ): void {
	if ( ! isset( $_POST['skduo_player_nonce'] ) || ! wp_verify_nonce( $_POST['skduo_player_nonce'], 'skduo_player_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$keys = [ 'team_id', 'number', 'position', 'height', 'weight', 'spike_height', 'block_height', 'birth_year', 'profile_url', 'stats_url' ];
	foreach ( $keys as $key ) {
		if ( ! isset( $_POST[ 'skduo_' . $key ] ) ) {
			continue;
		}

		$raw = $_POST[ 'skduo_' . $key ];
		$value = match ( $key ) {
			'team_id', 'number', 'birth_year' => absint( $raw ),
			'profile_url', 'stats_url'        => esc_url_raw( $raw ),
			'position'                        => in_array( wp_unslash( $raw ), array_merge( ...array_values( skduo_mangija_position_groups() ) ), true ) ? sanitize_text_field( $raw ) : '',
			default                           => sanitize_text_field( $raw ),
		};

		update_post_meta( $post_id, '_' . $key, $value );
	}

	$season_images = [];
	$raw_season_images = isset( $_POST['skduo_season_images'] ) && is_array( $_POST['skduo_season_images'] )
		? wp_unslash( $_POST['skduo_season_images'] )
		: [];

	foreach ( $raw_season_images as $season_slug => $image_id ) {
		$raw_season_slug = (string) $season_slug;
		$season_slug = sanitize_title( $raw_season_slug );
		$image_id = absint( $image_id );

		if ( '' !== $season_slug && $image_id ) {
			$season_images[ $season_slug ] = $image_id;

			$dash_slug = sanitize_title( str_replace( '/', '-', $raw_season_slug ) );
			if ( '' !== $dash_slug ) {
				$season_images[ $dash_slug ] = $image_id;
			}
		}
	}

	if ( empty( $season_images ) ) {
		delete_post_meta( $post_id, '_season_images' );
	} else {
		update_post_meta( $post_id, '_season_images', $season_images );
	}

	delete_transient( 'skduo_teams_with_players' );
} );

add_action( 'set_object_terms', function ( int $object_id, $terms, $tt_ids, string $taxonomy ): void {
	if ( 'mangija_hooaeg' === $taxonomy || 'mangija_voistkond' === $taxonomy ) {
		delete_transient( 'skduo_teams_with_players' );
	}
}, 10, 4 );
