<?php
/**
 * Integration smoke test: adult rider pays for own one-off lesson.
 * Run inside wp-env with:
 * wp-env run cli wp eval-file wp-content/plugins/horse-club-os/tests/integration/adult-cash-payment.php
 */

defined( 'ABSPATH' ) || exit( 1 );

function hcos_test_assert_same( $expected, $actual, $label ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, sprintf( "FAIL: %s\nExpected: %s\nActual: %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) ) );
		exit( 1 );
	}
	fwrite( STDOUT, "PASS: {$label}\n" );
}

function hcos_test_post( $post_type, $title ) {
	$post_id = wp_insert_post(
		array(
			'post_type'   => $post_type,
			'post_status' => 'publish',
			'post_title'  => $title,
		),
		true
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		fwrite( STDERR, "FAIL: unable to create {$post_type}\n" );
		exit( 1 );
	}

	return (int) $post_id;
}

if ( ! class_exists( 'HCOS_Bookings' ) || ! class_exists( 'HCOS_Payments' ) ) {
	fwrite( STDERR, "FAIL: Horse Club OS is not active.\n" );
	exit( 1 );
}

if ( ! function_exists( 'update_field' ) ) {
	fwrite( STDERR, "FAIL: Advanced Custom Fields is not active.\n" );
	exit( 1 );
}

$client_id = hcos_test_post( 'clients', 'Automated Adult Rider' );
update_post_meta( $client_id, 'client_status', 'active' );

$lesson_id = hcos_test_post( 'lessons', 'Automated one-off lesson' );
update_post_meta( $lesson_id, 'lesson_date', gmdate( 'Y-m-d', strtotime( '+1 day' ) ) );
update_post_meta( $lesson_id, 'lesson_time', '11:00:00' );
update_post_meta( $lesson_id, 'lesson_price', '2000' );
update_post_meta( $lesson_id, 'lesson_capacity', '1' );
update_post_meta( $lesson_id, 'lesson_status', 'planned' );

$booking_id = hcos_test_post( 'bookings', 'Automated booking' );
update_post_meta( $booking_id, 'booking_lesson', $lesson_id );
update_post_meta( $booking_id, 'booking_rider', $client_id );
update_post_meta( $booking_id, 'booking_status', 'confirmed' );
update_post_meta( $booking_id, 'booking_attendance', 'expected' );
update_post_meta( $booking_id, 'booking_source', 'admin' );

HCOS_Bookings::prepare_booking( $booking_id );
HCOS_Payments::recalculate_saved_object( $booking_id );

hcos_test_assert_same( $client_id, (int) get_post_meta( $booking_id, 'booking_payer', true ), 'booking falls back to rider as payer' );
hcos_test_assert_same( 'unpaid', (string) get_post_meta( $booking_id, 'booking_payment_status', true ), 'booking starts unpaid' );
hcos_test_assert_same( 2000.0, (float) get_post_meta( $booking_id, 'booking_debt_amount', true ), 'booking debt equals lesson price' );

$payment_id = hcos_test_post( 'payments', 'Automated payment' );
update_post_meta( $payment_id, 'payment_date', gmdate( 'Y-m-d' ) );
update_post_meta( $payment_id, 'payment_amount', '2000' );
update_post_meta( $payment_id, 'payment_method', 'cash' );
update_post_meta( $payment_id, 'payment_status', 'paid' );
update_post_meta( $payment_id, 'payment_purpose_type', 'booking' );
update_post_meta( $payment_id, 'payment_booking', $booking_id );

HCOS_Payments::prepare_payment( $payment_id );

hcos_test_assert_same( $client_id, (int) get_post_meta( $payment_id, 'payment_payer', true ), 'payment inherits booking payer' );
hcos_test_assert_same( 'paid', (string) get_post_meta( $booking_id, 'booking_payment_status', true ), 'booking becomes paid' );
hcos_test_assert_same( 2000.0, (float) get_post_meta( $booking_id, 'booking_paid_amount', true ), 'paid amount equals payment' );
hcos_test_assert_same( 0.0, (float) get_post_meta( $booking_id, 'booking_debt_amount', true ), 'debt becomes zero after payment' );

update_post_meta( $booking_id, 'booking_attendance', 'present' );
HCOS_Attendance::process_booking( $booking_id );

hcos_test_assert_same( 'present', (string) get_post_meta( $booking_id, 'booking_attendance', true ), 'attendance is present' );
hcos_test_assert_same( 0, (int) get_post_meta( $booking_id, 'booking_membership_operation', true ), 'cash booking creates no membership debit' );
hcos_test_assert_same( 0.0, (float) get_post_meta( $booking_id, 'booking_debt_amount', true ), 'debt remains zero after attendance' );

fwrite( STDOUT, "\nHCOS adult cash payment smoke test passed.\n" );
