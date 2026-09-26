<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboHome;
use Yarbo\YarboHub;

$projectRoot = dirname(__DIR__, 2);
$hub = new YarboHub($projectRoot);
$home = new YarboHome($projectRoot);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    json_response($home->dashboard());
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($input)) {
    json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$action = (string) ($input['action'] ?? '');
if (!$hub->enabled(YarboHub::MODULE_HOME) && $action !== 'setup') {
    json_response(['ok' => false, 'error' => 'Turn on the Home module in Settings.'], 403);
}

set_time_limit($action === 'commission' ? 100 : ($action === 'setup' ? 12 : 40));
ignore_user_abort($action === 'setup');

try {
    $result = match ($action) {
        'setup' => $home->startSetup(),
        'commission' => $home->commission((string) ($input['code'] ?? '')),
        'command' => $home->command($input),
        'rename' => $home->saveMeta(['names' => [$input['id'] ?? '' => $input['name'] ?? '']]),
        'room' => $home->saveMeta(['rooms' => [$input['id'] ?? '' => $input['room'] ?? '']]),
        'scene_save' => $home->saveScene($input),
        'scene_delete' => $home->deleteScene((string) ($input['id'] ?? '')),
        'scene_run' => $home->runScene((string) ($input['id'] ?? '')),
        'paper_assign' => $home->assignPaper((string) ($input['tablet_id'] ?? ''), is_array($input['ids'] ?? null) ? $input['ids'] : []),
        default => ['ok' => false, 'error' => 'Unknown action'],
    };
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => friendly_error($e)], 500);
}

if ($action === 'rename' || $action === 'room') {
    json_response(['ok' => (bool) $result, 'error' => $result ? null : 'Could not save']);
}

json_response(is_array($result) ? $result : ['ok' => false, 'error' => 'Failed']);
