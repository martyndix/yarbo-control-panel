<?php

declare(strict_types=1);

require __DIR__ . '/../src/YarboPaperDevice.php';
require __DIR__ . '/../src/YarboVestaboard.php';
require __DIR__ . '/../src/YarboHub.php';

$root = sys_get_temp_dir() . '/yarbo-paper-read-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/papermono-devices.json', json_encode([
    'devices' => [
        ['id' => 'tab1', 'name' => 'Tablet 1', 'kind' => 'papermono', 'token' => 'tok1'],
        ['id' => 'tab2', 'name' => 'Tablet 2', 'kind' => 'papermono', 'token' => 'tok2'],
    ],
    'prefs' => ['timezone' => 'Europe/Paris'],
    'messages' => [],
], JSON_UNESCAPED_SLASHES));

$devices = new Yarbo\YarboPaperDevice($root);
$from = $devices->findById('tab1');
$to = $devices->findById('tab2');
if ($from === null || $to === null) {
    fwrite(STDERR, "devices missing\n");
    exit(1);
}

$sent = $devices->postPaperMessage($from, 'tab2', 'TESTING');
if (!($sent['ok'] ?? false) || ($sent['message']['text'] ?? '') !== 'TESTING') {
    fwrite(STDERR, "send failed\n");
    exit(1);
}
$mid = (string) $sent['message']['id'];

$ref = new ReflectionClass($devices);
$compact = $ref->getMethod('prefsCompact');
$compact->setAccessible(true);
$sender = $compact->invoke($devices, $from);
$recv = $compact->invoke($devices, $to);
$sMsg = $sender['paper_messages'][0] ?? null;
$rMsg = $recv['paper_messages'][0] ?? null;
if (!($sMsg['mine'] ?? false) || ($sMsg['read_label'] ?? '') !== 'Sent') {
    fwrite(STDERR, 'sender before ' . json_encode($sMsg) . "\n");
    exit(1);
}
if (($rMsg['mine'] ?? true) || ($rMsg['text'] ?? '') !== 'TESTING') {
    fwrite(STDERR, 'recv before ' . json_encode($rMsg) . "\n");
    exit(1);
}

$read = $devices->markPaperRead($to, $mid);
if (!($read['ok'] ?? false)) {
    fwrite(STDERR, "read failed\n");
    exit(1);
}
$again = $devices->markPaperRead($to, $mid);
if (!($again['ok'] ?? false) || !($again['already'] ?? false)) {
    fwrite(STDERR, "already " . json_encode($again) . "\n");
    exit(1);
}

$sender2 = $compact->invoke($devices, $from);
$label = (string) ($sender2['paper_messages'][0]['read_label'] ?? '');
if (!str_starts_with($label, 'Read')) {
    fwrite(STDERR, "label {$label}\n");
    exit(1);
}

echo "ok: paper read receipt\n";
