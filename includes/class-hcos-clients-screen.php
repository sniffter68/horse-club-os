<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Clients_Screen {
	private static $hook = '';

	private static $role_labels = array(
		'rider'       => 'Всадник',
		'guardian'    => 'Родитель',
		'payer'       => 'Плательщик',
		'horse_owner' => 'Владелец',
		'contact'     => 'Контакт',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_page() {
		self::$hook = add_submenu_page(
			'edit.php?post_type=clients',
			'Клиенты Horse Club OS',
			'Обзор клиентов',
			'edit_hcos_clients',
			'hcos-clients',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( self::$hook !== $hook ) {
			return;
		}
		wp_enqueue_style( 'hcos-dashboard', plugins_url( 'assets/css/admin-dashboard.css', HCOS_PLUGIN_FILE ), array(), HCOS_VERSION );
		wp_enqueue_style( 'hcos-clients', plugins_url( 'assets/css/admin-clients.css', HCOS_PLUGIN_FILE ), array( 'hcos-dashboard' ), HCOS_VERSION );
	}

	public static function render_page() {
		if ( ! current_user_can( 'edit_hcos_clients' ) ) {
			wp_die( esc_html__( 'Недостаточно прав для просмотра клиентов.', 'horse-club-os' ) );
		}

		$search = isset( $_GET['hcos_search'] ) ? sanitize_text_field( wp_unslash( $_GET['hcos_search'] ) ) : '';
		$status = isset( $_GET['hcos_client_status'] ) ? sanitize_key( wp_unslash( $_GET['hcos_client_status'] ) ) : 'all';
		$role   = isset( $_GET['hcos_client_role'] ) ? sanitize_key( wp_unslash( $_GET['hcos_client_role'] ) ) : 'all';
		$status = in_array( $status, array( 'all', 'active', 'inactive', 'archived' ), true ) ? $status : 'all';
		$role   = 'all' === $role || isset( self::$role_labels[ $role ] ) ? $role : 'all';

		$all_clients = get_posts( array( 'post_type' => 'clients', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true ) );
		$summary     = self::summary( $all_clients );
		$clients     = self::filter_clients( $all_clients, $search, $status, $role );
		$finance     = current_user_can( 'hcos_view_finances' );
		$memberships = self::memberships_by_client( $finance );
		$debts       = self::debts_by_payer( $finance );
		?>
		<div class="hcos-app hcos-clients-app">
			<?php HCOS_Dashboard::sidebar( 'clients' ); ?>
			<main class="hcos-main hcos-clients-main">
				<?php self::render_header( count( $all_clients ) ); ?>
				<?php self::render_summary( $summary, $finance ); ?>
				<?php self::render_filters( $search, $status, $role ); ?>
				<?php self::render_table( $clients, $memberships, $debts, $finance ); ?>
			</main>
		</div>
		<?php
	}

	private static function render_header( $count ) {
		?>
		<header class="hcos-header hcos-clients-header">
			<div><h1>Клиенты</h1><p><?php echo esc_html( self::plural( $count, 'человек', 'человека', 'человек' ) ); ?> в базе клуба</p></div>
			<div class="hcos-header-actions"><a class="hcos-secondary-button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=clients' ) ); ?>">Стандартный список</a><a class="hcos-primary-button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=clients' ) ); ?>">＋ Новый клиент</a></div>
		</header>
		<?php
	}

	private static function render_summary( $summary, $finance ) {
		$items = array(
			array( 'Все клиенты', $summary['total'], 'в базе клуба', '' ),
			array( 'Активные', $summary['active'], 'готовы к записи', 'active' ),
			array( 'Всадники', $summary['riders'], 'участники занятий', '' ),
		);
		if ( $finance ) {
			$items[] = array( 'С активным абонементом', $summary['memberships'], 'активен или заморожен', 'membership' );
		}
		echo '<section class="hcos-client-summary">';
		foreach ( $items as $item ) {
			echo '<article class="hcos-client-stat ' . esc_attr( $item[3] ) . '"><span>' . esc_html( $item[0] ) . '</span><strong>' . esc_html( $item[1] ) . '</strong><small>' . esc_html( $item[2] ) . '</small></article>';
		}
		echo '</section>';
	}

	private static function render_filters( $search, $status, $role ) {
		?>
		<form class="hcos-client-filters" method="get">
			<input type="hidden" name="post_type" value="clients"><input type="hidden" name="page" value="hcos-clients">
			<label class="hcos-client-search"><span class="screen-reader-text">Поиск</span><input type="search" name="hcos_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Имя, телефон или email"></label>
			<label><span class="screen-reader-text">Статус</span><select name="hcos_client_status"><option value="all">Все статусы</option><?php self::option( 'active', 'Активные', $status ); ?><?php self::option( 'inactive', 'Неактивные', $status ); ?><?php self::option( 'archived', 'Архив', $status ); ?></select></label>
			<label><span class="screen-reader-text">Роль</span><select name="hcos_client_role"><option value="all">Все роли</option><?php foreach ( self::$role_labels as $key => $label ) { self::option( $key, $label, $role ); } ?></select></label>
			<button type="submit">Показать</button><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=clients&page=hcos-clients' ) ); ?>">Сбросить</a>
		</form>
		<?php
	}

	private static function render_table( $clients, $memberships, $debts, $finance ) {
		?>
		<section class="hcos-client-panel">
			<div class="hcos-client-panel-heading"><div><h2>Список клиентов</h2><p>Найдено: <?php echo esc_html( count( $clients ) ); ?></p></div></div>
			<?php if ( ! $clients ) : ?><div class="hcos-empty"><strong>Клиенты не найдены</strong><span>Измените условия поиска или добавьте нового клиента.</span></div><?php else : ?>
			<div class="hcos-client-table-wrap"><table class="hcos-client-table"><thead><tr><th>Клиент</th><th>Контакты</th><th>Статус</th><th>Абонемент</th><?php if ( $finance ) : ?><th>Долг</th><?php endif; ?><th></th></tr></thead><tbody>
			<?php foreach ( $clients as $client ) { self::render_client( $client, isset( $memberships[ $client->ID ] ) ? $memberships[ $client->ID ] : null, isset( $debts[ $client->ID ] ) ? $debts[ $client->ID ] : 0, $finance ); } ?>
			</tbody></table></div><?php endif; ?>
		</section>
		<?php
	}

	private static function render_client( $client, $membership, $debt, $finance ) {
		$phone       = trim( (string) get_post_meta( $client->ID, 'client_phone', true ) );
		$email       = trim( (string) get_post_meta( $client->ID, 'client_email', true ) );
		$status      = (string) get_post_meta( $client->ID, 'client_status', true ) ?: 'active';
		$roles       = self::roles( $client->ID );
		$payer_id    = absint( get_post_meta( $client->ID, 'client_payer', true ) );
		$status_text = array( 'active' => 'Активен', 'inactive' => 'Неактивен', 'archived' => 'Архив' );
		$edit_url    = get_edit_post_link( $client->ID );
		?>
		<tr>
			<td><div class="hcos-client-person"><span class="hcos-client-avatar"><?php echo esc_html( self::initials( $client->post_title ) ); ?></span><span><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $client->post_title ); ?></a><small><?php echo esc_html( implode( ' · ', $roles ) ); ?></small><?php if ( $payer_id && $payer_id !== $client->ID ) : ?><em>Плательщик: <?php echo esc_html( get_the_title( $payer_id ) ); ?></em><?php endif; ?></span></div></td>
			<td><div class="hcos-client-contacts"><?php if ( $phone ) : ?><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a><?php endif; ?><?php if ( $email ) : ?><a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a><?php endif; ?><?php if ( ! $phone && ! $email ) : ?><span>Не указаны</span><?php endif; ?></div></td>
			<td><span class="hcos-client-status is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( isset( $status_text[ $status ] ) ? $status_text[ $status ] : $status ); ?></span></td>
			<td><?php self::render_membership( $membership, $finance ); ?></td>
			<?php if ( $finance ) : ?><td><span class="hcos-client-debt <?php echo $debt > 0 ? 'has-debt' : ''; ?>"><?php echo esc_html( self::money( $debt ) ); ?></span></td><?php endif; ?>
			<td><a class="hcos-client-open" href="<?php echo esc_url( $edit_url ); ?>">Открыть →</a></td>
		</tr>
		<?php
	}

	private static function render_membership( $membership, $finance ) {
		if ( ! $finance ) {
			echo '<span class="hcos-client-muted">Скрыто</span>';
			return;
		}
		if ( ! $membership ) {
			echo '<span class="hcos-client-muted">Нет активного</span>';
			return;
		}
		$url = get_edit_post_link( $membership['id'] );
		echo '<a class="hcos-client-membership" href="' . esc_url( $url ) . '"><strong>' . esc_html( self::number( $membership['balance'] ) . ' занятий' ) . '</strong><small>до ' . esc_html( $membership['end'] ?: 'без даты' ) . '</small></a>';
	}

	private static function filter_clients( $clients, $search, $status, $role ) {
		$needle = self::lower( trim( $search ) );
		$digits = preg_replace( '/\D+/', '', $search );
		return array_values( array_filter( $clients, static function ( $client ) use ( $needle, $digits, $status, $role ) {
			$client_status = (string) get_post_meta( $client->ID, 'client_status', true ) ?: 'active';
			$roles         = get_post_meta( $client->ID, 'client_roles', true );
			$roles         = is_array( $roles ) ? $roles : array_filter( array( $roles ) );
			if ( 'all' !== $status && $status !== $client_status ) { return false; }
			if ( 'all' !== $role && ! in_array( $role, $roles, true ) ) { return false; }
			if ( '' === $needle ) { return true; }
			$phone = (string) get_post_meta( $client->ID, 'client_phone', true );
			$email = (string) get_post_meta( $client->ID, 'client_email', true );
			$text  = HCOS_Clients_Screen::lower( $client->post_title . ' ' . $phone . ' ' . $email );
			return false !== strpos( $text, $needle ) || ( $digits && false !== strpos( preg_replace( '/\D+/', '', $phone ), $digits ) );
		} ) );
	}

	private static function summary( $clients ) {
		$data = array( 'total' => count( $clients ), 'active' => 0, 'riders' => 0, 'memberships' => 0 );
		foreach ( $clients as $client ) {
			if ( 'active' === ( (string) get_post_meta( $client->ID, 'client_status', true ) ?: 'active' ) ) { $data['active']++; }
			$roles = get_post_meta( $client->ID, 'client_roles', true );
			if ( in_array( 'rider', is_array( $roles ) ? $roles : array_filter( array( $roles ) ), true ) ) { $data['riders']++; }
		}
		$ids = get_posts( array( 'post_type' => 'memberships', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => array( array( 'key' => 'membership_status', 'value' => array( 'active', 'frozen' ), 'compare' => 'IN' ) ) ) );
		$client_ids = array();
		foreach ( $ids as $id ) { $client_ids[] = absint( get_post_meta( $id, 'membership_client', true ) ); }
		$data['memberships'] = count( array_unique( array_filter( $client_ids ) ) );
		return $data;
	}

	private static function memberships_by_client( $finance ) {
		if ( ! $finance ) { return array(); }
		$result = array();
		$ids = get_posts( array( 'post_type' => 'memberships', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => array( array( 'key' => 'membership_status', 'value' => array( 'active', 'frozen' ), 'compare' => 'IN' ) ) ) );
		foreach ( $ids as $id ) {
			$client_id = absint( get_post_meta( $id, 'membership_client', true ) );
			if ( ! $client_id ) { continue; }
			$item = array( 'id' => $id, 'balance' => (float) get_post_meta( $id, 'membership_balance', true ), 'end_raw' => (string) get_post_meta( $id, 'membership_end_date', true ) );
			$item['end'] = self::date( $item['end_raw'] );
			if ( ! isset( $result[ $client_id ] ) || $item['end_raw'] > $result[ $client_id ]['end_raw'] ) { $result[ $client_id ] = $item; }
		}
		return $result;
	}

	private static function debts_by_payer( $finance ) {
		if ( ! $finance ) { return array(); }
		$result = array();
		foreach ( array( 'memberships' => 'membership_debt_amount', 'bookings' => 'booking_debt_amount' ) as $post_type => $debt_key ) {
			$ids = get_posts( array( 'post_type' => $post_type, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
			foreach ( $ids as $id ) {
				$debt = max( 0, (float) get_post_meta( $id, $debt_key, true ) );
				if ( $debt <= 0 ) { continue; }
				if ( 'memberships' === $post_type ) {
					$payer_id = absint( get_post_meta( $id, 'membership_payer', true ) );
					$rider_id = absint( get_post_meta( $id, 'membership_client', true ) );
					if ( ! $payer_id && $rider_id ) { $payer_id = absint( get_post_meta( $rider_id, 'client_payer', true ) ) ?: $rider_id; }
				} else {
					$payer_id = absint( get_post_meta( $id, 'booking_payer', true ) );
				}
				if ( $payer_id ) { $result[ $payer_id ] = isset( $result[ $payer_id ] ) ? $result[ $payer_id ] + $debt : $debt; }
			}
		}
		return $result;
	}

	private static function roles( $client_id ) {
		$value = get_post_meta( $client_id, 'client_roles', true );
		$value = is_array( $value ) ? $value : array_filter( array( $value ) );
		$roles = array();
		foreach ( $value as $role ) { $roles[] = isset( self::$role_labels[ $role ] ) ? self::$role_labels[ $role ] : $role; }
		return $roles ?: array( 'Роль не указана' );
	}

	private static function option( $value, $label, $selected ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>'; }
	private static function lower( $value ) { return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value ); }
	private static function initials( $name ) { $result = ''; foreach ( array_slice( preg_split( '/\s+/u', trim( $name ) ), 0, 2 ) as $part ) { $result .= function_exists( 'mb_substr' ) ? mb_substr( $part, 0, 1 ) : substr( $part, 0, 1 ); } return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $result ) : strtoupper( $result ); }
	private static function money( $value ) { return number_format_i18n( (float) $value, 0 ) . ' ₽'; }
	private static function number( $value ) { return number_format_i18n( (float) $value, (float) $value === floor( (float) $value ) ? 0 : 1 ); }
	private static function date( $value ) { $value = preg_replace( '/\D+/', '', (string) $value ); if ( 8 !== strlen( $value ) ) { return ''; } $date = DateTimeImmutable::createFromFormat( '!Ymd', $value, wp_timezone() ); return $date ? wp_date( 'd.m.Y', $date->getTimestamp(), wp_timezone() ) : ''; }
	private static function plural( $number, $one, $few, $many ) { $a = $number % 10; $b = $number % 100; return $number . ' ' . ( 1 === $a && 11 !== $b ? $one : ( $a >= 2 && $a <= 4 && ( $b < 12 || $b > 14 ) ? $few : $many ) ); }
}
