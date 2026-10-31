-- ==========================================================
--  DSAU Admissions Portal - database schema + starter data
--  Import with:  mysql -u root -p < database.sql
--  or via phpMyAdmin -> Import.
-- ==========================================================

CREATE DATABASE IF NOT EXISTS dsau_portal
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dsau_portal;

-- ---------- Admin users ----------
CREATE TABLE IF NOT EXISTS admins (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','staff') NOT NULL DEFAULT 'admin',
  last_login_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------- Failed login tracking ----------
CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip_address   VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;

-- ---------- Admin password reset tokens ----------
CREATE TABLE IF NOT EXISTS password_reset_tokens (
  admin_id   INT UNSIGNED NOT NULL PRIMARY KEY,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reset_expires (expires_at),
  CONSTRAINT fk_reset_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- Courses ----------
CREATE TABLE IF NOT EXISTS courses (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title           VARCHAR(160) NOT NULL,
  slug            VARCHAR(190) NOT NULL UNIQUE,
  description     TEXT NULL,
  topics          TEXT NULL,                         -- one topic/module per line
  duration_months TINYINT UNSIGNED NOT NULL DEFAULT 3,
  fee_beginner    INT UNSIGNED NOT NULL DEFAULT 300000,
  fee_advanced    INT UNSIGNED NOT NULL DEFAULT 400000,
  capacity        INT UNSIGNED NULL,                 -- optional seat limit per intake
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  sort_order      INT NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------- Intakes ----------
CREATE TABLE IF NOT EXISTS intakes (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                 VARCHAR(120) NOT NULL,
  start_date           DATE NULL,
  application_deadline DATE NULL,
  is_open              TINYINT(1) NOT NULL DEFAULT 1,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------- Applications (mirrors the DSAU Google Form) ----------
CREATE TABLE IF NOT EXISTS applications (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reference          VARCHAR(40) NOT NULL UNIQUE,
  intake_id          INT UNSIGNED NOT NULL,
  course_id          INT UNSIGNED NOT NULL,
  level              ENUM('Beginner','Advanced') NOT NULL DEFAULT 'Beginner',

  full_name          VARCHAR(160) NOT NULL,
  has_laptop         ENUM('Yes','No') NOT NULL,
  has_internet       ENUM('Yes','No') NOT NULL,
  has_smartphone     ENUM('Yes','No') NOT NULL,
  has_basic_skills   ENUM('Yes','No') NOT NULL,
  ack_fees           TINYINT(1) NOT NULL DEFAULT 0,
  ack_installments   TINYINT(1) NOT NULL DEFAULT 0,

  gender             ENUM('Female','Male') NOT NULL,
  date_of_birth      DATE NOT NULL,
  phone              VARCHAR(30) NOT NULL,
  email              VARCHAR(190) NOT NULL,
  location           VARCHAR(190) NOT NULL,
  education          VARCHAR(60) NOT NULL,
  education_other    VARCHAR(120) NULL,
  employment         VARCHAR(60) NOT NULL,
  employment_other   VARCHAR(120) NULL,
  heard_about        VARCHAR(255) NOT NULL,           -- comma separated
  heard_about_other  VARCHAR(120) NULL,

  fee_due            INT UNSIGNED NOT NULL DEFAULT 0,
  status             ENUM('Pending','Under Review','Admitted','Waitlisted','Rejected') NOT NULL DEFAULT 'Pending',
  admin_notes        TEXT NULL,
  reviewed_by        INT UNSIGNED NULL,
  reviewed_at        DATETIME NULL,
  ip_address         VARCHAR(45) NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_status (status),
  INDEX idx_course (course_id),
  INDEX idx_intake (intake_id),
  INDEX idx_created (created_at),
  INDEX idx_email (email),
  CONSTRAINT fk_app_course FOREIGN KEY (course_id) REFERENCES courses(id),
  CONSTRAINT fk_app_intake FOREIGN KEY (intake_id) REFERENCES intakes(id),
  CONSTRAINT fk_app_admin  FOREIGN KEY (reviewed_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- Payments (supports installments) ----------
CREATE TABLE IF NOT EXISTS payments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  amount         INT UNSIGNED NOT NULL,
  method         ENUM('Mobile Money','Bank Transfer','Cash','Other') NOT NULL DEFAULT 'Mobile Money',
  reference_no   VARCHAR(80) NULL,
  paid_on        DATE NOT NULL,
  notes          VARCHAR(255) NULL,
  recorded_by    INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_paid_on (paid_on),
  CONSTRAINT fk_pay_app   FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_admin FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ==========================================================
--  Starter data taken from digitalshiftacademyug.com/courses
--  (Beginner UGX 300,000  |  Advanced UGX 400,000 | 3 months, online)
-- ==========================================================
INSERT INTO courses (title, slug, description, topics, duration_months, fee_beginner, fee_advanced, sort_order) VALUES
('Data Analysis', 'data-analysis',
 'Turn raw data into decisions. Learn to clean, analyse and present data using the tools employers ask for.',
 'Data Analysis in Excel\nSQL for Data Analysis\nBusiness Intelligence\nPython for Data Analysis', 3, 300000, 400000, 1),
('Cybersecurity', 'cybersecurity',
 'Protect people, devices and organisations. From fundamentals to ethical hacking and cryptography.',
 'Cybersecurity Fundamentals\nMobile and Computer Security\nNetwork and Cloud Security\nPenetration Testing (Ethical Hacking)\nCryptography', 3, 300000, 400000, 2),
('Digital Marketing', 'digital-marketing',
 'Grow brands and businesses online with social media, content, paid ads and branding skills.',
 'Social Media Marketing\nContent Creation & Strategy\nPaid Digital Advertising\nBranding & Business Growth', 3, 300000, 400000, 3),
('Artificial Intelligence', 'artificial-intelligence',
 'Use AI to work faster and smarter: prompt engineering, content, research and automation with AI agents.',
 'AI Fundamentals & Prompt Engineering\nAI-Powered Content Creation & Productivity\nAI for Research & Data Analysis\nAI Automation, Custom GPTs & AI Agents', 3, 300000, 400000, 4),
('Software Development', 'software-development',
 'Build and ship real web applications, from programming basics to deployment.',
 'Programming Fundamentals\nFull-Stack Frameworks\nVersion Control with Git & GitHub\nDeploy Web Applications', 3, 300000, 400000, 5);

INSERT INTO intakes (name, start_date, application_deadline, is_open) VALUES
('September 2026 Intake', '2026-09-01', '2026-08-25', 0),
('January 2027 Intake',   '2027-01-18', '2027-01-10', 1);

-- The first administrator is created in the browser the first time you open
--   http://localhost/dsau-portal/admin/setup.php
