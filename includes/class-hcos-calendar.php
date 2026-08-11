<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Calendar {
	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'acf/load_value/name=lesson_date', array( __CLASS__, 'prefill_lesson_date' ), 10, 3 );
	}

	public static function prefill_lesson_date( $value, $post_id, $field ) {
		if ( $value || ! isset( $_GET['hcos_date'] ) || 'new_post' !== $post_id ) {
			return $value;
		}
		$date = sanitize_text_field( wp_unslash( $_GET['hcos_date'] ) );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? str_replace( '-', '', $date ) : $value;
	}

	public static function register_page() {
		self::$hook = add_submenu_page(
			'edit.php?post_type=lessons',
			'Расписание занятий',
			'Расписание',
			'edit_hcos_lessons',
			'hcos-calendar',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( self::$hook !== $hook ) {
			return;
		}
		wp_enqueue_style( 'hcos-dashboard', plugins_url( 'assets/css/admin-dashboard.css', HCOS_PLUGIN_FILE ), array(), HCOS_VERSION );
		wp_enqueue_style( 'hcos-calendar', plugins_url( 'assets/css/admin-calendar.css', HCOS_PLUGIN_FILE ), array( 'hcos-dashboard' ), HCOS_VERSION );
	}

	public static function render_page() {
		if ( ! current_user_can( 'edit_hcos_lessons' ) ) {
			wp_die( esc_html__( 'Недостаточно прав для просмотра расписания.', 'horse-club-os' ) );
		}

		$week_start = self::requested_week();
		$week_end   = $week_start->modify( '+6 days' );
		$trainer_id = isset( $_GET['hcos_trainer'] ) ? absint( $_GET['hcos_trainer'] ) : 0;
		$horse_id   = isset( $_GET['hcos_horse'] ) ? absint( $_GET['hcos_horse'] ) : 0;
		$lessons    = self::get_lessons( $week_start, $week_end, $trainer_id, $horse_id );
		$days       = self::group_by_day( $lessons, $week_start );
		?>
		<div class="hcos-app hcos-calendar-app">
			<?php HCOS_Dashboard::sidebar( 'calendar' ); ?>
			<main class="hcos-main hcos-calendar-main">
				<?php self::render_header( $week_start, $week_end, $trainer_id, $horse_id, count( $lessons ) ); ?>
				<?php self::render_filters( $week_start, $trainer_id, $horse_id ); ?>
				<?php self::render_grid( $days ); ?>
			</main>
		</div>
		<?php
	}

	private static function requested_week() {
		$value  = isset( $_GET['hcos_week'] ) ? sanitize_text_field( wp_unslash( $_GET['hcos_week'] ) ) : current_time( 'Y-m-d' );
		$anchor = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		if ( ! $anchor ) {
			$anchor = new DateTimeImmutable( 'today', wp_timezone() );
		}
		return $anchor->modify( 'monday this week' );
	}

	private static function render_header( $week_start, $week_end, $trainer_id, $horse_id, $count ) {
		$previous = self::calendar_url( $week_start->modify( '-7 days' ), $trainer_id, $horse_id );
		$current  = self::calendar_url( new DateTimeImmutable( 'today', wp_timezone() ), $trainer_id, $horse_id );
		$next     = self::calendar_url( $week_start->modify( '+7 days' ), $trainer_id, $horse_id );
		?>
		<header class="hcos-header hcos-calendar-header">
			<div>
				<h1>Расписание</h1>
				<p><?php echo esc_html( wp_date( 'j F', $week_start->getTimestamp(), wp_timezone() ) . ' — ' . wp_date( 'j F Y', $week_end->getTimestamp(), wp_timezone() ) ); ?> · <?php echo esc_html( self::plural( $count, 'занятие', 'занятия', 'занятий' ) ); ?></p>
			</div>
			<div class="hcos-header-actions">
				<div class="hcos-date-switcher hcos-week-switcher"><a href="<?php echo esc_url( $previous ); ?>">‹</a><a href="<?php echo esc_url( $current ); ?>">Текущая неделя</a><a href="<?php echo esc_url( $next ); ?>">›</a></div>
				<a class="hcos-primary-button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=lessons' ) ); ?>">＋ Добавить занятие</a>
			</div>
		</header>
		<?php
	}

	private static function render_filters( $week_start, $trainer_id, $horse_id ) {
		$trainers = get_posts( array( 'post_type' => 'trainers', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true ) );
		$horses   = get_posts( array( 'post_type' => 'horses', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true ) );
		?>
		<form class="hcos-calendar-filters" method="get">
			<input type="hidden" name="post_type" value="lessons">
			<input type="hidden" name="page" value="hcos-calendar">
			<label><span>Неделя</span><input type="date" name="hcos_week" value="<?php echo esc_attr( $week_start->format( 'Y-m-d' ) ); ?>"></label>
			<?php self::render_select( 'hcos_trainer', 'Все тренеры', $trainers, $trainer_id ); ?>
			<?php self::render_select( 'hcos_horse', 'Все лошади', $horses, $horse_id ); ?>
			<button type="submit">Показать</button>
			<?php if ( $trainer_id || $horse_id ) : ?><a href="<?php echo esc_url( self::calendar_url( $week_start ) ); ?>">Сбросить</a><?php endif; ?>
		</form>
		<?php
	}

	private static function render_select( $name, $empty_label, $posts, $selected_id ) {
		echo '<label><span>' . esc_html( $empty_label ) . '</span><select name="' . esc_attr( $name ) . '">';
		echo '<option value="">' . esc_html( $empty_label ) . '</option>';
		foreach ( $posts as $post ) {
			echo '<option value="' . esc_attr( $post->ID ) . '" ' . selected( $selected_id, $post->ID, false ) . '>' . esc_html( get_the_title( $post ) ) . '</option>';
		}
		echo '</select></label>';
	}

	private static function get_lessons( $week_start, $week_end, $trainer_id, $horse_id ) {
		$meta_query = array(
			array( 'key' => 'lesson_date', 'value' => array( $week_start->format( 'Ymd' ), $week_end->format( 'Ymd' ) ), 'compare' => 'BETWEEN', 'type' => 'NUMERIC' ),
		);
		if ( $trainer_id ) {
			$meta_query[] = array( 'key' => 'lesson_trainer', 'value' => $trainer_id, 'compare' => '=' );
		}
		if ( $horse_id ) {
			$meta_query[] = array( 'key' => 'lesson_horse', 'value' => $horse_id, 'compare' => '=' );
		}
		$lessons = get_posts( array( 'post_type' => 'lessons', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => -1, 'meta_query' => $meta_query, 'no_found_rows' => true ) );
		usort( $lessons, static function ( $left, $right ) {
			return strcmp( (string) get_post_meta( $left->ID, 'lesson_date', true ) . get_post_meta( $left->ID, 'lesson_time', true ), (string) get_post_meta( $right->ID, 'lesson_date', true ) . get_post_meta( $right->ID, 'lesson_time', true ) );
		} );
		return $lessons;
	}

	private static function group_by_day( $lessons, $week_start ) {
		$days = array();
		for ( $offset = 0; $offset < 7; $offset++ ) {
			$days[ $week_start->modify( '+' . $offset . ' days' )->format( 'Ymd' ) ] = array();
		}
		foreach ( $lessons as $lesson ) {
			$key = preg_replace( '/[^0-9]/', '', (string) get_post_meta( $lesson->ID, 'lesson_date', true ) );
			if ( isset( $days[ $key ] ) ) {
				$days[ $key ][] = $lesson;
			}
		}
		return $days;
	}

	private static function render_grid( $days ) {
		$today = current_time( 'Ymd' );
		echo '<div class="hcos-calendar-scroll"><div class="hcos-calendar-grid">';
		foreach ( $days as $date_key => $lessons ) {
			$day = DateTimeImmutable::createFromFormat( '!Ymd', $date_key, wp_timezone() );
			echo '<section class="hcos-calendar-day' . ( $today === $date_key ? ' is-today' : '' ) . '">';
			echo '<header><span>' . esc_html( wp_date( 'D', $day->getTimestamp(), wp_timezone() ) ) . '</span><strong>' . esc_html( wp_date( 'j', $day->getTimestamp(), wp_timezone() ) ) . '</strong><small>' . esc_html( self::plural( count( $lessons ), 'занятие', 'занятия', 'занятий' ) ) . '</small></header>';
			if ( ! $lessons ) {
				echo '<div class="hcos-calendar-empty"><span>Свободный день</span><a href="' . esc_url( add_query_arg( 'hcos_date', $day->format( 'Y-m-d' ), admin_url( 'post-new.php?post_type=lessons' ) ) ) . '">＋ Добавить</a></div>';
			}
			foreach ( $lessons as $lesson ) {
				self::render_lesson( $lesson );
			}
			echo '</section>';
		}
		echo '</div></div>';
	}

	private static function render_lesson( $lesson ) {
		$status      = (string) get_post_meta( $lesson->ID, 'lesson_status', true ) ?: 'planned';
		$time        = substr( (string) get_post_meta( $lesson->ID, 'lesson_time', true ), 0, 5 );
		$end_time    = substr( (string) get_post_meta( $lesson->ID, 'lesson_end_time', true ), 0, 5 );
		$trainer_id  = absint( get_post_meta( $lesson->ID, 'lesson_trainer', true ) );
		$horse_id    = absint( get_post_meta( $lesson->ID, 'lesson_horse', true ) );
		$service_id  = absint( get_post_meta( $lesson->ID, 'lesson_service', true ) );
		$booking_ids = HCOS_Bookings::get_active_booking_ids( $lesson->ID );
		$riders      = array();
		foreach ( $booking_ids as $booking_id ) {
			$rider_id = absint( get_post_meta( $booking_id, 'booking_rider', true ) );
			if ( $rider_id ) {
				$riders[] = get_the_title( $rider_id );
			}
		}
		if ( ! $riders ) {
			$legacy_id = absint( get_post_meta( $lesson->ID, 'lesson_client', true ) );
			if ( $legacy_id ) {
				$riders[] = get_the_title( $legacy_id );
			}
		}
		$capacity = HCOS_Bookings::get_lesson_capacity( $lesson->ID );
		$labels   = array( 'planned' => 'Запланировано', 'confirmed' => 'Подтверждено', 'completed' => 'Проведено', 'cancelled_by_client' => 'Отмена клиентом', 'cancelled_by_club' => 'Отмена клубом', 'no_show' => 'Неявка', 'rescheduled' => 'Перенесено', 'cancelled' => 'Отменено' );
		?>
		<article class="hcos-calendar-lesson status-<?php echo esc_attr( sanitize_html_class( $status ) ); ?>">
			<div class="hcos-calendar-lesson-top"><a href="<?php echo esc_url( get_edit_post_link( $lesson->ID ) ); ?>"><?php echo esc_html( $time . ( $end_time ? '–' . $end_time : '' ) ); ?></a><span><?php echo esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ); ?></span></div>
			<strong><?php echo esc_html( $service_id ? get_the_title( $service_id ) : get_the_title( $lesson ) ); ?></strong>
			<p><?php echo esc_html( $riders ? implode( ', ', array_unique( $riders ) ) : 'Нет записей' ); ?></p>
			<small><?php echo esc_html( ( $horse_id ? get_the_title( $horse_id ) : 'Лошадь не выбрана' ) . ' · ' . ( $trainer_id ? get_the_title( $trainer_id ) : 'Тренер не выбран' ) ); ?></small>
			<footer><span>Мест: <?php echo esc_html( count( $booking_ids ) . '/' . $capacity ); ?></span><a href="<?php echo esc_url( get_edit_post_link( $lesson->ID ) ); ?>">Открыть →</a></footer>
		</article>
		<?php
	}

	private static function calendar_url( $week, $trainer_id = 0, $horse_id = 0 ) {
		$args = array( 'post_type' => 'lessons', 'page' => 'hcos-calendar', 'hcos_week' => $week->format( 'Y-m-d' ) );
		if ( $trainer_id ) {
			$args['hcos_trainer'] = $trainer_id;
		}
		if ( $horse_id ) {
			$args['hcos_horse'] = $horse_id;
		}
		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	private static function plural( $number, $one, $few, $many ) {
		$a = $number % 10;
		$b = $number % 100;
		$word = 1 === $a && 11 !== $b ? $one : ( $a >= 2 && $a <= 4 && ( $b < 12 || $b > 14 ) ? $few : $many );
		return $number . ' ' . $word;
	}
}
