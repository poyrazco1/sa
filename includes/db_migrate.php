<?php
declare(strict_types=1);

/**
 * Uygulama tarafı, geriye uyumlu şema sağlayıcı (self-healing migration).
 *
 * Amaç: Yeni ZIP dosyaları phpMyAdmin'den install.sql tekrar import edilmese bile
 * CRM/RBAC tablolarını oluşturur ve mevcut tablolara eksik (nullable) bağ kolonlarını
 * güvenli ALTER ile ekler. Mevcut projenin "ensure_*" desenini merkezileştirir.
 *
 * GÜVENLİK/GÜVENİLİRLİK:
 * - Sadece CREATE TABLE IF NOT EXISTS + information_schema kontrollü ADD COLUMN + idempotent seed.
 * - DROP/TRUNCATE yok, mevcut veriyi bozmaz.
 * - app_settings.schema_version >= hedef ise hızlı yoldan çıkar (her istekte çalışmaz).
 * - Çağıran taraf try/catch ile sarmalamalı; DB yoksa uygulama akışı kırılmamalı.
 */

const CRM_SCHEMA_VERSION = 80;

function db_migrate(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    // app_settings yoksa oluştur (sürüm işareti için gerekli).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `app_settings` (
            `setting_key` VARCHAR(120) NOT NULL,
            `setting_value` LONGTEXT NULL,
            `value_type` VARCHAR(30) NOT NULL DEFAULT 'string',
            `description` VARCHAR(255) NULL,
            `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $current = 0;
    try {
        $stmt = $pdo->query("SELECT `setting_value` FROM `app_settings` WHERE `setting_key` = 'schema_version'");
        $val = $stmt ? $stmt->fetchColumn() : false;
        if ($val !== false && is_numeric($val)) {
            $current = (int)$val;
        }
    } catch (Throwable $e) {
        $current = 0;
    }

    if ($current >= CRM_SCHEMA_VERSION) {
        $done = true;
        return;
    }

    db_migrate_create_tables($pdo);
    db_migrate_seed_rbac($pdo);
    db_migrate_extend_existing($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `description`)
        VALUES ('schema_version', :v, 'int', 'Uygulama şema sürümü (CRM/RBAC).')
        ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)
    ");
    $stmt->execute([':v' => (string)CRM_SCHEMA_VERSION]);

    $done = true;
}

/** information_schema üzerinden kolon var mı kontrolü (mevcut şema/DATABASE()). */
function db_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
    ");
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/** Kolon yoksa güvenli şekilde ekle. $definition yalın kolon tanımıdır. */
function db_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (db_column_exists($pdo, $table, $column)) {
        return;
    }
    // Tablo/kolon adları sabit (kullanıcı girdisi değil); değerler prepared değil ama
    // literal DDL olduğundan enjeksiyon yüzeyi yok.
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

function db_migrate_create_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `roles` (
            `code` VARCHAR(40) NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `description` VARCHAR(255) NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `role_permissions` (
            `role_code` VARCHAR(40) NOT NULL,
            `permission` VARCHAR(60) NOT NULL,
            PRIMARY KEY (`role_code`, `permission`),
            KEY `idx_rp_perm` (`permission`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `notes` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `body` TEXT NOT NULL,
            `related_type` VARCHAR(30) NOT NULL,
            `related_id` BIGINT UNSIGNED NOT NULL,
            `user_id` BIGINT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_notes_related` (`related_type`, `related_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function db_migrate_seed_rbac(PDO $pdo): void
{
    $pdo->exec("
        INSERT INTO `roles` (`code`, `name`, `description`, `sort_order`) VALUES
        ('admin',   'Yönetici',     'Tüm yetkiler, kullanıcı ve ayar yönetimi.', 10),
        ('manager', 'Müdür',        'CRM tam erişim, tüm kayıtları görür.',       20),
        ('sales',   'Temsilci',     'Araçlar + kendi/atanan CRM kayıtları.',       30),
        ('viewer',  'Görüntüleyici','Salt okuma.',                                40)
        ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`), `sort_order` = VALUES(`sort_order`)
    ");

    $pdo->exec("
        INSERT INTO `role_permissions` (`role_code`, `permission`) VALUES
        ('admin','dashboard.view'),('admin','tools.use'),('admin','crm.view'),('admin','crm.edit'),
        ('admin','crm.delete'),('admin','records.view_all'),('admin','records.view_own'),
        ('admin','admin.settings'),('admin','admin.users'),
        ('manager','dashboard.view'),('manager','tools.use'),('manager','crm.view'),('manager','crm.edit'),
        ('manager','crm.delete'),('manager','records.view_all'),('manager','records.view_own'),
        ('sales','dashboard.view'),('sales','tools.use'),('sales','crm.view'),('sales','crm.edit'),
        ('sales','records.view_own'),
        ('viewer','dashboard.view'),('viewer','crm.view'),('viewer','records.view_own')
        ON DUPLICATE KEY UPDATE `permission` = VALUES(`permission`)
    ");

    // İlk admin — sadece hiç kullanıcı yoksa oluştur (mevcut parolayı asla sıfırlamaz).
    $count = (int)$pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare("
            INSERT INTO `users` (`username`, `email`, `password_hash`, `full_name`, `role_code`, `is_active`, `must_change_password`)
            VALUES ('admin', NULL, :hash, 'Sistem Yöneticisi', 'admin', 1, 1)
        ");
        // Varsayılan parola: Admin1234!  (ilk girişte değiştirilmelidir)
        $stmt->execute([':hash' => '$2y$12$3C5.fXYUzVxtZtnDJCX8PesIdAXNaJQSmRr3D8lVlUm.K3VGMHfCC']);
    }
}

function db_migrate_extend_existing(PDO $pdo): void
{
    // Mevcut tablolar yoksa dokunma (base install.sql henüz import edilmemiş olabilir).
    $tables = $pdo->query("
        SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
    ")->fetchAll(PDO::FETCH_COLUMN);
    $tables = array_map('strval', $tables);

    if (in_array('price_calculation_logs', $tables, true)) {
        db_add_column_if_missing($pdo, 'price_calculation_logs', 'user_id', 'BIGINT UNSIGNED NULL AFTER `user_name`');
        db_add_column_if_missing($pdo, 'price_calculation_logs', 'company_id', 'BIGINT UNSIGNED NULL');
        db_add_column_if_missing($pdo, 'price_calculation_logs', 'contact_id', 'BIGINT UNSIGNED NULL');
        db_add_column_if_missing($pdo, 'price_calculation_logs', 'opportunity_id', 'BIGINT UNSIGNED NULL');
        db_add_column_if_missing($pdo, 'price_calculation_logs', 'quote_id', 'BIGINT UNSIGNED NULL');
    }

    if (in_array('shipping_label_logs', $tables, true)) {
        db_add_column_if_missing($pdo, 'shipping_label_logs', 'user_id', 'BIGINT UNSIGNED NULL');
        db_add_column_if_missing($pdo, 'shipping_label_logs', 'company_id', 'BIGINT UNSIGNED NULL');
        db_add_column_if_missing($pdo, 'shipping_label_logs', 'contact_id', 'BIGINT UNSIGNED NULL');
    }

    if (in_array('label_customers', $tables, true)) {
        db_add_column_if_missing($pdo, 'label_customers', 'company_id', 'BIGINT UNSIGNED NULL');
        db_add_column_if_missing($pdo, 'label_customers', 'contact_id', 'BIGINT UNSIGNED NULL');
    }
}
