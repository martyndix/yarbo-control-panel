<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/YarboHub.php';
require_once __DIR__ . '/../src/YarboUnifi.php';
require_once __DIR__ . '/../src/YarboHome.php';
require_once __DIR__ . '/../src/YarboHomeAutomations.php';

use Yarbo\YarboHome;
use Yarbo\YarboHomeAutomations;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function backupRoot(): string
{
    $root = sys_get_temp_dir() . '/yarbo-home-backup-' . bin2hex(random_bytes(4));
    mkdir($root . '/data', 0775, true);

    return $root;
}

function richHome(): array
{
    return [
        'names' => ['1:1' => 'Kitchen spots', '1:2' => 'Hall lamp'],
        'room_defs' => [
            ['id' => 'r1', 'name' => 'Kitchen'],
            ['id' => 'r2', 'name' => 'Hall'],
            ['id' => 'r3', 'name' => 'Cellar'],
        ],
        'rooms' => ['1:1' => 'r1', '1:2' => 'r2'],
        'group_defs' => [['id' => 'g1', 'name' => 'Spots', 'room_id' => 'r1']],
        'groups' => ['1:1' => 'g1'],
        'scenes' => [
            ['id' => 's1', 'name' => 'Evening', 'actions' => [['id' => '1:1', 'on' => true, 'brightness' => 40]]],
            ['id' => 's2', 'name' => 'Night', 'actions' => [['id' => '1:2', 'on' => false]]],
        ],
        'paper' => ['tab1' => ['1:1', 'scene:s1']],
        'hidden' => [],
        'device_order' => ['1:1', '1:2'],
        'last_devices' => [
            ['id' => '1:1', 'name' => 'Kitchen spots', 'kind' => 'light', 'on' => true],
        ],
    ];
}

function porchRule(): array
{
    return [
        'id' => 'a-porch',
        'name' => 'Front door opens → Porch on',
        'enabled' => true,
        'trigger' => ['type' => 'device', 'id' => 'unifi:hub:door1', 'event' => 'opens'],
        'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'on']],
        'names' => ['unifi:hub:door1' => 'Front door', 'unifi:light:porch' => 'Porch'],
    ];
}

$root = backupRoot();
file_put_contents($root . '/data/home.json', json_encode(richHome(), JSON_UNESCAPED_SLASHES));
$home = new YarboHome($root);
$auto = new YarboHomeAutomations($root);
$saved = $auto->save(porchRule());
assert_true(!empty($saved['ok']), 'seed automation: ' . json_encode($saved));

$exported = $home->exportBackup();
assert_true(!empty($exported['ok']), 'export ok');
$backup = $exported['backup'] ?? [];
assert_true(($backup['kind'] ?? '') === YarboHome::BACKUP_KIND, 'export kind');
assert_true(($backup['version'] ?? 0) === YarboHome::BACKUP_VERSION, 'export version');
assert_true(isset($backup['home']['room_defs'][0]['id']), 'export rooms');
assert_true(count($backup['home']['scenes'] ?? []) === 2, 'export scenes');
assert_true(count($backup['automations']['automations'] ?? []) === 1, 'export automations');
assert_true(($exported['summary']['rooms'] ?? 0) === 3, 'summary rooms');
assert_true(($exported['summary']['scenes'] ?? 0) === 2, 'summary scenes');
assert_true(($exported['summary']['automations'] ?? 0) === 1, 'summary automations');

$wiped = richHome();
$wiped['room_defs'] = [['id' => 'rx', 'name' => 'Other']];
$wiped['rooms'] = [];
$wiped['group_defs'] = [];
$wiped['groups'] = [];
$wiped['scenes'] = [];
$wiped['paper'] = [];
$wiped['names'] = ['1:9' => 'Spare'];
file_put_contents($root . '/data/home.json', json_encode($wiped, JSON_UNESCAPED_SLASHES));
$auto->delete('a-porch');
assert_true($auto->publicList() === [], 'automations cleared before restore');

$roundtrip = $home->importBackup(['action' => 'backup_import', 'backup' => $backup]);
assert_true(!empty($roundtrip['ok']), 'roundtrip: ' . json_encode($roundtrip));
$after = $home->load();
assert_true(count($after['room_defs']) === 3, 'rooms restored');
assert_true(($after['rooms']['1:1'] ?? '') === 'r1', 'assignments restored');
assert_true(count($after['scenes']) === 2, 'scenes restored');
assert_true(($after['names']['1:1'] ?? '') === 'Kitchen spots', 'names restored');
assert_true(($after['paper']['tab1'][1] ?? '') === 'scene:s1', 'HOUSE restored');
$rules = $auto->publicList();
assert_true(count($rules) === 1, 'automations restored');
assert_true(($rules[0]['id'] ?? '') === 'a-porch', 'automation id restored');

$garbage = $home->importBackup(['action' => 'backup_import', 'foo' => 'bar']);
assert_true(($garbage['ok'] ?? true) === false, 'garbage rejected');
assert_true(str_contains((string) ($garbage['error'] ?? ''), 'not a Home backup'), 'garbage error');

$keepRooms = $home->load();
$autoOnly = $home->importBackup([
    'action' => 'backup_import',
    'automations' => [
        [
            'id' => 'a-only',
            'enabled' => true,
            'trigger' => ['type' => 'device', 'id' => 'unifi:hub:door1', 'event' => 'closes'],
            'actions' => [['kind' => 'device', 'id' => 'unifi:light:porch', 'command' => 'off']],
        ],
    ],
]);
assert_true(!empty($autoOnly['ok']), 'automations-only: ' . json_encode($autoOnly));
$roomsStay = $home->load();
assert_true(($roomsStay['rooms']['1:1'] ?? '') === ($keepRooms['rooms']['1:1'] ?? ''), 'automations-only keeps rooms');
assert_true(count($roomsStay['scenes']) === count($keepRooms['scenes']), 'automations-only keeps scenes');
$onlyRules = $auto->publicList();
assert_true(count($onlyRules) === 1, 'automations-only replaces rules');
assert_true(($onlyRules[0]['id'] ?? '') === 'a-only', 'automations-only id');

$rawRoot = backupRoot();
file_put_contents($rawRoot . '/data/home.json', json_encode([
    'names' => ['1:9' => 'Spare'],
    'room_defs' => [['id' => 'rx', 'name' => 'Other']],
    'rooms' => [],
    'group_defs' => [],
    'groups' => [],
    'scenes' => [],
    'paper' => [],
    'hidden' => [],
    'device_order' => [],
    'last_devices' => [],
], JSON_UNESCAPED_SLASHES));
$rawHome = new YarboHome($rawRoot);
$rawAuto = new YarboHomeAutomations($rawRoot);
$rawAuto->save(porchRule());
$rawResult = $rawHome->importBackup(richHome());
assert_true(!empty($rawResult['ok']), 'raw home.json: ' . json_encode($rawResult));
$rawAfter = $rawHome->load();
assert_true(($rawAfter['rooms']['1:1'] ?? '') === 'r1', 'raw home.json restores rooms');
assert_true(count($rawAfter['scenes']) === 2, 'raw home.json restores scenes');
assert_true(count($rawAuto->publicList()) === 1, 'raw home.json keeps automations');
assert_true(($rawAuto->publicList()[0]['id'] ?? '') === 'a-porch', 'raw home.json does not wipe automations');

$wrapped = YarboHome::normalizeBackupPayload([
    'ok' => true,
    'backup' => [
        'kind' => YarboHome::BACKUP_KIND,
        'home' => ['room_defs' => [['id' => 'r1', 'name' => 'Kitchen']], 'scenes' => []],
        'automations' => ['automations' => [porchRule()]],
    ],
]);
assert_true(($wrapped['kind'] ?? '') === YarboHome::BACKUP_KIND, 'unwrap nested backup');
assert_true(isset($wrapped['home']['room_defs'][0]), 'unwrap home');
assert_true(isset($wrapped['automations']['automations'][0]), 'unwrap automations');

$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$html = (string) file_get_contents(__DIR__ . '/../public/index.php');
$api = (string) file_get_contents(__DIR__ . '/../public/api/home.php');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
$docs = (string) file_get_contents(__DIR__ . '/../docs/home.md');
assert_true(str_contains($js, 'function downloadHomeBackup'), 'downloadHomeBackup exists');
assert_true(str_contains($js, 'function restoreHomeBackupFromInput'), 'restoreHomeBackupFromInput exists');
assert_true(str_contains($js, 'backup_export'), 'JS calls backup_export');
assert_true(str_contains($js, 'backup_import'), 'JS calls backup_import');
assert_true(str_contains($html, 'id="home-backup-download"'), 'Download backup button');
assert_true(str_contains($html, 'id="home-backup-restore"'), 'Restore from file button');
assert_true(str_contains($api, "'backup_export'"), 'API export action');
assert_true(str_contains($api, "'backup_import'"), 'API import action');
assert_true(str_contains($change, '## [4.0.134]'), 'changelog 4.0.134');
assert_true(str_contains($docs, 'Download backup'), 'docs mention Download backup');

echo "test_home_backup.php ok\n";
