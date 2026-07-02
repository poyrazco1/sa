<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/crm.php';

// Ayarlar yalnızca admin.settings yetkisiyle.
if (function_exists('require_permission_api')) {
    require_permission_api('admin.settings');
}

const SETTING_KEYS = [
    'vat_rate' => 'decimal',
    'free_cargo_threshold_try' => 'decimal',
    'sarf_expense_usd' => 'decimal',
    'default_sender_name' => 'string',
    'default_sender_address' => 'string',
    'default_sender_phone' => 'string',
];

$input = crm_input();
$action = (string)($_GET['action'] ?? $input['action'] ?? 'get');

function s_num($v): ?float
{
    if ($v === '' || $v === null) {
        return null;
    }
    $v = str_replace([' ', ','], ['', '.'], (string)$v);
    return is_numeric($v) ? (float)$v : null;
}

try {
    $pdo = db();

    if ($action === 'get') {
        $settings = [];
        try {
            $rows = $pdo->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach (SETTING_KEYS as $k => $t) {
                $settings[$k] = $rows[$k] ?? '';
            }
        } catch (Throwable $e) {
            $settings = [];
        }

        $payments = [];
        try {
            $payments = $pdo->query("SELECT code, label, effect_type, rate, description, is_active, sort_order FROM payment_methods ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $payments = [];
        }

        $carriers = [];
        try {
            $carriers = $pdo->query("SELECT code, label, agreement_code, pricing_multiplier, extra_after_desi, extra_per_desi, is_active_price, is_active_label, sort_order FROM cargo_carriers ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $carriers = [];
        }

        $tariffs = [];
        try {
            $tariffs = $pdo->query("SELECT id, carrier_code, min_desi, max_desi, base_price_try, is_active FROM cargo_tariffs ORDER BY carrier_code ASC, min_desi ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $tariffs = [];
        }

        json_response(['ok' => true, 'data' => [
            'settings' => $settings,
            'payments' => $payments,
            'carriers' => $carriers,
            'tariffs' => $tariffs,
        ]]);
    }

    if ($action === 'save_settings') {
        $items = is_array($input['items'] ?? null) ? $input['items'] : [];
        $stmt = $pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value, value_type)
            VALUES (:k, :v, :t)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $saved = 0;
        foreach (SETTING_KEYS as $key => $type) {
            if (!array_key_exists($key, $items)) {
                continue;
            }
            $val = (string)$items[$key];
            if ($type === 'decimal') {
                $num = s_num($val);
                if ($num === null) {
                    continue;
                }
                $val = (string)$num;
            } else {
                $val = mb_substr(trim($val), 0, 255);
            }
            $stmt->execute([':k' => $key, ':v' => $val, ':t' => $type]);
            $saved++;
        }
        crm_log($pdo, 'system', 'Genel ayarlar güncellendi', $saved . ' alan', 'settings', null);
        json_response(['ok' => true, 'message' => 'Ayarlar kaydedildi.']);
    }

    if ($action === 'save_payments') {
        $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
        $stmt = $pdo->prepare("UPDATE payment_methods SET rate = :rate, is_active = :active WHERE code = :code");
        foreach ($rows as $r) {
            if (!is_array($r) || empty($r['code'])) {
                continue;
            }
            $rate = s_num($r['rate'] ?? '');
            if ($rate === null) {
                continue;
            }
            if ($rate > 1) {
                $rate = $rate / 100; // yüzde -> oran
            }
            $rate = max(0, min(1, $rate));
            $stmt->execute([':rate' => $rate, ':active' => !empty($r['is_active']) ? 1 : 0, ':code' => (string)$r['code']]);
        }
        crm_log($pdo, 'system', 'Ödeme oranları güncellendi', '', 'settings', null);
        json_response(['ok' => true, 'message' => 'Ödeme oranları kaydedildi.']);
    }

    if ($action === 'save_carriers') {
        $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
        $stmt = $pdo->prepare("
            UPDATE cargo_carriers
            SET agreement_code = :ac, pricing_multiplier = :pm, is_active_price = :ap, is_active_label = :al
            WHERE code = :code
        ");
        foreach ($rows as $r) {
            if (!is_array($r) || empty($r['code'])) {
                continue;
            }
            $pm = s_num($r['pricing_multiplier'] ?? '');
            $stmt->execute([
                ':ac' => mb_substr(trim((string)($r['agreement_code'] ?? '')), 0, 120) ?: null,
                ':pm' => $pm !== null ? max(0, $pm) : 1,
                ':ap' => !empty($r['is_active_price']) ? 1 : 0,
                ':al' => !empty($r['is_active_label']) ? 1 : 0,
                ':code' => (string)$r['code'],
            ]);
        }
        crm_log($pdo, 'system', 'Kargo firmaları güncellendi', '', 'settings', null);
        json_response(['ok' => true, 'message' => 'Kargo firmaları kaydedildi.']);
    }

    if ($action === 'save_tariffs') {
        $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
        $stmt = $pdo->prepare("UPDATE cargo_tariffs SET base_price_try = :bp, is_active = :active WHERE id = :id");
        foreach ($rows as $r) {
            if (!is_array($r) || empty($r['id'])) {
                continue;
            }
            $bp = s_num($r['base_price_try'] ?? '');
            if ($bp === null) {
                continue;
            }
            $stmt->execute([':bp' => max(0, $bp), ':active' => !empty($r['is_active']) ? 1 : 0, ':id' => (int)$r['id']]);
        }
        crm_log($pdo, 'system', 'Kargo tarifeleri güncellendi', '', 'settings', null);
        json_response(['ok' => true, 'message' => 'Kargo tarifeleri kaydedildi.']);
    }

    json_response(['ok' => false, 'message' => 'Geçersiz action.'], 400);
} catch (Throwable $e) {
    error_log('[settings_api] ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Ayar API hatası.'], 500);
}
