<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = file_get_contents($root . '/public/index.php');
$js = file_get_contents($root . '/public/assets/app.js');
$changelog = file_get_contents($root . '/CHANGELOG.md');

if (!is_string($index) || !is_string($js) || !is_string($changelog)) {
    fwrite(STDERR, "could not read panel files\n");
    exit(1);
}

if (!preg_match('/<form id="settings-form"[^>]*\bnovalidate\b/', $index)) {
    fwrite(STDERR, "settings form must use novalidate so hidden-pane fields cannot block Save\n");
    exit(1);
}

if (!preg_match('/<form id="settings-form"[^>]*\bmethod="post"/', $index)) {
    fwrite(STDERR, "settings form must POST so a JS-less Save does not GET-reload\n");
    exit(1);
}

if (preg_match('/settingsHost\)\s*els\.settingsHost\.required/', $js)
    || preg_match('/settingsSerial\)\s*els\.settingsSerial\.required/', $js)
    || str_contains($js, 'els.settingsHost.required')
    || str_contains($js, 'els.settingsSerial.required')) {
    fwrite(STDERR, "do not set native required on broker/serial — they sit on a hidden pane\n");
    exit(1);
}

if (!str_contains($js, 'Broker IP and serial number are required.')) {
    fwrite(STDERR, "JS must still require broker/serial when Yarbo is on\n");
    exit(1);
}

if (!preg_match('/^## \[4\.0\.1\]/m', $changelog)) {
    fwrite(STDERR, "CHANGELOG must bump to 4.0.1 for the Settings Save fix\n");
    exit(1);
}

require $root . '/src/YarboHub.php';

$saved = Yarbo\YarboHub::modulesFromInput([
    'module_yarbo' => true,
    'module_powerwall' => false,
    'module_lymow' => false,
    'module_home' => true,
    'module_unifi' => true,
    'modules' => [
        'yarbo' => true,
        'powerwall' => false,
        'lymow' => false,
        'home' => true,
        'unifi' => true,
    ],
], [
    'yarbo' => true,
    'powerwall' => true,
    'lymow' => false,
    'home' => false,
    'unifi' => false,
]);
if ($saved['powerwall'] !== false || $saved['home'] !== true || $saved['unifi'] !== true || $saved['yarbo'] !== true) {
    fwrite(STDERR, 'modulesFromInput ' . json_encode($saved) . "\n");
    exit(1);
}

echo "ok: settings module save\n";
