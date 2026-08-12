<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Services_Screen {
	private static $hook = '';

	private static $status_labels = array(
		'active'   => 'Активна',
		'inactive' => 'Неактивна',
	);

	private static $category_labels = array(
		'training'   => 'Обучение',
		'sport'      => 'Спортивная тренировка',
		'trail'      => 'Прогулка',
		'children'   => 'Детское занятие',
		'horse_care' => 'Уход за лошадью',
		'event'      => 'Мероприятие',
		'other'      => 'Другое',
	);

	private static $level_labels = array(
		'beginner'     => 'Начинающий',
		'intermediate' => 'Средний',
		'advanced'     => 'Продвинутый',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_page() {
		self::$hook = add_submenu_page(
			'edit.php?post_type=services',
			'Услуги Horse Club OS',
			'Обзор услуг',
			'edit_hcos_services',
			'hcos-services',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( self::$hook !== $hook ) {
			return;
		}
		wp_enqueue_style( 'hcos-dashboard', plugins_url( 'assets/css/admin-dashboard.css', HCOS_PLUGIN_FILE ), array(), HCOS_VERSION );
		wp_enqueue_style( 'hcos-services-screen', plugins_url( 'assets/css/admin-services.css', HCOS_PLUGIN_FILE ), array( 'hcos-dashboard' ), HCOS_VERSION );
	}

	public static function render_page() {
		if ( ! current_user_can( 'edit_hcos_services' ) ) {
			wp_die( esc_html__( 'Недостаточно прав для просмотра услуг.', 'horse-club-os' ) );
		}

		$search = isset( $_GET['hcos_search'] ) ? sanitize_text_field( wp_unslash( $_GET['hcos_search'] ) ) : '';
		$status = isset( $_GET['hcos_status'] ) ? sanitize_key( wp_unslash( $_GET['hcos_status'] ) ) : 'all';
		if ( 'all' !== $status && ! isset( self::$status_labels[ $status ] ) ) {
			$status = 'all';
		}

		$services = self::get_services();
		$usage    = self::today_usage();
		$summary  = self::summary( $services, $usage );
		$filtered = self::filter( $services, $search, $status );
		?>
		<div class="hcos-app hcos-services-app">
			<?php HCOS_Dashboard::sidebar( 'services' ); ?>
			<main class="hcos-main hcos-services-main">
				<header class="hcos-header hcos-services-header">
					<div><h1>Услуги</h1><p><?php echo esc_html( self::plural( count( $services ), 'услуга', 'услуги', 'услуг' ) . ' в каталоге клуба' ); ?></p></div>
					<div class="hcos-header-actions"><a class="hcos-services-secondary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=lessons&page=hcos-calendar' ) ); ?>">Расписание</a><a class="hcos-primary-button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=services' ) ); ?>">＋ Новая услуга</a></div>
				</header>

				<section class="hcos-services-summary">
					<?php self::stat( 'Всего', $summary['total'], 'в каталоге клуба', '' ); ?>
					<?php self::stat( 'Активные', $summary['active'], 'доступны для записи', 'active' ); ?>
					<?php self::stat( 'Категории', $summary['categories'], 'используется в каталоге', 'categories' ); ?>
					<?php self::stat( 'Занятий сегодня', $summary['lessons'], self::plural( $summary['used'], 'услуга востребована', 'услуги востребованы', 'услуг востребованы' ), 'usage' ); ?>
				</section>

				<form class="hcos-services-filters" method="get">
					<input type="hidden" name="post_type" value="services"><input type="hidden" name="page" value="hcos-services">
					<label class="hcos-services-search"><span class="screen-reader-text">Поиск</span><input type="search" name="hcos_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Поиск по названию, категории или тренеру"></label>
					<label><span class="screen-reader-text">Статус</span><select name="hcos_status"><option value="all">Все статусы</option><?php foreach ( self::$status_labels as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
					<button type="submit">Показать</button><a href="<?php echo esc_url( self::list_url() ); ?>">Сбросить</a>
				</form>

				<section class="hcos-services-panel"><div class="hcos-services-panel-heading"><div><h2>Каталог услуг</h2><p><?php echo esc_html( 'Найдено: ' . count( $filtered ) ); ?></p></div><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=services' ) ); ?>">Стандартный список</a></div><?php self::render_table( $filtered, $usage ); ?></section>
			</main>
		</div>
		<?php
	}

	private static function get_services() {
		return get_posts( array( 'post_type' => 'services', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true ) );
	}

	private static function filter( $items, $search, $status ) {
		$needle = self::lower( trim( $search ) );
		return array_values( array_filter( $items, static function ( $item ) use ( $needle, $status ) {
			$item_status = (string) get_post_meta( $item->ID, 'service_status', true ) ?: 'active';
			if ( 'all' !== $status && $status !== $item_status ) {
				return false;
			}
			if ( '' === $needle ) {
				return true;
			}
			$category = (string) get_post_meta( $item->ID, 'service_category', true );
			$trainers = self::relation_ids( get_post_meta( $item->ID, 'service_allowed_trainers', true ) );
			$names    = array_map( 'get_the_title', $trainers );
			$text     = $item->post_title . ' ' . ( isset( self::$category_labels[ $category ] ) ? self::$category_labels[ $category ] : $category ) . ' ' . implode( ' ', $names );
			return false !== strpos( self::lower( $text ), $needle );
		} ) );
	}

	private static function today_usage() {
		$date = wp_date( 'Ymd', null, wp_timezone() );
		$ids  = get_posts( array( 'post_type' => 'lessons', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => array( array( 'key' => 'lesson_date', 'value' => $date, 'compare' => '=', 'type' => 'NUMERIC' ) ) ) );
		$data = array();
		foreach ( $ids as $lesson_id ) {
			$status = (string) get_post_meta( $lesson_id, 'lesson_status', true );
			if ( in_array( $status, array( 'cancelled', 'cancelled_by_client', 'cancelled_by_club', 'rescheduled' ), true ) ) {
				continue;
			}
			$service_id = absint( get_post_meta( $lesson_id, 'lesson_service', true ) );
			if ( ! $service_id ) {
				continue;
			}
			if ( ! isset( $data[ $service_id ] ) ) {
				$data[ $service_id ] = array( 'lessons' => 0, 'bookings' => 0 );
			}
			$data[ $service_id ]['lessons']++;
			$data[ $service_id ]['bookings'] += count( HCOS_Bookings::get_active_booking_ids( $lesson_id ) );
		}
		return $data;
	}

	private static function summary( $items, $usage ) {
		$categories = array();
		$data       = array( 'total' => count( $items ), 'active' => 0, 'categories' => 0, 'lessons' => 0, 'used' => 0 );
		foreach ( $items as $item ) {
			if ( 'active' === ( (string) get_post_meta( $item->ID, 'service_status', true ) ?: 'active' ) ) {
				$data['active']++;
			}
			$category = (string) get_post_meta( $item->ID, 'service_category', true );
			if ( $category ) {
				$categories[ $category ] = true;
			}
		}
		$data['categories'] = count( $categories );
		foreach ( $usage as $load ) {
			$data['lessons'] += $load['lessons'];
			if ( $load['lessons'] ) {
				$data['used']++;
			}
		}
		return $data;
	}

	private static function stat( $label, $value, $note, $class ) {
		echo '<article class="hcos-services-stat ' . esc_attr( $class ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong><small>' . esc_html( $note ) . '</small></article>';
	}

	private static function render_table( $items, $usage ) {
		$show_finances = current_user_can( 'hcos_view_finances' );
		?>
		<div class="hcos-services-table-wrap"><table class="hcos-services-table"><thead><tr><th>Услуга</th><th>Формат</th><th>Длительность</th><?php if ( $show_finances ) : ?><th>Цена</th><?php endif; ?><th>Ограничения</th><th>Тренеры</th><th>Сегодня</th><th>Статус</th><th></th></tr></thead><tbody>
		<?php if ( ! $items ) : ?><tr><td colspan="<?php echo esc_attr( $show_finances ? 9 : 8 ); ?>"><div class="hcos-services-empty">Услуги по выбранным условиям не найдены</div></td></tr><?php endif; ?>
		<?php foreach ( $items as $item ) { self::render_row( $item, isset( $usage[ $item->ID ] ) ? $usage[ $item->ID ] : array( 'lessons' => 0, 'bookings' => 0 ), $show_finances ); } ?>
		</tbody></table></div>
		<?php
	}

	private static function render_row( $item, $usage, $show_finances ) {
		$status     = (string) get_post_meta( $item->ID, 'service_status', true ) ?: 'active';
		$category   = (string) get_post_meta( $item->ID, 'service_category', true );
		$format     = (string) get_post_meta( $item->ID, 'service_format', true ) ?: 'individual';
		$capacity   = max( 1, absint( get_post_meta( $item->ID, 'service_capacity', true ) ) );
		$duration   = absint( get_post_meta( $item->ID, 'service_duration', true ) );
		$price      = (float) get_post_meta( $item->ID, 'service_price', true );
		$membership = (bool) get_post_meta( $item->ID, 'service_membership_allowed', true );
		$min_age    = absint( get_post_meta( $item->ID, 'service_min_age', true ) );
		$max_age    = absint( get_post_meta( $item->ID, 'service_max_age', true ) );
		$levels     = get_post_meta( $item->ID, 'service_rider_levels', true );
		$levels     = is_array( $levels ) ? $levels : array_filter( array( $levels ) );
		$levels     = array_map( static function ( $value ) { return isset( HCOS_Services_Screen::$level_labels[ $value ] ) ? HCOS_Services_Screen::$level_labels[ $value ] : $value; }, $levels );
		$trainers   = self::relation_ids( get_post_meta( $item->ID, 'service_allowed_trainers', true ) );
		$names      = array_values( array_filter( array_map( 'get_the_title', $trainers ) ) );
		$age        = $min_age || $max_age ? ( $min_age ?: 0 ) . '–' . ( $max_age ?: '∞' ) . ' лет' : '';
		$rules      = array_filter( array( $levels ? implode( ', ', $levels ) : 'Все уровни', $age ) );
		?>
		<tr>
			<td><div class="hcos-services-identity"><span class="hcos-services-mark">У</span><span><a href="<?php echo esc_url( get_edit_post_link( $item->ID ) ); ?>"><?php echo esc_html( $item->post_title ); ?></a><small><?php echo esc_html( isset( self::$category_labels[ $category ] ) ? self::$category_labels[ $category ] : ( $category ?: 'Без категории' ) ); ?></small></span></div></td>
			<td><div class="hcos-services-format"><strong><?php echo esc_html( 'group' === $format ? 'Групповая' : 'Индивидуальная' ); ?></strong><small><?php echo esc_html( 'до ' . $capacity . ' чел.' ); ?></small></div></td>
			<td><span class="hcos-services-duration"><?php echo esc_html( $duration ? $duration . ' мин.' : 'Не задана' ); ?></span></td>
			<?php if ( $show_finances ) : ?><td><div class="hcos-services-price"><strong><?php echo esc_html( number_format_i18n( $price, 0 ) . ' ₽' ); ?></strong><small><?php echo $membership ? 'Можно по абонементу' : 'Только разовая оплата'; ?></small></div></td><?php endif; ?>
			<td><div class="hcos-services-rules"><?php echo esc_html( implode( ' · ', $rules ) ); ?></div></td>
			<td><div class="hcos-services-trainers"><?php echo esc_html( $names ? implode( ', ', $names ) : 'Любой активный тренер' ); ?></div></td>
			<td><div class="hcos-services-usage"><strong><?php echo esc_html( self::plural( $usage['lessons'], 'занятие', 'занятия', 'занятий' ) ); ?></strong><small><?php echo esc_html( self::plural( $usage['bookings'], 'запись', 'записи', 'записей' ) ); ?></small></div></td>
			<td><span class="hcos-services-status is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( isset( self::$status_labels[ $status ] ) ? self::$status_labels[ $status ] : $status ); ?></span></td>
			<td><a class="hcos-services-open" href="<?php echo esc_url( get_edit_post_link( $item->ID ) ); ?>">Редактировать →</a></td>
		</tr>
		<?php
	}

	private static function relation_ids( $value ) {
		$value = is_array( $value ) ? $value : array_filter( array( $value ) );
		return array_values( array_filter( array_map( 'absint', $value ) ) );
	}

	private static function list_url() { return add_query_arg( array( 'post_type' => 'services', 'page' => 'hcos-services' ), admin_url( 'edit.php' ) ); }
	private static function lower( $value ) { return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value ); }
	private static function plural( $number, $one, $few, $many ) { $a = $number % 10; $b = $number % 100; return $number . ' ' . ( 1 === $a && 11 !== $b ? $one : ( $a >= 2 && $a <= 4 && ( $b < 12 || $b > 14 ) ? $few : $many ) ); }
}
