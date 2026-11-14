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
$tmp = sys_get_temp_dir() . '/yarbo-plan-hold-auto-' . bin2hex(random_bytes(3));
mkdir($tmp . '/data', 0775, true);
file_put_contents($tmp . '/data/hub-config.json', json_encode([
    'modules' => ['home' => true, 'yarbo' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($tmp . '/data/home.json', json_encode([
    'names' => [],
    'automations' => [],
], JSON_UNESCAPED_SLASHES));

$held = YarboHomeAutomations::yarboSnapshotRow([
    'online' => true,
    'error_code' => 0,
    'planning_paused' => true,
    'name' => 'Yarbo',
]);
$running = YarboHomeAutomations::yarboSnapshotRow([
    'online' => true,
    'error_code' => 0,
    'planning_paused' => false,
    'name' => 'Yarbo',
]);
assert_true(($held['plan_hold'] ?? false) === true, 'snapshot plan_hold from planning_paused');
assert_true(($running['plan_hold'] ?? true) === false, 'snapshot clear when not paused');
assert_true(
    YarboHomeAutomations::whenPhrase(['type' => 'robot', 'id' => 'yarbo', 'event' => 'plan_hold']) === 'Yarbo Plan hold',
    'whenPhrase plan_hold'
);
assert_true(
    YarboHomeAutomations::whenPhrase(['type' => 'robot', 'id' => 'yarbo', 'event' => 'plan_resume']) === 'Yarbo Plan resume',
    'whenPhrase plan_resume'
);

$auto = new YarboHomeAutomations($tmp);
$commands = [];
$auto->setCommandHandler(static function (array $action) use (&$commands): array {
    $commands[] = $action;

    return ['ok' => true];
});

$save = $auto->save([
    'id' => 'a-hold-dock',
    'enabled' => true,
    'name' => '',
    'trigger' => ['type' => 'robot', 'id' => 'yarbo', 'event' => 'plan_hold'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 0,
    'names' => ['unifi:light:porch' => 'Porch'],
]);
assert_true(!empty($save['ok']), 'save plan_hold → light: ' . json_encode($save));
assert_true(($save['automation']['trigger']['event'] ?? '') === 'plan_hold', 'plan_hold event persisted');
assert_true(
    str_contains((string) ($save['automation']['name'] ?? ''), 'Plan hold'),
    'auto-name mentions Plan hold: ' . ($save['automation']['name'] ?? '')
);

$t0 = 2_000_200_000;
$r = $auto->tick([$running], $t0);
assert_true(($r['fired'] ?? []) === [], 'idle running: no fire');
$r = $auto->tick([$held], $t0 + 1);
assert_true(($r['fired'] ?? []) === ['a-hold-dock'], 'plan_hold edge fires: ' . json_encode($r));
assert_true(
    (($commands[0]['command'] ?? '') === 'on'),
    'light on when plan holds: ' . json_encode($commands)
);
$r = $auto->tick([$held], $t0 + 2);
assert_true(($r['fired'] ?? []) === [], 'still held: no re-fire');

$auto->delete('a-hold-dock');
@unlink($auto->statePath());
$commands = [];
$resumeSave = $auto->save([
    'id' => 'a-resume',
    'enabled' => true,
    'trigger' => ['type' => 'robot', 'id' => 'yarbo', 'event' => 'plan_resume'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'off']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($resumeSave['ok']), 'save plan_resume When');
$r = $auto->tick([$held], $t0 + 10);
assert_true(($r['fired'] ?? []) === [], 'held: plan_resume When idle');
$r = $auto->tick([$running], $t0 + 11);
assert_true(($r['fired'] ?? []) === ['a-resume'], 'plan_resume edge fires: ' . json_encode($r));

$auto->delete('a-resume');
@unlink($auto->statePath());
$ifSave = $auto->save([
    'id' => 'a-if-hold',
    'enabled' => true,
    'trigger' => ['type' => 'time', 'at' => '12:00'],
    'conditions' => [['type' => 'robot', 'id' => 'yarbo', 'event' => 'plan_hold']],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($ifSave['ok']), 'save Only if plan_hold');
$noon = gmmktime(12, 0, 0, 1, 15, 2030);
$r = $auto->tick([$running], $noon);
assert_true(($r['fired'] ?? []) === [], 'noon but not held: blocked by Only if');
$r = $auto->tick([$held], $noon + 86400);
assert_true(($r['fired'] ?? []) === ['a-if-hold'], 'noon + plan_hold Only if: ' . json_encode($r));

// Lymow cannot use plan_hold — normalizes to error.
$ly = $auto->save([
    'id' => 'a-ly-hold',
    'enabled' => true,
    'trigger' => ['type' => 'robot', 'id' => 'lymow', 'event' => 'plan_hold'],
    'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
    'cooldown_sec' => 0,
]);
assert_true(!empty($ly['ok']), 'lymow plan_hold still saves');
assert_true(
    ($ly['automation']['trigger']['event'] ?? '') === 'error',
    'lymow plan_hold becomes error: ' . json_encode($ly['automation']['trigger'] ?? null)
);
$auto->delete('a-ly-hold');
$auto->delete('a-if-hold');

$js = (string) file_get_contents($rootFs . '/public/assets/app.js');
$change = (string) file_get_contents($rootFs . '/CHANGELOG.md');
$php = (string) file_get_contents($rootFs . '/src/YarboHomeAutomations.php');
assert_true(str_contains($js, 'HOME_AUTO_YARBO_ID}:plan_hold'), 'UI Plan hold tray chip');
assert_true(str_contains($js, 'HOME_AUTO_YARBO_ID}:plan_resume'), 'UI Plan resume tray chip');
assert_true(str_contains($js, "['plan_hold', 'Plan hold']"), 'UI plan_hold option');
assert_true(str_contains($php, 'planning_paused'), 'cache stores planning_paused');
assert_true(str_contains($php, 'YARBO_PLAN_HOLD_EVENTS'), 'plan hold events constant');
assert_true(str_contains($change, '## [4.0.301]'), 'changelog 4.0.301');
assert_true(str_contains($change, 'Plan hold'), 'changelog mentions Plan hold');

echo "ALL PASS\n";
