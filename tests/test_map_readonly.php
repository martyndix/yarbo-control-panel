<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboMapBackup;
use Yarbo\YarboMqtt;

$root = dirname(__DIR__);
$backups = new YarboMapBackup($root);

// Construct without connecting — restoreDraft must return before any MQTT I/O.
$client = new YarboMqtt('127.0.0.1', 9, 'test-serial');
$result = $backups->restoreDraft($client, [
    'type' => 'FeatureCollection',
    'features' => [],
], true);

if (($result['ok'] ?? true) !== false) {
    fwrite(STDERR, "restoreDraft must fail closed\n");
    exit(1);
}
if (($result['map_writes'] ?? true) !== false) {
    fwrite(STDERR, "restoreDraft must set map_writes=false\n");
    exit(1);
}
if (!str_contains((string) ($result['error'] ?? ''), 'disabled')) {
    fwrite(STDERR, 'unexpected error: ' . json_encode($result) . "\n");
    exit(1);
}

$api = file_get_contents($root . '/public/api/map_backup.php');
if ($api === false || !str_contains($api, "action === 'restore'")) {
    fwrite(STDERR, "map_backup API missing restore guard\n");
    exit(1);
}
if (!str_contains($api, '403') || !str_contains($api, 'Map writes are disabled')) {
    fwrite(STDERR, "map_backup API must reject restore with 403\n");
    exit(1);
}

$index = file_get_contents($root . '/public/index.php');
if ($index === false) {
    fwrite(STDERR, "missing index.php\n");
    exit(1);
}
foreach (['map-edit-toggle', 'map-save-robot', 'map-export-draft', 'leaflet-draw'] as $needle) {
    if (str_contains($index, $needle)) {
        fwrite(STDERR, "index still exposes {$needle}\n");
        exit(1);
    }
}

$js = file_get_contents($root . '/public/assets/app.js');
if ($js === false || !str_contains($js, 'Map writes are disabled')) {
    fwrite(STDERR, "app.js missing map-write disabled toast\n");
    exit(1);
}
if (str_contains($js, 'data-zone-edit=')) {
    fwrite(STDERR, "zone Edit buttons still rendered\n");
    exit(1);
}

$backupSrc = file_get_contents($root . '/src/YarboMapBackup.php');
if ($backupSrc === false || !str_contains($backupSrc, "'blocked' => true")) {
    fwrite(STDERR, "saveChangedZones/sendUnpublished must hard-block map writes\n");
    exit(1);
}

$change = file_get_contents($root . '/CHANGELOG.md');
if ($change === false || !str_contains($change, '## [4.0.212]')) {
    fwrite(STDERR, "changelog 4.0.212 missing\n");
    exit(1);
}

$discover = file_get_contents($root . '/scripts/discover_map.php');
if ($discover === false || !str_contains($discover, 'Refusing --send-probes')) {
    fwrite(STDERR, "discover_map must refuse --send-probes\n");
    exit(1);
}

echo "map readonly ok\n";
