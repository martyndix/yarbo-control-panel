<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHomeAutomations;
use Yarbo\YarboUnifi;

function assert_true(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "ok: {$msg}\n";
}

$rootFs = dirname(__DIR__);

$applied = YarboUnifi::applyCameraDetections(
    ['id' => 'unifi:camera:cam1', 'kind' => 'camera', 'native_id' => 'cam1'],
    [
        'person' => true,
        'detect_person_at' => 1_900_000_100,
        'motion' => true,
        'motion_at' => 1_900_000_100,
        'vehicle' => false,
        'detect_vehicle_at' => 0,
    ]
);
assert_true(!empty($applied['has_motion']), 'camera has_motion after detection overlay');
assert_true(($applied['person'] ?? false) === true, 'person flag set');
assert_true((int) ($applied['detect_person_at'] ?? 0) === 1_900_000_100, 'detect_person_at set');
assert_true((int) ($applied['motion_at'] ?? 0) === 1_900_000_100, 'motion_at set');

$tmp = sys_get_temp_dir() . '/yarbo-cam-detect-' . bin2hex(random_bytes(3));
mkdir($tmp . '/data', 0775, true);
file_put_contents($tmp . '/data/hub-config.json', json_encode([
    'modules' => ['home' => true, 'unifi' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($tmp . '/data/home.json', json_encode([
    'names' => [
        'unifi:camera:drive' => 'Driveway',
        'unifi:light:porch' => 'Porch',
    ],
    'automations' => [],
], JSON_UNESCAPED_SLASHES));

$auto = new YarboHomeAutomations($tmp);
$commands = [];
$auto->setCommandHandler(static function (array $action) use (&$commands): array {
    $commands[] = $action;

    return ['ok' => true];
});

$save = $auto->save([
    'id' => 'a-person',
    'enabled' => true,
    'name' => 'Driveway person',
    'trigger' => ['type' => 'device', 'id' => 'unifi:camera:drive', 'event' => 'person'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($save['ok']), 'save person When: ' . json_encode($save));

$t0 = 1_900_000_000;
$cam = static function (int $personAt, bool $person = false): array {
    return [
        [
            'id' => 'unifi:camera:drive',
            'kind' => 'camera',
            'on' => false,
            'motion' => $person,
            'motion_at' => $personAt,
            'person' => $person,
            'detect_person_at' => $personAt,
        ],
        [
            'id' => 'unifi:light:porch',
            'kind' => 'light',
            'on' => false,
        ],
    ];
};

$r = $auto->tick($cam(0, false), $t0);
assert_true(($r['fired'] ?? []) === [], 'idle: no fire');
$r = $auto->tick($cam($t0 + 5, true), $t0 + 5);
assert_true(($r['fired'] ?? []) === ['a-person'], 'person edge fires: ' . json_encode($r));
$r = $auto->tick($cam($t0 + 5, true), $t0 + 6);
assert_true(($r['fired'] ?? []) === [], 'same stamp does not re-fire');
$auto->delete('a-person');
@unlink($auto->statePath());

$within = $auto->save([
    'id' => 'a-person-if',
    'enabled' => true,
    'trigger' => ['type' => 'device', 'id' => 'unifi:light:porch', 'event' => 'turns_on'],
    'conditions' => [[
        'type' => 'device',
        'id' => 'unifi:camera:drive',
        'state' => 'person',
        'within_sec' => 600,
    ]],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($within['ok']), 'save person Only if within');
assert_true(
    (int) (($within['automation']['conditions'][0]['within_sec'] ?? 0)) === 600,
    'person within_sec persisted'
);

$devices = static function (bool $lightOn, int $personAt, bool $person = false): array {
    return [
        [
            'id' => 'unifi:camera:drive',
            'kind' => 'camera',
            'person' => $person,
            'detect_person_at' => $personAt,
            'motion' => $person,
            'motion_at' => $personAt,
        ],
        [
            'id' => 'unifi:light:porch',
            'kind' => 'light',
            'on' => $lightOn,
        ],
    ];
};
$t1 = 1_900_001_000;
$r = $auto->tick($devices(false, $t1 - 50, false), $t1);
assert_true(($r['fired'] ?? []) === [], 'within setup idle');
$r = $auto->tick($devices(true, $t1 - 50, false), $t1 + 1);
assert_true(($r['fired'] ?? []) === ['a-person-if'], 'Only if recent person stamp: ' . json_encode($r));

$js = (string) file_get_contents($rootFs . '/public/assets/app.js');
$index = (string) file_get_contents($rootFs . '/public/index.php');
$change = (string) file_get_contents($rootFs . '/CHANGELOG.md');
$py = (string) file_get_contents($rootFs . '/scripts/unifi_protect_events.py');
$panel = (string) file_get_contents($rootFs . '/scripts/panel.sh');

assert_true(str_contains($js, 'homeAutoCanSmartDetect'), 'UI smart detect helper');
assert_true(str_contains($js, "['person', 'person detected']"), 'UI person When event');
assert_true(str_contains($js, 'homeAutoCopyWhenToIf'), 'copy from When helper');
assert_true(str_contains($js, 'homeAutoCopyThenToIf'), 'copy from Then helper');
assert_true(str_contains($index, 'data-auto-if="copy-when"'), 'Copy from When button');
assert_true(str_contains($index, 'data-auto-if="copy-then"'), 'Copy from Then button');
assert_true(str_contains($py, 'subscribe/events'), 'subscriber uses Integration subscribe/events');
assert_true(str_contains($py, 'X-API-KEY'), 'subscriber uses API key');
assert_true(str_contains($py, 'merge_event_fragment'), 'subscriber merges partial updates');
assert_true(!str_contains($py, '/proxy/protect/api/events'), 'no private events API');
assert_true(str_contains($panel, 'unifi_protect_events.py'), 'panel starts subscriber');
assert_true(str_contains($panel, 'unifi_protect_events: exited'), 'panel restarts subscriber');
assert_true(str_contains($change, '## [4.0.276]'), 'changelog 4.0.276');

$tmpPe = sys_get_temp_dir() . '/yarbo-pe-status-' . bin2hex(random_bytes(3));
mkdir($tmpPe . '/data', 0775, true);
file_put_contents($tmpPe . '/data/unifi-config.json', json_encode([
    'host' => '192.168.1.1',
    'protect_api_key' => 'test-key',
], JSON_UNESCAPED_SLASHES));
file_put_contents($tmpPe . '/data/unifi-protect-events.json', json_encode([
    'updated_at' => time(),
    'subscriber_ok' => true,
    'last_error' => '',
    'event_hits' => 3,
    'last_event_at' => time() - 10,
    'cameras' => [],
], JSON_UNESCAPED_SLASHES));
$pe = (new YarboUnifi($tmpPe))->protectEventsStatus();
assert_true(!empty($pe['ok']), 'protectEventsStatus ok when subscribed: ' . json_encode($pe));
assert_true((int) ($pe['event_hits'] ?? 0) === 3, 'event_hits surfaced');

$phrase = YarboHomeAutomations::whenPhrase(
    ['type' => 'device', 'id' => 'unifi:camera:drive', 'event' => 'person'],
    ['unifi:camera:drive' => 'Driveway']
);
assert_true($phrase === 'Driveway person detected', 'whenPhrase person: ' . $phrase);

echo "ALL PASS\n";
