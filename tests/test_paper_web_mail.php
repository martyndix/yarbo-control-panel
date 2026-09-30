<?php

declare(strict_types=1);

require __DIR__ . '/../src/YarboPaperDevice.php';
require __DIR__ . '/../src/YarboVestaboard.php';
require __DIR__ . '/../src/YarboHub.php';

$root = sys_get_temp_dir() . '/yarbo-paper-web-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/papermono-devices.json', json_encode([
    'devices' => [
        ['id' => 'tab1', 'name' => 'Kitchen', 'kind' => 'papermono', 'token' => 'tok1'],
        ['id' => 'tab2', 'name' => 'Study', 'kind' => 'papermono', 'token' => 'tok2'],
    ],
    'prefs' => ['timezone' => 'Europe/Paris'],
    'messages' => [],
], JSON_UNESCAPED_SLASHES));

$devices = new Yarbo\YarboPaperDevice($root);
$web = $devices->webClient();
if (($web['id'] ?? '') !== 'web' || ($web['kind'] ?? '') !== 'web') {
    fwrite(STDERR, 'web client ' . json_encode($web) . "\n");
    exit(1);
}

$dash = $devices->dashboard();
$ids = array_column($dash['devices'], 'id');
if (in_array('web', $ids, true)) {
    fwrite(STDERR, "web leaked into tablet list\n");
    exit(1);
}
if (($dash['web_client']['name'] ?? '') !== 'Desktop') {
    fwrite(STDERR, 'dashboard web ' . json_encode($dash['web_client']) . "\n");
    exit(1);
}

$renamed = $devices->rename('web', 'Office PC');
if (($renamed['name'] ?? '') !== 'Office PC') {
    fwrite(STDERR, "rename failed\n");
    exit(1);
}

$ref = new ReflectionClass($devices);
$compact = $ref->getMethod('prefsCompact');
$compact->setAccessible(true);
$tab1 = $devices->findById('tab1');
$peers = $compact->invoke($devices, $tab1)['paper_peers'] ?? [];
$peerIds = array_column($peers, 'id');
$peerNames = array_column($peers, 'name');
if ($peerIds[0] !== 'web' || $peerNames[0] !== 'Office PC' || !in_array('tab2', $peerIds, true)) {
    fwrite(STDERR, 'peers ' . json_encode($peers) . "\n");
    exit(1);
}

$sent = $devices->postPaperMessage($devices->webClient(), 'tab1', 'Put the kettle on');
if (!($sent['ok'] ?? false)) {
    fwrite(STDERR, "web send failed\n");
    exit(1);
}
$mid = (string) $sent['message']['id'];
$kitchen = $compact->invoke($devices, $tab1)['paper_messages'][0] ?? null;
if (($kitchen['from_name'] ?? '') !== 'Office PC' || ($kitchen['mine'] ?? true) || ($kitchen['text'] ?? '') !== 'Put the kettle on') {
    fwrite(STDERR, 'kitchen inbox ' . json_encode($kitchen) . "\n");
    exit(1);
}

$reply = $devices->postPaperMessage($tab1, 'web', 'On it');
if (!($reply['ok'] ?? false)) {
    fwrite(STDERR, "tablet reply failed\n");
    exit(1);
}
$mail = $devices->mailView();
if (($mail['unread'] ?? 0) !== 1) {
    fwrite(STDERR, 'unread ' . json_encode($mail) . "\n");
    exit(1);
}
$incoming = $mail['messages'][0] ?? null;
if (($incoming['from_name'] ?? '') !== 'Kitchen' || empty($incoming['unread'])) {
    fwrite(STDERR, 'incoming ' . json_encode($incoming) . "\n");
    exit(1);
}

$read = $devices->markPaperRead($devices->webClient(), (string) $incoming['id']);
if (!($read['ok'] ?? false)) {
    fwrite(STDERR, "web read failed\n");
    exit(1);
}
$mail2 = $devices->mailView();
if (($mail2['unread'] ?? -1) !== 0) {
    fwrite(STDERR, 'unread after read ' . json_encode($mail2) . "\n");
    exit(1);
}

$sender = $compact->invoke($devices, $tab1);
$replyRow = null;
foreach ($sender['paper_messages'] as $row) {
    if (($row['text'] ?? '') === 'On it') {
        $replyRow = $row;
    }
}
if ($replyRow === null || !str_starts_with((string) ($replyRow['read_label'] ?? ''), 'Read')) {
    fwrite(STDERR, 'receipt ' . json_encode($sender['paper_messages']) . "\n");
    exit(1);
}

if ($devices->revoke('web')) {
    fwrite(STDERR, "web revoke should fail\n");
    exit(1);
}

$html = file_get_contents(__DIR__ . '/../public/index.php');
foreach (['data-panel-id="mail"', 'id="paper-mail-name"', 'id="papermono-web-name"'] as $needle) {
    if (!str_contains((string) $html, $needle)) {
        fwrite(STDERR, "missing ui {$needle}\n");
        exit(1);
    }
}

echo "ok: paper web mail\n";
