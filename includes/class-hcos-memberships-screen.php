<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Memberships_Screen {
	private static $hook = '';

	private static $status_labels = array(
		'draft'     => 'Черновик',
		'active'    => 'Активен',
		'frozen'    => 'Заморожен',
		'exhausted' => 'Исчерпан',
		'expired'   => 'Истёк',
		'cancelled' => 'Отменён',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_page() {
		self::$hook = add_submenu_page(
			'edit.php?post_type=memberships',
			'Абонементы Horse Club OS',
			'Обзор абонементов',
			'edit_hcos_memberships',
			'hcos-memberships',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( self::$hook !== $hook ) {
			return;
		}
		wp_enqueue_style( 'hcos-dashboard', plugins_url( 'assets/css/admin-dashboard.css', HCOS_PLUGIN_FILE ), array(), HCOS_VERSION );
		wp_enqueue_style( 'hcos-memberships-screen', plugins_url( 'assets/css/admin-memberships.css', HCOS_PLUGIN_FILE ), array( 'hcos-dashboard' ), HCOS_VERSION );
	}

	public static function render_page() {
		if ( ! current_user_can( 'edit_hcos_memberships' ) ) {
			wp_die( esc_html__( 'Недостаточно прав для просмотра абонементов.', 'horse-club-os' ) );
		}

		$search = isset( $_GET['hcos_search'] ) ? sanitize_text_field( wp_unslash( $_GET['hcos_search'] ) ) : '';
		$status = isset( $_GET['hcos_status'] ) ? sanitize_key( wp_unslash( $_GET['hcos_status'] ) ) : 'all';
		if ( 'all' !== $status && ! isset( self::$status_labels[ $status ] ) ) {
			$status = 'all';
		}
		$memberships = self::get_memberships();
		$summary     = self::summary( $memberships );
		$filtered    = self::filter( $memberships, $search, $status );
		$finance     = current_user_can( 'hcos_view_finances' );
		?>
		<div class="hcos-app hcos-memberships-app">
			<?php HCOS_Dashboard::sidebar( 'memberships' ); ?>
			<main class="hcos-main hcos-memberships-main">
				<header class="hcos-header hcos-memberships-header"><div><h1>Абонементы</h1><p><?php echo esc_html( self::plural( count( $memberships ), 'абонемент', 'абонемента', 'абонементов' ) . ' в базе клуба' ); ?></p></div><div class="hcos-header-actions"><a class="hcos-memberships-secondary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=pricing_plans' ) ); ?>">Тарифы и пакеты</a><a class="hcos-primary-button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=memberships' ) ); ?>">＋ Новый абонемент</a></div></header>

				<section class="hcos-memberships-summary">
					<?php self::stat( 'Всего', $summary['total'], 'в базе клуба', '' ); ?>
					<?php self::stat( 'Активные', $summary['active'], 'включая замороженные', 'active' ); ?>
					<?php self::stat( 'Остаток занятий', self::number( $summary['balance'] ), 'по действующим абонементам', 'balance' ); ?>
					<?php if ( $finance ) { self::stat( 'Текущий долг', self::money( $summary['debt'] ), 'по всем абонементам', $summary['debt'] > 0 ? 'debt' : '' ); } ?>
				</section>

				<form class="hcos-memberships-filters" method="get">
					<input type="hidden" name="post_type" value="memberships"><input type="hidden" name="page" value="hcos-memberships">
					<label class="hcos-memberships-search"><span class="screen-reader-text">Поиск</span><input type="search" name="hcos_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Поиск по клиенту, плательщику или тарифу"></label>
					<label><span class="screen-reader-text">Статус</span><select name="hcos_status"><option value="all">Все статусы</option><?php foreach ( self::$status_labels as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
					<button type="submit">Показать</button><a href="<?php echo esc_url( self::list_url() ); ?>">Сбросить</a>
				</form>

				<section class="hcos-memberships-panel"><div class="hcos-memberships-panel-heading"><div><h2>Список абонементов</h2><p><?php echo esc_html( 'Найдено: ' . count( $filtered ) ); ?></p></div><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=memberships' ) ); ?>">Стандартный список</a></div><?php self::render_table( $filtered, $finance ); ?></section>
			</main>
		</div>
		<?php
	}

	private static function get_memberships() {
		return get_posts( array( 'post_type' => 'memberships', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true ) );
	}

	private static function filter( $items, $search, $status ) {
		$needle = self::lower( trim( $search ) );
		return array_values( array_filter( $items, static function ( $item ) use ( $needle, $status ) {
			$item_status = (string) get_post_meta( $item->ID, 'membership_status', true ) ?: 'draft';
			if ( 'all' !== $status && $status !== $item_status ) { return false; }
			if ( '' === $needle ) { return true; }
			$rider = absint( get_post_meta( $item->ID, 'membership_client', true ) );
			$payer = absint( get_post_meta( $item->ID, 'membership_payer', true ) );
			$plan  = absint( get_post_meta( $item->ID, 'membership_plan', true ) );
			$text  = $item->post_title . ' ' . get_the_title( $rider ) . ' ' . get_the_title( $payer ) . ' ' . get_the_title( $plan ) . ' ' . get_post_meta( $item->ID, 'membership_plan_name_snapshot', true );
			return false !== strpos( HCOS_Memberships_Screen::lower( $text ), $needle );
		} ) );
	}

	private static function summary( $items ) {
		$data = array( 'total' => count( $items ), 'active' => 0, 'balance' => 0.0, 'debt' => 0.0 );
		foreach ( $items as $item ) {
			$status = (string) get_post_meta( $item->ID, 'membership_status', true ) ?: 'draft';
			if ( in_array( $status, array( 'active', 'frozen' ), true ) ) {
				$data['active']++;
				$data['balance'] += max( 0, (float) get_post_meta( $item->ID, 'membership_balance', true ) );
			}
			$data['debt'] += max( 0, (float) get_post_meta( $item->ID, 'membership_debt_amount', true ) );
		}
		return $data;
	}

	private static function stat( $label, $value, $note, $class ) {
		echo '<article class="hcos-memberships-stat ' . esc_attr( $class ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong><small>' . esc_html( $note ) . '</small></article>';
	}

	private static function render_table( $items, $finance ) {
		?>
		<div class="hcos-memberships-table-wrap"><table class="hcos-memberships-table"><thead><tr><th>Абонемент</th><th>Всадник и плательщик</th><th>Период</th><th>Остаток</th><?php if ( $finance ) : ?><th>Оплата</th><?php endif; ?><th>Статус</th><th></th></tr></thead><tbody>
		<?php if ( ! $items ) : ?><tr><td colspan="7"><div class="hcos-memberships-empty">Абонементы по выбранным условиям не найдены</div></td></tr><?php endif; ?>
		<?php foreach ( $items as $item ) { self::render_row( $item, $finance ); } ?>
		</tbody></table></div>
		<?php
	}

	private static function render_row( $item, $finance ) {
		$rider_id = absint( get_post_meta( $item->ID, 'membership_client', true ) );
		$payer_id = absint( get_post_meta( $item->ID, 'membership_payer', true ) );
		if ( ! $payer_id && $rider_id ) { $payer_id = absint( get_post_meta( $rider_id, 'client_payer', true ) ) ?: $rider_id; }
		$plan_id  = absint( get_post_meta( $item->ID, 'membership_plan', true ) );
		$plan     = (string) get_post_meta( $item->ID, 'membership_plan_name_snapshot', true );
		$plan     = $plan ?: ( $plan_id ? get_the_title( $plan_id ) : $item->post_title );
		$status   = (string) get_post_meta( $item->ID, 'membership_status', true ) ?: 'draft';
		$balance  = (float) get_post_meta( $item->ID, 'membership_balance', true );
		$limit    = (float) get_post_meta( $item->ID, 'membership_lesson_limit', true );
		$paid     = (float) get_post_meta( $item->ID, 'membership_paid_amount', true );
		$price    = (float) get_post_meta( $item->ID, 'membership_price', true );
		$debt     = max( 0, (float) get_post_meta( $item->ID, 'membership_debt_amount', true ) );
		$start    = self::date( get_post_meta( $item->ID, 'membership_start_date', true ) );
		$end      = self::date( get_post_meta( $item->ID, 'membership_end_date', true ) );
		$rider_url = $rider_id ? add_query_arg( array( 'post_type' => 'clients', 'page' => 'hcos-clients', 'hcos_client_id' => $rider_id ), admin_url( 'edit.php' ) ) : '';
		?>
		<tr><td><div class="hcos-memberships-name"><a href="<?php echo esc_url( get_edit_post_link( $item->ID ) ); ?>"><?php echo esc_html( $plan ?: 'Абонемент' ); ?></a><small><?php echo esc_html( $item->post_title ); ?></small></div></td><td><div class="hcos-memberships-people"><?php if ( $rider_url ) : ?><a href="<?php echo esc_url( $rider_url ); ?>"><?php echo esc_html( get_the_title( $rider_id ) ); ?></a><?php else : ?><span>Всадник не указан</span><?php endif; ?><small><?php echo esc_html( 'Плательщик: ' . ( $payer_id ? get_the_title( $payer_id ) : 'не указан' ) ); ?></small></div></td><td><div class="hcos-memberships-period"><strong><?php echo esc_html( $start ?: 'Без даты' ); ?></strong><small><?php echo esc_html( $end ? 'до ' . $end : 'без окончания' ); ?></small></div></td><td><div class="hcos-memberships-balance"><strong><?php echo esc_html( self::number( $balance ) ); ?></strong><small><?php echo esc_html( $limit ? 'из ' . self::number( $limit ) : 'занятий' ); ?></small></div></td><?php if ( $finance ) : ?><td><div class="hcos-memberships-payment"><strong><?php echo esc_html( self::money( $paid ) . ' / ' . self::money( $price ) ); ?></strong><small class="<?php echo $debt > 0 ? 'has-debt' : ''; ?>"><?php echo esc_html( $debt > 0 ? 'Долг ' . self::money( $debt ) : 'Оплачено' ); ?></small></div></td><?php endif; ?><td><span class="hcos-memberships-status is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( isset( self::$status_labels[ $status ] ) ? self::$status_labels[ $status ] : $status ); ?></span></td><td><a class="hcos-memberships-open" href="<?php echo esc_url( get_edit_post_link( $item->ID ) ); ?>">Редактировать →</a></td></tr>
		<?php
	}

	private static function list_url() { return add_query_arg( array( 'post_type' => 'memberships', 'page' => 'hcos-memberships' ), admin_url( 'edit.php' ) ); }
	private static function lower( $value ) { return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value ); }
	private static function money( $value ) { return number_format_i18n( (float) $value, 0 ) . ' ₽'; }
	private static function number( $value ) { return number_format_i18n( (float) $value, (float) $value === floor( (float) $value ) ? 0 : 1 ); }
	private static function date( $value ) { $value = preg_replace( '/\D+/', '', (string) $value ); if ( 8 !== strlen( $value ) ) { return ''; } $date = DateTimeImmutable::createFromFormat( '!Ymd', $value, wp_timezone() ); return $date ? wp_date( 'd.m.Y', $date->getTimestamp(), wp_timezone() ) : ''; }
	private static function plural( $number, $one, $few, $many ) { $a = $number % 10; $b = $number % 100; return $number . ' ' . ( 1 === $a && 11 !== $b ? $one : ( $a >= 2 && $a <= 4 && ( $b < 12 || $b > 14 ) ? $few : $many ) ); }
}
