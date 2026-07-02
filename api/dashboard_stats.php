<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/dashboard_data.php';

// Yetki: dashboard görüntüleme (auth aktifse denetlenir).
if (function_exists('require_permission_api')) {
    require_permission_api('dashboard.view');
}

$pdo = null;
try {
    $pdo = db();
} catch (Throwable $e) {
    $pdo = null;
}

json_response([
    'ok' => true,
    'data' => dashboard_stats($pdo),
]);
