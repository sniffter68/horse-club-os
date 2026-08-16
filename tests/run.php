<?php

define( 'ABSPATH', __DIR__ . '/' );

$hcos_test_meta    = array();
$hcos_test_options = array();
$hcos_test_updates = array();
$hcos_test_caps    = array();

function absint( $value ) {
	return abs( (int) $value );
}

function wp_timezone() {
	return new DateTimeZone( 'Europe/Moscow' );
}

function admin_url( $path = '' ) {
	return 'https://crm.example.test/wp-admin/' . ltrim( $path, '/' );
}

function network_site_url( $path = '', $scheme = null ) {
	return 'https://crm.example.test/' . ltrim( $path, '/' );
}

function current_user_can( $capability ) {
	global $hcos_test_caps;
	return ! empty( $hcos_test_caps[ $capability ] );
}

function esc_url( $value ) {
	return (string) $value;
}

function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function get_post_meta( $post_id, $key, $single = false ) {
	global $hcos_test_meta;
	return isset( $hcos_test_meta[ $post_id ][ $key ] ) ? $hcos_test_meta[ $post_id ][ $key ] : '';
}

function get_posts( $args ) {
	global $hcos_test_meta;
	if ( 'memberships' === $args['post_type'] ) {
		return array( 1 );
	}
	if ( 'bookings' === $args['post_type'] ) {
		return array( 2, 3 );
	}
	if ( 'payments' !== $args['post_type'] ) {
		return array();
	}

	$ids = array();
	foreach ( array( 101, 102 ) as $payment_id ) {
		if ( isset( $hcos_test_meta[ $payment_id ][ $args['meta_key'] ] ) && (int) $hcos_test_meta[ $payment_id ][ $args['meta_key'] ] === (int) $args['meta_value'] ) {
			$ids[] = $payment_id;
		}
	}
	return $ids;
}

function update_field( $field, $value, $post_id ) {
	global $hcos_test_meta, $hcos_test_updates;
	$map = array(
		'field_hcos_membership_paid_amount'    => 'membership_paid_amount',
		'field_hcos_membership_debt_amount'    => 'membership_debt_amount',
		'field_hcos_membership_payment_status' => 'membership_payment_status',
		'field_hcos_booking_paid_amount'       => 'booking_paid_amount',
		'field_hcos_booking_debt_amount'       => 'booking_debt_amount',
		'field_hcos_booking_payment_status'    => 'booking_payment_status',
	);
	$hcos_test_meta[ $post_id ][ $map[ $field ] ] = $value;
	$hcos_test_updates[] = array( $post_id, $field, $value );
}

function get_option( $key ) {
	global $hcos_test_options;
	return isset( $hcos_test_options[ $key ] ) ? $hcos_test_options[ $key ] : false;
}

function update_option( $key, $value, $autoload = null ) {
	global $hcos_test_options;
	$hcos_test_options[ $key ] = $value;
	return true;
}

require_once dirname( __DIR__ ) . '/includes/class-hcos-attendance.php';
require_once dirname( __DIR__ ) . '/includes/class-hcos-payments.php';
require_once dirname( __DIR__ ) . '/includes/class-hcos-login.php';
require_once dirname( __DIR__ ) . '/includes/class-hcos-mail.php';
require_once dirname( __DIR__ ) . '/includes/class-hcos-security.php';
require_once dirname( __DIR__ ) . '/includes/class-hcos-dashboard.php';

function hcos_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: $message\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

$before = new DateTimeImmutable( '2026-08-16 10:59:59', wp_timezone() );
$start  = new DateTimeImmutable( '2026-08-16 11:00:00', wp_timezone() );
hcos_assert_same( false, HCOS_Attendance::is_datetime_started( '2026-08-16', '11:00:00', $before ), 'Future attendance must be blocked.' );
hcos_assert_same( true, HCOS_Attendance::is_datetime_started( '20260816', '110000', $start ), 'Attendance must be allowed at lesson start.' );
hcos_assert_same( false, HCOS_Attendance::is_datetime_started( 'invalid', '11:00', $start ), 'Invalid lesson date must fail closed.' );

class HCOS_Test_User {
	private $allowed;
	public function __construct( $allowed ) { $this->allowed = $allowed; }
	public function has_cap( $capability ) { return $this->allowed && 'edit_hcos_lessons' === $capability; }
}

hcos_assert_same( true, HCOS_Login::is_portal_path( '/', '/' ), 'Domain root must be recognized as the login portal.' );
hcos_assert_same( true, HCOS_Login::is_portal_path( '/wordpress/', '/wordpress/' ), 'Subdirectory WordPress root must be recognized.' );
hcos_assert_same( false, HCOS_Login::is_portal_path( '/wp-json/', '/' ), 'REST paths must not be treated as the login portal.' );
hcos_assert_same( true, HCOS_Login::can_use_portal( new HCOS_Test_User( true ) ), 'Horse Club OS staff capability must grant portal access.' );
hcos_assert_same( false, HCOS_Login::can_use_portal( new HCOS_Test_User( false ) ), 'Unrelated WordPress users must not enter the portal.' );
hcos_assert_same( 'https://crm.example.test/wp-admin/admin.php?page=hcos-dashboard', HCOS_Login::destination(), 'Successful login must open the Horse Club OS dashboard.' );

$mail_user               = new stdClass();
$mail_user->display_name = 'Инга Чернышова';
$reset_message           = HCOS_Mail::filter_password_reset_message( '', 'test-key', 'Inga', $mail_user );
hcos_assert_same( 'Союз любителей конного спорта', HCOS_Mail::filter_from_name( 'WordPress' ), 'Outgoing mail must use the club sender name.' );
hcos_assert_same( 'Доступ в Horse Club OS', HCOS_Mail::filter_password_reset_title( '', 'Inga', $mail_user ), 'Password reset email must use the branded subject.' );
hcos_assert_same( true, false !== strpos( $reset_message, 'Здравствуйте, Инга Чернышова!' ), 'Password reset email must address the user.' );
hcos_assert_same( true, false !== strpos( $reset_message, 'action=rp&key=test-key&login=Inga' ), 'Password reset email must contain the WordPress reset URL.' );

$mail_args = HCOS_Mail::format_password_reset_email(
	array(
		'subject' => HCOS_Mail::PASSWORD_RESET_TITLE,
		'headers' => "Content-Type: text/plain\nX-Horse-Club: OS",
	)
);
hcos_assert_same( array( 'X-Horse-Club: OS', 'Content-Type: text/html; charset=UTF-8' ), array_values( $mail_args['headers'] ), 'Password reset email must use one HTML content type header.' );

$sensitive_fields = new ReflectionProperty( 'HCOS_Security', 'sensitive_fields' );
$sensitive_fields->setAccessible( true );
hcos_assert_same( true, in_array( 'lesson_comment', $sensitive_fields->getValue(), true ), 'Trainer must not see the lesson administrator comment.' );

$attention = new ReflectionMethod( 'HCOS_Dashboard', 'attention' );
$attention->setAccessible( true );
$hcos_test_caps = array();
ob_start();
$attention->invoke( null, array() );
$trainer_actions = ob_get_clean();
hcos_assert_same( false, false !== strpos( $trainer_actions, 'Создать абонемент' ), 'Trainer dashboard must not offer membership creation.' );
hcos_assert_same( false, false !== strpos( $trainer_actions, 'Принять оплату' ), 'Trainer dashboard must not offer payment creation.' );

$hcos_test_caps = array( 'hcos_view_finances' => true, 'edit_hcos_memberships' => true );
ob_start();
$attention->invoke( null, array() );
$manager_actions = ob_get_clean();
hcos_assert_same( true, false !== strpos( $manager_actions, 'Создать абонемент' ), 'Manager dashboard must retain membership creation.' );
hcos_assert_same( true, false !== strpos( $manager_actions, 'Принять оплату' ), 'Manager dashboard must retain payment creation.' );

hcos_assert_same( 'unpaid', HCOS_Payments::payment_state( 1000, 0, 0 ), 'Zero payment must remain unpaid.' );
hcos_assert_same( 'partial', HCOS_Payments::payment_state( 1000, 400, 0 ), 'Partial payment must be marked partial.' );
hcos_assert_same( 'paid', HCOS_Payments::payment_state( 1000, 1000, 0 ), 'Full payment must be marked paid.' );
hcos_assert_same( 'refund', HCOS_Payments::payment_state( 1000, 800, 200 ), 'A refund must be visible.' );

$hcos_test_meta = array(
	1   => array( 'membership_price' => 1000 ),
	2   => array( 'booking_lesson' => 20 ),
	3   => array( 'booking_lesson' => 20, 'booking_membership' => 1 ),
	20  => array( 'lesson_price' => 2000 ),
	101 => array( 'payment_membership' => 1, 'payment_status' => 'paid', 'payment_amount' => 400 ),
	102 => array( 'payment_booking' => 2, 'payment_status' => 'paid', 'payment_amount' => 2000 ),
);

HCOS_Payments::backfill_existing_totals();
hcos_assert_same( 400.0, $hcos_test_meta[1]['membership_paid_amount'], 'Backfill must calculate membership paid amount.' );
hcos_assert_same( 600.0, $hcos_test_meta[1]['membership_debt_amount'], 'Backfill must calculate membership debt.' );
hcos_assert_same( 'partial', $hcos_test_meta[1]['membership_payment_status'], 'Backfill must calculate membership payment status.' );
hcos_assert_same( 0, $hcos_test_meta[2]['booking_debt_amount'], 'Paid one-off booking must have no debt.' );
hcos_assert_same( 'paid', $hcos_test_meta[2]['booking_payment_status'], 'Paid one-off booking must be marked paid.' );
hcos_assert_same( 'membership', $hcos_test_meta[3]['booking_payment_status'], 'Membership booking must not get money debt.' );
hcos_assert_same( '0.26.1', $hcos_test_options['hcos_payment_totals_backfill_version'], 'Backfill version must be stored.' );

$updates_before = count( $hcos_test_updates );
HCOS_Payments::backfill_existing_totals();
hcos_assert_same( $updates_before, count( $hcos_test_updates ), 'Completed backfill must not run twice.' );

echo "Horse Club OS regression tests passed.\n";
