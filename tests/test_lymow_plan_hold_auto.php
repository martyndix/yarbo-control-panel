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
$tmp = sys_get_temp_dir() . '/lymow-plan-hold-auto-' . bin2hex(random_bytes(3));
mkdir($tmp . '/data', 0775, true);
file_put_contents($tmp . '/data/hub-config.json', json_encode([
    'modules' => ['home' => true, 'yarbo' => true, 'lymow' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($tmp . '/data/home.json', json_encode([
    'names' => [],
    'automations' => [],
], JSON_UNESCAPED_SLASHES));

$held = YarboHomeAutomations::lymowSnapshotRow([
    'work_status' => 3,
    'charging_label' => 'No',
    'name' => 'Lymow',
]);
$mowing = YarboHomeAutomations::lymowSnapshotRow([
    'work_status' => 2,
    'charging_label' => 'No',
    'name' => 'Lymow',
]);
assert_true(($held['plan_hold'] ?? false) === true, 'snapshot plan_hold from work_status 3');
assert_true(($mowing['plan_hold'] ?? true) === false, 'mowing is not plan hold');
assert_true(
    YarboHomeAutomations::whenPhrase(['type' => 'robot', 'id' => 'lymow', 'event' => 'plan_hold']) === 'Lymow Plan hold',
    'whenPhrase plan_hold'
);
assert_true(
    YarboHomeAutomations::whenPhrase(['type' => 'robot', 'id' => 'lymow', 'event' => 'plan_resume']) === 'Lymow Plan resume',
    'whenPhrase plan_resume'
);

$auto = new YarboHomeAutomations($tmp);
$commands = [];
$auto->setCommandHandler(static function (array $action) use (&$commands): array {
    $commands[] = $action;

    return ['ok' => true];
});

$save = $auto->save([
    'id' => 'a-ly-hold',
    'enabled' => true,
    'name' => '',
    'trigger' => ['type' => 'robot', 'id' => 'lymow', 'event' => 'plan_hold'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 0,
    'names' => ['unifi:light:porch' => 'Porch'],
]);
assert_true(!empty($save['ok']), 'save lymow plan_hold: ' . json_encode($save));
assert_true(($save['automation']['trigger']['event'] ?? '') === 'plan_hold', 'plan_hold event persisted');
assert_true(
    str_contains((string) ($save['automation']['name'] ?? ''), 'Plan hold'),
    'auto-name: ' . ($save['automation']['name'] ?? '')
);

$t0 = 2_000_400_000;
$r = $auto->tick([$mowing], $t0);
assert_true(($r['fired'] ?? []) === [], 'idle mowing: no fire');
$r = $auto->tick([$held], $t0 + 1);
assert_true(($r['fired'] ?? []) === ['a-ly-hold'], 'plan_hold edge fires: ' . json_encode($r));
$r = $auto->tick([$held], $t0 + 2);
assert_true(($r['fired'] ?? []) === [], 'still held: no re-fire');

$auto->delete('a-ly-hold');
@unlink($auto->statePath());
$commands = [];
$resumeSave = $auto->save([
    'id' => 'a-ly-resume',
    'enabled' => true,
    'trigger' => ['type' => 'robot', 'id' => 'lymow', 'event' => 'plan_resume'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'off']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($resumeSave['ok']), 'save plan_resume When');
$r = $auto->tick([$held], $t0 + 10);
assert_true(($r['fired'] ?? []) === [], 'held: plan_resume When idle');
$r = $auto->tick([$mowing], $t0 + 11);
assert_true(($r['fired'] ?? []) === ['a-ly-resume'], 'plan_resume edge fires: ' . json_encode($r));

$auto->delete('a-ly-resume');
@unlink($auto->statePath());
$ifSave = $auto->save([
    'id' => 'a-if-ly-hold',
    'enabled' => true,
    'trigger' => ['type' => 'time', 'at' => '12:00'],
    'conditions' => [['type' => 'robot', 'id' => 'lymow', 'event' => 'plan_hold']],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($ifSave['ok']), 'save Only if plan_hold');
$noon = gmmktime(12, 0, 0, 1, 15, 2030);
$r = $auto->tick([$mowing], $noon);
assert_true(($r['fired'] ?? []) === [], 'noon but not held: blocked');
$r = $auto->tick([$held], $noon + 86400);
assert_true(($r['fired'] ?? []) === ['a-if-ly-hold'], 'noon + plan_hold Only if: ' . json_encode($r));

$js = (string) file_get_contents($rootFs . '/public/assets/app.js');
$change = (string) file_get_contents($rootFs . '/CHANGELOG.md');
$php = (string) file_get_contents($rootFs . '/src/YarboHomeAutomations.php');
assert_true(str_contains($js, 'HOME_AUTO_LYMOW_ID}:plan_hold'), 'UI Lymow Plan hold tray');
assert_true(str_contains($js, 'HOME_AUTO_LYMOW_ID}:plan_resume'), 'UI Lymow Plan resume tray');
assert_true(str_contains($php, 'lymowPlanHoldFromData'), 'lymow plan hold helper');
assert_true(str_contains($php, 'ROBOT_PLAN_HOLD_EVENTS'), 'shared plan hold events');
assert_true(str_contains($change, '## [4.0.303]'), 'changelog 4.0.303');
assert_true(str_contains($change, 'Lymow'), 'changelog mentions Lymow');

echo "ALL PASS\n";
