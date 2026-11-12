<?php

declare(strict_types=1);

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
assert_true(str_contains($js, 'function gatewayHtmlError'), 'gatewayHtmlError helper');
assert_true(str_contains($js, 'Panel gateway timed out (504)'), 'friendly 504 copy');
assert_true(str_contains($js, 'no-js ie6 oldie'), 'detects Cloudflare HTML');
assert_true(str_contains($js, 'Retrying…') || str_contains($js, 'Retrying...'), 'retries instead of forcing LAN');
assert_true(!str_contains($js, 'Open the panel on the home network and refresh'), 'no LAN-only refresh nag');
assert_true(str_contains($js, 'restoreVestaboardPreviewCache'), 'restores Vestaboard from session cache');
assert_true(str_contains($js, 'isGatewayTimeoutError'), 'soft gateway timeout helper');
assert_true(str_contains($js, 'Home is taking longer than usual'), 'home keeps retrying without diagnostic nag');

$status = (string) file_get_contents(__DIR__ . '/../public/api/status.php');
assert_true(str_contains($status, 'telemetry(2.5, true, true, false)'), 'status prefers cached telemetry');
assert_true(!str_contains($status, 'requireRunning'), 'status does not block on agent spawn');
assert_true(str_contains($status, 'hub_status_extras(false)'), 'status skips live Powerwall refresh');

$vb = (string) file_get_contents(__DIR__ . '/../src/YarboVestaboard.php');
assert_true(str_contains($vb, 'vestaboardLayout(false)'), 'Powerwall Vestaboard layout uses cache on dashboard');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.256]'), 'changelog 4.0.256');
assert_true(str_contains($change, '## [4.0.261]'), 'changelog 4.0.261');
assert_true(str_contains($change, '## [4.0.262]'), 'changelog 4.0.262');
assert_true(str_contains($change, '## [4.0.263]'), 'changelog 4.0.263');

$paper = (string) file_get_contents(__DIR__ . '/../src/YarboPaperDevice.php');
assert_true(str_contains($paper, 'telemetry(2.0, false, true, false)'), 'compact never spawns MQTT agent');
assert_true(str_contains($paper, 'nightLayout(false)'), 'compact night preview skips UniFi door scan');

assert_true(str_contains($vb, 'nightLayout(false)'), 'web night dashboard skips UniFi door scan');
assert_true(str_contains($status, '$transient ? 200 : 504'), 'transient telemetry returns JSON 200');

echo "ok\n";
