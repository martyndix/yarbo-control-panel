<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

const MAX_LINEAR = 0.5;
const MAX_ANGULAR = 0.8;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

$input = $_POST;
if (empty($input)) {
    $body = file_get_contents('php://input');
    if ($body !== false && $body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }
}

if (!isset($input['linear'], $input['angular'])) {
    json_response(['ok' => false, 'error' => 'linear and angular required'], 400);
}

$linear = (float) $input['linear'];
$angular = (float) $input['angular'];
$enterManual = filter_var($input['enter_manual'] ?? false, FILTER_VALIDATE_BOOLEAN);

$linear = max(-MAX_LINEAR, min(MAX_LINEAR, $linear));
$angular = max(-MAX_ANGULAR, min(MAX_ANGULAR, $angular));

try {
    $client = yarbo_client($config);
    $client->connect();
    $client->sendDrive($linear, $angular, $enterManual);
    $client->disconnect();

    json_response([
        'ok' => true,
        'linear' => $linear,
        'angular' => $angular,
        'enter_manual' => $enterManual,
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => friendly_error($e)], 500);
}
