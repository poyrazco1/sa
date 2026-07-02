<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Sadece POST desteklenir.'], 405);
}

$data = request_json();

if (!is_array($data) || empty($data)) {
    json_response(['ok' => false, 'message' => 'Boş kayıt.'], 422);
}

try {
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS price_calculation_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_name VARCHAR(120) NULL,
            user_id BIGINT UNSIGNED NULL,
            company_id BIGINT UNSIGNED NULL,
            contact_id BIGINT UNSIGNED NULL,
            opportunity_id BIGINT UNSIGNED NULL,
            quote_id BIGINT UNSIGNED NULL,
            sold TINYINT(1) NOT NULL DEFAULT 0,
            sold_label VARCHAR(40) NULL,
            currency VARCHAR(10) NOT NULL,
            payment_code VARCHAR(40) NULL,
            payment_label VARCHAR(120) NULL,
            cost_input DECIMAL(18,4) NULL,
            profit_percent DECIMAL(8,2) NULL,
            carrier_code VARCHAR(40) NULL,
            carrier_label VARCHAR(120) NULL,
            desi DECIMAL(10,2) NULL,
            sale_ex_try DECIMAL(18,4) NULL,
            sale_inc_try DECIMAL(18,4) NULL,
            collected_ex_try DECIMAL(18,4) NULL,
            collected_inc_try DECIMAL(18,4) NULL,
            net_profit_try DECIMAL(18,4) NULL,
            cargo_try DECIMAL(18,4) NULL,
            customer_cargo_try DECIMAL(18,4) NULL,
            customer_total_ex_try DECIMAL(18,4) NULL,
            installment_extra_loss_try DECIMAL(18,4) NULL,
            payment_impact_title VARCHAR(160) NULL,
            payment_impact_rule VARCHAR(255) NULL,
            payment_impact_try DECIMAL(18,4) NULL,
            usd_try_rate DECIMAL(18,6) NULL,
            eur_try_rate DECIMAL(18,6) NULL,
            rates_source VARCHAR(120) NULL,
            sale_ex_text VARCHAR(80) NULL,
            sale_inc_text VARCHAR(80) NULL,
            customer_total_ex_text VARCHAR(80) NULL,
            payload LONGTEXT NOT NULL,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at),
            KEY idx_user_name (user_name),
            KEY idx_sold (sold),
            KEY idx_payment (payment_code),
            KEY idx_carrier (carrier_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->prepare("
        INSERT INTO price_calculation_logs (
            user_name, user_id, company_id, contact_id, opportunity_id, quote_id,
            sold, sold_label, currency, payment_code, payment_label,
            cost_input, profit_percent, carrier_code, carrier_label, desi,
            sale_ex_try, sale_inc_try, collected_ex_try, collected_inc_try,
            net_profit_try, cargo_try, customer_cargo_try, customer_total_ex_try,
            installment_extra_loss_try, payment_impact_title, payment_impact_rule, payment_impact_try,
            usd_try_rate, eur_try_rate, rates_source,
            sale_ex_text, sale_inc_text, customer_total_ex_text, payload
        ) VALUES (
            :user_name, :user_id, :company_id, :contact_id, :opportunity_id, :quote_id,
            :sold, :sold_label, :currency, :payment_code, :payment_label,
            :cost_input, :profit_percent, :carrier_code, :carrier_label, :desi,
            :sale_ex_try, :sale_inc_try, :collected_ex_try, :collected_inc_try,
            :net_profit_try, :cargo_try, :customer_cargo_try, :customer_total_ex_try,
            :installment_extra_loss_try, :payment_impact_title, :payment_impact_rule, :payment_impact_try,
            :usd_try_rate, :eur_try_rate, :rates_source,
            :sale_ex_text, :sale_inc_text, :customer_total_ex_text, :payload
        )
    ");

    $__pwUser = (function_exists('auth_user') ? auth_user() : null);
    $stmt->execute([
        ':user_name' => (string)($data['userName'] ?? ''),
        ':user_id' => $__pwUser ? (int)$__pwUser['id'] : (((int)($data['user_id'] ?? 0)) ?: null),
        ':company_id' => ((int)($data['company_id'] ?? 0)) ?: null,
        ':contact_id' => ((int)($data['contact_id'] ?? 0)) ?: null,
        ':opportunity_id' => ((int)($data['opportunity_id'] ?? 0)) ?: null,
        ':quote_id' => ((int)($data['quote_id'] ?? 0)) ?: null,
        ':sold' => !empty($data['sold']) ? 1 : 0,
        ':sold_label' => (string)($data['soldLabel'] ?? ''),
        ':currency' => (string)($data['currency'] ?? 'TL'),
        ':payment_code' => (string)($data['payment'] ?? ''),
        ':payment_label' => (string)($data['paymentLabel'] ?? ''),
        ':cost_input' => (float)($data['cost'] ?? 0),
        ':profit_percent' => (float)($data['profitPercent'] ?? 0),
        ':carrier_code' => (string)($data['carrier'] ?? ''),
        ':carrier_label' => (string)($data['carrierLabel'] ?? ''),
        ':desi' => (float)($data['desi'] ?? 0),
        ':sale_ex_try' => (float)($data['saleExTry'] ?? 0),
        ':sale_inc_try' => (float)($data['saleIncTry'] ?? 0),
        ':collected_ex_try' => (float)($data['collectedExTry'] ?? 0),
        ':collected_inc_try' => (float)($data['collectedIncTry'] ?? 0),
        ':net_profit_try' => (float)($data['netProfitTry'] ?? 0),
        ':cargo_try' => (float)($data['cargoTry'] ?? 0),
        ':customer_cargo_try' => (float)($data['customerCargoTry'] ?? 0),
        ':customer_total_ex_try' => (float)($data['customerTotalExTry'] ?? 0),
        ':installment_extra_loss_try' => (float)($data['installmentExtraLossTry'] ?? 0),
        ':payment_impact_title' => (string)($data['paymentImpactTitle'] ?? ''),
        ':payment_impact_rule' => (string)($data['paymentImpactRule'] ?? ''),
        ':payment_impact_try' => (float)($data['paymentImpactTry'] ?? 0),
        ':usd_try_rate' => (float)($data['usdTryRate'] ?? 0),
        ':eur_try_rate' => (float)($data['eurTryRate'] ?? 0),
        ':rates_source' => (string)($data['ratesSource'] ?? ''),
        ':sale_ex_text' => (string)($data['saleExText'] ?? ''),
        ':sale_inc_text' => (string)($data['saleIncText'] ?? ''),
        ':customer_total_ex_text' => (string)($data['customerTotalExText'] ?? ''),
        ':payload' => json_encode($data, JSON_UNESCAPED_UNICODE),
    ]);

    json_response([
        'ok' => true,
        'id' => (int)$pdo->lastInsertId(),
        'message' => 'Fiyat kaydı DB’ye yazıldı.',
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => 'Fiyat kaydı DB hatası.'], 500);
}
