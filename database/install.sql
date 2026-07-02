-- Akıllı Fiyat Sihirbazı / Kargo Etiketi Paneli
-- FULL INSTALL SQL
-- PLESK UYUMLU IMPORT SÜRÜMÜ
-- DİKKAT: Bu SQL dosyası CREATE DATABASE / USE DATABASE komutu içermez.
-- Plesk'te önce veritabanını oluşturun, sonra bu SQL'i o seçili veritabanının içine import edin.
-- MariaDB 10.6+ / MySQL 8+
-- Bu dosya sadece tabloları oluşturur; oranlar, komisyonlar, kargo firmaları ve kargo ücretlerini de seed eder.


CREATE TABLE IF NOT EXISTS `app_settings` (
    `setting_key` VARCHAR(120) NOT NULL,
    `setting_value` LONGTEXT NULL,
    `value_type` VARCHAR(30) NOT NULL DEFAULT 'string',
    `description` VARCHAR(255) NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `description`) VALUES
('vat_rate', '0.20', 'decimal', 'KDV oranı'),
('free_cargo_threshold_try', '15000', 'decimal', 'KDV dahil ücretsiz kargo eşiği'),
('sarf_expense_usd', '0.10', 'decimal', 'Her ürüne eklenen gizli minimum sarf gideri'),
('default_sender_name', 'Poyraz Toner', 'string', 'Kargo etiketi gönderici adı'),
('default_sender_address', 'Hürriyet, Akgün Sk. No:17-a, 34212 Bağcılar/İstanbul', 'string', 'Kargo etiketi gönderici adresi'),
('default_sender_phone', '(0212) 550 09 09', 'string', 'Kargo etiketi gönderici telefonu')
ON DUPLICATE KEY UPDATE
    `setting_value` = VALUES(`setting_value`),
    `value_type` = VALUES(`value_type`),
    `description` = VALUES(`description`);

CREATE TABLE IF NOT EXISTS `currency_rates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `source` VARCHAR(120) NULL,
    `rate_date` VARCHAR(80) NULL,
    `base_currency` VARCHAR(10) NOT NULL,
    `quote_currency` VARCHAR(10) NOT NULL DEFAULT 'TRY',
    `rate` DECIMAL(18,6) NOT NULL,
    `payload` LONGTEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_currency_pair_created` (`base_currency`, `quote_currency`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_methods` (
    `code` VARCHAR(40) NOT NULL,
    `label` VARCHAR(120) NOT NULL,
    `effect_type` VARCHAR(40) NOT NULL,
    `rate` DECIMAL(10,5) NOT NULL DEFAULT 0,
    `description` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `payment_methods` (`code`, `label`, `effect_type`, `rate`, `description`, `is_active`, `sort_order`) VALUES
('card', 'Kredi kartı', 'commission_from_sale', 0.03200, 'Satıştan %3,20 komisyon kesilir.', 1, 10),
('eft', 'Havale / EFT', 'discount_from_sale', 0.03000, 'Satışa %3 indirim uygulanır.', 1, 20),
('term30', '30 gün vadeli', 'term_cost', 0.03500, '30 gün vadeli satışta %3,50 vade farkı.', 1, 30),
('term60', '60 gün vadeli', 'term_cost', 0.07000, '60 gün vadeli satışta %7 vade farkı.', 1, 40),
('installment2', '2 taksit', 'commission_from_sale', 0.05500, '2 taksitte satıştan %5,50 komisyon kesilir.', 1, 50)
ON DUPLICATE KEY UPDATE
    `label` = VALUES(`label`),
    `effect_type` = VALUES(`effect_type`),
    `rate` = VALUES(`rate`),
    `description` = VALUES(`description`),
    `is_active` = VALUES(`is_active`),
    `sort_order` = VALUES(`sort_order`);

CREATE TABLE IF NOT EXISTS `cargo_carriers` (
    `code` VARCHAR(40) NOT NULL,
    `label` VARCHAR(120) NOT NULL,
    `logo_color` VARCHAR(500) NULL,
    `logo_bw` VARCHAR(500) NULL,
    `agreement_code` VARCHAR(120) NULL,
    `pricing_multiplier` DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    `extra_after_desi` DECIMAL(10,2) NULL,
    `extra_per_desi` DECIMAL(10,2) NULL,
    `is_active_price` TINYINT(1) NOT NULL DEFAULT 1,
    `is_active_label` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `cargo_carriers` (
    `code`, `label`, `logo_color`, `logo_bw`, `agreement_code`, `pricing_multiplier`,
    `extra_after_desi`, `extra_per_desi`, `is_active_price`, `is_active_label`, `sort_order`
) VALUES
('hepsijet', 'Hepsijet',
 'https://www.poyraztoner.com/Data/EditorFiles/Kargo%20Logo/hepsijet-logo.png',
 'https://www.poyraztoner.com/Data/EditorFiles/Kargo%20Logo/hepsijet-logo-siyah.png',
 NULL, 1.2500, NULL, NULL, 1, 0, 10),
('dhl', 'DHL',
 'https://www.poyraztoner.com/Data/EditorFiles/Kargo%20Logo/dhl-logo.png',
 'https://www.poyraztoner.com/Data/EditorFiles/Kargo%20Logo/dhl-logo-siyah.png',
 '695383532', 1.0000, NULL, NULL, 1, 1, 20),
('aras', 'Aras',
 'https://www.poyraztoner.com/Data/EditorFiles/Kargo%20Logo/aras-logo.png',
 'https://www.poyraztoner.com/Data/EditorFiles/Kargo%20Logo/aras-logo-siyah.png',
 NULL, 1.0000, 30.00, 11.55, 1, 1, 30)
ON DUPLICATE KEY UPDATE
    `label` = VALUES(`label`),
    `logo_color` = VALUES(`logo_color`),
    `logo_bw` = VALUES(`logo_bw`),
    `agreement_code` = VALUES(`agreement_code`),
    `pricing_multiplier` = VALUES(`pricing_multiplier`),
    `extra_after_desi` = VALUES(`extra_after_desi`),
    `extra_per_desi` = VALUES(`extra_per_desi`),
    `is_active_price` = VALUES(`is_active_price`),
    `is_active_label` = VALUES(`is_active_label`),
    `sort_order` = VALUES(`sort_order`);

CREATE TABLE IF NOT EXISTS `cargo_tariffs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `carrier_code` VARCHAR(40) NOT NULL,
    `min_desi` DECIMAL(10,2) NOT NULL,
    `max_desi` DECIMAL(10,2) NOT NULL,
    `base_price_try` DECIMAL(12,2) NOT NULL,
    `multiplier` DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    `final_price_try` DECIMAL(12,2) AS (`base_price_try` * `multiplier`) STORED,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_carrier_range` (`carrier_code`, `min_desi`, `max_desi`),
    KEY `idx_carrier_active` (`carrier_code`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `cargo_tariffs` (`carrier_code`, `min_desi`, `max_desi`, `base_price_try`, `multiplier`, `is_active`) VALUES
('hepsijet', 0, 1, 113.69, 1.2500, 1),
('hepsijet', 2, 4, 113.69, 1.2500, 1),
('hepsijet', 5, 10, 117.27, 1.2500, 1),
('hepsijet', 11, 20, 186.21, 1.2500, 1),
('hepsijet', 21, 30, 311.66, 1.2500, 1),
('hepsijet', 31, 40, 390.07, 1.2500, 1),

('dhl', 0, 5, 178.31, 1.0000, 1),
('dhl', 6, 10, 197.29, 1.0000, 1),
('dhl', 11, 15, 216.28, 1.0000, 1),
('dhl', 16, 20, 241.58, 1.0000, 1),
('dhl', 21, 25, 286.44, 1.0000, 1),
('dhl', 26, 30, 342.84, 1.0000, 1),
('dhl', 31, 40, 406.12, 1.0000, 1),
('dhl', 41, 50, 650.00, 1.0000, 1),

('aras', 0, 5, 201.00, 1.0000, 1),
('aras', 6, 10, 220.00, 1.0000, 1),
('aras', 11, 15, 261.00, 1.0000, 1),
('aras', 16, 20, 340.00, 1.0000, 1),
('aras', 21, 25, 397.00, 1.0000, 1),
('aras', 26, 30, 453.00, 1.0000, 1)
ON DUPLICATE KEY UPDATE
    `base_price_try` = VALUES(`base_price_try`),
    `multiplier` = VALUES(`multiplier`),
    `is_active` = VALUES(`is_active`);

CREATE TABLE IF NOT EXISTS `price_calculation_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `user_name` VARCHAR(120) NULL,
    `sold` TINYINT(1) NOT NULL DEFAULT 0,
    `sold_label` VARCHAR(40) NULL,
    `currency` VARCHAR(10) NOT NULL,
    `payment_code` VARCHAR(40) NULL,
    `payment_label` VARCHAR(120) NULL,
    `cost_input` DECIMAL(18,4) NULL,
    `profit_percent` DECIMAL(8,2) NULL,
    `carrier_code` VARCHAR(40) NULL,
    `carrier_label` VARCHAR(120) NULL,
    `desi` DECIMAL(10,2) NULL,
    `sale_ex_try` DECIMAL(18,4) NULL,
    `sale_inc_try` DECIMAL(18,4) NULL,
    `collected_ex_try` DECIMAL(18,4) NULL,
    `collected_inc_try` DECIMAL(18,4) NULL,
    `net_profit_try` DECIMAL(18,4) NULL,
    `cargo_try` DECIMAL(18,4) NULL,
    `customer_cargo_try` DECIMAL(18,4) NULL,
    `customer_total_ex_try` DECIMAL(18,4) NULL,
    `installment_extra_loss_try` DECIMAL(18,4) NULL,
    `payment_impact_title` VARCHAR(160) NULL,
    `payment_impact_rule` VARCHAR(255) NULL,
    `payment_impact_try` DECIMAL(18,4) NULL,
    `usd_try_rate` DECIMAL(18,6) NULL,
    `eur_try_rate` DECIMAL(18,6) NULL,
    `rates_source` VARCHAR(120) NULL,
    `sale_ex_text` VARCHAR(80) NULL,
    `sale_inc_text` VARCHAR(80) NULL,
    `customer_total_ex_text` VARCHAR(80) NULL,
    `payload` LONGTEXT NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_user_name` (`user_name`),
    KEY `idx_sold` (`sold`),
    KEY `idx_payment` (`payment_code`),
    KEY `idx_carrier` (`carrier_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `label_customers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    `customer_name` VARCHAR(190) NULL,
    `company_name` VARCHAR(190) NULL,
    `phone` VARCHAR(60) NULL,
    `note` VARCHAR(255) NULL,
    `raw_address` TEXT NULL,
    `mahalle` VARCHAR(160) NULL,
    `street` VARCHAR(190) NULL,
    `door_no` VARCHAR(80) NULL,
    `postcode` VARCHAR(20) NULL,
    `county` VARCHAR(120) NULL,
    `city` VARCHAR(120) NULL,
    `extra` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_customer_name` (`customer_name`),
    KEY `idx_company_name` (`company_name`),
    KEY `idx_phone` (`phone`),
    KEY `idx_city_county` (`city`, `county`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shipping_label_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` VARCHAR(100) NULL,
    `customer_id` VARCHAR(80) NULL,
    `customer_name` VARCHAR(190) NULL,
    `company_name` VARCHAR(190) NULL,
    `address_mode` VARCHAR(30) NULL,
    `carrier` VARCHAR(40) NOT NULL,
    `carrier_label` VARCHAR(80) NULL,
    `cargo_agreement_code` VARCHAR(80) NULL,
    `sender_name` VARCHAR(160) NULL,
    `sender_address` VARCHAR(255) NULL,
    `sender_phone` VARCHAR(60) NULL,
    `logo_mode` VARCHAR(20) NULL,
    `payment_type` VARCHAR(30) NOT NULL,
    `payment_label` VARCHAR(80) NULL,
    `paper` VARCHAR(20) NOT NULL,
    `paper_label` VARCHAR(40) NULL,
    `invoice_ref` VARCHAR(120) NOT NULL,
    `piece_total` INT UNSIGNED NOT NULL DEFAULT 1,
    `piece_refs` LONGTEXT NOT NULL,
    `qr_payloads` LONGTEXT NULL,
    `recipient` VARCHAR(190) NULL,
    `phone` VARCHAR(60) NOT NULL,
    `raw_address` TEXT NULL,
    `mahalle` VARCHAR(160) NOT NULL,
    `street` VARCHAR(190) NOT NULL,
    `door_no` VARCHAR(80) NULL,
    `postcode` VARCHAR(20) NULL,
    `county` VARCHAR(120) NOT NULL,
    `city` VARCHAR(120) NOT NULL,
    `extra` VARCHAR(255) NULL,
    `payload` LONGTEXT NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_invoice_ref` (`invoice_ref`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_carrier` (`carrier`),
    KEY `idx_recipient` (`recipient`),
    KEY `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shipping_label_pieces` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `label_log_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `invoice_ref` VARCHAR(120) NOT NULL,
    `piece_ref` VARCHAR(160) NOT NULL,
    `piece_no` INT UNSIGNED NOT NULL,
    `piece_total` INT UNSIGNED NOT NULL,
    `qr_payload` TEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_piece_ref` (`piece_ref`),
    KEY `idx_label_log_id` (`label_log_id`),
    KEY `idx_invoice_ref` (`invoice_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `print_output_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `output_type` VARCHAR(40) NOT NULL,
    `related_log_id` BIGINT UNSIGNED NULL,
    `paper` VARCHAR(20) NULL,
    `piece_total` INT UNSIGNED NULL,
    `html_snapshot` LONGTEXT NULL,
    `payload` LONGTEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_output_type` (`output_type`),
    KEY `idx_related_log_id` (`related_log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- v80 — CRM + RBAC (login, kullanıcı, rol/yetki, CRM modülleri)
-- Geriye uyumlu: yeni tablolar IF NOT EXISTS; DROP/TRUNCATE yok.
-- Mevcut tablolara eklenen kolonlar uygulama tarafında (includes/db_migrate.php)
-- information_schema kontrollü, güvenli ALTER ile eklenir.
-- ============================================================

-- --- Roller ---
CREATE TABLE IF NOT EXISTS `roles` (
    `code` VARCHAR(40) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `description` VARCHAR(255) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`code`, `name`, `description`, `sort_order`) VALUES
('admin',   'Yönetici',   'Tüm yetkiler, kullanıcı ve ayar yönetimi.', 10),
('manager', 'Müdür',      'CRM tam erişim, tüm kayıtları görür.',       20),
('sales',   'Temsilci',   'Araçlar + kendi/atanan CRM kayıtları.',       30),
('viewer',  'Görüntüleyici','Salt okuma.',                              40)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `sort_order` = VALUES(`sort_order`);

-- --- Rol yetkileri ---
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_code` VARCHAR(40) NOT NULL,
    `permission` VARCHAR(60) NOT NULL,
    PRIMARY KEY (`role_code`, `permission`),
    KEY `idx_rp_perm` (`permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `role_permissions` (`role_code`, `permission`) VALUES
('admin','dashboard.view'),('admin','tools.use'),('admin','crm.view'),('admin','crm.edit'),
('admin','crm.delete'),('admin','records.view_all'),('admin','records.view_own'),
('admin','admin.settings'),('admin','admin.users'),
('manager','dashboard.view'),('manager','tools.use'),('manager','crm.view'),('manager','crm.edit'),
('manager','crm.delete'),('manager','records.view_all'),('manager','records.view_own'),
('sales','dashboard.view'),('sales','tools.use'),('sales','crm.view'),('sales','crm.edit'),
('sales','records.view_own'),
('viewer','dashboard.view'),('viewer','crm.view'),('viewer','records.view_own')
ON DUPLICATE KEY UPDATE `permission` = VALUES(`permission`);

-- --- Kullanıcılar ---
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(80) NOT NULL,
    `email` VARCHAR(190) NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(160) NULL,
    `role_code` VARCHAR(40) NOT NULL DEFAULT 'sales',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
    `last_login_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_username` (`username`),
    KEY `idx_users_role` (`role_code`),
    KEY `idx_users_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- İlk kurulum admin kullanıcısı.
-- Kullanıcı adı: admin  —  Parola: Admin1234!  (İLK GİRİŞTE DEĞİŞTİRİLMELİDİR)
-- Tekrar import'ta parolayı SIFIRLAMAZ (username=username no-op).
INSERT INTO `users` (`username`, `email`, `password_hash`, `full_name`, `role_code`, `is_active`, `must_change_password`) VALUES
('admin', NULL, '$2y$12$3C5.fXYUzVxtZtnDJCX8PesIdAXNaJQSmRr3D8lVlUm.K3VGMHfCC', 'Sistem Yöneticisi', 'admin', 1, 1)
ON DUPLICATE KEY UPDATE `username` = `username`;

-- --- Firmalar ---
CREATE TABLE IF NOT EXISTS `companies` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(190) NOT NULL,
    `tax_office` VARCHAR(120) NULL,
    `tax_no` VARCHAR(40) NULL,
    `phone` VARCHAR(60) NULL,
    `email` VARCHAR(190) NULL,
    `city` VARCHAR(120) NULL,
    `county` VARCHAR(120) NULL,
    `address` TEXT NULL,
    `source` VARCHAR(80) NULL,
    `owner_user_id` BIGINT UNSIGNED NULL,
    `created_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_companies_name` (`name`),
    KEY `idx_companies_owner` (`owner_user_id`),
    KEY `idx_companies_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Kişiler ---
CREATE TABLE IF NOT EXISTS `contacts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` BIGINT UNSIGNED NULL,
    `full_name` VARCHAR(190) NOT NULL,
    `title` VARCHAR(120) NULL,
    `phone` VARCHAR(60) NULL,
    `email` VARCHAR(190) NULL,
    `note` VARCHAR(255) NULL,
    `owner_user_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_contacts_company` (`company_id`),
    KEY `idx_contacts_name` (`full_name`),
    KEY `idx_contacts_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Lead (aday) ---
CREATE TABLE IF NOT EXISTS `leads` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(190) NOT NULL,
    `company_id` BIGINT UNSIGNED NULL,
    `contact_id` BIGINT UNSIGNED NULL,
    `contact_name` VARCHAR(190) NULL,
    `phone` VARCHAR(60) NULL,
    `email` VARCHAR(190) NULL,
    `source` VARCHAR(80) NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'new',
    `est_value` DECIMAL(18,2) NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'TL',
    `owner_user_id` BIGINT UNSIGNED NULL,
    `note` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_leads_status` (`status`),
    KEY `idx_leads_owner` (`owner_user_id`),
    KEY `idx_leads_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Fırsatlar ---
CREATE TABLE IF NOT EXISTS `opportunities` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(190) NOT NULL,
    `company_id` BIGINT UNSIGNED NULL,
    `contact_id` BIGINT UNSIGNED NULL,
    `stage` VARCHAR(30) NOT NULL DEFAULT 'new',
    `amount` DECIMAL(18,2) NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'TL',
    `probability` TINYINT UNSIGNED NULL,
    `expected_close_date` DATE NULL,
    `owner_user_id` BIGINT UNSIGNED NULL,
    `lead_id` BIGINT UNSIGNED NULL,
    `note` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_opps_stage` (`stage`),
    KEY `idx_opps_owner` (`owner_user_id`),
    KEY `idx_opps_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Teklifler ---
CREATE TABLE IF NOT EXISTS `quotes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `quote_no` VARCHAR(60) NOT NULL,
    `opportunity_id` BIGINT UNSIGNED NULL,
    `company_id` BIGINT UNSIGNED NULL,
    `contact_id` BIGINT UNSIGNED NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'draft',
    `currency` VARCHAR(10) NOT NULL DEFAULT 'TL',
    `subtotal` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `vat_total` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `grand_total` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `valid_until` DATE NULL,
    `owner_user_id` BIGINT UNSIGNED NULL,
    `note` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_quote_no` (`quote_no`),
    KEY `idx_quotes_status` (`status`),
    KEY `idx_quotes_company` (`company_id`),
    KEY `idx_quotes_opp` (`opportunity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quote_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `quote_id` BIGINT UNSIGNED NOT NULL,
    `product_name` VARCHAR(255) NOT NULL,
    `ws_product_code` VARCHAR(80) NULL,
    `qty` DECIMAL(12,2) NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `vat_rate` DECIMAL(6,4) NOT NULL DEFAULT 0.20,
    `line_total` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_qitems_quote` (`quote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Görevler ---
CREATE TABLE IF NOT EXISTS `tasks` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'open',
    `priority` VARCHAR(20) NOT NULL DEFAULT 'normal',
    `due_at` DATETIME NULL,
    `remind_at` DATETIME NULL,
    `assigned_user_id` BIGINT UNSIGNED NULL,
    `related_type` VARCHAR(30) NULL,
    `related_id` BIGINT UNSIGNED NULL,
    `created_by` BIGINT UNSIGNED NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tasks_status` (`status`),
    KEY `idx_tasks_assigned` (`assigned_user_id`),
    KEY `idx_tasks_due` (`due_at`),
    KEY `idx_tasks_related` (`related_type`, `related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Notlar ---
CREATE TABLE IF NOT EXISTS `notes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `body` TEXT NOT NULL,
    `related_type` VARCHAR(30) NOT NULL,
    `related_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notes_related` (`related_type`, `related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Aktivite / zaman tüneli (audit + CRM feed) ---
CREATE TABLE IF NOT EXISTS `activities` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` VARCHAR(30) NOT NULL DEFAULT 'note',
    `subject` VARCHAR(255) NULL,
    `body` TEXT NULL,
    `related_type` VARCHAR(30) NULL,
    `related_id` BIGINT UNSIGNED NULL,
    `user_id` BIGINT UNSIGNED NULL,
    `occurred_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_act_related` (`related_type`, `related_id`),
    KEY `idx_act_user` (`user_id`),
    KEY `idx_act_occurred` (`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Şema sürüm işareti (uygulama tarafı migration bunu kontrol eder).
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `description`) VALUES
('schema_version', '80', 'int', 'Uygulama şema sürümü (CRM/RBAC).')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
