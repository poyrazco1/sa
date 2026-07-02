<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/crm.php';

if (function_exists('require_permission_api')) {
    require_permission_api('admin.users');
}

$input = crm_input();
$action = (string)($_GET['action'] ?? $input['action'] ?? 'list');

/** Aktif admin sayısı (kilitlenmeyi önlemek için). */
function active_admin_count(PDO $pdo, int $excludeId = 0): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_code = 'admin' AND is_active = 1 AND id <> :ex");
    $stmt->execute([':ex' => $excludeId]);
    return (int)$stmt->fetchColumn();
}

try {
    $pdo = db();

    if ($action === 'roles') {
        $rows = $pdo->query("SELECT code, name FROM roles ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
        json_response(['ok' => true, 'data' => $rows]);
    }

    if ($action === 'list') {
        $rows = $pdo->query("
            SELECT u.id, u.username, u.email, u.full_name, u.role_code, u.is_active,
                   u.must_change_password, u.last_login_at, u.created_at,
                   r.name AS role_name
            FROM users u LEFT JOIN roles r ON r.code = u.role_code
            ORDER BY u.username ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        json_response(['ok' => true, 'data' => $rows]);
    }

    if ($action === 'save') {
        $id = (int)($input['id'] ?? 0);
        $username = mb_substr(trim((string)($input['username'] ?? '')), 0, 80);
        $fullName = mb_substr(trim((string)($input['full_name'] ?? '')), 0, 160);
        $email = mb_substr(trim((string)($input['email'] ?? '')), 0, 190);
        $roleCode = (string)($input['role_code'] ?? 'sales');
        $isActive = !empty($input['is_active']) ? 1 : 0;
        $password = (string)($input['password'] ?? '');

        if ($username === '') {
            json_response(['ok' => false, 'message' => 'Kullanıcı adı zorunlu.'], 422);
        }
        // rol geçerli mi?
        $roleOk = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE code = :c");
        $roleOk->execute([':c' => $roleCode]);
        if ((int)$roleOk->fetchColumn() === 0) {
            json_response(['ok' => false, 'message' => 'Geçersiz rol.'], 422);
        }

        // username benzersiz mi?
        $dup = $pdo->prepare("SELECT id FROM users WHERE username = :u AND id <> :id");
        $dup->execute([':u' => $username, ':id' => $id]);
        if ($dup->fetch()) {
            json_response(['ok' => false, 'message' => 'Bu kullanıcı adı zaten var.'], 422);
        }

        if ($id > 0) {
            $cur = $pdo->prepare("SELECT role_code, is_active FROM users WHERE id = :id");
            $cur->execute([':id' => $id]);
            $existing = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                json_response(['ok' => false, 'message' => 'Kullanıcı bulunamadı.'], 404);
            }
            // Son admini pasifleştirme/demote koruması
            $wasAdminActive = ($existing['role_code'] === 'admin' && (int)$existing['is_active'] === 1);
            $willBeAdminActive = ($roleCode === 'admin' && $isActive === 1);
            if ($wasAdminActive && !$willBeAdminActive && active_admin_count($pdo, $id) === 0) {
                json_response(['ok' => false, 'message' => 'Son aktif admin pasifleştirilemez.'], 409);
            }

            if ($password !== '') {
                if (strlen($password) < 8) {
                    json_response(['ok' => false, 'message' => 'Parola en az 8 karakter olmalı.'], 422);
                }
                $pdo->prepare("UPDATE users SET username=:u, full_name=:f, email=:e, role_code=:r, is_active=:a, password_hash=:p WHERE id=:id")
                    ->execute([':u' => $username, ':f' => $fullName, ':e' => $email ?: null, ':r' => $roleCode, ':a' => $isActive, ':p' => password_hash($password, PASSWORD_BCRYPT), ':id' => $id]);
            } else {
                $pdo->prepare("UPDATE users SET username=:u, full_name=:f, email=:e, role_code=:r, is_active=:a WHERE id=:id")
                    ->execute([':u' => $username, ':f' => $fullName, ':e' => $email ?: null, ':r' => $roleCode, ':a' => $isActive, ':id' => $id]);
            }
            crm_log($pdo, 'system', 'Kullanıcı güncellendi', $username, 'user', $id);
            json_response(['ok' => true, 'message' => 'Kullanıcı güncellendi.']);
        }

        if (strlen($password) < 8) {
            json_response(['ok' => false, 'message' => 'Yeni kullanıcı için en az 8 karakterli parola gerekli.'], 422);
        }
        $pdo->prepare("INSERT INTO users (username, full_name, email, role_code, is_active, password_hash, must_change_password) VALUES (:u,:f,:e,:r,:a,:p,0)")
            ->execute([':u' => $username, ':f' => $fullName, ':e' => $email ?: null, ':r' => $roleCode, ':a' => $isActive, ':p' => password_hash($password, PASSWORD_BCRYPT)]);
        $newId = (int)$pdo->lastInsertId();
        crm_log($pdo, 'system', 'Kullanıcı oluşturuldu', $username, 'user', $newId);
        json_response(['ok' => true, 'message' => 'Kullanıcı eklendi.', 'data' => ['id' => $newId]]);
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            json_response(['ok' => false, 'message' => 'Geçersiz ID.'], 422);
        }
        $me = function_exists('auth_user') ? auth_user() : null;
        if ($me && (int)$me['id'] === $id) {
            json_response(['ok' => false, 'message' => 'Kendi hesabınızı silemezsiniz.'], 409);
        }
        $cur = $pdo->prepare("SELECT role_code, is_active, username FROM users WHERE id = :id");
        $cur->execute([':id' => $id]);
        $u = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            json_response(['ok' => false, 'message' => 'Kullanıcı bulunamadı.'], 404);
        }
        if ($u['role_code'] === 'admin' && (int)$u['is_active'] === 1 && active_admin_count($pdo, $id) === 0) {
            json_response(['ok' => false, 'message' => 'Son aktif admin silinemez.'], 409);
        }
        $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $id]);
        crm_log($pdo, 'system', 'Kullanıcı silindi', (string)$u['username'], 'user', $id);
        json_response(['ok' => true, 'message' => 'Kullanıcı silindi.']);
    }

    json_response(['ok' => false, 'message' => 'Geçersiz action.'], 400);
} catch (Throwable $e) {
    error_log('[users_api] ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Kullanıcı API hatası.'], 500);
}
