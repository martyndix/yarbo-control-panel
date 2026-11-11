<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Yarbo\YarboHome;
use Yarbo\YarboHomeAutomations;
use Yarbo\YarboHub;
use Yarbo\YarboSensorHistory;
use Yarbo\YarboUnifi;

$projectRoot = dirname(__DIR__, 2);
$hub = new YarboHub($projectRoot);
$home = new YarboHome($projectRoot);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $getAction = trim((string) ($_GET['action'] ?? ''));
    if ($getAction === 'sensor_history') {
        set_time_limit(12);
        if (!$hub->enabled(YarboHub::MODULE_HOME) && !$hub->enabled(YarboHub::MODULE_UNIFI)) {
            json_response(['ok' => false, 'error' => 'Turn on Home or UniFi in Settings.'], 403);
        }
        $id = trim((string) ($_GET['id'] ?? ''));
        if ($id === '') {
            json_response(['ok' => false, 'error' => 'Pick a sensor'], 400);
        }
        $to = isset($_GET['to']) && is_numeric($_GET['to']) ? (int) $_GET['to'] : time();
        $from = isset($_GET['from']) && is_numeric($_GET['from']) ? (int) $_GET['from'] : ($to - 7 * 86400);
        $bucket = trim((string) ($_GET['bucket'] ?? '30m'));
        json_response((new YarboSensorHistory($projectRoot))->series($id, $from, $to, $bucket));
    }
    set_time_limit(8);
    try {
        (new YarboHomeAutomations($projectRoot))->kickRunner();
    } catch (Throwable) {
    }
    try {
        if ($hub->enabled(YarboHub::MODULE_UNIFI)) {
            (new YarboUnifi($projectRoot))->flushAutoOff();
        }
    } catch (Throwable) {
    }
    try {
        if ($hub->enabled(YarboHub::MODULE_HOME)) {
            $home->flushAutoOff();
        }
    } catch (Throwable) {
    }
    json_response($home->dashboard());
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($input)) {
    json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$action = (string) ($input['action'] ?? '');
if ($action === 'sensor_history_sample') {
    if (!$hub->enabled(YarboHub::MODULE_HOME) && !$hub->enabled(YarboHub::MODULE_UNIFI)) {
        json_response(['ok' => false, 'error' => 'Turn on Home or UniFi in Settings.'], 403);
    }
} elseif (!$hub->enabled(YarboHub::MODULE_HOME) && $action !== 'setup') {
    json_response(['ok' => false, 'error' => 'Turn on the Home module in Settings.'], 403);
}

set_time_limit($action === 'commission' || $action === 'scan' ? 150 : ($action === 'setup' ? 12 : ($action === 'room_command' || $action === 'group_command' || $action === 'scene_run' || $action === 'scene_off' ? 180 : ($action === 'command' ? 100 : 70))));
ignore_user_abort($action === 'setup');

try {
    $result = match ($action) {
        'setup' => $home->startSetup(),
        'commission' => $home->commission((string) ($input['code'] ?? '')),
        'scan' => $home->scanDevices(),
        'forget' => $home->forgetNode((int) ($input['node_id'] ?? 0)),
        'hide' => $home->hideDevice((string) ($input['id'] ?? ''), true),
        'unhide' => $home->hideDevice((string) ($input['id'] ?? ''), false),
        'remove' => $home->removeDevice((string) ($input['id'] ?? ''), (bool) ($input['whole_node'] ?? false)),
        'command' => $home->command($input),
        'auto_off' => (static function () use ($home, $input): array {
            $id = trim((string) ($input['id'] ?? ''));
            $sec = (int) ($input['sec'] ?? $input['auto_off_sec'] ?? 0);
            if ($id === '') {
                return ['ok' => false, 'error' => 'Pick a plug'];
            }
            if (!$home->setAutoOff($id, $sec)) {
                return ['ok' => false, 'error' => 'Auto-off is only for Matter/Hue plugs'];
            }

            return [
                'ok' => true,
                'id' => $id,
                'auto_off_sec' => $home->autoOffSec($id),
                'auto_off_map' => $home->autoOffMap(),
            ];
        })(),
        'motor_mode' => (static function () use ($home, $input): array {
            $id = trim((string) ($input['id'] ?? ''));
            $watts = (int) ($input['watts'] ?? $input['motor_mode_w'] ?? 0);
            if ($id === '') {
                return ['ok' => false, 'error' => 'Pick a plug'];
            }
            if (!$home->setMotorMode($id, $watts)) {
                return ['ok' => false, 'error' => 'Motor mode is only for Matter/Hue plugs'];
            }

            return [
                'ok' => true,
                'id' => $id,
                'motor_mode_w' => $home->motorModeWatts($id),
                'motor_mode_map' => $home->motorModeMap(),
            ];
        })(),
        'rename' => $home->saveMeta(['names' => [$input['id'] ?? '' => $input['name'] ?? '']]),
        'room' => $home->assignDeviceRoom((string) ($input['id'] ?? ''), (string) ($input['room_id'] ?? $input['room'] ?? '')),
        'contact_kind' => $home->assignContactKind((string) ($input['id'] ?? ''), (string) ($input['contact_kind'] ?? $input['kind'] ?? '')),
        'room_save' => $home->saveRoom($input),
        'room_delete' => $home->deleteRoom((string) ($input['id'] ?? '')),
        'room_command' => $home->commandRoom($input),
        'group' => $home->assignDeviceGroup((string) ($input['id'] ?? ''), (string) ($input['group_id'] ?? $input['group'] ?? '')),
        'group_save' => $home->saveGroup($input),
        'group_delete' => $home->deleteGroup((string) ($input['id'] ?? '')),
        'group_command' => $home->commandGroup($input),
        'scene_save' => $home->saveScene($input),
        'scene_delete' => $home->deleteScene((string) ($input['id'] ?? '')),
        'scene_run' => $home->toggleScene((string) ($input['id'] ?? '')),
        'scene_off' => $home->stopScene((string) ($input['id'] ?? '')),
        'paper_assign' => $home->assignPaper((string) ($input['tablet_id'] ?? ''), is_array($input['ids'] ?? null) ? $input['ids'] : []),
        'reorder' => $home->reorder($input),
        'backup_export' => $home->exportBackup(),
        'backup_import' => $home->importBackup($input),
        'automation_save' => (new YarboHomeAutomations($projectRoot))->save($input),
        'automation_delete' => (new YarboHomeAutomations($projectRoot))->delete((string) ($input['id'] ?? '')),
        'automation_enable' => (new YarboHomeAutomations($projectRoot))->setEnabled(
            (string) ($input['id'] ?? ''),
            (bool) ($input['enabled'] ?? false)
        ),
        'sensor_history_sample' => (new YarboSensorHistory($projectRoot))->sampleOnce(),
        default => ['ok' => false, 'error' => 'Unknown action'],
    };
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => friendly_error($e)], 500);
}

if ($action === 'rename') {
    json_response(['ok' => (bool) $result, 'error' => $result ? null : 'Could not save']);
}

json_response(is_array($result) ? $result : ['ok' => false, 'error' => 'Failed']);
