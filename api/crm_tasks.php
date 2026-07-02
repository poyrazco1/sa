<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/crm.php';

const TASK_STATUSES = ['open', 'done'];
const TASK_PRIORITIES = ['low', 'normal', 'high'];

$input = crm_input();
$action = (string)($_GET['action'] ?? $input['action'] ?? 'list');

/** Görev kapsamı: view_all değilse atanan veya oluşturan kullanıcı. */
function task_scope(array &$params): string
{
    if (crm_view_all()) {
        return '';
    }
    $uid = crm_uid();
    if ($uid === null) {
        return '';
    }
    $params[':su'] = $uid;
    return " AND (t.assigned_user_id = :su OR t.created_by = :su) ";
}

try {
    $pdo = db();

    if ($action === 'users') {
        require_permission_api('crm.view');
        $rows = $pdo->query("SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name ASC, username ASC")->fetchAll(PDO::FETCH_ASSOC);
        json_response(['ok' => true, 'data' => $rows]);
    }

    if ($action === 'list') {
        require_permission_api('crm.view');
        $q = trim((string)($_GET['q'] ?? $input['q'] ?? ''));
        $status = (string)($_GET['status'] ?? $input['status'] ?? '');
        $params = [];
        $where = 'WHERE 1=1';
        $where .= task_scope($params);
        if (in_array($status, TASK_STATUSES, true)) {
            $where .= " AND t.status = :status";
            $params[':status'] = $status;
        }
        if ($q !== '') {
            $where .= " AND (t.title LIKE :q OR t.description LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        $sql = "
            SELECT t.id, t.title, t.description, t.status, t.priority, t.due_at, t.remind_at,
                   t.assigned_user_id, t.related_type, t.related_id, t.created_by,
                   t.completed_at, t.created_at, t.updated_at,
                   u.full_name AS assigned_name,
                   CASE WHEN t.status = 'open' AND t.due_at IS NOT NULL AND t.due_at < NOW() THEN 1 ELSE 0 END AS overdue
            FROM tasks t
            LEFT JOIN users u ON u.id = t.assigned_user_id
            {$where}
            ORDER BY t.status ASC, (t.due_at IS NULL) ASC, t.due_at ASC, t.id DESC
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
        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['ok' => false, 'message' => 'Görev bulunamadı.'], 404);
        }
        // related_company_id kolaylığı (form için).
        $row['related_company_id'] = ((string)($row['related_type'] ?? '') === 'company') ? (int)$row['related_id'] : 0;
        json_response(['ok' => true, 'data' => $row]);
    }

    if ($action === 'save') {
        require_permission_api('crm.edit');
        $id = (int)($input['id'] ?? 0);
        $title = crm_str($input, 'title', 255);
        if ($title === '') {
            json_response(['ok' => false, 'message' => 'Görev başlığı zorunlu.'], 422);
        }

        $relatedCompany = (int)($input['related_company_id'] ?? 0);
        $assigned = (int)($input['assigned_user_id'] ?? 0);
        $fields = [
            'title' => $title,
            'description' => crm_str($input, 'description', 4000),
            'status' => crm_enum($input, 'status', TASK_STATUSES, 'open'),
            'priority' => crm_enum($input, 'priority', TASK_PRIORITIES, 'normal'),
            'due_at' => crm_datetime($input, 'due_at'),
            'remind_at' => crm_datetime($input, 'remind_at'),
            'assigned_user_id' => $assigned > 0 ? $assigned : crm_uid(),
            'related_type' => $relatedCompany > 0 ? 'company' : null,
            'related_id' => $relatedCompany > 0 ? $relatedCompany : null,
        ];

        if ($id > 0) {
            $own = $pdo->prepare("SELECT assigned_user_id, created_by, status FROM tasks WHERE id = :id");
            $own->execute([':id' => $id]);
            $row = $own->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                json_response(['ok' => false, 'message' => 'Görev bulunamadı.'], 404);
            }
            if (!crm_view_all()) {
                $uid = crm_uid();
                $ok = $uid !== null && ((int)$row['assigned_user_id'] === $uid || (int)$row['created_by'] === $uid);
                if (!$ok) {
                    json_response(['ok' => false, 'message' => 'Bu görevi düzenleme yetkiniz yok.'], 403);
                }
            }
            // done'a geçiş
            $completedSet = '';
            if ((string)$row['status'] !== 'done' && $fields['status'] === 'done') {
                $completedSet = ', completed_at = NOW()';
            } elseif ($fields['status'] !== 'done') {
                $completedSet = ', completed_at = NULL';
            }
            $sets = [];
            foreach ($fields as $k => $v) {
                $sets[] = "`{$k}` = :{$k}";
            }
            $fields['id'] = $id;
            $pdo->prepare("UPDATE tasks SET " . implode(', ', $sets) . $completedSet . " WHERE id = :id")->execute($fields);
            crm_log($pdo, 'system', 'Görev güncellendi', $title, 'task', $id);
            json_response(['ok' => true, 'message' => 'Görev güncellendi.', 'data' => ['id' => $id]]);
        }

        $fields['created_by'] = crm_uid();
        $cols = array_keys($fields);
        $ph = array_map(static fn($c) => ':' . $c, $cols);
        $pdo->prepare("INSERT INTO tasks (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $ph) . ")")->execute($fields);
        $newId = (int)$pdo->lastInsertId();
        crm_log($pdo, 'system', 'Görev oluşturuldu', $title, 'task', $newId);
        json_response(['ok' => true, 'message' => 'Görev eklendi.', 'data' => ['id' => $newId]]);
    }

    if ($action === 'complete') {
        require_permission_api('crm.edit');
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            json_response(['ok' => false, 'message' => 'Geçersiz ID.'], 422);
        }
        $own = $pdo->prepare("SELECT assigned_user_id, created_by, title FROM tasks WHERE id = :id");
        $own->execute([':id' => $id]);
        $row = $own->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['ok' => false, 'message' => 'Görev bulunamadı.'], 404);
        }
        if (!crm_view_all()) {
            $uid = crm_uid();
            if (!($uid !== null && ((int)$row['assigned_user_id'] === $uid || (int)$row['created_by'] === $uid))) {
                json_response(['ok' => false, 'message' => 'Yetkiniz yok.'], 403);
            }
        }
        $pdo->prepare("UPDATE tasks SET status = 'done', completed_at = NOW() WHERE id = :id")->execute([':id' => $id]);
        crm_log($pdo, 'status_change', 'Görev tamamlandı', (string)$row['title'], 'task', $id);
        json_response(['ok' => true, 'message' => 'Görev tamamlandı.']);
    }

    if ($action === 'delete') {
        require_permission_api('crm.delete');
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            json_response(['ok' => false, 'message' => 'Geçersiz ID.'], 422);
        }
        $own = $pdo->prepare("SELECT assigned_user_id, created_by, title FROM tasks WHERE id = :id");
        $own->execute([':id' => $id]);
        $row = $own->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['ok' => false, 'message' => 'Görev bulunamadı.'], 404);
        }
        if (!crm_view_all()) {
            $uid = crm_uid();
            if (!($uid !== null && ((int)$row['assigned_user_id'] === $uid || (int)$row['created_by'] === $uid))) {
                json_response(['ok' => false, 'message' => 'Yetkiniz yok.'], 403);
            }
        }
        $pdo->prepare("DELETE FROM tasks WHERE id = :id")->execute([':id' => $id]);
        crm_log($pdo, 'system', 'Görev silindi', (string)$row['title'], 'task', $id);
        json_response(['ok' => true, 'message' => 'Görev silindi.']);
    }

    json_response(['ok' => false, 'message' => 'Geçersiz action.'], 400);
} catch (Throwable $e) {
    error_log('[crm_tasks] ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Görev API hatası.'], 500);
}
