CREATE DATABASE IF NOT EXISTS taskflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE taskflow;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email_encrypted TEXT NOT NULL,
    email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    job_title VARCHAR(120) NOT NULL,
    department VARCHAR(120) NOT NULL,
    superior_id BIGINT UNSIGNED NULL,
    role ENUM('gestor', 'funcionario') NOT NULL DEFAULT 'funcionario',
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_superior FOREIGN KEY (superior_id) REFERENCES users(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_user_created (user_id, created_at),
    CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
