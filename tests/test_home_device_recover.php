<?php

declare(strict_types=1);

require __DIR__ . '/../src/YarboHome.php';
require __DIR__ . '/../src/YarboHub.php';

$root = sys_get_temp_dir() . '/yarbo-home-recover-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);

$remembered = [
    ['id' => '1:1', 'name' => 'Kitchen spots', 'kind' => 'light', 'node_id' => 1, 'endpoint' => 1],
    ['id' => '1:2', 'name' => 'Kitchen lamp', 'kind' => 'light', 'node_id' => 1, 'endpoint' => 2],
];
file_put_contents($root . '/data/home.json', json_encode([
    'names' => ['1:1' => 'Kitchen spots'],
    'room_defs' => [['id' => 'r1', 'name' => 'Kitchen']],
    'rooms' => ['1:1' => 'r1'],
    'group_defs' => [],
    'groups' => [],
    'scenes' => [],
    'paper' => [],
    'hidden' => [],
    'device_order' => ['1:1', '1:2'],
    'last_devices' => $remembered,
], JSON_UNESCAPED_SLASHES));

$home = new Yarbo\YarboHome($root);
$store = $home->load();
if (count($store['last_devices']) !== 2) {
    fwrite(STDERR, 'last_devices lost on load ' . json_encode($store['last_devices']) . "\n");
    exit(1);
}

$home->saveMeta(['names' => ['1:1' => 'Kitchen spots']]);
$store = $home->load();
if (count($store['last_devices']) !== 2) {
    fwrite(STDERR, "last_devices wiped by saveMeta\n");
    exit(1);
}

$kept = Yarbo\YarboHome::preferLiveOrRemembered([], $store['last_devices']);
if (count($kept) !== 2 || ($kept[0]['id'] ?? '') !== '1:1') {
    fwrite(STDERR, "empty live wiped remembered devices\n");
    exit(1);
}
$stub = [['id' => '13:1', 'name' => 'Matter node 13', 'kind' => 'light', 'node_id' => 13, 'endpoint' => 1, 'vendor' => '']];
$named = Yarbo\YarboHome::preferLiveOrRemembered($stub, $store['last_devices']);
if (count($named) !== 2 || ($named[0]['id'] ?? '') !== '1:1') {
    fwrite(STDERR, "uninterviewed stub hid remembered devices\n");
    exit(1);
}
$live = Yarbo\YarboHome::preferLiveOrRemembered(
    [['id' => '1:9', 'name' => 'New']],
    $store['last_devices']
);
if (($live[0]['id'] ?? '') !== '1:9') {
    fwrite(STDERR, "live list should win when present\n");
    exit(1);
}

echo "ok\n";
