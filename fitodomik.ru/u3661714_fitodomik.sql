-- Схема базы данных. Записи из рабочих таблиц исключены.

CREATE TABLE `account_client_grants` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `origin_device_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `alarm_thresholds` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parameter_type` enum('temperature','humidity_air','humidity_soil','co2') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `min_limit` decimal(8,2) NOT NULL,
  `max_limit` decimal(8,2) NOT NULL,
  `target_value` decimal(8,2) DEFAULT NULL COMMENT 'Целевое значение (если необходимо)',
  `tolerance` decimal(5,2) DEFAULT '1.00' COMMENT 'Допустимое отклонение',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `api_rate_limits` (
  `bucket_key` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `window_started_at` datetime NOT NULL,
  `attempts` int NOT NULL DEFAULT '0',
  `blocked_until` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `author_links` (
  `id` int UNSIGNED NOT NULL,
  `link_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_public` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `author_milestones` (
  `id` int UNSIGNED NOT NULL,
  `milestone_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_text` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_public` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `author_profile` (
  `profile_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `public_handle` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `tagline` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `intro` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `biography` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `location_text` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status_text` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `avatar_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `is_public` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `author_projects` (
  `id` int UNSIGNED NOT NULL,
  `slug` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `stack_text` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status_text` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `live_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `repo_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_public` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `author_skills` (
  `id` int UNSIGNED NOT NULL,
  `skill_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_public` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `client_device_credentials` (
  `grant_id` bigint UNSIGNED NOT NULL,
  `device_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `code` (
  `id` int NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `language` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'text',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `code_links` (
  `id` int NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Название ссылки для отображения',
  `url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'URL ссылки',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Описание ссылки (опционально)',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Активна ли ссылка',
  `sort_order` int DEFAULT '0' COMMENT 'Порядок сортировки',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `components` (
  `id` int NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `price` decimal(10,2) DEFAULT NULL,
  `price_type` enum('bought','custom','undefined') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'undefined',
  `status` enum('bought','not_bought') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'not_bought',
  `info` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `device_alert_rules` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rule_id` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `rule_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `device_automation_scenarios` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scenario_id` varchar(96) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `conditions_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actions_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cooldown_seconds` int NOT NULL DEFAULT '0',
  `last_triggered_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `device_automation_scenario_log` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scenario_id` varchar(96) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `device_commands` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `command_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `command_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','running','done','error','cancelled','expired') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `source` enum('web','site','telegram','automation','desktop','system') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'site',
  `requested_by_user_id` int DEFAULT NULL,
  `requested_by_telegram_id` bigint DEFAULT NULL,
  `created_by_user_id` int DEFAULT NULL,
  `telegram_user_id` bigint DEFAULT NULL,
  `scenario_id` varchar(96) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `available_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` datetime DEFAULT NULL,
  `picked_at` datetime DEFAULT NULL,
  `delivery_client` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lease_until` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `duration_ms` int DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `result_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `result_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `attempts` int NOT NULL DEFAULT '0',
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by_user_id` int DEFAULT NULL,
  `result_notified_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `device_fault_reports` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_key` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fault_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `photo_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notified_at` timestamp NULL DEFAULT NULL,
  `photo_notified_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `device_notification_settings` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `telegram_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `notify_soil_moisture` tinyint(1) NOT NULL DEFAULT '1',
  `notify_temperature` tinyint(1) NOT NULL DEFAULT '1',
  `notify_camera` tinyint(1) NOT NULL DEFAULT '1',
  `send_photos_telegram` tinyint(1) NOT NULL DEFAULT '0',
  `send_daily_report` tinyint(1) NOT NULL DEFAULT '1',
  `daily_report_time` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '20:00',
  `alert_check_interval_minutes` int NOT NULL DEFAULT '60',
  `alert_repeat_cooldown_minutes` int NOT NULL DEFAULT '180',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `device_pair_requests` (
  `id` bigint NOT NULL,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_secret_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `status` enum('pending','approved','expired','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_delivered_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `device_relay_definitions` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `relay_uid` varchar(96) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pin` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `relay_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'relay',
  `active_low` tinyint(1) NOT NULL DEFAULT '1',
  `config_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `show_in_control` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `device_sensor_definitions` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sensor_uid` varchar(96) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sensor_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `pin` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `address` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `unit` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `calibration_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `config_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `send_to_server` tinyint(1) NOT NULL DEFAULT '1',
  `show_in_monitoring` tinyint(1) NOT NULL DEFAULT '1',
  `show_in_charts` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `device_sensor_profiles` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `profile_id` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sensor_type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `profile_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'private',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `device_sensor_values` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sensor_uid` varchar(96) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` decimal(14,4) DEFAULT NULL,
  `raw_value` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `measured_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `device_states` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `lamp_state` tinyint(1) DEFAULT '0',
  `curtains_state` tinyint(1) DEFAULT '0',
  `last_updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `event_log` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `description` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `farm_photo_storage` (
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int NOT NULL,
  `size_bytes` bigint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `farm_status` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `temperature` float DEFAULT NULL,
  `humidity` float DEFAULT NULL,
  `light_level` float DEFAULT NULL,
  `curtains_level` decimal(4,2) DEFAULT NULL,
  `co2_level` float DEFAULT NULL,
  `photo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_analysis` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `favorite_modes` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `preset_mode_id` int NOT NULL,
  `added_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `feedback` (
  `id` int NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attachment` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('new','in_progress','completed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'new',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `feedback_cooldown` (
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_message_time` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `feedback_messages` (
  `id` int NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_answered` tinyint(1) NOT NULL DEFAULT '0',
  `is_faq` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `feedback_notification_outbox` (
  `id` bigint UNSIGNED NOT NULL,
  `message_id` int NOT NULL,
  `attempts` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `available_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `delivered_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `feedback_replies` (
  `id` int NOT NULL,
  `message_id` int NOT NULL,
  `reply_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fitodomik_migrations` (
  `migration_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fitodomik_schema_meta` (
  `meta_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta_value` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lighting_schedule` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `required_hours` decimal(4,2) DEFAULT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_exception` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `messages` (
  `id` int NOT NULL,
  `text` text NOT NULL,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `analysis_image_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `photos` (
  `id` int NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `seo_title` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seo_alt` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seo_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `seo_keywords` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `slug` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_width` int DEFAULT NULL,
  `image_height` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_indexable` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `planting_events` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `type` varchar(20) NOT NULL,
  `plant_name` varchar(100) NOT NULL,
  `event_date` date NOT NULL,
  `event_time` time DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `planting_reminders` (
  `id` int NOT NULL,
  `event_id` int NOT NULL,
  `reminder_date` date NOT NULL,
  `reminder_time` time DEFAULT NULL,
  `is_shown` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `plant_catalog` (
  `id` int UNSIGNED NOT NULL,
  `slug` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_ru` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_latin` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` smallint UNSIGNED NOT NULL,
  `keywords` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `short_description` varchar(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `difficulty` enum('easy','medium','hard') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `germination_days_min` smallint UNSIGNED DEFAULT NULL,
  `germination_days_max` smallint UNSIGNED DEFAULT NULL,
  `harvest_days_min` smallint UNSIGNED DEFAULT NULL,
  `harvest_days_max` smallint UNSIGNED DEFAULT NULL,
  `temperature_min` decimal(4,1) NOT NULL,
  `temperature_max` decimal(4,1) NOT NULL,
  `air_humidity_min` tinyint UNSIGNED NOT NULL,
  `air_humidity_max` tinyint UNSIGNED NOT NULL,
  `soil_moisture_min` tinyint UNSIGNED NOT NULL,
  `soil_moisture_max` tinyint UNSIGNED NOT NULL,
  `light_hours_min` decimal(3,1) NOT NULL,
  `light_hours_max` decimal(3,1) NOT NULL,
  `light_intensity` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `watering_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `co2_notes` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ventilation_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `dormancy_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `container_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `interesting_fact` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `growth_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `harvest_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `taste_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `vitamins` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `challenges` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `underwater_signs` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `overwater_signs` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `care_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `warnings` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `suitable_indoor` tinyint(1) NOT NULL DEFAULT '1',
  `suitable_fitodomik` tinyint(1) NOT NULL DEFAULT '1',
  `is_edible` tinyint(1) NOT NULL DEFAULT '0',
  `is_decorative` tinyint(1) NOT NULL DEFAULT '0',
  `is_fast_growing` tinyint(1) NOT NULL DEFAULT '0',
  `is_featured` tinyint(1) NOT NULL DEFAULT '0',
  `popularity` smallint UNSIGNED NOT NULL DEFAULT '0',
  `image_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_source_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_author` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `image_license` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source_urls` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` smallint NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `plant_categories` (
  `id` smallint UNSIGNED NOT NULL,
  `slug` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sort_order` smallint NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `posts` (
  `id` int NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `preset_modes` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `temperature` decimal(4,1) NOT NULL,
  `tolerance` decimal(2,1) NOT NULL DEFAULT '1.0',
  `humidity` int NOT NULL,
  `humidity_tolerance` decimal(2,1) NOT NULL DEFAULT '1.0',
  `light_hours` decimal(3,1) NOT NULL,
  `light_start` time NOT NULL,
  `light_end` time NOT NULL,
  `is_shared` tinyint(1) NOT NULL DEFAULT '0',
  `share_code` varchar(10) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `lamp_hours_json` longtext,
  `curtain_schedule` longtext,
  `source_plant_id` int UNSIGNED DEFAULT NULL,
  `source_device_id` varchar(64) DEFAULT NULL,
  `soil_moisture` tinyint UNSIGNED DEFAULT NULL,
  `soil_moisture_tolerance` decimal(4,1) DEFAULT NULL,
  `description` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `schedule` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) DEFAULT NULL,
  `curtains_schedule` tinyint(1) DEFAULT '0',
  `lighting_schedule` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `time` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `sensor_data` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `temperature` decimal(5,2) DEFAULT NULL,
  `humidity` decimal(5,2) DEFAULT NULL,
  `co2` int NOT NULL DEFAULT '400',
  `soil_moisture` decimal(5,2) DEFAULT NULL,
  `light_level` decimal(8,2) DEFAULT NULL,
  `pressure` decimal(7,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `curtains_state` tinyint(1) DEFAULT '0',
  `lamp_state` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
  `id` int NOT NULL,
  `cooldown_time` int NOT NULL DEFAULT '600',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_alert_state` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `alert_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `active_since` datetime DEFAULT NULL,
  `last_sent_at` datetime DEFAULT NULL,
  `last_alert_value` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_bot_state` (
  `state_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `state_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_callback_actions` (
  `id` bigint NOT NULL,
  `token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int NOT NULL,
  `telegram_user_id` bigint NOT NULL,
  `device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','used','cancelled','expired') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `telegram_link_id` bigint DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_links` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `telegram_user_id` bigint NOT NULL,
  `telegram_chat_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `telegram_username` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telegram_first_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telegram_last_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','revoked') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `linked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` datetime DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_link_codes` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `deep_token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','used','expired','revoked') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `telegram_user_id` bigint DEFAULT NULL,
  `telegram_chat_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_login_tokens` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `telegram_chat_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `telegram_link_id` bigint DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_sessions` (
  `id` bigint NOT NULL,
  `telegram_user_id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `selected_device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_seen_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_settings` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alerts_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `daily_report_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `offline_alert_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `arduino_alert_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `camera_alert_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `analysis_alert_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `photo_alert_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `system_alert_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `report_time` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '20:00',
  `quiet_hours_start` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quiet_hours_end` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `offline_after_minutes` int NOT NULL DEFAULT '120',
  `alert_cooldown_minutes` int NOT NULL DEFAULT '180',
  `last_daily_report_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_verifications` (
  `id` bigint NOT NULL,
  `username` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `telegram_chat_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `telegram_user_id` bigint DEFAULT NULL,
  `telegram_username` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telegram_first_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registration_code_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `verified_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `timeline` (
  `id` int NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `avatar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_admin` tinyint(1) DEFAULT '0',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  `login_attempts` int DEFAULT '0',
  `is_locked` tinyint(1) DEFAULT '0',
  `reset_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reset_token_expires` timestamp NULL DEFAULT NULL,
  `telegram_username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telegram_last_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telegram_chat_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telegram_user_id` bigint DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT '0',
  `api_token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auth_generation` bigint UNSIGNED NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_devices` (
  `id` bigint NOT NULL,
  `device_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_token` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` int NOT NULL,
  `device_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('active','revoked') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `linked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_remember_tokens` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `selector` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `user_agent_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telegram_link_id` bigint DEFAULT NULL,
  `auth_generation` bigint UNSIGNED NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_telegram_settings` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `telegram_chat_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telegram_user_id` bigint DEFAULT NULL,
  `telegram_username` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telegram_last_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `account_client_grants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `idx_grant_origin` (`user_id`,`origin_device_id`,`revoked_at`);

ALTER TABLE `alarm_thresholds`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_alarm_device_parameter` (`user_id`,`device_id`,`parameter_type`);

ALTER TABLE `api_rate_limits`
  ADD PRIMARY KEY (`bucket_key`),
  ADD KEY `idx_api_rate_limits_updated` (`updated_at`);

ALTER TABLE `author_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_author_links_key` (`link_key`),
  ADD KEY `idx_author_links_public_sort` (`is_public`,`sort_order`);

ALTER TABLE `author_milestones`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_author_milestones_key` (`milestone_key`),
  ADD KEY `idx_author_milestones_public_sort` (`is_public`,`sort_order`);

ALTER TABLE `author_profile`
  ADD PRIMARY KEY (`profile_key`),
  ADD KEY `idx_author_profile_public` (`is_public`);

ALTER TABLE `author_projects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_author_projects_slug` (`slug`),
  ADD KEY `idx_author_projects_public_sort` (`is_public`,`sort_order`);

ALTER TABLE `author_skills`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_author_skills_key` (`skill_key`),
  ADD KEY `idx_author_skills_public_sort` (`is_public`,`sort_order`);

ALTER TABLE `client_device_credentials`
  ADD PRIMARY KEY (`grant_id`,`device_id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`);

ALTER TABLE `code`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `code_links`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `components`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `device_alert_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_device_alert_rule` (`user_id`,`device_id`,`rule_id`),
  ADD KEY `idx_device_alert_rules_device` (`device_id`);

ALTER TABLE `device_automation_scenarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_device_scenario` (`user_id`,`device_id`,`scenario_id`),
  ADD KEY `idx_device_scenario_device` (`user_id`,`device_id`,`enabled`),
  ADD KEY `idx_device_scenario_plain_device` (`user_id`,`device_id`);

ALTER TABLE `device_automation_scenario_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_device_scenario_log` (`user_id`,`device_id`,`scenario_id`,`created_at`),
  ADD KEY `idx_device_scenario_log_device` (`user_id`,`device_id`);

ALTER TABLE `device_commands`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_device_commands_device_status` (`user_id`,`device_id`,`status`),
  ADD KEY `idx_device_commands_created` (`created_at`),
  ADD KEY `idx_device_commands_expires` (`expires_at`),
  ADD KEY `idx_device_commands_telegram_notify` (`source`,`requested_by_telegram_id`,`status`,`result_notified_at`),
  ADD KEY `idx_device_commands_source_created` (`user_id`,`device_id`,`source`,`created_at`),
  ADD KEY `idx_command_lease` (`user_id`,`device_id`,`status`,`lease_until`);

ALTER TABLE `device_fault_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fault_source` (`user_id`,`device_id`,`event_key`),
  ADD KEY `ix_fault_delivery` (`notified_at`,`created_at`),
  ADD KEY `ix_fault_device` (`user_id`,`device_id`,`created_at`);

ALTER TABLE `device_notification_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_device_notifications` (`user_id`,`device_id`),
  ADD KEY `idx_device_notifications_device` (`device_id`);

ALTER TABLE `device_pair_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_device_pair_status` (`status`),
  ADD KEY `idx_device_pair_user` (`user_id`);

ALTER TABLE `device_relay_definitions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_device_relay` (`user_id`,`device_id`,`relay_uid`),
  ADD KEY `idx_device_relay_device` (`user_id`,`device_id`,`enabled`),
  ADD KEY `idx_device_relay_plain_device` (`user_id`,`device_id`);

ALTER TABLE `device_sensor_definitions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_device_sensor` (`user_id`,`device_id`,`sensor_uid`),
  ADD KEY `idx_device_sensor_device` (`user_id`,`device_id`,`enabled`),
  ADD KEY `idx_device_sensor_plain_device` (`user_id`,`device_id`);

ALTER TABLE `device_sensor_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_profile` (`user_id`,`profile_id`),
  ADD KEY `idx_sensor_profiles_user` (`user_id`,`updated_at`),
  ADD KEY `idx_sensor_profiles_public` (`is_public`,`status`,`updated_at`),
  ADD KEY `idx_sensor_profiles_type` (`sensor_type`,`connection_type`);

ALTER TABLE `device_sensor_values`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_device_sensor_values` (`user_id`,`device_id`,`sensor_uid`,`measured_at`),
  ADD KEY `idx_device_sensor_values_device` (`user_id`,`device_id`),
  ADD KEY `idx_device_sensor_values_measured` (`user_id`,`device_id`,`measured_at`),
  ADD KEY `idx_device_sensor_values_created` (`created_at`);

ALTER TABLE `device_states`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `event_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `event_type` (`event_type`),
  ADD KEY `idx_event_user_device_created` (`user_id`,`device_id`,`created_at`);

ALTER TABLE `farm_photo_storage`
  ADD PRIMARY KEY (`filename`),
  ADD KEY `idx_photo_storage_owner` (`user_id`,`size_bytes`);

ALTER TABLE `farm_status`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_farm_status_user_device_created` (`user_id`,`device_id`,`created_at`);

ALTER TABLE `favorite_modes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_preset_unique` (`user_id`,`preset_mode_id`),
  ADD KEY `preset_mode_id` (`preset_mode_id`);

ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `feedback_cooldown`
  ADD PRIMARY KEY (`ip_address`);

ALTER TABLE `feedback_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_feedback_faq` (`is_faq`,`created_at`);

ALTER TABLE `feedback_notification_outbox`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `message_id` (`message_id`),
  ADD KEY `idx_feedback_outbox_ready` (`delivered_at`,`available_at`);

ALTER TABLE `feedback_replies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_feedback_replies_message` (`message_id`);

ALTER TABLE `fitodomik_migrations`
  ADD PRIMARY KEY (`migration_id`);

ALTER TABLE `fitodomik_schema_meta`
  ADD PRIMARY KEY (`meta_key`);

ALTER TABLE `lighting_schedule`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `photos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_photos_filename` (`filename`),
  ADD KEY `idx_photos_slug` (`slug`),
  ADD KEY `idx_photos_indexable` (`is_indexable`);

ALTER TABLE `planting_events`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `planting_reminders`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `plant_catalog`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_plant_catalog_slug` (`slug`),
  ADD KEY `idx_plant_catalog_listing` (`is_active`,`category_id`,`difficulty`,`sort_order`),
  ADD KEY `idx_plant_catalog_name_ru` (`name_ru`),
  ADD KEY `idx_plant_catalog_name_latin` (`name_latin`),
  ADD KEY `idx_plant_catalog_featured` (`is_active`,`is_featured`,`popularity`),
  ADD KEY `fk_plant_catalog_category` (`category_id`);

ALTER TABLE `plant_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_plant_categories_slug` (`slug`),
  ADD KEY `idx_plant_categories_active_sort` (`is_active`,`sort_order`,`name`);

ALTER TABLE `posts`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `preset_modes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_preset_modes_source_plant` (`user_id`,`source_plant_id`),
  ADD KEY `idx_preset_modes_source_device` (`user_id`,`source_device_id`);

ALTER TABLE `schedule`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_schedule_device_time` (`user_id`,`device_id`,`time`),
  ADD KEY `user_id` (`user_id`,`time`),
  ADD KEY `idx_schedule_user_time` (`user_id`,`time`);

ALTER TABLE `sensor_data`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_sensor_user_device_created` (`user_id`,`device_id`,`created_at`);

ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `telegram_alert_state`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_tg_alert_state` (`user_id`,`device_id`,`alert_key`),
  ADD KEY `idx_tg_alert_state_user` (`user_id`);

ALTER TABLE `telegram_bot_state`
  ADD PRIMARY KEY (`state_key`);

ALTER TABLE `telegram_callback_actions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `idx_tg_callback_user` (`user_id`,`telegram_user_id`),
  ADD KEY `idx_tg_callback_status_expires` (`status`,`expires_at`);

ALTER TABLE `telegram_links`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_telegram_links_telegram_user` (`telegram_user_id`),
  ADD KEY `idx_telegram_links_user` (`user_id`),
  ADD KEY `idx_telegram_links_status` (`status`);

ALTER TABLE `telegram_link_codes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD UNIQUE KEY `uniq_tg_link_deep_token` (`deep_token_hash`),
  ADD KEY `idx_tg_link_codes_user` (`user_id`),
  ADD KEY `idx_tg_link_codes_status` (`status`);

ALTER TABLE `telegram_login_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `idx_telegram_login_user` (`user_id`),
  ADD KEY `idx_telegram_login_expires` (`expires_at`);

ALTER TABLE `telegram_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `telegram_user_id` (`telegram_user_id`),
  ADD KEY `idx_tg_sessions_user` (`user_id`);

ALTER TABLE `telegram_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_tg_settings_device` (`user_id`,`device_id`),
  ADD KEY `idx_tg_settings_user` (`user_id`);

ALTER TABLE `telegram_verifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_telegram_verifications_username` (`username`),
  ADD KEY `idx_telegram_verifications_chat` (`telegram_chat_id`),
  ADD KEY `idx_telegram_verifications_user` (`user_id`);

ALTER TABLE `timeline`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username_idx` (`username`),
  ADD UNIQUE KEY `api_token_unique_idx` (`api_token`);

ALTER TABLE `user_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `device_id` (`device_id`),
  ADD UNIQUE KEY `idx_user_devices_token` (`device_token`),
  ADD KEY `idx_user_devices_user` (`user_id`),
  ADD KEY `idx_user_devices_status` (`status`);

ALTER TABLE `user_remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `selector` (`selector`),
  ADD KEY `idx_remember_user` (`user_id`),
  ADD KEY `idx_remember_expires` (`expires_at`);

ALTER TABLE `user_telegram_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `idx_user_telegram_id` (`telegram_user_id`);

ALTER TABLE `account_client_grants`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `alarm_thresholds`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `author_links`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `author_milestones`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `author_projects`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `author_skills`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

ALTER TABLE `code`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `code_links`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `components`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `device_alert_rules`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `device_automation_scenarios`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `device_automation_scenario_log`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `device_commands`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `device_fault_reports`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `device_notification_settings`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `device_pair_requests`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `device_relay_definitions`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `device_sensor_definitions`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `device_sensor_profiles`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `device_sensor_values`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `event_log`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `farm_status`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `feedback`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `feedback_messages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `feedback_notification_outbox`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `feedback_replies`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `lighting_schedule`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `photos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

ALTER TABLE `planting_events`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `planting_reminders`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `plant_catalog`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=151;

ALTER TABLE `plant_categories`
  MODIFY `id` smallint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

ALTER TABLE `posts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=76;

ALTER TABLE `preset_modes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `schedule`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `sensor_data`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `telegram_alert_state`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `telegram_callback_actions`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `telegram_links`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `telegram_link_codes`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `telegram_login_tokens`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `telegram_sessions`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `telegram_settings`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `telegram_verifications`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `timeline`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `user_devices`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `user_remember_tokens`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `user_telegram_settings`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `plant_catalog`
  ADD CONSTRAINT `fk_plant_catalog_category` FOREIGN KEY (`category_id`) REFERENCES `plant_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
