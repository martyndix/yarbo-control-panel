<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/YarboChangelog.php';
require_once __DIR__ . '/../src/YarboHub.php';
require_once __DIR__ . '/../src/YarboVestaboard.php';
require_once __DIR__ . '/../src/YarboPaperDevice.php';
require_once __DIR__ . '/../src/YarboMetrics.php';

$lint = [];
exec('php -l ' . escapeshellarg(__DIR__ . '/../src/YarboMetrics.php') . ' 2>&1', $lint, $lintCode);
if ($lintCode !== 0) {
    fwrite(STDERR, "YarboMetrics.php does not parse\n" . implode("\n", $lint) . "\n");
    exit(1);
}

$src = file_get_contents(__DIR__ . '/../src/YarboMetrics.php');
if ($src === false || !str_contains($src, "'unifi' => !empty(\$modules[YarboHub::MODULE_UNIFI]),")) {
    fwrite(STDERR, "metrics payload must include the UniFi module flag\n");
    exit(1);
}
if (preg_match("/MODULE_UNIFI\\]\\),\\s*\\],\\s*'vestaboard'/", $src)) {
    fwrite(STDERR, "vestaboard must stay inside modules; a stray close broke every 4.0 ping\n");
    exit(1);
}

$root = sys_get_temp_dir() . '/yarbo-metrics-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
copy(__DIR__ . '/../CHANGELOG.md', $root . '/CHANGELOG.md');
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => [
        'yarbo' => true,
        'powerwall' => true,
        'lymow' => false,
        'home' => true,
        'unifi' => true,
    ],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/vestaboard-config.json', json_encode(['enabled' => true], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/papermono-devices.json', json_encode([
    'devices' => [
        ['id' => 'tab1', 'name' => 'Martyn', 'kind' => 'papermono', 'token' => 'aa'],
        ['id' => 'tab2', 'name' => 'Barbara', 'kind' => 'papercolor', 'token' => 'bb'],
    ],
    'prefs' => [],
    'messages' => [],
], JSON_UNESCAPED_SLASHES));

$metrics = new Yarbo\YarboMetrics($root);
$payload = $metrics->payload();
$version = Yarbo\YarboChangelog::currentVersion($root);
if (($payload['version'] ?? '') !== $version || $version === null || $version === '') {
    fwrite(STDERR, 'ping version ' . json_encode($payload['version'] ?? null) . " vs changelog {$version}\n");
    exit(1);
}
$modules = is_array($payload['modules'] ?? null) ? $payload['modules'] : [];
foreach (['yarbo' => true, 'powerwall' => true, 'lymow' => false, 'home' => true, 'unifi' => true, 'vestaboard' => true] as $key => $want) {
    if (($modules[$key] ?? null) !== $want) {
        fwrite(STDERR, "module {$key} " . json_encode($modules) . "\n");
        exit(1);
    }
}
if (!empty($payload['vestaboard']) && !isset($modules['vestaboard'])) {
    fwrite(STDERR, "vestaboard leaked outside modules\n");
    exit(1);
}
$paper = is_array($payload['paper'] ?? null) ? $payload['paper'] : [];
if ((int) ($paper['papermono'] ?? -1) !== 1 || (int) ($paper['papercolor'] ?? -1) !== 1) {
    fwrite(STDERR, 'paper counts ' . json_encode($paper) . "\n");
    exit(1);
}
if (!preg_match('/^[a-f0-9-]{16,64}$/i', (string) ($payload['id'] ?? ''))) {
    fwrite(STDERR, 'install id ' . json_encode($payload['id'] ?? null) . "\n");
    exit(1);
}

$change = file_get_contents(__DIR__ . '/../CHANGELOG.md');
if (!is_string($change) || !str_contains($change, '## [4.0.49]')) {
    fwrite(STDERR, "changelog 4.0.49 missing\n");
    exit(1);
}

echo "ok\n";
