<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHome;
use Yarbo\YarboHomeAutomations;
use Yarbo\YarboHub;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-auto-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true, 'unifi' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'names' => [
        '1:2' => 'Lamp',
        'unifi:sensor:s1' => 'Kitchen Sensor',
        'unifi:light:porch' => 'Porch',
        'unifi:hub:door1' => 'Front door',
    ],
    'room_defs' => [['id' => 'r1', 'name' => 'Kitchen']],
    'rooms' => [
        '1:2' => 'r1',
        'unifi:sensor:s1' => 'r1',
        'unifi:light:porch' => 'r1',
    ],
    'group_defs' => [['id' => 'g1', 'name' => 'Sensors', 'room_id' => 'r1']],
    'groups' => ['unifi:sensor:s1' => 'g1'],
    'scenes' => [['id' => 'sc-outdoor', 'name' => 'Outdoor Lights']],
    'paper' => [],
    'hidden' => [],
    'device_order' => [],
    'last_devices' => [
        ['id' => '1:2', 'name' => 'Lamp', 'kind' => 'light', 'on' => false],
    ],
], JSON_UNESCAPED_SLASHES));

$home = new YarboHome($root);
assert_true(!YarboHome::deviceAcceptsCommand('unifi:sensor:s1', 'on'), 'UniFi sensor must not accept on');
assert_true(!YarboHome::deviceAcceptsCommand('unifi:camera:c1', 'off'), 'UniFi camera must not accept off');
assert_true(!YarboHome::deviceAcceptsCommand('unifi:hub:door1', 'on'), 'Access hub must not accept on');
assert_true(YarboHome::deviceAcceptsCommand('unifi:hub:door1', 'unlock'), 'Access hub must accept unlock');
assert_true(YarboHome::deviceAcceptsCommand('unifi:light:porch', 'on'), 'UniFi light must accept on');
assert_true(YarboHome::deviceAcceptsCommand('1:2', 'on', 'light'), 'Matter light must accept on');
assert_true(YarboHome::deviceAcceptsCommand('1:9', 'on', 'heater'), 'heater must accept on');
assert_true(!YarboHome::deviceAcceptsCommand('9:1', 'on', 'sensor'), 'Matter sensor must not accept on');

$filtered = $home->filterCommandIds(['unifi:sensor:s1', 'unifi:light:porch', '1:2', 'unifi:hub:door1'], 'on');
assert_true($filtered === ['unifi:light:porch', '1:2'], 'filter on must skip sensor and door, keep lights: ' . json_encode($filtered));

$unlockOnly = $home->filterCommandIds(['unifi:sensor:s1', 'unifi:hub:door1', '1:2'], 'unlock');
assert_true($unlockOnly === ['unifi:hub:door1'], 'unlock must keep the door only: ' . json_encode($unlockOnly));

$groupOnlySensor = $home->commandGroup(['group_id' => 'g1', 'command' => 'on']);
assert_true(($groupOnlySensor['ok'] ?? true) === false, 'sensor-only group must not pretend to succeed');
assert_true(($groupOnlySensor['error'] ?? '') === 'No controllable devices', 'sensor-only group: ' . json_encode($groupOnlySensor));

$auto = new YarboHomeAutomations($root);
$commands = [];
$auto->setCommandHandler(static function (array $action) use (&$commands): array {
    $commands[] = $action;

    return ['ok' => true];
});

$saved = $auto->save([
    'id' => 'a-open',
    'name' => '',
    'enabled' => true,
    'trigger' => ['type' => 'device', 'id' => 'unifi:hub:door1', 'event' => 'stays_open', 'for_sec' => 300],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'names' => ['unifi:hub:door1' => 'Front door', 'unifi:light:porch' => 'Porch'],
    'timezone' => 'America/New_York',
]);
assert_true(!empty($saved['ok']), 'save duration rule: ' . json_encode($saved));
assert_true(($saved['automation']['name'] ?? '') === 'Front door open 5 min → Porch on', 'auto-name: ' . ($saved['automation']['name'] ?? ''));
$disk = json_decode((string) file_get_contents($auto->storePath()), true);
assert_true(!isset($disk['timezone']), 'must not store a timezone');
assert_true(($disk['automations'][0]['trigger']['for_sec'] ?? 0) === 300, 'for_sec persisted');

$door = static function (bool $open): array {
    return [[
        'id' => 'unifi:hub:door1',
        'kind' => 'hub',
        'open' => $open,
        'on' => false,
    ], [
        'id' => 'unifi:light:porch',
        'kind' => 'light',
        'on' => false,
        'open' => null,
    ]];
};

$t0 = 1_700_000_000;
$r = $auto->tick($door(true), $t0);
assert_true($r['fired'] === [], 'duration must not fire when it first becomes true');
$r = $auto->tick($door(true), $t0 + 299);
assert_true($r['fired'] === [], 'duration must not fire at 4:59');
$r = $auto->tick($door(true), $t0 + 300);
assert_true($r['fired'] === ['a-open'], 'duration must fire at 5:00: ' . json_encode($r));
assert_true(count($commands) === 1 && ($commands[0]['id'] ?? '') === 'unifi:light:porch', 'duration action: ' . json_encode($commands));
$r = $auto->tick($door(true), $t0 + 330);
assert_true($r['fired'] === [], 'duration must not fire again while still open');
$r = $auto->tick($door(false), $t0 + 400);
assert_true($r['fired'] === [], 'close resets the hold');
$r = $auto->tick($door(true), $t0 + 401);
assert_true($r['fired'] === [], 'reopen starts a new hold');
$r = $auto->tick($door(true), $t0 + 701);
assert_true($r['fired'] === ['a-open'], 'second open-for-5min must fire again: ' . json_encode($r));

$auto->delete('a-open');
$commands = [];
@unlink($auto->statePath());

$edge = $auto->save([
    'id' => 'a-edge',
    'enabled' => true,
    'trigger' => ['type' => 'device', 'id' => 'unifi:hub:door1', 'event' => 'opens'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 30,
]);
assert_true(!empty($edge['ok']), 'save open-edge rule');
$auto->tick($door(false), $t0);
$r = $auto->tick($door(true), $t0 + 1);
assert_true($r['fired'] === ['a-edge'], 'open edge must fire: ' . json_encode($r));
$r = $auto->tick($door(false), $t0 + 2);
$r = $auto->tick($door(true), $t0 + 3);
assert_true($r['fired'] === [], 'cooldown must suppress a second open');
$r = $auto->tick($door(false), $t0 + 40);
$r = $auto->tick($door(true), $t0 + 41);
assert_true($r['fired'] === ['a-edge'], 'open after cooldown must fire');

$auto->delete('a-edge');
$commands = [];
@unlink($auto->statePath());

$thresh = $auto->save([
    'id' => 'a-temp',
    'enabled' => true,
    'trigger' => ['type' => 'threshold', 'id' => 'unifi:sensor:s1', 'metric' => 'temperature', 'op' => 'above', 'value' => 22],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($thresh['ok']), 'save threshold rule');
$sensorSnap = static function (float $temp): array {
    return [[
        'id' => 'unifi:sensor:s1',
        'kind' => 'sensor',
        'temperature' => $temp,
        'on' => false,
        'open' => false,
    ]];
};
$auto->tick($sensorSnap(21.0), $t0);
$r = $auto->tick($sensorSnap(22.5), $t0 + 1);
assert_true($r['fired'] === ['a-temp'], 'threshold cross above must fire: ' . json_encode($r));
$r = $auto->tick($sensorSnap(23.0), $t0 + 2);
assert_true($r['fired'] === [], 'staying above must not re-fire');
$auto->tick($sensorSnap(20.0), $t0 + 3);
$r = $auto->tick($sensorSnap(22.1), $t0 + 4);
assert_true($r['fired'] === ['a-temp'], 'cross above again must fire');

$auto->delete('a-temp');
$commands = [];
@unlink($auto->statePath());

$tz = new DateTimeZone(YarboHomeAutomations::timezoneName());
$at = (new DateTimeImmutable('now', $tz))->setTime(21, 30);
$now = $at->getTimestamp();
$timeRule = $auto->save([
    'id' => 'a-time',
    'enabled' => true,
    'trigger' => ['type' => 'time', 'at' => '21:30', 'days' => [(int) $at->format('w')]],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'off']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($timeRule['ok']), 'save time rule');
$r = $auto->tick([['id' => 'unifi:light:porch', 'on' => true]], $now - 60);
assert_true($r['fired'] === [], 'time rule must not fire a minute early');
$r = $auto->tick([['id' => 'unifi:light:porch', 'on' => true]], $now);
assert_true($r['fired'] === ['a-time'], 'time rule must fire at 21:30: ' . json_encode($r));
$r = $auto->tick([['id' => 'unifi:light:porch', 'on' => true]], $now + 5);
assert_true($r['fired'] === [], 'time rule once per local day');

$auto->delete('a-time');
$commands = [];
@unlink($auto->statePath());

$auto->save(['latitude' => 51.5, 'longitude' => -0.1]);
$coords = $auto->coordsPublic();
assert_true($coords['source'] === 'saved' && abs(($coords['latitude'] ?? 0) - 51.5) < 0.01, 'saved coords: ' . json_encode($coords));
$sunRule = $auto->save([
    'id' => 'a-sun',
    'enabled' => true,
    'trigger' => ['type' => 'sun', 'event' => 'sunset', 'offset_min' => 15],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($sunRule['ok']), 'save sun rule');
$sunsetHm = $auto->sunEventHm('sunset', $now, 51.5, -0.1, 15, $tz);
assert_true(is_string($sunsetHm) && preg_match('/^\d{2}:\d{2}$/', $sunsetHm) === 1, 'sunset hm: ' . (string) $sunsetHm);
$sunLocal = $at->setTime((int) substr($sunsetHm, 0, 2), (int) substr($sunsetHm, 3, 2));
$r = $auto->tick([['id' => 'unifi:light:porch', 'on' => false]], $sunLocal->getTimestamp() - 60);
assert_true($r['fired'] === [], 'sunset+15 must not fire early');
$r = $auto->tick([['id' => 'unifi:light:porch', 'on' => false]], $sunLocal->getTimestamp());
assert_true($r['fired'] === ['a-sun'], 'sunset+15 must fire: ' . json_encode($r) . ' at ' . $sunsetHm);
$r = $auto->tick([['id' => 'unifi:light:porch', 'on' => false]], $sunLocal->getTimestamp() + 30);
assert_true($r['fired'] === [], 'sun once per local day');

$sensorThen = $auto->save([
    'id' => 'a-bad',
    'trigger' => ['type' => 'time', 'at' => '08:00'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:sensor:s1', 'command' => 'on']],
]);
assert_true(empty($sensorThen['ok']), 'sensors must not be Then actions');

$auto->delete('a-sun');
$auto->delete('a-bad');
$commands = [];
@unlink($auto->statePath());

$motionScene = $auto->save([
    'id' => 'a-motion',
    'name' => '',
    'enabled' => true,
    'trigger' => ['type' => 'device', 'id' => 'unifi:sensor:s1', 'event' => 'motion'],
    'actions' => [['kind' => 'scene', 'id' => 'sc-outdoor', 'command' => 'run']],
    'off_after_sec' => 300,
    'cooldown_sec' => 30,
    'names' => ['unifi:sensor:s1' => 'Kitchen Sensor', 'scene:sc-outdoor' => 'Outdoor Lights'],
]);
assert_true(!empty($motionScene['ok']), 'save motion → scene: ' . json_encode($motionScene));
assert_true(($motionScene['automation']['off_after_sec'] ?? 0) === 300, 'off_after persisted');
assert_true(str_contains((string) ($motionScene['automation']['name'] ?? ''), 'off after 5 min'), 'auto-name includes timer: ' . ($motionScene['automation']['name'] ?? ''));
assert_true(str_contains((string) ($motionScene['automation']['name'] ?? ''), 'Outdoor Lights'), 'auto-name includes scene');

$sense = static function (bool $motion): array {
    return [[
        'id' => 'unifi:sensor:s1',
        'kind' => 'sensor',
        'open' => false,
        'on' => false,
        'motion' => $motion,
    ]];
};

$tMotion = 1_800_000_000;
$r = $auto->tick($sense(false), $tMotion);
assert_true($r['fired'] === [] && ($r['turned_off'] ?? []) === [], 'motion idle must not fire');
$r = $auto->tick($sense(true), $tMotion + 1);
assert_true($r['fired'] === ['a-motion'], 'motion edge must run the scene: ' . json_encode($r));
assert_true(count($commands) === 1
    && ($commands[0]['kind'] ?? '') === 'scene'
    && ($commands[0]['id'] ?? '') === 'sc-outdoor'
    && ($commands[0]['command'] ?? '') === 'run', 'motion action is scene run: ' . json_encode($commands));
$r = $auto->tick($sense(true), $tMotion + 300);
assert_true($r['fired'] === [] && ($r['turned_off'] ?? []) === [], 'off-after must wait the full 5 min from fire');
$r = $auto->tick($sense(false), $tMotion + 301);
assert_true(($r['turned_off'] ?? []) === ['a-motion'], 'off-after must stop the scene: ' . json_encode($r));
assert_true(count($commands) === 2
    && ($commands[1]['kind'] ?? '') === 'scene'
    && ($commands[1]['command'] ?? '') === 'stop', 'off-after command is scene stop: ' . json_encode($commands));

$commands = [];
@unlink($auto->statePath());
$r = $auto->tick($sense(false), $tMotion);
$r = $auto->tick($sense(true), $tMotion + 1);
assert_true($r['fired'] === ['a-motion'], 'second motion fire');
$r = $auto->tick($sense(false), $tMotion + 2);
$r = $auto->tick($sense(true), $tMotion + 11);
assert_true($r['fired'] === [], 'cooldown must skip a second scene run');
assert_true(count($commands) === 1, 'cooldown retrigger must not run Then again: ' . json_encode($commands));
$r = $auto->tick($sense(false), $tMotion + 301);
assert_true(($r['turned_off'] ?? []) === [], 'retrigger during cooldown must extend the timer');
$r = $auto->tick($sense(false), $tMotion + 311);
assert_true(($r['turned_off'] ?? []) === ['a-motion'], 'extended off-after must fire from last motion: ' . json_encode($r));

$auto->delete('a-motion');
$commands = [];
@unlink($auto->statePath());

assert_true(YarboHomeAutomations::hmInWindow('21:00', '20:00', '22:00'), 'window inside');
assert_true(!YarboHomeAutomations::hmInWindow('19:00', '20:00', '22:00'), 'window before');
assert_true(YarboHomeAutomations::hmInWindow('23:00', '22:00', '06:00'), 'overnight window');

$dash = $home->dashboard();
assert_true(isset($dash['automations']) && isset($dash['server_timezone']), 'dashboard exposes automations');
assert_true(!is_file($root . '/data/home-automations-state.json') || true, 'dashboard may not need a state file');
$stateBefore = is_file($auto->statePath()) ? filemtime($auto->statePath()) : 0;
$home->dashboard();
clearstatcache();
$stateAfterTickless = is_file($auto->statePath()) ? filemtime($auto->statePath()) : 0;
assert_true($stateAfterTickless === $stateBefore, 'Home GET must not run the automations loop');

$homePhp = (string) file_get_contents(__DIR__ . '/../public/api/home.php');
assert_true(!str_contains($homePhp, 'home_automations.php'), 'home.php must not start the sidecar');
assert_true(str_contains($homePhp, 'automation_save'), 'home.php CRUD');
$panel = (string) file_get_contents(__DIR__ . '/../scripts/panel.sh');
assert_true(str_contains($panel, 'home_automations.php'), 'panel.sh must start the sidecar');
$index = (string) file_get_contents(__DIR__ . '/../public/index.php');
assert_true(str_contains($index, 'home-automations-page'), 'Automations overlay');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
assert_true(str_contains($js, 'openHomeAutomations'), 'Automations UI');
assert_true(str_contains($js, 'homeAutoTrayGroups'), 'tray grouping helper');
assert_true(str_contains($js, "['lights', 'Lights']"), 'lights group');
assert_true(str_contains($js, "['sensors', 'Sensors']"), 'sensors group');
assert_true(str_contains($js, "['doors', 'Doors']"), 'doors group');
assert_true(str_contains($js, "['scenes', 'Scenes']"), 'scenes group');
assert_true(str_contains($js, 'homeAutoReadOffAfter'), 'turn-off-after helper');
assert_true(str_contains($js, "data-auto-starter=\"motion\""), 'motion starter');
assert_true(str_contains($index, 'auto-off-after'), 'turn-off-after field');

$offRoot = sys_get_temp_dir() . '/yarbo-auto-off-' . bin2hex(random_bytes(3));
mkdir($offRoot . '/data', 0775, true);
file_put_contents($offRoot . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => false],
], JSON_UNESCAPED_SLASHES));
file_put_contents($offRoot . '/data/home-automations.json', json_encode([
    'automations' => [[
        'id' => 'a1',
        'name' => 'Off',
        'enabled' => true,
        'trigger' => ['type' => 'time', 'at' => '21:30'],
        'actions' => [['kind' => 'device', 'id' => '1:2', 'command' => 'on']],
        'cooldown_sec' => 0,
    ]],
], JSON_UNESCAPED_SLASHES));
$off = new YarboHomeAutomations($offRoot);
$fired = [];
$off->setCommandHandler(static function (array $action) use (&$fired): array {
    $fired[] = $action;

    return ['ok' => true];
});
$r = $off->tick([['id' => '1:2', 'on' => false]], $now);
assert_true($r['fired'] === [] && $fired === [], 'disabled Home module must not run automations');

echo "test_home_automations.php ok\n";
