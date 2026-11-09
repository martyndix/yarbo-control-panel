<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/YarboHub.php';
require_once __DIR__ . '/../src/YarboVestaboard.php';
require_once __DIR__ . '/../src/YarboTelemetry.php';
require_once __DIR__ . '/../src/YarboLymow.php';
require_once __DIR__ . '/../src/YarboPowerwall.php';

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-vb-act-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => [
        'yarbo' => true,
        'powerwall' => true,
        'lymow' => true,
        'home' => false,
        'unifi' => false,
    ],
    'active_module' => 'yarbo',
    'vestaboard_live' => 'batteries',
    'house_name' => '',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => false,
    'rotate_enabled' => false,
    'rotate_views' => ['yarbo', 'powerwall', 'lymow'],
    'rotate_minutes' => 5,
], JSON_UNESCAPED_SLASHES));

$hub = new Yarbo\YarboHub($root);
$vb = new Yarbo\YarboVestaboard($root);

assert_true(
    Yarbo\YarboHub::firstEnabledVestaboardLive([
        'yarbo' => true,
        'powerwall' => true,
        'lymow' => true,
    ]) === 'powerwall',
    'idle default prefers Powerwall'
);

$r = $vb->applyActivityLivePolicy(
    ['mowing' => true, 'error' => false],
    ['mowing' => false, 'error' => false]
);
assert_true(!empty($r['active']) && !empty($r['changed']), 'yarbo mowing activates: ' . json_encode($r));
assert_true($hub->vestaboardLive() === 'yarbo', 'live switches to yarbo while mowing');
$cfg = $vb->load();
assert_true(($cfg['live_resume'] ?? null) === 'batteries', 'All is remembered for resume: ' . json_encode($cfg));

$r = $vb->applyActivityLivePolicy(
    ['mowing' => true, 'error' => false],
    ['mowing' => false, 'error' => false]
);
assert_true(!empty($r['active']) && empty($r['changed']), 'still mowing does not flap');

$r = $vb->applyActivityLivePolicy(
    ['mowing' => false, 'error' => false],
    ['mowing' => true, 'error' => false]
);
assert_true(!empty($r['active']) && !empty($r['changed']), 'lymow mowing takes over');
assert_true($hub->vestaboardLive() === 'lymow', 'live switches to lymow');

$vb->save(['activity_focus' => 'yarbo', 'activity_swap_at' => gmdate('c', time() - 10)]);
$r = $vb->applyActivityLivePolicy(
    ['mowing' => true, 'error' => false],
    ['mowing' => true, 'error' => false]
);
assert_true(!empty($r['active']), 'both mowing stays active');
assert_true($hub->vestaboardLive() === 'yarbo', 'both mowing keeps current focus inside 5 min');

$vb->save(['activity_focus' => 'yarbo', 'activity_swap_at' => gmdate('c', time() - 400)]);
$r = $vb->applyActivityLivePolicy(
    ['mowing' => true, 'error' => false],
    ['mowing' => true, 'error' => false]
);
assert_true($hub->vestaboardLive() === 'lymow', 'both mowing swaps after 5 min: ' . $hub->vestaboardLive());

$r = $vb->applyActivityLivePolicy(
    ['mowing' => true, 'error' => true],
    ['mowing' => true, 'error' => false]
);
assert_true($hub->vestaboardLive() === 'yarbo', 'error wins over dual mowing');

$r = $vb->applyActivityLivePolicy(
    ['mowing' => false, 'error' => false],
    ['mowing' => false, 'error' => false]
);
assert_true(empty($r['active']) && !empty($r['changed']), 'idle restores after activity');
assert_true($hub->vestaboardLive() === 'batteries', 'All resumes when jobs end: ' . $hub->vestaboardLive());

$hub->save(['vestaboard_live' => 'powerwall']);
$vb->save([
    'rotate_enabled' => true,
    'rotate_views' => ['yarbo', 'powerwall', 'lymow'],
    'rotate_minutes' => 5,
    'rotate_at' => gmdate('c', time() - 400),
    'rotate_started_at' => gmdate('c', time() - 90000),
    'live_resume' => null,
    'activity_focus' => null,
]);
$ref = new ReflectionClass($vb);
$stop = $ref->getMethod('maybeAdvanceRotation');
$stop->setAccessible(true);
$changed = $stop->invoke($vb);
assert_true($changed === true, 'rotate past 24h returns to default');
assert_true($hub->vestaboardLive() === 'powerwall', 'rotate expiry lands on Powerwall');
$cfg = $vb->load();
assert_true(empty($cfg['rotate_enabled']), 'rotate disabled after 24h');

$hub->save(['vestaboard_live' => 'powerwall']);
$vb->save(['smart_display' => false, 'live_resume' => null, 'activity_focus' => null]);
$r = $vb->applyActivityLivePolicy(
    ['mowing' => true, 'error' => false],
    ['mowing' => true, 'error' => false]
);
assert_true(empty($r['active']) && empty($r['changed']), 'smart display off ignores mowing');
assert_true($hub->vestaboardLive() === 'powerwall', 'smart off keeps Powerwall');

$vb->save(['smart_display' => true]);
$hub->save([
    'modules' => [
        'yarbo' => false,
        'powerwall' => true,
        'lymow' => true,
        'home' => false,
        'unifi' => false,
    ],
    'vestaboard_live' => 'powerwall',
]);
$r = $vb->applyActivityLivePolicy(
    ['mowing' => true, 'error' => false],
    ['mowing' => true, 'error' => false]
);
assert_true(!empty($r['active']), 'lymow-only module still follows Lymow');
assert_true($hub->vestaboardLive() === 'lymow', 'yarbo off skips Yarbo even if override says mowing');

$hub->save([
    'modules' => [
        'yarbo' => true,
        'powerwall' => true,
        'lymow' => true,
        'home' => false,
        'unifi' => false,
    ],
    'vestaboard_live' => 'powerwall',
]);
$vb->save(['live_resume' => null, 'activity_focus' => null, 'activity_swap_at' => null]);

$view = $vb->publicView();
assert_true(($view['smart_display'] ?? null) === true, 'publicView exposes smart_display');

$dockYarbo = Yarbo\YarboVestaboard::yarboActivityFromParsed([
    'error_code' => 0,
    'planning_paused' => false,
    'plan_running' => false,
    'returning_to_dock' => true,
]);
assert_true(!empty($dockYarbo['mowing']) && empty($dockYarbo['error']), 'Yarbo docking counts as active');

$idleYarbo = Yarbo\YarboVestaboard::yarboActivityFromParsed([
    'error_code' => 0,
    'planning_paused' => false,
    'plan_running' => false,
    'returning_to_dock' => false,
]);
assert_true(empty($idleYarbo['mowing']), 'Yarbo idle is not active');

$headingOut = Yarbo\YarboVestaboard::yarboActivityFromParsed([
    'error_code' => 0,
    'planning_paused' => false,
    'plan_running' => true,
    'returning_to_dock' => false,
]);
assert_true(!empty($headingOut['mowing']), 'Yarbo heading out / mowing counts as active');

$lyDock = Yarbo\YarboVestaboard::lymowActivityFromData([
    'work_status' => 4,
    'robot_state' => 1,
    'error_code' => 0,
]);
assert_true(!empty($lyDock['mowing']) && empty($lyDock['error']), 'Lymow docking (4) counts as active');

$lyPauseDock = Yarbo\YarboVestaboard::lymowActivityFromData([
    'work_status' => 10,
    'robot_state' => 1,
    'error_code' => 0,
]);
assert_true(!empty($lyPauseDock['mowing']), 'Lymow pause-docking (10) counts as active');

$lyWait = Yarbo\YarboVestaboard::lymowActivityFromData([
    'work_status' => 1,
    'robot_state' => 1,
    'error_code' => 0,
]);
assert_true(empty($lyWait['mowing']), 'Lymow WAIT is not active');

$r = $vb->applyActivityLivePolicy(
    ['mowing' => true, 'error' => false],
    ['mowing' => false, 'error' => false]
);
assert_true(!empty($r['active']) && $hub->vestaboardLive() === 'yarbo', 'docking override still switches live');

$src = (string) file_get_contents(__DIR__ . '/../src/YarboVestaboard.php');
assert_true(str_contains($src, 'ACTIVITY_SWAP_SECONDS'), 'swap constant');
assert_true(str_contains($src, 'ROTATE_MAX_SECONDS'), 'rotate max constant');
assert_true(str_contains($src, 'applyActivityLivePolicy'), 'activity policy');
assert_true(str_contains($src, "'smart_display'"), 'smart_display setting');
assert_true(str_contains($src, 'returning_to_dock'), 'Yarbo docking in activity');
assert_true(str_contains($src, 'in_array($work, [2, 4, 8, 9, 10], true)'), 'Lymow docking statuses');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
assert_true(str_contains($js, 'updateVestaboardSmartHint'), 'settings hint adapts to modules');
assert_true(str_contains($js, 'vestaboard_smart_display'), 'settings save smart display');
assert_true(str_contains($js, 'heading out, or docking'), 'settings hint mentions docking');
$index = (string) file_get_contents(__DIR__ . '/../public/index.php');
assert_true(str_contains($index, 'Vestaboard Smart Display'), 'settings checkbox label');
assert_true(str_contains($index, 'heading out, or docking'), 'settings default hint mentions docking');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.210]'), 'changelog 4.0.210');
assert_true(str_contains($change, '## [4.0.209]'), 'changelog 4.0.209');
assert_true(str_contains($change, '## [4.0.241]'), 'changelog 4.0.241');
$docs = (string) file_get_contents(__DIR__ . '/../docs/vestaboard.md');
assert_true(str_contains($docs, 'Smart Display'), 'docs mention Smart Display');
assert_true(str_contains($docs, 'docking home'), 'docs mention docking');

echo "test_vestaboard_activity.php ok\n";
