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
    'void rebuildMenuLayout()',
    'void applyMenuVisible(JsonVariant vis)',
    'i == PAPERMONO_PAGE_HOME || i == PAPERMONO_PAGE_BOARD',
    '#define PAPERMONO_FW_VERSION "0.1.49"',
    "public const FIRMWARE_VERSION = '0.1.49';",
    'data-menu-label="yarbo"',
    'data-menu-visible="yarbo"',
    'id="paper-menu-list"',
    'int stepYarboPage(int from, int dir)',
    'void applyMenuOrder(JsonVariant order)',
    'Unlock menu — one YARBO button; A/B steps Status, Health, Plans. Reorder in Settings. MAIL blob if unread',
    'PaperMono firmware **0.1.49**',
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
