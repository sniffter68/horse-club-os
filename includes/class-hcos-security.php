<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Security {
	const MANAGER_ROLE = 'hcos_manager';
	const TRAINER_ROLE = 'hcos_trainer';

	private static $post_type_bases = array(
		'clients'        => 'client',
		'horses'         => 'horse',
		'trainers'       => 'trainer',
		'services'       => 'service',
		'lessons'        => 'lesson',
		'bookings'       => 'booking',
		'pricing_plans'  => 'pricing_plan',
		'memberships'    => 'membership',
		'membership_ops' => 'membership_op',
		'payments'       => 'payment',
		'hcos_audit'     => 'audit_entry',
	);

	private static $trainer_post_types = array( 'clients', 'horses', 'trainers', 'services', 'lessons', 'bookings' );

	private static $sensitive_fields = array(
		'client_medical_notes',
		'client_admin_notes',
		'horse_notes',
		'trainer_payment_scheme',
		'trainer_rate',
		'trainer_admin_notes',
		'service_admin_notes',
		'lesson_comment',
		'booking_admin_notes',
		'pricing_plan_admin_notes',
	);

	private static $financial_fields = array(
		'trainer_payment_scheme',
		'trainer_rate',
		'service_price',
		'lesson_price',
		'booking_payment_status',
		'booking_paid_amount',
		'booking_debt_amount',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_notices', array( __CLASS__, 'backup_notice' ) );

		foreach ( self::$sensitive_fields as $field_name ) {
			add_filter( 'acf/prepare_field/name=' . $field_name, array( __CLASS__, 'prepare_sensitive_field' ) );
		}
		foreach ( self::$financial_fields as $field_name ) {
			add_filter( 'acf/prepare_field/name=' . $field_name, array( __CLASS__, 'prepare_financial_field' ) );
		}

		foreach ( array_keys( self::$post_type_bases ) as $post_type ) {
			add_filter( 'rest_prepare_' . $post_type, array( __CLASS__, 'filter_rest_response' ), 10, 3 );
			add_filter( 'rest_pre_insert_' . $post_type, array( __CLASS__, 'protect_rest_request' ), 10, 2 );
		}
	}

	public static function post_type_capabilities( $post_type ) {
		if ( 'hcos_audit' === $post_type ) {
			return array(
				'edit_post'              => 'edit_hcos_audit_entry',
				'read_post'              => 'read_hcos_audit_entry',
				'delete_post'            => 'delete_hcos_audit_entry',
				'edit_posts'             => 'hcos_view_audit',
				'edit_others_posts'      => 'hcos_manage_audit',
				'publish_posts'          => 'hcos_manage_audit',
				'read_private_posts'     => 'hcos_view_audit',
				'delete_posts'           => 'hcos_manage_audit',
				'delete_private_posts'   => 'hcos_manage_audit',
				'delete_published_posts' => 'hcos_manage_audit',
				'delete_others_posts'    => 'hcos_manage_audit',
				'edit_private_posts'     => 'hcos_manage_audit',
				'edit_published_posts'   => 'hcos_manage_audit',
				'create_posts'           => 'do_not_allow',
			);
		}

		$base   = isset( self::$post_type_bases[ $post_type ] ) ? self::$post_type_bases[ $post_type ] : $post_type;
		$plural = $base . 's';

		return array(
			'edit_post'              => 'edit_hcos_' . $base,
			'read_post'              => 'read_hcos_' . $base,
			'delete_post'            => 'delete_hcos_' . $base,
			'edit_posts'             => 'edit_hcos_' . $plural,
			'edit_others_posts'      => 'edit_others_hcos_' . $plural,
			'publish_posts'          => 'publish_hcos_' . $plural,
			'read_private_posts'     => 'read_private_hcos_' . $plural,
			'delete_posts'           => 'delete_hcos_' . $plural,
			'delete_private_posts'   => 'delete_private_hcos_' . $plural,
			'delete_published_posts' => 'delete_published_hcos_' . $plural,
			'delete_others_posts'    => 'delete_others_hcos_' . $plural,
			'edit_private_posts'     => 'edit_private_hcos_' . $plural,
			'edit_published_posts'   => 'edit_published_hcos_' . $plural,
			'create_posts'           => 'edit_hcos_' . $plural,
		);
	}

	public static function install_roles() {
		add_role( self::MANAGER_ROLE, 'Руководитель Horse Club OS', array( 'read' => true, 'upload_files' => true ) );
		add_role( self::TRAINER_ROLE, 'Тренер Horse Club OS', array( 'read' => true, 'upload_files' => true ) );

		$administrator = get_role( 'administrator' );
		$manager       = get_role( self::MANAGER_ROLE );
		$trainer       = get_role( self::TRAINER_ROLE );
		if ( $manager ) {
			$manager->add_cap( 'upload_files' );
		}
		if ( $trainer ) {
			$trainer->add_cap( 'upload_files' );
		}

		foreach ( self::$post_type_bases as $post_type => $unused ) {
			$caps = array_values( self::post_type_capabilities( $post_type ) );
			foreach ( array_unique( $caps ) as $capability ) {
				if ( $administrator && 'do_not_allow' !== $capability ) {
					$administrator->add_cap( $capability );
				}
				if ( $manager && 'hcos_audit' !== $post_type ) {
					$manager->add_cap( $capability );
				}
			}

			if ( $trainer && in_array( $post_type, self::$trainer_post_types, true ) ) {
				foreach ( array_unique( $caps ) as $capability ) {
					if ( false === strpos( $capability, 'delete_' ) ) {
						$trainer->add_cap( $capability );
					}
				}
			}
		}

		foreach ( array( $administrator, $manager ) as $role ) {
			if ( $role ) {
				$role->add_cap( 'hcos_view_finances' );
				$role->add_cap( 'hcos_view_sensitive_notes' );
				$role->add_cap( 'hcos_view_audit' );
			}
		}
		if ( $administrator ) {
			$administrator->add_cap( 'hcos_manage_audit' );
		}

		update_option( 'hcos_roles_version', HCOS_VERSION, false );
	}

	public static function maybe_upgrade() {
		if ( HCOS_VERSION !== get_option( 'hcos_roles_version' ) ) {
			self::install_roles();
		}
	}

	public static function prepare_sensitive_field( $field ) {
		return current_user_can( 'hcos_view_sensitive_notes' ) ? $field : false;
	}

	public static function prepare_financial_field( $field ) {
		return current_user_can( 'hcos_view_finances' ) ? $field : false;
	}

	public static function protect_rest_request( $prepared_post, $request ) {
		$acf = $request->get_param( 'acf' );
		if ( ! is_array( $acf ) ) {
			return $prepared_post;
		}
		$blocked = array();
		if ( ! current_user_can( 'hcos_view_sensitive_notes' ) ) {
			$blocked = self::$sensitive_fields;
		}
		if ( ! current_user_can( 'hcos_view_finances' ) ) {
			$blocked = array_merge( $blocked, self::$financial_fields );
		}
		foreach ( $blocked as $field_name ) {
			unset( $acf[ $field_name ] );
		}
		$request->set_param( 'acf', $acf );
		return $prepared_post;
	}

	public static function filter_rest_response( $response, $post, $request ) {
		if ( ! isset( $response->data['acf'] ) || ! is_array( $response->data['acf'] ) ) {
			return $response;
		}

		$hidden_fields = array();
		if ( ! current_user_can( 'hcos_view_sensitive_notes' ) ) {
			$hidden_fields = self::$sensitive_fields;
		}
		if ( ! current_user_can( 'hcos_view_finances' ) ) {
			$hidden_fields = array_merge( $hidden_fields, self::$financial_fields );
		}
		foreach ( $hidden_fields as $field_name ) {
			unset( $response->data['acf'][ $field_name ] );
		}

		return $response;
	}

	public static function is_sensitive_field( $field_name ) {
		return in_array( $field_name, self::$sensitive_fields, true ) || in_array( $field_name, self::$financial_fields, true ) || 0 === strpos( $field_name, 'payment_' ) || 0 === strpos( $field_name, 'membership_' );
	}

	public static function register_admin_page() {
		add_management_page(
			'Безопасность Horse Club OS',
			'Безопасность Horse Club OS',
			'manage_options',
			'hcos-security',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'horse-club-os' ) );
		}
		?>
		<div class="wrap">
			<h1>Безопасность Horse Club OS</h1>
			<h2>Роли</h2>
			<p><strong>Администратор:</strong> полное управление WordPress и CRM.</p>
			<p><strong>Руководитель Horse Club OS:</strong> все данные CRM, финансы, чувствительные заметки и журнал изменений.</p>
			<p><strong>Тренер Horse Club OS:</strong> рабочие карточки, расписание и посещаемость без финансов, медицинских и внутренних заметок; удаление записей запрещено.</p>
			<h2>Резервное копирование</h2>
			<p>Полная резервная копия должна включать базу данных WordPress и каталог <code>wp-content</code>. Для локального Docker настройте резервное копирование тома базы данных и папки проекта вне контейнера.</p>
			<p>После настройки выполните пробное восстановление в отдельный тестовый контейнер. Плагин намеренно не хранит копии персональных данных в общедоступной папке uploads.</p>
		</div>
		<?php
	}

	public static function backup_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'hcos' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>Horse Club OS:</strong> настройте внешнюю резервную копию базы данных и wp-content, затем проверьте восстановление. Инструкция: Инструменты → Безопасность Horse Club OS.</p></div>';
	}
}
