<?php
/**
 * Horse Club OS full pilot regression suite.
 * Runs only inside the isolated wp-env test WordPress.
 */

defined( 'ABSPATH' ) || exit( 1 );

function hcos_t_fail( $message ) {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}
function hcos_t_pass( $message ) { fwrite( STDOUT, "PASS: {$message}\n" ); }
function hcos_t_same( $expected, $actual, $label ) {
	if ( $expected !== $actual ) {
		hcos_t_fail( $label . ' | expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
	hcos_t_pass( $label );
}
function hcos_t_true( $value, $label ) {
	if ( ! $value ) { hcos_t_fail( $label ); }
	hcos_t_pass( $label );
}
function hcos_t_post( $type, $title ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title ), true );
	if ( is_wp_error( $id ) || ! $id ) { hcos_t_fail( "cannot create {$type}" ); }
	return (int) $id;
}
function hcos_t_client( $name, $payer = 0 ) {
	$id = hcos_t_post( 'clients', $name );
	update_post_meta( $id, 'client_status', 'active' );
	update_post_meta( $id, 'client_registration_date', current_time( 'Ymd' ) );
	if ( $payer ) { update_post_meta( $id, 'client_payer', $payer ); }
	return $id;
}
function hcos_t_resource( $type, $name ) { return hcos_t_post( $type, $name ); }
function hcos_t_lesson( $title, $price = 2000, $capacity = 1, $status = 'planned', $trainer = 0, $horse = 0, $service = 0 ) {
	$id = hcos_t_post( 'lessons', $title );
	update_post_meta( $id, 'lesson_date', current_time( 'Ymd' ) );
	update_post_meta( $id, 'lesson_time', '11:00:00' );
	update_post_meta( $id, 'lesson_price', (string) $price );
	update_post_meta( $id, 'lesson_capacity', (string) $capacity );
	update_post_meta( $id, 'lesson_status', $status );
	if ( $trainer ) { update_post_meta( $id, 'lesson_trainer', $trainer ); }
	if ( $horse ) { update_post_meta( $id, 'lesson_horse', $horse ); }
	if ( $service ) { update_post_meta( $id, 'lesson_service', $service ); }
	return $id;
}
function hcos_t_booking( $lesson, $rider, $membership = 0, $status = 'confirmed', $attendance = 'expected' ) {
	$id = hcos_t_post( 'bookings', 'Automated booking' );
	update_post_meta( $id, 'booking_lesson', $lesson );
	update_post_meta( $id, 'booking_rider', $rider );
	update_post_meta( $id, 'booking_status', $status );
	update_post_meta( $id, 'booking_attendance', $attendance );
	update_post_meta( $id, 'booking_source', 'admin' );
	update_post_meta( $id, 'booking_charge_policy', 'auto' );
	if ( $membership ) { update_post_meta( $id, 'booking_membership', $membership ); }
	HCOS_Bookings::prepare_booking( $id );
	HCOS_Payments::recalculate_saved_object( $id );
	return $id;
}
function hcos_t_payment( $booking, $amount, $status = 'paid', $payer = 0 ) {
	$id = hcos_t_post( 'payments', 'Automated payment' );
	update_post_meta( $id, 'payment_date', current_time( 'Ymd' ) );
	update_post_meta( $id, 'payment_amount', (string) $amount );
	update_post_meta( $id, 'payment_method', 'cash' );
	update_post_meta( $id, 'payment_status', $status );
	update_post_meta( $id, 'payment_purpose_type', 'booking' );
	update_post_meta( $id, 'payment_booking', $booking );
	if ( $payer ) { update_post_meta( $id, 'payment_payer', $payer ); }
	HCOS_Payments::prepare_payment( $id );
	return $id;
}
function hcos_t_membership( $client, $payer, $limit = 4, $price = 8000 ) {
	$id = hcos_t_post( 'memberships', 'Automated membership' );
	update_post_meta( $id, 'membership_client', $client );
	update_post_meta( $id, 'membership_payer', $payer );
	update_post_meta( $id, 'membership_lesson_limit', (string) $limit );
	update_post_meta( $id, 'membership_price', (string) $price );
	update_post_meta( $id, 'membership_status', 'active' );
	HCOS_Memberships::prepare_membership( $id );
	HCOS_Payments::recalculate_saved_object( $id );
	return $id;
}
function hcos_t_booking_validation( $lesson, $rider, $status = 'confirmed', $current = 0 ) {
	$old = $_POST;
	$_POST = array(
		'post_ID' => $current,
		'acf' => array(
			'field_hcos_booking_lesson' => $lesson,
			'field_hcos_booking_status' => $status,
		),
	);
	$result = HCOS_Bookings::validate_booking( true, $rider, array(), 'acf[field_hcos_booking_rider]' );
	$_POST = $old;
	return $result;
}
function hcos_t_report_collect() {
	$ref = new ReflectionMethod( 'HCOS_Reports', 'collect' );
	$ref->setAccessible( true );
	$day = new DateTimeImmutable( 'today', wp_timezone() );
	return $ref->invoke( null, $day, $day );
}

foreach ( array( 'HCOS_Bookings', 'HCOS_Payments', 'HCOS_Memberships', 'HCOS_Attendance', 'HCOS_Security', 'HCOS_Reports' ) as $class ) {
	if ( ! class_exists( $class ) ) { hcos_t_fail( "{$class} is unavailable" ); }
}
if ( ! function_exists( 'update_field' ) ) { hcos_t_fail( 'ACF is unavailable' ); }

// Ensure roles/capabilities exist for permission tests.
HCOS_Security::install_roles();

$trainer_resource = hcos_t_resource( 'trainers', 'Regression Trainer' );
$horse_resource   = hcos_t_resource( 'horses', 'Regression Horse' );
$service_resource = hcos_t_resource( 'services', 'Regression Service' );

// 1. Adult pays for self; one-off payment clears debt.
$adult = hcos_t_client( 'Regression Adult' );
$adult_lesson = hcos_t_lesson( 'Adult lesson', 2000, 1, 'planned', $trainer_resource, $horse_resource, $service_resource );
$adult_booking = hcos_t_booking( $adult_lesson, $adult );
hcos_t_same( $adult, (int) get_post_meta( $adult_booking, 'booking_payer', true ), 'adult booking falls back to rider as payer' );
hcos_t_same( 2000.0, (float) get_post_meta( $adult_booking, 'booking_debt_amount', true ), 'adult one-off debt starts at lesson price' );
$adult_payment = hcos_t_payment( $adult_booking, 2000, 'paid' );
hcos_t_same( $adult, (int) get_post_meta( $adult_payment, 'payment_payer', true ), 'adult payment inherits booking payer' );
hcos_t_same( 'paid', (string) get_post_meta( $adult_booking, 'booking_payment_status', true ), 'adult booking becomes paid' );
hcos_t_same( 0.0, (float) get_post_meta( $adult_booking, 'booking_debt_amount', true ), 'adult debt becomes zero' );

// 2. Child uses explicit primary payer, not rider.
$parent = hcos_t_client( 'Regression Parent' );
$child_payer = hcos_t_client( 'Regression Separate Payer' );
$child = hcos_t_client( 'Regression Child', $child_payer );
update_post_meta( $child, 'client_guardians', array( $parent ) );
$child_lesson = hcos_t_lesson( 'Child lesson', 1800 );
$child_booking = hcos_t_booking( $child_lesson, $child );
hcos_t_same( $child_payer, (int) get_post_meta( $child_booking, 'booking_payer', true ), 'child booking uses primary payer' );
$child_payment = hcos_t_payment( $child_booking, 1800 );
hcos_t_same( $child_payer, (int) get_post_meta( $child_payment, 'payment_payer', true ), 'child payment inherits separate payer' );
hcos_t_same( 0.0, (float) get_post_meta( $child_booking, 'booking_debt_amount', true ), 'child debt becomes zero' );

// 3. One payer can fund multiple riders independently.
$family_payer = hcos_t_client( 'Regression Family Payer' );
$child_a = hcos_t_client( 'Regression Child A', $family_payer );
$child_b = hcos_t_client( 'Regression Child B', $family_payer );
$family_lesson_a = hcos_t_lesson( 'Family lesson A', 1500 );
$family_lesson_b = hcos_t_lesson( 'Family lesson B', 1500 );
$family_booking_a = hcos_t_booking( $family_lesson_a, $child_a );
$family_booking_b = hcos_t_booking( $family_lesson_b, $child_b );
hcos_t_same( $family_payer, (int) get_post_meta( $family_booking_a, 'booking_payer', true ), 'first child uses family payer' );
hcos_t_same( $family_payer, (int) get_post_meta( $family_booking_b, 'booking_payer', true ), 'second child uses family payer' );

// 4. Group lesson: unique riders allowed up to capacity; duplicate/overflow rejected.
$group_lesson = hcos_t_lesson( 'Group lesson', 1200, 2 );
$group_rider_1 = hcos_t_client( 'Regression Group Rider 1' );
$group_rider_2 = hcos_t_client( 'Regression Group Rider 2' );
$group_rider_3 = hcos_t_client( 'Regression Group Rider 3' );
hcos_t_same( true, hcos_t_booking_validation( $group_lesson, $group_rider_1 ), 'first rider fits group capacity' );
$group_booking_1 = hcos_t_booking( $group_lesson, $group_rider_1 );
hcos_t_true( is_string( hcos_t_booking_validation( $group_lesson, $group_rider_1 ) ), 'duplicate active rider is rejected' );
hcos_t_same( true, hcos_t_booking_validation( $group_lesson, $group_rider_2 ), 'second rider fits group capacity' );
$group_booking_2 = hcos_t_booking( $group_lesson, $group_rider_2 );
hcos_t_true( is_string( hcos_t_booking_validation( $group_lesson, $group_rider_3 ) ), 'third rider is rejected when capacity is full' );
hcos_t_same( 2, count( HCOS_Bookings::get_active_booking_ids( $group_lesson ) ), 'group lesson has exactly two active bookings' );

// 5. Pending/cancelled payments do not reduce debt; partial payment does.
$money_rider = hcos_t_client( 'Regression Money Rider' );
$money_lesson = hcos_t_lesson( 'Money states lesson', 3000 );
$money_booking = hcos_t_booking( $money_lesson, $money_rider );
hcos_t_payment( $money_booking, 3000, 'pending' );
hcos_t_same( 3000.0, (float) get_post_meta( $money_booking, 'booking_debt_amount', true ), 'pending payment does not reduce debt' );
hcos_t_payment( $money_booking, 3000, 'cancelled' );
hcos_t_same( 3000.0, (float) get_post_meta( $money_booking, 'booking_debt_amount', true ), 'cancelled payment does not reduce debt' );
hcos_t_payment( $money_booking, 1000, 'paid' );
hcos_t_same( 'partial', (string) get_post_meta( $money_booking, 'booking_payment_status', true ), 'partial payment sets partial status' );
hcos_t_same( 2000.0, (float) get_post_meta( $money_booking, 'booking_debt_amount', true ), 'partial payment leaves correct debt' );

// 6. Cash refund reopens debt and marks refund state.
$refund_rider = hcos_t_client( 'Regression Refund Rider' );
$refund_lesson = hcos_t_lesson( 'Refund lesson', 2200 );
$refund_booking = hcos_t_booking( $refund_lesson, $refund_rider );
hcos_t_payment( $refund_booking, 2200, 'paid' );
hcos_t_payment( $refund_booking, 2200, 'refund' );
hcos_t_same( 'refund', (string) get_post_meta( $refund_booking, 'booking_payment_status', true ), 'refund payment sets refund state' );
hcos_t_same( 2200.0, (float) get_post_meta( $refund_booking, 'booking_debt_amount', true ), 'full refund restores booking debt' );

// 7. Membership initial credit and payment/debt model.
$member_payer = hcos_t_client( 'Regression Membership Payer' );
$member_rider = hcos_t_client( 'Regression Membership Rider', $member_payer );
$membership = hcos_t_membership( $member_rider, $member_payer, 4, 8000 );
hcos_t_same( 4.0, (float) get_post_meta( $membership, 'membership_balance', true ), 'membership starts with initial balance' );
hcos_t_same( 8000.0, (float) get_post_meta( $membership, 'membership_debt_amount', true ), 'membership starts with full debt' );
$membership_payment = hcos_t_post( 'payments', 'Membership payment' );
update_post_meta( $membership_payment, 'payment_date', current_time( 'Ymd' ) );
update_post_meta( $membership_payment, 'payment_amount', '8000' );
update_post_meta( $membership_payment, 'payment_method', 'card' );
update_post_meta( $membership_payment, 'payment_status', 'paid' );
update_post_meta( $membership_payment, 'payment_purpose_type', 'membership' );
update_post_meta( $membership_payment, 'payment_membership', $membership );
HCOS_Payments::prepare_payment( $membership_payment );
hcos_t_same( $member_payer, (int) get_post_meta( $membership_payment, 'payment_payer', true ), 'membership payment inherits payer' );
hcos_t_same( 0.0, (float) get_post_meta( $membership, 'membership_debt_amount', true ), 'membership debt clears after payment' );

// 8. Attendance on membership debits exactly once, even after repeated processing.
$member_lesson = hcos_t_lesson( 'Membership attendance lesson', 2000 );
$member_booking = hcos_t_booking( $member_lesson, $member_rider, $membership, 'confirmed', 'present' );
HCOS_Attendance::snapshot_booking_rules( $member_booking );
HCOS_Attendance::process_booking( $member_booking );
HCOS_Memberships::recalculate( $membership );
hcos_t_same( 3.0, (float) get_post_meta( $membership, 'membership_balance', true ), 'present attendance debits one membership lesson' );
$first_debit = (int) get_post_meta( $member_booking, 'booking_membership_operation', true );
hcos_t_true( $first_debit > 0, 'membership debit operation is linked to booking' );
HCOS_Attendance::process_booking( $member_booking );
HCOS_Memberships::recalculate( $membership );
hcos_t_same( 3.0, (float) get_post_meta( $membership, 'membership_balance', true ), 'reprocessing attendance does not double-debit' );

// 9. Excused outcome after debit creates one refund and is idempotent.
update_post_meta( $member_booking, 'booking_attendance', 'excused' );
HCOS_Attendance::process_booking( $member_booking );
HCOS_Memberships::recalculate( $membership );
hcos_t_same( 4.0, (float) get_post_meta( $membership, 'membership_balance', true ), 'excused attendance refunds previously debited lesson' );
$refund_op = (int) get_post_meta( $member_booking, 'booking_membership_refund_operation', true );
hcos_t_true( $refund_op > 0, 'membership refund operation is linked to booking' );
HCOS_Attendance::process_booking( $member_booking );
HCOS_Memberships::recalculate( $membership );
hcos_t_same( 4.0, (float) get_post_meta( $membership, 'membership_balance', true ), 'reprocessing refund does not duplicate credit' );

// 10. No-show charges one lesson.
$noshow_lesson = hcos_t_lesson( 'No-show lesson', 2000 );
$noshow_booking = hcos_t_booking( $noshow_lesson, $member_rider, $membership, 'confirmed', 'no_show' );
HCOS_Attendance::snapshot_booking_rules( $noshow_booking );
HCOS_Attendance::process_booking( $noshow_booking );
HCOS_Memberships::recalculate( $membership );
hcos_t_same( 3.0, (float) get_post_meta( $membership, 'membership_balance', true ), 'no-show debits one membership lesson' );

// 11. Club cancellation refunds an already charged membership booking.
update_post_meta( $noshow_booking, 'booking_status', 'cancelled_by_club' );
update_post_meta( $noshow_booking, 'booking_attendance', 'expected' );
HCOS_Attendance::process_booking( $noshow_booking );
HCOS_Memberships::recalculate( $membership );
hcos_t_same( 4.0, (float) get_post_meta( $membership, 'membership_balance', true ), 'club cancellation refunds charged membership lesson' );

// 12. Trainer permissions: operational access, no finance/sensitive/delete.
$trainer_user = wp_create_user( 'hcos_regression_trainer', wp_generate_password( 24 ), 'trainer@example.test' );
wp_update_user( array( 'ID' => $trainer_user, 'role' => HCOS_Security::TRAINER_ROLE ) );
wp_set_current_user( $trainer_user );
hcos_t_true( current_user_can( 'edit_hcos_bookings' ), 'trainer can work with bookings' );
hcos_t_same( false, current_user_can( 'hcos_view_finances' ), 'trainer cannot view finances' );
hcos_t_same( false, current_user_can( 'hcos_view_sensitive_notes' ), 'trainer cannot view sensitive notes' );
hcos_t_same( false, current_user_can( 'delete_hcos_booking', $adult_booking ), 'trainer cannot delete bookings' );

// 13. Manager can see finances and sensitive information.
$manager_user = wp_create_user( 'hcos_regression_manager', wp_generate_password( 24 ), 'manager@example.test' );
wp_update_user( array( 'ID' => $manager_user, 'role' => HCOS_Security::MANAGER_ROLE ) );
wp_set_current_user( $manager_user );
hcos_t_true( current_user_can( 'hcos_view_finances' ), 'manager can view finances' );
hcos_t_true( current_user_can( 'hcos_view_sensitive_notes' ), 'manager can view sensitive notes' );

// 14. Reports use period revenue, current debt and booking attendance.
// Mark one lesson completed to distinguish slot status from booking attendance.
update_post_meta( $adult_lesson, 'lesson_status', 'completed' );
update_post_meta( $adult_booking, 'booking_attendance', 'present' );
$report = hcos_t_report_collect();
hcos_t_true( $report['lessons_total'] >= 1, 'report counts lessons in selected day' );
hcos_t_true( $report['lessons_completed'] >= 1, 'report counts completed lesson status' );
hcos_t_true( $report['attendance_present'] >= 1, 'report counts present booking attendance' );
hcos_t_true( $report['revenue'] > 0, 'report calculates positive net revenue from paid/refund operations' );
hcos_t_true( $report['debt'] >= 0, 'report calculates non-negative current debt' );
hcos_t_true( isset( $report['trainers'][ $trainer_resource ] ) && $report['trainers'][ $trainer_resource ] >= 1, 'report counts trainer load' );
hcos_t_true( isset( $report['horses'][ $horse_resource ] ) && $report['horses'][ $horse_resource ] >= 1, 'report counts horse load' );

fwrite( STDOUT, "\nALL HORSE CLUB OS PILOT REGRESSION TESTS PASSED.\n" );
