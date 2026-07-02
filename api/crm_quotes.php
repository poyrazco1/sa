<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/crm.php';

const QUOTE_STATUSES = ['draft', 'sent', 'accepted', 'rejected'];

$input = crm_input();
$action = (string)($_GET['action'] ?? $input['action'] ?? 'list');

/** Kalemleri temizle + satır toplamlarını hesapla. */
function quote_clean_items(array $items): array
{
    $out = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $name = trim((string)($it['product_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $qty = (float)str_replace(',', '.', (string)($it['qty'] ?? 1));
        if ($qty <= 0) {
            $qty = 1;
        }
        $unit = (float)str_replace(',', '.', (string)($it['unit_price'] ?? 0));
        $vat = (float)str_replace(',', '.', (string)($it['vat_rate'] ?? 0.20));
        if ($vat > 1) {
            $vat = $vat / 100; // 20 -> 0.20
        }
        $vat = max(0, min(1, $vat));
        $line = round($qty * $unit, 2);
        $out[] = [
            'product_name' => mb_substr($name, 0, 255),
            'ws_product_code' => mb_substr(trim((string)($it['ws_product_code'] ?? '')), 0, 80),
            'qty' => $qty,
            'unit_price' => round($unit, 2),
            'vat_rate' => $vat,
            'line_total' => $line,
        ];
    }
    return $out;
}

function quote_totals(array $items): array
{
    $subtotal = 0.0;
    $vatTotal = 0.0;
    foreach ($items as $it) {
        $subtotal += $it['line_total'];
        $vatTotal += $it['line_total'] * $it['vat_rate'];
    }
    $subtotal = round($subtotal, 2);
    $vatTotal = round($vatTotal, 2);
    return ['subtotal' => $subtotal, 'vat_total' => $vatTotal, 'grand_total' => round($subtotal + $vatTotal, 2)];
}

try {
    $pdo = db();

    if ($action === 'list') {
        require_permission_api('crm.view');
        $q = trim((string)($_GET['q'] ?? $input['q'] ?? ''));
        $status = (string)($_GET['status'] ?? $input['status'] ?? '');
        $params = [];
        $where = 'WHERE 1=1';
        $where .= crm_scope('q.owner_user_id', $params);
        if (in_array($status, QUOTE_STATUSES, true)) {
            $where .= " AND q.status = :status";
            $params[':status'] = $status;
        }
        if ($q !== '') {
            $where .= " AND (q.quote_no LIKE :q OR co.name LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        $sql = "
            SELECT q.id, q.quote_no, q.company_id, q.status, q.currency, q.grand_total,
                   q.valid_until, q.owner_user_id, q.created_at, q.updated_at,
                   co.name AS company_name, u.full_name AS owner_name,
                   (SELECT COUNT(*) FROM quote_items qi WHERE qi.quote_id = q.id) AS item_count
            FROM quotes q
            LEFT JOIN companies co ON co.id = q.company_id
            LEFT JOIN users u ON u.id = q.owner_user_id
            {$where}
            ORDER BY q.id DESC
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
        $stmt = $pdo->prepare("SELECT q.*, co.name AS company_name FROM quotes q LEFT JOIN companies co ON co.id = q.company_id WHERE q.id = :id");
        $stmt->execute([':id' => $id]);
        $quote = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$quote) {
            json_response(['ok' => false, 'message' => 'Teklif bulunamadı.'], 404);
        }
        $its = $pdo->prepare("SELECT product_name, ws_product_code, qty, unit_price, vat_rate, line_total FROM quote_items WHERE quote_id = :id ORDER BY sort_order ASC, id ASC");
        $its->execute([':id' => $id]);
        json_response(['ok' => true, 'data' => ['quote' => $quote, 'items' => $its->fetchAll(PDO::FETCH_ASSOC)]]);
    }

    if ($action === 'save') {
        require_permission_api('crm.edit');
        $id = (int)($input['id'] ?? 0);
        $items = quote_clean_items(is_array($input['items'] ?? null) ? $input['items'] : []);
        if (!$items) {
            json_response(['ok' => false, 'message' => 'En az bir ürün satırı gerekli.'], 422);
        }
        $totals = quote_totals($items);
        $companyId = (int)($input['company_id'] ?? 0);
        $header = [
            'company_id' => $companyId > 0 ? $companyId : null,
            'opportunity_id' => (int)($input['opportunity_id'] ?? 0) ?: null,
            'status' => crm_enum($input, 'status', QUOTE_STATUSES, 'draft'),
            'currency' => crm_enum($input, 'currency', ['TL', 'USD', 'EUR'], 'TL'),
            'subtotal' => $totals['subtotal'],
            'vat_total' => $totals['vat_total'],
            'grand_total' => $totals['grand_total'],
            'valid_until' => crm_date($input, 'valid_until'),
            'note' => crm_str($input, 'note', 2000),
        ];

        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $own = $pdo->prepare("SELECT owner_user_id FROM quotes WHERE id = :id");
                $own->execute([':id' => $id]);
                $row = $own->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $pdo->rollBack();
                    json_response(['ok' => false, 'message' => 'Teklif bulunamadı.'], 404);
                }
                if (!crm_can_touch($row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null)) {
                    $pdo->rollBack();
                    json_response(['ok' => false, 'message' => 'Bu kaydı düzenleme yetkiniz yok.'], 403);
                }
                $sets = [];
                foreach ($header as $k => $v) {
                    $sets[] = "`{$k}` = :{$k}";
                }
                $header['id'] = $id;
                $pdo->prepare("UPDATE quotes SET " . implode(', ', $sets) . " WHERE id = :id")->execute($header);
                crm_log($pdo, 'system', 'Teklif güncellendi', '', 'quote', $id);
            } else {
                $header['owner_user_id'] = crm_uid();
                $header['quote_no'] = uniqid('TMP-', true);
                $cols = array_keys($header);
                $ph = array_map(static fn($c) => ':' . $c, $cols);
                $pdo->prepare("INSERT INTO quotes (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $ph) . ")")->execute($header);
                $id = (int)$pdo->lastInsertId();
                $quoteNo = 'TKF-' . date('Y') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
                $pdo->prepare("UPDATE quotes SET quote_no = :qn WHERE id = :id")->execute([':qn' => $quoteNo, ':id' => $id]);
                crm_log($pdo, 'system', 'Teklif oluşturuldu', $quoteNo, 'quote', $id);
            }

            $pdo->prepare("DELETE FROM quote_items WHERE quote_id = :id")->execute([':id' => $id]);
            $itemStmt = $pdo->prepare("
                INSERT INTO quote_items (quote_id, product_name, ws_product_code, qty, unit_price, vat_rate, line_total, sort_order)
                VALUES (:qid, :pn, :code, :qty, :unit, :vat, :line, :sort)
            ");
            foreach ($items as $i => $it) {
                $itemStmt->execute([
                    ':qid' => $id,
                    ':pn' => $it['product_name'],
                    ':code' => $it['ws_product_code'],
                    ':qty' => $it['qty'],
                    ':unit' => $it['unit_price'],
                    ':vat' => $it['vat_rate'],
                    ':line' => $it['line_total'],
                    ':sort' => $i,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        json_response(['ok' => true, 'message' => 'Teklif kaydedildi.', 'data' => ['id' => $id]]);
    }

    if ($action === 'set_status') {
        require_permission_api('crm.edit');
        $id = (int)($input['id'] ?? 0);
        $status = crm_enum($input, 'status', QUOTE_STATUSES, '');
        if ($id <= 0 || $status === '') {
            json_response(['ok' => false, 'message' => 'Geçersiz istek.'], 422);
        }
        $own = $pdo->prepare("SELECT owner_user_id, quote_no FROM quotes WHERE id = :id");
        $own->execute([':id' => $id]);
        $row = $own->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['ok' => false, 'message' => 'Teklif bulunamadı.'], 404);
        }
        if (!crm_can_touch($row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null)) {
            json_response(['ok' => false, 'message' => 'Yetkiniz yok.'], 403);
        }
        $pdo->prepare("UPDATE quotes SET status = :s WHERE id = :id")->execute([':s' => $status, ':id' => $id]);
        crm_log($pdo, 'status_change', 'Teklif durumu: ' . $status, (string)$row['quote_no'], 'quote', $id);
        json_response(['ok' => true, 'message' => 'Durum güncellendi.']);
    }

    if ($action === 'delete') {
        require_permission_api('crm.delete');
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            json_response(['ok' => false, 'message' => 'Geçersiz ID.'], 422);
        }
        $own = $pdo->prepare("SELECT owner_user_id, quote_no FROM quotes WHERE id = :id");
        $own->execute([':id' => $id]);
        $row = $own->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['ok' => false, 'message' => 'Teklif bulunamadı.'], 404);
        }
        if (!crm_can_touch($row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null)) {
            json_response(['ok' => false, 'message' => 'Yetkiniz yok.'], 403);
        }
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM quote_items WHERE quote_id = :id")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM quotes WHERE id = :id")->execute([':id' => $id]);
        $pdo->commit();
        crm_log($pdo, 'system', 'Teklif silindi', (string)$row['quote_no'], 'quote', $id);
        json_response(['ok' => true, 'message' => 'Teklif silindi.']);
    }

    json_response(['ok' => false, 'message' => 'Geçersiz action.'], 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[crm_quotes] ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Teklif API hatası.'], 500);
}
