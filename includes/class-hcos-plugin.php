<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Plugin {
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function init() {
		HCOS_Security::init();
		HCOS_Audit::init();
		HCOS_Post_Types::init();
		HCOS_ACF::init();
		HCOS_Admin::init();
		HCOS_Dashboard::init();
		HCOS_Clients_Screen::init();
		HCOS_Lesson_Validation::init();
		HCOS_Calendar::init();
		HCOS_Memberships::init();
		HCOS_Memberships_Screen::init();
		HCOS_Bookings::init();
		HCOS_Pricing_Plans::init();
		HCOS_Payments::init();
		HCOS_Attendance::init();
		HCOS_Reports::init();
	}

	public static function activate() {
		HCOS_Security::install_roles();
		HCOS_Post_Types::register_post_types();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}
}
