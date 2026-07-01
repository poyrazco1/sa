<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$input = request_json();
$action = $_GET['action'] ?? ($input['action'] ?? '');

function ensure_customer_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS label_customers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            customer_name VARCHAR(190) NULL,
            company_name VARCHAR(190) NULL,
            phone VARCHAR(60) NULL,
            note VARCHAR(255) NULL,
            raw_address TEXT NULL,
            mahalle VARCHAR(160) NULL,
            street VARCHAR(190) NULL,
            door_no VARCHAR(80) NULL,
            postcode VARCHAR(20) NULL,
            county VARCHAR(120) NULL,
            city VARCHAR(120) NULL,
            extra VARCHAR(255) NULL,
            PRIMARY KEY (id),
            KEY idx_customer_name (customer_name),
            KEY idx_company_name (company_name),
            KEY idx_phone (phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS shipping_label_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(100) NULL,
            customer_id VARCHAR(80) NULL,
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
}

function normalize_customer_rows(array $rows): array
{
    $seen = [];
    $out = [];

    foreach ($rows as $row) {
        $key = mb_strtolower(trim(($row['phone'] ?? '') . '|' . ($row['company_name'] ?? '') . '|' . ($row['customer_name'] ?? '') . '|' . ($row['mahalle'] ?? '') . '|' . ($row['street'] ?? '')), 'UTF-8');
        if ($key !== '||||' && isset($seen[$key])) {
            continue;
        }

        if ($key !== '||||') {
            $seen[$key] = true;
        }

        $out[] = $row;
    }

    return array_slice($out, 0, 30);
}

try {
    $pdo = db();
    ensure_customer_tables($pdo);

    if ($action === 'search') {
        $q = trim((string)($input['q'] ?? ''));

        if ($q === '') {
            $sql = "
                SELECT CAST(id AS CHAR) AS id, customer_name, company_name, phone, note, raw_address, mahalle, street, door_no, postcode, county, city, extra,
                       'Müşteri kaydı' AS source_label, created_at, updated_at
                FROM label_customers
                UNION ALL
                SELECT CONCAT('log-', id) AS id, recipient AS customer_name, recipient AS company_name, phone, invoice_ref AS note, raw_address, mahalle, street, door_no, postcode, county, city, extra,
                       'Eski gönderi kaydı' AS source_label, created_at, created_at AS updated_at
                FROM shipping_label_logs
                ORDER BY updated_at DESC
                LIMIT 30
            ";
            $stmt = $pdo->query($sql);
            json_response(['ok' => true, 'customers' => normalize_customer_rows($stmt->fetchAll())]);
        }

        $like = '%' . $q . '%';
        $sql = "
            SELECT CAST(id AS CHAR) AS id, customer_name, company_name, phone, note, raw_address, mahalle, street, door_no, postcode, county, city, extra,
                   'Müşteri kaydı' AS source_label, created_at, updated_at
            FROM label_customers
            WHERE customer_name LIKE :q1
               OR company_name LIKE :q2
               OR phone LIKE :q3
               OR note LIKE :q4
               OR raw_address LIKE :q5
               OR mahalle LIKE :q6
               OR street LIKE :q7
               OR county LIKE :q8
               OR city LIKE :q9

            UNION ALL

            SELECT CONCAT('log-', id) AS id, recipient AS customer_name, recipient AS company_name, phone, invoice_ref AS note, raw_address, mahalle, street, door_no, postcode, county, city, extra,
                   'Eski gönderi kaydı' AS source_label, created_at, created_at AS updated_at
            FROM shipping_label_logs
            WHERE recipient LIKE :q10
               OR phone LIKE :q11
               OR invoice_ref LIKE :q12
               OR raw_address LIKE :q13
               OR mahalle LIKE :q14
               OR street LIKE :q15
               OR county LIKE :q16
               OR city LIKE :q17

            ORDER BY updated_at DESC
            LIMIT 50
        ";

        $params = [];
        for ($i = 1; $i <= 17; $i++) {
            $params[":q{$i}"] = $like;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        json_response(['ok' => true, 'customers' => normalize_customer_rows($stmt->fetchAll())]);
    }

    if ($action === 'save') {
        $rawId = (string)($input['id'] ?? '');
        $id = is_numeric($rawId) ? (int)$rawId : 0;

        $fields = [
            'customer_name', 'company_name', 'phone', 'note', 'raw_address', 'mahalle', 'street',
            'door_no', 'postcode', 'county', 'city', 'extra',
        ];

        $data = [];
        foreach ($fields as $field) {
            $data[$field] = trim((string)($input[$field] ?? ''));
        }

        if ($data['customer_name'] === '' && $data['company_name'] === '') {
            $data['customer_name'] = trim((string)($input['recipient'] ?? ''));
        }

        if ($data['customer_name'] === '' && $data['company_name'] === '') {
            json_response(['ok' => false, 'message' => 'Müşteri adı veya firma adı eksik.'], 422);
        }

        if ($id > 0) {
            $sets = [];
            foreach ($fields as $field) {
                $sets[] = "{$field} = :{$field}";
            }

            $data['id'] = $id;
            $stmt = $pdo->prepare("UPDATE label_customers SET " . implode(', ', $sets) . " WHERE id = :id");
            $stmt->execute($data);
        } else {
            $columns = implode(', ', $fields);
            $params = ':' . implode(', :', $fields);
            $stmt = $pdo->prepare("INSERT INTO label_customers ({$columns}) VALUES ({$params})");
            $stmt->execute($data);
            $id = (int)$pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("
            SELECT CAST(id AS CHAR) AS id, customer_name, company_name, phone, note, raw_address, mahalle, street, door_no, postcode, county, city, extra,
                   'Müşteri kaydı' AS source_label, created_at, updated_at
            FROM label_customers
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);

        json_response(['ok' => true, 'customer' => $stmt->fetch()]);
    }


    if ($action === 'delete') {
        $id = isset($input['id']) && is_numeric($input['id']) ? (int)$input['id'] : 0;

        if ($id <= 0) {
            json_response(['ok' => false, 'message' => 'Geçersiz müşteri ID.'], 422);
        }

        $stmt = $pdo->prepare("DELETE FROM label_customers WHERE id = :id");
        $stmt->execute([':id' => $id]);

        json_response([
            'ok' => true,
            'deleted' => $stmt->rowCount(),
            'message' => 'Müşteri silindi.'
        ]);
    }

    json_response(['ok' => false, 'message' => 'Geçersiz action.'], 400);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => 'DB hatası.'], 500);
}
