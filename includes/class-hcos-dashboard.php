<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Dashboard {
	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 5 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_page() {
		self::$hook = add_menu_page( 'Horse Club OS', 'Horse Club OS', 'edit_hcos_lessons', 'hcos-dashboard', array( __CLASS__, 'render_page' ), 'dashicons-admin-home', 2 );
	}

	public static function enqueue_assets( $hook ) {
		if ( self::$hook === $hook ) {
			wp_enqueue_style( 'hcos-dashboard', plugins_url( 'assets/css/admin-dashboard.css', HCOS_PLUGIN_FILE ), array(), HCOS_VERSION );
		}
	}

	public static function render_page() {
		if ( ! current_user_can( 'edit_hcos_lessons' ) ) {
			wp_die( esc_html__( 'Недостаточно прав для просмотра панели.', 'horse-club-os' ) );
		}
		$date   = self::requested_date();
		$filter = self::requested_filter();
		$data   = self::collect( $date );
		?>
		<div class="hcos-app">
			<?php self::sidebar( 'dashboard' ); ?>
			<main class="hcos-main">
				<?php self::header( $date ); ?>
				<?php self::metrics( $data ); ?>
				<div class="hcos-workspace">
					<?php self::schedule( self::filter_lessons( $data['lessons'], $filter ), $data['lessons'], $date, $filter ); ?>
					<?php self::attention( $data['attention'] ); ?>
				</div>
			</main>
		</div>
		<?php
	}

	public static function sidebar( $active = 'dashboard' ) {
		$user  = wp_get_current_user();
		$name  = $user->display_name ?: $user->user_login;
		$links = array(
			array( 'dashboard', 'Главная', admin_url( 'admin.php?page=hcos-dashboard' ) ),
			array( 'calendar', 'Расписание', admin_url( 'edit.php?post_type=lessons&page=hcos-calendar' ) ),
			array( 'clients', 'Клиенты', admin_url( 'edit.php?post_type=clients&page=hcos-clients' ) ),
			array( 'memberships', 'Абонементы', admin_url( 'edit.php?post_type=memberships' ) ),
			array( 'payments', 'Платежи', admin_url( 'edit.php?post_type=payments' ) ),
			array( 'horses', 'Лошади', admin_url( 'edit.php?post_type=horses' ) ),
			array( 'trainers', 'Тренеры', admin_url( 'edit.php?post_type=trainers' ) ),
			array( 'services', 'Услуги', admin_url( 'edit.php?post_type=services' ) ),
		);
		?>
		<aside class="hcos-sidebar">
			<div class="hcos-brand"><span class="hcos-brand-mark">H</span><span><strong>Horse Club</strong><small>OS</small></span></div>
			<nav class="hcos-nav" aria-label="Разделы CRM">
				<?php foreach ( $links as $link ) : ?><a class="<?php echo $active === $link[0] ? 'is-active' : ''; ?>" href="<?php echo esc_url( $link[2] ); ?>"><i></i><?php echo esc_html( $link[1] ); ?></a><?php endforeach; ?>
				<span class="hcos-nav-divider"></span><a href="<?php echo esc_url( current_user_can( 'manage_options' ) ? admin_url( 'options-general.php' ) : admin_url( 'profile.php' ) ); ?>"><i></i>Настройки</a>
			</nav>
			<a class="hcos-user" href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"><span class="hcos-avatar"><?php echo esc_html( self::initials( $name ) ); ?></span><span><strong><?php echo esc_html( $name ); ?></strong><small><?php echo in_array( HCOS_Security::TRAINER_ROLE, (array) $user->roles, true ) ? 'Тренер' : 'Администратор'; ?></small></span></a>
		</aside>
		<?php
	}

	private static function header( DateTimeImmutable $date ) {
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		?>
		<header class="hcos-header"><div><h1><?php echo esc_html( ( $date->format( 'Y-m-d' ) === $today->format( 'Y-m-d' ) ? 'Сегодня, ' : '' ) . wp_date( 'j F', $date->getTimestamp(), wp_timezone() ) ); ?></h1><p><?php echo esc_html( wp_date( 'l', $date->getTimestamp(), wp_timezone() ) ); ?> · рабочий день клуба</p></div>
			<div class="hcos-header-actions"><div class="hcos-date-switcher"><a href="<?php echo esc_url( self::url( $date->modify( '-1 day' ) ) ); ?>">‹</a><a href="<?php echo esc_url( self::url( $today ) ); ?>"><?php echo esc_html( wp_date( 'j F', $date->getTimestamp(), wp_timezone() ) ); ?></a><a href="<?php echo esc_url( self::url( $date->modify( '+1 day' ) ) ); ?>">›</a></div><a class="hcos-primary-button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=lessons' ) ); ?>">＋ Добавить занятие</a></div>
		</header>
		<?php
	}

	private static function metrics( $data ) {
		$items = array(
			array( 'Занятия сегодня', count( $data['lessons'] ), $data['upcoming'] . ' запланировано', '' ),
			array( 'Проведено', $data['completed'], $data['rate'] . '% от расписания', '' ),
			array( 'Чистая выручка', self::money( $data['revenue'] ), 'за выбранный день', 'finance' ),
			array( 'Текущий долг', self::money( $data['debt'] ), 'требует внимания', 'finance debt' ),
		);
		echo '<section class="hcos-metrics">';
		foreach ( $items as $item ) {
			if ( false !== strpos( $item[3], 'finance' ) && ! current_user_can( 'hcos_view_finances' ) ) { continue; }
			echo '<article class="hcos-metric ' . esc_attr( $item[3] ) . '"><h2>' . esc_html( $item[0] ) . '</h2><strong>' . esc_html( $item[1] ) . '</strong><p>' . esc_html( $item[2] ) . '</p></article>';
		}
		echo '</section>';
	}

	private static function schedule( $lessons, $all, DateTimeImmutable $date, $filter ) {
		$tabs = array( 'all' => 'Все', 'upcoming' => 'Запланировано', 'completed' => 'Проведено' );
		?>
		<section class="hcos-panel hcos-schedule"><div class="hcos-panel-heading"><div><h2>Расписание на день</h2><p><?php echo esc_html( count( $all ) . ' ' . self::plural( count( $all ), 'занятие', 'занятия', 'занятий' ) ); ?></p></div><div class="hcos-tabs"><?php foreach ( $tabs as $key => $label ) : ?><a class="<?php echo $filter === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::url( $date, $key ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></div></div>
			<div class="hcos-lessons"><?php if ( ! $lessons ) : ?><div class="hcos-empty"><strong>На этот день занятий нет</strong><span>Добавьте занятие или выберите другую дату.</span></div><?php endif; ?><?php foreach ( $lessons as $lesson ) { self::lesson( $lesson ); } ?></div>
		</section>
		<?php
	}

	private static function lesson( $lesson ) {
		$status     = (string) get_post_meta( $lesson->ID, 'lesson_status', true );
		$completed  = 'completed' === $status;
		$service_id = absint( get_post_meta( $lesson->ID, 'lesson_service', true ) );
		$horse_id   = absint( get_post_meta( $lesson->ID, 'lesson_horse', true ) );
		$trainer_id = absint( get_post_meta( $lesson->ID, 'lesson_trainer', true ) );
		$duration   = absint( get_post_meta( $lesson->ID, 'lesson_duration', true ) ) ?: absint( get_post_meta( $service_id, 'service_duration', true ) );
		$resources  = array_filter( array( $horse_id ? get_the_title( $horse_id ) : '', $trainer_id ? get_the_title( $trainer_id ) : '' ) );
		$riders     = self::riders( $lesson->ID );
		?>
		<article class="hcos-lesson <?php echo $completed ? 'is-completed' : 'is-upcoming'; ?>"><div class="hcos-lesson-time"><strong><?php echo esc_html( substr( (string) get_post_meta( $lesson->ID, 'lesson_time', true ), 0, 5 ) ?: '—' ); ?></strong><small><?php echo esc_html( $duration ? $duration . ' мин' : '' ); ?></small></div><div class="hcos-lesson-info"><strong><?php echo esc_html( $service_id ? get_the_title( $service_id ) : get_the_title( $lesson ) ); ?></strong><span><?php echo esc_html( $riders ? implode( ', ', $riders ) : 'Нет записей' ); ?></span><small><?php echo esc_html( implode( ' · ', $resources ) ); ?></small></div><div class="hcos-lesson-state"><span><?php echo $completed ? 'Проведено' : 'Запланировано'; ?></span><a href="<?php echo esc_url( get_edit_post_link( $lesson->ID ) ); ?>">Открыть →</a></div></article>
		<?php
	}

	private static function attention( $tasks ) {
		?>
		<aside class="hcos-panel hcos-attention"><div class="hcos-panel-heading"><div><h2>Требует внимания</h2><p>Задачи, которые лучше решить сегодня</p></div><span class="hcos-task-count"><?php echo esc_html( count( $tasks ) ); ?></span></div><div class="hcos-tasks">
			<?php if ( ! $tasks ) : ?><div class="hcos-empty compact"><strong>Срочных задач нет</strong><span>Все основные данные в порядке.</span></div><?php endif; ?>
			<?php foreach ( $tasks as $index => $task ) : ?><article class="hcos-task <?php echo 0 === $index && 'debt' === $task['type'] ? 'is-critical' : ''; ?>"><h3><i></i><?php echo esc_html( $task['title'] ); ?></h3><p><?php echo esc_html( $task['note'] ); ?></p><a href="<?php echo esc_url( $task['url'] ); ?>"><?php echo esc_html( $task['action'] ); ?> →</a></article><?php endforeach; ?>
		</div><div class="hcos-quick-actions"><h3>Быстрые действия</h3><a class="is-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=clients' ) ); ?>">＋ Новый клиент</a><?php if ( current_user_can( 'hcos_view_finances' ) ) : ?><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=payments' ) ); ?>">Принять оплату</a><?php endif; ?><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=memberships' ) ); ?>">Создать абонемент</a></div></aside>
		<?php
	}

	private static function collect( DateTimeImmutable $date ) {
		$lessons = self::lessons( $date ); $completed = 0;
		foreach ( $lessons as $lesson ) { if ( 'completed' === get_post_meta( $lesson->ID, 'lesson_status', true ) ) { $completed++; } }
		$debt = self::debt();
		return array( 'lessons' => $lessons, 'completed' => $completed, 'upcoming' => count( $lessons ) - $completed, 'rate' => count( $lessons ) ? round( 100 * $completed / count( $lessons ) ) : 0, 'revenue' => self::revenue( $date ), 'debt' => $debt['total'], 'attention' => self::attention_items( $lessons, $debt['items'] ) );
	}

	private static function lessons( DateTimeImmutable $date ) {
		$items = get_posts( array( 'post_type' => 'lessons', 'post_status' => 'publish', 'posts_per_page' => -1, 'no_found_rows' => true, 'meta_query' => array( array( 'key' => 'lesson_date', 'value' => $date->format( 'Ymd' ), 'compare' => '=', 'type' => 'NUMERIC' ) ) ) );
		usort( $items, static function ( $a, $b ) { return strcmp( (string) get_post_meta( $a->ID, 'lesson_time', true ), (string) get_post_meta( $b->ID, 'lesson_time', true ) ); } );
		return $items;
	}

	private static function riders( $lesson_id ) {
		$names = array();
		foreach ( HCOS_Bookings::get_active_booking_ids( $lesson_id ) as $booking_id ) { $id = absint( get_post_meta( $booking_id, 'booking_rider', true ) ); if ( $id ) { $names[] = get_the_title( $id ); } }
		if ( ! $names ) { $id = absint( get_post_meta( $lesson_id, 'lesson_client', true ) ); if ( $id ) { $names[] = get_the_title( $id ); } }
		return array_values( array_unique( array_filter( $names ) ) );
	}

	private static function revenue( DateTimeImmutable $date ) {
		$total = 0.0; $ids = get_posts( array( 'post_type' => 'payments', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => array( array( 'key' => 'payment_date', 'value' => $date->format( 'Ymd' ), 'compare' => '=', 'type' => 'NUMERIC' ) ) ) );
		foreach ( $ids as $id ) { $status = get_post_meta( $id, 'payment_status', true ); $amount = (float) get_post_meta( $id, 'payment_amount', true ); if ( 'paid' === $status ) { $total += $amount; } elseif ( 'refund' === $status ) { $total -= $amount; } }
		return $total;
	}

	private static function debt() {
		$total = 0.0; $items = array();
		if ( ! current_user_can( 'hcos_view_finances' ) ) { return array( 'total' => 0, 'items' => array() ); }
		foreach ( array( 'memberships' => 'membership_debt_amount', 'bookings' => 'booking_debt_amount' ) as $type => $key ) {
			foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) ) as $id ) { $amount = max( 0, (float) get_post_meta( $id, $key, true ) ); if ( ! $amount ) { continue; } $total += $amount; $client = absint( get_post_meta( $id, 'memberships' === $type ? 'membership_client' : 'booking_payer', true ) ); $items[] = compact( 'amount', 'client', 'id', 'type' ); }
		}
		usort( $items, static function ( $a, $b ) { return $b['amount'] <=> $a['amount']; } );
		return compact( 'total', 'items' );
	}

	private static function attention_items( $lessons, $debts ) {
		$items = array();
		if ( $debts ) { $d = $debts[0]; $items[] = array( 'type' => 'debt', 'title' => 'Задолженность ' . self::money( $d['amount'] ), 'note' => $d['client'] ? get_the_title( $d['client'] ) : get_the_title( $d['id'] ), 'url' => get_edit_post_link( $d['client'] ?: $d['id'] ), 'action' => 'Открыть клиента' ); }
		foreach ( $lessons as $lesson ) { if ( in_array( get_post_meta( $lesson->ID, 'lesson_status', true ), array( '', 'planned' ), true ) ) { $items[] = array( 'type' => 'lesson', 'title' => 'Подтвердить занятие', 'note' => substr( (string) get_post_meta( $lesson->ID, 'lesson_time', true ), 0, 5 ) . ' · ' . get_the_title( $lesson ), 'url' => get_edit_post_link( $lesson->ID ), 'action' => 'Открыть занятие' ); break; } }
		foreach ( get_posts( array( 'post_type' => 'memberships', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => array( array( 'key' => 'membership_status', 'value' => array( 'active', 'frozen' ), 'compare' => 'IN' ) ) ) ) as $id ) { $balance = (float) get_post_meta( $id, 'membership_balance', true ); if ( $balance <= 1 ) { $client = absint( get_post_meta( $id, 'membership_client', true ) ); $items[] = array( 'type' => 'membership', 'title' => 'Абонемент заканчивается', 'note' => ( $client ? get_the_title( $client ) : get_the_title( $id ) ) . ' · ' . self::number( $balance ) . ' занятие', 'url' => get_edit_post_link( $id ), 'action' => 'Открыть абонемент' ); break; } }
		return array_slice( $items, 0, 3 );
	}

	private static function requested_date() { $value = isset( $_GET['hcos_date'] ) ? sanitize_text_field( wp_unslash( $_GET['hcos_date'] ) ) : ''; $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() ); return $date ?: new DateTimeImmutable( 'today', wp_timezone() ); }
	private static function requested_filter() { $value = isset( $_GET['hcos_status'] ) ? sanitize_key( wp_unslash( $_GET['hcos_status'] ) ) : 'all'; return in_array( $value, array( 'all', 'upcoming', 'completed' ), true ) ? $value : 'all'; }
	private static function filter_lessons( $lessons, $filter ) { if ( 'all' === $filter ) { return $lessons; } return array_values( array_filter( $lessons, static function ( $lesson ) use ( $filter ) { $done = 'completed' === get_post_meta( $lesson->ID, 'lesson_status', true ); return 'completed' === $filter ? $done : ! $done; } ) ); }
	private static function url( DateTimeImmutable $date, $filter = 'all' ) { return add_query_arg( array( 'page' => 'hcos-dashboard', 'hcos_date' => $date->format( 'Y-m-d' ), 'hcos_status' => $filter ), admin_url( 'admin.php' ) ); }
	private static function initials( $name ) { $result = ''; foreach ( array_slice( preg_split( '/\s+/u', trim( $name ) ), 0, 2 ) as $part ) { $result .= function_exists( 'mb_substr' ) ? mb_substr( $part, 0, 1 ) : substr( $part, 0, 1 ); } return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $result ) : strtoupper( $result ); }
	private static function money( $value ) { return number_format_i18n( (float) $value, 0 ) . ' ₽'; }
	private static function number( $value ) { return number_format_i18n( (float) $value, (float) $value === floor( (float) $value ) ? 0 : 1 ); }
	private static function plural( $number, $one, $few, $many ) { $a = $number % 10; $b = $number % 100; return 1 === $a && 11 !== $b ? $one : ( $a >= 2 && $a <= 4 && ( $b < 12 || $b > 14 ) ? $few : $many ); }
}
