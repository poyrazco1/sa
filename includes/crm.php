<?php
declare(strict_types=1);

/**
 * CRM ortak yardımcıları: sahiplik/kapsam (RBAC), aktivite kaydı, giriş temizleme.
 * API uçları (api/crm_*.php) bunu kullanır. Tüm DB erişimi prepared statement iledir.
 */

/** Giriş yapan kullanıcının id'si (yoksa null). */
function crm_uid(): ?int
{
    $u = function_exists('auth_user') ? auth_user() : null;
    return $u ? (int)$u['id'] : null;
}

/** Kullanıcı tüm kayıtları görebilir mi? (admin/manager). Auth kapalıysa (legacy) evet. */
function crm_view_all(): bool
{
    if (!function_exists('auth_system_available') || !auth_system_available()) {
        return true;
    }
    return function_exists('user_can') && user_can('records.view_all');
}

/**
 * Sahiplik kapsamı WHERE parçası. view_all değilse yalnızca kendi kayıtları.
 * $col sabit kolon adıdır (kullanıcı girdisi değil).
 */
function crm_scope(string $col, array &$params): string
{
    if (crm_view_all()) {
        return '';
    }
    $uid = crm_uid();
    if ($uid === null) {
        return '';
    }
    $params[':scope_uid'] = $uid;
    return " AND {$col} = :scope_uid ";
}

/** Bir satırı (sahibine göre) düzenleme/silme yetkisi var mı? */
function crm_can_touch(?int $ownerId): bool
{
    if (crm_view_all()) {
        return true;
    }
    $uid = crm_uid();
    return $uid !== null && ($ownerId === null || (int)$ownerId === $uid);
}

/** Aktivite/zaman tüneli kaydı (best-effort; hata ana işlemi bozmaz). */
function crm_log(PDO $pdo, string $type, string $subject, string $body, string $relatedType, ?int $relatedId): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activities (type, subject, body, related_type, related_id, user_id, occurred_at)
            VALUES (:t, :s, :b, :rt, :ri, :uid, NOW())
        ");
        $stmt->execute([
            ':t' => $type,
            ':s' => mb_substr($subject, 0, 250),
            ':b' => $body,
            ':rt' => $relatedType,
            ':ri' => $relatedId,
            ':uid' => crm_uid(),
        ]);
    } catch (Throwable $e) {
        // yut
    }
}

/** JSON gövde alanını temizle (trim + uzunluk sınırı). */
function crm_str(array $src, string $key, int $max = 255): string
{
    $v = isset($src[$key]) ? trim((string)$src[$key]) : '';
    return mb_substr($v, 0, $max);
}

/** Değeri izinli listeye kısıtla; değilse varsayılan. */
function crm_enum(array $src, string $key, array $allowed, string $default): string
{
    $v = isset($src[$key]) ? trim((string)$src[$key]) : '';
    return in_array($v, $allowed, true) ? $v : $default;
}

/** Ondalık sayı (nokta/virgül toleranslı); boşsa null. */
function crm_decimal(array $src, string $key): ?float
{
    if (!isset($src[$key]) || trim((string)$src[$key]) === '') {
        return null;
    }
    $v = str_replace([' ', ','], ['', '.'], (string)$src[$key]);
    return is_numeric($v) ? (float)$v : null;
}

/** 0-100 arası tam sayı olasılık; boşsa null. */
function crm_prob(array $src, string $key): ?int
{
    $v = crm_decimal($src, $key);
    if ($v === null) {
        return null;
    }
    return max(0, min(100, (int)round($v)));
}

/** Tarih (YYYY-MM-DD) doğrula; geçersizse null. */
function crm_date(array $src, string $key): ?string
{
    $v = isset($src[$key]) ? trim((string)$src[$key]) : '';
    if ($v === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

/** İsteğin durum-değiştiren gövdesini oku (JSON). */
function crm_input(): array
{
    if (function_exists('request_json')) {
        return request_json();
    }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}
