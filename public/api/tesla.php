<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboPowerwall;

$projectRoot = dirname(__DIR__, 2);
$powerwall = new YarboPowerwall($projectRoot);
$action = (string) ($_GET['action'] ?? '');

if ($action === 'callback') {
    $error = (string) ($_GET['error'] ?? '');
    $code = (string) ($_GET['code'] ?? '');
    header('Content-Type: text/html; charset=utf-8');
    if ($error !== '') {
        echo '<p>Tesla returned: ' . htmlspecialchars($error, ENT_QUOTES) . '</p><p><a href="/">Back to the panel</a></p>';
        exit;
    }
    if ($code === '') {
        echo '<p>Missing Tesla authorization code.</p><p><a href="/">Back to the panel</a></p>';
        exit;
    }
    $result = $powerwall->exchangeAuthorizationCode($code);
    $ok = !empty($result['ok']);
    echo '<p>' . htmlspecialchars((string) ($result['message'] ?? $result['error'] ?? ''), ENT_QUOTES) . '</p>';
    echo '<p><a href="/">Back to the panel</a></p>';
    http_response_code($ok ? 200 : 400);
    exit;
}

if ($action === 'public_key') {
    $path = $powerwall->publicKeyPath();
    if (!is_file($path)) {
        json_response(['ok' => false, 'error' => 'Generate Tesla keys in Settings first.'], 404);
    }
    header('Content-Type: application/x-pem-file');
    readfile($path);
    exit;
}

json_response(['ok' => false, 'error' => 'Unknown action'], 400);
