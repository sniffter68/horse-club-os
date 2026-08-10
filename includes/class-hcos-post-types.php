<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_Post_Types {
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
	}

	public static function register_post_types() {
		$post_types = array(
			'clients'        => array( 'Клиенты', 'Клиент', 'dashicons-groups' ),
			'horses'         => array( 'Лошади', 'Лошадь', 'dashicons-pets' ),
			'trainers'       => array( 'Тренеры', 'Тренер', 'dashicons-businessperson' ),
			'services'       => array( 'Услуги', 'Услуга', 'dashicons-clipboard' ),
			'lessons'        => array( 'Занятия', 'Занятие', 'dashicons-calendar-alt' ),
			'bookings'       => array( 'Записи на занятия', 'Запись на занятие', 'dashicons-yes-alt' ),
			'pricing_plans'  => array( 'Тарифы / пакеты', 'Тариф / пакет', 'dashicons-money-alt' ),
			'memberships'    => array( 'Абонементы', 'Абонемент', 'dashicons-tickets-alt' ),
			'membership_ops' => array( 'Операции абонементов', 'Операция абонемента', 'dashicons-list-view' ),
			'payments'       => array( 'Платежи', 'Платёж', 'dashicons-money' ),
			'hcos_audit'     => array( 'Журнал изменений', 'Запись журнала', 'dashicons-shield' ),
		);
		$parent_menus = array(
			'bookings'       => 'edit.php?post_type=lessons',
			'pricing_plans'  => 'edit.php?post_type=memberships',
			'membership_ops' => 'edit.php?post_type=memberships',
			'hcos_audit'     => 'tools.php',
		);

		foreach ( $post_types as $slug => $settings ) {
			register_post_type(
				$slug,
				array(
					'labels'              => self::labels( $settings[0], $settings[1] ),
					'public'              => false,
					'publicly_queryable'  => false,
					'show_ui'             => true,
					'show_in_menu'        => isset( $parent_menus[ $slug ] ) ? $parent_menus[ $slug ] : true,
					'show_in_rest'        => 'hcos_audit' !== $slug,
					'rest_base'           => $slug,
					'menu_icon'           => $settings[2],
					'supports'            => array( 'title' ),
					'has_archive'         => false,
					'rewrite'             => false,
					'exclude_from_search' => true,
					'map_meta_cap'        => true,
					'capabilities'        => HCOS_Security::post_type_capabilities( $slug ),
				),
			);
		}
	}

	private static function labels( $plural, $singular ) {
		return array(
			'name'               => $plural,
			'singular_name'      => $singular,
			'menu_name'          => $plural,
			'add_new'            => 'Добавить',
			'add_new_item'       => 'Добавить: ' . $singular,
			'edit_item'          => 'Редактировать: ' . $singular,
			'new_item'           => 'Новый объект: ' . $singular,
			'view_item'          => 'Просмотреть: ' . $singular,
			'search_items'       => 'Поиск: ' . $plural,
			'not_found'          => 'Ничего не найдено',
			'not_found_in_trash' => 'В корзине ничего не найдено',
			'all_items'          => 'Все: ' . $plural,
		);
	}
}
