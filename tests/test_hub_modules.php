<?php

declare(strict_types=1);

require __DIR__ . '/../src/YarboHub.php';

$root = sys_get_temp_dir() . '/yarbo-hub-mod-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);

$hub = new Yarbo\YarboHub($root);

if (Yarbo\YarboHub::asBool('false') !== false || Yarbo\YarboHub::asBool('0') !== false || Yarbo\YarboHub::asBool(false) !== false) {
    fwrite(STDERR, "asBool false failed\n");
    exit(1);
}
if (Yarbo\YarboHub::asBool('true') !== true || Yarbo\YarboHub::asBool('on') !== true) {
    fwrite(STDERR, "asBool true failed\n");
    exit(1);
}

$current = [
    'yarbo' => true,
    'powerwall' => true,
    'lymow' => true,
    'home' => true,
];
$fromJson = Yarbo\YarboHub::modulesFromInput([
    'module_yarbo' => true,
    'module_powerwall' => false,
    'module_lymow' => false,
    'module_home' => true,
], $current);
if ($fromJson['powerwall'] !== false || $fromJson['lymow'] !== false || $fromJson['home'] !== true) {
    fwrite(STDERR, 'json flags ' . json_encode($fromJson) . "\n");
    exit(1);
}

$stringFalse = Yarbo\YarboHub::modulesFromInput([
    'module_yarbo' => 'true',
    'module_powerwall' => 'false',
    'module_lymow' => '0',
    'module_home' => 'off',
], $current);
if ($stringFalse['powerwall'] !== false || $stringFalse['lymow'] !== false || $stringFalse['home'] !== false) {
    fwrite(STDERR, 'string false ' . json_encode($stringFalse) . "\n");
    exit(1);
}

$form = Yarbo\YarboHub::modulesFromInput([
    'module_yarbo' => 'on',
], $current);
if ($form['yarbo'] !== true || $form['powerwall'] !== false || $form['lymow'] !== false || $form['home'] !== false) {
    fwrite(STDERR, 'form omit ' . json_encode($form) . "\n");
    exit(1);
}

$liveOnly = Yarbo\YarboHub::modulesFromInput([
    'vestaboard_live' => 'powerwall',
], $current);
if ($liveOnly !== $current) {
    fwrite(STDERR, 'live-only must keep modules ' . json_encode($liveOnly) . "\n");
    exit(1);
}

if (!$hub->save([
    'modules' => [
        'yarbo' => true,
        'powerwall' => true,
        'lymow' => false,
        'home' => true,
    ],
    'vestaboard_live' => 'powerwall',
])) {
    fwrite(STDERR, "save on failed\n");
    exit(1);
}
$on = $hub->load();
if (empty($on['modules']['powerwall']) || $on['vestaboard_live'] !== 'powerwall') {
    fwrite(STDERR, 'saved on ' . json_encode($on) . "\n");
    exit(1);
}

if (!$hub->save([
    'module_yarbo' => true,
    'module_powerwall' => false,
    'module_lymow' => false,
    'module_home' => true,
    'vestaboard_live' => 'powerwall',
])) {
    fwrite(STDERR, "save off failed\n");
    exit(1);
}
$off = $hub->load();
$view = $hub->publicView();
if (!empty($off['modules']['powerwall']) || !empty($off['modules']['lymow'])) {
    fwrite(STDERR, 'powerwall stayed on ' . json_encode($off) . "\n");
    exit(1);
}
if ($off['vestaboard_live'] === 'powerwall') {
    fwrite(STDERR, "live stayed on disabled powerwall\n");
    exit(1);
}
$choiceIds = array_column($view['vestaboard_live_choices'], 'id');
if (in_array('powerwall', $choiceIds, true) || in_array('lymow', $choiceIds, true) || in_array('batteries', $choiceIds, true)) {
    fwrite(STDERR, 'choices still list disabled ' . json_encode($choiceIds) . "\n");
    exit(1);
}
if (!in_array('yarbo', $choiceIds, true)) {
    fwrite(STDERR, "yarbo missing from choices\n");
    exit(1);
}

$enabledIds = array_column($view['enabled'], 'id');
if (in_array('powerwall', $enabledIds, true) || !in_array('home', $enabledIds, true)) {
    fwrite(STDERR, 'enabled ' . json_encode($enabledIds) . "\n");
    exit(1);
}

$menu = Yarbo\YarboHub::menuVisibleForModules(
    ['yarbo' => true, 'powerwall' => true, 'lymow' => true, 'house' => true, 'note' => true],
    $off['modules'],
);
if ($menu['powerwall'] !== false || $menu['lymow'] !== false || $menu['house'] !== true || $menu['note'] !== true) {
    fwrite(STDERR, 'menu overlay ' . json_encode($menu) . "\n");
    exit(1);
}

if (Yarbo\YarboHub::allViewAvailable(['yarbo' => true, 'home' => true, 'powerwall' => false, 'lymow' => false])) {
    fwrite(STDERR, "ALL should need two battery modules\n");
    exit(1);
}
if (!Yarbo\YarboHub::allViewAvailable(['yarbo' => true, 'powerwall' => true, 'lymow' => false, 'home' => false])) {
    fwrite(STDERR, "ALL should show for yarbo+powerwall\n");
    exit(1);
}

echo "ok: hub modules\n";
