<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/crm.php';

$input = crm_input();
$action = (string)($_GET['action'] ?? $input['action'] ?? 'list');

try {
    $pdo = db();

    /* ---------------- LIST ---------------- */
    if ($action === 'list') {
        require_permission_api('crm.view');

        $q = trim((string)($_GET['q'] ?? $input['q'] ?? ''));
        $params = [];
        $where = 'WHERE 1=1';
        $where .= crm_scope('c.owner_user_id', $params);
        if ($q !== '') {
            $where .= " AND (c.name LIKE :q OR c.phone LIKE :q OR c.tax_no LIKE :q OR c.city LIKE :q OR c.email LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        $sql = "
            SELECT c.id, c.name, c.tax_office, c.tax_no, c.phone, c.email, c.city, c.county,
                   c.source, c.owner_user_id, c.created_at, c.updated_at,
                   u.full_name AS owner_name,
                   (SELECT COUNT(*) FROM contacts ct WHERE ct.company_id = c.id) AS contact_count
            FROM companies c
            LEFT JOIN users u ON u.id = c.owner_user_id
            {$where}
            ORDER BY c.name ASC
            LIMIT 300
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_response(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /* ---------------- GET (detail) ---------------- */
    if ($action === 'get') {
        require_permission_api('crm.view');

        $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
        if ($id <= 0) {
            json_response(['ok' => false, 'message' => 'Geçersiz ID.'], 422);
        }

        $stmt = $pdo->prepare("
            SELECT c.*, u.full_name AS owner_name
            FROM companies c LEFT JOIN users u ON u.id = c.owner_user_id
            WHERE c.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) {
            json_response(['ok' => false, 'message' => 'Firma bulunamadı.'], 404);
        }
        if (!crm_can_touch($company['owner_user_id'] !== null ? (int)$company['owner_user_id'] : null) && !crm_view_all()) {
            json_response(['ok' => false, 'message' => 'Bu kayda erişim yetkiniz yok.'], 403);
        }

        $cs = $pdo->prepare("SELECT id, full_name, title, phone, email FROM contacts WHERE company_id = :id ORDER BY full_name ASC");
        $cs->execute([':id' => $id]);
        $contacts = $cs->fetchAll(PDO::FETCH_ASSOC);

        $as = $pdo->prepare("
            SELECT a.type, a.subject, a.body, a.occurred_at, u.full_name AS user_name
            FROM activities a LEFT JOIN users u ON u.id = a.user_id
            WHERE a.related_type = 'company' AND a.related_id = :id
            ORDER BY a.occurred_at DESC, a.id DESC LIMIT 20
        ");
        $as->execute([':id' => $id]);
        $activities = $as->fetchAll(PDO::FETCH_ASSOC);

        json_response(['ok' => true, 'data' => ['company' => $company, 'contacts' => $contacts, 'activities' => $activities]]);
    }

    /* ---------------- SAVE (create/update) ---------------- */
    if ($action === 'save') {
        require_permission_api('crm.edit');

        $id = (int)($input['id'] ?? 0);
        $name = crm_str($input, 'name', 190);
        if ($name === '') {
            json_response(['ok' => false, 'message' => 'Firma adı zorunlu.'], 422);
        }

        $fields = [
            'name' => $name,
            'tax_office' => crm_str($input, 'tax_office', 120),
            'tax_no' => crm_str($input, 'tax_no', 40),
            'phone' => crm_str($input, 'phone', 60),
            'email' => crm_str($input, 'email', 190),
            'city' => crm_str($input, 'city', 120),
            'county' => crm_str($input, 'county', 120),
            'address' => crm_str($input, 'address', 2000),
            'source' => crm_str($input, 'source', 80),
        ];

        if ($id > 0) {
            $own = $pdo->prepare("SELECT owner_user_id FROM companies WHERE id = :id");
            $own->execute([':id' => $id]);
            $row = $own->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                json_response(['ok' => false, 'message' => 'Firma bulunamadı.'], 404);
            }
            if (!crm_can_touch($row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null)) {
                json_response(['ok' => false, 'message' => 'Bu kaydı düzenleme yetkiniz yok.'], 403);
            }

            $sets = [];
            foreach ($fields as $k => $v) {
                $sets[] = "`{$k}` = :{$k}";
            }
            $fields['id'] = $id;
            $pdo->prepare("UPDATE companies SET " . implode(', ', $sets) . " WHERE id = :id")->execute($fields);
            crm_log($pdo, 'system', 'Firma güncellendi', $name, 'company', $id);
            json_response(['ok' => true, 'message' => 'Firma güncellendi.', 'data' => ['id' => $id]]);
        }

        $fields['owner_user_id'] = crm_uid();
        $fields['created_by'] = crm_uid();
        $cols = array_keys($fields);
        $ph = array_map(static fn($c) => ':' . $c, $cols);
        $pdo->prepare("INSERT INTO companies (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $ph) . ")")
            ->execute($fields);
        $newId = (int)$pdo->lastInsertId();
        crm_log($pdo, 'system', 'Firma eklendi', $name, 'company', $newId);
        json_response(['ok' => true, 'message' => 'Firma eklendi.', 'data' => ['id' => $newId]]);
    }

    /* ---------------- DELETE ---------------- */
    if ($action === 'delete') {
        require_permission_api('crm.delete');

        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            json_response(['ok' => false, 'message' => 'Geçersiz ID.'], 422);
        }
        $own = $pdo->prepare("SELECT owner_user_id, name FROM companies WHERE id = :id");
        $own->execute([':id' => $id]);
        $row = $own->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['ok' => false, 'message' => 'Firma bulunamadı.'], 404);
        }
        if (!crm_can_touch($row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null)) {
            json_response(['ok' => false, 'message' => 'Bu kaydı silme yetkiniz yok.'], 403);
        }

        // Kişileri silme; yalnızca firma bağını kopar (veri kaybı olmasın).
        $pdo->prepare("UPDATE contacts SET company_id = NULL WHERE company_id = :id")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM companies WHERE id = :id")->execute([':id' => $id]);
        crm_log($pdo, 'system', 'Firma silindi', (string)$row['name'], 'company', $id);
        json_response(['ok' => true, 'message' => 'Firma silindi.']);
    }

    json_response(['ok' => false, 'message' => 'Geçersiz action.'], 400);
} catch (Throwable $e) {
    error_log('[crm_companies] ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Firma API hatası.'], 500);
}
