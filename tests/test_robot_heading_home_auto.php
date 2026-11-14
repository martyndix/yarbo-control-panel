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
$tmp = sys_get_temp_dir() . '/yarbo-heading-home-auto-' . bin2hex(random_bytes(3));
mkdir($tmp . '/data', 0775, true);
file_put_contents($tmp . '/data/hub-config.json', json_encode([
    'modules' => ['home' => true, 'yarbo' => true, 'lymow' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($tmp . '/data/home.json', json_encode([
    'names' => [],
    'automations' => [],
], JSON_UNESCAPED_SLASHES));

$yarboHome = YarboHomeAutomations::yarboSnapshotRow([
    'online' => true,
    'returning_to_dock' => true,
]);
$yarboIdle = YarboHomeAutomations::yarboSnapshotRow([
    'online' => true,
    'returning_to_dock' => false,
]);
assert_true(($yarboHome['heading_home'] ?? false) === true, 'yarbo heading_home from returning_to_dock');
assert_true(($yarboIdle['heading_home'] ?? true) === false, 'yarbo idle not heading home');

$lyDock = YarboHomeAutomations::lymowSnapshotRow([
    'work_status' => 4,
    'charging_label' => 'No',
]);
$lyPauseDock = YarboHomeAutomations::lymowSnapshotRow([
    'work_status' => 10,
    'charging_label' => 'No',
]);
$lyReturning = YarboHomeAutomations::lymowSnapshotRow([
    'work_status' => 2,
    'charging_label' => 'Returning',
]);
$lyCharge = YarboHomeAutomations::lymowSnapshotRow([
    'work_status' => 4,
    'charging_label' => 'Yes',
]);
$lyMow = YarboHomeAutomations::lymowSnapshotRow([
    'work_status' => 2,
    'charging_label' => 'No',
]);
assert_true(($lyDock['heading_home'] ?? false) === true, 'lymow work_status 4 is heading home');
assert_true(($lyPauseDock['heading_home'] ?? false) === true, 'lymow pause-docking 10 is heading home');
assert_true(($lyReturning['heading_home'] ?? false) === true, 'lymow Returning label is heading home');
assert_true(($lyCharge['heading_home'] ?? true) === false, 'lymow on-pad charge is not heading home');
assert_true(($lyMow['heading_home'] ?? true) === false, 'lymow mowing is not heading home');

assert_true(
    YarboHomeAutomations::whenPhrase(['type' => 'robot', 'id' => 'yarbo', 'event' => 'heading_home']) === 'Yarbo Heading home',
    'whenPhrase yarbo heading home'
);
assert_true(
    YarboHomeAutomations::whenPhrase(['type' => 'robot', 'id' => 'lymow', 'event' => 'heading_home']) === 'Lymow Heading home',
    'whenPhrase lymow heading home'
);

$auto = new YarboHomeAutomations($tmp);
$commands = [];
$auto->setCommandHandler(static function (array $action) use (&$commands): array {
    $commands[] = $action;

    return ['ok' => true];
});

$save = $auto->save([
    'id' => 'a-yarbo-home',
    'enabled' => true,
    'name' => '',
    'trigger' => ['type' => 'robot', 'id' => 'yarbo', 'event' => 'heading_home'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 0,
    'names' => ['unifi:light:porch' => 'Porch'],
]);
assert_true(!empty($save['ok']), 'save yarbo heading_home: ' . json_encode($save));
assert_true(($save['automation']['trigger']['event'] ?? '') === 'heading_home', 'event persisted');
assert_true(
    str_contains((string) ($save['automation']['name'] ?? ''), 'Heading home'),
    'auto-name: ' . ($save['automation']['name'] ?? '')
);

$t0 = 2_000_300_000;
$r = $auto->tick([$yarboIdle], $t0);
assert_true(($r['fired'] ?? []) === [], 'idle: no fire');
$r = $auto->tick([$yarboHome], $t0 + 1);
assert_true(($r['fired'] ?? []) === ['a-yarbo-home'], 'yarbo heading_home edge: ' . json_encode($r));
$r = $auto->tick([$yarboHome], $t0 + 2);
assert_true(($r['fired'] ?? []) === [], 'still heading: no re-fire');

$auto->delete('a-yarbo-home');
@unlink($auto->statePath());
$commands = [];
$lySave = $auto->save([
    'id' => 'a-ly-home',
    'enabled' => true,
    'trigger' => ['type' => 'robot', 'id' => 'lymow', 'event' => 'heading_home'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($lySave['ok']), 'save lymow heading_home');
$r = $auto->tick([$lyMow], $t0 + 10);
assert_true(($r['fired'] ?? []) === [], 'mowing: no fire');
$r = $auto->tick([$lyDock], $t0 + 11);
assert_true(($r['fired'] ?? []) === ['a-ly-home'], 'lymow docking edge: ' . json_encode($r));

$auto->delete('a-ly-home');
@unlink($auto->statePath());
$ifSave = $auto->save([
    'id' => 'a-if-home',
    'enabled' => true,
    'trigger' => ['type' => 'time', 'at' => '12:00'],
    'conditions' => [['type' => 'robot', 'id' => 'yarbo', 'event' => 'heading_home']],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($ifSave['ok']), 'save Only if heading_home');
$noon = gmmktime(12, 0, 0, 1, 15, 2030);
$r = $auto->tick([$yarboIdle], $noon);
assert_true(($r['fired'] ?? []) === [], 'noon but idle: blocked');
$r = $auto->tick([$yarboHome], $noon + 86400);
assert_true(($r['fired'] ?? []) === ['a-if-home'], 'noon + heading home Only if: ' . json_encode($r));

$js = (string) file_get_contents($rootFs . '/public/assets/app.js');
$change = (string) file_get_contents($rootFs . '/CHANGELOG.md');
$php = (string) file_get_contents($rootFs . '/src/YarboHomeAutomations.php');
assert_true(str_contains($js, 'HOME_AUTO_YARBO_ID}:heading_home'), 'UI Yarbo Heading home tray');
assert_true(str_contains($js, 'HOME_AUTO_LYMOW_ID}:heading_home'), 'UI Lymow Heading home tray');
assert_true(str_contains($js, "['heading_home', 'Heading home']"), 'UI heading_home option');
assert_true(str_contains($php, 'returning_to_dock'), 'cache stores returning_to_dock');
assert_true(str_contains($php, 'lymowHeadingHomeFromData'), 'lymow heading helper');
assert_true(str_contains($change, '## [4.0.302]'), 'changelog 4.0.302');
assert_true(str_contains($change, 'Heading home'), 'changelog mentions Heading home');

echo "ALL PASS\n";
