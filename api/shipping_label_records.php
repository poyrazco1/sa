<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$input = request_json();
$action = $_GET['action'] ?? ($input['action'] ?? '');

function ensure_shipping_label_tables(PDO $pdo): void
{
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
            KEY idx_carrier (carrier),
            KEY idx_recipient (recipient),
            KEY idx_phone (phone)
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

    $columns = $pdo->query("SHOW COLUMNS FROM shipping_label_logs")->fetchAll(PDO::FETCH_COLUMN);
    $add = [
        'customer_name' => "ALTER TABLE shipping_label_logs ADD COLUMN customer_name VARCHAR(190) NULL AFTER customer_id",
        'company_name' => "ALTER TABLE shipping_label_logs ADD COLUMN company_name VARCHAR(190) NULL AFTER customer_name",
    ];

    foreach ($add as $column => $sql) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec($sql);
        }
    }
}

function decode_payload_row(array $row): array
{
    $payload = json_decode((string)($row['payload'] ?? ''), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $payload['customer_name'] = $payload['customer_name'] ?? ($row['customer_name'] ?? '');
    $payload['company_name'] = $payload['company_name'] ?? ($row['company_name'] ?? '');
    $payload['recipient'] = $payload['recipient'] ?? ($row['recipient'] ?? '');

    $row['payload'] = $payload;
    return $row;
}

function payload_value(array $payload, string $key, mixed $default = ''): mixed
{
    return $payload[$key] ?? $default;
}

function save_label_payload(PDO $pdo, int $id, array $payload): void
{
    $pieceRefs = is_array($payload['piece_refs'] ?? null) ? $payload['piece_refs'] : [];
    $qrPayloads = is_array($payload['qr_payloads'] ?? null) ? $payload['qr_payloads'] : [];

    $stmt = $pdo->prepare("
        UPDATE shipping_label_logs SET
            created_by = :created_by,
            customer_id = :customer_id,
            customer_name = :customer_name,
            company_name = :company_name,
            address_mode = :address_mode,
            carrier = :carrier,
            carrier_label = :carrier_label,
            cargo_agreement_code = :cargo_agreement_code,
            sender_name = :sender_name,
            sender_address = :sender_address,
            sender_phone = :sender_phone,
            logo_mode = :logo_mode,
            payment_type = :payment_type,
            payment_label = :payment_label,
            paper = :paper,
            paper_label = :paper_label,
            invoice_ref = :invoice_ref,
            piece_total = :piece_total,
            piece_refs = :piece_refs,
            qr_payloads = :qr_payloads,
            recipient = :recipient,
            phone = :phone,
            raw_address = :raw_address,
            mahalle = :mahalle,
            street = :street,
            door_no = :door_no,
            postcode = :postcode,
            county = :county,
            city = :city,
            extra = :extra,
            payload = :payload
        WHERE id = :id
    ");

    $stmt->execute([
        ':id' => $id,
        ':created_by' => (string)payload_value($payload, 'created_by'),
        ':customer_id' => (string)payload_value($payload, 'customer_id'),
        ':customer_name' => (string)payload_value($payload, 'customer_name'),
        ':company_name' => (string)payload_value($payload, 'company_name'),
        ':address_mode' => (string)payload_value($payload, 'address_mode'),
        ':carrier' => (string)payload_value($payload, 'carrier'),
        ':carrier_label' => (string)payload_value($payload, 'carrier_label'),
        ':cargo_agreement_code' => (string)payload_value($payload, 'cargo_agreement_code'),
        ':sender_name' => (string)payload_value($payload, 'sender_name'),
        ':sender_address' => (string)payload_value($payload, 'sender_address'),
        ':sender_phone' => (string)payload_value($payload, 'sender_phone'),
        ':logo_mode' => (string)payload_value($payload, 'logo_mode'),
        ':payment_type' => (string)payload_value($payload, 'payment_type'),
        ':payment_label' => (string)payload_value($payload, 'payment_label'),
        ':paper' => (string)payload_value($payload, 'paper'),
        ':paper_label' => (string)payload_value($payload, 'paper_label'),
        ':invoice_ref' => (string)payload_value($payload, 'invoice_ref'),
        ':piece_total' => (int)payload_value($payload, 'piece_total', 1),
        ':piece_refs' => json_encode($pieceRefs, JSON_UNESCAPED_UNICODE),
        ':qr_payloads' => json_encode($qrPayloads, JSON_UNESCAPED_UNICODE),
        ':recipient' => (string)payload_value($payload, 'recipient'),
        ':phone' => (string)payload_value($payload, 'phone'),
        ':raw_address' => (string)payload_value($payload, 'raw_address'),
        ':mahalle' => (string)payload_value($payload, 'mahalle'),
        ':street' => (string)payload_value($payload, 'street'),
        ':door_no' => (string)payload_value($payload, 'door_no'),
        ':postcode' => (string)payload_value($payload, 'postcode'),
        ':county' => (string)payload_value($payload, 'county'),
        ':city' => (string)payload_value($payload, 'city'),
        ':extra' => (string)payload_value($payload, 'extra'),
        ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    $pdo->prepare("DELETE FROM shipping_label_pieces WHERE label_log_id = :id")->execute([':id' => $id]);

    $pieceStmt = $pdo->prepare("
        INSERT INTO shipping_label_pieces (
            label_log_id, invoice_ref, piece_ref, piece_no, piece_total, qr_payload
        ) VALUES (
            :label_log_id, :invoice_ref, :piece_ref, :piece_no, :piece_total, :qr_payload
        )
    ");

    foreach ($pieceRefs as $index => $pieceRef) {
        $pieceStmt->execute([
            ':label_log_id' => $id,
            ':invoice_ref' => (string)payload_value($payload, 'invoice_ref'),
            ':piece_ref' => (string)$pieceRef,
            ':piece_no' => $index + 1,
            ':piece_total' => (int)payload_value($payload, 'piece_total', 1),
            ':qr_payload' => (string)($qrPayloads[$index] ?? ''),
        ]);
    }
}

try {
    $pdo = db();
    ensure_shipping_label_tables($pdo);

    if ($action === 'list') {
        $q = trim((string)($_GET['q'] ?? $input['q'] ?? ''));
        $limit = 80;

        if ($q === '') {
            $stmt = $pdo->prepare("
                SELECT id, created_at, created_by, customer_name, company_name, recipient, phone,
                       invoice_ref, carrier, carrier_label, paper, paper_label, piece_total,
                       mahalle, street, door_no, county, city
                FROM shipping_label_logs
                ORDER BY id DESC
                LIMIT {$limit}
            ");
            $stmt->execute();
        } else {
            $like = '%' . $q . '%';
            $stmt = $pdo->prepare("
                SELECT id, created_at, created_by, customer_name, company_name, recipient, phone,
                       invoice_ref, carrier, carrier_label, paper, paper_label, piece_total,
                       mahalle, street, door_no, county, city
                FROM shipping_label_logs
                WHERE customer_name LIKE :q
                   OR company_name LIKE :q
                   OR recipient LIKE :q
                   OR phone LIKE :q
                   OR invoice_ref LIKE :q
                   OR mahalle LIKE :q
                   OR street LIKE :q
                   OR county LIKE :q
                   OR city LIKE :q
                ORDER BY id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([':q' => $like]);
        }

        json_response(['ok' => true, 'records' => $stmt->fetchAll()]);
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
        if ($id <= 0) {
            json_response(['ok' => false, 'message' => 'Geçersiz ID.'], 422);
        }

        $stmt = $pdo->prepare("SELECT * FROM shipping_label_logs WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            json_response(['ok' => false, 'message' => 'Kayıt bulunamadı.'], 404);
        }

        json_response(['ok' => true, 'record' => decode_payload_row($row)]);
    }

    if ($action === 'update') {
        $id = (int)($input['id'] ?? 0);
        $payload = $input['payload'] ?? null;

        if ($id <= 0 || !is_array($payload)) {
            json_response(['ok' => false, 'message' => 'Güncelleme için ID ve payload gerekli.'], 422);
        }

        $pdo->beginTransaction();
        save_label_payload($pdo, $id, $payload);
        $pdo->commit();

        json_response(['ok' => true, 'id' => $id, 'message' => 'Kargo etiketi güncellendi.']);
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);

        if ($id <= 0) {
            json_response(['ok' => false, 'message' => 'Geçersiz ID.'], 422);
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM shipping_label_pieces WHERE label_log_id = :id")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM shipping_label_logs WHERE id = :id")->execute([':id' => $id]);
        $pdo->commit();

        json_response(['ok' => true, 'message' => 'Kayıt silindi.']);
    }

    json_response(['ok' => false, 'message' => 'Geçersiz action.'], 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response(['ok' => false, 'message' => 'Kargo kayıt API hatası.'], 500);
}
