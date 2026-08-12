<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Bookings_Screen {
	private static $hook = '';

	private static $status_labels = array(
		'pending'             => 'Ожидает подтверждения',
		'confirmed'           => 'Подтверждена',
		'cancelled_by_client' => 'Отменена клиентом',
		'cancelled_by_club'   => 'Отменена клубом',
		'waitlist'            => 'Лист ожидания',
	);

	private static $attendance_labels = array(
		'expected' => 'Ожидается',
		'present'  => 'Присутствовал',
		'no_show'  => 'Неявка',
		'excused'  => 'Уважительная отмена',
	);

	private static $payment_labels = array(
		'unpaid'    => 'Не оплачено',
		'paid'      => 'Оплачено',
		'membership'=> 'Абонемент',
		'partial'   => 'Частично',
		'refund'    => 'Возврат',
	);

	private static $method_labels = array(
		'cash'     => 'Наличные',
		'card'     => 'Карта',
		'transfer' => 'Перевод',
		'online'   => 'Онлайн',
		'other'    => 'Другое',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_page() {
		self::$hook = add_submenu_page(
			'edit.php?post_type=lessons',
			'Записи на занятия Horse Club OS',
			'Обзор записей',
			'edit_hcos_bookings',
			'hcos-bookings',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( self::$hook !== $hook ) {
			return;
		}
		wp_enqueue_style( 'hcos-dashboard', plugins_url( 'assets/css/admin-dashboard.css', HCOS_PLUGIN_FILE ), array(), HCOS_VERSION );
		wp_enqueue_style( 'hcos-bookings-screen', plugins_url( 'assets/css/admin-bookings.css', HCOS_PLUGIN_FILE ), array( 'hcos-dashboard' ), HCOS_VERSION );
	}

	public static function render_page() {
		if ( ! current_user_can( 'edit_hcos_bookings' ) ) {
			wp_die( esc_html__( 'Недостаточно прав для просмотра записей.', 'horse-club-os' ) );
		}

		$filters  = self::filters();
		$bookings = self::get_bookings( $filters['from'], $filters['to'] );
		$filtered = self::filter_bookings( $bookings, $filters );
		$summary  = self::summary( $filtered );
		?>
		<div class="hcos-app hcos-bookings-app">
			<?php HCOS_Dashboard::sidebar( 'bookings' ); ?>
			<main class="hcos-main hcos-bookings-main">
				<header class="hcos-header hcos-bookings-header">
					<div><h1>Записи на занятия</h1><p><?php echo esc_html( self::period_label( $filters['from'], $filters['to'] ) . ' · ' . self::plural( count( $filtered ), 'запись', 'записи', 'записей' ) ); ?></p></div>
					<div class="hcos-header-actions"><a class="hcos-bookings-secondary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=lessons&page=hcos-calendar' ) ); ?>">Расписание</a><a class="hcos-primary-button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=bookings' ) ); ?>">＋ Новая запись</a></div>
				</header>

				<section class="hcos-bookings-summary">
					<?php self::stat( 'Всего записей', $summary['total'], 'за выбранный период', '' ); ?>
					<?php self::stat( 'Ожидаются', $summary['expected'], 'предстоит отметить', 'expected' ); ?>
					<?php self::stat( 'Посетили', $summary['present'], $summary['attendance_rate'] . '% посещаемость', 'present' ); ?>
					<?php if ( current_user_can( 'hcos_view_finances' ) ) : ?><?php self::stat( 'Задолженность', self::money( $summary['debt'] ), self::plural( $summary['debt_count'], 'запись требует оплаты', 'записи требуют оплаты', 'записей требуют оплаты' ), 'debt' ); ?><?php else : ?><?php self::stat( 'Неявки', $summary['no_show'], 'за выбранный период', 'no-show' ); ?><?php endif; ?>
				</section>

				<?php self::render_filters( $filters ); ?>
				<section class="hcos-bookings-panel"><div class="hcos-bookings-panel-heading"><div><h2>Участники занятий</h2><p><?php echo esc_html( 'Найдено: ' . count( $filtered ) ); ?></p></div><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=bookings' ) ); ?>">Стандартный список</a></div><?php self::render_table( $filtered ); ?></section>
			</main>
		</div>
		<?php
	}

	private static function filters() {
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		$from  = self::date_param( 'hcos_from', $today );
		$to    = self::date_param( 'hcos_to', $today->modify( '+6 days' ) );
		if ( $to < $from ) {
			$to = $from;
		}
		$status = isset( $_GET['hcos_status'] ) ? sanitize_key( wp_unslash( $_GET['hcos_status'] ) ) : 'all';
		$attendance = isset( $_GET['hcos_attendance'] ) ? sanitize_key( wp_unslash( $_GET['hcos_attendance'] ) ) : 'all';
		return array(
			'from'       => $from,
			'to'         => $to,
			'search'     => isset( $_GET['hcos_search'] ) ? sanitize_text_field( wp_unslash( $_GET['hcos_search'] ) ) : '',
			'status'     => 'all' === $status || isset( self::$status_labels[ $status ] ) ? $status : 'all',
			'attendance' => 'all' === $attendance || isset( self::$attendance_labels[ $attendance ] ) ? $attendance : 'all',
		);
	}

	private static function date_param( $name, $fallback ) {
		$value = isset( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : '';
		$date  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() ) : false;
		return $date ?: $fallback;
	}

	private static function get_bookings( $from, $to ) {
		$lesson_ids = get_posts( array(
			'post_type'      => 'lessons',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => 'lesson_date', 'value' => array( $from->format( 'Ymd' ), $to->format( 'Ymd' ) ), 'compare' => 'BETWEEN', 'type' => 'NUMERIC' ) ),
		) );
		if ( ! $lesson_ids ) {
			return array();
		}
		$bookings = get_posts( array(
			'post_type'      => 'bookings',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => 'booking_lesson', 'value' => $lesson_ids, 'compare' => 'IN' ) ),
		) );
		usort( $bookings, static function ( $left, $right ) {
			return strcmp( HCOS_Bookings_Screen::sort_key( $left->ID ), HCOS_Bookings_Screen::sort_key( $right->ID ) );
		} );
		return $bookings;
	}

	private static function sort_key( $booking_id ) {
		$lesson_id = absint( get_post_meta( $booking_id, 'booking_lesson', true ) );
		return preg_replace( '/[^0-9]/', '', (string) get_post_meta( $lesson_id, 'lesson_date', true ) ) . (string) get_post_meta( $lesson_id, 'lesson_time', true ) . str_pad( (string) $booking_id, 12, '0', STR_PAD_LEFT );
	}

	private static function filter_bookings( $bookings, $filters ) {
		$needle = self::lower( trim( $filters['search'] ) );
		return array_values( array_filter( $bookings, static function ( $booking ) use ( $filters, $needle ) {
			$status     = (string) get_post_meta( $booking->ID, 'booking_status', true ) ?: 'confirmed';
			$attendance = (string) get_post_meta( $booking->ID, 'booking_attendance', true ) ?: 'expected';
			if ( 'all' !== $filters['status'] && $status !== $filters['status'] ) {
				return false;
			}
			if ( 'all' !== $filters['attendance'] && $attendance !== $filters['attendance'] ) {
				return false;
			}
			if ( '' === $needle ) {
				return true;
			}
			$lesson_id  = absint( get_post_meta( $booking->ID, 'booking_lesson', true ) );
			$rider_id   = absint( get_post_meta( $booking->ID, 'booking_rider', true ) );
			$payer_id   = absint( get_post_meta( $booking->ID, 'booking_payer', true ) );
			$service_id = absint( get_post_meta( $lesson_id, 'lesson_service', true ) );
			$text = implode( ' ', array( $booking->post_title, get_the_title( $rider_id ), get_the_title( $payer_id ), get_the_title( $lesson_id ), get_the_title( $service_id ) ) );
			return false !== strpos( HCOS_Bookings_Screen::lower( $text ), $needle );
		} ) );
	}

	private static function summary( $bookings ) {
		$data = array( 'total' => count( $bookings ), 'expected' => 0, 'present' => 0, 'no_show' => 0, 'marked' => 0, 'attendance_rate' => 0, 'debt' => 0, 'debt_count' => 0 );
		foreach ( $bookings as $booking ) {
			$attendance = (string) get_post_meta( $booking->ID, 'booking_attendance', true ) ?: 'expected';
			if ( 'expected' === $attendance ) { $data['expected']++; }
			if ( 'present' === $attendance ) { $data['present']++; $data['marked']++; }
			if ( 'no_show' === $attendance ) { $data['no_show']++; $data['marked']++; }
			if ( 'excused' === $attendance ) { $data['marked']++; }
			$debt = max( 0, (float) get_post_meta( $booking->ID, 'booking_debt_amount', true ) );
			$data['debt'] += $debt;
			if ( $debt > 0 ) { $data['debt_count']++; }
		}
		$data['attendance_rate'] = $data['marked'] ? (int) round( 100 * $data['present'] / $data['marked'] ) : 0;
		return $data;
	}

	private static function render_filters( $filters ) {
		?>
		<form class="hcos-bookings-filters" method="get">
			<input type="hidden" name="post_type" value="lessons"><input type="hidden" name="page" value="hcos-bookings">
			<label class="hcos-bookings-search"><span>Поиск</span><input type="search" name="hcos_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="Всадник, плательщик или услуга"></label>
			<label><span>С</span><input type="date" name="hcos_from" value="<?php echo esc_attr( $filters['from']->format( 'Y-m-d' ) ); ?>"></label>
			<label><span>По</span><input type="date" name="hcos_to" value="<?php echo esc_attr( $filters['to']->format( 'Y-m-d' ) ); ?>"></label>
			<label><span>Статус</span><select name="hcos_status"><option value="all">Все статусы</option><?php foreach ( self::$status_labels as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			<label><span>Посещение</span><select name="hcos_attendance"><option value="all">Все результаты</option><?php foreach ( self::$attendance_labels as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['attendance'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			<button type="submit">Показать</button><a href="<?php echo esc_url( self::list_url() ); ?>">Сбросить</a>
		</form>
		<?php
	}

	private static function render_table( $bookings ) {
		$show_finances = current_user_can( 'hcos_view_finances' );
		?>
		<div class="hcos-bookings-table-wrap"><table class="hcos-bookings-table"><thead><tr><th>Дата и занятие</th><th>Всадник</th><th>Плательщик</th><th>Статус записи</th><th>Посещение</th><?php if ( $show_finances ) : ?><th>Оплата</th><th>Долг</th><?php endif; ?><th></th></tr></thead><tbody>
		<?php if ( ! $bookings ) : ?><tr><td colspan="<?php echo esc_attr( $show_finances ? 8 : 6 ); ?>"><div class="hcos-bookings-empty">Записи по выбранным условиям не найдены</div></td></tr><?php endif; ?>
		<?php foreach ( $bookings as $booking ) { self::render_row( $booking, $show_finances ); } ?>
		</tbody></table></div>
		<?php
	}

	private static function render_row( $booking, $show_finances ) {
		$lesson_id     = absint( get_post_meta( $booking->ID, 'booking_lesson', true ) );
		$rider_id      = absint( get_post_meta( $booking->ID, 'booking_rider', true ) );
		$payer_id      = absint( get_post_meta( $booking->ID, 'booking_payer', true ) );
		$service_id    = absint( get_post_meta( $lesson_id, 'lesson_service', true ) );
		$trainer_id    = absint( get_post_meta( $lesson_id, 'lesson_trainer', true ) );
		$status        = (string) get_post_meta( $booking->ID, 'booking_status', true ) ?: 'confirmed';
		$attendance    = (string) get_post_meta( $booking->ID, 'booking_attendance', true ) ?: 'expected';
		$payment       = (string) get_post_meta( $booking->ID, 'booking_payment_status', true ) ?: 'unpaid';
		$membership_id = absint( get_post_meta( $booking->ID, 'booking_membership', true ) );
		$debt          = max( 0, (float) get_post_meta( $booking->ID, 'booking_debt_amount', true ) );
		$date          = self::format_date( get_post_meta( $lesson_id, 'lesson_date', true ) );
		$time          = substr( (string) get_post_meta( $lesson_id, 'lesson_time', true ), 0, 5 );
		$method        = $show_finances ? self::payment_method( $booking->ID ) : '';
		?>
		<tr>
			<td><div class="hcos-bookings-lesson"><strong><?php echo esc_html( trim( $date . ' · ' . $time, ' ·' ) ?: 'Дата не указана' ); ?></strong><a href="<?php echo esc_url( get_edit_post_link( $lesson_id ) ); ?>"><?php echo esc_html( $service_id ? get_the_title( $service_id ) : ( get_the_title( $lesson_id ) ?: 'Занятие' ) ); ?></a><small><?php echo esc_html( $trainer_id ? get_the_title( $trainer_id ) : 'Тренер не указан' ); ?></small></div></td>
			<td><div class="hcos-bookings-person"><span class="hcos-bookings-avatar"><?php echo esc_html( self::initial( get_the_title( $rider_id ) ) ); ?></span><span><a href="<?php echo esc_url( get_edit_post_link( $rider_id ) ); ?>"><?php echo esc_html( $rider_id ? get_the_title( $rider_id ) : 'Не указан' ); ?></a><small>всадник</small></span></div></td>
			<td><div class="hcos-bookings-payer"><?php if ( $payer_id ) : ?><a href="<?php echo esc_url( get_edit_post_link( $payer_id ) ); ?>"><?php echo esc_html( get_the_title( $payer_id ) ); ?></a><small><?php echo esc_html( $payer_id === $rider_id ? 'самостоятельная оплата' : 'плательщик за всадника' ); ?></small><?php else : ?><span>Не указан</span><?php endif; ?></div></td>
			<td><span class="hcos-bookings-status is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( isset( self::$status_labels[ $status ] ) ? self::$status_labels[ $status ] : $status ); ?></span></td>
			<td><span class="hcos-bookings-attendance is-<?php echo esc_attr( $attendance ); ?>"><?php echo esc_html( isset( self::$attendance_labels[ $attendance ] ) ? self::$attendance_labels[ $attendance ] : $attendance ); ?></span></td>
			<?php if ( $show_finances ) : ?><td><div class="hcos-bookings-payment"><strong><?php echo esc_html( isset( self::$payment_labels[ $payment ] ) ? self::$payment_labels[ $payment ] : $payment ); ?></strong><small><?php echo esc_html( $membership_id ? get_the_title( $membership_id ) : ( isset( self::$method_labels[ $method ] ) ? self::$method_labels[ $method ] : 'разовая оплата' ) ); ?></small></div></td><td><strong class="hcos-bookings-debt <?php echo $debt > 0 ? 'has-debt' : ''; ?>"><?php echo esc_html( self::money( $debt ) ); ?></strong></td><?php endif; ?>
			<td><a class="hcos-bookings-open" href="<?php echo esc_url( get_edit_post_link( $booking->ID ) ); ?>">Открыть →</a></td>
		</tr>
		<?php
	}

	private static function payment_method( $booking_id ) {
		$ids = get_posts( array( 'post_type' => 'payments', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true, 'meta_query' => array( array( 'key' => 'payment_booking', 'value' => $booking_id, 'compare' => '=' ), array( 'key' => 'payment_status', 'value' => 'paid', 'compare' => '=' ) ) ) );
		return $ids ? (string) get_post_meta( $ids[0], 'payment_method', true ) : '';
	}

	private static function stat( $label, $value, $note, $class ) { echo '<article class="hcos-bookings-stat ' . esc_attr( $class ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong><small>' . esc_html( $note ) . '</small></article>'; }
	private static function format_date( $value ) { $digits = preg_replace( '/[^0-9]/', '', (string) $value ); $date = DateTimeImmutable::createFromFormat( '!Ymd', $digits, wp_timezone() ); return $date ? wp_date( 'd.m.Y', $date->getTimestamp(), wp_timezone() ) : ''; }
	private static function period_label( $from, $to ) { return $from->format( 'Y-m-d' ) === $to->format( 'Y-m-d' ) ? wp_date( 'j F Y', $from->getTimestamp(), wp_timezone() ) : wp_date( 'j F', $from->getTimestamp(), wp_timezone() ) . ' — ' . wp_date( 'j F Y', $to->getTimestamp(), wp_timezone() ); }
	private static function list_url() { return add_query_arg( array( 'post_type' => 'lessons', 'page' => 'hcos-bookings' ), admin_url( 'edit.php' ) ); }
	private static function initial( $name ) { $name = trim( (string) $name ); return '' === $name ? '?' : ( function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $name, 0, 1 ) ) : strtoupper( substr( $name, 0, 1 ) ) ); }
	private static function money( $amount ) { return number_format_i18n( (float) $amount, 0 ) . ' ₽'; }
	private static function lower( $value ) { return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value ); }
	private static function plural( $number, $one, $few, $many ) { $a = $number % 10; $b = $number % 100; return $number . ' ' . ( 1 === $a && 11 !== $b ? $one : ( $a >= 2 && $a <= 4 && ( $b < 12 || $b > 14 ) ? $few : $many ) ); }
}
