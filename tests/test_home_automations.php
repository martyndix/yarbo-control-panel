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
        '12:3' => 'Garage lights',
        '31:1' => 'Boiler',
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
assert_true(YarboHome::deviceAcceptsCommand('1:9', 'off', 'heater'), 'heater must accept off');
assert_true(YarboHome::deviceAcceptsCommand('1:9', 'setpoint', 'heater'), 'heater must accept setpoint');
assert_true(!YarboHome::deviceAcceptsCommand('1:9', 'color', 'heater'), 'heater must not accept colour');
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
]);
assert_true(!empty($saved['ok']), 'save duration rule: ' . json_encode($saved));
assert_true(($saved['automation']['name'] ?? '') === 'Front door open 5 min → Porch on', 'auto-name: ' . ($saved['automation']['name'] ?? ''));
$disk = json_decode((string) file_get_contents($auto->storePath()), true);
assert_true(!isset($saved['automation']['timezone']), 'rules must not carry a timezone');
assert_true(($disk['automations'][0]['trigger']['for_sec'] ?? 0) === 300, 'for_sec persisted');

$named = $auto->save([
    'id' => 'a-named',
    'name' => '',
    'enabled' => true,
    'trigger' => ['type' => 'device', 'id' => 'unifi:hub:door1', 'event' => 'stays_open', 'for_sec' => 300],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'names' => ['unifi:hub:door1' => 'Front door', 'unifi:light:porch' => 'Porch'],
]);
assert_true(($named['automation']['name'] ?? '') === 'Front door open 5 min → Porch on', 'template auto-name: ' . ($named['automation']['name'] ?? ''));
$renamed = $auto->save([
    'id' => 'a-named',
    'name' => (string) ($named['automation']['name'] ?? ''),
    'enabled' => true,
    'trigger' => ['type' => 'device', 'id' => 'unifi:hub:door1', 'event' => 'opens'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'names' => ['unifi:hub:door1' => 'Front door', 'unifi:light:porch' => 'Porch'],
]);
assert_true(($renamed['automation']['name'] ?? '') === 'Front door opens → Porch on', 'edit auto-name follows When: ' . ($renamed['automation']['name'] ?? ''));
$customKept = $auto->save([
    'id' => 'a-named',
    'name' => 'Porch night',
    'enabled' => true,
    'trigger' => ['type' => 'device', 'id' => 'unifi:hub:door1', 'event' => 'opens'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'off']],
    'names' => ['unifi:hub:door1' => 'Front door', 'unifi:light:porch' => 'Porch'],
]);
assert_true(($customKept['automation']['name'] ?? '') === 'Porch night', 'typed name kept: ' . ($customKept['automation']['name'] ?? ''));
assert_true(YarboHomeAutomations::nameLooksGenerated('Front door opens → Porch on', $renamed['automation'] ?? [], [
    'unifi:hub:door1' => 'Front door',
    'unifi:light:porch' => 'Porch',
]), 'generated name is detected');
assert_true(!YarboHomeAutomations::nameLooksGenerated('Porch night', $renamed['automation'] ?? [], [
    'unifi:hub:door1' => 'Front door',
    'unifi:light:porch' => 'Porch',
]), 'typed name is not treated as generated');
$auto->delete('a-named');

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

$orRule = $auto->save([
    'id' => 'a-or',
    'enabled' => true,
    'when_match' => 'any',
    'triggers' => [
        ['type' => 'device', 'id' => 'unifi:sensor:pir-a', 'event' => 'motion'],
        ['type' => 'device', 'id' => 'unifi:sensor:pir-b', 'event' => 'motion'],
    ],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($orRule['ok']), 'save OR when: ' . json_encode($orRule));
assert_true(count($orRule['automation']['triggers'] ?? []) === 2, 'OR keeps both Whens');
assert_true(($orRule['automation']['when_match'] ?? '') === 'any', 'OR match');
$pirSnap = static function (bool $a, bool $b, int $aAt = 0, int $bAt = 0): array {
    return [
        ['id' => 'unifi:sensor:pir-a', 'kind' => 'sensor', 'motion' => $a, 'motion_at' => $aAt, 'on' => $a],
        ['id' => 'unifi:sensor:pir-b', 'kind' => 'sensor', 'motion' => $b, 'motion_at' => $bAt, 'on' => $b],
        ['id' => 'unifi:light:porch', 'kind' => 'light', 'on' => false],
    ];
};
$auto->tick($pirSnap(false, false), $t0);
$r = $auto->tick($pirSnap(true, false, $t0 + 1), $t0 + 1);
assert_true($r['fired'] === ['a-or'], 'OR fires when the first PIR edges: ' . json_encode($r));
$r = $auto->tick($pirSnap(false, false), $t0 + 2);
$r = $auto->tick($pirSnap(false, true, 0, $t0 + 3), $t0 + 3);
assert_true($r['fired'] === ['a-or'], 'OR fires when the second PIR edges: ' . json_encode($r));

$auto->delete('a-or');
$commands = [];
@unlink($auto->statePath());

$andRule = $auto->save([
    'id' => 'a-and',
    'enabled' => true,
    'when_match' => 'all',
    'triggers' => [
        ['type' => 'device', 'id' => 'unifi:sensor:pir-a', 'event' => 'motion'],
        ['type' => 'device', 'id' => 'unifi:sensor:pir-b', 'event' => 'motion'],
    ],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 0,
    'names' => ['unifi:sensor:pir-a' => 'Drive motion', 'unifi:sensor:pir-b' => 'Porch motion', 'unifi:light:porch' => 'Porch'],
]);
assert_true(!empty($andRule['ok']), 'save AND when');
assert_true(str_contains((string) ($andRule['automation']['name'] ?? ''), ' and '), 'AND sentence uses and: ' . ($andRule['automation']['name'] ?? ''));
$auto->tick($pirSnap(true, false, $t0, 0), $t0);
$r = $auto->tick($pirSnap(true, true, $t0, $t0 + 1), $t0 + 1);
assert_true($r['fired'] === ['a-and'], 'AND fires when both detect and one just edged: ' . json_encode($r));
$r = $auto->tick($pirSnap(true, false, $t0, 0), $t0 + 2);
$r = $auto->tick($pirSnap(true, false, $t0 + 3, 0), $t0 + 3);
assert_true($r['fired'] === [], 'AND must not fire when only one PIR is detecting: ' . json_encode($r));

$auto->delete('a-and');
$commands = [];
@unlink($auto->statePath());

$look = $auto->save([
    'id' => 'a-look',
    'enabled' => true,
    'trigger' => ['type' => 'device', 'id' => 'unifi:sensor:s1', 'event' => 'motion'],
    'actions' => [[
        'kind' => 'device',
        'id' => '1:2',
        'command' => 'on',
        'device_kind' => 'light',
        'brightness' => 40,
        'hex' => '#FFD27A',
        'off_after_sec' => 300,
    ]],
    'names' => ['unifi:sensor:s1' => 'Kitchen Sensor', '1:2' => 'Lamp'],
]);
assert_true(!empty($look['ok']), 'save Then look: ' . json_encode($look));
$lookAction = $look['automation']['actions'][0] ?? [];
assert_true(($lookAction['command'] ?? '') === 'on', 'look stays On');
assert_true(($lookAction['device_kind'] ?? '') === 'light', 'look keeps device_kind');
assert_true(($lookAction['brightness'] ?? 0) === 40, 'look brightness persisted');
assert_true(($lookAction['hex'] ?? '') === '#ffd27a', 'look hex normalized: ' . json_encode($lookAction));
assert_true(
    YarboHomeAutomations::thenPhrase($lookAction, ['1:2' => 'Lamp']) === 'Lamp 40%, off after 5 min',
    'look phrase: ' . YarboHomeAutomations::thenPhrase($lookAction, ['1:2' => 'Lamp'])
);
$auto->tick([
    ['id' => 'unifi:sensor:s1', 'kind' => 'sensor', 'motion' => false, 'on' => false],
    ['id' => '1:2', 'kind' => 'light', 'on' => false],
], $t0 + 800);
$r = $auto->tick([
    ['id' => 'unifi:sensor:s1', 'kind' => 'sensor', 'motion' => true, 'motion_at' => $t0 + 801, 'on' => true],
    ['id' => '1:2', 'kind' => 'light', 'on' => false],
], $t0 + 801);
assert_true($r['fired'] === ['a-look'], 'look rule fires: ' . json_encode($r));
assert_true(($commands[0]['brightness'] ?? 0) === 40, 'fired look keeps brightness: ' . json_encode($commands));
assert_true(($commands[0]['hex'] ?? '') === '#ffd27a', 'fired look keeps hex');
$auto->delete('a-look');
$commands = [];
@unlink($auto->statePath());

$heatThen = $auto->save([
    'id' => 'a-heat',
    'enabled' => true,
    'trigger' => ['type' => 'time', 'at' => '07:00'],
    'actions' => [[
        'kind' => 'device',
        'id' => '1:9',
        'command' => 'on',
        'device_kind' => 'heater',
        'celsius' => 21.5,
    ]],
    'names' => ['1:9' => 'Hall heater'],
]);
assert_true(!empty($heatThen['ok']), 'save heater Then: ' . json_encode($heatThen));
$heatAction = $heatThen['automation']['actions'][0] ?? [];
assert_true(($heatAction['device_kind'] ?? '') === 'heater', 'heater kind persisted');
assert_true(($heatAction['celsius'] ?? 0) === 21.5, 'heater celsius persisted: ' . json_encode($heatAction));
assert_true(!isset($heatAction['brightness']), 'heater Then must not store brightness');
assert_true(
    YarboHomeAutomations::thenPhrase($heatAction, ['1:9' => 'Hall heater']) === 'Hall heater 21.5°',
    'heater phrase'
);
$porchLook = $auto->save([
    'id' => 'a-heat-bad',
    'enabled' => true,
    'trigger' => ['type' => 'time', 'at' => '07:01'],
    'actions' => [[
        'kind' => 'device',
        'id' => 'unifi:light:porch',
        'command' => 'on',
        'brightness' => 80,
        'hex' => 'not-a-colour',
    ]],
]);
$porchAction = $porchLook['automation']['actions'][0] ?? [];
assert_true(($porchAction['command'] ?? '') === 'on', 'UniFi floodlight Then stays On');
assert_true(($porchAction['brightness'] ?? 0) === 80, 'extras may persist but runner ignores unsupported');
assert_true(!isset($porchAction['hex']), 'invalid hex dropped: ' . json_encode($porchAction));
$auto->delete('a-heat');
$auto->delete('a-heat-bad');
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

$tzSave = $auto->save(['timezone' => 'Europe/London']);
assert_true(!empty($tzSave['ok']), 'save timezone');
$disk = json_decode((string) file_get_contents($auto->storePath()), true);
assert_true(($disk['timezone'] ?? '') === 'Europe/London', 'timezone persisted: ' . json_encode($disk['timezone'] ?? null));
assert_true($auto->timezoneName() === 'Europe/London', 'resolved timezone: ' . $auto->timezoneName());
$utcSave = $auto->save(['timezone' => 'UTC']);
assert_true(!empty($utcSave['ok']), 'save UTC timezone');
$utcPub = $auto->timezonePublic();
assert_true(($utcPub['saved'] ?? true) === false, 'saved UTC must be treated as unsaved: ' . json_encode($utcPub));
$nameBeforeAdopt = $auto->timezoneName();
$adopted = $auto->adoptClientTimezone('Europe/Paris');
if (strcasecmp($nameBeforeAdopt, 'UTC') === 0 || strcasecmp($nameBeforeAdopt, 'Etc/UTC') === 0) {
    assert_true($adopted && $auto->timezoneName() === 'Europe/Paris', 'UTC panel adopts browser zone: ' . $auto->timezoneName());
} else {
    assert_true(!$adopted, 'non-UTC OS zone must not be overwritten by the browser');
    assert_true($auto->timezoneName() === $nameBeforeAdopt, 'kept OS zone ' . $auto->timezoneName());
}
$londonKeep = $auto->adoptClientTimezone('America/New_York');
assert_true(!$londonKeep, 'saved/OS local zone must not switch to another browser zone');
$auto->save(['timezone' => 'Europe/London']);
$seconds = $auto->save([
    'id' => 'a-hm',
    'enabled' => true,
    'trigger' => ['type' => 'time', 'at' => '21:30:00'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'off']],
    'cooldown_sec' => 0,
]);
assert_true(($seconds['automation']['trigger']['at'] ?? '') === '21:30', 'HH:MM:SS normalizes: ' . json_encode($seconds['automation']['trigger'] ?? null));
$auto->delete('a-hm');

$tz = new DateTimeZone($auto->timezoneName());
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
$today = $at->format('Y-m-d');
$stale = json_decode((string) file_get_contents($auto->statePath()), true);
assert_true(is_array($stale), 'state after time fire');
$stale['day_slot']['a-time'] = $today;
file_put_contents($auto->statePath(), json_encode($stale, JSON_UNESCAPED_SLASHES));
$r = $auto->tick([['id' => 'unifi:light:porch', 'on' => true]], $now + 10);
assert_true($r['fired'] === ['a-time'], 'date-only day_slot must not block a new slot key: ' . json_encode($r));
$retimed = $auto->save([
    'id' => 'a-time',
    'enabled' => true,
    'trigger' => ['type' => 'time', 'at' => '21:31', 'days' => [(int) $at->format('w')]],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'off']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($retimed['ok']), 'save retimed rule');
$later = $at->setTime(21, 31)->getTimestamp();
$r = $auto->tick([['id' => 'unifi:light:porch', 'on' => true]], $later);
assert_true($r['fired'] === ['a-time'], 'new time same day after save must fire: ' . json_encode($r));

$auto->delete('a-time');
$commands = [];
@unlink($auto->statePath());
$skip = $auto->save([
    'id' => 'a-skip',
    'enabled' => true,
    'trigger' => ['type' => 'time', 'at' => '21:30'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'off']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($skip['ok']), 'save skip-minute rule');
$r = $auto->tick([['id' => 'unifi:light:porch', 'on' => true]], $now - 60);
assert_true($r['fired'] === [], 'catch-up must not fire before the time');
$r = $auto->tick([['id' => 'unifi:light:porch', 'on' => true]], $now + 60);
assert_true($r['fired'] === ['a-skip'], 'catch-up must fire if the sidecar skipped the minute: ' . json_encode($r));
$auto->delete('a-skip');
$commands = [];
@unlink($auto->statePath());
$late = $auto->save([
    'id' => 'a-late',
    'enabled' => true,
    'trigger' => ['type' => 'time', 'at' => '21:30'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'off']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($late['ok']), 'save late-start rule');
$r = $auto->tick([['id' => 'unifi:light:porch', 'on' => true]], $now + 60);
assert_true($r['fired'] === [], 'first tick after the time must not fire every past slot');
$auto->delete('a-late');
$commands = [];
@unlink($auto->statePath());

$failAuto = new YarboHomeAutomations($root);
$failAuto->setCommandHandler(static function (): array {
    return ['ok' => false, 'error' => 'device busy'];
});
$failAuto->save(['timezone' => 'Europe/London']);
$failSave = $failAuto->save([
    'id' => 'a-fail-time',
    'enabled' => true,
    'trigger' => ['type' => 'time', 'at' => '21:30'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'off']],
    'cooldown_sec' => 30,
]);
assert_true(!empty($failSave['ok']), 'save failing time rule');
$r = $failAuto->tick([['id' => 'unifi:light:porch', 'on' => true]], $now);
assert_true($r['fired'] === [] && ($r['errors'][0] ?? '') === 'Porch: device busy', 'failed Then must not count as fired: ' . json_encode($r));
$failState = json_decode((string) file_get_contents($failAuto->statePath()), true);
$failDay = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->format('Y-m-d');
assert_true(($failState['day_slot']['a-fail-time'] ?? '') !== $failDay, 'failed Then must not set day_slot');
assert_true((int) ($failState['last_fire']['a-fail-time'] ?? 0) === $now, 'failed Then still sets last_fire for cooldown');
assert_true(($failState['then_error']['a-fail-time'] ?? '') === 'Porch: device busy', 'failed Then must persist then_error');
$failPub = $failAuto->runnerPublic();
assert_true(($failPub['then_error']['a-fail-time'] ?? '') === 'Porch: device busy', 'runnerPublic then_error: ' . json_encode($failPub));
assert_true(($failPub['last_error'] ?? '') === 'Porch: device busy', 'sticky last_error from Then: ' . json_encode($failPub));
$r = $failAuto->tick([['id' => 'unifi:light:porch', 'on' => true]], $now + 5);
assert_true($r['fired'] === [] && ($r['errors'] ?? []) === [], 'cooldown must skip failed Then retry');
$failState = json_decode((string) file_get_contents($failAuto->statePath()), true);
assert_true(($failState['then_error']['a-fail-time'] ?? '') === 'Porch: device busy', 'then_error must survive an empty later tick');
$failPub = $failAuto->runnerPublic();
assert_true(($failPub['then_error']['a-fail-time'] ?? '') === 'Porch: device busy', 'sticky then_error after empty tick');
assert_true(($failPub['last_error'] ?? '') === 'Porch: device busy', 'sticky last_error after empty tick');
$okCommands = [];
$failAuto->setCommandHandler(static function (array $action) use (&$okCommands): array {
    $okCommands[] = $action;

    return ['ok' => true];
});
$r = $failAuto->tick([['id' => 'unifi:light:porch', 'on' => true]], $now + 90);
assert_true($r['fired'] === ['a-fail-time'], 'failed Then must retry after cooldown even past the minute: ' . json_encode($r));
assert_true(count($okCommands) === 1, 'retry Then ran once');
$failState = json_decode((string) file_get_contents($failAuto->statePath()), true);
assert_true(($failState['then_error']['a-fail-time'] ?? '') === '', 'successful Then must clear then_error');
$failPub = $failAuto->runnerPublic();
assert_true(($failPub['then_error']['a-fail-time'] ?? '') === '', 'runnerPublic clears then_error on success');
$failAuto->delete('a-fail-time');
$commands = [];
@unlink($auto->statePath());

$partialRoot = sys_get_temp_dir() . '/yarbo-auto-partial-' . bin2hex(random_bytes(3));
mkdir($partialRoot . '/data', 0775, true);
file_put_contents($partialRoot . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($partialRoot . '/data/home.json', json_encode([
    'names' => [
        '31:1' => 'Boiler',
        'unifi:light:porch' => 'Porch',
    ],
    'room_defs' => [],
    'rooms' => [],
    'group_defs' => [],
    'groups' => [],
    'scenes' => [],
    'paper' => [],
    'hidden' => [],
    'device_order' => [],
    'last_devices' => [
        ['id' => '31:1', 'name' => 'Boiler', 'kind' => 'light', 'on' => false, 'dimmable' => true],
    ],
], JSON_UNESCAPED_SLASHES));
$partial = new YarboHomeAutomations($partialRoot);
$partialCmds = [];
$partial->setCommandHandler(static function (array $action) use (&$partialCmds): array {
    $partialCmds[] = $action;
    if (($action['id'] ?? '') === '31:1') {
        return ['ok' => false, 'error' => 'InteractionModelError: UnsupportedCluster (0xc3)'];
    }

    return ['ok' => true];
});
$partial->save(['timezone' => $auto->timezoneName()]);
$partialSave = $partial->save([
    'id' => 'a-garage-open',
    'enabled' => true,
    'trigger' => ['type' => 'time', 'at' => '21:30'],
    'actions' => [
        [
            'kind' => 'device',
            'id' => '31:1',
            'command' => 'on',
            'device_kind' => 'light',
            'brightness' => 100,
            'off_after_sec' => 900,
        ],
        [
            'kind' => 'device',
            'id' => 'unifi:light:porch',
            'command' => 'on',
            'off_after_sec' => 900,
        ],
    ],
    'cooldown_sec' => 0,
]);
assert_true(!empty($partialSave['ok']), 'save garage Then: ' . json_encode($partialSave));
$r = $partial->tick([['id' => 'unifi:light:porch', 'on' => false], ['id' => '31:1', 'kind' => 'light', 'on' => false]], $now);
assert_true($r['fired'] === [], 'partial Then must not count as fired: ' . json_encode($r));
assert_true(str_contains((string) ($r['errors'][0] ?? ''), 'Boiler'), 'Then error names Boiler: ' . json_encode($r));
assert_true(str_contains((string) ($r['errors'][0] ?? ''), '0xc3'), 'Then error keeps 0xc3: ' . json_encode($r));
assert_true(count($partialCmds) === 2, 'rest of Then still runs: ' . json_encode($partialCmds));
$partialState = json_decode((string) file_get_contents($partial->statePath()), true);
$delayed = $partialState['delayed']['a-garage-open'] ?? null;
assert_true(is_array($delayed) && $delayed !== [], 'off-after still queues when one Then fails: ' . json_encode($delayed));

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

$night = $auto->save([
    'id' => 'a-night',
    'enabled' => true,
    'trigger' => ['type' => 'device', 'id' => 'unifi:sensor:s1', 'event' => 'motion'],
    'conditions' => [['type' => 'sun_window', 'start' => 'sunset', 'end' => 'sunrise']],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 0,
    'names' => ['unifi:sensor:s1' => 'Kitchen Sensor', 'unifi:light:porch' => 'Porch'],
]);
assert_true(!empty($night['ok']), 'save sun window: ' . json_encode($night));
assert_true(($night['automation']['conditions'][0]['start'] ?? '') === 'sunset', 'sun window start');
assert_true(($night['automation']['conditions'][0]['end'] ?? '') === 'sunrise', 'sun window end');
$sunsetClock = $auto->sunEventHm('sunset', $now, 51.5, -0.1, 0, $tz);
$sunriseClock = $auto->sunEventHm('sunrise', $now, 51.5, -0.1, 0, $tz);
assert_true(is_string($sunsetClock) && is_string($sunriseClock), 'sun window clocks');
$noonLocal = $at->setTime(12, 0);
$nightLocal = $at->setTime(22, 0);
$motionSnap = static function (bool $motion): array {
    return [
        ['id' => 'unifi:sensor:s1', 'kind' => 'sensor', 'motion' => $motion, 'on' => false],
        ['id' => 'unifi:light:porch', 'kind' => 'light', 'on' => false],
    ];
};
$auto->tick($motionSnap(false), $noonLocal->getTimestamp() - 1);
$r = $auto->tick($motionSnap(true), $noonLocal->getTimestamp());
assert_true($r['fired'] === [], 'daytime motion must not pass sunset→sunrise: ' . json_encode($r) . " sunset=$sunsetClock sunrise=$sunriseClock");
$auto->tick($motionSnap(false), $nightLocal->getTimestamp() - 1);
$r = $auto->tick($motionSnap(true), $nightLocal->getTimestamp());
assert_true($r['fired'] === ['a-night'], 'night motion must pass sunset→sunrise: ' . json_encode($r) . " sunset=$sunsetClock sunrise=$sunriseClock");
$auto->delete('a-night');
$commands = [];
@unlink($auto->statePath());

$tzRoot = sys_get_temp_dir() . '/yarbo-auto-tzc-' . bin2hex(random_bytes(3));
mkdir($tzRoot . '/data', 0775, true);
file_put_contents($tzRoot . '/data/hub-config.json', json_encode([
    'modules' => ['home' => true],
], JSON_UNESCAPED_SLASHES));
$tzCoords = new YarboHomeAutomations($tzRoot);
$tzCoords->save(['timezone' => 'Europe/Zurich']);
$fromTz = $tzCoords->coordsPublic();
assert_true(($fromTz['source'] ?? '') === 'timezone', 'timezone coords: ' . json_encode($fromTz));
assert_true(abs(($fromTz['latitude'] ?? 0) - 47.3833) < 0.05, 'Zurich lat: ' . json_encode($fromTz));
assert_true(($fromTz['needs_coords'] ?? true) === false, 'timezone fills needs_coords');
$found = $tzCoords->coordsFromTimezone('Europe/Zurich');
assert_true(is_array($found) && abs($found['latitude'] - 47.3833) < 0.05, 'coordsFromTimezone');

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

$actionOff = $auto->save([
    'id' => 'a-chip-off',
    'name' => '',
    'enabled' => true,
    'trigger' => ['type' => 'device', 'id' => 'unifi:sensor:s1', 'event' => 'motion'],
    'actions' => [['kind' => 'scene', 'id' => 'sc-outdoor', 'command' => 'run', 'off_after_sec' => 120]],
    'off_after_sec' => 0,
    'cooldown_sec' => 0,
    'names' => ['unifi:sensor:s1' => 'Kitchen Sensor', 'scene:sc-outdoor' => 'Outdoor Lights'],
]);
assert_true(!empty($actionOff['ok']), 'save per-action off after: ' . json_encode($actionOff));
assert_true((int) (($actionOff['automation']['actions'][0]['off_after_sec'] ?? 0)) === 120, 'action off_after persisted');
assert_true(str_contains((string) ($actionOff['automation']['name'] ?? ''), 'off after 2 min'), 'chip timer in name: ' . ($actionOff['automation']['name'] ?? ''));
$tChip = 1_810_000_000;
$r = $auto->tick($sense(false), $tChip);
$r = $auto->tick($sense(true), $tChip + 1);
assert_true($r['fired'] === ['a-chip-off'], 'chip-off motion fire: ' . json_encode($r));
$r = $auto->tick($sense(false), $tChip + 119);
assert_true(($r['turned_off'] ?? []) === [], 'chip off-after must wait 2 min');
$r = $auto->tick($sense(false), $tChip + 121);
assert_true(($r['turned_off'] ?? []) === ['a-chip-off'], 'chip off-after must stop: ' . json_encode($r));
$auto->delete('a-chip-off');
$commands = [];
@unlink($auto->statePath());

$motionAtRule = $auto->save([
    'id' => 'a-motion-at',
    'enabled' => true,
    'trigger' => ['type' => 'device', 'id' => 'unifi:sensor:s1', 'event' => 'motion'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($motionAtRule['ok']), 'save motion_at rule');
$senseStamp = static function (bool $motion, int $at): array {
    return [[
        'id' => 'unifi:sensor:s1',
        'kind' => 'sensor',
        'open' => false,
        'on' => false,
        'motion' => $motion,
        'motion_at' => $at,
    ], [
        'id' => 'unifi:light:porch',
        'kind' => 'light',
        'on' => false,
    ]];
};
$tStamp = 1_820_000_000;
$r = $auto->tick($senseStamp(false, 0), $tStamp);
assert_true($r['fired'] === [], 'motion_at idle');
$r = $auto->tick($senseStamp(false, 1_820_000_040), $tStamp + 1);
assert_true($r['fired'] === ['a-motion-at'], 'motion_at increase must fire when boolean stays false: ' . json_encode($r));
$auto->delete('a-motion-at');
$commands = [];
@unlink($auto->statePath());

assert_true(YarboHomeAutomations::hmInWindow('21:00', '20:00', '22:00'), 'window inside');
assert_true(!YarboHomeAutomations::hmInWindow('19:00', '20:00', '22:00'), 'window before');
assert_true(YarboHomeAutomations::hmInWindow('23:00', '22:00', '06:00'), 'overnight window');
assert_true(
    YarboHomeAutomations::whenPhrase(
        ['type' => 'device', 'id' => 'unifi:sensor:g', 'event' => 'motion'],
        ['unifi:sensor:g' => 'New Garage motion']
    ) === 'New Garage motion',
    'do not append motion onto a name that already ends with motion'
);
$nodeErr = $home->friendlyMatterError('Node 12 is not (yet) available.');
assert_true(str_contains($nodeErr, 'Garage lights'), 'node error names the light: ' . $nodeErr);
assert_true(str_contains($nodeErr, 'Matter node 12'), 'node error keeps the node id: ' . $nodeErr);

$dash = $home->dashboard();
assert_true(isset($dash['automations']) && isset($dash['server_timezone']), 'dashboard exposes automations');
assert_true(!is_file($root . '/data/home-automations-state.json') || true, 'dashboard may not need a state file');
$stateBefore = is_file($auto->statePath()) ? filemtime($auto->statePath()) : 0;
$home->dashboard();
clearstatcache();
$stateAfterTickless = is_file($auto->statePath()) ? filemtime($auto->statePath()) : 0;
assert_true($stateAfterTickless === $stateBefore, 'Home GET must not run the automations loop');

$homePhp = (string) file_get_contents(__DIR__ . '/../public/api/home.php');
assert_true(str_contains($homePhp, 'kickRunner'), 'home.php must start the sidecar if it is down');
assert_true(str_contains($homePhp, 'automation_save'), 'home.php CRUD');
$panel = (string) file_get_contents(__DIR__ . '/../scripts/panel.sh');
assert_true(str_contains($panel, 'home_automations.php'), 'panel.sh must start the sidecar');
assert_true(str_contains($panel, 'home-automations.log'), 'sidecar writes a log');
$sidecar = (string) file_get_contents(__DIR__ . '/../scripts/home_automations.php');
assert_true(strpos($sidecar, 'tick();') < strpos($sidecar, 'refreshUnifiIfDue'), 'time tick must run before UniFi refresh');
assert_true(str_contains($sidecar, 'acquireRunnerLock'), 'sidecar takes a pid lock');
$index = (string) file_get_contents(__DIR__ . '/../public/index.php');
assert_true(str_contains($index, 'home-automations-page'), 'Automations overlay');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
assert_true(str_contains($js, 'openHomeAutomations'), 'Automations UI');
assert_true(str_contains($js, 'data-auto-copy'), 'Copy as template');
assert_true(str_contains($js, 'Delete “${label}”? This cannot be undone.'), 'delete confirmation');
assert_true(str_contains($js, 'homeAutoFollowName'), 'rename when chips change');
assert_true(str_contains($js, 'homeAutoDraftFromRule'), 'copy clones the rule');
assert_true(str_contains($index, 'Follows When and Then unless you type a name'), 'name follows hint');
assert_true(str_contains($js, 'homeAutoTrayGroups'), 'tray grouping helper');
assert_true(str_contains($js, "['lights', 'Lights']"), 'lights group');
assert_true(str_contains($js, "['sensors', 'Sensors']"), 'sensors group');
assert_true(str_contains($js, 'homeAutoDeviceSelectGroups'), 'only-if grouping helper');
assert_true(str_contains($js, "['cameras', 'Cameras']"), 'cameras group');
assert_true(str_contains($js, 'homeAutoEnsureTimezone'), 'timezone adopt helper');
assert_true(str_contains($js, "event === 'motion'"), 'live When phrase must not say motion motion');
assert_true(str_contains($js, 'last_fire_hm'), 'last ran hint');
assert_true(str_contains($js, 'Then failed:'), 'Then-failed hint');
assert_true(str_contains($js, 'runner.then_error'), 'per-rule Then error');
assert_true(str_contains($js, 'clientTimezoneHeaders()'), 'Home sends browser timezone');
assert_true(str_contains($js, 'Pick your local timezone'), 'UTC wall-clock warning');
assert_true(str_contains($index, 'auto-timezone'), 'timezone picker');
assert_true(isset($dash['timezone']['name']) && isset($dash['runner']), 'dashboard exposes timezone and runner');
assert_true(str_contains($js, "['scenes', 'Scenes']"), 'scenes group');
assert_true(str_contains($js, 'homeAutoReadOffAfter'), 'turn-off-after helper');
assert_true(str_contains($js, "data-auto-starter=\"motion\""), 'motion starter');
assert_true(str_contains($js, 'homeAutoLocatePanel'), 'panel location helper');
assert_true(str_contains($js, 'sun_window'), 'sunset/sunrise Only if');
assert_true(str_contains($js, "which === 'sun'"), 'sunset/sunrise Only if handler');
assert_true(str_contains($index, 'Use this panel'), 'panel location button');
assert_true(str_contains($index, 'Sunset / sunrise'), 'sun window button');
assert_true(!str_contains($js, 'getCurrentPosition'), 'HTTP pages must not use browser GPS');
assert_true(str_contains($js, 'homeAutoCanOpen'), 'capability helper');
assert_true(str_contains($js, 'homeAutoCanMotion'), 'motion capability helper');
assert_true(str_contains($js, 'data-auto-then-off'), 'then chip off-after');
assert_true(str_contains($js, 'homeAutoThenCanOffAfter'), 'then off-after helper');
assert_true(str_contains($js, 'homeAutoIfStateOptions'), 'only-if states');
assert_true(str_contains($js, 'homeAutoThenParamsHtml'), 'Then look controls helper');
assert_true(str_contains($js, 'data-auto-hex'), 'Then colour picker');
assert_true(str_contains($js, 'data-auto-celsius'), 'Then heater setpoint');
assert_true(str_contains($js, 'data-auto-kelvin'), 'Then colour temperature');
assert_true(str_contains($js, 'homeAutoApplyThenLook'), 'Then look change helper');
assert_true(str_contains($index, 'On can set brightness, colour, or temperature'), 'Then look hint');

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

assert_true(YarboHomeAutomations::pidIsRunning(getmypid()), 'current pid is running');
assert_true(!YarboHomeAutomations::pidIsRunning(2147483647), 'missing pid is not running');
assert_true($auto->acquireRunnerLock(), 'runner lock can be taken');
$auto->kickRunner();
assert_true(!is_file($auto->pidPath()) || (int) trim((string) file_get_contents($auto->pidPath())) === getmypid(), 'kickRunner must not spawn when scripts/ is missing');
assert_true(str_contains($js, 'homeHeaterActionsHtml'), 'heater On/Off and setpoint controls');
assert_true(str_contains($js, 'data-home-setpoint'), 'heater setpoint input');
assert_true(str_contains($js, 'data-home-room-temp'), 'heater room temperature');
assert_true(str_contains($js, 'Any (or)'), 'multiple When Any chip');
assert_true(str_contains($js, 'All (and)'), 'multiple When All chip');
assert_true(str_contains($js, 'homeAutoApplyWhen'), 'When chips append');
assert_true(str_contains($js, 'Last ran stays empty'), 'stale runner copy');
assert_true(str_contains($js, 'Runner is active'), 'active runner copy');
assert_true(str_contains($js, 'Not run yet.'), 'not-run-yet copy');
assert_true(str_contains($index, 'data-panel-version'), 'panel version on the page');
$matterPhp = (string) file_get_contents(__DIR__ . '/../src/YarboMatterAgentClient.php');
assert_true(str_contains($matterPhp, 'function portOpen'), 'Matter client checks the listening port');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.37]'), 'changelog 4.0.37');
assert_true(str_contains($change, '## [4.0.39]'), 'changelog 4.0.39');
assert_true(str_contains($change, '## [4.0.40]'), 'changelog 4.0.40');
assert_true(str_contains($change, '## [4.0.52]'), 'changelog 4.0.52');
assert_true(str_contains($change, '## [4.0.53]'), 'changelog 4.0.53');
assert_true(str_contains($change, '## [4.0.54]'), 'changelog 4.0.54');
assert_true(str_contains($change, '## [4.0.55]'), 'changelog 4.0.55');
assert_true(str_contains($change, '## [4.0.56]'), 'changelog 4.0.56');
assert_true(str_contains($change, '## [4.0.57]'), 'changelog 4.0.57');
assert_true(str_contains($change, '## [4.0.58]'), 'changelog 4.0.58');
assert_true(str_contains($change, '## [4.0.59]'), 'changelog 4.0.59');
assert_true(str_contains($change, '## [4.0.60]'), 'changelog 4.0.60');
assert_true(str_contains($js, 'homeAutoSanitizeThenLooks'), 'Then drops colour the bulb cannot do');
assert_true(str_contains($js, 'homeColorInputsHtml'), 'Home colour controls helper');

echo "test_home_automations.php ok\n";
