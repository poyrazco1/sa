<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function ensure_label_customers(PDO $pdo): void
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
            KEY idx_phone (phone),
            KEY idx_city_county (city, county)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function csv_header_map(array $header): array
{
    $map = [];
    foreach ($header as $index => $name) {
        $key = mb_strtolower(trim((string)$name), 'UTF-8');
        $key = str_replace(["\xEF\xBB\xBF", ' ', '-', '.', 'ı'], ['', '_', '_', '', 'i'], $key);

        $aliases = [
            'alici_adi' => 'customer_name',
            'musteri_adi' => 'customer_name',
            'musteri' => 'customer_name',
            'customer' => 'customer_name',
            'customer_name' => 'customer_name',
            'firma' => 'company_name',
            'firma_adi' => 'company_name',
            'company' => 'company_name',
            'company_name' => 'company_name',
            'telefon' => 'phone',
            'tel' => 'phone',
            'phone' => 'phone',
            'not' => 'note',
            'note' => 'note',
            'adres' => 'raw_address',
            'address' => 'raw_address',
            'raw_address' => 'raw_address',
            'mahalle' => 'mahalle',
            'mah' => 'mahalle',
            'mh' => 'mahalle',
            'sokak' => 'street',
            'cadde' => 'street',
            'street' => 'street',
            'no' => 'door_no',
            'kapi_no' => 'door_no',
            'door_no' => 'door_no',
            'posta_kodu' => 'postcode',
            'postakodu' => 'postcode',
            'postcode' => 'postcode',
            'ilce' => 'county',
            'county' => 'county',
            'il' => 'city',
            'sehir' => 'city',
            'city' => 'city',
            'ek_adres' => 'extra',
            'extra' => 'extra',
        ];

        if (isset($aliases[$key])) {
            $map[$index] = $aliases[$key];
        }
    }

    return $map;
}

function normalize_phone(string $phone): string
{
    return trim(preg_replace('/\s+/', ' ', $phone));
}

$action = $_GET['action'] ?? '';

try {
    $pdo = db();
    ensure_label_customers($pdo);

    if ($action === 'export') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="musteri-listesi-' . date('Ymd-His') . '.csv"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'customer_name', 'company_name', 'phone', 'note', 'raw_address',
            'mahalle', 'street', 'door_no', 'postcode', 'county', 'city', 'extra'
        ], ';');

        $stmt = $pdo->query("
            SELECT customer_name, company_name, phone, note, raw_address,
                   mahalle, street, door_no, postcode, county, city, extra
            FROM label_customers
            ORDER BY id DESC
        ");

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, $row, ';');
        }

        fclose($out);
        exit;
    }

    if ($action === 'import') {
        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            json_response(['ok' => false, 'message' => 'CSV dosyası alınamadı.'], 422);
        }

        $handle = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$handle) {
            json_response(['ok' => false, 'message' => 'CSV dosyası açılamadı.'], 422);
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            json_response(['ok' => false, 'message' => 'CSV boş.'], 422);
        }

        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $header = fgetcsv($handle, 0, $delimiter);
        if (!$header) {
            json_response(['ok' => false, 'message' => 'CSV başlığı okunamadı.'], 422);
        }

        $map = csv_header_map($header);
        if (!$map) {
            json_response(['ok' => false, 'message' => 'CSV başlıkları tanınmadı.'], 422);
        }

        $fields = [
            'customer_name', 'company_name', 'phone', 'note', 'raw_address',
            'mahalle', 'street', 'door_no', 'postcode', 'county', 'city', 'extra'
        ];

        $inserted = 0;
        $updated = 0;

        $select = $pdo->prepare("
            SELECT id FROM label_customers
            WHERE phone = :phone AND phone <> ''
            ORDER BY id DESC
            LIMIT 1
        ");

        $insert = $pdo->prepare("
            INSERT INTO label_customers (
                customer_name, company_name, phone, note, raw_address,
                mahalle, street, door_no, postcode, county, city, extra
            ) VALUES (
                :customer_name, :company_name, :phone, :note, :raw_address,
                :mahalle, :street, :door_no, :postcode, :county, :city, :extra
            )
        ");

        $update = $pdo->prepare("
            UPDATE label_customers SET
                customer_name = :customer_name,
                company_name = :company_name,
                phone = :phone,
                note = :note,
                raw_address = :raw_address,
                mahalle = :mahalle,
                street = :street,
                door_no = :door_no,
                postcode = :postcode,
                county = :county,
                city = :city,
                extra = :extra
            WHERE id = :id
        ");

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $data = array_fill_keys($fields, '');

            foreach ($map as $index => $field) {
                $data[$field] = trim((string)($row[$index] ?? ''));
            }

            $data['phone'] = normalize_phone($data['phone']);

            if ($data['customer_name'] === '' && $data['company_name'] === '' && $data['phone'] === '') {
                continue;
            }

            $select->execute([':phone' => $data['phone']]);
            $existingId = $data['phone'] !== '' ? (int)($select->fetchColumn() ?: 0) : 0;

            if ($existingId > 0) {
                $updateData = $data;
                $updateData['id'] = $existingId;
                $update->execute($updateData);
                $updated++;
            } else {
                $insert->execute($data);
                $inserted++;
            }
        }

        fclose($handle);

        json_response([
            'ok' => true,
            'imported' => $inserted,
            'updated' => $updated,
            'message' => 'CSV aktarımı tamamlandı.',
        ]);
    }

    json_response(['ok' => false, 'message' => 'Geçersiz action.'], 400);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => 'CSV API hatası.'], 500);
}
