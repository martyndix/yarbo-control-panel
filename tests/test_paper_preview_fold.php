<?php

declare(strict_types=1);

$html = file_get_contents(__DIR__ . '/../public/index.php');
if ($html === false) {
    fwrite(STDERR, "missing index.php\n");
    exit(1);
}

$needles = [
    '<details class="papermono-preview-fold">',
    '<summary>Screen previews</summary>',
    'HOUSE — same tile shape as the unlock menu; long names wrap',
    'MAIL — inbox with time stamps, read receipts, and WRITE',
    'Lock screen — envelope when mail is waiting, Unlock, and OFF',
    'Unlock menu — Yarbo pages grouped. Tick which buttons to show. MAIL blob if unread',
    'Opposite corners: tap 1, then 2',
];

foreach (array_slice($needles, 0, 6) as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "missing: {$needle}\n");
        exit(1);
    }
}

if (str_contains($html, $needles[6])) {
    fwrite(STDERR, "stale lock-screen hint still present\n");
    exit(1);
}

$open = substr_count($html, '<details class="papermono-preview-fold">');
if ($open !== 1) {
    fwrite(STDERR, "expected one preview fold, got {$open}\n");
    exit(1);
}

echo "ok\n";
