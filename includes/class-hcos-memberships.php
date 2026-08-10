<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Memberships {
	private static $previous_memberships = array();
	private static $creating_initial     = false;

	public static function init() {
		add_action( 'acf/save_post', array( __CLASS__, 'prepare_membership' ), 30 );
		add_action( 'acf/save_post', array( __CLASS__, 'prepare_operation' ), 30 );
		add_filter( 'acf/update_value/name=membership_op_membership', array( __CLASS__, 'remember_previous_membership' ), 10, 4 );
		add_filter( 'acf/validate_value/name=membership_op_amount', array( __CLASS__, 'validate_operation_amount' ), 10, 4 );
		add_action( 'trashed_post', array( __CLASS__, 'recalculate_from_operation' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'recalculate_from_operation' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'recalculate_before_delete' ) );
		add_action( 'admin_init', array( __CLASS__, 'backfill_existing_memberships' ) );
	}

	public static function backfill_existing_memberships() {
		if ( HCOS_VERSION === get_option( 'hcos_membership_ops_backfilled' ) || ! function_exists( 'update_field' ) ) {
			return;
		}

		$membership_ids = get_posts(
			array(
				'post_type'      => 'memberships',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $membership_ids as $membership_id ) {
			self::prepare_membership( $membership_id );
		}
		update_option( 'hcos_membership_ops_backfilled', HCOS_VERSION, false );
	}

	public static function remember_previous_membership( $value, $post_id, $field, $original ) {
		if ( is_numeric( $post_id ) ) {
			self::$previous_memberships[ (int) $post_id ] = absint( get_post_meta( $post_id, 'membership_op_membership', true ) );
		}
		return $value;
	}

	public static function validate_operation_amount( $valid, $value, $field, $input ) {
		if ( true !== $valid || ! isset( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) {
			return $valid;
		}

		$acf           = wp_unslash( $_POST['acf'] );
		$type          = isset( $acf['field_hcos_membership_op_type'] ) ? sanitize_key( $acf['field_hcos_membership_op_type'] ) : '';
		$membership_id = isset( $acf['field_hcos_membership_op_membership'] ) ? absint( $acf['field_hcos_membership_op_membership'] ) : 0;
		$amount        = (float) $value;

		if ( 'adjustment' !== $type && $amount <= 0 ) {
			return 'Для этой операции количество должно быть больше нуля.';
		}
		if ( 'adjustment' === $type && 0.0 === $amount ) {
			return 'Корректировка не может быть равна нулю.';
		}

		if ( 'debit' !== $type || ! $membership_id ) {
			return $valid;
		}

		$status = (string) get_post_meta( $membership_id, 'membership_status', true );
		if ( 'active' !== $status ) {
			return 'Списание возможно только с активного абонемента.';
		}

		$post_id   = isset( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0;
		$totals    = self::calculate_totals( $membership_id, $post_id );
		$available = $totals['credited'] - $totals['debited'];

		return $amount > $available ? sprintf( 'Недостаточно занятий: доступно %s.', number_format_i18n( $available, 0 ) ) : $valid;
	}

	public static function prepare_membership( $post_id ) {
		if ( self::$creating_initial || ! is_numeric( $post_id ) || 'memberships' !== get_post_type( $post_id ) ) {
			return;
		}

		$limit = absint( get_post_meta( $post_id, 'membership_lesson_limit', true ) );
		if ( $limit && ! self::has_initial_credit( $post_id ) ) {
			self::create_initial_credit( $post_id, $limit );
		}

		self::recalculate( $post_id );
	}

	public static function prepare_operation( $post_id ) {
		if ( ! is_numeric( $post_id ) || 'membership_ops' !== get_post_type( $post_id ) ) {
			return;
		}

		if ( ! get_post_meta( $post_id, 'membership_op_date', true ) ) {
			update_field( 'field_hcos_membership_op_date', current_time( 'Ymd' ), $post_id );
		}
		if ( ! get_post_meta( $post_id, 'membership_op_author', true ) ) {
			update_field( 'field_hcos_membership_op_author', get_current_user_id(), $post_id );
		}

		$membership_id = absint( get_post_meta( $post_id, 'membership_op_membership', true ) );
		self::update_operation_title( $post_id );
		self::recalculate( $membership_id );

		$previous_id = isset( self::$previous_memberships[ (int) $post_id ] ) ? self::$previous_memberships[ (int) $post_id ] : 0;
		if ( $previous_id && $previous_id !== $membership_id ) {
			self::recalculate( $previous_id );
		}
	}

	public static function recalculate( $membership_id ) {
		$membership_id = absint( $membership_id );
		if ( ! $membership_id || 'memberships' !== get_post_type( $membership_id ) ) {
			return;
		}

		$totals = self::calculate_totals( $membership_id );

		$credited = $totals['credited'];
		$debited  = $totals['debited'];
		$balance  = $credited - $debited;
		update_field( 'field_hcos_membership_credited', $credited, $membership_id );
		update_field( 'field_hcos_membership_debited', $debited, $membership_id );
		update_field( 'field_hcos_membership_balance', $balance, $membership_id );

		$status = (string) get_post_meta( $membership_id, 'membership_status', true );
		if ( 'active' === $status && $balance <= 0 ) {
			update_field( 'field_hcos_membership_status', 'exhausted', $membership_id );
		} elseif ( 'exhausted' === $status && $balance > 0 ) {
			update_field( 'field_hcos_membership_status', 'active', $membership_id );
		}
	}

	private static function calculate_totals( $membership_id, $exclude_operation_id = 0 ) {
		$operation_ids = get_posts(
			array(
				'post_type'      => 'membership_ops',
				// Only confirmed operations affect the balance. ACF can retain a
				// rejected operation as a draft after a validation error.
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => 'membership_op_membership',
				'meta_value'     => $membership_id,
			)
		);

		$credited = 0.0;
		$debited  = 0.0;
		foreach ( $operation_ids as $operation_id ) {
			if ( $exclude_operation_id && (int) $operation_id === (int) $exclude_operation_id ) {
				continue;
			}

			$type   = (string) get_post_meta( $operation_id, 'membership_op_type', true );
			$amount = (float) get_post_meta( $operation_id, 'membership_op_amount', true );
			if ( 'debit' === $type ) {
				$debited += abs( $amount );
			} elseif ( 'adjustment' === $type && $amount < 0 ) {
				$debited += abs( $amount );
			} elseif ( in_array( $type, array( 'credit', 'refund', 'adjustment' ), true ) ) {
				$credited += $amount;
			}
		}

		return array(
			'credited' => $credited,
			'debited'  => $debited,
		);
	}

	public static function recalculate_from_operation( $post_id ) {
		if ( 'membership_ops' === get_post_type( $post_id ) ) {
			self::recalculate( get_post_meta( $post_id, 'membership_op_membership', true ) );
		}
	}

	public static function recalculate_before_delete( $post_id ) {
		if ( 'membership_ops' !== get_post_type( $post_id ) ) {
			return;
		}
		$membership_id = absint( get_post_meta( $post_id, 'membership_op_membership', true ) );
		add_action(
			'deleted_post',
			static function () use ( $membership_id ) {
				self::recalculate( $membership_id );
			},
			10,
			0
		);
	}

	private static function has_initial_credit( $membership_id ) {
		return (bool) get_posts(
			array(
				'post_type'      => 'membership_ops',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => 'membership_op_membership', 'value' => $membership_id, 'compare' => '=' ),
					array( 'key' => '_hcos_initial_credit', 'value' => '1', 'compare' => '=' ),
				),
			)
		);
	}

	private static function create_initial_credit( $membership_id, $limit ) {
		self::$creating_initial = true;
		$operation_id = wp_insert_post(
			array(
				'post_type'   => 'membership_ops',
				'post_status' => 'publish',
				'post_title'  => 'Начальное начисление',
			)
		);

		if ( ! is_wp_error( $operation_id ) && $operation_id ) {
			update_field( 'field_hcos_membership_op_membership', $membership_id, $operation_id );
			update_field( 'field_hcos_membership_op_type', 'credit', $operation_id );
			update_field( 'field_hcos_membership_op_amount', $limit, $operation_id );
			update_field( 'field_hcos_membership_op_date', current_time( 'Ymd' ), $operation_id );
			update_field( 'field_hcos_membership_op_author', get_current_user_id(), $operation_id );
			update_field( 'field_hcos_membership_op_reason', 'Автоматическое начисление при создании абонемента.', $operation_id );
			update_post_meta( $operation_id, '_hcos_initial_credit', 1 );
		}
		self::$creating_initial = false;
	}

	private static function update_operation_title( $post_id ) {
		$type_labels = array( 'credit' => 'Начисление', 'debit' => 'Списание', 'refund' => 'Возврат', 'adjustment' => 'Корректировка' );
		$type        = (string) get_post_meta( $post_id, 'membership_op_type', true );
		$amount      = get_post_meta( $post_id, 'membership_op_amount', true );
		$date        = function_exists( 'get_field' ) ? get_field( 'membership_op_date', $post_id ) : '';
		$title       = trim( ( isset( $type_labels[ $type ] ) ? $type_labels[ $type ] : 'Операция' ) . ' ' . $amount . ' — ' . $date );

		if ( get_the_title( $post_id ) !== $title ) {
			wp_update_post( array( 'ID' => (int) $post_id, 'post_title' => $title ) );
		}
	}
}
