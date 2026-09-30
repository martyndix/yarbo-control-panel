<?php

declare(strict_types=1);

require __DIR__ . '/../src/YarboPaperDevice.php';
require __DIR__ . '/../src/YarboVestaboard.php';

$root = sys_get_temp_dir() . '/yarbo-menu-labels-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/papermono-devices.json', json_encode([
    'devices' => [
        ['id' => 'tab1', 'name' => 'Kitchen', 'kind' => 'papermono', 'token' => 'tok1'],
    ],
    'prefs' => [],
    'messages' => [],
], JSON_UNESCAPED_SLASHES));

$devices = new Yarbo\YarboPaperDevice($root);
$saved = $devices->savePrefs([
    'menu_labels' => [
        'status' => '  Robot  ',
        'house' => 'Lights and scenes name that is way too long',
        'radio' => '',
        'unknown' => 'nope',
    ],
]);
$labels = $saved['prefs']['menu_labels'] ?? [];
$defaults = Yarbo\YarboPaperDevice::defaultMenuLabels();
if (($labels['yarbo'] ?? '') !== 'Robot') {
    fwrite(STDERR, 'yarbo migrate ' . json_encode($labels) . "\n");
    exit(1);
}
if (!str_starts_with((string) ($labels['house'] ?? ''), 'Lights and scenes')) {
    fwrite(STDERR, 'house clip ' . json_encode($labels['house'] ?? null) . "\n");
    exit(1);
}
if (strlen((string) $labels['house']) > Yarbo\YarboPaperDevice::MENU_LABEL_MAX) {
    fwrite(STDERR, 'house too long ' . $labels['house'] . "\n");
    exit(1);
}
if (($labels['radio'] ?? '') !== $defaults['radio']) {
    fwrite(STDERR, 'radio default ' . json_encode($labels['radio'] ?? null) . "\n");
    exit(1);
}
if (array_key_exists('unknown', $labels) || array_key_exists('home', $labels) || array_key_exists('board', $labels)) {
    fwrite(STDERR, "unexpected keys " . json_encode($labels) . "\n");
    exit(1);
}

$hidden = $devices->savePrefs([
    'menu_visible' => [
        'health' => false,
        'status' => false,
        'plans' => false,
        'note' => '0',
    ],
]);
$vis = $hidden['prefs']['menu_visible'] ?? [];
if (($vis['yarbo'] ?? true) !== false || ($vis['note'] ?? true) !== false) {
    fwrite(STDERR, 'visible ' . json_encode($vis) . "\n");
    exit(1);
}
if (array_key_exists('board', $vis) || array_key_exists('health', $vis) || array_key_exists('status', $vis)) {
    fwrite(STDERR, "legacy keys still visible pref\n");
    exit(1);
}
foreach ($defaults as $id => $fallback) {
    if (!isset($labels[$id]) || $labels[$id] === '') {
        fwrite(STDERR, "missing {$id}\n");
        exit(1);
    }
}

$ref = new ReflectionClass($devices);
$compact = $ref->getMethod('prefsCompact');
$compact->setAccessible(true);
$tab1 = $devices->findById('tab1');
$extra = $compact->invoke($devices, $tab1);
if (($extra['menu_labels']['yarbo'] ?? '') !== 'Robot') {
    fwrite(STDERR, 'compact labels ' . json_encode($extra['menu_labels'] ?? null) . "\n");
    exit(1);
}
if (($extra['menu_visible']['yarbo'] ?? true) !== false) {
    fwrite(STDERR, 'compact vis ' . json_encode($extra['menu_visible'] ?? null) . "\n");
    exit(1);
}

$ordered = $devices->savePrefs([
    'menu_order' => ['house', 'radio', 'status', 'house', 'unknown'],
]);
$order = $ordered['prefs']['menu_order'] ?? [];
$expect = ['house', 'radio', 'yarbo', 'note', 'powerwall', 'lymow', 'device'];
if ($order !== $expect) {
    fwrite(STDERR, 'order ' . json_encode($order) . "\n");
    exit(1);
}
$compactOrder = $compact->invoke($devices, $tab1)['menu_order'] ?? [];
if ($compactOrder !== $expect) {
    fwrite(STDERR, 'compact order ' . json_encode($compactOrder) . "\n");
    exit(1);
}

$html = file_get_contents(__DIR__ . '/../public/index.php');
if ($html === false || str_contains($html, 'id="papermono-unlock-page"') || str_contains($html, 'Unlock to')) {
    fwrite(STDERR, "unlock-to setting still in settings UI\n");
    exit(1);
}
if (str_contains($html, 'data-menu-label="board"') || str_contains($html, 'data-menu-visible="board"')) {
    fwrite(STDERR, "board still on menu settings\n");
    exit(1);
}
if (str_contains($html, 'data-menu-label="status"') || str_contains($html, 'paper-menu-group-title')) {
    fwrite(STDERR, "legacy status/group menu still in settings UI\n");
    exit(1);
}
if (!str_contains($html, 'id="paper-menu-list"') || !str_contains($html, 'data-menu-move')) {
    fwrite(STDERR, "menu reorder UI missing\n");
    exit(1);
}

echo "ok: paper menu labels\n";
