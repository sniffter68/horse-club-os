<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Mail {
	const FROM_NAME            = 'Союз любителей конного спорта';
	const PASSWORD_RESET_TITLE = 'Доступ к рабочей системе';
	const RECOVERY_PATH        = '/';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_password_reset' ), -10 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ), PHP_INT_MAX );
		add_filter( 'retrieve_password_title', array( __CLASS__, 'filter_password_reset_title' ), 10, 3 );
		add_filter( 'retrieve_password_message', array( __CLASS__, 'filter_password_reset_message' ), 10, 4 );
		add_filter( 'wp_mail', array( __CLASS__, 'format_password_reset_email' ) );
	}

	public static function filter_from_name( $name ) {
		return self::FROM_NAME;
	}

	public static function filter_password_reset_title( $title, $user_login, $user_data ) {
		return self::PASSWORD_RESET_TITLE;
	}

	public static function filter_password_reset_message( $message, $key, $user_login, $user_data ) {
		$display_name = ! empty( $user_data->display_name ) ? $user_data->display_name : $user_login;
		$reset_url    = self::recovery_url( $key, $user_login );

		return sprintf(
			'<!doctype html><html><body style="margin:0;background:#f5f1e8;color:#25302b;font-family:Arial,sans-serif"><table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="background:#f5f1e8;padding:32px 16px"><tr><td align="center"><table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #ddd9cf;border-radius:14px"><tr><td style="padding:32px"><div style="color:#173f32;font-size:20px;font-weight:700">Horse Club OS</div><h1 style="margin:28px 0 16px;font-size:26px;line-height:1.25">Восстановление доступа</h1><p style="margin:0 0 16px;line-height:1.6">Здравствуйте, %1$s!</p><p style="margin:0 0 24px;line-height:1.6">Для вашей рабочей учётной записи получен запрос на восстановление доступа.</p><p style="margin:0 0 28px"><a href="%2$s" style="display:inline-block;padding:13px 20px;border-radius:9px;background:#173f32;color:#ffffff;text-decoration:none;font-weight:700">Продолжить</a></p><p style="margin:0 0 16px;color:#707873;font-size:13px;line-height:1.6">Если вы не отправляли этот запрос, просто проигнорируйте письмо.</p><p style="margin:24px 0 0;color:#707873;font-size:13px;line-height:1.6">Союз любителей конного спорта</p></td></tr></table></td></tr></table></body></html>',
			esc_html( $display_name ),
			esc_url( $reset_url )
		);
	}

	public static function recovery_url( $key, $user_login ) {
		return home_url( self::RECOVERY_PATH ) . '?access=' . rawurlencode( self::recovery_token( $key, $user_login ) );
	}

	public static function recovery_token( $key, $user_login ) {
		return rtrim( strtr( base64_encode( $user_login . "\n" . $key ), '+/', '-_' ), '=' );
	}

	public static function parse_recovery_token( $token ) {
		$token = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $token );
		if ( '' === $token ) {
			return false;
		}

		$remainder = strlen( $token ) % 4;
		if ( $remainder ) {
			$token .= str_repeat( '=', 4 - $remainder );
		}
		$decoded = base64_decode( strtr( $token, '-_', '+/' ), true );
		$parts   = false !== $decoded ? explode( "\n", $decoded, 2 ) : array();
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return false;
		}

		return array(
			'user_login' => $parts[0],
			'key'        => $parts[1],
		);
	}

	public static function wordpress_reset_url( $key, $user_login ) {
		return network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user_login ),
			'login'
		);
	}

	public static function is_recovery_path( $request_path ) {
		$expected_path = wp_parse_url( home_url( self::RECOVERY_PATH ), PHP_URL_PATH );
		return untrailingslashit( '/' . trim( rawurldecode( (string) $request_path ), '/' ) ) === untrailingslashit( '/' . trim( rawurldecode( (string) $expected_path ), '/' ) );
	}

	public static function maybe_redirect_password_reset() {
		$request_path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH );
		if ( ! self::is_recovery_path( $request_path ?: '/' ) || ! isset( $_GET['access'] ) ) {
			return;
		}

		$recovery = self::parse_recovery_token( sanitize_text_field( wp_unslash( $_GET['access'] ) ) );
		if ( false === $recovery ) {
			wp_safe_redirect( wp_lostpassword_url( home_url( '/' ) ) );
			exit;
		}

		wp_safe_redirect(
			self::wordpress_reset_url(
				sanitize_text_field( $recovery['key'] ),
				sanitize_user( $recovery['user_login'], true )
			)
		);
		exit;
	}

	public static function format_password_reset_email( $args ) {
		if ( empty( $args['subject'] ) || self::PASSWORD_RESET_TITLE !== $args['subject'] ) {
			return $args;
		}

		$headers = isset( $args['headers'] ) ? $args['headers'] : array();
		if ( is_string( $headers ) ) {
			$headers = preg_split( '/\r\n|\r|\n/', $headers );
		}
		$headers = array_filter(
			(array) $headers,
			static function ( $header ) {
				return 0 !== stripos( trim( (string) $header ), 'Content-Type:' );
			}
		);
		$headers[]       = 'Content-Type: text/html; charset=UTF-8';
		$args['headers'] = $headers;

		return $args;
	}
}
