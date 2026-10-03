<?php

declare(strict_types=1);

/**
 * PHP built-in server router for paper remote access.
 * Only /api/device.php tablet actions, always with a pairing token.
 * Started as: php -S 127.0.0.1:8089 scripts/paper_remote_router.php
 */

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    require $root . '/src/YarboPaperRemote.php';
}

use Yarbo\YarboPaperRemote;

$remote = new YarboPaperRemote($root);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
$query = $_GET;
$body = '';
if ($method !== 'GET' && $method !== 'HEAD') {
    $body = (string) file_get_contents('php://input');
}
$headers = [];
if (function_exists('getallheaders')) {
    $got = getallheaders();
    if (is_array($got)) {
        foreach ($got as $name => $value) {
            $headers[(string) $name] = (string) $value;
        }
    }
} else {
    foreach ($_SERVER as $key => $value) {
        if (!is_string($key) || !str_starts_with($key, 'HTTP_')) {
            continue;
        }
        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
        $headers[$name] = (string) $value;
    }
}

$decision = $remote->allowRemoteRequest($method, $path, $query, $headers, $body);
if (!($decision['ok'] ?? false)) {
    http_response_code((int) ($decision['status'] ?? 403));
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => (string) ($decision['error'] ?? 'Forbidden'),
    ]);
    exit;
}

$remote->proxyToPanel($method, $query, $headers, $body);
exit;
