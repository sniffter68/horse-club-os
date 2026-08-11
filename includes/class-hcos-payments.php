<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Payments {
	private static $previous_targets = array();
	private static $deleted_targets  = array();

	public static function init() {
		add_filter( 'acf/validate_value/name=payment_purpose_type', array( __CLASS__, 'validate_purpose' ), 10, 4 );
		add_filter( 'acf/update_value/name=payment_membership', array( __CLASS__, 'remember_previous_target' ), 10, 4 );
		add_filter( 'acf/update_value/name=payment_booking', array( __CLASS__, 'remember_previous_target' ), 10, 4 );
		add_action( 'acf/save_post', array( __CLASS__, 'prepare_payment' ), 30 );
		add_action( 'acf/save_post', array( __CLASS__, 'recalculate_saved_object' ), 40 );
		add_action( 'transition_post_status', array( __CLASS__, 'payment_status_changed' ), 20, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'remember_deleted_payment' ), 10, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'recalculate_deleted_payment' ), 10, 2 );
	}

	public static function validate_purpose( $valid, $value, $field, $input ) {
		if ( true !== $valid || ! isset( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) {
			return $valid;
		}

		$acf           = wp_unslash( $_POST['acf'] );
		$type          = sanitize_key( $value );
		$membership_id = isset( $acf['field_hcos_payment_membership'] ) ? absint( $acf['field_hcos_payment_membership'] ) : 0;
		$booking_id    = isset( $acf['field_hcos_payment_booking'] ) ? absint( $acf['field_hcos_payment_booking'] ) : 0;
		$purpose       = isset( $acf['field_hcos_payment_purpose'] ) ? trim( sanitize_text_field( $acf['field_hcos_payment_purpose'] ) ) : '';

		if ( 'membership' === $type && ( ! $membership_id || $booking_id ) ) {
			return 'Для платежа за абонемент выберите только абонемент.';
		}
		if ( 'booking' === $type && ( ! $booking_id || $membership_id ) ) {
			return 'Для платежа за занятие выберите только запись на занятие.';
		}
		if ( 'other' === $type && ( '' === $purpose || $membership_id || $booking_id ) ) {
			return 'Для другого назначения заполните текст назначения и очистите связи с абонементом и записью.';
		}

		return $valid;
	}

	public static function remember_previous_target( $value, $post_id, $field, $original ) {
		if ( ! is_numeric( $post_id ) ) {
			return $value;
		}

		$post_id = (int) $post_id;
		if ( ! isset( self::$previous_targets[ $post_id ] ) ) {
			self::$previous_targets[ $post_id ] = array(
				'membership' => absint( get_post_meta( $post_id, 'payment_membership', true ) ),
				'booking'    => absint( get_post_meta( $post_id, 'payment_booking', true ) ),
			);
		}

		return $value;
	}

	public static function prepare_payment( $post_id ) {
		if ( ! is_numeric( $post_id ) || 'payments' !== get_post_type( $post_id ) ) {
			return;
		}

		$post_id = (int) $post_id;
		if ( ! get_post_meta( $post_id, 'payment_author', true ) ) {
			update_field( 'field_hcos_payment_author', get_current_user_id(), $post_id );
		}

		self::fill_payer( $post_id );
		self::update_payment_title( $post_id );
		self::recalculate_payment_targets( $post_id );

		if ( isset( self::$previous_targets[ $post_id ] ) ) {
			self::recalculate_target( 'membership', self::$previous_targets[ $post_id ]['membership'] );
			self::recalculate_target( 'booking', self::$previous_targets[ $post_id ]['booking'] );
			unset( self::$previous_targets[ $post_id ] );
		}
	}

	public static function recalculate_saved_object( $post_id ) {
		if ( ! is_numeric( $post_id ) ) {
			return;
		}

		$post_type = get_post_type( $post_id );
		if ( 'memberships' === $post_type ) {
			self::recalculate_membership( (int) $post_id );
		} elseif ( 'bookings' === $post_type ) {
			self::recalculate_booking( (int) $post_id );
		}
	}

	private static function fill_payer( $payment_id ) {
		if ( get_post_meta( $payment_id, 'payment_payer', true ) ) {
			return;
		}

		$payer_id      = 0;
		$membership_id = absint( get_post_meta( $payment_id, 'payment_membership', true ) );
		$booking_id    = absint( get_post_meta( $payment_id, 'payment_booking', true ) );
		if ( $membership_id ) {
			$payer_id = absint( get_post_meta( $membership_id, 'membership_payer', true ) );
			if ( ! $payer_id ) {
				$client_id = absint( get_post_meta( $membership_id, 'membership_client', true ) );
				$payer_id  = self::resolve_client_payer( $client_id );
			}
		} elseif ( $booking_id ) {
			$payer_id = absint( get_post_meta( $booking_id, 'booking_payer', true ) );
			if ( ! $payer_id ) {
				$rider_id = absint( get_post_meta( $booking_id, 'booking_rider', true ) );
				$payer_id = self::resolve_client_payer( $rider_id );
			}
		}

		if ( $payer_id ) {
			update_field( 'field_hcos_payment_payer', $payer_id, $payment_id );
		}
	}

	private static function resolve_client_payer( $client_id ) {
		$client_id = absint( $client_id );
		if ( ! $client_id ) {
			return 0;
		}

		$payer_id = absint( get_post_meta( $client_id, 'client_payer', true ) );

		return $payer_id ?: $client_id;
	}

	private static function update_payment_title( $payment_id ) {
		$status = (string) get_post_meta( $payment_id, 'payment_status', true );
		$amount = (float) get_post_meta( $payment_id, 'payment_amount', true );
		$date   = function_exists( 'get_field' ) ? (string) get_field( 'payment_date', $payment_id ) : (string) get_post_meta( $payment_id, 'payment_date', true );
		$label  = 'refund' === $status ? 'Возврат' : 'Платёж';
		$title  = sprintf( '%s %s ₽ — %s', $label, number_format_i18n( $amount, 2 ), $date ?: current_time( 'Y-m-d' ) );

		if ( get_the_title( $payment_id ) === $title ) {
			return;
		}

		remove_action( 'acf/save_post', array( __CLASS__, 'prepare_payment' ), 30 );
		wp_update_post( array( 'ID' => $payment_id, 'post_title' => $title ) );
		add_action( 'acf/save_post', array( __CLASS__, 'prepare_payment' ), 30 );
	}

	public static function payment_status_changed( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status || ! $post instanceof WP_Post || 'payments' !== $post->post_type ) {
			return;
		}

		self::recalculate_payment_targets( $post->ID );
	}

	public static function remember_deleted_payment( $post_id, $post ) {
		if ( ! $post instanceof WP_Post || 'payments' !== $post->post_type ) {
			return;
		}

		self::$deleted_targets[ (int) $post_id ] = array(
			'membership' => absint( get_post_meta( $post_id, 'payment_membership', true ) ),
			'booking'    => absint( get_post_meta( $post_id, 'payment_booking', true ) ),
		);
	}

	public static function recalculate_deleted_payment( $post_id, $post ) {
		$post_id = (int) $post_id;
		if ( ! isset( self::$deleted_targets[ $post_id ] ) ) {
			return;
		}

		self::recalculate_target( 'membership', self::$deleted_targets[ $post_id ]['membership'] );
		self::recalculate_target( 'booking', self::$deleted_targets[ $post_id ]['booking'] );
		unset( self::$deleted_targets[ $post_id ] );
	}

	private static function recalculate_payment_targets( $payment_id ) {
		self::recalculate_target( 'membership', absint( get_post_meta( $payment_id, 'payment_membership', true ) ) );
		self::recalculate_target( 'booking', absint( get_post_meta( $payment_id, 'payment_booking', true ) ) );
	}

	private static function recalculate_target( $type, $target_id ) {
		$target_id = absint( $target_id );
		if ( ! $target_id ) {
			return;
		}

		if ( 'membership' === $type && 'memberships' === get_post_type( $target_id ) ) {
			self::recalculate_membership( $target_id );
		} elseif ( 'booking' === $type && 'bookings' === get_post_type( $target_id ) ) {
			self::recalculate_booking( $target_id );
		}
	}

	private static function calculate_money( $meta_key, $target_id ) {
		$payment_ids = get_posts(
			array(
				'post_type'      => 'payments',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => $meta_key,
				'meta_value'     => $target_id,
			)
		);

		$paid = 0.0;
		$refund = 0.0;
		foreach ( $payment_ids as $payment_id ) {
			$status = (string) get_post_meta( $payment_id, 'payment_status', true );
			$amount = (float) get_post_meta( $payment_id, 'payment_amount', true );
			if ( 'paid' === $status ) {
				$paid += $amount;
			} elseif ( 'refund' === $status ) {
				$refund += $amount;
			}
		}

		return array(
			'net'    => $paid - $refund,
			'refund' => $refund,
		);
	}

	private static function recalculate_membership( $membership_id ) {
		$money    = self::calculate_money( 'payment_membership', $membership_id );
		$expected = (float) get_post_meta( $membership_id, 'membership_price', true );
		$debt     = max( 0, $expected - $money['net'] );
		$status   = self::payment_state( $expected, $money['net'], $money['refund'] );

		update_field( 'field_hcos_membership_paid_amount', $money['net'], $membership_id );
		update_field( 'field_hcos_membership_debt_amount', $debt, $membership_id );
		update_field( 'field_hcos_membership_payment_status', $status, $membership_id );
	}

	private static function recalculate_booking( $booking_id ) {
		if ( get_post_meta( $booking_id, 'booking_membership', true ) ) {
			update_field( 'field_hcos_booking_paid_amount', 0, $booking_id );
			update_field( 'field_hcos_booking_debt_amount', 0, $booking_id );
			update_field( 'field_hcos_booking_payment_status', 'membership', $booking_id );
			return;
		}

		$lesson_id = absint( get_post_meta( $booking_id, 'booking_lesson', true ) );
		$expected  = $lesson_id ? (float) get_post_meta( $lesson_id, 'lesson_price', true ) : 0.0;
		$money     = self::calculate_money( 'payment_booking', $booking_id );
		$debt      = max( 0, $expected - $money['net'] );
		$status    = self::payment_state( $expected, $money['net'], $money['refund'] );

		update_field( 'field_hcos_booking_paid_amount', $money['net'], $booking_id );
		update_field( 'field_hcos_booking_debt_amount', $debt, $booking_id );
		update_field( 'field_hcos_booking_payment_status', $status, $booking_id );
	}

	private static function payment_state( $expected, $net, $refund ) {
		if ( $refund > 0 ) {
			return 'refund';
		}
		if ( $expected <= 0 || $net >= $expected ) {
			return 'paid';
		}
		if ( $net > 0 ) {
			return 'partial';
		}

		return 'unpaid';
	}
}
