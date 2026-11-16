<?php
/** Match list columns, chronological ordering and inclusive date-range filters. */
defined( 'ABSPATH' ) || exit;

function skduo_match_admin_date( mixed $value ): string {
	if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts ) ) { return ''; }
	return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? $value : '';
}

function skduo_match_admin_range(): array {
	$range = [];
	foreach ( [ 'from', 'to' ] as $key ) {
		$raw = $_GET[ 'skduo_match_' . $key ] ?? '';
		$range[ $key ] = skduo_match_admin_date( $raw );
		if ( '' !== $raw && '' === $range[ $key ] ) { $range['invalid'] = true; }
	}
	if ( $range['from'] && $range['to'] && $range['from'] > $range['to'] ) { $range['invalid'] = true; }
	return $range;
}

add_filter( 'manage_mang_posts_columns', function ( array $columns ): array {
	$result = [];
	foreach ( $columns as $key => $label ) {
		$result[ $key ] = $label;
		if ( 'title' === $key ) { $result['skduo_match_date'] = __( 'Mängu kuupäev', 'skduo' ); }
	}
	return $result;
} );

add_filter( 'manage_edit-mang_sortable_columns', function ( array $columns ): array {
	$columns['skduo_match_date'] = 'skduo_match_date';
	return $columns;
} );

add_action( 'manage_mang_posts_custom_column', function ( string $column, int $post_id ): void {
	if ( 'skduo_match_date' !== $column ) { return; }
	$match = skduo_match_data( get_post( $post_id ) );
	echo $match->date_ts ? esc_html( wp_date( 'd.m.Y H:i', $match->date_ts ) ) : esc_html__( 'Kuupäev määramata', 'skduo' );
}, 10, 2 );

add_action( 'restrict_manage_posts', function ( string $post_type, string $which ): void {
	if ( 'mang' !== $post_type || 'top' !== $which ) { return; }
	$range = skduo_match_admin_range();
	?>
	<label for="skduo-match-from"><?php esc_html_e( 'Mängud alates', 'skduo' ); ?></label>
	<input type="date" id="skduo-match-from" name="skduo_match_from" value="<?php echo esc_attr( $range['from'] ); ?>" aria-label="<?php esc_attr_e( 'Mängu kuupäev alates', 'skduo' ); ?>">
	<label for="skduo-match-to"><?php esc_html_e( 'kuni', 'skduo' ); ?></label>
	<input type="date" id="skduo-match-to" name="skduo_match_to" value="<?php echo esc_attr( $range['to'] ); ?>" aria-label="<?php esc_attr_e( 'Mängu kuupäev kuni', 'skduo' ); ?>">
	<?php if ( $range['from'] || $range['to'] || ! empty( $range['invalid'] ) ) : ?>
		<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mang' ) ); ?>"><?php esc_html_e( 'Eemalda filtrid', 'skduo' ); ?></a>
	<?php endif;
}, 10, 2 );

add_action( 'pre_get_posts', function ( WP_Query $query ): void {
	global $pagenow;
	if ( ! is_admin() || ! $query->is_main_query() || 'edit.php' !== $pagenow || 'mang' !== $query->get( 'post_type' ) ) { return; }
	$query->set( 'skduo_match_admin_list', true );
	if ( ! $query->get( 'orderby' ) ) {
		$query->set( 'orderby', 'skduo_match_date' );
		$query->set( 'order', 'ASC' );
	}
} );

add_filter( 'posts_clauses', function ( array $clauses, WP_Query $query ): array {
	if ( ! is_admin() || ! $query->get( 'skduo_match_admin_list' ) ) { return $clauses; }
	global $wpdb;
	// A scalar subquery keeps undated posts visible and never duplicates rows/pagination.
	$date = "(SELECT MIN(NULLIF(REPLACE(pm.meta_value, 'T', ' '), '')) FROM {$wpdb->postmeta} pm WHERE pm.post_id = {$wpdb->posts}.ID AND pm.meta_key = '_match_date')";
	$range = skduo_match_admin_range();
	if ( ! empty( $range['invalid'] ) ) {
		$clauses['where'] .= ' AND 1=0';
	} else {
		if ( $range['from'] ) { $clauses['where'] .= $wpdb->prepare( " AND LEFT($date, 10) >= %s", $range['from'] ); }
		if ( $range['to'] ) { $clauses['where'] .= $wpdb->prepare( " AND LEFT($date, 10) <= %s", $range['to'] ); }
	}
	if ( 'skduo_match_date' === $query->get( 'orderby' ) ) {
		$direction = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';
		$clauses['orderby'] = "$date IS NULL ASC, $date $direction, {$wpdb->posts}.ID $direction";
	}
	return $clauses;
}, 10, 2 );

add_action( 'admin_notices', function (): void {
	$screen = get_current_screen();
	if ( ! $screen || 'edit-mang' !== $screen->id || empty( skduo_match_admin_range()['invalid'] ) ) { return; }
	echo '<div class="notice notice-error"><p>' . esc_html__( 'Kontrolli mängude kuupäevavahemikku: algus peab olema lõppkuupäevast varasem või sellega võrdne.', 'skduo' ) . '</p></div>';
} );
