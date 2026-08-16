<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Mail {
	const FROM_NAME            = 'Союз любителей конного спорта';
	const PASSWORD_RESET_TITLE = 'Доступ в Horse Club OS';

	public static function init() {
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
		$reset_url    = network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user_login ),
			'login'
		);

		return sprintf(
			'<!doctype html><html><body style="margin:0;background:#f5f1e8;color:#25302b;font-family:Arial,sans-serif"><table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="background:#f5f1e8;padding:32px 16px"><tr><td align="center"><table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #ddd9cf;border-radius:14px"><tr><td style="padding:32px"><div style="color:#173f32;font-size:20px;font-weight:700">Horse Club OS</div><h1 style="margin:28px 0 16px;font-size:26px;line-height:1.25">Изменение пароля</h1><p style="margin:0 0 16px;line-height:1.6">Здравствуйте, %1$s!</p><p style="margin:0 0 24px;line-height:1.6">Для вашей рабочей учётной записи получен запрос на изменение пароля.</p><p style="margin:0 0 28px"><a href="%2$s" style="display:inline-block;padding:13px 20px;border-radius:9px;background:#173f32;color:#ffffff;text-decoration:none;font-weight:700">Задать новый пароль</a></p><p style="margin:0 0 16px;color:#707873;font-size:13px;line-height:1.6">Если вы не запрашивали изменение пароля, просто проигнорируйте это письмо.</p><p style="margin:24px 0 0;color:#707873;font-size:13px;line-height:1.6">Союз любителей конного спорта</p></td></tr></table></td></tr></table></body></html>',
			esc_html( $display_name ),
			esc_url( $reset_url )
		);
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
