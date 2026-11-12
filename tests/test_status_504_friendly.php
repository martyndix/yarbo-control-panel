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
assert_true(str_contains($js, 'Open the panel on the home network'), 'home-network hint');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.256]'), 'changelog 4.0.256');

echo "ok\n";
