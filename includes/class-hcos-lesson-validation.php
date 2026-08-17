<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Lesson_Validation {
	private const CANCELLED_STATUSES = array(
		'cancelled',
		'cancelled_by_client',
		'cancelled_by_club',
		'rescheduled',
	);

	public static function init() {
		add_filter( 'acf/validate_value/name=lesson_time', array( __CLASS__, 'validate_schedule' ), 10, 4 );
		add_filter( 'acf/validate_value/name=lesson_capacity', array( __CLASS__, 'validate_lesson_capacity' ), 10, 4 );
		add_filter( 'acf/validate_value/name=lesson_price', array( __CLASS__, 'validate_lesson_price' ), 10, 4 );
		add_filter( 'acf/validate_value/name=service_duration', array( __CLASS__, 'validate_service_duration' ), 10, 4 );
		add_filter( 'acf/validate_value/name=service_capacity', array( __CLASS__, 'validate_service_capacity' ), 10, 4 );
		add_filter( 'acf/validate_value/name=service_price', array( __CLASS__, 'validate_service_price' ), 10, 4 );
	}

	public static function validate_lesson_capacity( $valid, $value, $field, $input ) {
		if ( true !== $valid || absint( $value ) > 0 ) {
			return $valid;
		}

		$service_id = self::submitted_service_id();
		if ( $service_id && absint( get_post_meta( $service_id, 'service_capacity', true ) ) > 0 ) {
			return $valid;
		}

		return 'Укажите вместимость занятия или выберите услугу с заполненной вместимостью.';
	}

	public static function validate_lesson_price( $valid, $value, $field, $input ) {
		if ( true !== $valid ) {
			return $valid;
		}

		if ( self::is_non_negative_number( $value ) ) {
			return $valid;
		}

		$service_id    = self::submitted_service_id();
		$service_price = $service_id ? get_post_meta( $service_id, 'service_price', true ) : '';
		if ( self::is_non_negative_number( $service_price ) ) {
			return $valid;
		}

		return 'Укажите цену занятия или выберите услугу с заполненной базовой стоимостью.';
	}

	public static function validate_service_duration( $valid, $value, $field, $input ) {
		if ( true !== $valid || absint( $value ) > 0 ) {
			return $valid;
		}

		return 'Укажите продолжительность услуги больше нуля.';
	}

	public static function validate_service_capacity( $valid, $value, $field, $input ) {
		if ( true !== $valid || absint( $value ) > 0 ) {
			return $valid;
		}

		return 'Укажите максимум участников не меньше одного.';
	}

	public static function validate_service_price( $valid, $value, $field, $input ) {
		if ( true !== $valid || self::is_non_negative_number( $value ) ) {
			return $valid;
		}

		return 'Укажите базовую стоимость услуги. Для бесплатной услуги укажите 0.';
	}

	public static function validate_schedule( $valid, $value, $field, $input ) {
		if ( true !== $valid || ! isset( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) {
			return $valid;
		}

		$acf = wp_unslash( $_POST['acf'] );
		if ( ! is_array( $acf ) ) {
			return $valid;
		}

		$date       = self::normalize_date( isset( $acf['field_hcos_lesson_date'] ) ? $acf['field_hcos_lesson_date'] : '' );
		$time       = self::normalize_time( $value );
		$trainer_id = isset( $acf['field_hcos_lesson_trainer'] ) ? absint( $acf['field_hcos_lesson_trainer'] ) : 0;
		$horse_id   = isset( $acf['field_hcos_lesson_horse'] ) ? absint( $acf['field_hcos_lesson_horse'] ) : 0;
		$service_id = isset( $acf['field_hcos_lesson_service'] ) ? absint( $acf['field_hcos_lesson_service'] ) : 0;
		$duration   = isset( $acf['field_hcos_lesson_duration'] ) ? absint( $acf['field_hcos_lesson_duration'] ) : 0;
		$status     = isset( $acf['field_hcos_lesson_status'] ) ? sanitize_key( $acf['field_hcos_lesson_status'] ) : 'planned';

		if ( in_array( $status, self::CANCELLED_STATUSES, true ) ) {
			return $valid;
		}

		if ( ! $duration && $service_id ) {
			$duration = absint( get_post_meta( $service_id, 'service_duration', true ) );
		}

		if ( ! $date || ! $time || ! $duration || ( ! $trainer_id && ! $horse_id ) ) {
			return ! $duration ? 'Укажите продолжительность занятия или выберите услугу с заполненной продолжительностью.' : $valid;
		}

		$current_post_id = isset( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0;
		$start           = strtotime( self::display_date( $date ) . ' ' . $time );
		$end             = false !== $start ? $start + ( $duration * MINUTE_IN_SECONDS ) : false;
		if ( false === $start || false === $end ) {
			return 'Не удалось проверить дату и время занятия. Проверьте заполненные значения.';
		}

		$resource_query = array( 'relation' => 'OR' );
		if ( $trainer_id ) {
			$resource_query[] = array( 'key' => 'lesson_trainer', 'value' => $trainer_id, 'compare' => '=' );
		}
		if ( $horse_id ) {
			$resource_query[] = array( 'key' => 'lesson_horse', 'value' => $horse_id, 'compare' => '=' );
		}

		$candidates = get_posts(
			array(
				'post_type'      => 'lessons',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'post__not_in'   => $current_post_id ? array( $current_post_id ) : array(),
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => 'lesson_date', 'value' => $date, 'compare' => '=' ),
					$resource_query,
				),
			)
		);

		foreach ( $candidates as $candidate_id ) {
			$candidate_status = (string) get_post_meta( $candidate_id, 'lesson_status', true );
			if ( in_array( $candidate_status, self::CANCELLED_STATUSES, true ) ) {
				continue;
			}

			$candidate_time     = self::normalize_time( get_post_meta( $candidate_id, 'lesson_time', true ) );
			$candidate_duration = absint( get_post_meta( $candidate_id, 'lesson_duration', true ) );
			if ( ! $candidate_duration ) {
				$candidate_service  = absint( get_post_meta( $candidate_id, 'lesson_service', true ) );
				$candidate_duration = absint( get_post_meta( $candidate_service, 'service_duration', true ) );
			}

			if ( ! $candidate_time || ! $candidate_duration ) {
				continue;
			}

			$candidate_start = strtotime( self::display_date( $date ) . ' ' . $candidate_time );
			$candidate_end   = false !== $candidate_start ? $candidate_start + ( $candidate_duration * MINUTE_IN_SECONDS ) : false;
			if ( false === $candidate_start || false === $candidate_end || $start >= $candidate_end || $end <= $candidate_start ) {
				continue;
			}

			$conflicts = array();
			if ( $trainer_id && $trainer_id === absint( get_post_meta( $candidate_id, 'lesson_trainer', true ) ) ) {
				$conflicts[] = 'тренер «' . get_the_title( $trainer_id ) . '»';
			}
			if ( $horse_id && $horse_id === absint( get_post_meta( $candidate_id, 'lesson_horse', true ) ) ) {
				$conflicts[] = 'лошадь «' . get_the_title( $horse_id ) . '»';
			}

			if ( $conflicts ) {
				return sprintf(
					'Конфликт расписания: %1$s уже занята в занятии «%2$s» с %3$s до %4$s.',
					implode( ' и ', $conflicts ),
					get_the_title( $candidate_id ),
					substr( $candidate_time, 0, 5 ),
					gmdate( 'H:i', $candidate_end )
				);
			}
		}

		return $valid;
	}

	private static function normalize_date( $date ) {
		$digits = preg_replace( '/[^0-9]/', '', sanitize_text_field( (string) $date ) );
		return 8 === strlen( $digits ) ? $digits : '';
	}

	private static function normalize_time( $time ) {
		$time = sanitize_text_field( (string) $time );
		if ( preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/', $time ) ) {
			return 5 === strlen( $time ) ? $time . ':00' : $time;
		}
		return '';
	}

	private static function display_date( $date ) {
		return substr( $date, 0, 4 ) . '-' . substr( $date, 4, 2 ) . '-' . substr( $date, 6, 2 );
	}

	private static function submitted_service_id() {
		if ( ! isset( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) {
			return 0;
		}

		$acf = wp_unslash( $_POST['acf'] );
		return is_array( $acf ) && isset( $acf['field_hcos_lesson_service'] ) ? absint( $acf['field_hcos_lesson_service'] ) : 0;
	}

	private static function is_non_negative_number( $value ) {
		return '' !== trim( (string) $value ) && is_numeric( $value ) && (float) $value >= 0;
	}
}
