<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Audit {
	private static $writing = false;
	private static $old_values = array();

	private static $tracked_post_types = array(
		'clients', 'horses', 'trainers', 'services', 'lessons', 'bookings',
		'pricing_plans', 'memberships', 'membership_ops', 'payments',
	);

	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'log_status_change' ), 10, 3 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'remember_old_value' ), 10, 5 );
		add_action( 'added_post_meta', array( __CLASS__, 'log_added_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'log_updated_meta' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'log_deleted_meta' ), 10, 4 );
		add_filter( 'manage_hcos_audit_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_hcos_audit_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	public static function log_status_change( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status || ! $post instanceof WP_Post || ! self::tracks( $post->post_type ) ) {
			return;
		}
		self::write( 'status', $post->ID, 'post_status', $old_status, $new_status );
	}

	public static function log_added_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
		self::log_meta( 'field_added', $post_id, $meta_key, '', $meta_value );
	}

	public static function log_updated_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
		$index     = $post_id . ':' . $meta_key;
		$old_value = isset( self::$old_values[ $index ] ) ? self::$old_values[ $index ] : '';
		unset( self::$old_values[ $index ] );
		self::log_meta( 'field_updated', $post_id, $meta_key, $old_value, $meta_value );
	}

	public static function remember_old_value( $check, $post_id, $meta_key, $meta_value, $prev_value ) {
		if ( ! self::$writing && self::tracks( get_post_type( $post_id ) ) && self::track_meta_key( $meta_key ) ) {
			self::$old_values[ $post_id . ':' . $meta_key ] = get_post_meta( $post_id, $meta_key, true );
		}
		return $check;
	}

	public static function log_deleted_meta( $meta_ids, $post_id, $meta_key, $meta_value ) {
		self::log_meta( 'field_deleted', $post_id, $meta_key, $meta_value, '' );
	}

	private static function log_meta( $action, $post_id, $meta_key, $old_value, $new_value ) {
		if ( ! is_numeric( $post_id ) || ! self::tracks( get_post_type( $post_id ) ) || ! self::track_meta_key( $meta_key ) ) {
			return;
		}
		self::write( $action, (int) $post_id, $meta_key, $old_value, $new_value );
	}

	private static function tracks( $post_type ) {
		return in_array( $post_type, self::$tracked_post_types, true );
	}

	private static function track_meta_key( $meta_key ) {
		if ( ! is_string( $meta_key ) || '' === $meta_key || '_' === $meta_key[0] ) {
			return false;
		}
		return 0 !== strpos( $meta_key, 'hcos_audit_' );
	}

	private static function write( $action, $object_id, $field, $old_value, $new_value ) {
		if ( self::$writing ) {
			return;
		}

		self::$writing = true;
		$post_type     = get_post_type( $object_id );
		$object_title  = get_the_title( $object_id );
		$actor_id      = get_current_user_id();
		$actor         = $actor_id ? get_userdata( $actor_id ) : false;
		$title         = sprintf( '%s: %s #%d', current_time( 'd.m.Y H:i:s' ), $post_type, $object_id );

		$audit_id = wp_insert_post(
			array(
				'post_type'   => 'hcos_audit',
				'post_status' => 'private',
				'post_title'  => $title,
			),
			true
		);

		if ( ! is_wp_error( $audit_id ) ) {
			update_post_meta( $audit_id, 'hcos_audit_action', sanitize_key( $action ) );
			update_post_meta( $audit_id, 'hcos_audit_object_type', sanitize_key( $post_type ) );
			update_post_meta( $audit_id, 'hcos_audit_object_id', (int) $object_id );
			update_post_meta( $audit_id, 'hcos_audit_object_title', sanitize_text_field( $object_title ) );
			update_post_meta( $audit_id, 'hcos_audit_field', sanitize_key( $field ) );
			update_post_meta( $audit_id, 'hcos_audit_actor_id', (int) $actor_id );
			update_post_meta( $audit_id, 'hcos_audit_actor', $actor ? sanitize_text_field( $actor->display_name ) : 'Система' );
			update_post_meta( $audit_id, 'hcos_audit_old', self::safe_value( $field, $old_value ) );
			update_post_meta( $audit_id, 'hcos_audit_new', self::safe_value( $field, $new_value ) );
		}

		self::$writing = false;
	}

	private static function safe_value( $field, $value ) {
		if ( HCOS_Security::is_sensitive_field( $field ) ) {
			return '[скрыто]';
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
		}
		return function_exists( 'mb_substr' ) ? mb_substr( sanitize_text_field( (string) $value ), 0, 250 ) : substr( sanitize_text_field( (string) $value ), 0, 250 );
	}

	public static function columns( $columns ) {
		return array(
			'cb'           => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
			'title'        => 'Дата и объект',
			'audit_actor'  => 'Кто',
			'audit_action' => 'Действие',
			'audit_field'  => 'Поле',
			'audit_values' => 'Изменение',
		);
	}

	public static function render_column( $column, $post_id ) {
		if ( 'audit_actor' === $column ) {
			echo esc_html( get_post_meta( $post_id, 'hcos_audit_actor', true ) );
		} elseif ( 'audit_action' === $column ) {
			echo esc_html( get_post_meta( $post_id, 'hcos_audit_action', true ) );
		} elseif ( 'audit_field' === $column ) {
			echo esc_html( get_post_meta( $post_id, 'hcos_audit_field', true ) );
		} elseif ( 'audit_values' === $column ) {
			echo esc_html( get_post_meta( $post_id, 'hcos_audit_old', true ) . ' → ' . get_post_meta( $post_id, 'hcos_audit_new', true ) );
		}
	}
}
