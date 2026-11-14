<?php

declare(strict_types=1);

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "ok: {$message}\n";
}

$root = dirname(__DIR__);
$html = (string) file_get_contents($root . '/public/index.php');
$css = (string) file_get_contents($root . '/public/assets/style.css');
$change = (string) file_get_contents($root . '/CHANGELOG.md');

assert_true(str_contains($html, 'app-header-top'), 'header top row wrapper');
assert_true(str_contains($html, 'id="module-switcher"'), 'module switcher present');
assert_true(
    strpos($html, 'app-header-top') < strpos($html, 'id="module-switcher"'),
    'module switcher is after header top (full-width row)'
);
assert_true(str_contains($html, 'auto-editor-layout'), 'automation editor layout wrapper');
assert_true(
    preg_match('/auto-editor-layout[\s\S]*?auto-builder[\s\S]*?auto-tray-wrap/s', $html) === 1,
    'builder and tray share editor layout'
);

assert_true(str_contains($css, '.module-switcher'), 'module switcher styles');
assert_true(str_contains($css, 'flex-wrap: nowrap'), 'module tabs stay on one row');
assert_true(str_contains($css, '.auto-editor-layout'), 'auto editor layout styles');
assert_true(str_contains($css, 'minmax(17rem, 23rem)'), 'desktop tray column width');
assert_true(str_contains($css, 'position: sticky'), 'desktop tray sticky');
assert_true(str_contains($change, '## [4.0.291]'), 'changelog 4.0.291');

echo "test_ui_mobile_nav_auto_layout.php ok\n";
