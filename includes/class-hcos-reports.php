<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Reports {
	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_page() {
		self::$hook = add_menu_page(
			'Отчёты Horse Club OS',
			'Отчёты',
			'hcos_view_finances',
			'hcos-reports',
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-area',
			29
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( self::$hook !== $hook ) {
			return;
		}
		wp_enqueue_style( 'hcos-dashboard', plugins_url( 'assets/css/admin-dashboard.css', HCOS_PLUGIN_FILE ), array(), HCOS_VERSION );
		wp_enqueue_style( 'hcos-reports', plugins_url( 'assets/css/admin-reports.css', HCOS_PLUGIN_FILE ), array( 'hcos-dashboard' ), HCOS_VERSION );
	}

	public static function render_page() {
		if ( ! current_user_can( 'hcos_view_finances' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'horse-club-os' ) );
		}

		$period = self::get_period();
		$data   = self::collect( $period['start'], $period['end'] );
		?>
		<div class="hcos-app hcos-reports-app">
			<?php HCOS_Dashboard::sidebar( 'reports' ); ?>
			<main class="hcos-main hcos-reports-main">
			<header class="hcos-header hcos-reports-header"><div><h1>Отчёты</h1><p><?php echo esc_html( wp_date( 'j F', $period['start']->getTimestamp(), wp_timezone() ) . ' — ' . wp_date( 'j F Y', $period['end']->getTimestamp(), wp_timezone() ) ); ?> · показатели клуба</p></div><div class="hcos-header-actions"><a class="hcos-reports-secondary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=payments&page=hcos-payments' ) ); ?>">Платежи</a><a class="hcos-primary-button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=lessons&page=hcos-calendar' ) ); ?>">Расписание</a></div></header>
			<div class="hcos-reports-content">
			<h1>Отчёты Horse Club OS</h1>
			<p class="description">Оперативные показатели клуба по данным CRM. Выручка показана за выбранный период, а долги и остатки — на текущий момент.</p>

			<form method="get" class="hcos-report-filter">
				<input type="hidden" name="page" value="hcos-reports">
				<label>С <input type="date" name="hcos_from" value="<?php echo esc_attr( $period['start']->format( 'Y-m-d' ) ); ?>"></label>
				<label>По <input type="date" name="hcos_to" value="<?php echo esc_attr( $period['end']->format( 'Y-m-d' ) ); ?>"></label>
				<?php submit_button( 'Показать', 'primary', '', false ); ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=hcos-reports' ) ); ?>">Текущий месяц</a>
			</form>

			<?php if ( $period['corrected'] ) : ?>
				<div class="notice notice-warning inline"><p>Даты были исправлены. Дата «С» не может быть позже даты «По».</p></div>
			<?php endif; ?>

			<div class="hcos-report-cards">
				<?php self::card( 'Занятия', $data['lessons_total'], 'Проведено: ' . $data['lessons_completed'] ); ?>
				<?php self::card( 'Посещаемость', $data['attendance_rate'] . '%', $data['attendance_present'] . ' из ' . $data['attendance_counted'] ); ?>
				<?php self::card( 'Чистая выручка', self::money( $data['revenue'] ), 'Оплаты минус возвраты' ); ?>
				<?php self::card( 'Текущий долг', self::money( $data['debt'] ), 'Абонементы и разовые записи' ); ?>
				<?php self::card( 'Остаток абонементов', self::number( $data['membership_balance'] ), 'Активных/замороженных: ' . $data['memberships_active'] ); ?>
				<?php self::card( 'Новые клиенты', $data['clients_new'], 'Неактивных сейчас: ' . $data['clients_inactive'] ); ?>
			</div>

			<div class="hcos-report-columns">
				<?php self::attendance_table( $data ); ?>
				<?php self::lesson_status_table( $data['lesson_statuses'] ); ?>
			</div>
			<div class="hcos-report-columns">
				<?php self::resource_table( 'Загрузка тренеров', 'Тренер', $data['trainers'] ); ?>
				<?php self::resource_table( 'Загрузка лошадей', 'Лошадь', $data['horses'] ); ?>
			</div>
			</div>
			</main>
		</div>
		<style>
			.hcos-report-filter{display:flex;align-items:end;gap:12px;flex-wrap:wrap;margin:20px 0;padding:16px;background:#fff;border:1px solid #dcdcde}.hcos-report-filter label{display:flex;flex-direction:column;gap:5px;font-weight:600}.hcos-report-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin:18px 0}.hcos-report-card,.hcos-report-panel{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:18px}.hcos-report-card h2{font-size:13px;margin:0 0 10px;color:#50575e;text-transform:uppercase}.hcos-report-value{font-size:28px;font-weight:600;line-height:1.15}.hcos-report-note{color:#646970;margin-top:7px}.hcos-report-columns{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:14px}.hcos-report-panel h2{margin-top:0}.hcos-report-panel table{width:100%;border-collapse:collapse}.hcos-report-panel th,.hcos-report-panel td{text-align:left;padding:9px 7px;border-bottom:1px solid #f0f0f1}.hcos-report-panel th:last-child,.hcos-report-panel td:last-child{text-align:right}.hcos-report-empty{color:#646970}@media(max-width:782px){.hcos-report-columns{grid-template-columns:1fr}}
		</style>
		<?php
	}

	private static function collect( DateTimeImmutable $start, DateTimeImmutable $end ) {
		$lesson_ids = self::ids_between( 'lessons', 'lesson_date', $start, $end );
		$data       = array(
			'lessons_total'       => count( $lesson_ids ),
			'lessons_completed'   => 0,
			'lesson_statuses'     => array(),
			'attendance_present'  => 0,
			'attendance_no_show'  => 0,
			'attendance_excused'  => 0,
			'attendance_expected' => 0,
			'trainers'            => array(),
			'horses'              => array(),
		);

		$load_statuses = array( 'planned', 'confirmed', 'completed', 'no_show' );
		foreach ( $lesson_ids as $lesson_id ) {
			$status = (string) get_post_meta( $lesson_id, 'lesson_status', true );
			$status = $status ?: 'planned';
			$data['lesson_statuses'][ $status ] = isset( $data['lesson_statuses'][ $status ] ) ? $data['lesson_statuses'][ $status ] + 1 : 1;
			if ( 'completed' === $status ) {
				$data['lessons_completed']++;
			}
			if ( in_array( $status, $load_statuses, true ) ) {
				self::add_resource( $data['trainers'], absint( get_post_meta( $lesson_id, 'lesson_trainer', true ) ) );
				self::add_resource( $data['horses'], absint( get_post_meta( $lesson_id, 'lesson_horse', true ) ) );
			}
		}

		$booking_ids = self::booking_ids_for_lessons( $lesson_ids );
		foreach ( $booking_ids as $booking_id ) {
			$attendance = (string) get_post_meta( $booking_id, 'booking_attendance', true );
			if ( in_array( $attendance, array( 'present', 'no_show' ), true ) && ! HCOS_Attendance::is_booking_finalization_allowed( $booking_id ) ) {
				$attendance = 'expected';
			}
			$key        = 'attendance_' . ( in_array( $attendance, array( 'present', 'no_show', 'excused', 'expected' ), true ) ? $attendance : 'expected' );
			$data[ $key ]++;
		}

		$data['attendance_counted'] = $data['attendance_present'] + $data['attendance_no_show'];
		$data['attendance_rate']    = $data['attendance_counted'] ? round( 100 * $data['attendance_present'] / $data['attendance_counted'] ) : 0;
		$data['revenue']            = self::revenue_between( $start, $end );
		$data['debt']               = self::current_debt();
		$memberships                = self::current_memberships();
		$data                       = array_merge( $data, $memberships );
		$data['clients_new']        = count( self::ids_between( 'clients', 'client_registration_date', $start, $end ) );
		$data['clients_inactive']   = self::count_by_meta( 'clients', 'client_status', 'inactive' );

		arsort( $data['trainers'] );
		arsort( $data['horses'] );
		return $data;
	}

	private static function ids_between( $post_type, $meta_key, DateTimeImmutable $start, DateTimeImmutable $end ) {
		return get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => $meta_key,
						'value'   => array( $start->format( 'Ymd' ), $end->format( 'Ymd' ) ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
	}

	private static function booking_ids_for_lessons( $lesson_ids ) {
		if ( ! $lesson_ids ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => 'bookings',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array( 'key' => 'booking_lesson', 'value' => array_map( 'absint', $lesson_ids ), 'compare' => 'IN' ),
				),
			)
		);
	}

	private static function revenue_between( DateTimeImmutable $start, DateTimeImmutable $end ) {
		$payment_ids = self::ids_between( 'payments', 'payment_date', $start, $end );
		$total       = 0.0;
		foreach ( $payment_ids as $payment_id ) {
			$status = (string) get_post_meta( $payment_id, 'payment_status', true );
			$amount = (float) get_post_meta( $payment_id, 'payment_amount', true );
			if ( 'paid' === $status ) {
				$total += $amount;
			} elseif ( 'refund' === $status ) {
				$total -= $amount;
			}
		}
		return $total;
	}

	private static function current_debt() {
		$total = 0.0;
		foreach ( array( 'memberships' => 'membership_debt_amount', 'bookings' => 'booking_debt_amount' ) as $post_type => $meta_key ) {
			$ids = get_posts( array( 'post_type' => $post_type, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
			foreach ( $ids as $id ) {
				$total += max( 0, (float) get_post_meta( $id, $meta_key, true ) );
			}
		}
		return $total;
	}

	private static function current_memberships() {
		$ids = get_posts(
			array(
				'post_type'      => 'memberships',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( array( 'key' => 'membership_status', 'value' => array( 'active', 'frozen' ), 'compare' => 'IN' ) ),
			)
		);
		$balance = 0.0;
		foreach ( $ids as $id ) {
			$balance += max( 0, (float) get_post_meta( $id, 'membership_balance', true ) );
		}
		return array( 'memberships_active' => count( $ids ), 'membership_balance' => $balance );
	}

	private static function count_by_meta( $post_type, $meta_key, $meta_value ) {
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => $meta_key,
				'meta_value'     => $meta_value,
			)
		);
		return (int) $query->found_posts;
	}

	private static function add_resource( &$items, $post_id ) {
		if ( ! $post_id ) {
			return;
		}
		$items[ $post_id ] = isset( $items[ $post_id ] ) ? $items[ $post_id ] + 1 : 1;
	}

	private static function get_period() {
		$timezone = wp_timezone();
		$today    = new DateTimeImmutable( 'today', $timezone );
		$default_start = $today->modify( 'first day of this month' );
		$default_end   = $today->modify( 'last day of this month' );
		$start = self::parse_date( isset( $_GET['hcos_from'] ) ? wp_unslash( $_GET['hcos_from'] ) : '', $timezone ) ?: $default_start;
		$end   = self::parse_date( isset( $_GET['hcos_to'] ) ? wp_unslash( $_GET['hcos_to'] ) : '', $timezone ) ?: $default_end;
		$corrected = false;
		if ( $start > $end ) {
			$tmp = $start;
			$start = $end;
			$end = $tmp;
			$corrected = true;
		}
		return array( 'start' => $start, 'end' => $end, 'corrected' => $corrected );
	}

	private static function parse_date( $value, DateTimeZone $timezone ) {
		$value = sanitize_text_field( $value );
		$date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );
		$errors = DateTimeImmutable::getLastErrors();
		return $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ? $date : null;
	}

	private static function card( $title, $value, $note ) {
		echo '<section class="hcos-report-card"><h2>' . esc_html( $title ) . '</h2><div class="hcos-report-value">' . esc_html( $value ) . '</div><div class="hcos-report-note">' . esc_html( $note ) . '</div></section>';
	}

	private static function attendance_table( $data ) {
		$rows = array( 'Присутствовали' => $data['attendance_present'], 'Неявка' => $data['attendance_no_show'], 'Уважительная отмена' => $data['attendance_excused'], 'Ожидается' => $data['attendance_expected'] );
		self::simple_table( 'Посещаемость', 'Результат', $rows );
	}

	private static function lesson_status_table( $statuses ) {
		$labels = array( 'planned' => 'Запланировано', 'confirmed' => 'Подтверждено', 'completed' => 'Проведено', 'cancelled_by_client' => 'Отменено клиентом', 'cancelled_by_club' => 'Отменено клубом', 'no_show' => 'Неявка', 'rescheduled' => 'Перенесено', 'cancelled' => 'Отменено' );
		$rows = array();
		foreach ( $statuses as $status => $count ) {
			$rows[ isset( $labels[ $status ] ) ? $labels[ $status ] : $status ] = $count;
		}
		self::simple_table( 'Статусы занятий', 'Статус', $rows );
	}

	private static function resource_table( $title, $heading, $items ) {
		$rows = array();
		foreach ( array_slice( $items, 0, 10, true ) as $post_id => $count ) {
			$rows[ get_the_title( $post_id ) ?: ( '#' . $post_id ) ] = $count;
		}
		self::simple_table( $title, $heading, $rows );
	}

	private static function simple_table( $title, $heading, $rows ) {
		echo '<section class="hcos-report-panel"><h2>' . esc_html( $title ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="hcos-report-empty">Нет данных за выбранный период.</p></section>';
			return;
		}
		echo '<table><thead><tr><th>' . esc_html( $heading ) . '</th><th>Количество</th></tr></thead><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( self::number( $value ) ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	private static function money( $value ) {
		return number_format_i18n( (float) $value, 2 ) . ' ₽';
	}

	private static function number( $value ) {
		return number_format_i18n( (float) $value, (float) $value === floor( (float) $value ) ? 0 : 1 );
	}
}
