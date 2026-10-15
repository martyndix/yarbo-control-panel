<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/public/assets/app.js');
$css = (string) file_get_contents($root . '/public/assets/style.css');
$change = (string) file_get_contents($root . '/CHANGELOG.md');

if ($js === '' || $css === '' || $change === '') {
    fwrite(STDERR, "could not read home camera files\n");
    exit(1);
}

if (!str_contains($js, "camera ? ' home-device--camera' : ''") && !str_contains($js, 'home-device--camera')) {
    fwrite(STDERR, "camera cards must get home-device--camera\n");
    exit(1);
}

if (!str_contains($js, 'const lit = on && !camera') && !str_contains($js, 'Boolean(on) && !card.classList.contains(\'home-device--camera\')')) {
    fwrite(STDERR, "camera tiles must not take the green is-on class\n");
    exit(1);
}

if (!str_contains($css, '.home-device--camera:not(.home-device--hidden)')) {
    fwrite(STDERR, "manage layout must stack camera stills below the room select\n");
    exit(1);
}

if (!str_contains($css, '"handle actions actions"')) {
    fwrite(STDERR, "camera still row must sit below room/group, not beside the dropdown\n");
    exit(1);
}

if (!str_contains($css, '.home-device-actions--camera') || !str_contains($css, 'min-width: 0')) {
    fwrite(STDERR, "camera still cell must shrink instead of overlapping the room select\n");
    exit(1);
}

if (!str_contains($css, '.home-device-room-select') || !str_contains($css, 'z-index: 2')) {
    fwrite(STDERR, "room dropdown must stack above the still\n");
    exit(1);
}

if (!str_contains($css, '.home-device--camera.is-on')) {
    fwrite(STDERR, "camera is-on must stay a neutral row\n");
    exit(1);
}

if (!str_contains($change, '## [4.0.114]')) {
    fwrite(STDERR, "changelog 4.0.114\n");
    exit(1);
}

if (!str_contains($change, '## [4.0.115]')) {
    fwrite(STDERR, "changelog 4.0.115\n");
    exit(1);
}

echo "ok: home camera room\n";
