-- Poweradmin schema update to 4.4.1
-- Add WebAuthn passkey storage for MFA.

CREATE TABLE IF NOT EXISTS `user_passkeys` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `credential_id` text NOT NULL,
    `public_key` text NOT NULL,
    `name` varchar(255) NOT NULL,
    `sign_count` bigint NOT NULL DEFAULT 0,
    `transports` text DEFAULT NULL,
    `aaguid` varchar(64) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_user_passkeys_credential_id` (`credential_id`(255)),
    KEY `idx_user_passkeys_user_id` (`user_id`),
    CONSTRAINT `fk_user_passkeys_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
