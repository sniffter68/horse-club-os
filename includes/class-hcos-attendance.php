<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Attendance {
	public static function init() {
		add_action( 'acf/save_post', array( __CLASS__, 'snapshot_booking_rules' ), 45 );
		add_action( 'acf/save_post', array( __CLASS__, 'process_booking' ), 50 );
	}

	public static function snapshot_booking_rules( $post_id ) {
		if ( ! is_numeric( $post_id ) || 'bookings' !== get_post_type( $post_id ) || get_post_meta( $post_id, '_hcos_booking_policy_snapshotted', true ) ) {
			return;
		}

		$membership_id = absint( get_post_meta( $post_id, 'booking_membership', true ) );
		$lesson_id     = absint( get_post_meta( $post_id, 'booking_lesson', true ) );
		$service_id    = $lesson_id ? absint( get_post_meta( $lesson_id, 'lesson_service', true ) ) : 0;
		$hours         = '';
		if ( $membership_id ) {
			$hours = get_post_meta( $membership_id, 'membership_cancellation_hours_snapshot', true );
		}
		if ( '' === (string) $hours && $service_id ) {
			$hours = get_post_meta( $service_id, 'service_free_cancellation_hours', true );
		}

		$late_policy = $service_id ? (string) get_post_meta( $service_id, 'service_late_cancel_policy', true ) : '';
		if ( ! in_array( $late_policy, array( 'charge_full', 'charge_partial', 'no_charge', 'manual' ), true ) ) {
			$late_policy = 'manual';
		}

		update_field( 'field_hcos_booking_cancellation_hours_snapshot', max( 0, (int) $hours ), $post_id );
		update_field( 'field_hcos_booking_late_cancel_policy_snapshot', $late_policy, $post_id );
		update_post_meta( $post_id, '_hcos_booking_policy_snapshotted', current_time( 'mysql' ) );
	}

	public static function process_booking( $post_id ) {
		if ( ! is_numeric( $post_id ) || 'bookings' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return;
		}

		$post_id       = (int) $post_id;
		$membership_id = absint( get_post_meta( $post_id, 'booking_membership', true ) );
		if ( ! $membership_id ) {
			self::set_result( $post_id, 'Абонемент не выбран: денежная оплата обрабатывается отдельно.' );
			return;
		}

		$status = (string) get_post_meta( $post_id, 'booking_status', true );
		if ( in_array( $status, array( 'cancelled_by_client', 'cancelled_by_club' ), true ) && ! get_post_meta( $post_id, 'booking_cancelled_at', true ) ) {
			update_field( 'field_hcos_booking_cancelled_at', current_time( 'Y-m-d H:i:s' ), $post_id );
		}

		$decision = self::get_decision( $post_id );
		if ( 'none' === $decision ) {
			$net_charge = self::get_booking_net_charge( $post_id, $membership_id );
			if ( $net_charge > 0 ) {
				$operation_id = self::create_operation( $post_id, $membership_id, 'refund', 'Автоматический возврат после сброса результата посещения.' );
				if ( $operation_id ) {
					update_field( 'field_hcos_booking_membership_refund_operation', $operation_id, $post_id );
					self::set_result( $post_id, 'Результат занятия сброшен: возвращено 1 занятие на абонемент.' );
				}
				return;
			}

			self::set_result( $post_id, 'Ожидается итог занятия: операция не создана.' );
			return;
		}
		if ( 'manual' === $decision ) {
			self::set_result( $post_id, 'Требуется решение администратора: автоматическая операция не создана.' );
			return;
		}

		$net_charge = self::get_booking_net_charge( $post_id, $membership_id );
		if ( 'charge' === $decision ) {
			if ( $net_charge >= 1 ) {
				self::set_result( $post_id, 'Занятие уже списано с абонемента.' );
				return;
			}

			HCOS_Memberships::recalculate( $membership_id );
			$balance = (float) get_post_meta( $membership_id, 'membership_balance', true );
			$status  = (string) get_post_meta( $membership_id, 'membership_status', true );
			if ( 'active' !== $status || $balance < 1 ) {
				self::set_result( $post_id, 'Списание не выполнено: абонемент не активен или нет доступных занятий.' );
				return;
			}

			$operation_id = self::create_operation( $post_id, $membership_id, 'debit', 'Автоматическое списание по посещаемости.' );
			if ( $operation_id ) {
				update_field( 'field_hcos_booking_membership_operation', $operation_id, $post_id );
				self::set_result( $post_id, 'Списано 1 занятие с абонемента.' );
			}
			return;
		}

		if ( $net_charge <= 0 ) {
			self::set_result( $post_id, 'Списание отсутствует: возврат не требуется.' );
			return;
		}

		$operation_id = self::create_operation( $post_id, $membership_id, 'refund', 'Автоматический возврат по отмене или уважительной причине.' );
		if ( $operation_id ) {
			update_field( 'field_hcos_booking_membership_refund_operation', $operation_id, $post_id );
			self::set_result( $post_id, 'Возвращено 1 занятие на абонемент.' );
		}
	}

	private static function get_decision( $booking_id ) {
		$override = (string) get_post_meta( $booking_id, 'booking_charge_policy', true );
		if ( 'charge' === $override ) {
			return 'charge';
		}
		if ( 'no_charge' === $override ) {
			return 'no_charge';
		}
		if ( 'manual' === $override ) {
			return 'manual';
		}

		$attendance = (string) get_post_meta( $booking_id, 'booking_attendance', true );
		$status     = (string) get_post_meta( $booking_id, 'booking_status', true );
		if ( in_array( $attendance, array( 'present', 'no_show' ), true ) ) {
			return 'charge';
		}
		if ( 'excused' === $attendance || 'cancelled_by_club' === $status ) {
			return 'no_charge';
		}
		if ( 'cancelled_by_client' !== $status ) {
			return 'none';
		}

		if ( self::is_free_cancellation( $booking_id ) ) {
			return 'no_charge';
		}

		$late_policy = (string) get_post_meta( $booking_id, 'booking_late_cancel_policy_snapshot', true );
		if ( 'charge_full' === $late_policy ) {
			return 'charge';
		}
		if ( 'no_charge' === $late_policy ) {
			return 'no_charge';
		}

		return 'manual';
	}

	private static function is_free_cancellation( $booking_id ) {
		$lesson_id    = absint( get_post_meta( $booking_id, 'booking_lesson', true ) );
		$cancelled_at = function_exists( 'get_field' ) ? (string) get_field( 'booking_cancelled_at', $booking_id ) : '';
		$lesson_date  = $lesson_id && function_exists( 'get_field' ) ? (string) get_field( 'lesson_date', $lesson_id ) : '';
		$lesson_time  = $lesson_id && function_exists( 'get_field' ) ? (string) get_field( 'lesson_time', $lesson_id ) : '';
		if ( ! $cancelled_at || ! $lesson_date || ! $lesson_time ) {
			return false;
		}

		try {
			$cancelled = new DateTimeImmutable( $cancelled_at, wp_timezone() );
			$lesson    = new DateTimeImmutable( $lesson_date . ' ' . $lesson_time, wp_timezone() );
		} catch ( Exception $exception ) {
			return false;
		}

		$hours_before = ( $lesson->getTimestamp() - $cancelled->getTimestamp() ) / HOUR_IN_SECONDS;
		$free_hours   = max( 0, (int) get_post_meta( $booking_id, 'booking_cancellation_hours_snapshot', true ) );
		return $hours_before >= $free_hours;
	}

	private static function get_booking_net_charge( $booking_id, $membership_id ) {
		$operation_ids = get_posts(
			array(
				'post_type'      => 'membership_ops',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => '_hcos_booking_id', 'value' => $booking_id, 'compare' => '=' ),
					array( 'key' => 'membership_op_membership', 'value' => $membership_id, 'compare' => '=' ),
				),
			)
		);

		$net = 0.0;
		foreach ( $operation_ids as $operation_id ) {
			$type = (string) get_post_meta( $operation_id, 'membership_op_type', true );
			if ( 'debit' === $type ) {
				$net += abs( (float) get_post_meta( $operation_id, 'membership_op_amount', true ) );
			} elseif ( 'refund' === $type ) {
				$net -= abs( (float) get_post_meta( $operation_id, 'membership_op_amount', true ) );
			}
		}

		return $net;
	}

	private static function create_operation( $booking_id, $membership_id, $type, $reason ) {
		$operation_id = wp_insert_post(
			array(
				'post_type'   => 'membership_ops',
				'post_status' => 'publish',
				'post_title'  => 'Автоматическая операция по записи #' . $booking_id,
			)
		);
		if ( is_wp_error( $operation_id ) || ! $operation_id ) {
			self::set_result( $booking_id, 'Не удалось создать операцию абонемента.' );
			return 0;
		}

		$lesson_id = absint( get_post_meta( $booking_id, 'booking_lesson', true ) );
		update_field( 'field_hcos_membership_op_membership', $membership_id, $operation_id );
		update_field( 'field_hcos_membership_op_type', $type, $operation_id );
		update_field( 'field_hcos_membership_op_amount', 1, $operation_id );
		update_field( 'field_hcos_membership_op_date', current_time( 'Ymd' ), $operation_id );
		update_field( 'field_hcos_membership_op_lesson', $lesson_id, $operation_id );
		update_field( 'field_hcos_membership_op_author', get_current_user_id(), $operation_id );
		update_field( 'field_hcos_membership_op_reason', $reason . ' Запись #' . $booking_id . '.', $operation_id );
		update_post_meta( $operation_id, '_hcos_booking_id', $booking_id );
		update_post_meta( $operation_id, '_hcos_attendance_operation', 1 );
		HCOS_Memberships::prepare_operation( $operation_id );

		return (int) $operation_id;
	}

	private static function set_result( $booking_id, $message ) {
		update_field( 'field_hcos_booking_charge_result', $message, $booking_id );
	}
}
