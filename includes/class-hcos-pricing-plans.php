<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Pricing_Plans {
	public static function init() {
		add_filter( 'acf/validate_value/name=membership_plan', array( __CLASS__, 'validate_membership_plan' ), 10, 4 );
		add_filter( 'acf/prepare_field/name=membership_plan', array( __CLASS__, 'lock_membership_plan_field' ) );
		add_filter( 'acf/validate_value/name=membership_status', array( __CLASS__, 'validate_manual_terms' ), 10, 4 );
		add_action( 'acf/save_post', array( __CLASS__, 'snapshot_membership_plan' ), 15 );
		add_action( 'acf/save_post', array( __CLASS__, 'preserve_membership_plan' ), 20 );
	}

	public static function validate_membership_plan( $valid, $value, $field, $input ) {
		if ( true !== $valid ) {
			return $valid;
		}

		$plan_id     = absint( $value );
		$current_id = isset( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0;

		if ( $current_id && get_post_meta( $current_id, '_hcos_plan_snapshotted', true ) ) {
			$stored_plan_id = absint( get_post_meta( $current_id, 'membership_plan', true ) );
			if ( $stored_plan_id && ! $plan_id ) {
				return 'Тариф уже сохранён снимком и не может быть удалён.';
			}

			return $stored_plan_id && $stored_plan_id !== $plan_id ? 'Тариф уже сохранён снимком и не может быть заменён.' : $valid;
		}

		if ( ! $plan_id ) {
			return $valid;
		}

		if ( 'active' !== get_post_meta( $plan_id, 'pricing_plan_status', true ) ) {
			return 'Для нового абонемента можно выбрать только активный тариф.';
		}

		if ( $current_id && self::membership_has_operations( $current_id ) ) {
			return 'Нельзя назначить тариф абонементу, по которому уже есть операции. Его условия должны остаться историческими.';
		}

		return $valid;
	}

	public static function lock_membership_plan_field( $field ) {
		$post_id = 0;
		if ( isset( $_GET['post'] ) ) {
			$post_id = absint( $_GET['post'] );
		} elseif ( isset( $_POST['post_ID'] ) ) {
			$post_id = absint( $_POST['post_ID'] );
		}

		if ( $post_id && 'memberships' === get_post_type( $post_id ) && get_post_meta( $post_id, '_hcos_plan_snapshotted', true ) ) {
			$plan_id = self::get_immutable_plan_id( $post_id );
			if ( $plan_id ) {
				update_post_meta( $post_id, 'membership_plan', $plan_id );
				update_post_meta( $post_id, '_membership_plan', 'field_hcos_membership_plan' );
				$field['value'] = $plan_id;
			}

			$field['disabled']     = 1;
			$field['instructions'] = 'Условия тарифа уже сохранены в абонементе. Выбранный тариф нельзя заменить или удалить.';
		}

		return $field;
	}

	public static function validate_manual_terms( $valid, $value, $field, $input ) {
		if ( true !== $valid || ! isset( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) {
			return $valid;
		}

		$acf     = wp_unslash( $_POST['acf'] );
		$plan_id = isset( $acf['field_hcos_membership_plan'] ) ? absint( $acf['field_hcos_membership_plan'] ) : 0;
		if ( $plan_id ) {
			return $valid;
		}

		$lesson_limit = isset( $acf['field_hcos_membership_lesson_limit'] ) ? absint( $acf['field_hcos_membership_lesson_limit'] ) : 0;
		$end_date     = isset( $acf['field_hcos_membership_end_date'] ) ? sanitize_text_field( $acf['field_hcos_membership_end_date'] ) : '';
		if ( ! $lesson_limit || ! $end_date ) {
			return 'Выберите тариф либо вручную укажите количество занятий и дату окончания.';
		}

		return $valid;
	}

	public static function snapshot_membership_plan( $post_id ) {
		if ( ! is_numeric( $post_id ) || 'memberships' !== get_post_type( $post_id ) || get_post_meta( $post_id, '_hcos_plan_snapshotted', true ) ) {
			return;
		}

		$plan_id = absint( get_post_meta( $post_id, 'membership_plan', true ) );
		if ( ! $plan_id || 'pricing_plans' !== get_post_type( $plan_id ) || 'active' !== get_post_meta( $plan_id, 'pricing_plan_status', true ) ) {
			return;
		}

		$lesson_count = absint( get_post_meta( $plan_id, 'pricing_plan_lesson_count', true ) );
		$validity_days = absint( get_post_meta( $plan_id, 'pricing_plan_validity_days', true ) );
		$price         = get_post_meta( $plan_id, 'pricing_plan_price', true );
		$services      = function_exists( 'get_field' ) ? get_field( 'pricing_plan_services', $plan_id ) : array();
		$start_date    = function_exists( 'get_field' ) ? (string) get_field( 'membership_start_date', $post_id ) : '';

		if ( ! $lesson_count || ! $validity_days || ! $start_date ) {
			return;
		}

		$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, wp_timezone() );
		if ( ! $start ) {
			return;
		}

		$end = $start->modify( '+' . max( 0, $validity_days - 1 ) . ' days' );
		if ( ! get_post_meta( $post_id, 'membership_purchase_date', true ) ) {
			update_field( 'field_hcos_membership_purchase_date', current_time( 'Ymd' ), $post_id );
		}

		update_field( 'field_hcos_membership_end_date', $end->format( 'Ymd' ), $post_id );
		update_field( 'field_hcos_membership_lesson_limit', $lesson_count, $post_id );
		update_field( 'field_hcos_membership_price', $price, $post_id );
		update_field( 'field_hcos_membership_services', is_array( $services ) ? $services : array(), $post_id );
		update_field( 'field_hcos_membership_freeze_allowed', (bool) get_post_meta( $plan_id, 'pricing_plan_freeze_allowed', true ), $post_id );
		update_field( 'field_hcos_membership_freeze_days_limit', absint( get_post_meta( $plan_id, 'pricing_plan_freeze_days', true ) ), $post_id );
		update_field( 'field_hcos_membership_plan_name_snapshot', get_the_title( $plan_id ), $post_id );
		update_field( 'field_hcos_membership_plan_version_snapshot', get_post_meta( $plan_id, 'pricing_plan_version', true ), $post_id );
		update_field( 'field_hcos_membership_plan_validity_snapshot', $validity_days, $post_id );
		update_field( 'field_hcos_membership_cancellation_hours_snapshot', absint( get_post_meta( $plan_id, 'pricing_plan_cancellation_hours', true ) ), $post_id );
		update_field( 'field_hcos_membership_rules_snapshot', get_post_meta( $plan_id, 'pricing_plan_rules', true ), $post_id );
		update_post_meta( $post_id, '_hcos_plan_id_snapshot', $plan_id );
		update_post_meta( $post_id, '_hcos_plan_snapshotted', current_time( 'mysql' ) );
	}

	public static function preserve_membership_plan( $post_id ) {
		if ( ! is_numeric( $post_id ) || 'memberships' !== get_post_type( $post_id ) || ! get_post_meta( $post_id, '_hcos_plan_snapshotted', true ) ) {
			return;
		}

		$plan_id = self::get_immutable_plan_id( $post_id );
		if ( ! $plan_id ) {
			return;
		}

		update_post_meta( $post_id, '_hcos_plan_id_snapshot', $plan_id );
		update_post_meta( $post_id, 'membership_plan', $plan_id );
		update_post_meta( $post_id, '_membership_plan', 'field_hcos_membership_plan' );
	}

	private static function get_immutable_plan_id( $membership_id ) {
		$plan_id = absint( get_post_meta( $membership_id, '_hcos_plan_id_snapshot', true ) );
		if ( $plan_id && 'pricing_plans' === get_post_type( $plan_id ) ) {
			return $plan_id;
		}

		$plan_id = absint( get_post_meta( $membership_id, 'membership_plan', true ) );
		if ( $plan_id && 'pricing_plans' === get_post_type( $plan_id ) ) {
			return $plan_id;
		}

		$name    = trim( (string) get_post_meta( $membership_id, 'membership_plan_name_snapshot', true ) );
		$version = (string) get_post_meta( $membership_id, 'membership_plan_version_snapshot', true );
		if ( '' === $name ) {
			return 0;
		}

		$args = array(
			'post_type'      => 'pricing_plans',
			'post_status'    => 'any',
			'posts_per_page' => 2,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'title'           => $name,
		);
		if ( '' !== $version ) {
			$args['meta_key']   = 'pricing_plan_version';
			$args['meta_value'] = $version;
		}

		$matches = get_posts( $args );
		return 1 === count( $matches ) ? absint( $matches[0] ) : 0;
	}

	private static function membership_has_operations( $membership_id ) {
		return (bool) get_posts(
			array(
				'post_type'      => 'membership_ops',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => 'membership_op_membership',
				'meta_value'     => absint( $membership_id ),
			)
		);
	}
}
