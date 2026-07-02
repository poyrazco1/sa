<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/crm.php';

const NOTE_RELATED = ['company', 'contact', 'lead', 'opportunity', 'quote', 'task'];

$input = crm_input();
$action = (string)($_GET['action'] ?? $input['action'] ?? 'add');

try {
    $pdo = db();

    if ($action === 'add') {
        require_permission_api('crm.edit');
        $relatedType = crm_enum($input, 'related_type', NOTE_RELATED, '');
        $relatedId = (int)($input['related_id'] ?? 0);
        $body = trim((string)($input['body'] ?? ''));
        if ($relatedType === '' || $relatedId <= 0 || $body === '') {
            json_response(['ok' => false, 'message' => 'Not ve ilgili kayıt gerekli.'], 422);
        }
        $body = mb_substr($body, 0, 4000);

        $pdo->prepare("INSERT INTO notes (body, related_type, related_id, user_id) VALUES (:b, :rt, :ri, :uid)")
            ->execute([':b' => $body, ':rt' => $relatedType, ':ri' => $relatedId, ':uid' => crm_uid()]);
        // Zaman tüneline de düş.
        crm_log($pdo, 'note', 'Not eklendi', $body, $relatedType, $relatedId);

        json_response(['ok' => true, 'message' => 'Not eklendi.']);
    }

    if ($action === 'list') {
        require_permission_api('crm.view');
        $relatedType = crm_enum($_GET, 'related_type', NOTE_RELATED, '');
        $relatedId = (int)($_GET['related_id'] ?? 0);
        if ($relatedType === '' || $relatedId <= 0) {
            json_response(['ok' => false, 'message' => 'İlgili kayıt gerekli.'], 422);
        }
        $stmt = $pdo->prepare("
            SELECT n.id, n.body, n.created_at, u.full_name AS user_name
            FROM notes n LEFT JOIN users u ON u.id = n.user_id
            WHERE n.related_type = :rt AND n.related_id = :ri
            ORDER BY n.created_at DESC, n.id DESC LIMIT 50
        ");
        $stmt->execute([':rt' => $relatedType, ':ri' => $relatedId]);
        json_response(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    json_response(['ok' => false, 'message' => 'Geçersiz action.'], 400);
} catch (Throwable $e) {
    error_log('[crm_notes] ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Not API hatası.'], 500);
}
