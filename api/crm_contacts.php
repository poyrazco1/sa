<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/crm.php';

$input = crm_input();
$action = (string)($_GET['action'] ?? $input['action'] ?? 'list');

try {
    $pdo = db();

    if ($action === 'list') {
        require_permission_api('crm.view');

        $q = trim((string)($_GET['q'] ?? $input['q'] ?? ''));
        $companyId = (int)($_GET['company_id'] ?? $input['company_id'] ?? 0);
        $params = [];
        $where = 'WHERE 1=1';
        $where .= crm_scope('ct.owner_user_id', $params);
        if ($companyId > 0) {
            $where .= " AND ct.company_id = :cid";
            $params[':cid'] = $companyId;
        }
        if ($q !== '') {
            $where .= " AND (ct.full_name LIKE :q OR ct.phone LIKE :q OR ct.email LIKE :q OR ct.title LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        $sql = "
            SELECT ct.id, ct.company_id, ct.full_name, ct.title, ct.phone, ct.email, ct.note,
                   ct.owner_user_id, ct.created_at, ct.updated_at,
                   co.name AS company_name, u.full_name AS owner_name
            FROM contacts ct
            LEFT JOIN companies co ON co.id = ct.company_id
            LEFT JOIN users u ON u.id = ct.owner_user_id
            {$where}
            ORDER BY ct.full_name ASC
            LIMIT 300
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_response(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'get') {
        require_permission_api('crm.view');
        $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
        if ($id <= 0) {
            json_response(['ok' => false, 'message' => 'Geçersiz ID.'], 422);
        }
        $stmt = $pdo->prepare("
            SELECT ct.*, co.name AS company_name
            FROM contacts ct LEFT JOIN companies co ON co.id = ct.company_id
            WHERE ct.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['ok' => false, 'message' => 'Kişi bulunamadı.'], 404);
        }
        json_response(['ok' => true, 'data' => $row]);
    }

    if ($action === 'save') {
        require_permission_api('crm.edit');

        $id = (int)($input['id'] ?? 0);
        $name = crm_str($input, 'full_name', 190);
        if ($name === '') {
            json_response(['ok' => false, 'message' => 'Kişi adı zorunlu.'], 422);
        }
        $companyId = (int)($input['company_id'] ?? 0);

        $fields = [
            'company_id' => $companyId > 0 ? $companyId : null,
            'full_name' => $name,
            'title' => crm_str($input, 'title', 120),
            'phone' => crm_str($input, 'phone', 60),
            'email' => crm_str($input, 'email', 190),
            'note' => crm_str($input, 'note', 255),
        ];

        if ($id > 0) {
            $own = $pdo->prepare("SELECT owner_user_id FROM contacts WHERE id = :id");
            $own->execute([':id' => $id]);
            $row = $own->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                json_response(['ok' => false, 'message' => 'Kişi bulunamadı.'], 404);
            }
            if (!crm_can_touch($row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null)) {
                json_response(['ok' => false, 'message' => 'Bu kaydı düzenleme yetkiniz yok.'], 403);
            }
            $sets = [];
            foreach ($fields as $k => $v) {
                $sets[] = "`{$k}` = :{$k}";
            }
            $fields['id'] = $id;
            $pdo->prepare("UPDATE contacts SET " . implode(', ', $sets) . " WHERE id = :id")->execute($fields);
            crm_log($pdo, 'system', 'Kişi güncellendi', $name, 'contact', $id);
            json_response(['ok' => true, 'message' => 'Kişi güncellendi.', 'data' => ['id' => $id]]);
        }

        $fields['owner_user_id'] = crm_uid();
        $cols = array_keys($fields);
        $ph = array_map(static fn($c) => ':' . $c, $cols);
        $pdo->prepare("INSERT INTO contacts (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $ph) . ")")
            ->execute($fields);
        $newId = (int)$pdo->lastInsertId();
        crm_log($pdo, 'system', 'Kişi eklendi', $name, 'contact', $newId);
        json_response(['ok' => true, 'message' => 'Kişi eklendi.', 'data' => ['id' => $newId]]);
    }

    if ($action === 'delete') {
        require_permission_api('crm.delete');
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            json_response(['ok' => false, 'message' => 'Geçersiz ID.'], 422);
        }
        $own = $pdo->prepare("SELECT owner_user_id, full_name FROM contacts WHERE id = :id");
        $own->execute([':id' => $id]);
        $row = $own->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['ok' => false, 'message' => 'Kişi bulunamadı.'], 404);
        }
        if (!crm_can_touch($row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null)) {
            json_response(['ok' => false, 'message' => 'Bu kaydı silme yetkiniz yok.'], 403);
        }
        $pdo->prepare("DELETE FROM contacts WHERE id = :id")->execute([':id' => $id]);
        crm_log($pdo, 'system', 'Kişi silindi', (string)$row['full_name'], 'contact', $id);
        json_response(['ok' => true, 'message' => 'Kişi silindi.']);
    }

    json_response(['ok' => false, 'message' => 'Geçersiz action.'], 400);
} catch (Throwable $e) {
    error_log('[crm_contacts] ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Kişi API hatası.'], 500);
}
