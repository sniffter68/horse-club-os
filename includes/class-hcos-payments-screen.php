<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Payments_Screen {
	private static $hook = '';

	private static $status_labels = array(
		'pending'   => 'Ожидает',
		'paid'      => 'Оплачен',
		'refund'    => 'Возврат',
		'cancelled' => 'Отменён',
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
			'edit.php?post_type=payments',
			'Платежи Horse Club OS',
			'Обзор платежей',
			'hcos_view_finances',
			'hcos-payments',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( self::$hook !== $hook ) {
			return;
		}
		wp_enqueue_style( 'hcos-dashboard', plugins_url( 'assets/css/admin-dashboard.css', HCOS_PLUGIN_FILE ), array(), HCOS_VERSION );
		wp_enqueue_style( 'hcos-payments-screen', plugins_url( 'assets/css/admin-payments.css', HCOS_PLUGIN_FILE ), array( 'hcos-dashboard' ), HCOS_VERSION );
	}

	public static function render_page() {
		if ( ! current_user_can( 'hcos_view_finances' ) ) {
			wp_die( esc_html__( 'Недостаточно прав для просмотра платежей.', 'horse-club-os' ) );
		}

		$search = isset( $_GET['hcos_search'] ) ? sanitize_text_field( wp_unslash( $_GET['hcos_search'] ) ) : '';
		$status = isset( $_GET['hcos_status'] ) ? sanitize_key( wp_unslash( $_GET['hcos_status'] ) ) : 'all';
		$method = isset( $_GET['hcos_method'] ) ? sanitize_key( wp_unslash( $_GET['hcos_method'] ) ) : 'all';
		if ( 'all' !== $status && ! isset( self::$status_labels[ $status ] ) ) {
			$status = 'all';
		}
		if ( 'all' !== $method && ! isset( self::$method_labels[ $method ] ) ) {
			$method = 'all';
		}

		$payments = self::get_payments();
		$summary  = self::summary( $payments );
		$filtered = self::filter( $payments, $search, $status, $method );
		?>
		<div class="hcos-app hcos-payments-app">
			<?php HCOS_Dashboard::sidebar( 'payments' ); ?>
			<main class="hcos-main hcos-payments-main">
				<header class="hcos-header hcos-payments-header"><div><h1>Платежи</h1><p><?php echo esc_html( self::plural( count( $payments ), 'операция', 'операции', 'операций' ) . ' в журнале' ); ?></p></div><div class="hcos-header-actions"><a class="hcos-payments-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=hcos-reports' ) ); ?>">Финансовый отчёт</a><a class="hcos-primary-button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=payments' ) ); ?>">＋ Принять оплату</a></div></header>

				<section class="hcos-payments-summary">
					<?php self::stat( 'Чистая выручка', self::money( $summary['net'] ), 'оплаты минус возвраты', 'net' ); ?>
					<?php self::stat( 'Получено', self::money( $summary['paid'] ), self::plural( $summary['paid_count'], 'оплата', 'оплаты', 'оплат' ), 'paid' ); ?>
					<?php self::stat( 'Возвращено', self::money( $summary['refund'] ), self::plural( $summary['refund_count'], 'возврат', 'возврата', 'возвратов' ), 'refund' ); ?>
					<?php self::stat( 'Ожидают', self::money( $summary['pending'] ), self::plural( $summary['pending_count'], 'операция', 'операции', 'операций' ), 'pending' ); ?>
				</section>

				<form class="hcos-payments-filters" method="get">
					<input type="hidden" name="post_type" value="payments"><input type="hidden" name="page" value="hcos-payments">
					<label class="hcos-payments-search"><span class="screen-reader-text">Поиск</span><input type="search" name="hcos_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Поиск по плательщику, назначению или номеру операции"></label>
					<label><span class="screen-reader-text">Статус</span><select name="hcos_status"><option value="all">Все статусы</option><?php foreach ( self::$status_labels as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
					<label><span class="screen-reader-text">Способ оплаты</span><select name="hcos_method"><option value="all">Все способы</option><?php foreach ( self::$method_labels as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $method, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
					<button type="submit">Показать</button><a href="<?php echo esc_url( self::list_url() ); ?>">Сбросить</a>
				</form>

				<section class="hcos-payments-panel"><div class="hcos-payments-panel-heading"><div><h2>Журнал платежей</h2><p><?php echo esc_html( 'Найдено: ' . count( $filtered ) ); ?></p></div><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=payments' ) ); ?>">Стандартный список</a></div><?php self::render_table( $filtered ); ?></section>
			</main>
		</div>
		<?php
	}

	private static function get_payments() {
		return get_posts( array( 'post_type' => 'payments', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => -1, 'orderby' => 'meta_value_num', 'meta_key' => 'payment_date', 'order' => 'DESC', 'no_found_rows' => true ) );
	}

	private static function filter( $items, $search, $status, $method ) {
		$needle = self::lower( trim( $search ) );
		return array_values( array_filter( $items, static function ( $item ) use ( $needle, $status, $method ) {
			$item_status = (string) get_post_meta( $item->ID, 'payment_status', true ) ?: 'pending';
			$item_method = (string) get_post_meta( $item->ID, 'payment_method', true );
			if ( 'all' !== $status && $status !== $item_status ) { return false; }
			if ( 'all' !== $method && $method !== $item_method ) { return false; }
			if ( '' === $needle ) { return true; }
			$payer_id = absint( get_post_meta( $item->ID, 'payment_payer', true ) );
			$target   = self::purpose( $item->ID );
			$text     = $item->post_title . ' ' . get_the_title( $payer_id ) . ' ' . $target['label'] . ' ' . get_post_meta( $item->ID, 'payment_purpose', true ) . ' ' . get_post_meta( $item->ID, 'payment_reference', true );
			return false !== strpos( HCOS_Payments_Screen::lower( $text ), $needle );
		} ) );
	}

	private static function summary( $items ) {
		$data = array( 'paid' => 0.0, 'refund' => 0.0, 'pending' => 0.0, 'net' => 0.0, 'paid_count' => 0, 'refund_count' => 0, 'pending_count' => 0 );
		foreach ( $items as $item ) {
			$status = (string) get_post_meta( $item->ID, 'payment_status', true ) ?: 'pending';
			$amount = max( 0, (float) get_post_meta( $item->ID, 'payment_amount', true ) );
			if ( 'publish' !== $item->post_status || 'pending' === $status ) {
				if ( 'cancelled' !== $status ) { $data['pending'] += $amount; $data['pending_count']++; }
			} elseif ( 'paid' === $status ) {
				$data['paid'] += $amount; $data['paid_count']++;
			} elseif ( 'refund' === $status ) {
				$data['refund'] += $amount; $data['refund_count']++;
			}
		}
		$data['net'] = $data['paid'] - $data['refund'];
		return $data;
	}

	private static function stat( $label, $value, $note, $class ) {
		echo '<article class="hcos-payments-stat ' . esc_attr( $class ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong><small>' . esc_html( $note ) . '</small></article>';
	}

	private static function render_table( $items ) {
		?>
		<div class="hcos-payments-table-wrap"><table class="hcos-payments-table"><thead><tr><th>Дата</th><th>Плательщик</th><th>Назначение</th><th>Способ</th><th>Сумма</th><th>Статус</th><th></th></tr></thead><tbody>
		<?php if ( ! $items ) : ?><tr><td colspan="7"><div class="hcos-payments-empty">Платежи по выбранным условиям не найдены</div></td></tr><?php endif; ?>
		<?php foreach ( $items as $item ) { self::render_row( $item ); } ?>
		</tbody></table></div>
		<?php
	}

	private static function render_row( $item ) {
		$payer_id = absint( get_post_meta( $item->ID, 'payment_payer', true ) );
		$status   = (string) get_post_meta( $item->ID, 'payment_status', true ) ?: 'pending';
		$method   = (string) get_post_meta( $item->ID, 'payment_method', true );
		$amount   = (float) get_post_meta( $item->ID, 'payment_amount', true );
		$date     = self::date( get_post_meta( $item->ID, 'payment_date', true ) );
		$purpose  = self::purpose( $item->ID );
		$reference = trim( (string) get_post_meta( $item->ID, 'payment_reference', true ) );
		$payer_url = $payer_id ? add_query_arg( array( 'post_type' => 'clients', 'page' => 'hcos-clients', 'hcos_client_id' => $payer_id ), admin_url( 'edit.php' ) ) : '';
		?>
		<tr><td><div class="hcos-payments-date"><strong><?php echo esc_html( $date ?: 'Без даты' ); ?></strong><small><?php echo esc_html( $reference ? '№ ' . $reference : $item->post_title ); ?></small></div></td><td><div class="hcos-payments-payer"><?php if ( $payer_url ) : ?><a href="<?php echo esc_url( $payer_url ); ?>"><?php echo esc_html( get_the_title( $payer_id ) ); ?></a><?php else : ?><span>Плательщик не указан</span><?php endif; ?></div></td><td><div class="hcos-payments-purpose"><?php if ( $purpose['url'] ) : ?><a href="<?php echo esc_url( $purpose['url'] ); ?>"><?php echo esc_html( $purpose['label'] ); ?></a><?php else : ?><span><?php echo esc_html( $purpose['label'] ); ?></span><?php endif; ?><small><?php echo esc_html( $purpose['type'] ); ?></small></div></td><td><span class="hcos-payments-method"><?php echo esc_html( isset( self::$method_labels[ $method ] ) ? self::$method_labels[ $method ] : ( $method ?: 'Не указан' ) ); ?></span></td><td><strong class="hcos-payments-amount <?php echo 'refund' === $status ? 'is-refund' : ''; ?>"><?php echo esc_html( ( 'refund' === $status ? '−' : '' ) . self::money( $amount ) ); ?></strong></td><td><span class="hcos-payments-status is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( isset( self::$status_labels[ $status ] ) ? self::$status_labels[ $status ] : $status ); ?></span></td><td><a class="hcos-payments-open" href="<?php echo esc_url( get_edit_post_link( $item->ID ) ); ?>">Редактировать →</a></td></tr>
		<?php
	}

	private static function purpose( $payment_id ) {
		$type = (string) get_post_meta( $payment_id, 'payment_purpose_type', true );
		if ( 'membership' === $type ) {
			$target_id = absint( get_post_meta( $payment_id, 'payment_membership', true ) );
			return array( 'label' => $target_id ? get_the_title( $target_id ) : 'Абонемент не указан', 'type' => 'Абонемент', 'url' => $target_id ? get_edit_post_link( $target_id ) : '' );
		}
		if ( 'booking' === $type ) {
			$target_id = absint( get_post_meta( $payment_id, 'payment_booking', true ) );
			return array( 'label' => $target_id ? get_the_title( $target_id ) : 'Запись не указана', 'type' => 'Разовое занятие', 'url' => $target_id ? get_edit_post_link( $target_id ) : '' );
		}
		$label = trim( (string) get_post_meta( $payment_id, 'payment_purpose', true ) );
		return array( 'label' => $label ?: 'Другое назначение', 'type' => 'Другое', 'url' => '' );
	}

	private static function list_url() { return add_query_arg( array( 'post_type' => 'payments', 'page' => 'hcos-payments' ), admin_url( 'edit.php' ) ); }
	private static function lower( $value ) { return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value ); }
	private static function money( $value ) { return number_format_i18n( (float) $value, 0 ) . ' ₽'; }
	private static function date( $value ) { $value = preg_replace( '/\D+/', '', (string) $value ); if ( 8 !== strlen( $value ) ) { return ''; } $date = DateTimeImmutable::createFromFormat( '!Ymd', $value, wp_timezone() ); return $date ? wp_date( 'd.m.Y', $date->getTimestamp(), wp_timezone() ) : ''; }
	private static function plural( $number, $one, $few, $many ) { $a = $number % 10; $b = $number % 100; return $number . ' ' . ( 1 === $a && 11 !== $b ? $one : ( $a >= 2 && $a <= 4 && ( $b < 12 || $b > 14 ) ? $few : $many ) ); }
}
