<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Admin {
	public static function init() {
		add_filter( 'manage_clients_posts_columns', array( __CLASS__, 'client_columns' ) );
		add_action( 'manage_clients_posts_custom_column', array( __CLASS__, 'render_client_column' ), 10, 2 );
		add_filter( 'manage_horses_posts_columns', array( __CLASS__, 'horse_columns' ) );
		add_action( 'manage_horses_posts_custom_column', array( __CLASS__, 'render_horse_column' ), 10, 2 );
		add_filter( 'manage_trainers_posts_columns', array( __CLASS__, 'trainer_columns' ) );
		add_action( 'manage_trainers_posts_custom_column', array( __CLASS__, 'render_trainer_column' ), 10, 2 );
		add_filter( 'manage_services_posts_columns', array( __CLASS__, 'service_columns' ) );
		add_action( 'manage_services_posts_custom_column', array( __CLASS__, 'render_service_column' ), 10, 2 );
		add_filter( 'manage_lessons_posts_columns', array( __CLASS__, 'lesson_columns' ) );
		add_action( 'manage_lessons_posts_custom_column', array( __CLASS__, 'render_lesson_column' ), 10, 2 );
		add_filter( 'manage_bookings_posts_columns', array( __CLASS__, 'booking_columns' ) );
		add_action( 'manage_bookings_posts_custom_column', array( __CLASS__, 'render_booking_column' ), 10, 2 );
		add_filter( 'manage_pricing_plans_posts_columns', array( __CLASS__, 'pricing_plan_columns' ) );
		add_action( 'manage_pricing_plans_posts_custom_column', array( __CLASS__, 'render_pricing_plan_column' ), 10, 2 );
		add_filter( 'manage_memberships_posts_columns', array( __CLASS__, 'membership_columns' ) );
		add_action( 'manage_memberships_posts_custom_column', array( __CLASS__, 'render_membership_column' ), 10, 2 );
		add_filter( 'manage_membership_ops_posts_columns', array( __CLASS__, 'membership_operation_columns' ) );
		add_action( 'manage_membership_ops_posts_custom_column', array( __CLASS__, 'render_membership_operation_column' ), 10, 2 );
		add_filter( 'manage_payments_posts_columns', array( __CLASS__, 'payment_columns' ) );
		add_action( 'manage_payments_posts_custom_column', array( __CLASS__, 'render_payment_column' ), 10, 2 );
	}

	public static function client_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'title' === $key ) {
				$new_columns['client_roles']  = 'Роли';
				$new_columns['client_phone']  = 'Телефон';
				$new_columns['client_email']  = 'Email';
				$new_columns['client_status'] = 'Статус';
			}
		}

		return $new_columns;
	}

	public static function render_client_column( $column, $post_id ) {
		if ( ! in_array( $column, array( 'client_roles', 'client_phone', 'client_email', 'client_status' ), true ) ) {
			return;
		}

		$value = get_post_meta( $post_id, $column, true );

		if ( 'client_status' === $column ) {
			$statuses = array(
				'active'   => 'Активен',
				'inactive' => 'Неактивен',
				'archived' => 'Архив',
			);
			$value = isset( $statuses[ $value ] ) ? $statuses[ $value ] : $value;
		}

		if ( 'client_roles' === $column ) {
			$role_labels = array(
				'rider'       => 'Всадник',
				'guardian'    => 'Родитель',
				'payer'       => 'Плательщик',
				'horse_owner' => 'Владелец',
				'contact'     => 'Контакт',
			);
			$roles       = is_array( $value ) ? $value : array_filter( array( $value ) );
			$value       = implode( ', ', array_map( static function ( $role ) use ( $role_labels ) {
				return isset( $role_labels[ $role ] ) ? $role_labels[ $role ] : $role;
			}, $roles ) );
		}

		echo '' !== (string) $value ? esc_html( $value ) : '&mdash;';
	}

	public static function horse_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'title' === $key ) {
				$new_columns['horse_status']          = 'Статус';
				$new_columns['horse_owner']           = 'Владелец';
				$new_columns['horse_min_rider_level'] = 'Уровень всадника';
			}
		}

		return $new_columns;
	}

	public static function render_horse_column( $column, $post_id ) {
		if ( ! in_array( $column, array( 'horse_status', 'horse_owner', 'horse_min_rider_level' ), true ) ) {
			return;
		}

		$value = get_post_meta( $post_id, $column, true );

		if ( 'horse_status' === $column ) {
			$labels = array( 'active' => 'Активна', 'rest' => 'Отдых', 'unavailable' => 'Недоступна', 'archived' => 'Архив' );
			$value  = isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
		} elseif ( 'horse_min_rider_level' === $column ) {
			$labels = array( 'beginner' => 'Начинающий', 'intermediate' => 'Средний', 'advanced' => 'Продвинутый' );
			$value  = isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
		} elseif ( 'horse_owner' === $column && $value ) {
			$value = get_the_title( (int) $value );
		}

		echo '' !== (string) $value ? esc_html( $value ) : '&mdash;';
	}

	public static function trainer_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'title' === $key ) {
				$new_columns['trainer_phone']           = 'Телефон';
				$new_columns['trainer_specializations'] = 'Специализации';
				$new_columns['trainer_status']          = 'Статус';
			}
		}

		return $new_columns;
	}

	public static function render_trainer_column( $column, $post_id ) {
		if ( ! in_array( $column, array( 'trainer_phone', 'trainer_specializations', 'trainer_status' ), true ) ) {
			return;
		}

		$value = get_post_meta( $post_id, $column, true );

		if ( 'trainer_status' === $column ) {
			$labels = array( 'active' => 'Работает', 'vacation' => 'Отпуск', 'sick' => 'Больничный', 'inactive' => 'Не работает', 'archived' => 'Архив' );
			$value  = isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
		} elseif ( 'trainer_specializations' === $column ) {
			$labels = array( 'beginner_training' => 'Начинающие', 'children' => 'Дети', 'dressage' => 'Выездка', 'jumping' => 'Конкур', 'trail' => 'Прогулки', 'rehabilitation' => 'Восстановление', 'other' => 'Другое' );
			$items  = is_array( $value ) ? $value : array_filter( array( $value ) );
			$value  = implode( ', ', array_map( static function ( $item ) use ( $labels ) {
				return isset( $labels[ $item ] ) ? $labels[ $item ] : $item;
			}, $items ) );
		}

		echo '' !== (string) $value ? esc_html( $value ) : '&mdash;';
	}

	public static function service_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'title' === $key ) {
				$new_columns['service_format']   = 'Формат';
				$new_columns['service_duration'] = 'Длительность';
				$new_columns['service_price']    = 'Цена';
				$new_columns['service_status']   = 'Статус';
			}
		}

		if ( ! current_user_can( 'hcos_view_finances' ) ) {
			unset( $new_columns['service_price'] );
		}
		return $new_columns;
	}

	public static function render_service_column( $column, $post_id ) {
		if ( ! in_array( $column, array( 'service_format', 'service_duration', 'service_price', 'service_status' ), true ) ) {
			return;
		}

		$value = get_post_meta( $post_id, $column, true );

		if ( 'service_format' === $column ) {
			$value = 'group' === $value ? 'Групповой' : ( 'individual' === $value ? 'Индивидуальный' : $value );
		} elseif ( 'service_duration' === $column && '' !== (string) $value ) {
			$value = absint( $value ) . ' мин.';
		} elseif ( 'service_price' === $column && '' !== (string) $value ) {
			$value = number_format_i18n( (float) $value, 2 ) . ' ₽';
		} elseif ( 'service_status' === $column ) {
			$value = 'active' === $value ? 'Активна' : ( 'inactive' === $value ? 'Неактивна' : $value );
		}

		echo '' !== (string) $value ? esc_html( $value ) : '&mdash;';
	}

	public static function lesson_columns( $columns ) {
		$new_columns = array(
			'cb'             => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
			'title'          => 'Занятие',
			'lesson_datetime'=> 'Дата и время',
			'lesson_bookings'=> 'Записи',
			'lesson_trainer' => 'Тренер',
			'lesson_horse'   => 'Лошадь',
			'lesson_price'   => 'Цена',
			'lesson_status'  => 'Статус',
			'date'           => isset( $columns['date'] ) ? $columns['date'] : 'Дата публикации',
		);
		if ( ! current_user_can( 'hcos_view_finances' ) ) {
			unset( $new_columns['lesson_price'] );
		}
		return $new_columns;
	}

	public static function render_lesson_column( $column, $post_id ) {
		if ( 'lesson_datetime' === $column ) {
			$date = function_exists( 'get_field' ) ? get_field( 'lesson_date', $post_id ) : get_post_meta( $post_id, 'lesson_date', true );
			$time = get_post_meta( $post_id, 'lesson_time', true );
			$value = trim( $date . ' ' . substr( (string) $time, 0, 5 ) );
		} elseif ( 'lesson_bookings' === $column ) {
			$booking_ids = HCOS_Bookings::get_active_booking_ids( $post_id );
			$rider_names = array();
			foreach ( $booking_ids as $booking_id ) {
				$rider_id = absint( get_post_meta( $booking_id, 'booking_rider', true ) );
				if ( $rider_id ) {
					$rider_names[] = get_the_title( $rider_id );
				}
			}
			$value = $rider_names ? implode( ', ', $rider_names ) . ' (' . count( $booking_ids ) . '/' . HCOS_Bookings::get_lesson_capacity( $post_id ) . ')' : '0/' . HCOS_Bookings::get_lesson_capacity( $post_id );
		} elseif ( in_array( $column, array( 'lesson_trainer', 'lesson_horse' ), true ) ) {
			$related_id = (int) get_post_meta( $post_id, $column, true );
			$value      = $related_id ? get_the_title( $related_id ) : '';
		} else {
			$value = get_post_meta( $post_id, $column, true );
		}

		if ( 'lesson_price' === $column && '' !== (string) $value ) {
			$value = number_format_i18n( (float) $value, 2 ) . ' ₽';
		} elseif ( 'lesson_status' === $column ) {
			$labels = array( 'planned' => 'Запланировано', 'confirmed' => 'Подтверждено', 'completed' => 'Проведено', 'cancelled_by_client' => 'Отмена клиентом', 'cancelled_by_club' => 'Отмена клубом', 'no_show' => 'Неявка', 'rescheduled' => 'Перенесено', 'cancelled' => 'Отменено' );
			$value  = isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
		}

		echo '' !== (string) $value ? esc_html( $value ) : '&mdash;';
	}

	public static function booking_columns( $columns ) {
		$new_columns = array(
			'cb'                     => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
			'title'                  => 'Запись',
			'booking_lesson'         => 'Занятие',
			'booking_rider'          => 'Всадник',
			'booking_payer'          => 'Плательщик',
			'booking_status'         => 'Статус',
			'booking_attendance'     => 'Посещение',
			'booking_charge_result'  => 'Абонемент',
			'booking_payment_status' => 'Оплата',
			'booking_debt_amount'    => 'Долг',
			'date'                   => isset( $columns['date'] ) ? $columns['date'] : 'Дата публикации',
		);
		if ( ! current_user_can( 'hcos_view_finances' ) ) {
			unset( $new_columns['booking_payment_status'], $new_columns['booking_debt_amount'] );
		}
		return $new_columns;
	}

	public static function render_booking_column( $column, $post_id ) {
		$value = get_post_meta( $post_id, $column, true );
		if ( in_array( $column, array( 'booking_lesson', 'booking_rider', 'booking_payer' ), true ) && $value ) {
			$value = get_the_title( (int) $value );
		} elseif ( 'booking_status' === $column ) {
			$labels = array( 'pending' => 'Ожидает', 'confirmed' => 'Подтверждена', 'cancelled_by_client' => 'Отмена клиентом', 'cancelled_by_club' => 'Отмена клубом', 'waitlist' => 'Лист ожидания' );
			$value  = isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
		} elseif ( 'booking_attendance' === $column ) {
			$labels = array( 'expected' => 'Ожидается', 'present' => 'Присутствовал', 'no_show' => 'Неявка', 'excused' => 'Уважительная отмена' );
			$value  = isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
		} elseif ( 'booking_payment_status' === $column ) {
			$labels = array( 'unpaid' => 'Не оплачено', 'paid' => 'Оплачено', 'membership' => 'Абонемент', 'partial' => 'Частично', 'refund' => 'Возврат' );
			$value  = isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
		} elseif ( 'booking_debt_amount' === $column && '' !== (string) $value ) {
			$value = number_format_i18n( (float) $value, 2 ) . ' ₽';
		}

		echo '' !== (string) $value ? esc_html( $value ) : '&mdash;';
	}

	public static function membership_columns( $columns ) {
		return array(
			'cb'                      => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
			'title'                   => 'Абонемент',
			'membership_client'       => 'Всадник',
			'membership_plan'         => 'Тариф',
			'membership_period'       => 'Период',
			'membership_lesson_limit' => 'Занятий',
			'membership_balance'      => 'Остаток',
			'membership_price'        => 'Стоимость',
			'membership_paid_amount'  => 'Оплачено',
			'membership_debt_amount'  => 'Долг',
			'membership_status'       => 'Статус',
			'date'                    => isset( $columns['date'] ) ? $columns['date'] : 'Дата публикации',
		);
	}

	public static function render_membership_column( $column, $post_id ) {
		if ( 'membership_client' === $column ) {
			$client_id = absint( get_post_meta( $post_id, 'membership_client', true ) );
			$value     = $client_id ? get_the_title( $client_id ) : '';
		} elseif ( 'membership_plan' === $column ) {
			$plan_id = absint( get_post_meta( $post_id, 'membership_plan', true ) );
			$value   = $plan_id ? get_the_title( $plan_id ) : '';
		} elseif ( 'membership_period' === $column ) {
			$start = function_exists( 'get_field' ) ? get_field( 'membership_start_date', $post_id ) : get_post_meta( $post_id, 'membership_start_date', true );
			$end   = function_exists( 'get_field' ) ? get_field( 'membership_end_date', $post_id ) : get_post_meta( $post_id, 'membership_end_date', true );
			$value = trim( $start . ( $end ? ' — ' . $end : '' ) );
		} else {
			$value = get_post_meta( $post_id, $column, true );
		}

		if ( in_array( $column, array( 'membership_price', 'membership_paid_amount', 'membership_debt_amount' ), true ) && '' !== (string) $value ) {
			$value = number_format_i18n( (float) $value, 2 ) . ' ₽';
		} elseif ( 'membership_status' === $column ) {
			$labels = array( 'draft' => 'Черновик', 'active' => 'Активен', 'frozen' => 'Заморожен', 'exhausted' => 'Исчерпан', 'expired' => 'Истёк', 'cancelled' => 'Отменён' );
			$value  = isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
		}

		echo '' !== (string) $value ? esc_html( $value ) : '&mdash;';
	}

	public static function pricing_plan_columns( $columns ) {
		return array(
			'cb'                         => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
			'title'                      => 'Тариф / пакет',
			'pricing_plan_version'       => 'Версия',
			'pricing_plan_lesson_count'  => 'Занятий',
			'pricing_plan_validity_days' => 'Срок',
			'pricing_plan_price'         => 'Стоимость',
			'pricing_plan_status'        => 'Статус',
			'date'                       => isset( $columns['date'] ) ? $columns['date'] : 'Дата публикации',
		);
	}

	public static function render_pricing_plan_column( $column, $post_id ) {
		$value = get_post_meta( $post_id, $column, true );
		if ( 'pricing_plan_validity_days' === $column && '' !== (string) $value ) {
			$value = absint( $value ) . ' дн.';
		} elseif ( 'pricing_plan_price' === $column && '' !== (string) $value ) {
			$value = number_format_i18n( (float) $value, 2 ) . ' ₽';
		} elseif ( 'pricing_plan_status' === $column ) {
			$labels = array( 'draft' => 'Черновик', 'active' => 'Активен', 'archived' => 'Архив' );
			$value  = isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
		}

		echo '' !== (string) $value ? esc_html( $value ) : '&mdash;';
	}

	public static function membership_operation_columns( $columns ) {
		return array(
			'cb'                       => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
			'title'                    => 'Операция',
			'membership_op_membership' => 'Абонемент',
			'membership_op_type'       => 'Тип',
			'membership_op_amount'     => 'Количество',
			'membership_op_date'       => 'Дата',
			'membership_op_lesson'     => 'Занятие',
		);
	}

	public static function render_membership_operation_column( $column, $post_id ) {
		$value = get_post_meta( $post_id, $column, true );
		if ( in_array( $column, array( 'membership_op_membership', 'membership_op_lesson' ), true ) && $value ) {
			$value = get_the_title( (int) $value );
		} elseif ( 'membership_op_type' === $column ) {
			$labels = array( 'credit' => 'Начисление', 'debit' => 'Списание', 'refund' => 'Возврат', 'adjustment' => 'Корректировка' );
			$value  = isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
		} elseif ( 'membership_op_date' === $column && function_exists( 'get_field' ) ) {
			$value = get_field( 'membership_op_date', $post_id );
		}

		echo '' !== (string) $value ? esc_html( $value ) : '&mdash;';
	}

	public static function payment_columns( $columns ) {
		return array(
			'cb'                   => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
			'title'                => 'Платёж',
			'payment_payer'        => 'Плательщик',
			'payment_amount'       => 'Сумма',
			'payment_date'         => 'Дата',
			'payment_method'       => 'Способ',
			'payment_status'       => 'Статус',
			'payment_purpose_type' => 'Назначение',
		);
	}

	public static function render_payment_column( $column, $post_id ) {
		$value = get_post_meta( $post_id, $column, true );
		if ( 'payment_payer' === $column && $value ) {
			$value = get_the_title( (int) $value );
		} elseif ( 'payment_amount' === $column && '' !== (string) $value ) {
			$value = number_format_i18n( (float) $value, 2 ) . ' ₽';
		} elseif ( 'payment_date' === $column && function_exists( 'get_field' ) ) {
			$value = get_field( 'payment_date', $post_id );
		} elseif ( 'payment_method' === $column ) {
			$labels = array( 'cash' => 'Наличные', 'card' => 'Карта', 'transfer' => 'Перевод', 'online' => 'Онлайн', 'other' => 'Другое' );
			$value  = isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
		} elseif ( 'payment_status' === $column ) {
			$labels = array( 'pending' => 'Ожидает', 'paid' => 'Оплачен', 'refund' => 'Возврат', 'cancelled' => 'Отменён' );
			$value  = isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
		} elseif ( 'payment_purpose_type' === $column ) {
			$type = (string) $value;
			if ( 'membership' === $type ) {
				$target_id = absint( get_post_meta( $post_id, 'payment_membership', true ) );
				$value     = $target_id ? 'Абонемент: ' . get_the_title( $target_id ) : 'Абонемент';
			} elseif ( 'booking' === $type ) {
				$target_id = absint( get_post_meta( $post_id, 'payment_booking', true ) );
				$value     = $target_id ? 'Запись: ' . get_the_title( $target_id ) : 'Запись';
			} else {
				$value = get_post_meta( $post_id, 'payment_purpose', true );
			}
		}

		echo '' !== (string) $value ? esc_html( $value ) : '&mdash;';
	}
}
