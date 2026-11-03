<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboTelemetry;
use Yarbo\YarboVestaboard;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-paper-mow-' . bin2hex(random_bytes(4));
mkdir($root . '/data', 0775, true);

$mowing = YarboTelemetry::parseForPanel([
    'StateMSG' => [
        'working_state' => 0,
        'charging_status' => 2,
        'error_code' => 0,
        'on_going_planning' => 1,
        'planning_paused' => 0,
        'on_going_recharging' => 0,
    ],
    'BatteryMSG' => ['capacity' => 72],
    'HeadMsg' => ['head_type' => 3],
], null, $root);
assert_true(($mowing['plan_running'] ?? false) === true, 'plan_running while mowing');
assert_true(($mowing['state'] ?? '') === 'active', 'state active while mowing despite leftover charge flag: ' . json_encode($mowing['state'] ?? null));

$departing = YarboTelemetry::parseForPanel([
    'StateMSG' => [
        'working_state' => 0,
        'charging_status' => 0,
        'error_code' => 0,
        'on_going_planning' => 0,
        'on_going_to_start_point' => 1,
        'planning_paused' => 0,
        'on_going_recharging' => 0,
    ],
    'BatteryMSG' => ['capacity' => 80],
    'HeadMsg' => ['head_type' => 3],
], null, $root);
assert_true(($departing['plan_running'] ?? false) === true, 'departing to start counts as plan_running');
assert_true(($departing['state'] ?? '') === 'active', 'departing shows active');

$vb = new YarboVestaboard($root);
$layout = $vb->compose($mowing, true);
$lines = implode("\n", $layout['lines'] ?? []);
assert_true(
    str_contains($lines, 'MOWING') || ($layout['verb'] ?? '') === 'MOWING',
    'Vestaboard MOWING over leftover charge: ' . $lines
);

$fw = (string) file_get_contents(dirname(__DIR__) . '/firmware/papermono/src/main.cpp');
$ver = (string) file_get_contents(dirname(__DIR__) . '/firmware/papermono/src/version.h');
$php = (string) file_get_contents(dirname(__DIR__) . '/src/YarboPaperDevice.php');
$net = (string) file_get_contents(dirname(__DIR__) . '/firmware/papermono/src/paper_net.h');
$agent = (string) file_get_contents(dirname(__DIR__) . '/scripts/mqtt_agent.py');
$mqtt = (string) file_get_contents(dirname(__DIR__) . '/src/YarboMqttAgentClient.php');
$home = (string) file_get_contents(dirname(__DIR__) . '/src/YarboHome.php');

assert_true(str_contains($ver, '#define PAPERMONO_FW_VERSION "0.1.74"'), 'firmware 0.1.74');
assert_true(str_contains($php, "public const FIRMWARE_VERSION = '0.1.74';"), 'panel pin 0.1.74');
assert_true(str_contains($fw, 'PAPERMONO_PAGE_HOME || page == PAPERMONO_PAGE_STATUS'), 'HOME is a Yarbo page');
assert_true(str_contains($fw, 'if (pageEnabled(PAPERMONO_PAGE_HOME)) return PAPERMONO_PAGE_HOME;'), 'Yarbo opens HOME with DOCK');
assert_true(str_contains($fw, 'drawButton(16 + bw + gap, y0 + bh + gap, bw, bh, "PLANS", false);'), 'HOME shows PLANS');
assert_true(str_contains($fw, 'showPage(PAPERMONO_PAGE_PLANS, true);'), 'PLANS button opens work plans');
assert_true(str_contains($fw, 'drawButton(16, 108, 120, 42, "BACK", false);'), 'Plans draws top BACK');
assert_true(str_contains($fw, 'drawButton(16 + bw + gap, startY, bw, 72, "BACK", false);'), 'Plans draws bottom BACK when no MORE');
assert_true(str_contains($fw, "if (x >= 16 && x <= 136 && y >= 108 && y <= 150) {\n        showPage(PAPERMONO_PAGE_HOME, false);"), 'Plans top BACK returns to HOME');
assert_true(str_contains($fw, 'return 160;'), 'Plans list leaves room under BACK');
assert_true(str_contains($fw, 'paperNetGet(http, url, 20000)'), 'compact allows 20s');
assert_true(str_contains($net, 'int lanMs = remoteMs;'), 'LAN uses full HTTP budget');
assert_true(str_contains($php, 'telemetry(2.0, false, true)'), 'compact prefers cached telemetry');
assert_true(str_contains($php, 'dashboardPayload(false)'), 'compact does not refresh Powerwall');
assert_true(str_contains($mqtt, 'preferCache'), 'MQTT client prefer_cache');
assert_true(str_contains($agent, 'prefer_cache'), 'MQTT agent prefer_cache');
assert_true(str_contains($home, 'devicesLiveCache'), 'Home live-state cache for compact');

echo "test_paper_yarbo_mowing.php ok\n";
