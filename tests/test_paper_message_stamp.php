<?php

declare(strict_types=1);

require __DIR__ . '/../src/YarboPaperDevice.php';
require __DIR__ . '/../src/YarboVestaboard.php';
require __DIR__ . '/../src/YarboHub.php';

$root = sys_get_temp_dir() . '/yarbo-paper-stamp-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/papermono-devices.json', json_encode([
    'devices' => [],
    'prefs' => ['timezone' => 'Europe/Paris'],
    'messages' => [],
], JSON_UNESCAPED_SLASHES));

$devices = new Yarbo\YarboPaperDevice($root);
$ref = new ReflectionClass($devices);
$format = $ref->getMethod('formatMessageLocal');
$format->setAccessible(true);
$tzMethod = $ref->getMethod('prefsTimezone');
$tzMethod->setAccessible(true);
$tz = $tzMethod->invoke($devices);
$out = $format->invoke($devices, '2026-09-29T18:20:00+00:00', $tz);
if ($out !== 'Tue 29 Sep 20:20') {
    fwrite(STDERR, "stamp {$out}\n");
    exit(1);
}
$empty = $format->invoke($devices, '', $tz);
if ($empty !== '') {
    fwrite(STDERR, "empty stamp {$empty}\n");
    exit(1);
}

echo "ok: paper message stamp\n";
