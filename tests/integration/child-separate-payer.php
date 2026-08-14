<?php
/**
 * Integration smoke test: child rider uses a separate primary payer.
 * Run inside wp-env with:
 * wp-env run cli wp eval-file wp-content/plugins/horse-club-os/tests/integration/child-separate-payer.php
 */

defined( 'ABSPATH' ) || exit( 1 );

function hcos_child_test_assert_same( $expected, $actual, $label ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, sprintf( "FAIL: %s\nExpected: %s\nActual: %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) ) );
		exit( 1 );
	}
	fwrite( STDOUT, "PASS: {$label}\n" );
}

function hcos_child_test_post( $post_type, $title ) {
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

$child_id = hcos_child_test_post( 'clients', 'Automated Child Rider' );
$guardian_id = hcos_child_test_post( 'clients', 'Automated Guardian' );
$payer_id = hcos_child_test_post( 'clients', 'Automated Separate Payer' );

update_post_meta( $child_id, 'client_status', 'active' );
update_post_meta( $guardian_id, 'client_status', 'active' );
update_post_meta( $payer_id, 'client_status', 'active' );
update_post_meta( $child_id, 'client_guardians', array( $guardian_id ) );
update_post_meta( $child_id, 'client_payer', $payer_id );

$lesson_id = hcos_child_test_post( 'lessons', 'Automated child lesson' );
update_post_meta( $lesson_id, 'lesson_date', gmdate( 'Y-m-d', strtotime( '+2 days' ) ) );
update_post_meta( $lesson_id, 'lesson_time', '12:00:00' );
update_post_meta( $lesson_id, 'lesson_price', '2500' );
update_post_meta( $lesson_id, 'lesson_capacity', '1' );
update_post_meta( $lesson_id, 'lesson_status', 'planned' );

$booking_id = hcos_child_test_post( 'bookings', 'Automated child booking' );
update_post_meta( $booking_id, 'booking_lesson', $lesson_id );
update_post_meta( $booking_id, 'booking_rider', $child_id );
update_post_meta( $booking_id, 'booking_status', 'confirmed' );
update_post_meta( $booking_id, 'booking_attendance', 'expected' );
update_post_meta( $booking_id, 'booking_source', 'admin' );

HCOS_Bookings::prepare_booking( $booking_id );
HCOS_Payments::recalculate_saved_object( $booking_id );

hcos_child_test_assert_same( $payer_id, (int) get_post_meta( $booking_id, 'booking_payer', true ), 'booking inherits child primary payer' );
hcos_child_test_assert_same( $child_id, (int) get_post_meta( $booking_id, 'booking_rider', true ), 'booking keeps child as rider' );
hcos_child_test_assert_same( 'unpaid', (string) get_post_meta( $booking_id, 'booking_payment_status', true ), 'child booking starts unpaid' );
hcos_child_test_assert_same( 2500.0, (float) get_post_meta( $booking_id, 'booking_debt_amount', true ), 'child booking debt equals lesson price' );

$payment_id = hcos_child_test_post( 'payments', 'Automated child payment' );
update_post_meta( $payment_id, 'payment_date', gmdate( 'Y-m-d' ) );
update_post_meta( $payment_id, 'payment_amount', '2500' );
update_post_meta( $payment_id, 'payment_method', 'card' );
update_post_meta( $payment_id, 'payment_status', 'paid' );
update_post_meta( $payment_id, 'payment_purpose_type', 'booking' );
update_post_meta( $payment_id, 'payment_booking', $booking_id );

HCOS_Payments::prepare_payment( $payment_id );

hcos_child_test_assert_same( $payer_id, (int) get_post_meta( $payment_id, 'payment_payer', true ), 'payment inherits separate payer from booking' );
hcos_child_test_assert_same( 'paid', (string) get_post_meta( $booking_id, 'booking_payment_status', true ), 'child booking becomes paid' );
hcos_child_test_assert_same( 0.0, (float) get_post_meta( $booking_id, 'booking_debt_amount', true ), 'child booking debt becomes zero' );

fwrite( STDOUT, "\nHCOS child separate payer smoke test passed.\n" );
