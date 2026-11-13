<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/yarbo-night-auto-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
mkdir($root . '/src', 0775, true);

require dirname(__DIR__) . '/vendor/autoload.php';

use Yarbo\YarboHomeAutomations;
use Yarbo\YarboVestaboard;

function assert_true(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "ok: {$msg}\n";
}

file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['home' => true, 'unifi' => true, 'vestaboard' => true],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'contact_kind' => [
        'unifi:door:garage' => 'door',
        'unifi:sensor:kitchen-window' => 'window',
    ],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-config.json', json_encode([
    'enabled' => true,
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-inventory.json', json_encode([
    'doors' => [[
        'id' => 'unifi:door:garage',
        'name' => 'Garage',
        'lockable' => true,
        'open' => false,
        'dps' => 'close',
        'dps_label' => 'Closed',
    ]],
    'sensors' => [[
        'id' => 'unifi:sensor:kitchen-window',
        'name' => 'Kitchen Window',
        'kind' => 'sensor',
        'has_open' => true,
        'open' => false,
    ]],
], JSON_UNESCAPED_SLASHES));

$nowHm = (new DateTimeImmutable('now'))->format('H:i');
$start = $nowHm;
$endParts = explode(':', $nowHm);
$endH = ((int) $endParts[0] + 2) % 24;
$end = sprintf('%02d:%s', $endH, $endParts[1]);

file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'night_hours_enabled' => true,
    'night_start' => $start,
    'night_end' => $end,
    'night_active' => true,
    'night_last_open' => false,
    'night_last_open_key' => '',
], JSON_UNESCAPED_SLASHES));

$auto = new YarboHomeAutomations($root);
$auto->setCommandHandler(static function () {
    return ['ok' => true];
});

$saved = $auto->save([
    'id' => 'a-night-open',
    'name' => 'Night open → porch',
    'enabled' => true,
    'cooldown_sec' => 0,
    'trigger' => ['type' => 'vestaboard_night', 'event' => 'open'],
    'actions' => [['kind' => 'device', 'id' => 'light:porch', 'command' => 'on']],
]);
assert_true(!empty($saved['ok']), 'save night open rule: ' . json_encode($saved));
$store = $auto->load();
$trig = $store['automations'][0]['triggers'][0]
    ?? $store['automations'][0]['trigger']
    ?? null;
assert_true(($trig['type'] ?? '') === 'vestaboard_night', 'normalized type: ' . json_encode($store['automations'][0] ?? null));
assert_true(
    YarboHomeAutomations::whenPhrase(['type' => 'vestaboard_night', 'event' => 'open']) === 'Night door/window open',
    'when phrase'
);

$closed = [[
    'id' => YarboHomeAutomations::VESTABOARD_NIGHT_ID,
    'kind' => 'vestaboard_night',
    'name' => 'Night door/window',
    'open' => false,
]];
$opened = [[
    'id' => YarboHomeAutomations::VESTABOARD_NIGHT_ID,
    'kind' => 'vestaboard_night',
    'name' => 'Night door/window',
    'open' => true,
]];

$t0 = time();
$r = $auto->tick($closed, $t0);
assert_true(($r['fired'] ?? []) === [], 'no fire while closed');
$r = $auto->tick($opened, $t0 + 1);
assert_true(($r['fired'] ?? []) === ['a-night-open'], 'fires on open edge: ' . json_encode($r));
$r = $auto->tick($opened, $t0 + 2);
assert_true(($r['fired'] ?? []) === [], 'no re-fire while still open');

// Night hours disabled → live row null → dormant (no edge).
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'night_hours_enabled' => false,
    'night_start' => $start,
    'night_end' => $end,
], JSON_UNESCAPED_SLASHES));
$vb = new YarboVestaboard($root);
assert_true($auto->vestaboardNightLiveRow() === null, 'live row null when night hours off');

// Persist rule still present while preference off.
$store = $auto->load();
$trig = $store['automations'][0]['triggers'][0]
    ?? $store['automations'][0]['trigger']
    ?? null;
assert_true(($trig['type'] ?? '') === 'vestaboard_night', 'rule kept when night off');

$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
$php = (string) file_get_contents(dirname(__DIR__) . '/src/YarboHomeAutomations.php');
$watch = (string) file_get_contents(dirname(__DIR__) . '/scripts/vestaboard_watch.php');
assert_true(str_contains($js, 'homeAutoNightHoursOn'), 'UI gate helper');
assert_true(str_contains($js, 'Night door/window open'), 'UI chip label');
assert_true(str_contains($js, "kind === 'vestaboard_night'"), 'tray apply');
assert_true(str_contains($php, 'VESTABOARD_NIGHT_ID'), 'PHP constant');
assert_true(str_contains($php, 'requestTickNudge'), 'automations nudge watcher');
assert_true(str_contains($watch, 'isNightHours() ? 1 : 8'), 'faster night poll');

$change = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.281]'), 'changelog 4.0.281');

fwrite(STDOUT, "test_vestaboard_night_auto.php ok\n");
