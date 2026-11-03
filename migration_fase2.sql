-- Execute uma vez no banco existente. Não recria nem apaga tabelas da Fase 1.
CREATE TABLE `groups` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL, department VARCHAR(120) NOT NULL,
 UNIQUE KEY uq_group_name (name, department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE group_members (
 group_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (group_id,user_id), KEY idx_member_user (user_id),
 FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE tasks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(200) NOT NULL, description TEXT NOT NULL,
 parent_id BIGINT UNSIGNED NULL,
 type ENUM('individual','group') NOT NULL, group_id BIGINT UNSIGNED NULL,
 category VARCHAR(100) NOT NULL DEFAULT '',
 priority ENUM('baixa','media','alta','urgente') NOT NULL DEFAULT 'media',
 status ENUM('pendente','em_andamento','concluida') NOT NULL DEFAULT 'pendente',
 due_at DATETIME NOT NULL, created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, completed_at DATETIME NULL,
 KEY idx_due_at (due_at), KEY idx_status (status), KEY idx_priority (priority),
 FOREIGN KEY (parent_id) REFERENCES tasks(id) ON DELETE CASCADE,
 FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE RESTRICT,
 FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE task_assignees (
 task_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (task_id,user_id), KEY idx_assignee_user (user_id),
 FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
