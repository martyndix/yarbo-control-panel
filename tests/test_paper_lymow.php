<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboPaperDevice;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$rootDir = dirname(__DIR__);
$ver = (string) file_get_contents($rootDir . '/firmware/papermono/src/version.h');
$php = (string) file_get_contents($rootDir . '/src/YarboPaperDevice.php');
$fw = (string) file_get_contents($rootDir . '/firmware/papermono/src/main.cpp');
$api = (string) file_get_contents($rootDir . '/public/api/device.php');

assert_true(str_contains($ver, '#define PAPERMONO_FW_VERSION "0.1.91"'), 'firmware 0.1.91');
assert_true(str_contains($ver, 'PAPERMONO_LYMOW_ZONE_MAX'), 'zone max define');
assert_true(str_contains($ver, 'PAPERMONO_LYMOW_ZONE_VISIBLE'), 'zone visible define');
assert_true(str_contains($php, "public const FIRMWARE_VERSION = '0.1.91';"), 'panel pin 0.1.91');
assert_true(str_contains($php, "'lymow_zones'"), 'compact includes lymow_zones');
assert_true(str_contains($php, "'lymow_work_status'"), 'compact includes work status');

assert_true(str_contains($api, "'lymow_command'"), 'device API lymow_command');
assert_true(str_contains($api, "'lymow_maps'"), 'device API lymow_maps');
assert_true(str_contains($api, 'sendCommand'), 'device API calls sendCommand');
assert_true(str_contains($api, 'fetchZones'), 'device API calls fetchZones');

assert_true(str_contains($fw, 'handleLymowTouch'), 'firmware handleLymowTouch');
assert_true(str_contains($fw, 'httpLymowCommand'), 'firmware httpLymowCommand');
assert_true(str_contains($fw, 'httpLymowMaps'), 'firmware httpLymowMaps');
assert_true(str_contains($fw, 'lymowZoneIds'), 'firmware zone storage');
assert_true(str_contains($fw, 'lymowZonePage'), 'firmware zone paging');
assert_true(str_contains($fw, '"CANCEL"'), 'firmware Cancel button');
assert_true(str_contains($fw, 'lymowSend("cancel")'), 'firmware cancel touch');
assert_true(str_contains($fw, 'lymow_zones'), 'firmware parses lymow_zones');
assert_true(str_contains($fw, 'PAPERMONO_PAGE_LYMOW)'), 'touch routes Lymow page');
assert_true(str_contains($fw, 'lymowAnyZoneOn'), 'firmware any-zone helper');
assert_true(str_contains($fw, 'bool on = false;'), 'zones default unselected');
assert_true(str_contains($fw, 'Never pre-select zones'), 'comment documents no preselect');
assert_true(str_contains($fw, 'paused || lymowAnyZoneOn()'), 'START gated on zone selection');
assert_true(str_contains($fw, '!paused && !lymowAnyZoneOn()'), 'START touch ignored without zone');

$tmp = sys_get_temp_dir() . '/yarbo-paper-lymow-' . bin2hex(random_bytes(3));
mkdir($tmp . '/data', 0775, true);
file_put_contents($tmp . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => false, 'lymow' => true, 'home' => false, 'powerwall' => false],
], JSON_UNESCAPED_SLASHES));
file_put_contents($tmp . '/data/papermono-devices.json', json_encode([
    'devices' => [
        ['id' => 'tab1', 'name' => 'Kitchen', 'kind' => 'papermono', 'token' => 'tok1'],
    ],
    'prefs' => [],
    'messages' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($tmp . '/data/lymow-state.json', json_encode([
    'ok' => true,
    'battery' => 88,
    'work_status' => 1,
    'is_charging' => false,
    'zones' => [
        ['hash_id' => 'wsmjco1T', 'name' => 'Front Lawn', 'enabled' => true, 'area_m2' => 120],
        ['hash_id' => 'KX1kGyat', 'name' => 'Side', 'enabled' => true],
    ],
    'zones_fetched_at' => '2026-11-08T12:00:00Z',
], JSON_UNESCAPED_SLASHES));

$compact = new ReflectionMethod(YarboPaperDevice::class, 'companionCompact');
$compact->setAccessible(true);
$paper = new YarboPaperDevice($tmp);
$out = $compact->invoke($paper, null, false, ['id' => 'tab1', 'name' => 'Kitchen']);
assert_true(!empty($out['lymow_enabled']), 'lymow module on');
assert_true(is_array($out['lymow_zones'] ?? null), 'zones array');
assert_true(count($out['lymow_zones']) === 2, 'two zones in compact: ' . json_encode($out['lymow_zones']));
assert_true(($out['lymow_zones'][0]['name'] ?? '') === 'Front Lawn', 'zone name from map');
assert_true(($out['lymow_zones'][0]['hash_id'] ?? '') === 'wsmjco1T', 'zone hash');
assert_true(($out['lymow_work_status'] ?? -2) === 1, 'work status in compact');
assert_true(($out['lymow_battery'] ?? -1) === 88, 'battery in compact');

$change = (string) file_get_contents($rootDir . '/CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.226]'), 'changelog 4.0.226');
assert_true(str_contains($change, 'active zone'), 'changelog mentions active zone');
assert_true(str_contains($change, '## [4.0.225]'), 'changelog 4.0.225');
assert_true(str_contains($change, '## [4.0.240]'), 'changelog 4.0.240');
assert_true(str_contains($change, 'no longer arrive pre-selected'), 'changelog mentions unselected zones');
assert_true(str_contains($change, 'Cancel'), 'changelog mentions Cancel');
assert_true(str_contains($change, 'PaperMono'), 'changelog mentions PaperMono');

$docs = (string) file_get_contents($rootDir . '/docs/papermono.md');
assert_true(str_contains($docs, 'lymow_command'), 'docs mention lymow_command');
assert_true(str_contains($docs, 'zone chips'), 'docs mention zone chips');
assert_true(str_contains($docs, 'CANCEL'), 'docs mention CANCEL');
assert_true(str_contains($docs, 'not pre-selected'), 'docs mention zones not pre-selected');
$lyDocs = (string) file_get_contents($rootDir . '/docs/lymow.md');
assert_true(str_contains($lyDocs, 'Zones start unselected'), 'lymow docs unselected start');

fwrite(STDOUT, "test_paper_lymow.php ok\n");
