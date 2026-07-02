<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/crm.php';

const LEAD_STATUSES = ['new', 'contacted', 'qualified', 'won', 'lost'];

$input = crm_input();
$action = (string)($_GET['action'] ?? $input['action'] ?? 'list');

try {
    $pdo = db();

    if ($action === 'list') {
        require_permission_api('crm.view');
        $q = trim((string)($_GET['q'] ?? $input['q'] ?? ''));
        $status = (string)($_GET['status'] ?? $input['status'] ?? '');
        $params = [];
        $where = 'WHERE 1=1';
        $where .= crm_scope('l.owner_user_id', $params);
        if (in_array($status, LEAD_STATUSES, true)) {
            $where .= " AND l.status = :status";
            $params[':status'] = $status;
        }
        if ($q !== '') {
            $where .= " AND (l.title LIKE :q OR l.contact_name LIKE :q OR l.phone LIKE :q OR l.email LIKE :q OR co.name LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        $sql = "
            SELECT l.id, l.title, l.company_id, l.contact_name, l.phone, l.email, l.source,
                   l.status, l.est_value, l.currency, l.owner_user_id, l.created_at, l.updated_at,
                   co.name AS company_name, u.full_name AS owner_name
            FROM leads l
            LEFT JOIN companies co ON co.id = l.company_id
            LEFT JOIN users u ON u.id = l.owner_user_id
            {$where}
            ORDER BY FIELD(l.status,'new','contacted','qualified','won','lost'), l.updated_at DESC, l.id DESC
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
        $stmt = $pdo->prepare("SELECT l.*, co.name AS company_name FROM leads l LEFT JOIN companies co ON co.id = l.company_id WHERE l.id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['ok' => false, 'message' => 'Lead bulunamadı.'], 404);
        }
        json_response(['ok' => true, 'data' => $row]);
    }

    if ($action === 'save') {
        require_permission_api('crm.edit');
        $id = (int)($input['id'] ?? 0);
        $title = crm_str($input, 'title', 190);
        if ($title === '') {
            json_response(['ok' => false, 'message' => 'Başlık zorunlu.'], 422);
        }
        $companyId = (int)($input['company_id'] ?? 0);
        $fields = [
            'title' => $title,
            'company_id' => $companyId > 0 ? $companyId : null,
            'contact_name' => crm_str($input, 'contact_name', 190),
            'phone' => crm_str($input, 'phone', 60),
            'email' => crm_str($input, 'email', 190),
            'source' => crm_str($input, 'source', 80),
            'status' => crm_enum($input, 'status', LEAD_STATUSES, 'new'),
            'est_value' => crm_decimal($input, 'est_value'),
            'note' => crm_str($input, 'note', 2000),
        ];

        if ($id > 0) {
            $own = $pdo->prepare("SELECT owner_user_id FROM leads WHERE id = :id");
            $own->execute([':id' => $id]);
            $row = $own->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                json_response(['ok' => false, 'message' => 'Lead bulunamadı.'], 404);
            }
            if (!crm_can_touch($row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null)) {
                json_response(['ok' => false, 'message' => 'Bu kaydı düzenleme yetkiniz yok.'], 403);
            }
            $sets = [];
            foreach ($fields as $k => $v) {
                $sets[] = "`{$k}` = :{$k}";
            }
            $fields['id'] = $id;
            $pdo->prepare("UPDATE leads SET " . implode(', ', $sets) . " WHERE id = :id")->execute($fields);
            crm_log($pdo, 'system', 'Lead güncellendi', $title, 'lead', $id);
            json_response(['ok' => true, 'message' => 'Lead güncellendi.', 'data' => ['id' => $id]]);
        }

        $fields['owner_user_id'] = crm_uid();
        $cols = array_keys($fields);
        $ph = array_map(static fn($c) => ':' . $c, $cols);
        $pdo->prepare("INSERT INTO leads (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $ph) . ")")
            ->execute($fields);
        $newId = (int)$pdo->lastInsertId();
        crm_log($pdo, 'system', 'Lead eklendi', $title, 'lead', $newId);
        json_response(['ok' => true, 'message' => 'Lead eklendi.', 'data' => ['id' => $newId]]);
    }

    if ($action === 'convert') {
        require_permission_api('crm.edit');
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            json_response(['ok' => false, 'message' => 'Geçersiz ID.'], 422);
        }
        $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lead) {
            json_response(['ok' => false, 'message' => 'Lead bulunamadı.'], 404);
        }
        if (!crm_can_touch($lead['owner_user_id'] !== null ? (int)$lead['owner_user_id'] : null)) {
            json_response(['ok' => false, 'message' => 'Bu kaydı dönüştürme yetkiniz yok.'], 403);
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("
                INSERT INTO opportunities (title, company_id, stage, amount, currency, owner_user_id, lead_id, note)
                VALUES (:title, :company_id, 'new', :amount, :currency, :owner, :lead_id, :note)
            ");
            $ins->execute([
                ':title' => (string)$lead['title'],
                ':company_id' => $lead['company_id'] !== null ? (int)$lead['company_id'] : null,
                ':amount' => $lead['est_value'] !== null ? (float)$lead['est_value'] : null,
                ':currency' => (string)($lead['currency'] ?? 'TL'),
                ':owner' => $lead['owner_user_id'] !== null ? (int)$lead['owner_user_id'] : crm_uid(),
                ':lead_id' => $id,
                ':note' => (string)($lead['note'] ?? ''),
            ]);
            $oppId = (int)$pdo->lastInsertId();

            // Lead'i "nitelikli" olarak işaretle (yeni/iletişim aşamasındaysa).
            if (in_array((string)$lead['status'], ['new', 'contacted'], true)) {
                $pdo->prepare("UPDATE leads SET status = 'qualified' WHERE id = :id")->execute([':id' => $id]);
            }
            crm_log($pdo, 'status_change', 'Lead fırsata çevrildi', (string)$lead['title'], 'lead', $id);
            crm_log($pdo, 'system', 'Fırsat oluşturuldu (lead dönüşümü)', (string)$lead['title'], 'opportunity', $oppId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        json_response(['ok' => true, 'message' => 'Lead fırsata çevrildi.', 'data' => ['opportunity_id' => $oppId]]);
    }

    if ($action === 'delete') {
        require_permission_api('crm.delete');
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            json_response(['ok' => false, 'message' => 'Geçersiz ID.'], 422);
        }
        $own = $pdo->prepare("SELECT owner_user_id, title FROM leads WHERE id = :id");
        $own->execute([':id' => $id]);
        $row = $own->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['ok' => false, 'message' => 'Lead bulunamadı.'], 404);
        }
        if (!crm_can_touch($row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null)) {
            json_response(['ok' => false, 'message' => 'Bu kaydı silme yetkiniz yok.'], 403);
        }
        $pdo->prepare("DELETE FROM leads WHERE id = :id")->execute([':id' => $id]);
        crm_log($pdo, 'system', 'Lead silindi', (string)$row['title'], 'lead', $id);
        json_response(['ok' => true, 'message' => 'Lead silindi.']);
    }

    json_response(['ok' => false, 'message' => 'Geçersiz action.'], 400);
} catch (Throwable $e) {
    error_log('[crm_leads] ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Lead API hatası.'], 500);
}
