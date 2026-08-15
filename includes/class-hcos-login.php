<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Login {
	const ACTION = 'hcos_login';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_portal' ), 0 );
		add_action( 'login_init', array( __CLASS__, 'redirect_default_login' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'filter_login_redirect' ), 10, 3 );
	}

	public static function maybe_render_portal() {
		if ( ! self::is_portal_request() ) {
			return;
		}

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( self::can_use_portal( $user ) ) {
				wp_safe_redirect( self::destination( $user ) );
				exit;
			}
			wp_logout();
			self::render( 'У этой учётной записи нет доступа к Horse Club OS.' );
		}

		$error = '';
		if ( 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			$error = self::handle_login();
		}

		self::render( $error );
	}

	private static function handle_login() {
		$action = isset( $_POST['hcos_action'] ) ? sanitize_key( wp_unslash( $_POST['hcos_action'] ) ) : '';
		$nonce  = isset( $_POST['hcos_login_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['hcos_login_nonce'] ) ) : '';
		if ( self::ACTION !== $action || ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			return 'Сессия формы истекла. Обновите страницу и попробуйте снова.';
		}

		$login    = isset( $_POST['log'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['log'] ) ) ) : '';
		$password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '';
		$remember = ! empty( $_POST['rememberme'] );
		if ( '' === $login || '' === $password ) {
			return 'Введите логин и пароль.';
		}

		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);
		if ( is_wp_error( $user ) ) {
			return 'Не удалось войти. Проверьте логин и пароль.';
		}
		if ( ! self::can_use_portal( $user ) ) {
			wp_logout();
			return 'У этой учётной записи нет доступа к Horse Club OS.';
		}

		wp_safe_redirect( self::destination( $user ) );
		exit;
	}

	public static function can_use_portal( $user ) {
		return $user && method_exists( $user, 'has_cap' ) && $user->has_cap( 'edit_hcos_lessons' );
	}

	public static function destination( $user = null ) {
		return admin_url( 'admin.php?page=hcos-dashboard' );
	}

	public static function filter_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		return self::can_use_portal( $user ) ? self::destination( $user ) : $redirect_to;
	}

	public static function redirect_default_login() {
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' );
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';
		if ( 'GET' !== $method || 'login' !== $action || isset( $_REQUEST['interim-login'] ) || isset( $_REQUEST['reauth'] ) ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	public static function is_portal_path( $request_path, $home_path ) {
		$request_path = '/' . trim( rawurldecode( (string) $request_path ), '/' );
		$home_path    = '/' . trim( rawurldecode( (string) $home_path ), '/' );
		return untrailingslashit( $request_path ) === untrailingslashit( $home_path );
	}

	private static function is_portal_request() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		$request_path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH );
		$home_path    = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return self::is_portal_path( $request_path ?: '/', $home_path ?: '/' );
	}

	private static function render( $error = '' ) {
		nocache_headers();
		status_header( 200 );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: same-origin', true );
		$login_value = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
		$remember    = ! empty( $_POST['rememberme'] );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title>Вход — Horse Club OS</title>
			<link rel="stylesheet" href="<?php echo esc_url( plugins_url( 'assets/css/login.css', HCOS_PLUGIN_FILE ) ); ?>?ver=<?php echo esc_attr( HCOS_VERSION ); ?>">
		</head>
		<body class="hcos-login-page">
			<main class="hcos-login-shell">
				<section class="hcos-login-intro" aria-label="Horse Club OS">
					<div class="hcos-login-brand"><span>H</span><strong>Horse Club <small>OS</small></strong></div>
					<div><p class="hcos-login-kicker">Рабочая система клуба</p><h1>Всё важное для рабочего дня — в одном месте.</h1><p>Расписание, клиенты, лошади и посещаемость с доступом согласно вашей роли.</p></div>
					<p class="hcos-login-security">Защищённый вход для сотрудников Horse Club</p>
				</section>
				<section class="hcos-login-card">
					<div class="hcos-login-mobile-brand"><span>H</span><strong>Horse Club OS</strong></div>
					<h2>Вход в систему</h2>
					<p>Используйте рабочую учётную запись администратора или тренера.</p>
					<?php if ( $error ) : ?><div class="hcos-login-error" role="alert"><?php echo esc_html( $error ); ?></div><?php endif; ?>
					<form method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>">
						<input type="hidden" name="hcos_action" value="<?php echo esc_attr( self::ACTION ); ?>">
						<?php wp_nonce_field( self::ACTION, 'hcos_login_nonce' ); ?>
						<label for="hcos-log">Логин или email</label>
						<input id="hcos-log" name="log" type="text" value="<?php echo esc_attr( $login_value ); ?>" autocomplete="username" autocapitalize="none" required autofocus>
						<label for="hcos-pwd">Пароль</label>
						<input id="hcos-pwd" name="pwd" type="password" autocomplete="current-password" required>
						<div class="hcos-login-options"><label><input type="checkbox" name="rememberme" value="forever" <?php checked( $remember ); ?>> <span>Запомнить меня</span></label><a href="<?php echo esc_url( wp_lostpassword_url( home_url( '/' ) ) ); ?>">Забыли пароль?</a></div>
						<button type="submit">Войти</button>
					</form>
					<p class="hcos-login-help">Нет доступа? Обратитесь к администратору клуба.</p>
				</section>
			</main>
		</body>
		</html>
		<?php
		exit;
	}
}
