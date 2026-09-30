<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$fw = file_get_contents($root . '/firmware/papermono/src/main.cpp');
$ver = file_get_contents($root . '/firmware/papermono/src/version.h');
$php = file_get_contents($root . '/src/YarboPaperDevice.php');
$html = file_get_contents($root . '/public/index.php');
$log = file_get_contents($root . '/CHANGELOG.md');
if ($fw === false || $ver === false || $php === false || $html === false || $log === false) {
    fwrite(STDERR, "missing source\n");
    exit(1);
}

$needles = [
    'bool menuOpen = false;',
    'void drawMenuPage(bool forceFull)',
    'void handleMenuTouch(int x, int y)',
    'void drawNotifyBlob(int bx, int by, int bw, bool invert)',
    'bool tapOnMenuChip(int x, int y)',
    'menuOpen = true;',
    'PAPERMONO_PAGE_RADIO && unreadCount > 0',
    'PAPERMONO_PAGE_HOME || !pageEnabled(i)',
    'void applyMenuLabels(JsonVariant labels)',
    'void drawFittedLabel(int cx, int cy, int maxW, const String &text, uint16_t fg, uint16_t bg)',
    'void houseButtonRect(int idx, int &x, int &y, int &w, int &h)',
    '#define PAPERMONO_FW_VERSION "0.1.46"',
    "public const FIRMWARE_VERSION = '0.1.46';",
    'data-menu-label="status"',
    'id="paper-menu-labels"',
    'PaperMono Unlock always opens the page menu',
    'Unlock menu — tap a page. MAIL shows a blob when a message is unread. Names are set in Settings',
    'PaperMono firmware **0.1.46**',
];

$hay = $fw . "\n" . $ver . "\n" . $php . "\n" . $html . "\n" . $log;
foreach ($needles as $needle) {
    if (!str_contains($hay, $needle)) {
        fwrite(STDERR, "missing: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($fw, 'if (menuOpen) {')) {
    fwrite(STDERR, "drawScreen missing menuOpen branch\n");
    exit(1);
}

echo "ok\n";
