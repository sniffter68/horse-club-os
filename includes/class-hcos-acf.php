<?php

defined( 'ABSPATH' ) || exit;

final class HCOS_ACF {
	public static function init() {
		add_action( 'acf/init', array( __CLASS__, 'register_field_groups' ) );
		add_action( 'acf/save_post', array( __CLASS__, 'prepare_client' ), 20 );
		add_action( 'acf/save_post', array( __CLASS__, 'prepare_lesson' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
	}

	public static function dependency_notice() {
		if ( function_exists( 'acf_add_local_field_group' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>Horse Club OS:</strong> для работы полей установите и активируйте плагин Advanced Custom Fields (ACF).</p></div>';
	}

	public static function register_field_groups() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		acf_add_local_field_group(
			array(
				'key'                   => 'group_hcos_client',
				'title'                 => 'Данные клиента',
				'fields'                => self::client_fields(),
				'location'              => self::location( 'clients' ),
				'position'              => 'normal',
				'style'                 => 'default',
				'active'                => true,
				'show_in_rest'          => 1,
			),
		);

		acf_add_local_field_group(
			array(
				'key'          => 'group_hcos_horse',
				'title'        => 'Данные лошади',
				'fields'       => array(
					self::field( 'horse_tab_identity', 'Основное', 'tab', array( 'placement' => 'top' ) ),
					self::field( 'horse_registered_name', 'Официальное имя', 'text', array( 'instructions' => 'Если отличается от клички в заголовке карточки.' ) ),
					self::field( 'horse_sex', 'Пол', 'select', array( 'choices' => array( 'mare' => 'Кобыла', 'gelding' => 'Мерин', 'stallion' => 'Жеребец' ), 'allow_null' => 1, 'wrapper' => array( 'width' => 33 ) ) ),
					self::field( 'horse_breed', 'Порода', 'text', array( 'wrapper' => array( 'width' => 33 ) ) ),
					self::field( 'horse_color', 'Масть', 'text', array( 'wrapper' => array( 'width' => 34 ) ) ),
					self::field( 'horse_birth_date', 'Дата рождения', 'date_picker', array( 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d' ) ),
					self::field( 'horse_photo', 'Фотография', 'image', array( 'return_format' => 'id', 'preview_size' => 'medium', 'library' => 'all' ) ),
					self::field( 'horse_tab_ownership', 'Владелец и учёт', 'tab', array( 'placement' => 'top' ) ),
					self::field( 'horse_owner', 'Владелец', 'post_object', array( 'post_type' => array( 'clients' ), 'allow_null' => 1, 'ui' => 1, 'return_format' => 'id', 'instructions' => 'Выберите клиента с ролью «Владелец лошади».' ) ),
					self::field( 'horse_stable_number', 'Номер денника', 'text', array( 'wrapper' => array( 'width' => 50 ) ) ),
					self::field( 'horse_inventory_number', 'Учётный номер', 'text', array( 'wrapper' => array( 'width' => 50 ) ) ),
					self::field( 'horse_tab_work', 'Работа и доступность', 'tab', array( 'placement' => 'top' ) ),
					self::field( 'horse_specializations', 'Специализация', 'checkbox', array( 'choices' => array( 'training' => 'Обучение', 'dressage' => 'Выездка', 'jumping' => 'Конкур', 'trail' => 'Прогулки', 'children' => 'Детские занятия', 'other' => 'Другое' ), 'layout' => 'horizontal' ) ),
					self::field( 'horse_min_rider_level', 'Минимальный уровень всадника', 'select', array( 'choices' => array( 'beginner' => 'Начинающий', 'intermediate' => 'Средний', 'advanced' => 'Продвинутый' ), 'allow_null' => 1 ) ),
					self::field( 'horse_allowed_services', 'Допустимые услуги', 'relationship', array( 'post_type' => array( 'services' ), 'filters' => array( 'search' ), 'return_format' => 'id', 'instructions' => 'Оставьте пустым, если специальных ограничений нет.' ) ),
					self::field( 'horse_max_daily_minutes', 'Максимальная нагрузка в день, минут', 'number', array( 'min' => 0, 'step' => 15, 'wrapper' => array( 'width' => 50 ) ) ),
					self::field( 'horse_min_break_minutes', 'Минимальный перерыв, минут', 'number', array( 'min' => 0, 'step' => 5, 'wrapper' => array( 'width' => 50 ) ) ),
					self::field( 'horse_status', 'Статус', 'select', array( 'choices' => array( 'active' => 'Активна', 'rest' => 'Отдых', 'unavailable' => 'Недоступна', 'archived' => 'Архив' ), 'default_value' => 'active' ) ),
					self::field( 'horse_unavailable_from', 'Недоступна с', 'date_picker', array( 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d', 'wrapper' => array( 'width' => 50 ) ) ),
					self::field( 'horse_unavailable_to', 'Недоступна до', 'date_picker', array( 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d', 'wrapper' => array( 'width' => 50 ) ) ),
					self::field( 'horse_unavailable_reason', 'Причина недоступности', 'textarea', array( 'rows' => 2 ) ),
					self::field( 'horse_tab_notes', 'Особенности', 'tab', array( 'placement' => 'top' ) ),
					self::field( 'horse_behavior_notes', 'Поведение и особенности работы', 'textarea', array( 'rows' => 4 ) ),
					self::field( 'horse_equipment_notes', 'Экипировка и снаряжение', 'textarea', array( 'rows' => 3 ) ),
					self::field( 'horse_notes', 'Внутренний комментарий', 'textarea' ),
				),
				'location'     => self::location( 'horses' ),
				'active'       => true,
				'show_in_rest' => 1,
			),
		);

		acf_add_local_field_group(
			array(
				'key'          => 'group_hcos_trainer',
				'title'        => 'Данные тренера',
				'fields'       => array(
					self::field( 'trainer_tab_identity', 'Основное', 'tab', array( 'placement' => 'top' ) ),
					self::field( 'trainer_phone', 'Телефон', 'text' ),
					self::field( 'trainer_email', 'Email', 'email' ),
					self::field( 'trainer_start_date', 'Дата начала работы', 'date_picker', array( 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d' ) ),
					self::field( 'trainer_status', 'Статус', 'select', array( 'choices' => array( 'active' => 'Работает', 'vacation' => 'Отпуск', 'sick' => 'Больничный', 'inactive' => 'Не работает', 'archived' => 'Архив' ), 'default_value' => 'active' ) ),
					self::field( 'trainer_photo', 'Фотография', 'image', array( 'return_format' => 'id', 'preview_size' => 'medium', 'library' => 'all' ) ),
					self::field( 'trainer_wp_user', 'Пользователь WordPress', 'user', array( 'allow_null' => 1, 'return_format' => 'id', 'instructions' => 'Понадобится для личного входа тренера и разграничения доступа.' ) ),
					self::field( 'trainer_tab_qualification', 'Квалификация', 'tab', array( 'placement' => 'top' ) ),
					self::field( 'trainer_specializations', 'Специализации', 'checkbox', array( 'choices' => array( 'beginner_training' => 'Обучение начинающих', 'children' => 'Детские занятия', 'dressage' => 'Выездка', 'jumping' => 'Конкур', 'trail' => 'Прогулки', 'rehabilitation' => 'Восстановительные занятия', 'other' => 'Другое' ), 'layout' => 'horizontal' ) ),
					self::field( 'trainer_rider_levels', 'Уровни всадников', 'checkbox', array( 'choices' => array( 'beginner' => 'Начинающий', 'intermediate' => 'Средний', 'advanced' => 'Продвинутый' ), 'layout' => 'horizontal' ) ),
					self::field( 'trainer_allowed_services', 'Проводимые услуги', 'relationship', array( 'post_type' => array( 'services' ), 'filters' => array( 'search' ), 'return_format' => 'id', 'instructions' => 'Оставьте пустым, если тренер может проводить любые активные услуги.' ) ),
					self::field( 'trainer_qualification', 'Образование и квалификация', 'textarea', array( 'rows' => 4 ) ),
					self::field( 'trainer_tab_schedule', 'Базовый график', 'tab', array( 'placement' => 'top' ) ),
					self::field( 'trainer_work_days', 'Рабочие дни', 'checkbox', array( 'choices' => array( 'monday' => 'Пн', 'tuesday' => 'Вт', 'wednesday' => 'Ср', 'thursday' => 'Чт', 'friday' => 'Пт', 'saturday' => 'Сб', 'sunday' => 'Вс' ), 'layout' => 'horizontal' ) ),
					self::field( 'trainer_work_start', 'Начало рабочего дня', 'time_picker', array( 'display_format' => 'H:i', 'return_format' => 'H:i:s', 'wrapper' => array( 'width' => 50 ) ) ),
					self::field( 'trainer_work_end', 'Окончание рабочего дня', 'time_picker', array( 'display_format' => 'H:i', 'return_format' => 'H:i:s', 'wrapper' => array( 'width' => 50 ) ) ),
					self::field( 'trainer_max_daily_lessons', 'Максимум занятий в день', 'number', array( 'min' => 0, 'step' => 1, 'wrapper' => array( 'width' => 50 ) ) ),
					self::field( 'trainer_min_break_minutes', 'Минимальный перерыв, минут', 'number', array( 'min' => 0, 'step' => 5, 'wrapper' => array( 'width' => 50 ) ) ),
					self::field( 'trainer_schedule_notes', 'Особенности графика', 'textarea', array( 'rows' => 3, 'instructions' => 'Исключения и отпуска позднее будут отдельными периодами доступности.' ) ),
					self::field( 'trainer_tab_finance', 'Расчёт оплаты', 'tab', array( 'placement' => 'top' ) ),
					self::field( 'trainer_payment_scheme', 'Схема оплаты', 'select', array( 'choices' => array( 'per_lesson' => 'За занятие', 'hourly' => 'Почасовая', 'fixed' => 'Фиксированная', 'mixed' => 'Смешанная' ), 'allow_null' => 1 ) ),
					self::field( 'trainer_rate', 'Базовая ставка', 'number', array( 'min' => 0, 'step' => 0.01, 'instructions' => 'Внутреннее поле. Ограничение доступа будет добавлено на этапе ролей.' ) ),
					self::field( 'trainer_tab_notes', 'Заметки', 'tab', array( 'placement' => 'top' ) ),
					self::field( 'trainer_public_description', 'Описание для клиентов', 'textarea', array( 'rows' => 4 ) ),
					self::field( 'trainer_admin_notes', 'Внутренние заметки', 'textarea', array( 'rows' => 4 ) ),
				),
				'location'     => self::location( 'trainers' ),
				'active'       => true,
				'show_in_rest' => 1,
			),
		);

		acf_add_local_field_group(
			array(
				'key'          => 'group_hcos_service',
				'title'        => 'Данные услуги',
				'fields'       => array(
					self::field( 'service_tab_main', 'Основное', 'tab', array( 'placement' => 'top' ) ),
					self::field( 'service_category', 'Категория', 'select', array( 'choices' => array( 'training' => 'Обучение', 'sport' => 'Спортивная тренировка', 'trail' => 'Прогулка', 'children' => 'Детское занятие', 'horse_care' => 'Уход за лошадью', 'event' => 'Мероприятие', 'other' => 'Другое' ), 'allow_null' => 1 ) ),
					self::field( 'service_format', 'Формат', 'select', array( 'choices' => array( 'individual' => 'Индивидуальный', 'group' => 'Групповой' ), 'default_value' => 'individual', 'wrapper' => array( 'width' => 50 ) ) ),
					self::field( 'service_capacity', 'Максимум участников', 'number', array( 'min' => 1, 'step' => 1, 'default_value' => 1, 'wrapper' => array( 'width' => 50 ), 'instructions' => 'Групповые занятия будут полностью поддержаны на отдельном этапе.' ) ),
					self::field( 'service_duration', 'Продолжительность, минут', 'number', array( 'min' => 0, 'step' => 5, 'wrapper' => array( 'width' => 50 ) ) ),
					self::field( 'service_price', 'Базовая стоимость', 'number', array( 'min' => 0, 'step' => 0.01, 'wrapper' => array( 'width' => 50 ), 'instructions' => 'При создании занятия цена будет сохраняться снимком, чтобы изменение тарифа не меняло историю.' ) ),
					self::field( 'service_status', 'Статус', 'select', array( 'choices' => array( 'active' => 'Активна', 'inactive' => 'Неактивна' ), 'default_value' => 'active' ) ),
					self::field( 'service_tab_eligibility', 'Ограничения', 'tab', array( 'placement' => 'top' ) ),
					self::field( 'service_rider_levels', 'Допустимые уровни всадников', 'checkbox', array( 'choices' => array( 'beginner' => 'Начинающий', 'intermediate' => 'Средний', 'advanced' => 'Продвинутый' ), 'layout' => 'horizontal', 'instructions' => 'Оставьте пустым, если услуга доступна всем уровням.' ) ),
					self::field( 'service_allowed_trainers', 'Допустимые тренеры', 'relationship', array( 'post_type' => array( 'trainers' ), 'filters' => array( 'search' ), 'return_format' => 'id', 'instructions' => 'Оставьте пустым, если услугу может проводить любой активный тренер.' ) ),
					self::field( 'service_allowed_horses', 'Допустимые лошади', 'relationship', array( 'post_type' => array( 'horses' ), 'filters' => array( 'search' ), 'return_format' => 'id', 'instructions' => 'Оставьте пустым, если специальных ограничений нет.' ) ),
					self::field( 'service_min_age', 'Минимальный возраст', 'number', array( 'min' => 0, 'step' => 1, 'wrapper' => array( 'width' => 50 ) ) ),
					self::field( 'service_max_age', 'Максимальный возраст', 'number', array( 'min' => 0, 'step' => 1, 'wrapper' => array( 'width' => 50 ) ) ),
					self::field( 'service_tab_rules', 'Оплата и отмена', 'tab', array( 'placement' => 'top' ) ),
					self::field( 'service_membership_allowed', 'Можно оплатить абонементом', 'true_false', array( 'ui' => 1, 'default_value' => 1, 'ui_on_text' => 'Да', 'ui_off_text' => 'Нет' ) ),
					self::field( 'service_free_cancellation_hours', 'Бесплатная отмена не позднее, часов', 'number', array( 'min' => 0, 'step' => 1 ) ),
					self::field( 'service_late_cancel_policy', 'Правило поздней отмены', 'select', array( 'choices' => array( 'charge_full' => 'Списать занятие полностью', 'charge_partial' => 'Частичное списание', 'no_charge' => 'Не списывать', 'manual' => 'Решает администратор' ), 'allow_null' => 1 ) ),
					self::field( 'service_cancellation_notes', 'Пояснение правил отмены', 'textarea', array( 'rows' => 3 ) ),
					self::field( 'service_tab_description', 'Описание', 'tab', array( 'placement' => 'top' ) ),
					self::field( 'service_public_description', 'Описание для клиентов', 'textarea', array( 'rows' => 4 ) ),
					self::field( 'service_admin_notes', 'Внутренние заметки', 'textarea', array( 'rows' => 4 ) ),
				),
				'location'     => self::location( 'services' ),
				'active'       => true,
				'show_in_rest' => 1,
			),
		);

		acf_add_local_field_group(
			array(
				'key'          => 'group_hcos_lesson',
				'title'        => 'Данные занятия',
				'fields'       => self::lesson_fields(),
				'location'     => self::location( 'lessons' ),
				'active'       => true,
				'show_in_rest' => 1,
			),
		);

		acf_add_local_field_group(
			array(
				'key'          => 'group_hcos_booking',
				'title'        => 'Данные записи на занятие',
				'fields'       => self::booking_fields(),
				'location'     => self::location( 'bookings' ),
				'active'       => true,
				'show_in_rest' => 1,
			),
		);

		acf_add_local_field_group(
			array(
				'key'          => 'group_hcos_pricing_plan',
				'title'        => 'Условия тарифа / пакета',
				'fields'       => self::pricing_plan_fields(),
				'location'     => self::location( 'pricing_plans' ),
				'active'       => true,
				'show_in_rest' => 1,
			),
		);

		acf_add_local_field_group(
			array(
				'key'          => 'group_hcos_membership',
				'title'        => 'Данные абонемента',
				'fields'       => self::membership_fields(),
				'location'     => self::location( 'memberships' ),
				'active'       => true,
				'show_in_rest' => 1,
			),
		);

		acf_add_local_field_group(
			array(
				'key'          => 'group_hcos_membership_op',
				'title'        => 'Данные операции абонемента',
				'fields'       => self::membership_operation_fields(),
				'location'     => self::location( 'membership_ops' ),
				'active'       => true,
				'show_in_rest' => 1,
			),
		);

		acf_add_local_field_group(
			array(
				'key'          => 'group_hcos_payment',
				'title'        => 'Данные платежа',
				'fields'       => self::payment_fields(),
				'location'     => self::location( 'payments' ),
				'active'       => true,
				'show_in_rest' => 1,
			),
		);
	}

	private static function client_fields() {
		return array(
			self::field( 'client_tab_identity', 'Основное', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'client_last_name', 'Фамилия', 'text', array( 'wrapper' => array( 'width' => 33 ) ) ),
			self::field( 'client_first_name', 'Имя', 'text', array( 'wrapper' => array( 'width' => 33 ) ) ),
			self::field( 'client_middle_name', 'Отчество', 'text', array( 'wrapper' => array( 'width' => 34 ) ) ),
			self::field(
				'client_roles',
				'Роли человека',
				'checkbox',
				array(
					'choices'       => array(
						'rider'       => 'Всадник',
						'guardian'    => 'Родитель / представитель',
						'payer'       => 'Плательщик',
						'horse_owner' => 'Владелец лошади',
						'contact'     => 'Контактное лицо',
					),
					'layout'        => 'horizontal',
					'return_format' => 'value',
					'instructions'  => 'Можно выбрать несколько ролей. Для участника занятия выберите «Всадник».',
				)
			),
			self::field( 'client_phone', 'Телефон', 'text' ),
			self::field( 'client_email', 'Email', 'email' ),
			self::field( 'client_birth_date', 'Дата рождения', 'date_picker', array( 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d' ) ),
			self::field( 'client_registration_date', 'Дата регистрации', 'date_picker', array( 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d', 'instructions' => 'Для новых клиентов заполняется автоматически при первом сохранении.' ) ),
			self::field(
				'client_source',
				'Источник привлечения',
				'select',
				array(
					'choices'    => array(
						'vk'             => 'VK',
						'telegram'       => 'Telegram',
						'website'        => 'Сайт',
						'recommendation' => 'Рекомендация',
						'event'          => 'Мероприятие',
						'other'          => 'Другое',
					),
					'allow_null' => 1,
				)
			),
			self::field( 'client_level', 'Уровень подготовки', 'select', array( 'choices' => array( 'beginner' => 'Начинающий', 'intermediate' => 'Средний', 'advanced' => 'Продвинутый' ), 'allow_null' => 1 ) ),
			self::field( 'client_status', 'Статус', 'select', array( 'choices' => array( 'active' => 'Активен', 'inactive' => 'Неактивен', 'archived' => 'Архив' ), 'default_value' => 'active' ) ),
			self::field( 'client_tab_relations', 'Связи и предпочтения', 'tab', array( 'placement' => 'top' ) ),
			self::field(
				'client_guardians',
				'Родители / представители',
				'relationship',
				array(
					'post_type'     => array( 'clients' ),
					'filters'       => array( 'search' ),
					'return_format' => 'id',
					'instructions'  => 'Укажите одного или нескольких взрослых для несовершеннолетнего всадника.',
				)
			),
			self::field(
				'client_payer',
				'Основной плательщик',
				'post_object',
				array(
					'post_type'     => array( 'clients' ),
					'allow_null'    => 1,
					'ui'            => 1,
					'return_format' => 'id',
					'instructions'  => 'Оставьте пустым, если человек платит за себя.',
				)
			),
			self::field( 'client_preferred_trainer', 'Предпочитаемый тренер', 'post_object', array( 'post_type' => array( 'trainers' ), 'allow_null' => 1, 'ui' => 1, 'return_format' => 'id' ) ),
			self::field( 'client_preferred_format', 'Предпочитаемый формат занятий', 'select', array( 'choices' => array( 'individual' => 'Индивидуальный', 'group' => 'Групповой', 'any' => 'Любой' ), 'allow_null' => 1 ) ),
			self::field( 'client_experience', 'Опыт верховой езды', 'textarea', array( 'rows' => 3 ) ),
			self::field( 'client_goals', 'Цели занятий', 'textarea', array( 'rows' => 3 ) ),
			self::field( 'client_tab_communication', 'Связь и согласия', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'client_vk_id', 'VK ID', 'text' ),
			self::field( 'client_telegram', 'Telegram', 'text' ),
			self::field( 'client_contact_channels', 'Предпочитаемые каналы связи', 'checkbox', array( 'choices' => array( 'phone' => 'Телефон', 'email' => 'Email', 'vk' => 'VK', 'telegram' => 'Telegram' ), 'layout' => 'horizontal' ) ),
			self::field( 'client_notification_contacts', 'Дополнительные получатели уведомлений', 'relationship', array( 'post_type' => array( 'clients' ), 'filters' => array( 'search' ), 'return_format' => 'id' ) ),
			self::field( 'client_notifications_consent', 'Согласие на уведомления', 'true_false', array( 'ui' => 1, 'ui_on_text' => 'Есть', 'ui_off_text' => 'Нет' ) ),
			self::field( 'client_consent_date', 'Дата согласия', 'date_picker', array( 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d' ) ),
			self::field( 'client_tab_notes', 'Особенности и заметки', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'client_emergency_contact', 'Экстренный контакт', 'textarea', array( 'rows' => 2 ) ),
			self::field( 'client_medical_notes', 'Медицинские примечания', 'textarea' ),
			self::field( 'client_admin_notes', 'Внутренние заметки', 'textarea' ),
		);
	}

	private static function lesson_fields() {
		$relation = array( 'type' => 'post_object', 'return_format' => 'id', 'ui' => 1, 'required' => 1 );

		return array(
			self::field( 'lesson_tab_resources', 'Ресурсы занятия', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'lesson_horse', 'Лошадь', 'post_object', array_merge( $relation, array( 'post_type' => array( 'horses' ) ) ) ),
			self::field( 'lesson_trainer', 'Тренер', 'post_object', array_merge( $relation, array( 'post_type' => array( 'trainers' ) ) ) ),
			self::field( 'lesson_service', 'Услуга', 'post_object', array_merge( $relation, array( 'post_type' => array( 'services' ) ) ) ),
			self::field( 'lesson_tab_schedule', 'Дата и стоимость', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'lesson_date', 'Дата', 'date_picker', array( 'required' => 1, 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d' ) ),
			self::field( 'lesson_time', 'Время', 'time_picker', array( 'required' => 1, 'display_format' => 'H:i', 'return_format' => 'H:i:s' ) ),
			self::field( 'lesson_duration', 'Продолжительность, минут', 'number', array( 'min' => 0, 'step' => 5, 'wrapper' => array( 'width' => 50 ), 'instructions' => 'При первом сохранении копируется из услуги, затем хранится независимо.' ) ),
			self::field( 'lesson_end_time', 'Время окончания', 'time_picker', array( 'display_format' => 'H:i', 'return_format' => 'H:i:s', 'wrapper' => array( 'width' => 50 ), 'instructions' => 'Рассчитывается автоматически по времени начала и продолжительности.' ) ),
			self::field( 'lesson_capacity', 'Вместимость', 'number', array( 'min' => 1, 'step' => 1, 'instructions' => 'При первом сохранении копируется из услуги. Участники добавляются отдельными записями.' ) ),
			self::field( 'lesson_price', 'Цена занятия', 'number', array( 'min' => 0, 'step' => 0.01, 'instructions' => 'При первом сохранении копируется из услуги и остаётся историческим снимком.' ) ),
			self::field( 'lesson_tab_status', 'Статус слота', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'lesson_status', 'Статус занятия', 'select', array( 'choices' => array( 'planned' => 'Запланировано', 'confirmed' => 'Подтверждено', 'completed' => 'Проведено', 'cancelled_by_client' => 'Отменено клиентом', 'cancelled_by_club' => 'Отменено клубом', 'no_show' => 'Неявка', 'rescheduled' => 'Перенесено', 'cancelled' => 'Отменено (старый статус)' ), 'default_value' => 'planned', 'required' => 1 ) ),
			self::field( 'lesson_cancellation_reason', 'Причина отмены', 'textarea', array( 'rows' => 2 ) ),
			self::field( 'lesson_rescheduled_from', 'Перенесено из занятия', 'post_object', array( 'post_type' => array( 'lessons' ), 'allow_null' => 1, 'ui' => 1, 'return_format' => 'id' ) ),
			self::field( 'lesson_tab_notes', 'Заметки', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'lesson_result', 'Результат занятия', 'textarea', array( 'rows' => 3 ) ),
			self::field( 'lesson_trainer_notes', 'Заметка тренера', 'textarea', array( 'rows' => 3 ) ),
			self::field( 'lesson_comment', 'Комментарий администратора', 'textarea' ),
		);
	}

	private static function booking_fields() {
		return array(
			self::field( 'booking_tab_participant', 'Участник', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'booking_lesson', 'Занятие', 'post_object', array( 'post_type' => array( 'lessons' ), 'required' => 1, 'ui' => 1, 'return_format' => 'id' ) ),
			self::field( 'booking_rider', 'Всадник', 'post_object', array( 'post_type' => array( 'clients' ), 'required' => 1, 'ui' => 1, 'return_format' => 'id' ) ),
			self::field( 'booking_payer', 'Заказчик / плательщик', 'post_object', array( 'post_type' => array( 'clients' ), 'allow_null' => 1, 'ui' => 1, 'return_format' => 'id', 'instructions' => 'Если не указан, используется основной плательщик всадника или сам всадник.' ) ),
			self::field( 'booking_horse', 'Лошадь участника', 'post_object', array( 'post_type' => array( 'horses' ), 'allow_null' => 1, 'ui' => 1, 'return_format' => 'id', 'instructions' => 'Для индивидуального занятия копируется из занятия; для группы может быть назначена отдельно.' ) ),
			self::field( 'booking_tab_state', 'Состояние записи', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'booking_status', 'Статус записи', 'select', array( 'choices' => array( 'pending' => 'Ожидает подтверждения', 'confirmed' => 'Подтверждена', 'cancelled_by_client' => 'Отменена клиентом', 'cancelled_by_club' => 'Отменена клубом', 'waitlist' => 'Лист ожидания' ), 'default_value' => 'confirmed', 'required' => 1 ) ),
			self::field( 'booking_attendance', 'Посещение', 'select', array( 'choices' => array( 'expected' => 'Ожидается', 'present' => 'Присутствовал', 'no_show' => 'Неявка', 'excused' => 'Уважительная отмена' ), 'default_value' => 'expected' ) ),
			self::field( 'booking_source', 'Источник записи', 'select', array( 'choices' => array( 'admin' => 'Администратор', 'vk' => 'VK', 'telegram' => 'Telegram', 'website' => 'Сайт', 'import' => 'Импорт', 'migration' => 'Перенос старых данных', 'other' => 'Другое' ), 'default_value' => 'admin' ) ),
			self::field( 'booking_cancellation_reason', 'Причина отмены', 'textarea', array( 'rows' => 2 ) ),
			self::field( 'booking_cancelled_at', 'Дата и время отмены', 'date_time_picker', array( 'display_format' => 'd.m.Y H:i', 'return_format' => 'Y-m-d H:i:s', 'instructions' => 'При отмене заполняется автоматически, если поле пустое.' ) ),
			self::field( 'booking_tab_finance', 'Абонемент и оплата', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'booking_membership', 'Абонемент', 'post_object', array( 'post_type' => array( 'memberships' ), 'allow_null' => 1, 'ui' => 1, 'return_format' => 'id' ) ),
			self::field( 'booking_membership_operation', 'Операция списания', 'post_object', array( 'post_type' => array( 'membership_ops' ), 'allow_null' => 1, 'ui' => 1, 'return_format' => 'id' ) ),
			self::field( 'booking_membership_refund_operation', 'Операция возврата', 'post_object', array( 'post_type' => array( 'membership_ops' ), 'allow_null' => 1, 'ui' => 1, 'return_format' => 'id' ) ),
			self::field( 'booking_charge_policy', 'Решение по списанию', 'select', array( 'choices' => array( 'auto' => 'По правилам автоматически', 'charge' => 'Списать занятие', 'no_charge' => 'Не списывать / вернуть', 'manual' => 'Не выполнять автоматически' ), 'default_value' => 'auto', 'instructions' => 'Обычно оставьте автоматический режим. Ручной выбор используется для исключений.' ) ),
			self::field( 'booking_charge_result', 'Результат обработки абонемента', 'text', array( 'readonly' => 1, 'disabled' => 1 ) ),
			self::field( 'booking_cancellation_hours_snapshot', 'Бесплатная отмена, часов (снимок)', 'number', array( 'readonly' => 1, 'disabled' => 1 ) ),
			self::field( 'booking_late_cancel_policy_snapshot', 'Поздняя отмена (снимок)', 'select', array( 'choices' => array( 'charge_full' => 'Списать занятие полностью', 'charge_partial' => 'Частичное списание — вручную', 'no_charge' => 'Не списывать', 'manual' => 'Решает администратор' ), 'disabled' => 1 ) ),
			self::field( 'booking_payment_status', 'Состояние оплаты', 'select', array( 'choices' => array( 'unpaid' => 'Не оплачено', 'paid' => 'Оплачено', 'membership' => 'Абонемент', 'partial' => 'Частично', 'refund' => 'Возврат' ), 'default_value' => 'unpaid' ) ),
			self::field( 'booking_paid_amount', 'Оплачено деньгами', 'number', array( 'readonly' => 1, 'disabled' => 1, 'step' => 0.01, 'wrapper' => array( 'width' => 50 ) ) ),
			self::field( 'booking_debt_amount', 'Задолженность', 'number', array( 'readonly' => 1, 'disabled' => 1, 'step' => 0.01, 'wrapper' => array( 'width' => 50 ) ) ),
			self::field( 'booking_tab_notes', 'Заметки', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'booking_trainer_notes', 'Заметка тренера', 'textarea', array( 'rows' => 3 ) ),
			self::field( 'booking_admin_notes', 'Комментарий администратора', 'textarea', array( 'rows' => 3 ) ),
		);
	}

	private static function membership_fields() {
		return array(
			self::field( 'membership_tab_owner', 'Участник и плательщик', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'membership_client', 'Всадник', 'post_object', array( 'post_type' => array( 'clients' ), 'required' => 1, 'ui' => 1, 'return_format' => 'id' ) ),
			self::field( 'membership_payer', 'Плательщик', 'post_object', array( 'post_type' => array( 'clients' ), 'allow_null' => 1, 'ui' => 1, 'return_format' => 'id', 'instructions' => 'Оставьте пустым, чтобы использовать основного плательщика всадника.' ) ),
			self::field( 'membership_plan', 'Тариф / пакет', 'post_object', array( 'post_type' => array( 'pricing_plans' ), 'allow_null' => 1, 'ui' => 1, 'return_format' => 'id', 'instructions' => 'Для нового абонемента условия тарифа будут скопированы и сохранены снимком.' ) ),
			self::field( 'membership_services', 'Допустимые услуги', 'relationship', array( 'post_type' => array( 'services' ), 'filters' => array( 'search' ), 'return_format' => 'id', 'instructions' => 'Оставьте пустым, если абонемент действует на все услуги, разрешающие оплату абонементом.' ) ),
			self::field( 'membership_tab_terms', 'Срок и объём', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'membership_purchase_date', 'Дата покупки', 'date_picker', array( 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d' ) ),
			self::field( 'membership_start_date', 'Дата начала', 'date_picker', array( 'required' => 1, 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d', 'wrapper' => array( 'width' => 50 ) ) ),
			self::field( 'membership_end_date', 'Дата окончания', 'date_picker', array( 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d', 'wrapper' => array( 'width' => 50 ), 'instructions' => 'Для тарифа рассчитывается автоматически; без тарифа заполните вручную.' ) ),
			self::field( 'membership_lesson_limit', 'Количество занятий', 'number', array( 'min' => 1, 'step' => 1, 'instructions' => 'Для тарифа копируется автоматически; без тарифа заполните вручную.' ) ),
			self::field( 'membership_price', 'Стоимость абонемента', 'number', array( 'min' => 0, 'step' => 0.01 ) ),
			self::field( 'membership_status', 'Статус', 'select', array( 'choices' => array( 'draft' => 'Черновик', 'active' => 'Активен', 'frozen' => 'Заморожен', 'exhausted' => 'Исчерпан', 'expired' => 'Истёк', 'cancelled' => 'Отменён' ), 'default_value' => 'draft', 'required' => 1 ) ),
			self::field( 'membership_payment_status', 'Состояние оплаты', 'select', array( 'choices' => array( 'unpaid' => 'Не оплачено', 'partial' => 'Частично', 'paid' => 'Оплачено', 'refund' => 'Есть возврат' ), 'default_value' => 'unpaid', 'disabled' => 1 ) ),
			self::field( 'membership_paid_amount', 'Оплачено', 'number', array( 'readonly' => 1, 'disabled' => 1, 'step' => 0.01, 'wrapper' => array( 'width' => 50 ) ) ),
			self::field( 'membership_debt_amount', 'Задолженность', 'number', array( 'readonly' => 1, 'disabled' => 1, 'step' => 0.01, 'wrapper' => array( 'width' => 50 ) ) ),
			self::field( 'membership_plan_name_snapshot', 'Название тарифа при покупке', 'text', array( 'readonly' => 1, 'disabled' => 1 ) ),
			self::field( 'membership_plan_version_snapshot', 'Версия тарифа при покупке', 'text', array( 'readonly' => 1, 'disabled' => 1 ) ),
			self::field( 'membership_plan_validity_snapshot', 'Срок тарифа при покупке, дней', 'number', array( 'readonly' => 1, 'disabled' => 1 ) ),
			self::field( 'membership_cancellation_hours_snapshot', 'Бесплатная отмена при покупке, часов', 'number', array( 'readonly' => 1, 'disabled' => 1 ) ),
			self::field( 'membership_rules_snapshot', 'Правила тарифа при покупке', 'textarea', array( 'readonly' => 1, 'disabled' => 1, 'rows' => 3 ) ),
			self::field( 'membership_tab_freeze', 'Заморозка', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'membership_freeze_allowed', 'Заморозка разрешена', 'true_false', array( 'ui' => 1, 'ui_on_text' => 'Да', 'ui_off_text' => 'Нет' ) ),
			self::field( 'membership_freeze_days_limit', 'Лимит заморозки, дней', 'number', array( 'min' => 0, 'step' => 1 ) ),
			self::field( 'membership_frozen_from', 'Заморожен с', 'date_picker', array( 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d', 'wrapper' => array( 'width' => 50 ) ) ),
			self::field( 'membership_frozen_to', 'Заморожен до', 'date_picker', array( 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d', 'wrapper' => array( 'width' => 50 ) ) ),
			self::field( 'membership_tab_balance', 'Рассчитанный остаток', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'membership_credited', 'Начислено занятий', 'number', array( 'readonly' => 1, 'disabled' => 1 ) ),
			self::field( 'membership_debited', 'Списано занятий', 'number', array( 'readonly' => 1, 'disabled' => 1 ) ),
			self::field( 'membership_balance', 'Остаток занятий', 'number', array( 'readonly' => 1, 'disabled' => 1, 'instructions' => 'Рассчитывается автоматически из журнала операций.' ) ),
			self::field( 'membership_tab_notes', 'Заметки', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'membership_notes', 'Комментарий', 'textarea', array( 'rows' => 4 ) ),
		);
	}

	private static function pricing_plan_fields() {
		return array(
			self::field( 'pricing_plan_tab_terms', 'Основные условия', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'pricing_plan_status', 'Статус', 'select', array( 'choices' => array( 'draft' => 'Черновик', 'active' => 'Активен', 'archived' => 'Архив' ), 'default_value' => 'active', 'required' => 1 ) ),
			self::field( 'pricing_plan_version', 'Версия условий', 'text', array( 'default_value' => '1', 'required' => 1, 'instructions' => 'Измените версию, если условия тарифа существенно обновились.' ) ),
			self::field( 'pricing_plan_lesson_count', 'Количество занятий', 'number', array( 'required' => 1, 'min' => 1, 'step' => 1, 'wrapper' => array( 'width' => 33 ) ) ),
			self::field( 'pricing_plan_validity_days', 'Срок действия, дней', 'number', array( 'required' => 1, 'min' => 1, 'step' => 1, 'wrapper' => array( 'width' => 33 ) ) ),
			self::field( 'pricing_plan_price', 'Стоимость', 'number', array( 'required' => 1, 'min' => 0, 'step' => 0.01, 'wrapper' => array( 'width' => 34 ) ) ),
			self::field( 'pricing_plan_services', 'Допустимые услуги', 'relationship', array( 'post_type' => array( 'services' ), 'filters' => array( 'search' ), 'return_format' => 'id', 'instructions' => 'Оставьте пустым, если пакет действует на все услуги, допускающие абонемент.' ) ),
			self::field( 'pricing_plan_tab_rules', 'Правила', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'pricing_plan_freeze_allowed', 'Заморозка разрешена', 'true_false', array( 'ui' => 1, 'ui_on_text' => 'Да', 'ui_off_text' => 'Нет' ) ),
			self::field( 'pricing_plan_freeze_days', 'Лимит заморозки, дней', 'number', array( 'min' => 0, 'step' => 1 ) ),
			self::field( 'pricing_plan_cancellation_hours', 'Бесплатная отмена не позднее, часов', 'number', array( 'min' => 0, 'step' => 1 ) ),
			self::field( 'pricing_plan_rules', 'Условия использования', 'textarea', array( 'rows' => 4 ) ),
			self::field( 'pricing_plan_admin_notes', 'Внутренние заметки', 'textarea', array( 'rows' => 3 ) ),
		);
	}

	private static function membership_operation_fields() {
		return array(
			self::field( 'membership_op_membership', 'Абонемент', 'post_object', array( 'post_type' => array( 'memberships' ), 'required' => 1, 'ui' => 1, 'return_format' => 'id' ) ),
			self::field( 'membership_op_type', 'Тип операции', 'select', array( 'choices' => array( 'credit' => 'Начисление', 'debit' => 'Списание', 'refund' => 'Возврат занятия', 'adjustment' => 'Корректировка' ), 'required' => 1 ) ),
			self::field( 'membership_op_amount', 'Количество занятий', 'number', array( 'required' => 1, 'step' => 1, 'instructions' => 'Для начисления, списания и возврата укажите положительное число. Для корректировки можно использовать отрицательное.' ) ),
			self::field( 'membership_op_date', 'Дата операции', 'date_picker', array( 'required' => 1, 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d' ) ),
			self::field( 'membership_op_lesson', 'Занятие', 'post_object', array( 'post_type' => array( 'lessons' ), 'allow_null' => 1, 'ui' => 1, 'return_format' => 'id', 'instructions' => 'Для списания или возврата желательно указать связанное занятие.' ) ),
			self::field( 'membership_op_author', 'Автор операции', 'user', array( 'allow_null' => 1, 'return_format' => 'id' ) ),
			self::field( 'membership_op_reason', 'Основание / комментарий', 'textarea', array( 'rows' => 3 ) ),
		);
	}

	private static function payment_fields() {
		return array(
			self::field( 'payment_tab_main', 'Платёж', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'payment_payer', 'Плательщик', 'post_object', array( 'post_type' => array( 'clients' ), 'allow_null' => 1, 'ui' => 1, 'return_format' => 'id', 'instructions' => 'Если не указан, будет подставлен плательщик из абонемента или записи.' ) ),
			self::field( 'payment_date', 'Дата платежа', 'date_picker', array( 'required' => 1, 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d', 'wrapper' => array( 'width' => 34 ) ) ),
			self::field( 'payment_amount', 'Сумма', 'number', array( 'required' => 1, 'min' => 0.01, 'step' => 0.01, 'wrapper' => array( 'width' => 33 ), 'instructions' => 'Для оплаты и возврата укажите положительную сумму.' ) ),
			self::field( 'payment_method', 'Способ оплаты', 'select', array( 'choices' => array( 'cash' => 'Наличные', 'card' => 'Карта', 'transfer' => 'Перевод', 'online' => 'Онлайн', 'other' => 'Другое' ), 'required' => 1, 'default_value' => 'cash', 'wrapper' => array( 'width' => 33 ) ) ),
			self::field( 'payment_status', 'Статус', 'select', array( 'choices' => array( 'pending' => 'Ожидает', 'paid' => 'Оплачен', 'refund' => 'Возврат', 'cancelled' => 'Отменён' ), 'required' => 1, 'default_value' => 'paid' ) ),
			self::field( 'payment_tab_purpose', 'Назначение', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'payment_purpose_type', 'Тип назначения', 'select', array( 'choices' => array( 'membership' => 'Абонемент', 'booking' => 'Запись на занятие', 'other' => 'Другое' ), 'required' => 1, 'default_value' => 'membership' ) ),
			self::field( 'payment_membership', 'Абонемент', 'post_object', array( 'post_type' => array( 'memberships' ), 'allow_null' => 1, 'ui' => 1, 'return_format' => 'id' ) ),
			self::field( 'payment_booking', 'Запись на занятие', 'post_object', array( 'post_type' => array( 'bookings' ), 'allow_null' => 1, 'ui' => 1, 'return_format' => 'id' ) ),
			self::field( 'payment_purpose', 'Назначение платежа', 'text', array( 'instructions' => 'Обязательно для назначения «Другое».' ) ),
			self::field( 'payment_tab_details', 'Подробности', 'tab', array( 'placement' => 'top' ) ),
			self::field( 'payment_reference', 'Номер чека / операции', 'text' ),
			self::field( 'payment_author', 'Принял платёж', 'user', array( 'allow_null' => 1, 'return_format' => 'id' ) ),
			self::field( 'payment_comment', 'Комментарий', 'textarea', array( 'rows' => 3 ) ),
		);
	}

	public static function prepare_client( $post_id ) {
		if ( ! is_numeric( $post_id ) || 'clients' !== get_post_type( $post_id ) ) {
			return;
		}

		if ( ! get_field( 'client_registration_date', $post_id ) ) {
			update_field( 'field_hcos_client_registration_date', current_time( 'Ymd' ), $post_id );
		}

		$name_parts = array_filter(
			array(
				trim( (string) get_field( 'client_last_name', $post_id ) ),
				trim( (string) get_field( 'client_first_name', $post_id ) ),
				trim( (string) get_field( 'client_middle_name', $post_id ) ),
			)
		);

		if ( empty( $name_parts ) ) {
			return;
		}

		$new_title = implode( ' ', $name_parts );
		if ( get_the_title( $post_id ) === $new_title ) {
			return;
		}

		remove_action( 'acf/save_post', array( __CLASS__, 'prepare_client' ), 20 );
		wp_update_post( array( 'ID' => (int) $post_id, 'post_title' => $new_title ) );
		add_action( 'acf/save_post', array( __CLASS__, 'prepare_client' ), 20 );
	}

	public static function prepare_lesson( $post_id ) {
		if ( ! is_numeric( $post_id ) || 'lessons' !== get_post_type( $post_id ) ) {
			return;
		}

		$service_id = (int) get_field( 'lesson_service', $post_id );
		$duration   = (int) get_field( 'lesson_duration', $post_id );
		if ( $duration <= 0 && $service_id ) {
			$duration = (int) get_field( 'service_duration', $service_id );
			if ( $duration > 0 ) {
				update_field( 'field_hcos_lesson_duration', $duration, $post_id );
			}
		}

		$price = get_post_meta( $post_id, 'lesson_price', true );
		if ( '' === $price && $service_id ) {
			$service_price = get_post_meta( $service_id, 'service_price', true );
			if ( '' !== $service_price ) {
				update_field( 'field_hcos_lesson_price', $service_price, $post_id );
			}
		}

		$capacity = absint( get_post_meta( $post_id, 'lesson_capacity', true ) );
		if ( ! $capacity && $service_id ) {
			$capacity = max( 1, absint( get_post_meta( $service_id, 'service_capacity', true ) ) );
			update_field( 'field_hcos_lesson_capacity', $capacity, $post_id );
		}

		$date = (string) get_field( 'lesson_date', $post_id );
		$time = (string) get_field( 'lesson_time', $post_id );
		if ( $date && $time && $duration > 0 ) {
			$timestamp = strtotime( $date . ' ' . $time );
			if ( false !== $timestamp ) {
				update_field( 'field_hcos_lesson_end_time', gmdate( 'H:i:s', $timestamp + ( $duration * MINUTE_IN_SECONDS ) ), $post_id );
			}
		}

		$client_id = (int) get_post_meta( $post_id, 'lesson_client', true );
		$subject   = $service_id ? get_the_title( $service_id ) : ( $client_id ? get_the_title( $client_id ) : '' );
		$title     = trim( implode( ' — ', array_filter( array( $date, substr( $time, 0, 5 ), $subject ) ) ) );
		if ( '' === $title || get_the_title( $post_id ) === $title ) {
			return;
		}

		remove_action( 'acf/save_post', array( __CLASS__, 'prepare_lesson' ), 20 );
		wp_update_post( array( 'ID' => (int) $post_id, 'post_title' => $title ) );
		add_action( 'acf/save_post', array( __CLASS__, 'prepare_lesson' ), 20 );
	}

	private static function field( $name, $label, $type, $extra = array() ) {
		return array_merge(
			array(
				'key'   => 'field_hcos_' . $name,
				'label' => $label,
				'name'  => $name,
				'type'  => $type,
			),
			$extra
		);
	}

	private static function location( $post_type ) {
		return array(
			array(
				array(
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => $post_type,
				),
			),
		);
	}
}
