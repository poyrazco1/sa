<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php'; // auth.php'yi de yükler

$base = auth_base_path();
auth_logout();

header('Location: ' . $base . '/login.php');
exit;
