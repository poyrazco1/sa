<?php
declare(strict_types=1);

/**
 * Dashboard özet verisi (tek kaynak).
 * Hem modules/dashboard.php (sunucu tarafı render) hem api/dashboard_stats.php (JSON)
 * bu fonksiyonu kullanır. Tüm sorgular sabit (kullanıcı girdisi yok) ve her biri
 * try/catch ile korunur; eksik tablo/DB durumunda güvenli varsayılan döner.
 */

/** Tek skaler değer (COUNT/SUM) güvenli oku. */
function ds_scalar(?PDO $pdo, string $sql): ?float
{
    if (!$pdo instanceof PDO) {
        return null;
    }
    try {
        $v = $pdo->query($sql)->fetchColumn();
        return $v === false ? null : (float)$v;
    } catch (Throwable $e) {
        return null;
    }
}

function ds_int(?PDO $pdo, string $sql): int
{
    $v = ds_scalar($pdo, $sql);
    return $v === null ? 0 : (int)$v;
}

function ds_num(?PDO $pdo, string $sql): float
{
    $v = ds_scalar($pdo, $sql);
    return $v === null ? 0.0 : (float)$v;
}

function dashboard_stats(?PDO $pdo): array
{
    $monthStart = "created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')";
    $today = "DATE(created_at) = CURDATE()";

    $price = [
        'total'        => ds_int($pdo, "SELECT COUNT(*) FROM price_calculation_logs"),
        'today'        => ds_int($pdo, "SELECT COUNT(*) FROM price_calculation_logs WHERE {$today}"),
        'month'        => ds_int($pdo, "SELECT COUNT(*) FROM price_calculation_logs WHERE {$monthStart}"),
        'sold_total'   => ds_int($pdo, "SELECT COUNT(*) FROM price_calculation_logs WHERE sold = 1"),
        'sold_month'   => ds_int($pdo, "SELECT COUNT(*) FROM price_calculation_logs WHERE sold = 1 AND {$monthStart}"),
        'profit_month' => ds_num($pdo, "SELECT COALESCE(SUM(net_profit_try),0) FROM price_calculation_logs WHERE sold = 1 AND {$monthStart}"),
        'sale_month'   => ds_num($pdo, "SELECT COALESCE(SUM(customer_total_ex_try),0) FROM price_calculation_logs WHERE sold = 1 AND {$monthStart}"),
    ];

    $labels = [
        'total' => ds_int($pdo, "SELECT COUNT(*) FROM shipping_label_logs"),
        'today' => ds_int($pdo, "SELECT COUNT(*) FROM shipping_label_logs WHERE {$today}"),
        'month' => ds_int($pdo, "SELECT COUNT(*) FROM shipping_label_logs WHERE {$monthStart}"),
    ];

    $crm = [
        'customers'             => ds_int($pdo, "SELECT COUNT(*) FROM label_customers"),
        'companies'             => ds_int($pdo, "SELECT COUNT(*) FROM companies"),
        'contacts'              => ds_int($pdo, "SELECT COUNT(*) FROM contacts"),
        'leads_open'            => ds_int($pdo, "SELECT COUNT(*) FROM leads WHERE status NOT IN ('won','lost')"),
        'opps_open'             => ds_int($pdo, "SELECT COUNT(*) FROM opportunities WHERE stage NOT IN ('won','lost')"),
        'opps_open_amount'      => ds_num($pdo, "SELECT COALESCE(SUM(amount),0) FROM opportunities WHERE stage NOT IN ('won','lost')"),
        'opps_won_month'        => ds_int($pdo, "SELECT COUNT(*) FROM opportunities WHERE stage = 'won' AND updated_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"),
        'opps_won_month_amount' => ds_num($pdo, "SELECT COALESCE(SUM(amount),0) FROM opportunities WHERE stage = 'won' AND updated_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"),
        'tasks_open'            => ds_int($pdo, "SELECT COUNT(*) FROM tasks WHERE status = 'open'"),
        'tasks_due_soon'        => ds_int($pdo, "SELECT COUNT(*) FROM tasks WHERE status = 'open' AND due_at IS NOT NULL AND due_at BETWEEN NOW() AND (NOW() + INTERVAL 7 DAY)"),
        'tasks_overdue'         => ds_int($pdo, "SELECT COUNT(*) FROM tasks WHERE status = 'open' AND due_at IS NOT NULL AND due_at < NOW()"),
        'quotes_total'          => ds_int($pdo, "SELECT COUNT(*) FROM quotes"),
        'quotes_sent'           => ds_int($pdo, "SELECT COUNT(*) FROM quotes WHERE status = 'sent'"),
    ];

    // Pipeline (fırsat aşamaları)
    $pipeline = [];
    if ($pdo instanceof PDO) {
        try {
            $rows = $pdo->query("
                SELECT stage, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS amt
                FROM opportunities
                GROUP BY stage
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $pipeline[(string)$r['stage']] = [
                    'count' => (int)$r['cnt'],
                    'amount' => (float)$r['amt'],
                ];
            }
        } catch (Throwable $e) {
            $pipeline = [];
        }
    }

    // Son aktiviteler
    $recent = [];
    if ($pdo instanceof PDO) {
        try {
            $rows = $pdo->query("
                SELECT a.type, a.subject, a.body, a.related_type, a.related_id, a.occurred_at,
                       u.full_name AS user_name
                FROM activities a
                LEFT JOIN users u ON u.id = a.user_id
                ORDER BY a.occurred_at DESC, a.id DESC
                LIMIT 8
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $recent[] = [
                    'type' => (string)($r['type'] ?? ''),
                    'subject' => (string)($r['subject'] ?? ''),
                    'body' => (string)($r['body'] ?? ''),
                    'related_type' => (string)($r['related_type'] ?? ''),
                    'related_id' => $r['related_id'] !== null ? (int)$r['related_id'] : null,
                    'occurred_at' => (string)($r['occurred_at'] ?? ''),
                    'user_name' => (string)($r['user_name'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            $recent = [];
        }
    }

    return [
        'price' => $price,
        'labels' => $labels,
        'crm' => $crm,
        'pipeline' => $pipeline,
        'recent' => $recent,
        'db_ready' => $pdo instanceof PDO,
        'generated_at' => date('Y-m-d H:i:s'),
    ];
}

/** Pipeline aşama etiketleri (görüntüleme). */
function ds_stage_label(string $stage): string
{
    $map = [
        'new' => 'Yeni',
        'qualified' => 'Nitelikli',
        'proposal' => 'Teklif',
        'won' => 'Kazanıldı',
        'lost' => 'Kaybedildi',
    ];
    return $map[$stage] ?? ucfirst($stage);
}
