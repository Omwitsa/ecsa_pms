CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','manager','employee') NOT NULL DEFAULT 'employee',
  job_title     VARCHAR(120) NOT NULL DEFAULT '',
  manager_id    INT UNSIGNED NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  CONSTRAINT fk_users_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kpis (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(150) NOT NULL,
  description TEXT NOT NULL,
  category    VARCHAR(60) NOT NULL DEFAULT 'General',
  measurement TEXT NOT NULL,
  target      VARCHAR(120) NOT NULL DEFAULT '',
  weight      DECIMAL(5,2) NOT NULL DEFAULT 0,
  scale_min   TINYINT NOT NULL DEFAULT 1,
  scale_max   TINYINT NOT NULL DEFAULT 5,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kpi_rating_levels (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kpi_id      INT UNSIGNED NOT NULL,
  rating      TINYINT NOT NULL,
  label       VARCHAR(60) NOT NULL,
  description TEXT NOT NULL,
  UNIQUE KEY uq_kpi_rating (kpi_id, rating),
  CONSTRAINT fk_levels_kpi FOREIGN KEY (kpi_id) REFERENCES kpis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appraisal_cycles (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  description TEXT NOT NULL,
  start_date  DATE NOT NULL,
  end_date    DATE NOT NULL,
  status      ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cycle_kpis (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cycle_id   INT UNSIGNED NOT NULL,
  kpi_id     INT UNSIGNED NOT NULL,
  title      VARCHAR(150) NOT NULL,
  category   VARCHAR(60) NOT NULL,
  weight     DECIMAL(5,2) NOT NULL,
  scale_min  TINYINT NOT NULL,
  scale_max  TINYINT NOT NULL,
  UNIQUE KEY uq_cycle_kpi (cycle_id, kpi_id),
  CONSTRAINT fk_ck_cycle FOREIGN KEY (cycle_id) REFERENCES appraisal_cycles(id) ON DELETE CASCADE,
  CONSTRAINT fk_ck_kpi   FOREIGN KEY (kpi_id)   REFERENCES kpis(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appraisals (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cycle_id          INT UNSIGNED NOT NULL,
  employee_id       INT UNSIGNED NOT NULL,
  reviewer_id       INT UNSIGNED NULL,
  status            ENUM('pending_self','pending_manager','completed') NOT NULL DEFAULT 'pending_self',
  self_comment      TEXT NULL,
  manager_comment   TEXT NULL,
  final_score       DECIMAL(5,2) NULL,
  rating_band       VARCHAR(60) NULL,
  self_submitted_at DATETIME NULL,
  completed_at      DATETIME NULL,
  UNIQUE KEY uq_cycle_employee (cycle_id, employee_id),
  CONSTRAINT fk_ap_cycle    FOREIGN KEY (cycle_id)    REFERENCES appraisal_cycles(id) ON DELETE CASCADE,
  CONSTRAINT fk_ap_employee FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ap_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appraisal_scores (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  appraisal_id    INT UNSIGNED NOT NULL,
  cycle_kpi_id    INT UNSIGNED NOT NULL,
  self_rating     TINYINT NULL,
  self_comment    TEXT NULL,
  manager_rating  TINYINT NULL,
  manager_comment TEXT NULL,
  UNIQUE KEY uq_appraisal_kpi (appraisal_id, cycle_kpi_id),
  CONSTRAINT fk_sc_appraisal FOREIGN KEY (appraisal_id) REFERENCES appraisals(id) ON DELETE CASCADE,
  CONSTRAINT fk_sc_cyclekpi  FOREIGN KEY (cycle_kpi_id) REFERENCES cycle_kpis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
