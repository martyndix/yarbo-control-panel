<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboVestaboard;

$projectRoot = dirname(__DIR__, 2);
$board = new YarboVestaboard($projectRoot);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');

function vestaboard_input(): array
{
    $input = $_POST;
    if ($input !== []) {
        return $input;
    }
    $body = file_get_contents('php://input');
    if ($body === false || $body === '') {
        return [];
    }
    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : [];
}

if ($method === 'GET' && ($action === 'preview' || $action === '')) {
    $sample = (string) ($_GET['sample'] ?? 'live');
    json_response($board->preview($sample === '' ? 'live' : $sample) + [
        'config' => $board->publicView(),
    ]);
}

$input = vestaboard_input();
$action = (string) ($input['action'] ?? $action);

if ($method === 'POST' && $action === 'save') {
    if (!$board->save($input)) {
        json_response(['ok' => false, 'error' => 'Could not write Vestaboard settings. Check data/ permissions.'], 500);
    }
    json_response(['ok' => true, 'config' => $board->publicView()]);
}

if ($method === 'POST' && $action === 'test') {
    json_response($board->testConnection($input) + ['config' => $board->publicView()]);
}

if ($method === 'POST' && $action === 'send') {
    json_response($board->sendNow($input) + ['config' => $board->publicView()]);
}

json_response(['ok' => false, 'error' => 'Unknown action'], 400);
