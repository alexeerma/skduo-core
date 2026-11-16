<?php
/** Credential protection and deliberately restricted diagnostic history. */
defined( 'ABSPATH' ) || exit;

function skduo_dp_encrypt_token( string $token, string $source ): string|WP_Error {
	if ( ! function_exists( 'openssl_encrypt' ) || ! skduo_dp_valid_token( $token ) ) {
		return new WP_Error( 'dp_token_storage', 'Token’i vorming on vigane või server ei toeta krüpteeritud salvestamist.' );
	}
	try {
		$iv = random_bytes( 12 );
		$key = hash( 'sha256', wp_salt( 'auth' ) . '|skduo-dataproject', true );
		$encrypted = openssl_encrypt( $token, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, skduo_dp_source( $source ) );
		return false === $encrypted ? new WP_Error( 'dp_token_storage', 'Token’i krüpteerimine ebaõnnestus.' )
			: 'skduo:v1:' . base64_encode( $iv . $tag . $encrypted );
	} catch ( Throwable $e ) {
		return new WP_Error( 'dp_token_storage', 'Token’i krüpteerimine ebaõnnestus.' );
	}
}

function skduo_dp_decrypt_token( string $stored, string $source ): string {
	if ( ! function_exists( 'openssl_decrypt' ) || ! str_starts_with( $stored, 'skduo:v1:' ) ) {
		return '';
	}
	$raw = base64_decode( substr( $stored, 9 ), true );
	if ( false === $raw || strlen( $raw ) < 29 ) {
		return '';
	}
	$key = hash( 'sha256', wp_salt( 'auth' ) . '|skduo-dataproject', true );
	$token = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), skduo_dp_source( $source ) );
	return is_string( $token ) && skduo_dp_valid_token( $token ) ? $token : '';
}

function skduo_dp_valid_token( string $token ): bool {
	return strlen( $token ) <= 8192 && 1 === preg_match( '#^[A-Za-z0-9._~+/-]+=*$#D', $token );
}

function skduo_dp_save_token( string $token, string $source ): bool|WP_Error {
	$encrypted = skduo_dp_encrypt_token( trim( $token ), $source );
	if ( is_wp_error( $encrypted ) ) {
		return $encrypted;
	}
	if ( ! update_option( skduo_dp_option( 'token', $source ), $encrypted, false ) ) {
		return new WP_Error( 'dp_token_storage', 'Token’i salvestamine ebaõnnestus.' );
	}
	delete_transient( skduo_dp_option( 'cooldown', $source ) );
	return true;
}

/** Log only known structural fields. No URL, header, token, body or arbitrary message. */
function skduo_dp_log( string $source, string $kind, array $details = [] ): void {
	$entry = [ 'time' => time(), 'kind' => in_array( $kind, [ 'request', 'sync', 'settings' ], true ) ? $kind : 'sync' ];
	foreach ( [ 'http', 'duration_ms', 'rows', 'created', 'updated', 'unchanged', 'undated', 'trashed' ] as $key ) {
		if ( isset( $details[ $key ] ) && is_numeric( $details[ $key ] ) ) {
			$entry[ $key ] = max( 0, (int) $details[ $key ] );
		}
	}
	if ( isset( $details['resource'] ) && preg_match( '#^(Season|Team|Competition|Phase|Championship|Leg|Match|Stadium)(/[1-9][0-9]*)?$#D', $details['resource'] ) ) {
		$entry['resource'] = $details['resource'];
	}
	foreach ( [ 'result', 'error' ] as $key ) {
		if ( isset( $details[ $key ] ) && preg_match( '/^[a-z_]{1,40}$/D', $details[ $key ] ) ) {
			$entry[ $key ] = $details[ $key ];
		}
	}
	// Numeric contract filters are sufficient to distinguish rounds without leaking credentials.
	foreach ( [ 'seasontype', 'seasonID', 'competitionID', 'phaseID', 'championshipID', 'legID' ] as $key ) {
		if ( isset( $details['query'][ $key ] ) && skduo_dp_positive_id( $details['query'][ $key ] ) ) {
			$entry['query'][ $key ] = (int) $details['query'][ $key ];
		}
	}
	$history = (array) get_option( skduo_dp_option( 'log', $source ), [] );
	$history = array_values( array_filter( $history, fn ( $row ): bool => is_array( $row ) && ( $row['time'] ?? 0 ) >= time() - 7 * DAY_IN_SECONDS ) );
	$history[] = $entry;
	update_option( skduo_dp_option( 'log', $source ), array_slice( $history, -200 ), false );
}

function skduo_dp_log_history( string $source ): array {
	return array_reverse( array_values( array_filter( (array) get_option( skduo_dp_option( 'log', $source ), [] ),
		fn ( $row ): bool => is_array( $row ) && ( $row['time'] ?? 0 ) >= time() - 7 * DAY_IN_SECONDS
	) ) );
}

function skduo_dp_retry_seconds( string $header ): int {
	$seconds = ctype_digit( $header ) ? (int) $header : ( strtotime( $header ) ?: time() ) - time();
	return min( HOUR_IN_SECONDS, max( 60, $seconds ) );
}
