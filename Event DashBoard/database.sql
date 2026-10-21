-- =========================================================
-- SOCIAL EVENTS PLATFORM — COMPLETE DATABASE
-- Import: mysql -u root -p < database.sql
-- Or paste into phpMyAdmin → SQL tab
-- =========================================================

-- 1. Create & select database
CREATE DATABASE IF NOT EXISTS `social_events`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `social_events`;

-- 2. Drop tables if they exist (safe re-import)
DROP TABLE IF EXISTS `messages`;
DROP TABLE IF EXISTS `events`;
DROP TABLE IF EXISTS `users`;

-- =========================================================
-- 3. USERS
-- =========================================================
CREATE TABLE `users` (
    `id`              INT           NOT NULL AUTO_INCREMENT,
    `full_name`       VARCHAR(100)  NOT NULL,
    `email`           VARCHAR(150)  NOT NULL,
    `password`        VARCHAR(255)  NOT NULL,
    `profile_picture` VARCHAR(255)  NOT NULL DEFAULT 'default.png',
    `reset_token`     VARCHAR(64)   DEFAULT NULL,
    `reset_expires`   DATETIME      DEFAULT NULL,
    `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_reset` (`reset_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 4. EVENTS
-- =========================================================
CREATE TABLE `events` (
    `id`          INT           NOT NULL AUTO_INCREMENT,
    `user_id`     INT           NOT NULL,
    `title`       VARCHAR(150)  NOT NULL,
    `description` TEXT          NOT NULL,
    `event_date`  DATETIME      NOT NULL,
    `location`    VARCHAR(200)  NOT NULL,
    `image`       VARCHAR(255)  DEFAULT NULL,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_events_user` (`user_id`),
    KEY `idx_events_date` (`event_date`),
    CONSTRAINT `fk_events_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 5. MESSAGES
-- =========================================================
CREATE TABLE `messages` (
    `id`          INT       NOT NULL AUTO_INCREMENT,
    `sender_id`   INT       NOT NULL,
    `receiver_id` INT       NOT NULL,
    `message`     TEXT      NOT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_msg_sender`   (`sender_id`),
    KEY `idx_msg_receiver` (`receiver_id`),
    KEY `idx_msg_conversation` (`sender_id`, `receiver_id`),
    KEY `idx_msg_created`  (`created_at`),
    CONSTRAINT `fk_msg_sender`
        FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_msg_receiver`
        FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 6. DEMO DATA
-- =========================================================
-- Demo password for BOTH users = "Password123"
-- Hash generated with password_hash('Password123', PASSWORD_DEFAULT)
INSERT INTO `users` (`full_name`, `email`, `password`, `profile_picture`) VALUES
('Alice Johnson', 'alice@example.com',
 '$2y$10$BSgkzQviwYnm5QnhF7qWdO19ApYXqGDbay/Z8TAESZvcgs0nnchu2',
 'default.png'),
('Bob Smith', 'bob@example.com',
 '$2y$10$BSgkzQviwYnm5QnhF7qWdO19ApYXqGDbay/Z8TAESZvcgs0nnchu2',
 'default.png');

-- Demo events (Alice hosts 2, Bob hosts 1 — all in the future)
INSERT INTO `events` (`user_id`, `title`, `description`, `event_date`, `location`, `image`) VALUES
(1, 'PHP Developers Meetup',
 'Monthly meetup for PHP devs. Bring your laptop and questions!',
 DATE_ADD(NOW(), INTERVAL 7 DAY),
 'Cape Town Innovation Hub',
 NULL),
(1, 'Weekend Hiking Trip',
 'Moderate 8km hike. Bring water, snacks, and good shoes.',
 DATE_ADD(NOW(), INTERVAL 14 DAY),
 'Table Mountain Trail',
 NULL),
(2, 'Web Security Workshop',
 'Hands-on workshop on OWASP Top 10, prepared statements, and secure uploads.',
 DATE_ADD(NOW(), INTERVAL 21 DAY),
 'Online (Zoom)',
 NULL);

-- Demo conversation (Alice → Bob, Bob → Alice)
INSERT INTO `messages` (`sender_id`, `receiver_id`, `message`) VALUES
(1, 2, 'Hi Bob, are you coming to the PHP meetup?'),
(2, 1, 'Yes! Looking forward to it. See you there 🎉'),
(1, 2, 'Great, I will save you a seat.'),
(2, 1, 'Thanks Alice!');

-- =========================================================
-- 7. VERIFY
-- =========================================================
SELECT 'users'    AS table_name, COUNT(*) AS row_count FROM `users`
UNION ALL
SELECT 'events'   AS table_name, COUNT(*) AS row_count FROM `events`
UNION ALL
SELECT 'messages' AS table_name, COUNT(*) AS row_count FROM `messages`;