<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboVestaboard;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-vb-night-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true, 'unifi' => true],
    'vestaboard_live' => 'yarbo',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'transport' => 'local',
    'api_key' => 'test-key',
    'quiet_timezone' => 'Europe/Zurich',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-config.json', json_encode([
    'host' => '',
    'show_on_home' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'contact_kind' => ['unifi:sensor:kitchen-window' => 'window'],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-inventory.json', json_encode([
    'cameras' => [],
    'lights' => [],
    'relays' => [],
    'doors' => [[
        'id' => 'unifi:door:garage',
        'native_id' => 'garage',
        'name' => 'Garage',
        'kind' => 'door',
        'source' => 'unifi',
        'has_dps' => true,
        'dps' => 'close',
        'dps_label' => 'Closed',
        'open' => false,
        'locked' => true,
    ]],
    'hubs' => [],
    'sensors' => [[
        'id' => 'unifi:sensor:kitchen-window',
        'native_id' => 'usp-kitchen',
        'name' => 'Kitchen window',
        'kind' => 'sensor',
        'source' => 'unifi',
        'product' => 'USP Sensor',
        'has_open' => true,
        'open' => false,
    ], [
        'id' => 'unifi:sensor:dps-garage',
        'native_id' => 'dps-garage',
        'door_id' => 'garage',
        'name' => 'Garage position',
        'kind' => 'sensor',
        'source' => 'unifi',
        'product' => 'Door position sensor',
        'has_dps' => true,
        'has_open' => true,
        'open' => false,
    ]],
    'errors' => [],
], JSON_UNESCAPED_SLASHES));

$vb = new YarboVestaboard($root);
assert_true($vb->nightHoursAvailable() === true, 'night available with UniFi door + window');
$summary = $vb->nightWatchSummary();
assert_true(($summary['count'] ?? 0) === 2, 'door + window watched; covered DPS skipped: ' . json_encode($summary));
assert_true(($summary['open'] ?? 1) === 0, 'all closed');

assert_true($vb->save([
    'night_hours_enabled' => true,
    'quiet_hours_enabled' => true,
    'night_start' => '22:00',
    'night_end' => '07:00',
]) === true, 'save night+quiet');
$cfg = $vb->load();
assert_true(!empty($cfg['night_hours_enabled']), 'night stays on when both requested');
assert_true(empty($cfg['quiet_hours_enabled']), 'quiet cleared when night wins');

assert_true($vb->save([
    'quiet_hours_enabled' => true,
    'night_hours_enabled' => true,
]) === true, 'save quiet+night again');
$cfg = $vb->load();
assert_true(!empty($cfg['quiet_hours_enabled']) || !empty($cfg['night_hours_enabled']), 'one overnight mode remains');
assert_true(empty($cfg['quiet_hours_enabled']) || empty($cfg['night_hours_enabled']), 'quiet and night never both on');

$vb->save(['night_hours_enabled' => true, 'quiet_hours_enabled' => false, 'night_start' => '00:00', 'night_end' => '23:59']);
assert_true($vb->isNightHours('12:00') === true, 'night active in window');
$secure = $vb->nightLayout(false);
assert_true(str_contains((string) ($secure['lines'][2] ?? ''), 'HOUSE SECURE'), 'secure bottom line: ' . ($secure['lines'][2] ?? ''));
assert_true(in_array(YarboVestaboard::COLOR_GREEN, $secure['codes'][2], true), 'secure has green pads');
assert_true(($secure['verb'] ?? '') === 'NIGHT', 'secure verb');
assert_true(empty($secure['open']), 'secure open flag');

$doorOpen = $vb->nightLayout(true, 'door');
assert_true(str_contains((string) ($doorOpen['lines'][2] ?? ''), 'DOOR OPEN'), 'door open phrase: ' . ($doorOpen['lines'][2] ?? ''));
assert_true(in_array(YarboVestaboard::COLOR_RED, $doorOpen['codes'][2], true), 'door open has red pads');
$winOpen = $vb->nightLayout(true, 'window');
assert_true(str_contains((string) ($winOpen['lines'][2] ?? ''), 'WIN OPEN'), 'window open phrase');
assert_true(in_array(YarboVestaboard::COLOR_RED, $winOpen['codes'][2], true), 'window open has red pads');
$bothOpen = $vb->nightLayout(true, 'both');
assert_true(str_contains((string) ($bothOpen['lines'][2] ?? ''), 'DOORS OPEN'), 'both open phrase');
assert_true(in_array(YarboVestaboard::COLOR_RED, $bothOpen['codes'][2], true), 'both open has red pads');
foreach (['DOOR OPEN', 'WIN OPEN', 'DOORS OPEN', 'HOUSE SECURE'] as $phrase) {
    assert_true(strlen($phrase) <= 13, 'status phrase leaves room for colour pads: ' . $phrase);
}

$inv = json_decode((string) file_get_contents($root . '/data/unifi-inventory.json'), true);
$inv['sensors'][0]['open'] = true;
file_put_contents($root . '/data/unifi-inventory.json', json_encode($inv, JSON_UNESCAPED_SLASHES));
$live = $vb->nightLayout();
assert_true(!empty($live['open']), 'live layout sees open window');
assert_true(str_contains((string) ($live['lines'][2] ?? ''), 'WIN OPEN'), 'live window open: ' . ($live['lines'][2] ?? ''));

$view = $vb->publicView();
assert_true(!empty($view['night_hours_available']), 'publicView night available');
assert_true(($view['night_watch']['count'] ?? 0) >= 2, 'publicView watch count');

$preview = $vb->preview('night-secure');
assert_true(($preview['ok'] ?? false) === true, 'night-secure preview');
assert_true(str_contains((string) ($preview['lines'][2] ?? ''), 'HOUSE SECURE'), 'preview secure line');

// UniFi off → night unavailable / cannot enable.
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'unifi' => false],
    'vestaboard_live' => 'yarbo',
], JSON_UNESCAPED_SLASHES));
$off = new YarboVestaboard($root);
assert_true($off->nightHoursAvailable() === false, 'night unavailable when UniFi off');
$off->save(['night_hours_enabled' => true]);
assert_true(empty($off->load()['night_hours_enabled']), 'cannot enable night without UniFi contacts');

// While night is active, an open door must flip the bottom row (tick also re-reads UniFi).
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true, 'unifi' => true],
    'vestaboard_live' => 'yarbo',
], JSON_UNESCAPED_SLASHES));
$secureHash = hash('sha256', json_encode($vb->nightLayout(false)['codes']));
$inv = json_decode((string) file_get_contents($root . '/data/unifi-inventory.json'), true);
// Close the window opened earlier so this edge is door-only.
foreach ($inv['sensors'] as &$sensorRow) {
    if (($sensorRow['id'] ?? '') === 'unifi:sensor:kitchen-window') {
        $sensorRow['open'] = false;
    }
}
unset($sensorRow);
foreach ($inv['doors'] as &$doorRow) {
    if (($doorRow['id'] ?? '') === 'unifi:door:garage') {
        $doorRow['open'] = true;
        $doorRow['dps'] = 'open';
        $doorRow['dps_label'] = 'Open';
    }
}
unset($doorRow);
file_put_contents($root . '/data/unifi-inventory.json', json_encode($inv, JSON_UNESCAPED_SLASHES));
$layoutAfter = $vb->nightLayout();
assert_true(!empty($layoutAfter['open']), 'inventory open door is seen by nightLayout');
assert_true(($layoutAfter['open_kind'] ?? '') === 'door', 'open kind is door: ' . ($layoutAfter['open_kind'] ?? ''));
assert_true(str_contains((string) ($layoutAfter['lines'][2] ?? ''), 'DOOR OPEN'), 'open door flips bottom row');
assert_true(
    hash('sha256', json_encode($layoutAfter['codes'])) !== $secureHash,
    'open layout hash differs from secure'
);
$phpSrc = (string) file_get_contents(dirname(__DIR__) . '/src/YarboVestaboard.php');
assert_true(str_contains($phpSrc, 'refreshNightContacts'), 'tick refreshes UniFi during night hours');
assert_true(str_contains($phpSrc, 'openChanged'), 'tick forces write when open state changes');
assert_true(str_contains($phpSrc, 'night_last_kind'), 'tick tracks open kind across ticks');

$change = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
$docs = (string) file_get_contents(dirname(__DIR__) . '/docs/vestaboard.md');
$html = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
assert_true(str_contains($change, '## [4.0.233]'), 'changelog 4.0.233');
assert_true(str_contains($change, '## [4.0.234]'), 'changelog 4.0.234');
assert_true(str_contains($change, '## [4.0.235]'), 'changelog 4.0.235');
assert_true(str_contains($docs, 'HOUSE SECURE'), 'docs HOUSE SECURE');
assert_true(str_contains($html, 'settings-vestaboard-night'), 'settings night checkbox');
assert_true(str_contains($html, 'HOUSE SECURE'), 'settings HOUSE SECURE');
assert_true(str_contains($html, 'cannot guarantee the security'), 'disclaimer in settings');
assert_true(str_contains($js, 'vestaboard_night_hours'), 'JS saves night hours');
assert_true(str_contains($js, 'function applyVestaboardNightHours'), 'JS night hours UI');
assert_true(str_contains($js, 'HOUSE SECURE'), 'JS night preview HOUSE SECURE');

fwrite(STDOUT, "test_vestaboard_night.php ok\n");
