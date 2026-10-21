<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/YarboHub.php';
require_once __DIR__ . '/../src/YarboUnifi.php';
require_once __DIR__ . '/../src/YarboHome.php';

use Yarbo\YarboHome;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function homeRoot(): string
{
    $root = sys_get_temp_dir() . '/yarbo-home-restore-' . bin2hex(random_bytes(4));
    mkdir($root . '/data', 0775, true);

    return $root;
}

function richStore(): array
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
        'last_devices' => [],
    ];
}

$root = homeRoot();
$path = $root . '/data/home.json';
file_put_contents($path, json_encode(richStore(), JSON_UNESCAPED_SLASHES));
$home = new YarboHome($root);
$home->load();
assert_true(is_file($root . '/data/home.json.bak'), 'first load snapshots a bak');

$wiped = richStore();
$wiped['room_defs'] = [];
$wiped['rooms'] = [];
$wiped['group_defs'] = [];
$wiped['groups'] = [];
$wiped['scenes'] = [];
$wiped['paper'] = [];
$wiped['names'] = [];
$wiped['last_devices'] = [['id' => '1:1', 'name' => 'Light', 'kind' => 'light']];
file_put_contents($path, json_encode($wiped, JSON_UNESCAPED_SLASHES));

$restored = $home->load();
assert_true(count($restored['room_defs']) === 3, 'rooms come back from bak: ' . json_encode($restored['room_defs']));
assert_true(count($restored['scenes']) === 2, 'scenes come back from bak');
assert_true(($restored['rooms']['1:1'] ?? '') === 'r1', 'room assignments come back');
assert_true(($restored['names']['1:1'] ?? '') === 'Kitchen spots', 'names come back');
assert_true(($restored['paper']['tab1'][1] ?? '') === 'scene:s1', 'HOUSE scene assignment comes back');

$disk = json_decode((string) file_get_contents($path), true);
assert_true(count($disk['room_defs'] ?? []) === 3, 'restored store is written back to home.json');

$root2 = homeRoot();
$path2 = $root2 . '/data/home.json';
file_put_contents($path2, json_encode(richStore(), JSON_UNESCAPED_SLASHES));
$home2 = new YarboHome($root2);
$write = new ReflectionMethod(YarboHome::class, 'write');
$write->setAccessible(true);
$empty = richStore();
$empty['room_defs'] = [];
$empty['rooms'] = [];
$empty['scenes'] = [];
$empty['last_devices'] = [['id' => '1:9', 'name' => 'New']];
$ok = $write->invoke($home2, $empty);
assert_true($ok === true, 'write of last_devices still succeeds');
$kept = $home2->load();
assert_true(count($kept['room_defs']) === 3, 'write must not persist empty rooms over a good file');
assert_true(count($kept['scenes']) === 2, 'write must not persist empty scenes over a good file');

$root3 = homeRoot();
$home3 = new YarboHome($root3);
file_put_contents($root3 . '/data/home.json.bak', json_encode(richStore(), JSON_UNESCAPED_SLASHES));
$missing = $home3->load();
assert_true(count($missing['room_defs']) === 3, 'missing home.json is rebuilt from bak');
assert_true(is_file($root3 . '/data/home.json'), 'missing home.json is recreated');

$root4 = homeRoot();
file_put_contents($root4 . '/data/home.json', '{not json');
file_put_contents($root4 . '/data/home.json.bak', json_encode(richStore(), JSON_UNESCAPED_SLASHES));
$corrupt = (new YarboHome($root4))->load();
assert_true(count($corrupt['room_defs']) === 3, 'corrupt home.json is rebuilt from bak');
assert_true(is_file($root4 . '/data/home.json.corrupt'), 'corrupt bytes are kept aside');

$root5 = homeRoot();
$sample = [
    'names' => ['1:2' => 'Light', '1:3' => 'Light', '1:4' => 'Lamp'],
    'room_defs' => [
        ['id' => 'r-kitchen', 'name' => 'Kitchen'],
        ['id' => 'r-hall', 'name' => 'Hall'],
        ['id' => 'r-porch', 'name' => 'Porch'],
    ],
    'rooms' => ['1:2' => 'r-kitchen', '1:3' => 'r-hall', '1:4' => 'r-kitchen'],
    'group_defs' => [],
    'groups' => [],
    'scenes' => [],
    'paper' => [],
    'hidden' => [],
    'device_order' => [],
];
file_put_contents($root5 . '/data/home.json', json_encode($sample, JSON_UNESCAPED_SLASHES));
$real = richStore();
for ($i = 3; $i <= 20; $i++) {
    $real['rooms']['1:' . $i] = 'r1';
    $real['names']['1:' . $i] = 'Light ' . $i;
}
file_put_contents($root5 . '/data/home.json.bak', json_encode($real, JSON_UNESCAPED_SLASHES));
$fromSample = (new YarboHome($root5))->load();
assert_true(($fromSample['rooms']['1:1'] ?? '') === 'r1', 'sample map is replaced from richer bak');
assert_true(count($fromSample['scenes']) === 2, 'scenes replace the empty sample');
assert_true(count($fromSample['rooms']) >= 10, 'assignments replace the sample map');

$root6 = homeRoot();
$regroup = richStore();
$regroup['room_defs'][] = ['id' => 'r4', 'name' => 'Loft'];
$regroup['scenes'] = [];
$regroup['rooms']['1:3'] = 'r4';
file_put_contents($root6 . '/data/home.json', json_encode($regroup, JSON_UNESCAPED_SLASHES));
file_put_contents($root6 . '/data/home.json.bak', json_encode(richStore(), JSON_UNESCAPED_SLASHES));
$kept = (new YarboHome($root6))->load();
assert_true(count($kept['room_defs']) === 4, 'regrouping rooms are not replaced from bak');
assert_true(($kept['rooms']['1:3'] ?? '') === 'r4', 'new room assignment is kept');
assert_true(($kept['scenes'] ?? []) === [], 'empty scenes are not restored while rooms are being rebuilt');

$php = (string) file_get_contents(__DIR__ . '/../src/YarboHome.php');
$sh = (string) file_get_contents(__DIR__ . '/../scripts/update.sh');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($php, "\$tmp = \$path . '.tmp'"), 'home.json writes via temp then rename');
assert_true(str_contains($php, 'function recoverUserMeta'), 'Home recovers user meta from backups');
assert_true(str_contains($php, 'function preserveUserMeta'), 'Home refuses empty room/scene writes');
assert_true(str_contains($sh, 'home.json.bak'), 'panel update preserves home.json.bak');
assert_true(str_contains($sh, 'home-backups'), 'panel update preserves dated home backups');
assert_true(str_contains($sh, 'home-automations.json'), 'panel update preserves automations');
assert_true(str_contains($js, 'Restored rooms and scenes from a backup'), 'Home toasts a restore');
assert_true(str_contains($js, 'homeManageOpen) return'), 'Home poll pauses while ⚙️ is open');
assert_true(str_contains($js, 'homeManageOpen && !opts.force'), 'Home GET does not retry while ⚙️ is open');
assert_true(str_contains($js, 'homePollBlocked() || homeManageOpen'), 'aborted Home GET does not retry while ⚙️ is open');
assert_true(str_contains($js, 'function saveHomeAssignment'), 'room assign retries once if Home was busy');
assert_true(str_contains($js, 'function applyHomeDeviceRoom'), 'room assign updates locally');
assert_true(str_contains($change, '## [4.0.133]'), 'changelog 4.0.133');
assert_true(str_contains($change, '## [4.0.135]'), 'changelog 4.0.135');
assert_true(str_contains($change, '## [4.0.136]'), 'changelog 4.0.136');
assert_true(str_contains($change, '## [4.0.137]'), 'changelog 4.0.137');
assert_true(str_contains($change, '## [4.0.138]'), 'changelog 4.0.138');

echo "test_home_meta_restore.php ok\n";
