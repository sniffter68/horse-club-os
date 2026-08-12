<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Horses_Screen {
	private static $hook = '';

	private static $status_labels = array(
		'active'      => 'Активна',
		'rest'        => 'Отдых',
		'unavailable' => 'Недоступна',
		'archived'    => 'Архив',
	);

	private static $level_labels = array(
		'beginner'     => 'Начинающий',
		'intermediate' => 'Средний',
		'advanced'     => 'Продвинутый',
	);

	private static $specialization_labels = array(
		'training' => 'Обучение',
		'dressage' => 'Выездка',
		'jumping'  => 'Конкур',
		'trail'    => 'Прогулки',
		'children' => 'Детские занятия',
		'other'    => 'Другое',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_page() {
		self::$hook = add_submenu_page(
			'edit.php?post_type=horses',
			'Лошади Horse Club OS',
			'Обзор лошадей',
			'edit_hcos_horses',
			'hcos-horses',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( self::$hook !== $hook ) {
			return;
		}
		wp_enqueue_style( 'hcos-dashboard', plugins_url( 'assets/css/admin-dashboard.css', HCOS_PLUGIN_FILE ), array(), HCOS_VERSION );
		wp_enqueue_style( 'hcos-horses-screen', plugins_url( 'assets/css/admin-horses.css', HCOS_PLUGIN_FILE ), array( 'hcos-dashboard' ), HCOS_VERSION );
	}

	public static function render_page() {
		if ( ! current_user_can( 'edit_hcos_horses' ) ) {
			wp_die( esc_html__( 'Недостаточно прав для просмотра лошадей.', 'horse-club-os' ) );
		}

		$search = isset( $_GET['hcos_search'] ) ? sanitize_text_field( wp_unslash( $_GET['hcos_search'] ) ) : '';
		$status = isset( $_GET['hcos_status'] ) ? sanitize_key( wp_unslash( $_GET['hcos_status'] ) ) : 'all';
		if ( 'all' !== $status && ! isset( self::$status_labels[ $status ] ) ) {
			$status = 'all';
		}

		$horses   = self::get_horses();
		$workload = self::today_workload();
		$summary  = self::summary( $horses, $workload );
		$filtered = self::filter( $horses, $search, $status );
		?>
		<div class="hcos-app hcos-horses-app">
			<?php HCOS_Dashboard::sidebar( 'horses' ); ?>
			<main class="hcos-main hcos-horses-main">
				<header class="hcos-header hcos-horses-header"><div><h1>Лошади</h1><p><?php echo esc_html( self::plural( count( $horses ), 'лошадь', 'лошади', 'лошадей' ) . ' в базе клуба' ); ?></p></div><div class="hcos-header-actions"><a class="hcos-horses-secondary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=lessons&page=hcos-calendar' ) ); ?>">Расписание</a><a class="hcos-primary-button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=horses' ) ); ?>">＋ Новая лошадь</a></div></header>

				<section class="hcos-horses-summary">
					<?php self::stat( 'Всего', $summary['total'], 'в базе клуба', '' ); ?>
					<?php self::stat( 'Активные', $summary['active'], 'доступны для работы', 'active' ); ?>
					<?php self::stat( 'Не в работе', $summary['unavailable'], 'отдых или недоступность', 'unavailable' ); ?>
					<?php self::stat( 'Занятий сегодня', $summary['lessons'], self::plural( $summary['busy'], 'лошадь занята', 'лошади заняты', 'лошадей заняты' ), 'workload' ); ?>
				</section>

				<form class="hcos-horses-filters" method="get">
					<input type="hidden" name="post_type" value="horses"><input type="hidden" name="page" value="hcos-horses">
					<label class="hcos-horses-search"><span class="screen-reader-text">Поиск</span><input type="search" name="hcos_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Поиск по кличке, породе, масти, владельцу или номеру"></label>
					<label><span class="screen-reader-text">Статус</span><select name="hcos_status"><option value="all">Все статусы</option><?php foreach ( self::$status_labels as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
					<button type="submit">Показать</button><a href="<?php echo esc_url( self::list_url() ); ?>">Сбросить</a>
				</form>

				<section class="hcos-horses-panel"><div class="hcos-horses-panel-heading"><div><h2>Список лошадей</h2><p><?php echo esc_html( 'Найдено: ' . count( $filtered ) ); ?></p></div><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=horses' ) ); ?>">Стандартный список</a></div><?php self::render_table( $filtered, $workload ); ?></section>
			</main>
		</div>
		<?php
	}

	private static function get_horses() {
		return get_posts( array( 'post_type' => 'horses', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true ) );
	}

	private static function filter( $items, $search, $status ) {
		$needle = self::lower( trim( $search ) );
		return array_values( array_filter( $items, static function ( $item ) use ( $needle, $status ) {
			$item_status = (string) get_post_meta( $item->ID, 'horse_status', true ) ?: 'active';
			if ( 'all' !== $status && $status !== $item_status ) { return false; }
			if ( '' === $needle ) { return true; }
			$owner_id = absint( get_post_meta( $item->ID, 'horse_owner', true ) );
			$text = $item->post_title . ' ' . get_post_meta( $item->ID, 'horse_registered_name', true ) . ' ' . get_post_meta( $item->ID, 'horse_breed', true ) . ' ' . get_post_meta( $item->ID, 'horse_color', true ) . ' ' . get_post_meta( $item->ID, 'horse_stable_number', true ) . ' ' . get_post_meta( $item->ID, 'horse_inventory_number', true ) . ' ' . get_the_title( $owner_id );
			return false !== strpos( HCOS_Horses_Screen::lower( $text ), $needle );
		} ) );
	}

	private static function summary( $items, $workload ) {
		$data = array( 'total' => count( $items ), 'active' => 0, 'unavailable' => 0, 'lessons' => 0, 'busy' => count( $workload ) );
		foreach ( $items as $item ) {
			$status = (string) get_post_meta( $item->ID, 'horse_status', true ) ?: 'active';
			if ( 'active' === $status ) { $data['active']++; }
			if ( in_array( $status, array( 'rest', 'unavailable' ), true ) ) { $data['unavailable']++; }
		}
		foreach ( $workload as $load ) { $data['lessons'] += $load['lessons']; }
		return $data;
	}

	private static function today_workload() {
		$date = wp_date( 'Ymd', null, wp_timezone() );
		$ids  = get_posts( array( 'post_type' => 'lessons', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => array( array( 'key' => 'lesson_date', 'value' => $date, 'compare' => '=', 'type' => 'NUMERIC' ) ) ) );
		$data = array();
		foreach ( $ids as $lesson_id ) {
			$status = (string) get_post_meta( $lesson_id, 'lesson_status', true );
			if ( in_array( $status, array( 'cancelled', 'no_show' ), true ) ) { continue; }
			$horse_id = absint( get_post_meta( $lesson_id, 'lesson_horse', true ) );
			if ( ! $horse_id ) { continue; }
			if ( ! isset( $data[ $horse_id ] ) ) { $data[ $horse_id ] = array( 'lessons' => 0, 'minutes' => 0 ); }
			$data[ $horse_id ]['lessons']++;
			$data[ $horse_id ]['minutes'] += max( 0, absint( get_post_meta( $lesson_id, 'lesson_duration', true ) ) );
		}
		return $data;
	}

	private static function stat( $label, $value, $note, $class ) {
		echo '<article class="hcos-horses-stat ' . esc_attr( $class ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong><small>' . esc_html( $note ) . '</small></article>';
	}

	private static function render_table( $items, $workload ) {
		?>
		<div class="hcos-horses-table-wrap"><table class="hcos-horses-table"><thead><tr><th>Лошадь</th><th>Специализация</th><th>Уровень всадника</th><th>Нагрузка сегодня</th><th>Доступность</th><th>Владелец</th><th>Статус</th><th></th></tr></thead><tbody>
		<?php if ( ! $items ) : ?><tr><td colspan="8"><div class="hcos-horses-empty">Лошади по выбранным условиям не найдены</div></td></tr><?php endif; ?>
		<?php foreach ( $items as $item ) { self::render_row( $item, isset( $workload[ $item->ID ] ) ? $workload[ $item->ID ] : array( 'lessons' => 0, 'minutes' => 0 ) ); } ?>
		</tbody></table></div>
		<?php
	}

	private static function render_row( $item, $load ) {
		$status     = (string) get_post_meta( $item->ID, 'horse_status', true ) ?: 'active';
		$owner_id   = absint( get_post_meta( $item->ID, 'horse_owner', true ) );
		$photo_id   = absint( get_post_meta( $item->ID, 'horse_photo', true ) );
		$breed      = trim( (string) get_post_meta( $item->ID, 'horse_breed', true ) );
		$color      = trim( (string) get_post_meta( $item->ID, 'horse_color', true ) );
		$level      = (string) get_post_meta( $item->ID, 'horse_min_rider_level', true );
		$maximum    = absint( get_post_meta( $item->ID, 'horse_max_daily_minutes', true ) );
		$from       = self::date( get_post_meta( $item->ID, 'horse_unavailable_from', true ) );
		$to         = self::date( get_post_meta( $item->ID, 'horse_unavailable_to', true ) );
		$reason     = trim( (string) get_post_meta( $item->ID, 'horse_unavailable_reason', true ) );
		$specializations = get_post_meta( $item->ID, 'horse_specializations', true );
		$specializations = is_array( $specializations ) ? $specializations : array_filter( array( $specializations ) );
		$specializations = array_map( static function ( $value ) { return isset( HCOS_Horses_Screen::$specialization_labels[ $value ] ) ? HCOS_Horses_Screen::$specialization_labels[ $value ] : $value; }, $specializations );
		$owner_url = $owner_id ? add_query_arg( array( 'post_type' => 'clients', 'page' => 'hcos-clients', 'hcos_client_id' => $owner_id ), admin_url( 'edit.php' ) ) : '';
		$availability = $from || $to ? trim( ( $from ?: 'сейчас' ) . ' — ' . ( $to ?: 'без срока' ) ) : 'Без ограничений';
		?>
		<tr><td><div class="hcos-horses-identity"><?php if ( $photo_id ) : ?><?php echo wp_get_attachment_image( $photo_id, array( 46, 46 ), false, array( 'class' => 'hcos-horses-photo' ) ); ?><?php else : ?><span class="hcos-horses-placeholder">H</span><?php endif; ?><span><a href="<?php echo esc_url( get_edit_post_link( $item->ID ) ); ?>"><?php echo esc_html( $item->post_title ); ?></a><small><?php echo esc_html( implode( ' · ', array_filter( array( $breed, $color ) ) ) ?: 'Данные не указаны' ); ?></small></span></div></td><td><div class="hcos-horses-specializations"><?php echo esc_html( $specializations ? implode( ', ', $specializations ) : 'Без ограничений' ); ?></div></td><td><span class="hcos-horses-level"><?php echo esc_html( isset( self::$level_labels[ $level ] ) ? self::$level_labels[ $level ] : 'Любой уровень' ); ?></span></td><td><div class="hcos-horses-load"><strong><?php echo esc_html( $load['lessons'] . ' / ' . $load['minutes'] . ' мин.' ); ?></strong><small><?php echo esc_html( $maximum ? 'лимит ' . $maximum . ' мин.' : 'лимит не задан' ); ?></small></div></td><td><div class="hcos-horses-availability"><strong><?php echo esc_html( $availability ); ?></strong><?php if ( $reason ) : ?><small><?php echo esc_html( $reason ); ?></small><?php endif; ?></div></td><td><?php if ( $owner_url ) : ?><a class="hcos-horses-owner" href="<?php echo esc_url( $owner_url ); ?>"><?php echo esc_html( get_the_title( $owner_id ) ); ?></a><?php else : ?><span class="hcos-horses-muted">Клуб</span><?php endif; ?></td><td><span class="hcos-horses-status is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( isset( self::$status_labels[ $status ] ) ? self::$status_labels[ $status ] : $status ); ?></span></td><td><a class="hcos-horses-open" href="<?php echo esc_url( get_edit_post_link( $item->ID ) ); ?>">Редактировать →</a></td></tr>
		<?php
	}

	private static function list_url() { return add_query_arg( array( 'post_type' => 'horses', 'page' => 'hcos-horses' ), admin_url( 'edit.php' ) ); }
	private static function lower( $value ) { return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value ); }
	private static function date( $value ) { $value = preg_replace( '/\D+/', '', (string) $value ); if ( 8 !== strlen( $value ) ) { return ''; } $date = DateTimeImmutable::createFromFormat( '!Ymd', $value, wp_timezone() ); return $date ? wp_date( 'd.m.Y', $date->getTimestamp(), wp_timezone() ) : ''; }
	private static function plural( $number, $one, $few, $many ) { $a = $number % 10; $b = $number % 100; return $number . ' ' . ( 1 === $a && 11 !== $b ? $one : ( $a >= 2 && $a <= 4 && ( $b < 12 || $b > 14 ) ? $few : $many ) ); }
}
