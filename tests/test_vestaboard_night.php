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
assert_true(str_contains((string) ($doorOpen['lines'][0] ?? ''), 'DOOR OPEN'), 'door open header: ' . ($doorOpen['lines'][0] ?? ''));
assert_true(str_contains((string) ($doorOpen['lines'][1] ?? ''), 'GARAGE'), 'door open names the contact: ' . ($doorOpen['lines'][1] ?? ''));
assert_true(in_array(YarboVestaboard::COLOR_RED, $doorOpen['codes'][0], true), 'door open header has red pads');
assert_true(in_array(YarboVestaboard::COLOR_RED, $doorOpen['codes'][2], true), 'door open filler row is red');
$winOpen = $vb->nightLayout(true, 'window');
assert_true(str_contains((string) ($winOpen['lines'][0] ?? ''), 'WIN OPEN'), 'window open header');
assert_true(str_contains((string) ($winOpen['lines'][1] ?? ''), 'KITCHEN'), 'window open names the contact');
assert_true(in_array(YarboVestaboard::COLOR_RED, $winOpen['codes'][0], true), 'window open has red pads');
$bothOpen = $vb->nightLayout(true, 'both');
assert_true(str_contains((string) ($bothOpen['lines'][0] ?? ''), 'OPEN'), 'both open header');
assert_true(count($bothOpen['open_names'] ?? []) >= 2, 'both open lists two names');
assert_true(in_array(YarboVestaboard::COLOR_RED, $bothOpen['codes'][0], true), 'both open has red pads');
assert_true($vb->fitNightName('Garage Side Entrance Door', 15) === 'GARAGE SIDE', 'fitNightName keeps whole words');
assert_true(strlen($vb->fitNightName('SUPERCALIFRAGILISTICEXPIALIDOCIOUS', 15)) === 15, 'fitNightName hard-truncates long tokens');
foreach (['DOOR OPEN', 'WIN OPEN', 'DOORS OPEN', 'HOUSE SECURE', '3 OPEN', '+2 MORE'] as $phrase) {
    assert_true(strlen($phrase) <= 13, 'status phrase leaves room for colour pads: ' . $phrase);
}

$inv = json_decode((string) file_get_contents($root . '/data/unifi-inventory.json'), true);
$inv['sensors'][0]['open'] = true;
file_put_contents($root . '/data/unifi-inventory.json', json_encode($inv, JSON_UNESCAPED_SLASHES));
$live = $vb->nightLayout();
assert_true(!empty($live['open']), 'live layout sees open window');
assert_true(str_contains((string) ($live['lines'][0] ?? ''), 'WIN OPEN'), 'live window open header: ' . ($live['lines'][0] ?? ''));
assert_true(str_contains((string) ($live['lines'][1] ?? ''), 'KITCHEN'), 'live window open name: ' . ($live['lines'][1] ?? ''));

$view = $vb->publicView();
assert_true(!empty($view['night_hours_available']), 'publicView night available');
assert_true(($view['night_watch']['count'] ?? 0) >= 2, 'publicView watch count');

$preview = $vb->preview('night-secure');
assert_true(($preview['ok'] ?? false) === true, 'night-secure preview');
assert_true(str_contains((string) ($preview['lines'][2] ?? ''), 'HOUSE SECURE'), 'preview secure line');
$previewOpen = $vb->preview('night-open');
assert_true(($previewOpen['ok'] ?? false) === true, 'night-open preview');
assert_true(!empty($previewOpen['open']), 'night-open preview is open');
assert_true(in_array(YarboVestaboard::COLOR_RED, $previewOpen['codes'][0] ?? [], true), 'night-open preview red');

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
assert_true(str_contains((string) ($layoutAfter['lines'][0] ?? ''), 'DOOR OPEN'), 'open door uses three-row alert');
assert_true(str_contains((string) ($layoutAfter['lines'][1] ?? ''), 'GARAGE'), 'open door names garage');
assert_true(($layoutAfter['open_key'] ?? '') !== '', 'open key set for change tracking');
assert_true(
    hash('sha256', json_encode($layoutAfter['codes'])) !== $secureHash,
    'open layout hash differs from secure'
);

// Three opens → header count + first name + +N MORE.
$inv['doors'][0]['name'] = 'Garage Side Entrance Door';
$inv['sensors'][0]['open'] = true;
$inv['sensors'][] = [
    'id' => 'unifi:sensor:office-window',
    'native_id' => 'usp-office',
    'name' => 'Office Window Sensor',
    'kind' => 'sensor',
    'source' => 'unifi',
    'product' => 'USP Sensor',
    'has_open' => true,
    'open' => true,
];
file_put_contents($root . '/data/home.json', json_encode([
    'contact_kind' => [
        'unifi:sensor:kitchen-window' => 'window',
        'unifi:sensor:office-window' => 'window',
    ],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-inventory.json', json_encode($inv, JSON_UNESCAPED_SLASHES));
$multi = $vb->nightLayout();
assert_true(($multi['open'] ?? false) === true, 'multi open');
assert_true(str_contains((string) ($multi['lines'][0] ?? ''), '3 OPEN'), 'multi header: ' . ($multi['lines'][0] ?? ''));
assert_true(str_contains((string) ($multi['lines'][1] ?? ''), 'GARAGE'), 'multi first name');
assert_true(str_contains((string) ($multi['lines'][2] ?? ''), '+2 MORE'), 'multi remainder: ' . ($multi['lines'][2] ?? ''));

$phpSrc = (string) file_get_contents(dirname(__DIR__) . '/src/YarboVestaboard.php');
assert_true(str_contains($phpSrc, 'refreshNightContacts'), 'tick refreshes UniFi during night hours');
assert_true(str_contains($phpSrc, 'openChanged'), 'tick forces write when open state changes');
assert_true(str_contains($phpSrc, 'night_last_open_key'), 'tick tracks which contacts are open');
assert_true(str_contains($phpSrc, 'nightOpenAlertLayout'), 'open alert uses full board');

$change = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
$docs = (string) file_get_contents(dirname(__DIR__) . '/docs/vestaboard.md');
$html = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
assert_true(str_contains($change, '## [4.0.236]'), 'changelog 4.0.236');
assert_true(str_contains($docs, 'Simulate open'), 'docs Simulate open');
assert_true(str_contains($html, 'settings-vestaboard-night'), 'settings night checkbox');
assert_true(str_contains($html, 'settings-vestaboard-night-simulate'), 'settings simulate button');
assert_true(str_contains($html, 'HOUSE SECURE'), 'settings HOUSE SECURE');
assert_true(str_contains($html, 'cannot guarantee the security'), 'disclaimer in settings');
assert_true(str_contains($js, 'vestaboard_night_hours'), 'JS saves night hours');
assert_true(str_contains($js, 'function applyVestaboardNightHours'), 'JS night hours UI');
assert_true(str_contains($js, 'function toggleNightOpenSimulate'), 'JS simulate open');
assert_true(str_contains($js, 'HOUSE SECURE'), 'JS night preview HOUSE SECURE');

fwrite(STDOUT, "test_vestaboard_night.php ok\n");
