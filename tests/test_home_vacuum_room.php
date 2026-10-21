<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/public/assets/app.js');
$css = (string) file_get_contents($root . '/public/assets/style.css');
$change = (string) file_get_contents($root . '/CHANGELOG.md');

if ($js === '' || $css === '' || $change === '') {
    fwrite(STDERR, "could not read home vacuum files\n");
    exit(1);
}

if (!str_contains($js, "vacuum ? ' home-device--vacuum' : ''") && !str_contains($js, 'home-device--vacuum')) {
    fwrite(STDERR, "vacuum cards must get home-device--vacuum\n");
    exit(1);
}

if (!str_contains($css, ':not(.home-device--camera):not(.home-device--vacuum)')) {
    fwrite(STDERR, "manage 5-col grid must skip vacuum cards\n");
    exit(1);
}

if (!str_contains($css, '.home-device.home-device--vacuum:not(.home-device--hidden)')) {
    fwrite(STDERR, "manage layout must stack vacuum room/group above Start\n");
    exit(1);
}

if (!str_contains($css, '"handle room manage"') || !str_contains($css, '"handle group manage"')) {
    fwrite(STDERR, "vacuum room and group must sit on their own rows\n");
    exit(1);
}

if (!str_contains($css, '.home-device.home-device--vacuum:not(.home-device--hidden) > .home-device-room-select')) {
    fwrite(STDERR, "vacuum room select must be sized on its own row\n");
    exit(1);
}

if (!str_contains($css, 'min-width: 12rem') || !str_contains($css, 'min-height: 2rem')) {
    fwrite(STDERR, "vacuum room dropdown must be wide and tall enough to use\n");
    exit(1);
}

if (!str_contains($change, '## [4.0.136]')) {
    fwrite(STDERR, "changelog 4.0.136\n");
    exit(1);
}

$jsApp = $js;
if (!str_contains($jsApp, 'homeManageOpen && !opts.force')) {
    fwrite(STDERR, "vacuum layout ship must also pause Home GET while manage is open\n");
    exit(1);
}

echo "ok: home vacuum room\n";
