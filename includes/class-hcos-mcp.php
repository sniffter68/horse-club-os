<?php

defined( 'ABSPATH' ) || exit;

/**
 * WordPress Abilities API + MCP Adapter integration.
 *
 * The integration is deliberately read-only in its first iteration. This keeps
 * production data safe while allowing AI agents to inspect compact CRM state
 * without reading large PHP files or raw post meta dumps.
 */
final class HCOS_MCP {
	const CATEGORY = 'horse-club-os';

	public static function init() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
		add_action( 'init', array( __CLASS__, 'boot_adapter' ), 1 );
	}

	public static function boot_adapter() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			\WP\MCP\Core\McpAdapter::instance();
		}
	}

	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'Horse Club OS',
				'description' => 'Compact, permission-checked CRM diagnostics for Horse Club OS.',
			)
		);
	}

	public static function register_abilities() {
		self::register_ability(
			'health-check',
			'HCOS health check',
			'Return compact runtime and CRM counters for diagnostics.',
			array( __CLASS__, 'health_check' ),
			array( __CLASS__, 'can_manage' )
		);

		self::register_ability(
			'inspect-booking',
			'Inspect booking',
			'Return a compact booking, lesson, payment and debt summary.',
			array( __CLASS__, 'inspect_booking' ),
			array( __CLASS__, 'can_view_finances' ),
			self::id_schema( 'booking_id', 'Booking post ID.' )
		);

		self::register_ability(
			'inspect-client-relations',
			'Inspect client relations',
			'Return a compact client role, payer and representative relationship summary without contact details.',
			array( __CLASS__, 'inspect_client_relations' ),
			array( __CLASS__, 'can_read_clients' ),
			self::id_schema( 'client_id', 'Client post ID.' )
		);

		self::register_ability(
			'inspect-membership',
			'Inspect membership',
			'Return a compact membership balance, payment and operation summary.',
			array( __CLASS__, 'inspect_membership' ),
			array( __CLASS__, 'can_view_finances' ),
			self::id_schema( 'membership_id', 'Membership post ID.' )
		);
	}

	private static function register_ability( $slug, $label, $description, $execute_callback, $permission_callback, $input_schema = null ) {
		$args = array(
			'label'               => $label,
			'description'         => $description,
			'category'            => self::CATEGORY,
			'execute_callback'    => $execute_callback,
			'permission_callback' => $permission_callback,
			'meta'                => array(
				'mcp'         => array( 'public' => true ),
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		);

		if ( $input_schema ) {
			$args['input_schema'] = $input_schema;
		}

		wp_register_ability( 'hcos/' . $slug, $args );
	}

	private static function id_schema( $key, $description ) {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				$key => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => $description,
				),
			),
			'required'             => array( $key ),
			'additionalProperties' => false,
		);
	}

	public static function can_manage() {
		return current_user_can( 'manage_options' ) || current_user_can( 'hcos_view_finances' );
	}

	public static function can_view_finances() {
		return current_user_can( 'hcos_view_finances' );
	}

	public static function can_read_clients() {
		return current_user_can( 'edit_hcos_clients' ) || current_user_can( 'hcos_view_finances' );
	}

	public static function health_check() {
		$post_types = array( 'clients', 'horses', 'trainers', 'services', 'lessons', 'bookings', 'memberships', 'payments' );
		$counts     = array();
		foreach ( $post_types as $post_type ) {
			$count                = wp_count_posts( $post_type );
			$counts[ $post_type ] = $count && isset( $count->publish ) ? (int) $count->publish : 0;
		}

		return array(
			'hcos_version'        => HCOS_VERSION,
			'wordpress_version'   => get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
			'acf_available'       => function_exists( 'get_field' ),
			'abilities_available' => function_exists( 'wp_register_ability' ),
			'mcp_adapter_loaded'  => class_exists( '\\WP\\MCP\\Core\\McpAdapter' ),
			'counts'              => $counts,
		);
	}

	public static function inspect_booking( $input ) {
		$booking_id = isset( $input['booking_id'] ) ? absint( $input['booking_id'] ) : 0;
		if ( ! $booking_id || 'bookings' !== get_post_type( $booking_id ) ) {
			return new WP_Error( 'hcos_booking_not_found', 'Booking not found.' );
		}

		$lesson_id     = absint( get_post_meta( $booking_id, 'booking_lesson', true ) );
		$rider_id      = absint( get_post_meta( $booking_id, 'booking_rider', true ) );
		$payer_id      = absint( get_post_meta( $booking_id, 'booking_payer', true ) );
		$membership_id = absint( get_post_meta( $booking_id, 'booking_membership', true ) );

		return array(
			'id'             => $booking_id,
			'title'          => get_the_title( $booking_id ),
			'status'         => (string) get_post_meta( $booking_id, 'booking_status', true ),
			'attendance'     => (string) get_post_meta( $booking_id, 'booking_attendance', true ),
			'payment_status' => (string) get_post_meta( $booking_id, 'booking_payment_status', true ),
			'paid_amount'    => (float) get_post_meta( $booking_id, 'booking_paid_amount', true ),
			'debt_amount'    => (float) get_post_meta( $booking_id, 'booking_debt_amount', true ),
			'rider'          => self::post_ref( $rider_id ),
			'payer'          => self::post_ref( $payer_id ),
			'membership'     => self::post_ref( $membership_id ),
			'lesson'         => array(
				'id'     => $lesson_id,
				'date'   => $lesson_id ? (string) get_post_meta( $lesson_id, 'lesson_date', true ) : '',
				'time'   => $lesson_id ? substr( (string) get_post_meta( $lesson_id, 'lesson_time', true ), 0, 5 ) : '',
				'price'  => $lesson_id ? (float) get_post_meta( $lesson_id, 'lesson_price', true ) : 0.0,
				'status' => $lesson_id ? (string) get_post_meta( $lesson_id, 'lesson_status', true ) : '',
			),
		);
	}

	public static function inspect_client_relations( $input ) {
		$client_id = isset( $input['client_id'] ) ? absint( $input['client_id'] ) : 0;
		if ( ! $client_id || 'clients' !== get_post_type( $client_id ) ) {
			return new WP_Error( 'hcos_client_not_found', 'Client not found.' );
		}

		$roles = get_post_meta( $client_id, 'client_roles', true );
		if ( ! is_array( $roles ) ) {
			$roles = $roles ? array( (string) $roles ) : array();
		}

		$representatives = get_post_meta( $client_id, 'client_representatives', true );
		if ( ! is_array( $representatives ) ) {
			$representatives = $representatives ? array( $representatives ) : array();
		}

		return array(
			'id'              => $client_id,
			'name'            => get_the_title( $client_id ),
			'status'          => (string) get_post_meta( $client_id, 'client_status', true ),
			'roles'           => array_values( array_map( 'strval', $roles ) ),
			'primary_payer'   => self::post_ref( absint( get_post_meta( $client_id, 'client_payer', true ) ) ),
			'representatives' => array_values( array_filter( array_map( array( __CLASS__, 'post_ref' ), array_map( 'absint', $representatives ) ) ) ),
		);
	}

	public static function inspect_membership( $input ) {
		$membership_id = isset( $input['membership_id'] ) ? absint( $input['membership_id'] ) : 0;
		if ( ! $membership_id || 'memberships' !== get_post_type( $membership_id ) ) {
			return new WP_Error( 'hcos_membership_not_found', 'Membership not found.' );
		}

		$operation_ids = get_posts(
			array(
				'post_type'      => 'membership_ops',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => 'membership_op_membership',
				'meta_value'     => $membership_id,
			)
		);

		$ops = array( 'credit' => 0.0, 'debit' => 0.0, 'refund' => 0.0, 'adjustment' => 0.0 );
		foreach ( $operation_ids as $operation_id ) {
			$type = (string) get_post_meta( $operation_id, 'membership_op_type', true );
			if ( isset( $ops[ $type ] ) ) {
				$ops[ $type ] += (float) get_post_meta( $operation_id, 'membership_op_amount', true );
			}
		}

		return array(
			'id'             => $membership_id,
			'title'          => get_the_title( $membership_id ),
			'status'         => (string) get_post_meta( $membership_id, 'membership_status', true ),
			'client'         => self::post_ref( absint( get_post_meta( $membership_id, 'membership_client', true ) ) ),
			'payer'          => self::post_ref( absint( get_post_meta( $membership_id, 'membership_payer', true ) ) ),
			'lesson_limit'   => (int) get_post_meta( $membership_id, 'membership_lesson_limit', true ),
			'balance'        => (float) get_post_meta( $membership_id, 'membership_balance', true ),
			'price'          => (float) get_post_meta( $membership_id, 'membership_price', true ),
			'paid_amount'    => (float) get_post_meta( $membership_id, 'membership_paid_amount', true ),
			'debt_amount'    => (float) get_post_meta( $membership_id, 'membership_debt_amount', true ),
			'payment_status' => (string) get_post_meta( $membership_id, 'membership_payment_status', true ),
			'operations'     => $ops,
		);
	}

	public static function post_ref( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return null;
		}

		return array(
			'id'   => $post_id,
			'name' => get_the_title( $post_id ),
		);
	}
}
