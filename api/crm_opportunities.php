<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/crm.php';

const OPP_STAGES = ['new', 'qualified', 'proposal', 'won', 'lost'];

$input = crm_input();
$action = (string)($_GET['action'] ?? $input['action'] ?? 'list');

try {
    $pdo = db();

    if ($action === 'list') {
        require_permission_api('crm.view');
        $q = trim((string)($_GET['q'] ?? $input['q'] ?? ''));
        $stage = (string)($_GET['stage'] ?? $input['stage'] ?? '');
        $params = [];
        $where = 'WHERE 1=1';
        $where .= crm_scope('o.owner_user_id', $params);
        if (in_array($stage, OPP_STAGES, true)) {
            $where .= " AND o.stage = :stage";
            $params[':stage'] = $stage;
        }
        if ($q !== '') {
            $where .= " AND (o.title LIKE :q OR co.name LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        $sql = "
            SELECT o.id, o.title, o.company_id, o.stage, o.amount, o.currency, o.probability,
                   o.expected_close_date, o.owner_user_id, o.created_at, o.updated_at,
                   co.name AS company_name, u.full_name AS owner_name
            FROM opportunities o
            LEFT JOIN companies co ON co.id = o.company_id
            LEFT JOIN users u ON u.id = o.owner_user_id
            {$where}
            ORDER BY FIELD(o.stage,'new','qualified','proposal','won','lost'), o.updated_at DESC, o.id DESC
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
        $stmt = $pdo->prepare("SELECT o.*, co.name AS company_name FROM opportunities o LEFT JOIN companies co ON co.id = o.company_id WHERE o.id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['ok' => false, 'message' => 'Fırsat bulunamadı.'], 404);
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
            'stage' => crm_enum($input, 'stage', OPP_STAGES, 'new'),
            'amount' => crm_decimal($input, 'amount'),
            'probability' => crm_prob($input, 'probability'),
            'expected_close_date' => crm_date($input, 'expected_close_date'),
            'note' => crm_str($input, 'note', 2000),
        ];

        if ($id > 0) {
            $own = $pdo->prepare("SELECT owner_user_id, stage FROM opportunities WHERE id = :id");
            $own->execute([':id' => $id]);
            $row = $own->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                json_response(['ok' => false, 'message' => 'Fırsat bulunamadı.'], 404);
            }
            if (!crm_can_touch($row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null)) {
                json_response(['ok' => false, 'message' => 'Bu kaydı düzenleme yetkiniz yok.'], 403);
            }
            $sets = [];
            foreach ($fields as $k => $v) {
                $sets[] = "`{$k}` = :{$k}";
            }
            $fields['id'] = $id;
            $pdo->prepare("UPDATE opportunities SET " . implode(', ', $sets) . " WHERE id = :id")->execute($fields);
            if ((string)$row['stage'] !== $fields['stage']) {
                crm_log($pdo, 'status_change', 'Fırsat aşaması: ' . $fields['stage'], $title, 'opportunity', $id);
            } else {
                crm_log($pdo, 'system', 'Fırsat güncellendi', $title, 'opportunity', $id);
            }
            json_response(['ok' => true, 'message' => 'Fırsat güncellendi.', 'data' => ['id' => $id]]);
        }

        $fields['owner_user_id'] = crm_uid();
        $cols = array_keys($fields);
        $ph = array_map(static fn($c) => ':' . $c, $cols);
        $pdo->prepare("INSERT INTO opportunities (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $ph) . ")")
            ->execute($fields);
        $newId = (int)$pdo->lastInsertId();
        crm_log($pdo, 'system', 'Fırsat eklendi', $title, 'opportunity', $newId);
        json_response(['ok' => true, 'message' => 'Fırsat eklendi.', 'data' => ['id' => $newId]]);
    }

    if ($action === 'delete') {
        require_permission_api('crm.delete');
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            json_response(['ok' => false, 'message' => 'Geçersiz ID.'], 422);
        }
        $own = $pdo->prepare("SELECT owner_user_id, title FROM opportunities WHERE id = :id");
        $own->execute([':id' => $id]);
        $row = $own->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['ok' => false, 'message' => 'Fırsat bulunamadı.'], 404);
        }
        if (!crm_can_touch($row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null)) {
            json_response(['ok' => false, 'message' => 'Bu kaydı silme yetkiniz yok.'], 403);
        }
        $pdo->prepare("DELETE FROM opportunities WHERE id = :id")->execute([':id' => $id]);
        crm_log($pdo, 'system', 'Fırsat silindi', (string)$row['title'], 'opportunity', $id);
        json_response(['ok' => true, 'message' => 'Fırsat silindi.']);
    }

    json_response(['ok' => false, 'message' => 'Geçersiz action.'], 400);
} catch (Throwable $e) {
    error_log('[crm_opportunities] ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Fırsat API hatası.'], 500);
}
