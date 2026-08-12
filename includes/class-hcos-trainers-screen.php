<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Trainers_Screen {
	private static $hook = '';

	private static $status_labels = array(
		'active'   => 'Работает',
		'vacation' => 'Отпуск',
		'sick'     => 'Больничный',
		'inactive' => 'Не работает',
		'archived' => 'Архив',
	);

	private static $specialization_labels = array(
		'beginner_training' => 'Обучение начинающих',
		'children'          => 'Детские занятия',
		'dressage'          => 'Выездка',
		'jumping'           => 'Конкур',
		'trail'             => 'Прогулки',
		'rehabilitation'    => 'Восстановительные занятия',
		'other'             => 'Другое',
	);

	private static $day_labels = array(
		'monday'    => 'Пн',
		'tuesday'   => 'Вт',
		'wednesday' => 'Ср',
		'thursday'  => 'Чт',
		'friday'    => 'Пт',
		'saturday'  => 'Сб',
		'sunday'    => 'Вс',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_page() {
		self::$hook = add_submenu_page(
			'edit.php?post_type=trainers',
			'Тренеры Horse Club OS',
			'Обзор тренеров',
			'edit_hcos_trainers',
			'hcos-trainers',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( self::$hook !== $hook ) {
			return;
		}
		wp_enqueue_style( 'hcos-dashboard', plugins_url( 'assets/css/admin-dashboard.css', HCOS_PLUGIN_FILE ), array(), HCOS_VERSION );
		wp_enqueue_style( 'hcos-trainers-screen', plugins_url( 'assets/css/admin-trainers.css', HCOS_PLUGIN_FILE ), array( 'hcos-dashboard' ), HCOS_VERSION );
	}

	public static function render_page() {
		if ( ! current_user_can( 'edit_hcos_trainers' ) ) {
			wp_die( esc_html__( 'Недостаточно прав для просмотра тренеров.', 'horse-club-os' ) );
		}

		$search = isset( $_GET['hcos_search'] ) ? sanitize_text_field( wp_unslash( $_GET['hcos_search'] ) ) : '';
		$status = isset( $_GET['hcos_status'] ) ? sanitize_key( wp_unslash( $_GET['hcos_status'] ) ) : 'all';
		if ( 'all' !== $status && ! isset( self::$status_labels[ $status ] ) ) {
			$status = 'all';
		}

		$trainers = self::get_trainers();
		$schedule = self::schedule_data();
		$summary  = self::summary( $trainers, $schedule );
		$filtered = self::filter( $trainers, $search, $status );
		?>
		<div class="hcos-app hcos-trainers-app">
			<?php HCOS_Dashboard::sidebar( 'trainers' ); ?>
			<main class="hcos-main hcos-trainers-main">
				<header class="hcos-header hcos-trainers-header">
					<div><h1>Тренеры</h1><p><?php echo esc_html( self::plural( count( $trainers ), 'тренер', 'тренера', 'тренеров' ) . ' в команде клуба' ); ?></p></div>
					<div class="hcos-header-actions"><a class="hcos-trainers-secondary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=lessons&page=hcos-calendar' ) ); ?>">Расписание</a><a class="hcos-primary-button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=trainers' ) ); ?>">＋ Новый тренер</a></div>
				</header>

				<section class="hcos-trainers-summary">
					<?php self::stat( 'Всего', $summary['total'], 'в команде клуба', '' ); ?>
					<?php self::stat( 'Работают', $summary['active'], 'активных тренеров', 'active' ); ?>
					<?php self::stat( 'Отсутствуют', $summary['away'], 'отпуск или больничный', 'away' ); ?>
					<?php self::stat( 'Занятий сегодня', $summary['lessons'], self::plural( $summary['busy'], 'тренер занят', 'тренера заняты', 'тренеров заняты' ), 'workload' ); ?>
				</section>

				<form class="hcos-trainers-filters" method="get">
					<input type="hidden" name="post_type" value="trainers"><input type="hidden" name="page" value="hcos-trainers">
					<label class="hcos-trainers-search"><span class="screen-reader-text">Поиск</span><input type="search" name="hcos_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Поиск по имени, телефону, email или специализации"></label>
					<label><span class="screen-reader-text">Статус</span><select name="hcos_status"><option value="all">Все статусы</option><?php foreach ( self::$status_labels as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
					<button type="submit">Показать</button><a href="<?php echo esc_url( self::list_url() ); ?>">Сбросить</a>
				</form>

				<section class="hcos-trainers-panel"><div class="hcos-trainers-panel-heading"><div><h2>Команда тренеров</h2><p><?php echo esc_html( 'Найдено: ' . count( $filtered ) ); ?></p></div><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=trainers' ) ); ?>">Стандартный список</a></div><?php self::render_table( $filtered, $schedule ); ?></section>
			</main>
		</div>
		<?php
	}

	private static function get_trainers() {
		return get_posts( array( 'post_type' => 'trainers', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true ) );
	}

	private static function filter( $items, $search, $status ) {
		$needle = self::lower( trim( $search ) );
		return array_values( array_filter( $items, static function ( $item ) use ( $needle, $status ) {
			$item_status = (string) get_post_meta( $item->ID, 'trainer_status', true ) ?: 'active';
			if ( 'all' !== $status && $status !== $item_status ) {
				return false;
			}
			if ( '' === $needle ) {
				return true;
			}
			$specializations = get_post_meta( $item->ID, 'trainer_specializations', true );
			$specializations = is_array( $specializations ) ? $specializations : array_filter( array( $specializations ) );
			$specializations = array_map( static function ( $value ) { return isset( HCOS_Trainers_Screen::$specialization_labels[ $value ] ) ? HCOS_Trainers_Screen::$specialization_labels[ $value ] : $value; }, $specializations );
			$text = $item->post_title . ' ' . get_post_meta( $item->ID, 'trainer_phone', true ) . ' ' . get_post_meta( $item->ID, 'trainer_email', true ) . ' ' . implode( ' ', $specializations );
			return false !== strpos( HCOS_Trainers_Screen::lower( $text ), $needle );
		} ) );
	}

	private static function schedule_data() {
		$today = wp_date( 'Ymd', null, wp_timezone() );
		$now   = wp_date( 'Hi', null, wp_timezone() );
		$ids   = get_posts( array( 'post_type' => 'lessons', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => array( array( 'key' => 'lesson_date', 'value' => $today, 'compare' => '>=', 'type' => 'NUMERIC' ) ) ) );
		usort( $ids, static function ( $a, $b ) {
			$left  = preg_replace( '/\D+/', '', (string) get_post_meta( $a, 'lesson_date', true ) ) . preg_replace( '/\D+/', '', (string) get_post_meta( $a, 'lesson_time', true ) );
			$right = preg_replace( '/\D+/', '', (string) get_post_meta( $b, 'lesson_date', true ) ) . preg_replace( '/\D+/', '', (string) get_post_meta( $b, 'lesson_time', true ) );
			return strcmp( $left, $right );
		} );

		$data = array();
		foreach ( $ids as $lesson_id ) {
			$status = (string) get_post_meta( $lesson_id, 'lesson_status', true );
			if ( in_array( $status, array( 'cancelled', 'cancelled_by_client', 'cancelled_by_club', 'rescheduled' ), true ) ) {
				continue;
			}
			$trainer_id = absint( get_post_meta( $lesson_id, 'lesson_trainer', true ) );
			if ( ! $trainer_id ) {
				continue;
			}
			if ( ! isset( $data[ $trainer_id ] ) ) {
				$data[ $trainer_id ] = array( 'lessons' => 0, 'minutes' => 0, 'next' => 0 );
			}
			$date = preg_replace( '/\D+/', '', (string) get_post_meta( $lesson_id, 'lesson_date', true ) );
			$time = preg_replace( '/\D+/', '', (string) get_post_meta( $lesson_id, 'lesson_time', true ) );
			if ( $today === $date && 'no_show' !== $status ) {
				$data[ $trainer_id ]['lessons']++;
				$data[ $trainer_id ]['minutes'] += max( 0, absint( get_post_meta( $lesson_id, 'lesson_duration', true ) ) );
			}
			if ( ! $data[ $trainer_id ]['next'] && ( $date > $today || ( $date === $today && substr( $time, 0, 4 ) >= $now ) ) && ! in_array( $status, array( 'completed', 'no_show' ), true ) ) {
				$data[ $trainer_id ]['next'] = $lesson_id;
			}
		}
		return $data;
	}

	private static function summary( $items, $schedule ) {
		$data = array( 'total' => count( $items ), 'active' => 0, 'away' => 0, 'lessons' => 0, 'busy' => 0 );
		foreach ( $items as $item ) {
			$status = (string) get_post_meta( $item->ID, 'trainer_status', true ) ?: 'active';
			if ( 'active' === $status ) {
				$data['active']++;
			}
			if ( in_array( $status, array( 'vacation', 'sick' ), true ) ) {
				$data['away']++;
			}
		}
		foreach ( $schedule as $load ) {
			$data['lessons'] += $load['lessons'];
			if ( $load['lessons'] ) {
				$data['busy']++;
			}
		}
		return $data;
	}

	private static function stat( $label, $value, $note, $class ) {
		echo '<article class="hcos-trainers-stat ' . esc_attr( $class ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong><small>' . esc_html( $note ) . '</small></article>';
	}

	private static function render_table( $items, $schedule ) {
		?>
		<div class="hcos-trainers-table-wrap"><table class="hcos-trainers-table"><thead><tr><th>Тренер</th><th>Специализация</th><th>Базовый график</th><th>Нагрузка сегодня</th><th>Ближайшее занятие</th><th>Статус</th><th></th></tr></thead><tbody>
		<?php if ( ! $items ) : ?><tr><td colspan="7"><div class="hcos-trainers-empty">Тренеры по выбранным условиям не найдены</div></td></tr><?php endif; ?>
		<?php foreach ( $items as $item ) { self::render_row( $item, isset( $schedule[ $item->ID ] ) ? $schedule[ $item->ID ] : array( 'lessons' => 0, 'minutes' => 0, 'next' => 0 ) ); } ?>
		</tbody></table></div>
		<?php
	}

	private static function render_row( $item, $load ) {
		$status   = (string) get_post_meta( $item->ID, 'trainer_status', true ) ?: 'active';
		$photo_id = absint( get_post_meta( $item->ID, 'trainer_photo', true ) );
		$phone    = trim( (string) get_post_meta( $item->ID, 'trainer_phone', true ) );
		$email    = trim( (string) get_post_meta( $item->ID, 'trainer_email', true ) );
		$days     = get_post_meta( $item->ID, 'trainer_work_days', true );
		$days     = is_array( $days ) ? $days : array_filter( array( $days ) );
		$days     = array_map( static function ( $value ) { return isset( HCOS_Trainers_Screen::$day_labels[ $value ] ) ? HCOS_Trainers_Screen::$day_labels[ $value ] : $value; }, $days );
		$start    = substr( (string) get_post_meta( $item->ID, 'trainer_work_start', true ), 0, 5 );
		$end      = substr( (string) get_post_meta( $item->ID, 'trainer_work_end', true ), 0, 5 );
		$maximum  = absint( get_post_meta( $item->ID, 'trainer_max_daily_lessons', true ) );
		$specializations = get_post_meta( $item->ID, 'trainer_specializations', true );
		$specializations = is_array( $specializations ) ? $specializations : array_filter( array( $specializations ) );
		$specializations = array_map( static function ( $value ) { return isset( HCOS_Trainers_Screen::$specialization_labels[ $value ] ) ? HCOS_Trainers_Screen::$specialization_labels[ $value ] : $value; }, $specializations );
		$calendar_url = add_query_arg( 'hcos_trainer', $item->ID, admin_url( 'edit.php?post_type=lessons&page=hcos-calendar' ) );
		?>
		<tr>
			<td><div class="hcos-trainers-identity"><?php if ( $photo_id ) : ?><?php echo wp_get_attachment_image( $photo_id, array( 46, 46 ), false, array( 'class' => 'hcos-trainers-photo' ) ); ?><?php else : ?><span class="hcos-trainers-placeholder"><?php echo esc_html( self::initials( $item->post_title ) ); ?></span><?php endif; ?><span><a href="<?php echo esc_url( get_edit_post_link( $item->ID ) ); ?>"><?php echo esc_html( $item->post_title ); ?></a><small><?php echo esc_html( implode( ' · ', array_filter( array( $phone, $email ) ) ) ?: 'Контакты не указаны' ); ?></small></span></div></td>
			<td><div class="hcos-trainers-specializations"><?php echo esc_html( $specializations ? implode( ', ', $specializations ) : 'Без специализации' ); ?></div></td>
			<td><div class="hcos-trainers-schedule"><strong><?php echo esc_html( $days ? implode( ', ', $days ) : 'Дни не указаны' ); ?></strong><small><?php echo esc_html( $start && $end ? $start . ' — ' . $end : 'Время не указано' ); ?></small></div></td>
			<td><div class="hcos-trainers-load"><strong><?php echo esc_html( $load['lessons'] . ' / ' . $load['minutes'] . ' мин.' ); ?></strong><small><?php echo esc_html( $maximum ? 'лимит ' . $maximum . ' занятий' : 'лимит не задан' ); ?></small></div></td>
			<td><?php self::next_lesson( $load['next'], $calendar_url ); ?></td>
			<td><span class="hcos-trainers-status is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( isset( self::$status_labels[ $status ] ) ? self::$status_labels[ $status ] : $status ); ?></span></td>
			<td><a class="hcos-trainers-open" href="<?php echo esc_url( get_edit_post_link( $item->ID ) ); ?>">Редактировать →</a></td>
		</tr>
		<?php
	}

	private static function next_lesson( $lesson_id, $calendar_url ) {
		if ( ! $lesson_id ) {
			echo '<a class="hcos-trainers-calendar" href="' . esc_url( $calendar_url ) . '">Нет запланированных</a>';
			return;
		}
		$date       = self::date( get_post_meta( $lesson_id, 'lesson_date', true ) );
		$time       = substr( (string) get_post_meta( $lesson_id, 'lesson_time', true ), 0, 5 );
		$service_id = absint( get_post_meta( $lesson_id, 'lesson_service', true ) );
		echo '<div class="hcos-trainers-next"><a href="' . esc_url( get_edit_post_link( $lesson_id ) ) . '">' . esc_html( trim( $date . ' ' . $time ) ) . '</a><small>' . esc_html( $service_id ? get_the_title( $service_id ) : get_the_title( $lesson_id ) ) . '</small></div>';
	}

	private static function list_url() { return add_query_arg( array( 'post_type' => 'trainers', 'page' => 'hcos-trainers' ), admin_url( 'edit.php' ) ); }
	private static function lower( $value ) { return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value ); }
	private static function date( $value ) { $value = preg_replace( '/\D+/', '', (string) $value ); if ( 8 !== strlen( $value ) ) { return ''; } $date = DateTimeImmutable::createFromFormat( '!Ymd', $value, wp_timezone() ); return $date ? wp_date( 'd.m.Y', $date->getTimestamp(), wp_timezone() ) : ''; }
	private static function initials( $name ) { $result = ''; foreach ( array_slice( preg_split( '/\s+/u', trim( $name ) ), 0, 2 ) as $part ) { $result .= function_exists( 'mb_substr' ) ? mb_substr( $part, 0, 1 ) : substr( $part, 0, 1 ); } return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $result ) : strtoupper( $result ); }
	private static function plural( $number, $one, $few, $many ) { $a = $number % 10; $b = $number % 100; return $number . ' ' . ( 1 === $a && 11 !== $b ? $one : ( $a >= 2 && $a <= 4 && ( $b < 12 || $b > 14 ) ? $few : $many ) ); }
}
