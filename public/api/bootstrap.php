<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$configPath = dirname(__DIR__, 2) . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'config.php not found. Copy config.example.php to config.php.']);
    exit;
}

/** @var array{broker_host: string, broker_port: int, serial: string} $config */
$config = require $configPath;

function yarbo_client(array $config): \Yarbo\YarboMqtt
{
    return new \Yarbo\YarboMqtt(
        $config['broker_host'],
        (int) $config['broker_port'],
        $config['serial'],
    );
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}
