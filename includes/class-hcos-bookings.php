<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Bookings {
	private const ACTIVE_STATUSES = array( 'pending', 'confirmed' );

	public static function init() {
		add_filter( 'acf/validate_value/name=booking_rider', array( __CLASS__, 'validate_booking' ), 10, 4 );
		add_action( 'acf/save_post', array( __CLASS__, 'prepare_booking' ), 35 );
		add_action( 'acf/save_post', array( __CLASS__, 'migrate_lesson_client' ), 40 );
		add_action( 'admin_init', array( __CLASS__, 'migrate_existing_lessons' ) );
	}

	public static function validate_booking( $valid, $value, $field, $input ) {
		if ( true !== $valid || ! isset( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) {
			return $valid;
		}

		$acf        = wp_unslash( $_POST['acf'] );
		$lesson_id  = isset( $acf['field_hcos_booking_lesson'] ) ? absint( $acf['field_hcos_booking_lesson'] ) : 0;
		$rider_id   = absint( $value );
		$status     = isset( $acf['field_hcos_booking_status'] ) ? sanitize_key( $acf['field_hcos_booking_status'] ) : 'confirmed';
		$current_id = isset( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0;

		if ( ! $lesson_id || ! $rider_id || ! in_array( $status, self::ACTIVE_STATUSES, true ) ) {
			return $valid;
		}

		$lesson_status = (string) get_post_meta( $lesson_id, 'lesson_status', true );
		if ( in_array( $lesson_status, array( 'cancelled', 'cancelled_by_client', 'cancelled_by_club', 'rescheduled' ), true ) ) {
			return 'Нельзя записать всадника на отменённое или перенесённое занятие.';
		}

		$active_booking_ids = self::get_active_booking_ids( $lesson_id, $current_id );
		foreach ( $active_booking_ids as $booking_id ) {
			if ( $rider_id === absint( get_post_meta( $booking_id, 'booking_rider', true ) ) ) {
				return 'Этот всадник уже записан на выбранное занятие.';
			}
		}

		$capacity = self::get_lesson_capacity( $lesson_id );
		if ( count( $active_booking_ids ) >= $capacity ) {
			return sprintf( 'Свободных мест нет: вместимость занятия — %d.', $capacity );
		}

		return $valid;
	}

	public static function prepare_booking( $post_id ) {
		if ( ! is_numeric( $post_id ) || 'bookings' !== get_post_type( $post_id ) ) {
			return;
		}

		$lesson_id = absint( get_post_meta( $post_id, 'booking_lesson', true ) );
		$rider_id  = absint( get_post_meta( $post_id, 'booking_rider', true ) );

		if ( $rider_id && ! get_post_meta( $post_id, 'booking_payer', true ) ) {
			$payer_id = absint( get_post_meta( $rider_id, 'client_payer', true ) );
			update_field( 'field_hcos_booking_payer', $payer_id ?: $rider_id, $post_id );
		}

		if ( $lesson_id && ! get_post_meta( $post_id, 'booking_horse', true ) ) {
			$horse_id = absint( get_post_meta( $lesson_id, 'lesson_horse', true ) );
			if ( $horse_id ) {
				update_field( 'field_hcos_booking_horse', $horse_id, $post_id );
			}
		}

		$date  = $lesson_id && function_exists( 'get_field' ) ? (string) get_field( 'lesson_date', $lesson_id ) : '';
		$time  = $lesson_id ? substr( (string) get_post_meta( $lesson_id, 'lesson_time', true ), 0, 5 ) : '';
		$title = trim( implode( ' — ', array_filter( array( $date, $time, $rider_id ? get_the_title( $rider_id ) : '' ) ) ) );
		if ( '' === $title || get_the_title( $post_id ) === $title ) {
			return;
		}

		remove_action( 'acf/save_post', array( __CLASS__, 'prepare_booking' ), 35 );
		wp_update_post( array( 'ID' => (int) $post_id, 'post_title' => $title ) );
		add_action( 'acf/save_post', array( __CLASS__, 'prepare_booking' ), 35 );
	}

	public static function migrate_existing_lessons() {
		if ( HCOS_VERSION === get_option( 'hcos_bookings_migrated' ) || ! function_exists( 'update_field' ) ) {
			return;
		}

		$lesson_ids = get_posts(
			array(
				'post_type'      => 'lessons',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $lesson_ids as $lesson_id ) {
			self::migrate_lesson( $lesson_id );
		}

		update_option( 'hcos_bookings_migrated', HCOS_VERSION, false );
	}

	public static function migrate_lesson_client( $post_id ) {
		if ( is_numeric( $post_id ) && 'lessons' === get_post_type( $post_id ) ) {
			self::migrate_lesson( $post_id );
		}
	}

	public static function migrate_lesson( $lesson_id ) {
		$lesson_id = absint( $lesson_id );
		$rider_id  = absint( get_post_meta( $lesson_id, 'lesson_client', true ) );
		if ( ! $lesson_id || ! $rider_id ) {
			return 0;
		}

		$existing = get_posts(
			array(
				'post_type'      => 'bookings',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => 'booking_lesson', 'value' => $lesson_id, 'compare' => '=' ),
					array( 'key' => 'booking_rider', 'value' => $rider_id, 'compare' => '=' ),
				),
			)
		);
		if ( $existing ) {
			return (int) $existing[0];
		}

		$lesson_post_status = get_post_status( $lesson_id );
		$booking_id         = wp_insert_post(
			array(
				'post_type'   => 'bookings',
				'post_status' => 'publish' === $lesson_post_status ? 'publish' : 'draft',
				'post_title'  => 'Перенос записи из занятия #' . $lesson_id,
			)
		);
		if ( is_wp_error( $booking_id ) || ! $booking_id ) {
			return 0;
		}

		$lesson_status = (string) get_post_meta( $lesson_id, 'lesson_status', true );
		$status_map    = array(
			'cancelled'           => 'cancelled_by_club',
			'cancelled_by_client' => 'cancelled_by_client',
			'cancelled_by_club'   => 'cancelled_by_club',
			'rescheduled'         => 'cancelled_by_club',
		);
		$booking_status = isset( $status_map[ $lesson_status ] ) ? $status_map[ $lesson_status ] : 'confirmed';
		$attendance     = (string) get_post_meta( $lesson_id, 'lesson_attendance_status', true );
		if ( 'completed' === $lesson_status && ! $attendance ) {
			$attendance = 'present';
		} elseif ( 'no_show' === $lesson_status ) {
			$attendance = 'no_show';
		}

		update_field( 'field_hcos_booking_lesson', $lesson_id, $booking_id );
		update_field( 'field_hcos_booking_rider', $rider_id, $booking_id );
		update_field( 'field_hcos_booking_payer', absint( get_post_meta( $lesson_id, 'lesson_payer', true ) ), $booking_id );
		update_field( 'field_hcos_booking_horse', absint( get_post_meta( $lesson_id, 'lesson_horse', true ) ), $booking_id );
		update_field( 'field_hcos_booking_status', $booking_status, $booking_id );
		update_field( 'field_hcos_booking_attendance', $attendance ?: 'expected', $booking_id );
		update_field( 'field_hcos_booking_source', get_post_meta( $lesson_id, 'lesson_source', true ) ?: 'migration', $booking_id );
		update_field( 'field_hcos_booking_cancellation_reason', get_post_meta( $lesson_id, 'lesson_cancellation_reason', true ), $booking_id );
		update_field( 'field_hcos_booking_membership', absint( get_post_meta( $lesson_id, 'lesson_membership', true ) ), $booking_id );
		update_field( 'field_hcos_booking_payment_status', get_post_meta( $lesson_id, 'lesson_payment_status', true ) ?: 'unpaid', $booking_id );
		update_field( 'field_hcos_booking_trainer_notes', get_post_meta( $lesson_id, 'lesson_trainer_notes', true ), $booking_id );
		update_field( 'field_hcos_booking_admin_notes', get_post_meta( $lesson_id, 'lesson_comment', true ), $booking_id );
		update_post_meta( $booking_id, '_hcos_migrated_from_lesson', $lesson_id );
		update_post_meta( $lesson_id, '_hcos_booking_migrated', $booking_id );
		self::prepare_booking( $booking_id );

		return (int) $booking_id;
	}

	public static function get_active_booking_ids( $lesson_id, $exclude_booking_id = 0 ) {
		$booking_ids = get_posts(
			array(
				'post_type'      => 'bookings',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'post__not_in'   => $exclude_booking_id ? array( $exclude_booking_id ) : array(),
				'meta_key'       => 'booking_lesson',
				'meta_value'     => absint( $lesson_id ),
			)
		);

		return array_values(
			array_filter(
				$booking_ids,
				static function ( $booking_id ) {
					return in_array( get_post_meta( $booking_id, 'booking_status', true ), self::ACTIVE_STATUSES, true );
				}
			)
		);
	}

	public static function get_lesson_capacity( $lesson_id ) {
		$capacity = absint( get_post_meta( $lesson_id, 'lesson_capacity', true ) );
		if ( ! $capacity ) {
			$service_id = absint( get_post_meta( $lesson_id, 'lesson_service', true ) );
			$capacity   = $service_id ? absint( get_post_meta( $service_id, 'service_capacity', true ) ) : 0;
		}

		return max( 1, $capacity );
	}
}
