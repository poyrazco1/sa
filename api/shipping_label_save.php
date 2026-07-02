<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Sadece POST desteklenir.'], 405);
}

$data = request_json();

$required = ['carrier', 'invoice_ref', 'piece_total', 'piece_refs', 'phone', 'mahalle', 'street', 'county', 'city'];
foreach ($required as $key) {
    if (!isset($data[$key]) || $data[$key] === '' || $data[$key] === []) {
        json_response(['ok' => false, 'message' => "{$key} eksik."], 422);
    }
}

try {
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS shipping_label_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(100) NULL,
            customer_id VARCHAR(80) NULL,
            customer_name VARCHAR(190) NULL,
            company_name VARCHAR(190) NULL,
            address_mode VARCHAR(30) NULL,
            carrier VARCHAR(40) NOT NULL,
            carrier_label VARCHAR(80) NULL,
            cargo_agreement_code VARCHAR(80) NULL,
            sender_name VARCHAR(160) NULL,
            sender_address VARCHAR(255) NULL,
            sender_phone VARCHAR(60) NULL,
            logo_mode VARCHAR(20) NULL,
            payment_type VARCHAR(30) NOT NULL,
            payment_label VARCHAR(80) NULL,
            paper VARCHAR(20) NOT NULL,
            paper_label VARCHAR(40) NULL,
            invoice_ref VARCHAR(120) NOT NULL,
            piece_total INT UNSIGNED NOT NULL DEFAULT 1,
            piece_refs LONGTEXT NOT NULL,
            qr_payloads LONGTEXT NULL,
            recipient VARCHAR(190) NULL,
            phone VARCHAR(60) NOT NULL,
            raw_address TEXT NULL,
            mahalle VARCHAR(160) NOT NULL,
            street VARCHAR(190) NOT NULL,
            door_no VARCHAR(80) NULL,
            postcode VARCHAR(20) NULL,
            county VARCHAR(120) NOT NULL,
            city VARCHAR(120) NOT NULL,
            extra VARCHAR(255) NULL,
            payload LONGTEXT NOT NULL,
            PRIMARY KEY (id),
            KEY idx_invoice_ref (invoice_ref),
            KEY idx_created_at (created_at),
            KEY idx_carrier (carrier)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS shipping_label_pieces (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            label_log_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            invoice_ref VARCHAR(120) NOT NULL,
            piece_ref VARCHAR(160) NOT NULL,
            piece_no INT UNSIGNED NOT NULL,
            piece_total INT UNSIGNED NOT NULL,
            qr_payload TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_piece_ref (piece_ref),
            KEY idx_label_log_id (label_log_id),
            KEY idx_invoice_ref (invoice_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $existingColumns = $pdo->query("SHOW COLUMNS FROM shipping_label_logs")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('customer_name', $existingColumns, true)) {
        $pdo->exec("ALTER TABLE shipping_label_logs ADD COLUMN customer_name VARCHAR(190) NULL AFTER customer_id");
    }
    if (!in_array('company_name', $existingColumns, true)) {
        $pdo->exec("ALTER TABLE shipping_label_logs ADD COLUMN company_name VARCHAR(190) NULL AFTER customer_name");
    }
    // CRM bağ kolonları (geriye uyumlu; app.js göndermezse NULL kalır).
    foreach (['user_id', 'company_id', 'contact_id'] as $crmCol) {
        if (!in_array($crmCol, $existingColumns, true)) {
            $pdo->exec("ALTER TABLE shipping_label_logs ADD COLUMN {$crmCol} BIGINT UNSIGNED NULL");
        }
    }

    $__pwUser = (function_exists('auth_user') ? auth_user() : null);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO shipping_label_logs (
            created_by, user_id, company_id, contact_id, customer_id, customer_name, company_name, address_mode, carrier, carrier_label, cargo_agreement_code,
            sender_name, sender_address, sender_phone, logo_mode,
            payment_type, payment_label, paper, paper_label,
            invoice_ref, piece_total, piece_refs, qr_payloads,
            recipient, phone, raw_address, mahalle, street, door_no,
            postcode, county, city, extra, payload
        ) VALUES (
            :created_by, :user_id, :company_id, :contact_id, :customer_id, :customer_name, :company_name, :address_mode, :carrier, :carrier_label, :cargo_agreement_code,
            :sender_name, :sender_address, :sender_phone, :logo_mode,
            :payment_type, :payment_label, :paper, :paper_label,
            :invoice_ref, :piece_total, :piece_refs, :qr_payloads,
            :recipient, :phone, :raw_address, :mahalle, :street, :door_no,
            :postcode, :county, :city, :extra, :payload
        )
    ");

    $pieceRefs = is_array($data['piece_refs']) ? $data['piece_refs'] : [];
    $qrPayloads = is_array($data['qr_payloads'] ?? null) ? $data['qr_payloads'] : [];

    $stmt->execute([
        ':created_by' => (string)($data['created_by'] ?? ''),
        ':user_id' => $__pwUser ? (int)$__pwUser['id'] : (((int)($data['user_id'] ?? 0)) ?: null),
        ':company_id' => ((int)($data['company_id'] ?? 0)) ?: null,
        ':contact_id' => ((int)($data['contact_id'] ?? 0)) ?: null,
        ':customer_id' => (string)($data['customer_id'] ?? ''),
        ':customer_name' => (string)($data['customer_name'] ?? ''),
        ':company_name' => (string)($data['company_name'] ?? ''),
        ':address_mode' => (string)($data['address_mode'] ?? ''),
        ':carrier' => (string)$data['carrier'],
        ':carrier_label' => (string)($data['carrier_label'] ?? ''),
        ':cargo_agreement_code' => (string)($data['cargo_agreement_code'] ?? ''),
        ':sender_name' => (string)($data['sender_name'] ?? ''),
        ':sender_address' => (string)($data['sender_address'] ?? ''),
        ':sender_phone' => (string)($data['sender_phone'] ?? ''),
        ':logo_mode' => (string)($data['logo_mode'] ?? ''),
        ':payment_type' => (string)$data['payment_type'],
        ':payment_label' => (string)($data['payment_label'] ?? ''),
        ':paper' => (string)$data['paper'],
        ':paper_label' => (string)($data['paper_label'] ?? ''),
        ':invoice_ref' => (string)$data['invoice_ref'],
        ':piece_total' => (int)$data['piece_total'],
        ':piece_refs' => json_encode($pieceRefs, JSON_UNESCAPED_UNICODE),
        ':qr_payloads' => json_encode($qrPayloads, JSON_UNESCAPED_UNICODE),
        ':recipient' => (string)($data['recipient'] ?? ''),
        ':phone' => (string)$data['phone'],
        ':raw_address' => (string)($data['raw_address'] ?? ''),
        ':mahalle' => (string)$data['mahalle'],
        ':street' => (string)$data['street'],
        ':door_no' => (string)($data['door_no'] ?? ''),
        ':postcode' => (string)($data['postcode'] ?? ''),
        ':county' => (string)$data['county'],
        ':city' => (string)$data['city'],
        ':extra' => (string)($data['extra'] ?? ''),
        ':payload' => json_encode($data, JSON_UNESCAPED_UNICODE),
    ]);

    $logId = (int)$pdo->lastInsertId();

    $pieceStmt = $pdo->prepare("
        INSERT INTO shipping_label_pieces (
            label_log_id, invoice_ref, piece_ref, piece_no, piece_total, qr_payload
        ) VALUES (
            :label_log_id, :invoice_ref, :piece_ref, :piece_no, :piece_total, :qr_payload
        )
    ");

    foreach ($pieceRefs as $index => $pieceRef) {
        $pieceStmt->execute([
            ':label_log_id' => $logId,
            ':invoice_ref' => (string)$data['invoice_ref'],
            ':piece_ref' => (string)$pieceRef,
            ':piece_no' => $index + 1,
            ':piece_total' => (int)$data['piece_total'],
            ':qr_payload' => (string)($qrPayloads[$index] ?? ''),
        ]);
    }

    $pdo->commit();

    json_response([
        'ok' => true,
        'id' => $logId,
        'message' => 'Kargo etiketi ve parça refleri kaydedildi.',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response(['ok' => false, 'message' => 'DB kayıt hatası.'], 500);
}
