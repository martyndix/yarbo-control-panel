<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboDockLights;
use Yarbo\YarboDockLightsSettings;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "ok: {$message}\n";
}

assert_true(YarboDockLights::desiredOn(false, true) === true, 'off pad + dark → on');
assert_true(YarboDockLights::desiredOn(true, true) === false, 'on pad + dark → off');
assert_true(YarboDockLights::desiredOn(false, false) === false, 'off pad + day → off');
assert_true(YarboDockLights::desiredOn(true, false) === false, 'on pad + day → off');

// Leftover charging_status while mowing must not keep lights off at night.
assert_true(
    YarboDockLights::effectivelyOnPad([
        'on_charge_pad' => true,
        'plan_running' => true,
        'planning_paused' => false,
    ]) === false,
    'mowing + leftover pad flag → off pad'
);
assert_true(
    YarboDockLights::effectivelyOnPad([
        'on_charge_pad' => true,
        'plan_running' => false,
        'planning_paused' => false,
    ]) === true,
    'idle on pad stays on pad'
);
assert_true(
    YarboDockLights::effectivelyOnPad([
        'on_charge_pad' => true,
        'plan_running' => true,
        'planning_paused' => true,
    ]) === true,
    'paused on pad stays on pad'
);
assert_true(
    YarboDockLights::effectivelyOnPad([
        'on_charge_pad' => true,
        'returning_to_dock' => true,
    ]) === false,
    'returning to dock → off pad'
);
assert_true(
    YarboDockLights::desiredOn(
        YarboDockLights::effectivelyOnPad([
            'on_charge_pad' => true,
            'plan_running' => true,
        ]),
        true
    ) === true,
    'mowing at night → lights on'
);

$root = sys_get_temp_dir() . '/yarbo-dock-lights-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
$settings = new YarboDockLightsSettings($root . '/data');
assert_true($settings->enabled() === false, 'default disabled');
assert_true($settings->save(['yarbo_dock_lights' => true]), 'save enable');
assert_true($settings->enabled() === true, 'enabled after save');
$view = $settings->publicView();
assert_true(($view['enabled'] ?? false) === true, 'publicView enabled');

$settings->write(['enabled' => false, 'last_applied' => null]);
$out = (new YarboDockLights($root))->tick();
assert_true(($out['reason'] ?? '') === 'disabled', 'tick reports disabled');

$html = (string) file_get_contents(__DIR__ . '/../public/index.php');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$api = (string) file_get_contents(__DIR__ . '/../public/api/settings.php');
$runner = (string) file_get_contents(__DIR__ . '/../scripts/home_automations.php');
assert_true(str_contains($html, 'settings-yarbo-dock-lights'), 'settings checkbox');
assert_true(str_contains($js, 'yarbo_dock_lights'), 'settings save field');
assert_true(str_contains($api, 'dock_lights'), 'settings API exposes dock_lights');
assert_true(str_contains($runner, 'YarboDockLights'), 'background runner hooks dock lights');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.298]'), 'changelog 4.0.298');
assert_true(str_contains($src = (string) file_get_contents(__DIR__ . '/../src/YarboDockLights.php'), 'effectivelyOnPad'), 'effectivelyOnPad helper');
assert_true(str_contains($src, 'agentLights'), 're-apply when agent lights hold mismatches');

echo "test_yarbo_dock_lights.php ok\n";
