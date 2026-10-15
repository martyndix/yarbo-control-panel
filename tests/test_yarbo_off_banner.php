<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHub;

$rootFiles = dirname(__DIR__);
$js = (string) file_get_contents($rootFiles . '/public/assets/app.js');
$html = (string) file_get_contents($rootFiles . '/public/index.php');
$status = (string) file_get_contents($rootFiles . '/public/api/status.php');
$change = (string) file_get_contents($rootFiles . '/CHANGELOG.md');

if ($js === '' || $html === '' || $status === '' || $change === '') {
    fwrite(STDERR, "could not read panel files\n");
    exit(1);
}

if (!str_contains($js, 'function yarboModuleEnabled') || !str_contains($js, 'if (message && !yarboModuleEnabled())')) {
    fwrite(STDERR, "setError must skip Yarbo connection errors when the module is off\n");
    exit(1);
}

if (!str_contains($js, 'if (!ids.includes(\'yarbo\'))')) {
    fwrite(STDERR, "module switcher must clear the Yarbo error banner when Yarbo is off\n");
    exit(1);
}

if (!str_contains($html, 'data-yarbo-module=')) {
    fwrite(STDERR, "index must expose whether Yarbo is on before JS loads\n");
    exit(1);
}

if (!str_contains($status, 'if (!$hub->enabled(YarboHub::MODULE_YARBO))')) {
    fwrite(STDERR, "status.php must skip MQTT when Yarbo is off\n");
    exit(1);
}

if (!str_contains($change, '## [4.0.113]')) {
    fwrite(STDERR, "changelog 4.0.113\n");
    exit(1);
}

$tmp = sys_get_temp_dir() . '/yarbo-off-banner-' . bin2hex(random_bytes(3));
mkdir($tmp . '/data', 0775, true);
$hub = new YarboHub($tmp);
if (!$hub->save([
    'modules' => [
        'yarbo' => false,
        'powerwall' => true,
        'lymow' => true,
        'home' => true,
        'unifi' => true,
    ],
])) {
    fwrite(STDERR, "save disabled yarbo failed\n");
    exit(1);
}
if ($hub->enabled(YarboHub::MODULE_YARBO)) {
    fwrite(STDERR, "enabled() still true after disabling Yarbo\n");
    exit(1);
}
$view = $hub->publicView();
$ids = array_column($view['enabled'], 'id');
if (in_array('yarbo', $ids, true)) {
    fwrite(STDERR, 'publicView still lists Yarbo ' . json_encode($ids) . "\n");
    exit(1);
}

echo "ok: yarbo off banner\n";
