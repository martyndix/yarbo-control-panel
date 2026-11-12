<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHomeAutomations;

function assert_true(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "ok: {$msg}\n";
}

$rootFs = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/yarbo-rain-auto-' . bin2hex(random_bytes(3));
mkdir($tmp . '/data', 0775, true);
file_put_contents($tmp . '/data/hub-config.json', json_encode([
    'modules' => ['home' => true, 'yarbo' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($tmp . '/data/home.json', json_encode([
    'names' => [],
    'automations' => [],
], JSON_UNESCAPED_SLASHES));

$wet = YarboHomeAutomations::yarboSnapshotRow([
    'online' => true,
    'error_code' => 0,
    'rain_detected' => true,
    'name' => 'Yarbo',
]);
$dry = YarboHomeAutomations::yarboSnapshotRow([
    'online' => true,
    'error_code' => 0,
    'rain_detected' => false,
    'name' => 'Yarbo',
]);
assert_true(($wet['rain'] ?? false) === true, 'snapshot rain from rain_detected');
assert_true(($dry['rain'] ?? true) === false, 'snapshot dry when not raining');
assert_true(
    YarboHomeAutomations::whenPhrase(['type' => 'robot', 'id' => 'yarbo', 'event' => 'rain']) === 'Yarbo Rain',
    'whenPhrase rain'
);
assert_true(
    YarboHomeAutomations::whenPhrase(['type' => 'robot', 'id' => 'yarbo', 'event' => 'dry']) === 'Yarbo Dry',
    'whenPhrase dry'
);

$auto = new YarboHomeAutomations($tmp);
$commands = [];
$auto->setCommandHandler(static function (array $action) use (&$commands): array {
    $commands[] = $action;

    return ['ok' => true];
});

$save = $auto->save([
    'id' => 'a-rain-dock',
    'enabled' => true,
    'name' => '',
    'trigger' => ['type' => 'robot', 'id' => 'yarbo', 'event' => 'rain'],
    'actions' => [['kind' => 'yarbo', 'command' => 'return_to_dock']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($save['ok']), 'save rain → dock: ' . json_encode($save));
assert_true(($save['automation']['trigger']['event'] ?? '') === 'rain', 'rain event persisted');
assert_true(
    str_contains((string) ($save['automation']['name'] ?? ''), 'Rain'),
    'auto-name mentions Rain: ' . ($save['automation']['name'] ?? '')
);

$t0 = 2_000_100_000;
$r = $auto->tick([$dry], $t0);
assert_true(($r['fired'] ?? []) === [], 'idle dry: no fire');
$r = $auto->tick([$wet], $t0 + 1);
assert_true(($r['fired'] ?? []) === ['a-rain-dock'], 'rain edge fires dock: ' . json_encode($r));
assert_true(
    (($commands[0]['command'] ?? '') === 'return_to_dock'),
    'dock commanded on rain: ' . json_encode($commands)
);
$r = $auto->tick([$wet], $t0 + 2);
assert_true(($r['fired'] ?? []) === [], 'still wet: no re-fire');

$auto->delete('a-rain-dock');
@unlink($auto->statePath());
$commands = [];
$drySave = $auto->save([
    'id' => 'a-dry',
    'enabled' => true,
    'trigger' => ['type' => 'robot', 'id' => 'yarbo', 'event' => 'dry'],
    'actions' => [['kind' => 'yarbo', 'command' => 'stop']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($drySave['ok']), 'save dry When');
$r = $auto->tick([$wet], $t0 + 10);
assert_true(($r['fired'] ?? []) === [], 'wet: dry When idle');
$r = $auto->tick([$dry], $t0 + 11);
assert_true(($r['fired'] ?? []) === ['a-dry'], 'dry edge fires: ' . json_encode($r));

$auto->delete('a-dry');
@unlink($auto->statePath());
$ifSave = $auto->save([
    'id' => 'a-if-rain',
    'enabled' => true,
    'trigger' => ['type' => 'time', 'at' => '12:00'],
    'conditions' => [['type' => 'robot', 'id' => 'yarbo', 'event' => 'rain']],
    'actions' => [['kind' => 'yarbo', 'command' => 'return_to_dock']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($ifSave['ok']), 'save Only if rain');
// Noon local UTC
$noon = gmmktime(12, 0, 0, 1, 15, 2030);
$r = $auto->tick([$dry], $noon);
assert_true(($r['fired'] ?? []) === [], 'noon but dry: blocked by Only if');
$r = $auto->tick([$wet], $noon + 86400);
assert_true(($r['fired'] ?? []) === ['a-if-rain'], 'noon + rain Only if: ' . json_encode($r));

// Start plan refused while rain is cached.
$auto->delete('a-if-rain');
@unlink($auto->statePath());
file_put_contents($auto->yarboCachePath(), json_encode([
    'online' => true,
    'error_code' => 0,
    'rain_detected' => true,
    'name' => 'Yarbo',
], JSON_UNESCAPED_SLASHES));
$commands = [];
$block = $auto->save([
    'id' => 'a-start-block',
    'enabled' => true,
    'trigger' => ['type' => 'robot', 'id' => 'yarbo', 'event' => 'ok'],
    'actions' => [['kind' => 'yarbo', 'command' => 'start_plan', 'plan_id' => 'p1', 'plan_name' => 'Lawn']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($block['ok']), 'save start_plan rule');
// Force fire via tick with error→ok while rain cached — start_plan should fail open (no throw) and not call handler.
$r = $auto->tick([
    YarboHomeAutomations::yarboSnapshotRow(['online' => true, 'error' => true, 'rain_detected' => true]),
], $t0 + 100);
assert_true(($r['fired'] ?? []) === [] && ($r['errors'] ?? []) === [], 'error setup idle');
$r = $auto->tick([
    YarboHomeAutomations::yarboSnapshotRow(['online' => true, 'error' => false, 'rain_detected' => true]),
], $t0 + 101);
assert_true($commands === [], 'start_plan not sent while raining: ' . json_encode($commands));
$errJoined = implode(' ', $r['errors'] ?? []);
assert_true(
    str_contains($errJoined, 'rain'),
    'rain block recorded as then error: ' . $errJoined
);

$js = (string) file_get_contents($rootFs . '/public/assets/app.js');
$change = (string) file_get_contents($rootFs . '/CHANGELOG.md');
assert_true(str_contains($js, 'yarbo:rain'), 'UI Rain tray chip');
assert_true(str_contains($js, 'yarbo:dry'), 'UI Dry tray chip');
assert_true(str_contains($js, "['rain', 'Rain']"), 'UI rain option');
assert_true(str_contains($change, '## [4.0.277]'), 'changelog 4.0.277');

echo "ALL PASS\n";
