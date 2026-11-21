CREATE TABLE IF NOT EXISTS `event_log_locks` (
    `lock_id` TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (`lock_id`)
) ENGINE=InnoDB;
