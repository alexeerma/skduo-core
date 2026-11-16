<?php
/** DataProject connection, ID discovery and sync controls (administrators only). */
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function (): void {
	add_submenu_page( 'edit.php?post_type=mang', 'DataProject', 'DataProject', 'manage_options', 'skduo-dataproject', 'skduo_dp_admin_page' );
} );

add_action( 'admin_post_skduo_dp_action', function (): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Puudub õigus DataProjecti seadistada.', '', [ 'response' => 403 ] );
	}
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_die( 'Lubatud on ainult POST-päring.', '', [ 'response' => 405 ] );
	}
	foreach ( [ 'source', 'operation', 'code', 'timezone', 'token', 'season_id', 'competition_id', 'team_id', '_wpnonce' ] as $field ) {
		if ( isset( $_POST[ $field ] ) && ! is_string( $_POST[ $field ] ) ) {
			wp_die( 'Vigane vormiandmete tüüp.', '', [ 'response' => 400 ] );
		}
	}
	check_admin_referer( 'skduo_dp_action' );
	$source = sanitize_key( wp_unslash( $_POST['source'] ?? 'bvl' ) );
	if ( ! in_array( $source, [ 'bvl', 'evf' ], true ) ) {
		wp_die( 'Tundmatu ühendus.', '', [ 'response' => 400 ] );
	}
	$operation = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) );
	if ( in_array( $operation, [ 'sync', 'discover' ], true ) ) {
		// Loading selectors must not prevent the next step: importing matches.
		$key = skduo_dp_option( 'admin_rate_' . $operation, $source );
		if ( get_transient( $key ) ) {
			skduo_dp_admin_notice( 'Sama toimingut saab korrata 60 sekundi pärast. Teist API toimingut saad käivitada kohe.', true );
			wp_safe_redirect( admin_url( 'edit.php?post_type=mang&page=skduo-dataproject&source=' . $source ) );
			exit;
		}
		set_transient( $key, true, MINUTE_IN_SECONDS );
	}
	if ( 'save' === $operation ) {
		$old = skduo_dp_settings( $source );
		$code = trim( sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) );
		$token = trim( (string) wp_unslash( $_POST['token'] ?? '' ) );
		$encrypted_token = '' !== $token && ! isset( $_POST['remove_token'] ) ? skduo_dp_encrypt_token( $token, $source ) : '';
		$timezone = sanitize_text_field( wp_unslash( $_POST['timezone'] ?? '' ) );
		if ( is_wp_error( $encrypted_token ) ) {
			skduo_dp_admin_notice( $encrypted_token->get_error_message(), true );
		} elseif ( ( '' !== $code && ! preg_match( '/^[a-zA-Z0-9_-]+$/D', $code ) ) || ! in_array( $timezone, DateTimeZone::listIdentifiers(), true ) ) {
			skduo_dp_admin_notice( 'Organisatsioonikood või ajavöönd on vigane.', true );
		} elseif ( get_option( 'skduo_dp_lock', false ) ) {
			skduo_dp_admin_notice( 'Oota sünkroonimise lõppu enne seadete muutmist.', true );
		} else {
			$settings = [
				'code' => $code, 'timezone' => $timezone, 'source' => $source,
				'season_id' => absint( $_POST['season_id'] ?? 0 ),
				'competition_id' => absint( $_POST['competition_id'] ?? 0 ),
				'team_id' => absint( $_POST['team_id'] ?? 0 ),
				'automatic' => isset( $_POST['automatic'] ),
			];
			// Preserve explicitly submitted IDs. The importer validates their API scope.
			update_option( skduo_dp_option( 'settings', $source ), $settings, false );
			if ( ! defined( skduo_dp_token_constant( $source ) ) ) {
				$token = trim( (string) wp_unslash( $_POST['token'] ?? '' ) );
				if ( isset( $_POST['remove_token'] ) ) {
					delete_option( skduo_dp_option( 'token', $source ) );
				} elseif ( '' !== $token && ! preg_match( '/[\r\n]/', $token ) ) {
					update_option( skduo_dp_option( 'token', $source ), $encrypted_token, false );
					delete_transient( skduo_dp_option( 'cooldown', $source ) );
				}
			}
			if ( $old['code'] !== $settings['code'] || (int) $old['season_id'] !== $settings['season_id'] ) {
				delete_option( skduo_dp_option( 'discovery', $source ) );
			}
			skduo_dp_log( $source, 'settings', [ 'result' => 'saved' ] );
			skduo_dp_admin_notice( 'Seaded salvestatud. Vajuta „Laadi valikud”, et küsida API-st hooaega, võistlust ja meeskonda.' );
		}
	} elseif ( 'discover' === $operation ) {
		$settings = skduo_dp_settings( $source );
		$seasons = skduo_dp_list( 'Season', [ 'seasontype' => 2 ], 'SeasonID', $settings );
		$competitions = $settings['season_id'] ? skduo_dp_list( 'Competition', [ 'seasonID' => $settings['season_id'] ], 'CompetitionID', $settings ) : [];
		$teams = $settings['season_id'] ? skduo_dp_list( 'Team', [ 'seasonID' => $settings['season_id'] ], 'TeamID', $settings ) : [];
		$error = null;
		foreach ( [ $seasons, $competitions, $teams ] as $result ) {
			if ( is_wp_error( $result ) ) {
				$error = $result;
				break;
			}
		}
		if ( $error ) {
			delete_option( skduo_dp_option( 'discovery', $source ) );
			skduo_dp_admin_notice( $error->get_error_message(), true );
		} else {
			// Store display data only; player/referee details and credentials do not belong here.
			$options = [];
			foreach ( [ 'season_id' => [ $seasons, 'SeasonID', 'Description' ], 'competition_id' => [ $competitions, 'CompetitionID', 'Description' ], 'team_id' => [ $teams, 'TeamID', 'Name' ] ] as $key => [ $rows, $id_key, $label_key ] ) {
				$options[ $key ] = array_map( fn ( array $row ): array => [ 'id' => (int) $row[ $id_key ], 'label' => sanitize_text_field( (string) ( $row[ $label_key ] ?? '' ) ) ], $rows );
			}
			update_option( skduo_dp_option( 'discovery', $source ), $options, false );
			skduo_dp_admin_notice( 'Valikud laaditud. Vali hooaeg ja salvesta. Seejärel laadi valikud uuesti ning vali soovitud võistlus ja Bigbank Tartu.' );
		}
	} elseif ( 'sync' === $operation ) {
		$result = skduo_dp_sync( $source );
		skduo_dp_admin_notice( is_wp_error( $result ) ? $result->get_error_message() : get_option( skduo_dp_option( 'last_result', $source ) )['message'], is_wp_error( $result ) );
	} elseif ( 'unlock' === $operation ) {
		$lock = (int) get_option( 'skduo_dp_lock', 0 );
		if ( $lock && time() - $lock > 30 * MINUTE_IN_SECONDS ) {
			delete_option( 'skduo_dp_lock' );
			skduo_dp_admin_notice( 'Katkenud sünkroonimise lukk vabastatud.' );
		} else {
			skduo_dp_admin_notice( 'Luku saab vabastada alles 30 minutit pärast sünkroonimise algust.', true );
		}
	}
	wp_safe_redirect( admin_url( 'edit.php?post_type=mang&page=skduo-dataproject&source=' . $source ) );
	exit;
} );

function skduo_dp_admin_notice( string $message, bool $error = false ): void {
	set_transient( 'skduo_dp_notice_' . get_current_user_id(), [ 'message' => $message, 'error' => $error ], 5 * MINUTE_IN_SECONDS );
}

function skduo_dp_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$raw_source = $_GET['source'] ?? 'bvl';
	$source = is_string( $raw_source ) ? skduo_dp_source( sanitize_key( wp_unslash( $raw_source ) ) ) : 'bvl';
	$settings = skduo_dp_settings( $source );
	$options = (array) get_option( skduo_dp_option( 'discovery', $source ), [] );
	$notice = get_transient( 'skduo_dp_notice_' . get_current_user_id() );
	delete_transient( 'skduo_dp_notice_' . get_current_user_id() );
	$last = get_option( skduo_dp_option( 'last_result', $source ), [] );
	?>
	<div class="wrap">
		<h1>DataProjecti mängud</h1>
		<nav class="nav-tab-wrapper" aria-label="DataProjecti ühendused">
		<?php foreach ( [ 'bvl' => 'Balti liiga', 'evf' => 'EVF / karikavõistlused' ] as $key => $label ) : ?>
			<a class="nav-tab <?php echo $source === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'edit.php?post_type=mang&page=skduo-dataproject&source=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
		</nav>
		<p>Igal ühendusel on oma token ja võistluse valik. Mõlema ühenduse mängud kuvatakse samas kalendris.</p>
		<?php if ( 'Europe/Tallinn' === $settings['timezone'] && in_array( wp_timezone()->getName(), [ 'UTC', '+00:00', 'Etc/UTC' ], true ) ) : ?>
			<div class="notice notice-warning"><p>
				Veebilehe ajavöönd on UTC, kuid API mänguajad on Eesti ajas. Eesti kohalike kellaaegade kuvamiseks vali
				<a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">Seaded → Üldine → Ajavöönd → Tallinn</a>
				ja sünkrooni mängud uuesti. Käsitsi muudetud mänguaeg vajab eraldi kontrollimist.
			</p></div>
		<?php endif; ?>
		<p>Ühenda DataProject, vali soovitud võistlus ja Bigbank Tartu ning too mängud olemasolevasse kalendrisse.</p>
		<?php if ( $notice ) : ?>
			<div class="notice <?php echo $notice['error'] ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="source" value="<?php echo esc_attr( $source ); ?>"><input type="hidden" name="action" value="skduo_dp_action">
			<input type="hidden" name="operation" value="save">
			<?php wp_nonce_field( 'skduo_dp_action' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><label for="dp-code">Organisatsioonikood</label></th><td>
					<input id="dp-code" name="code" value="<?php echo esc_attr( $settings['code'] ); ?>" class="regular-text" pattern="[a-zA-Z0-9_-]+">
					<p class="description">DataProjectilt saadud [CODE]. Kasutajanimest ei saa koodi kindlalt tuletada.</p>
				</td></tr>
				<tr><th><label for="dp-token">Bearer token</label></th><td>
					<?php if ( defined( skduo_dp_token_constant( $source ) ) ) : ?>
						<p>Token on seadistatud serveri konfiguratsioonis.</p>
					<?php else : ?>
						<input type="password" id="dp-token" name="token" value="" autocomplete="new-password" class="regular-text">
						<p class="description"><?php echo skduo_dp_token( $source ) ? 'Token on salvestatud. Jäta tühjaks, et säilitada olemasolev token.' : 'Sisesta DataProjecti token ilma „Bearer” eesliiteta. Kasutajanimi ja parool ei sobi siia.'; ?></p>
						<p class="description">Token salvestatakse krüpteeritult. WordPressi turvavõtmete muutmisel või andmebaasi viimisel teise serverisse tuleb token uuesti sisestada.</p>
						<?php if ( skduo_dp_token( $source ) ) : ?><p><label><input type="checkbox" name="remove_token"> Eemalda salvestatud token</label></p><?php endif; ?>
					<?php endif; ?>
				</td></tr>
				<?php foreach ( [ 'season_id' => 'Hooaeg', 'competition_id' => 'Võistlus', 'team_id' => 'Meeskond (Bigbank Tartu)' ] as $key => $label ) : ?>
					<tr><th><label for="dp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td>
						<?php if ( ! empty( $options[ $key ] ) ) : ?>
							<select id="dp-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>">
								<option value="0">Vali…</option>
								<?php foreach ( $options[ $key ] as $option ) : ?>
									<option value="<?php echo esc_attr( $option['id'] ); ?>" <?php selected( $settings[ $key ], $option['id'] ); ?>><?php echo esc_html( $option['label'] . ' (ID ' . $option['id'] . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<input type="number" min="0" id="dp-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $settings[ $key ] ); ?>">
							<p class="description">Laadi API valikud või sisesta DataProjectilt saadud ID.</p>
						<?php endif; ?>
					</td></tr>
				<?php endforeach; ?>
				<tr><th><label for="dp-timezone">API kellaaegade ajavöönd</label></th><td>
					<select id="dp-timezone" name="timezone">
						<?php foreach ( DateTimeZone::listIdentifiers() as $zone ) : ?>
							<option <?php selected( $settings['timezone'], $zone ); ?> value="<?php echo esc_attr( $zone ); ?>"><?php echo esc_html( $zone ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Kinnita DataProjectiga ilma ajavööndita kellaaegade tähendus. UTC või nihkega kuupäevad teisendatakse automaatselt. Veebilehe ajavöönd: <?php echo esc_html( wp_timezone()->getName() ); ?>.</p>
				</td></tr>
				<tr><th>Automaatne uuendamine</th><td><label><input type="checkbox" name="automatic" <?php checked( $settings['automatic'] ); ?>> Uuenda kord tunnis</label>
					<p class="description">WordPressi ajastaja käivitub lehe külastustega. Lülita sisse pärast esimest edukat käsitsi sünkroonimist.</p>
				</td></tr>
			</table>
			<?php submit_button( 'Salvesta seaded' ); ?>
		</form>
		<hr>
		<?php foreach ( [ 'discover' => 'Laadi valikud', 'sync' => 'Sünkrooni mängud' ] as $operation => $label ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:12px">
				<input type="hidden" name="source" value="<?php echo esc_attr( $source ); ?>"><input type="hidden" name="action" value="skduo_dp_action"><input type="hidden" name="operation" value="<?php echo esc_attr( $operation ); ?>">
				<?php wp_nonce_field( 'skduo_dp_action' ); submit_button( $label, 'secondary', 'submit', false ); ?>
			</form>
		<?php endforeach; ?>
		<p>Need nupud kasutavad salvestatud seadeid. Kuupäevata mängud salvestatakse mustandina; piletiviidad ja mängu sisu jäävad halduses muudetavaks.</p>
		<p>Kohalikud käsitsi lisatud mängud ei seostu API mängudega automaatselt. Kontrolli esimese impordi järel, kas oled samu mänge varem käsitsi sisestanud.</p>
		<?php if ( $last ) : ?>
			<h2>Viimane sünkroonimine</h2>
			<p><?php echo esc_html( wp_date( 'd.m.Y H:i', $last['time'] ) . ' — ' . $last['message'] ); ?></p>
		<?php endif; ?>
		<?php skduo_dp_admin_log( $source ); ?>
		<?php $lock = (int) get_option( 'skduo_dp_lock', 0 ); if ( $lock && time() - $lock > 30 * MINUTE_IN_SECONDS ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="source" value="<?php echo esc_attr( $source ); ?>"><input type="hidden" name="action" value="skduo_dp_action"><input type="hidden" name="operation" value="unlock">
				<?php wp_nonce_field( 'skduo_dp_action' ); submit_button( 'Vabasta katkenud sünkroonimise lukk', 'secondary' ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}


function skduo_dp_admin_log( string $source ): void {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$history = skduo_dp_log_history( $source );
	?>
	<h2>API logi</h2>
	<p>Viimased 200 sündmust, kuni 7 päeva. Logis on päringu ressurss, HTTP kood, kestus ja impordi tulemus. Token’eid, päiseid ega vastuste sisu ei salvestata.</p>
	<?php if ( ! $history ) : ?>
		<p>Logi on tühi. Käivita valikute laadimine või mängude sünkroonimine.</p>
	<?php else : ?>
	<div style="max-height:480px;overflow:auto">
	<table class="widefat striped">
		<thead><tr><th>Aeg</th><th>Toiming</th><th>Päring</th><th>HTTP</th><th>Kestus</th><th>Tulemus</th></tr></thead>
		<tbody>
		<?php foreach ( $history as $row ) :
			$kind = [ 'request' => 'API päring', 'sync' => 'Sünkroonimine', 'settings' => 'Seaded' ][ $row['kind'] ] ?? 'Sündmus';
			$description = [ 'ok' => 'Õnnestus', 'error' => 'Tõrge', 'saved' => 'Salvestatud' ][ $row['result'] ?? '' ] ?? '';
			if ( ! empty( $row['error'] ) ) { $description .= ' (' . $row['error'] . ')'; }
			if ( isset( $row['rows'] ) ) { $description .= ' · Kirjeid: ' . $row['rows']; }
			if ( isset( $row['created'] ) ) { $description .= sprintf( ' · Lisatud %d, uuendatud %d, muutumatud %d, kuupäevata %d, prügikastis %d', $row['created'], $row['updated'], $row['unchanged'] ?? 0, $row['undated'], $row['trashed'] ); }
			$request = ( $row['resource'] ?? '' ) . ( ! empty( $row['query'] ) ? '?' . http_build_query( $row['query'] ) : '' );
		?>
		<tr>
			<td><?php echo esc_html( wp_date( 'd.m.Y H:i:s', $row['time'] ) ); ?></td>
			<td><?php echo esc_html( $kind ); ?></td>
			<td><code><?php echo esc_html( $request ); ?></code></td>
			<td><?php echo esc_html( (string) ( $row['http'] ?? '—' ) ); ?></td>
			<td><?php echo isset( $row['duration_ms'] ) ? esc_html( $row['duration_ms'] . ' ms' ) : '—'; ?></td>
			<td><?php echo esc_html( $description ); ?></td>
		</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<?php endif;
}
