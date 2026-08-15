<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Attendance {
	public static function init() {
		add_filter( 'acf/validate_value/name=booking_attendance', array( __CLASS__, 'validate_attendance_time' ), 10, 4 );
		add_filter( 'acf/update_value/name=booking_attendance', array( __CLASS__, 'enforce_attendance_time' ), 10, 4 );
		add_action( 'acf/save_post', array( __CLASS__, 'snapshot_booking_rules' ), 45 );
		add_action( 'acf/save_post', array( __CLASS__, 'process_booking' ), 50 );
	}

	public static function validate_attendance_time( $valid, $value, $field, $input ) {
		if ( true !== $valid || ! in_array( $value, array( 'present', 'no_show' ), true ) ) {
			return $valid;
		}

		$lesson_id = self::posted_lesson_id();
		if ( ! $lesson_id || ! self::is_lesson_started( $lesson_id ) ) {
			return 'Посещение или неявку можно отметить только после начала занятия.';
		}

		return $valid;
	}

	public static function enforce_attendance_time( $value, $post_id, $field, $original ) {
		if ( ! in_array( $value, array( 'present', 'no_show' ), true ) ) {
			return $value;
		}

		$lesson_id = self::posted_lesson_id( $post_id );
		if ( $lesson_id && self::is_lesson_started( $lesson_id ) ) {
			return $value;
		}

		$previous = is_numeric( $post_id ) ? (string) get_post_meta( (int) $post_id, 'booking_attendance', true ) : '';
		return in_array( $previous, array( 'expected', 'excused' ), true ) ? $previous : 'expected';
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
		$attendance    = (string) get_post_meta( $post_id, 'booking_attendance', true );
		if ( in_array( $attendance, array( 'present', 'no_show' ), true ) && ! self::is_booking_finalization_allowed( $post_id ) ) {
			self::set_result( $post_id, 'Операция не создана: посещение или неявку можно отметить только после начала занятия.' );
			return;
		}

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

	public static function is_booking_finalization_allowed( $booking_id, DateTimeImmutable $now = null ) {
		$lesson_id = absint( get_post_meta( $booking_id, 'booking_lesson', true ) );
		return $lesson_id && self::is_lesson_started( $lesson_id, $now );
	}

	public static function is_lesson_started( $lesson_id, DateTimeImmutable $now = null ) {
		$date = (string) get_post_meta( $lesson_id, 'lesson_date', true );
		$time = (string) get_post_meta( $lesson_id, 'lesson_time', true );
		return self::is_datetime_started( $date, $time, $now );
	}

	public static function is_datetime_started( $date, $time, DateTimeImmutable $now = null ) {
		$date = preg_replace( '/\D+/', '', (string) $date );
		$time = preg_replace( '/\D+/', '', (string) $time );
		if ( 8 !== strlen( $date ) || strlen( $time ) < 4 ) {
			return false;
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$lesson   = DateTimeImmutable::createFromFormat( '!Ymd Hi', $date . ' ' . substr( $time, 0, 4 ), $timezone );
		$errors   = DateTimeImmutable::getLastErrors();
		if ( ! $lesson || ( false !== $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) ) {
			return false;
		}

		$now = $now ?: new DateTimeImmutable( 'now', $timezone );
		return $now->getTimestamp() >= $lesson->getTimestamp();
	}

	private static function posted_lesson_id( $post_id = 0 ) {
		if ( isset( $_POST['acf']['field_hcos_booking_lesson'] ) ) {
			return absint( wp_unslash( $_POST['acf']['field_hcos_booking_lesson'] ) );
		}

		if ( ! $post_id && isset( $_POST['post_ID'] ) ) {
			$post_id = absint( $_POST['post_ID'] );
		}

		return $post_id ? absint( get_post_meta( $post_id, 'booking_lesson', true ) ) : 0;
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
