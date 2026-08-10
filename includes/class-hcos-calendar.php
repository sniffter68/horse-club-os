<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Calendar {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
	}

	public static function register_page() {
		add_submenu_page(
			'edit.php?post_type=lessons',
			'Расписание занятий',
			'Расписание',
			'edit_posts',
			'hcos-calendar',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'edit_hcos_lessons' ) ) {
			wp_die( esc_html__( 'Недостаточно прав для просмотра расписания.', 'horse-club-os' ) );
		}

		$timezone   = wp_timezone();
		$week_value = isset( $_GET['hcos_week'] ) ? sanitize_text_field( wp_unslash( $_GET['hcos_week'] ) ) : current_time( 'Y-m-d' );
		$anchor      = DateTimeImmutable::createFromFormat( '!Y-m-d', $week_value, $timezone );
		if ( ! $anchor ) {
			$anchor = new DateTimeImmutable( 'today', $timezone );
		}

		$week_start = $anchor->modify( 'monday this week' );
		$week_end   = $week_start->modify( '+6 days' );
		$trainer_id = isset( $_GET['hcos_trainer'] ) ? absint( $_GET['hcos_trainer'] ) : 0;
		$horse_id   = isset( $_GET['hcos_horse'] ) ? absint( $_GET['hcos_horse'] ) : 0;
		$lessons    = self::get_lessons( $week_start, $week_end, $trainer_id, $horse_id );
		$days       = self::group_by_day( $lessons, $week_start );

		echo '<div class="wrap hcos-calendar-wrap">';
		echo '<h1 class="wp-heading-inline">Расписание занятий</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( admin_url( 'post-new.php?post_type=lessons' ) ) . '">Добавить занятие</a>';
		self::render_filters( $week_start, $trainer_id, $horse_id );
		self::render_navigation( $week_start, $trainer_id, $horse_id );
		self::render_grid( $days, $week_start );
		echo '</div>';
		self::render_styles();
	}

	private static function get_lessons( $week_start, $week_end, $trainer_id, $horse_id ) {
		$meta_query = array(
			array(
				'key'     => 'lesson_date',
				'value'   => array( $week_start->format( 'Ymd' ), $week_end->format( 'Ymd' ) ),
				'compare' => 'BETWEEN',
				'type'    => 'NUMERIC',
			),
		);

		if ( $trainer_id ) {
			$meta_query[] = array( 'key' => 'lesson_trainer', 'value' => $trainer_id, 'compare' => '=' );
		}
		if ( $horse_id ) {
			$meta_query[] = array( 'key' => 'lesson_horse', 'value' => $horse_id, 'compare' => '=' );
		}

		$lessons = get_posts(
			array(
				'post_type'      => 'lessons',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_query'     => $meta_query,
				'no_found_rows'  => true,
			)
		);

		usort(
			$lessons,
			static function ( $left, $right ) {
				$left_key  = get_post_meta( $left->ID, 'lesson_date', true ) . get_post_meta( $left->ID, 'lesson_time', true );
				$right_key = get_post_meta( $right->ID, 'lesson_date', true ) . get_post_meta( $right->ID, 'lesson_time', true );
				return strcmp( $left_key, $right_key );
			}
		);

		return $lessons;
	}

	private static function group_by_day( $lessons, $week_start ) {
		$days = array();
		for ( $offset = 0; $offset < 7; $offset++ ) {
			$key          = $week_start->modify( '+' . $offset . ' days' )->format( 'Ymd' );
			$days[ $key ] = array();
		}

		foreach ( $lessons as $lesson ) {
			$key = preg_replace( '/[^0-9]/', '', (string) get_post_meta( $lesson->ID, 'lesson_date', true ) );
			if ( isset( $days[ $key ] ) ) {
				$days[ $key ][] = $lesson;
			}
		}

		return $days;
	}

	private static function render_filters( $week_start, $trainer_id, $horse_id ) {
		$trainers = get_posts( array( 'post_type' => 'trainers', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$horses   = get_posts( array( 'post_type' => 'horses', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );

		echo '<form class="hcos-calendar-filters" method="get">';
		echo '<input type="hidden" name="post_type" value="lessons">';
		echo '<input type="hidden" name="page" value="hcos-calendar">';
		echo '<label>Неделя <input type="date" name="hcos_week" value="' . esc_attr( $week_start->format( 'Y-m-d' ) ) . '"></label>';
		self::render_select( 'hcos_trainer', 'Все тренеры', $trainers, $trainer_id );
		self::render_select( 'hcos_horse', 'Все лошади', $horses, $horse_id );
		echo '<button class="button button-primary" type="submit">Показать</button>';
		echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=lessons&page=hcos-calendar' ) ) . '">Сбросить</a>';
		echo '</form>';
	}

	private static function render_select( $name, $empty_label, $posts, $selected_id ) {
		echo '<label><span class="screen-reader-text">' . esc_html( $empty_label ) . '</span><select name="' . esc_attr( $name ) . '">';
		echo '<option value="">' . esc_html( $empty_label ) . '</option>';
		foreach ( $posts as $post ) {
			echo '<option value="' . esc_attr( $post->ID ) . '" ' . selected( $selected_id, $post->ID, false ) . '>' . esc_html( get_the_title( $post ) ) . '</option>';
		}
		echo '</select></label>';
	}

	private static function render_navigation( $week_start, $trainer_id, $horse_id ) {
		$base_args = array( 'post_type' => 'lessons', 'page' => 'hcos-calendar' );
		if ( $trainer_id ) {
			$base_args['hcos_trainer'] = $trainer_id;
		}
		if ( $horse_id ) {
			$base_args['hcos_horse'] = $horse_id;
		}

		$previous = add_query_arg( array_merge( $base_args, array( 'hcos_week' => $week_start->modify( '-7 days' )->format( 'Y-m-d' ) ) ), admin_url( 'edit.php' ) );
		$current  = add_query_arg( array_merge( $base_args, array( 'hcos_week' => current_time( 'Y-m-d' ) ) ), admin_url( 'edit.php' ) );
		$next     = add_query_arg( array_merge( $base_args, array( 'hcos_week' => $week_start->modify( '+7 days' )->format( 'Y-m-d' ) ) ), admin_url( 'edit.php' ) );

		echo '<div class="hcos-calendar-navigation">';
		echo '<a class="button" href="' . esc_url( $previous ) . '">← Предыдущая</a>';
		echo '<a class="button" href="' . esc_url( $current ) . '">Текущая неделя</a>';
		echo '<a class="button" href="' . esc_url( $next ) . '">Следующая →</a>';
		echo '</div>';
	}

	private static function render_grid( $days, $week_start ) {
		$today = current_time( 'Ymd' );
		echo '<div class="hcos-calendar-scroll"><div class="hcos-calendar-grid">';
		foreach ( $days as $date_key => $lessons ) {
			$day = DateTimeImmutable::createFromFormat( '!Ymd', $date_key, wp_timezone() );
			echo '<section class="hcos-calendar-day' . ( $today === $date_key ? ' is-today' : '' ) . '">';
			echo '<h2>' . esc_html( wp_date( 'D, d.m', $day->getTimestamp(), wp_timezone() ) ) . '<span>' . count( $lessons ) . '</span></h2>';
			if ( ! $lessons ) {
				echo '<p class="hcos-calendar-empty">Нет занятий</p>';
			}
			foreach ( $lessons as $lesson ) {
				self::render_lesson( $lesson );
			}
			echo '</section>';
		}
		echo '</div></div>';
	}

	private static function render_lesson( $lesson ) {
		$status        = (string) get_post_meta( $lesson->ID, 'lesson_status', true );
		$status_labels = array( 'planned' => 'Запланировано', 'confirmed' => 'Подтверждено', 'completed' => 'Проведено', 'cancelled_by_client' => 'Отмена клиентом', 'cancelled_by_club' => 'Отмена клубом', 'no_show' => 'Неявка', 'rescheduled' => 'Перенесено', 'cancelled' => 'Отменено' );
		$time          = substr( (string) get_post_meta( $lesson->ID, 'lesson_time', true ), 0, 5 );
		$end_time      = substr( (string) get_post_meta( $lesson->ID, 'lesson_end_time', true ), 0, 5 );
		$trainer_id    = absint( get_post_meta( $lesson->ID, 'lesson_trainer', true ) );
		$horse_id      = absint( get_post_meta( $lesson->ID, 'lesson_horse', true ) );
		$service_id    = absint( get_post_meta( $lesson->ID, 'lesson_service', true ) );
		$booking_ids   = HCOS_Bookings::get_active_booking_ids( $lesson->ID );
		$rider_names   = array();
		foreach ( $booking_ids as $booking_id ) {
			$rider_id = absint( get_post_meta( $booking_id, 'booking_rider', true ) );
			if ( $rider_id ) {
				$rider_names[] = get_the_title( $rider_id );
			}
		}
		if ( ! $rider_names ) {
			$legacy_client_id = absint( get_post_meta( $lesson->ID, 'lesson_client', true ) );
			if ( $legacy_client_id ) {
				$rider_names[] = get_the_title( $legacy_client_id );
			}
		}
		$capacity = HCOS_Bookings::get_lesson_capacity( $lesson->ID );

		echo '<article class="hcos-calendar-lesson status-' . esc_attr( sanitize_html_class( $status ?: 'planned' ) ) . '">';
		echo '<a class="hcos-calendar-time" href="' . esc_url( get_edit_post_link( $lesson->ID ) ) . '">' . esc_html( $time . ( $end_time ? '–' . $end_time : '' ) ) . '</a>';
		echo '<strong>' . esc_html( $rider_names ? implode( ', ', $rider_names ) : 'Нет записей' ) . '</strong>';
		echo '<span>Места: ' . esc_html( count( $booking_ids ) . '/' . $capacity ) . '</span>';
		echo '<span>' . esc_html( $service_id ? get_the_title( $service_id ) : 'Услуга не указана' ) . '</span>';
		echo '<span>Тренер: ' . esc_html( $trainer_id ? get_the_title( $trainer_id ) : '—' ) . '</span>';
		echo '<span>Лошадь: ' . esc_html( $horse_id ? get_the_title( $horse_id ) : '—' ) . '</span>';
		echo '<em>' . esc_html( isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status ) . '</em>';
		echo '</article>';
	}

	private static function render_styles() {
		echo '<style>
		.hcos-calendar-filters,.hcos-calendar-navigation{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:18px 0}.hcos-calendar-filters label{display:flex;gap:6px;align-items:center}.hcos-calendar-filters select{min-width:180px}.hcos-calendar-scroll{overflow-x:auto}.hcos-calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(180px,1fr));gap:10px;min-width:1260px}.hcos-calendar-day{background:#fff;border:1px solid #dcdcde;border-radius:6px;min-height:360px;padding:8px}.hcos-calendar-day.is-today{border:2px solid #2271b1}.hcos-calendar-day h2{display:flex;justify-content:space-between;margin:0 0 8px;padding:6px;font-size:14px}.hcos-calendar-day h2 span{background:#f0f0f1;border-radius:10px;padding:1px 7px}.hcos-calendar-empty{color:#646970;text-align:center}.hcos-calendar-lesson{display:flex;flex-direction:column;gap:3px;border-left:4px solid #72aee6;background:#f6f7f7;margin-bottom:8px;padding:8px;border-radius:3px}.hcos-calendar-lesson span,.hcos-calendar-lesson em{font-size:12px}.hcos-calendar-lesson em{font-style:normal;color:#50575e}.hcos-calendar-lesson.status-completed{border-left-color:#00a32a}.hcos-calendar-lesson.status-cancelled,.hcos-calendar-lesson.status-cancelled_by_client,.hcos-calendar-lesson.status-cancelled_by_club{border-left-color:#d63638;opacity:.75}.hcos-calendar-lesson.status-confirmed{border-left-color:#8c8f94}.hcos-calendar-lesson.status-no_show{border-left-color:#dba617}.hcos-calendar-time{font-weight:700;text-decoration:none}
		</style>';
	}
}
