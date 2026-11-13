<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHomeAutomations;
use Yarbo\YarboLymow;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = dirname(__DIR__);

$proto = (string) file_get_contents($root . '/scripts/lib/lymow_protocol.py');
assert_true(str_contains($proto, 'USER_CTRL_CLEAN = 1'), 'protocol CLEAN=1');
assert_true(str_contains($proto, 'USER_CTRL_FORCE_REINIT = 28'), 'protocol FORCE_REINIT=28');
assert_true(str_contains($proto, 'USER_CTRL_RECHARGE_DOCK = 33'), 'protocol RECHARGE_DOCK=33');
assert_true(str_contains($proto, 'USER_CTRL_RESUME_DOCK = 22'), 'protocol RESUME_DOCK=22');
assert_true(str_contains($proto, 'def resolve_dock_userctrl'), 'protocol resolve_dock_userctrl');
assert_true(str_contains($proto, '"cancel": USER_CTRL_FORCE_REINIT'), 'protocol cancel maps to FORCE_REINIT=28');
assert_true(str_contains($proto, 'USER_CTRL_QUERY_MAP = 19'), 'protocol QUERY_MAP=19');

assert_true(str_contains($proto, 'def encode_start_zones'), 'protocol encode_start_zones');
assert_true(str_contains($proto, 'def decode_map_zones'), 'protocol decode_map_zones');

$bridge = (string) file_get_contents($root . '/scripts/lymow_bridge.py');
assert_true(str_contains($bridge, 'resolve_dock_userctrl'), 'bridge uses resolve_dock_userctrl');
assert_true(str_contains($bridge, 'work_status='), 'bridge passes work_status for dock');
assert_true(str_contains($bridge, 'mqtt_query_map'), 'bridge mqtt_query_map');
assert_true(str_contains($bridge, 'cmd_maps'), 'bridge cmd_maps');
assert_true(str_contains($bridge, '--zones'), 'bridge --zones');

$lymowPhp = (string) file_get_contents($root . '/src/YarboLymow.php');
assert_true(str_contains($lymowPhp, 'function sendCommand'), 'YarboLymow::sendCommand');
assert_true(str_contains($lymowPhp, 'function fetchZones'), 'YarboLymow::fetchZones');
assert_true(str_contains($lymowPhp, 'function zonesFromData'), 'YarboLymow::zonesFromData');

$api = (string) file_get_contents($root . '/public/api/lymow.php');
assert_true(str_contains($api, "'maps'"), 'API maps action');
assert_true(str_contains($api, 'fetchZones'), 'API fetchZones');
assert_true(str_contains($api, "sendCommand"), 'API sendCommand');

$index = (string) file_get_contents($root . '/public/index.php');
assert_true(str_contains($index, 'id="lymow-zones-list"'), 'zone list');
assert_true(str_contains($index, 'id="lymow-zones-refresh"'), 'zones refresh');
assert_true(str_contains($index, 'data-lymow-cmd="start"'), 'Start button');
assert_true(str_contains($index, 'data-lymow-cmd="cancel"'), 'Cancel button');

$js = (string) file_get_contents($root . '/public/assets/app.js');
assert_true(str_contains($js, 'function renderLymowZones'), 'JS renderLymowZones');
assert_true(str_contains($js, 'function refreshLymowZones'), 'JS refreshLymowZones');
assert_true(str_contains($js, 'body.zones = zones'), 'JS start sends zones');
assert_true(str_contains($js, "'cancel'"), 'JS cancel command');
assert_true(str_contains($js, 'is-mowing'), 'JS highlights active mow zones');
assert_true(str_contains($js, 'lymowTaskZones'), 'JS tracks task zones');
assert_true(str_contains($js, 'function homeAutoLoadLymowZones'), 'JS auto load zones');
assert_true(str_contains($js, 'data-auto-lymow-zones'), 'JS auto zone select');

$autoPhp = (string) file_get_contents($root . '/src/YarboHomeAutomations.php');
assert_true(str_contains($autoPhp, "\$out['zones'] = \$zones"), 'automations store start zones');

$tmp = sys_get_temp_dir() . '/yarbo-lymow-zones-' . bin2hex(random_bytes(3));
mkdir($tmp . '/data', 0775, true);
$auto = new YarboHomeAutomations($tmp);

$badStart = $auto->save([
    'id' => 'a-lymow-bare-start',
    'name' => 'Bare start',
    'trigger' => ['type' => 'time', 'at' => '21:00'],
    'actions' => [['kind' => 'lymow', 'command' => 'start']],
]);
assert_true(empty($badStart['ok']), 'reject start without zones: ' . json_encode($badStart));

$saved = $auto->save([
    'id' => 'a-lymow-start-zones',
    'name' => 'Start front',
    'trigger' => ['type' => 'time', 'at' => '21:00'],
    'actions' => [[
        'kind' => 'lymow',
        'command' => 'start',
        'zones' => ['wsmjco1T'],
        'zone_names' => ['Front Lawn'],
    ]],
]);
assert_true(!empty($saved['ok']), 'save start with zones: ' . json_encode($saved));
$store = $auto->load();
$rule = null;
foreach ($store['automations'] as $row) {
    if (($row['id'] ?? '') === 'a-lymow-start-zones') {
        $rule = $row;
        break;
    }
}
assert_true(is_array($rule), 'saved rule present');
assert_true(($rule['actions'][0]['zones'][0] ?? '') === 'wsmjco1T', 'zone hash stored');
assert_true(
    str_contains(YarboHomeAutomations::thenPhrase($rule['actions'][0]), 'Front Lawn'),
    'thenPhrase includes zone name'
);

$zones = YarboLymow::zonesFromData([
    'zones' => [
        ['hashId' => 'a1', 'name' => 'Side', 'isEnabled' => true, 'area' => 12.5],
        ['hash_id' => 'b2', 'name' => '', 'enabled' => false],
        ['name' => 'skip me'],
    ],
]);
assert_true(count($zones) === 2, 'zonesFromData count');
assert_true($zones[0]['hash_id'] === 'a1' && $zones[0]['name'] === 'Side', 'zonesFromData name');
assert_true($zones[0]['area_m2'] === 12.5, 'zonesFromData area');
assert_true($zones[1]['name'] === 'b2' && $zones[1]['enabled'] === false, 'zonesFromData fallback name');

assert_true(YarboLymow::normalizeZoneIds('a, b;c') === ['a', 'b', 'c'], 'normalizeZoneIds');

$task = YarboLymow::taskZonesFromData([
    'zones' => [
        ['hash_id' => 'a1', 'name' => 'South Lawn'],
        ['hash_id' => 'b2', 'name' => 'Orchard'],
    ],
    'task_zones' => [['hash_id' => 'a1'], 'b2'],
]);
assert_true(count($task) === 2, 'taskZonesFromData count');
assert_true($task[0]['name'] === 'South Lawn', 'taskZonesFromData resolves name');
assert_true(YarboLymow::taskZonesLabel($task) === 'South Lawn + Orchard', 'taskZonesLabel two');
assert_true(YarboLymow::taskZonesActive(2), 'task active while mowing');
assert_true(YarboLymow::taskZonesActive(3), 'task active while paused');
assert_true(YarboLymow::taskZonesActive(4), 'task active while docking');
assert_true(!YarboLymow::taskZonesActive(0), 'task inactive when idle');
assert_true(!YarboLymow::taskZonesActive(5), 'task inactive when charging after finish');
assert_true(!YarboLymow::taskZonesActive(5, true), 'CHARGE + is_charging still clears zone name');
assert_true(!YarboLymow::taskZonesActive(11), 'task inactive when update');
assert_true(!YarboLymow::taskZonesActive(12), 'task inactive when full');
assert_true(!YarboLymow::taskZonesActive(13), 'task inactive when stop/cancel');

$chargeDashRoot = sys_get_temp_dir() . '/yarbo-lymow-charge-zone-' . bin2hex(random_bytes(3));
mkdir($chargeDashRoot . '/data', 0775, true);
file_put_contents($chargeDashRoot . '/data/lymow-config.json', json_encode([
    'host' => '127.0.0.1',
    'rtsp_url' => '',
    'display_name' => '',
    'last_ok' => false,
    'last_check' => null,
    'last_error' => '',
], JSON_UNESCAPED_SLASHES));
file_put_contents($chargeDashRoot . '/data/lymow-state.json', json_encode([
    'ok' => true,
    'online' => true,
    'battery' => 52,
    'work_status' => 5,
    'is_charging' => true,
    'task_zones' => [['hash_id' => 'orch', 'name' => 'Orchard']],
    'zones' => [['hash_id' => 'orch', 'name' => 'Orchard', 'area' => 504]],
], JSON_UNESCAPED_SLASHES));
$chargeDash = (new YarboLymow($chargeDashRoot))->dashboardPayload();
assert_true(($chargeDash['work_label'] ?? '') === 'CHARGE', 'dashboard CHARGE without Orchard: ' . ($chargeDash['work_label'] ?? ''));
assert_true(($chargeDash['task_zones'] ?? []) === [], 'dashboard task_zones cleared on charge');
assert_true(($chargeDash['task_zones_label'] ?? null) === null || ($chargeDash['task_zones_label'] ?? '') === '',
    'dashboard task_zones_label cleared on charge');

file_put_contents($chargeDashRoot . '/data/lymow-state.json', json_encode([
    'ok' => true,
    'online' => true,
    'battery' => 52,
    'work_status' => 2,
    'is_charging' => false,
    'task_zones' => [['hash_id' => 'orch', 'name' => 'Orchard']],
    'zones' => [['hash_id' => 'orch', 'name' => 'Orchard', 'area' => 504]],
], JSON_UNESCAPED_SLASHES));
$mowDash = (new YarboLymow($chargeDashRoot))->dashboardPayload();
assert_true(($mowDash['work_label'] ?? '') === 'MOWING · Orchard', 'mowing still shows zone: ' . ($mowDash['work_label'] ?? ''));

$docs = (string) file_get_contents($root . '/docs/lymow.md');
assert_true(str_contains($docs, 'Refresh'), 'docs mention Refresh zones');
assert_true(str_contains($docs, 'hash IDs'), 'docs mention hash IDs');
assert_true(str_contains($docs, 'Cancel'), 'docs mention Cancel');
assert_true(str_contains($docs, 'does not return to dock'), 'docs say Cancel does not dock');
assert_true(str_contains($docs, 'MOWING ·'), 'docs mention active zone in State');

$change = (string) file_get_contents($root . '/CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.289]'), 'changelog 4.0.289');
assert_true(str_contains($change, '## [4.0.228]'), 'changelog 4.0.228');
assert_true(str_contains($change, 'Dock after Cancel'), 'changelog mentions Dock after Cancel');
assert_true(str_contains($change, '## [4.0.227]'), 'changelog 4.0.227');
assert_true(str_contains($change, 'FORCE_REINIT'), 'changelog mentions FORCE_REINIT');
assert_true(str_contains($change, '## [4.0.226]'), 'changelog 4.0.226');
assert_true(str_contains($change, 'active zone'), 'changelog mentions active zone');
assert_true(str_contains($change, '## [4.0.225]'), 'changelog 4.0.225');
assert_true(str_contains($change, 'Cancel'), 'changelog mentions Cancel');
assert_true(str_contains($change, '## [4.0.223]'), 'changelog 4.0.223');
assert_true(str_contains($change, 'zone picker'), 'changelog mentions zone picker');

fwrite(STDOUT, "test_lymow_controls.php ok\n");
