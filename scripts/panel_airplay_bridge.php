<?php

declare(strict_types=1);

/**
 * CLI bridge for shairport-sync session hooks.
 * Usage: php scripts/panel_airplay_bridge.php active|inactive
 */

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "autoload missing\n");
    exit(1);
}
require $autoload;

use Yarbo\YarboPanelAirPlay;
use Yarbo\YarboUnifi;
use Yarbo\YarboUnifiRadio;

$action = strtolower(trim((string) ($argv[1] ?? '')));
if ($action !== 'active' && $action !== 'inactive') {
    fwrite(STDERR, "usage: panel_airplay_bridge.php active|inactive\n");
    exit(2);
}

$airplay = new YarboPanelAirPlay($root);
$unifi = new YarboUnifi($root);
$radio = new YarboUnifiRadio($root, $unifi);

if ($action === 'active') {
    $airplay->markSessionActive(true);
    $result = $radio->onAirplayClientActive();
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
    exit(!empty($result['ok']) ? 0 : 1);
}

$airplay->markSessionActive(false);
$result = $radio->onAirplayClientInactive();
echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
exit(!empty($result['ok']) ? 0 : 1);
