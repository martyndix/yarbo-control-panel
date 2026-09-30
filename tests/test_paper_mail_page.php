<?php

declare(strict_types=1);

require __DIR__ . '/../src/YarboPaperDevice.php';
require __DIR__ . '/../src/YarboVestaboard.php';
require __DIR__ . '/../src/YarboHub.php';

$html = (string) file_get_contents(__DIR__ . '/../public/index.php');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$css = (string) file_get_contents(__DIR__ . '/../public/assets/style.css');
$fw = (string) file_get_contents(__DIR__ . '/../firmware/papermono/src/main.cpp');

foreach ([
    'id="mail-open"',
    'id="mail-open-wrap"',
    'id="mail-unread-badge"',
    'id="mail-page"',
    'id="paper-mail-to"',
    'id="paper-mail-text"',
    'class="settings-field"',
] as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "missing ui {$needle}\n");
        exit(1);
    }
}
if (str_contains($html, 'data-panel-id="mail"') || str_contains($html, 'data-panel-visible="mail"')) {
    fwrite(STDERR, "mail still on the dashboard\n");
    exit(1);
}
if (!str_contains($js, 'setMailCompanionVisible') || !str_contains($js, 'setMailUnreadBadge')) {
    fwrite(STDERR, "missing mail header JS\n");
    exit(1);
}
if (!str_contains($css, '.mail-page-layout') || !str_contains($css, '.settings-field select')) {
    fwrite(STDERR, "missing mail form CSS\n");
    exit(1);
}
if (!str_contains($fw, 'bool anyNewUnread = false;')) {
    fwrite(STDERR, "firmware does not rebuild inbox from the panel list\n");
    exit(1);
}

$root = sys_get_temp_dir() . '/yarbo-paper-mail-page-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
$oldAt = gmdate('c', time() - 8 * 86400);
$freshAt = gmdate('c', time() - 3600);
$offlineAt = gmdate('c', time() - 2 * 86400);
file_put_contents($root . '/data/papermono-devices.json', json_encode([
    'devices' => [
        [
            'id' => 'tab1',
            'name' => 'Kitchen',
            'kind' => 'papermono',
            'token' => 'tok1',
            'last_seen_at' => $offlineAt,
        ],
        ['id' => 'tab2', 'name' => 'Study', 'kind' => 'papermono', 'token' => 'tok2'],
    ],
    'prefs' => ['timezone' => 'Europe/Paris'],
    'messages' => [
        [
            'id' => 'old1',
            'from' => 'web',
            'from_name' => 'Desktop',
            'to' => 'tab1',
            'to_name' => 'Kitchen',
            'text' => 'This should expire',
            'at' => $oldAt,
            'reads' => [],
        ],
        [
            'id' => 'keep1',
            'from' => 'web',
            'from_name' => 'Desktop',
            'to' => 'tab1',
            'to_name' => 'Kitchen',
            'text' => 'Waiting on the kitchen tablet',
            'at' => $freshAt,
            'reads' => [],
        ],
    ],
], JSON_UNESCAPED_SLASHES));

$devices = new Yarbo\YarboPaperDevice($root);
$mail = $devices->mailView();
if (($mail['has_tablets'] ?? false) !== true) {
    fwrite(STDERR, 'has_tablets ' . json_encode($mail) . "\n");
    exit(1);
}
$ids = array_column($mail['messages'], 'id');
if (in_array('old1', $ids, true)) {
    fwrite(STDERR, "expired mail still in desktop inbox\n");
    exit(1);
}
if (!in_array('keep1', $ids, true)) {
    fwrite(STDERR, "fresh mail missing from desktop inbox\n");
    exit(1);
}

$stored = json_decode((string) file_get_contents($root . '/data/papermono-devices.json'), true);
$storedIds = array_column($stored['messages'] ?? [], 'id');
if (in_array('old1', $storedIds, true)) {
    fwrite(STDERR, "expired mail still on disk\n");
    exit(1);
}

$ref = new ReflectionClass($devices);
$compact = $ref->getMethod('prefsCompact');
$compact->setAccessible(true);
$tab1 = $devices->findById('tab1');
if ($tab1 === null) {
    fwrite(STDERR, "missing kitchen tablet\n");
    exit(1);
}
if ($devices->deviceIsOnline($tab1)) {
    fwrite(STDERR, "kitchen should still be offline for this test\n");
    exit(1);
}
$inbox = $compact->invoke($devices, $tab1)['paper_messages'] ?? [];
$inboxIds = array_column($inbox, 'id');
if (in_array('old1', $inboxIds, true)) {
    fwrite(STDERR, "expired mail still queued for tablet\n");
    exit(1);
}
if (!in_array('keep1', $inboxIds, true)) {
    fwrite(STDERR, "offline tablet did not receive stored mail\n");
    exit(1);
}

$queued = $devices->postPaperMessage($devices->webClient(), 'tab1', 'Arrive when you wake');
if (!($queued['ok'] ?? false)) {
    fwrite(STDERR, "queue send failed\n");
    exit(1);
}
$queuedId = (string) ($queued['message']['id'] ?? '');
$inbox2 = $compact->invoke($devices, $devices->findById('tab1'))['paper_messages'] ?? [];
$inbox2Ids = array_column($inbox2, 'id');
if ($queuedId === '' || !in_array($queuedId, $inbox2Ids, true)) {
    fwrite(STDERR, 'queued note missing from compact ' . json_encode($inbox2) . "\n");
    exit(1);
}

$emptyRoot = sys_get_temp_dir() . '/yarbo-paper-mail-empty-' . bin2hex(random_bytes(3));
mkdir($emptyRoot . '/data', 0775, true);
file_put_contents($emptyRoot . '/data/papermono-devices.json', json_encode([
    'devices' => [],
    'prefs' => [],
    'messages' => [],
], JSON_UNESCAPED_SLASHES));
$empty = new Yarbo\YarboPaperDevice($emptyRoot);
$emptyMail = $empty->mailView();
if (($emptyMail['has_tablets'] ?? true) !== false) {
    fwrite(STDERR, "has_tablets should be false without a PaperMono\n");
    exit(1);
}

echo "ok: paper mail page\n";
