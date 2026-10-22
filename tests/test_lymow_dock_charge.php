<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboLymow;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

/**
 * @param array<string, mixed> $cloud
 * @return array{ok: bool, online: bool, lines: list<string>, codes: list<list<int>>, verb: string}
 */
function layoutFor(array $cloud): array
{
    $root = sys_get_temp_dir() . '/yarbo-ly-dock-' . bin2hex(random_bytes(3));
    mkdir($root . '/data', 0775, true);
    file_put_contents($root . '/data/hub-config.json', json_encode([
        'modules' => ['yarbo' => false, 'lymow' => true, 'home' => false, 'powerwall' => false],
    ], JSON_UNESCAPED_SLASHES));
    file_put_contents($root . '/data/lymow-state.json', json_encode($cloud, JSON_UNESCAPED_SLASHES));

    return (new YarboLymow($root))->vestaboardLayout();
}

// Mid-mow top-up: on pad charging, leftover docking work_status + is_recharging.
$midJob = layoutFor([
    'ok' => true,
    'online' => true,
    'battery' => 35,
    'work_status' => 4,
    'robot_state' => 3,
    'is_charging' => true,
    'is_recharging' => true,
    'mow_progress' => 42,
]);
assert_true(($midJob['verb'] ?? '') === 'CHARGING', 'mid-mow pad charge verb: ' . json_encode($midJob['verb'] ?? null));
assert_true(
    str_contains(implode("\n", $midJob['lines'] ?? []), 'ON DOCK'),
    'mid-mow pad charge shows ON DOCK: ' . json_encode($midJob['lines'] ?? null)
);
assert_true(
    !str_contains(implode("\n", $midJob['lines'] ?? []), 'HEADING HOME'),
    'mid-mow pad charge is not HEADING HOME: ' . json_encode($midJob['lines'] ?? null)
);

// Still travelling home: not on pad yet.
$heading = layoutFor([
    'ok' => true,
    'online' => true,
    'battery' => 18,
    'work_status' => 10,
    'is_charging' => false,
    'is_recharging' => true,
    'mow_progress' => 42,
]);
assert_true(($heading['verb'] ?? '') === 'DOCKING', 'en-route docking verb: ' . json_encode($heading['verb'] ?? null));
assert_true(
    str_contains(implode("\n", $heading['lines'] ?? []), 'HEADING HOME'),
    'en-route shows HEADING HOME: ' . json_encode($heading['lines'] ?? null)
);

// Pause off the pad still uses PLAN HOLD.
$paused = layoutFor([
    'ok' => true,
    'online' => true,
    'battery' => 60,
    'work_status' => 3,
    'is_charging' => false,
    'is_recharging' => false,
]);
assert_true(($paused['verb'] ?? '') === 'PAUSED', 'off-pad pause verb: ' . json_encode($paused['verb'] ?? null));

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.157]'), 'changelog 4.0.157');

echo "test_lymow_dock_charge.php ok\n";
