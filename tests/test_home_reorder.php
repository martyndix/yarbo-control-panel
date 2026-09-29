<?php

declare(strict_types=1);

require __DIR__ . '/../src/YarboHome.php';
require __DIR__ . '/../src/YarboHub.php';

$root = sys_get_temp_dir() . '/yarbo-home-reorder-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);

$home = new Yarbo\YarboHome($root);
$path = $root . '/data/home.json';
file_put_contents($path, json_encode([
    'names' => [],
    'room_defs' => [
        ['id' => 'r1', 'name' => 'Kitchen'],
        ['id' => 'r2', 'name' => 'Living'],
        ['id' => 'r3', 'name' => 'Hall'],
    ],
    'rooms' => ['1:1' => 'r1', '1:2' => 'r1'],
    'group_defs' => [
        ['id' => 'g1', 'name' => 'Spots', 'room_id' => 'r1'],
        ['id' => 'g2', 'name' => 'Lamps', 'room_id' => 'r1'],
        ['id' => 'g3', 'name' => 'Other', 'room_id' => 'r2'],
    ],
    'groups' => [],
    'scenes' => [
        ['id' => 's1', 'name' => 'Evening', 'actions' => [['id' => '1:1', 'on' => true]]],
        ['id' => 's2', 'name' => 'Night', 'actions' => [['id' => '1:1', 'on' => false]]],
    ],
    'paper' => ['tab1' => ['1:1', 'scene:s1']],
    'hidden' => [],
    'device_order' => [],
], JSON_UNESCAPED_SLASHES));

$rooms = $home->reorder(['kind' => 'rooms', 'ids' => ['r2', 'r1', 'r3']]);
if (!($rooms['ok'] ?? false)) {
    fwrite(STDERR, "rooms failed\n");
    exit(1);
}
$store = $home->load();
$roomIds = array_column($store['room_defs'], 'id');
if ($roomIds !== ['r2', 'r1', 'r3']) {
    fwrite(STDERR, 'room order ' . json_encode($roomIds) . "\n");
    exit(1);
}

$groups = $home->reorder(['kind' => 'groups', 'room_id' => 'r1', 'ids' => ['g2', 'g1']]);
if (!($groups['ok'] ?? false)) {
    fwrite(STDERR, "groups failed\n");
    exit(1);
}
$store = $home->load();
$groupIds = array_column($store['group_defs'], 'id');
if ($groupIds !== ['g2', 'g1', 'g3']) {
    fwrite(STDERR, 'group order ' . json_encode($groupIds) . "\n");
    exit(1);
}

$scenes = $home->reorder(['kind' => 'scenes', 'ids' => ['s2', 's1']]);
if (!($scenes['ok'] ?? false)) {
    fwrite(STDERR, "scenes failed\n");
    exit(1);
}
$store = $home->load();
$sceneIds = array_column($store['scenes'], 'id');
if ($sceneIds !== ['s2', 's1']) {
    fwrite(STDERR, 'scene order ' . json_encode($sceneIds) . "\n");
    exit(1);
}

$devices = $home->reorder(['kind' => 'devices', 'ids' => ['1:2', '1:1']]);
if (!($devices['ok'] ?? false)) {
    fwrite(STDERR, "devices failed\n");
    exit(1);
}
$store = $home->load();
if ($store['device_order'] !== ['1:2', '1:1']) {
    fwrite(STDERR, 'device order ' . json_encode($store['device_order']) . "\n");
    exit(1);
}

$paper = $home->reorder(['kind' => 'paper', 'tablet_id' => 'tab1', 'ids' => ['scene:s1', '1:1']]);
if (!($paper['ok'] ?? false)) {
    fwrite(STDERR, "paper failed\n");
    exit(1);
}
$store = $home->load();
if ($store['paper']['tab1'] !== ['scene:s1', '1:1']) {
    fwrite(STDERR, 'paper order ' . json_encode($store['paper']['tab1']) . "\n");
    exit(1);
}

echo "ok: home reorder\n";
