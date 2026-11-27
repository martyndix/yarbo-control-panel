<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHome;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$tz = 'Europe/Zurich';
$today = (new DateTimeImmutable('now', new DateTimeZone($tz)))->setTime(14, 32, 0);
$noon = $today->getTimestamp();
assert_true(
    YarboHome::motionChangedText($noon, $tz) === 'Last motion 14:32',
    'motionChangedText same-day'
);
$yesterday = $today->modify('-1 day')->setTime(18, 4, 0);
$yesterdayStamp = $yesterday->getTimestamp();
$priorExpected = 'Last motion ' . $yesterday->format('j M H:i');
assert_true(
    YarboHome::motionChangedText($yesterdayStamp, $tz) === $priorExpected,
    'motionChangedText prior-day'
);
assert_true(YarboHome::motionChangedText(0, $tz) === '', 'motionChangedText empty when at is 0');

$root = sys_get_temp_dir() . '/yarbo-home-motion-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'home' => true, 'unifi' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-config.json', json_encode([
    'host' => '',
    'api_key' => '',
    'show_on_home' => ['unifi:sensor:pir-light1'],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home-automations.json', json_encode([
    'timezone' => $tz,
    'rules' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/home.json', json_encode([
    'names' => [],
    'rooms' => ['28:1' => 'hall'],
    'room_defs' => [['id' => 'hall', 'name' => 'Hall']],
    'hidden' => [],
    'last_devices' => [
        [
            'id' => '28:1',
            'node_id' => 28,
            'endpoint' => 1,
            'name' => 'Hall motion',
            'kind' => 'sensor',
            'source' => 'matter',
            'product' => 'MYGGSPRAY',
            'has_motion' => true,
            'motion' => false,
            'motion_at' => 0,
            'status' => 'OK',
            'on' => false,
            'available' => true,
        ],
        [
            'id' => 'unifi:sensor:pir-light1',
            'native_id' => 'pir-light1',
            'name' => 'Flood motion',
            'kind' => 'sensor',
            'source' => 'unifi',
            'product' => 'Floodlight motion',
            'has_motion' => true,
            'motion' => false,
            'motion_at' => $noon,
            'status' => 'OK',
            'on' => false,
            'available' => true,
        ],
        [
            'id' => '80:1',
            'node_id' => 80,
            'endpoint' => 1,
            'name' => 'Air quality',
            'kind' => 'sensor',
            'source' => 'matter',
            'temperature' => 21.0,
            'humidity' => 40.0,
            'status' => '21.0° · 40% RH',
            'on' => false,
            'available' => true,
        ],
    ],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/unifi-inventory.json', json_encode([
    'ok' => true,
    'updated_at' => time(),
    'lights' => [],
    'cameras' => [],
    'sensors' => [
        [
            'id' => 'unifi:sensor:pir-light1',
            'native_id' => 'pir-light1',
            'name' => 'Flood motion',
            'kind' => 'sensor',
            'source' => 'unifi',
            'product' => 'Floodlight motion',
            'companion_of' => 'unifi:light:light1',
            'available' => true,
            'on' => false,
            'status' => 'OK',
            'open' => null,
            'motion' => false,
            'has_open' => false,
            'has_motion' => true,
            'motion_at' => $noon,
        ],
    ],
    'doors' => [],
    'hubs' => [],
    'relays' => [],
], JSON_UNESCAPED_SLASHES));

$home = new YarboHome($root);
$dash = $home->dashboard();
$byId = [];
foreach (array_merge($dash['devices'] ?? [], $dash['hidden_devices'] ?? []) as $row) {
    if (is_array($row) && isset($row['id'])) {
        $byId[(string) $row['id']] = $row;
    }
}

$pir = $byId['unifi:sensor:pir-light1'] ?? [];
assert_true(
    ($pir['motion_changed_text'] ?? '') === YarboHome::motionChangedText($noon, $tz),
    'UniFi PIR shows Last motion from controller stamp: ' . json_encode($pir)
);
assert_true((int) ($pir['motion_changed_at'] ?? 0) === $noon, 'UniFi PIR motion_changed_at matches stamp');

$matterIdle = $byId['28:1'] ?? [];
assert_true(empty($matterIdle['motion_changed_text'] ?? null), 'Matter idle first sight has no caption yet');
$store = json_decode((string) file_get_contents($root . '/data/home.json'), true);
assert_true(is_array($store['motion_changed']['28:1'] ?? null), 'first sight seeds motion_changed');
assert_true(($store['motion_changed']['28:1']['at'] ?? 1) === 0, 'first sight at is 0 until motion');

// Rising edge on Matter occupancy with no controller stamp.
$devices = json_decode((string) file_get_contents($root . '/data/home.json'), true);
foreach ($devices['last_devices'] as &$row) {
    if (($row['id'] ?? '') === '28:1') {
        $row['motion'] = true;
        $row['on'] = true;
        $row['status'] = 'Motion';
        $row['motion_at'] = 0;
    }
}
unset($row);
file_put_contents($root . '/data/home.json', json_encode($devices, JSON_UNESCAPED_SLASHES));
$afterRise = (new YarboHome($root))->dashboard();
$hall = [];
foreach ($afterRise['devices'] as $row) {
    if (($row['id'] ?? '') === '28:1') {
        $hall = $row;
        break;
    }
}
assert_true(
    is_string($hall['motion_changed_text'] ?? null)
        && str_starts_with((string) $hall['motion_changed_text'], 'Last motion '),
    'Matter rising edge adds Last motion caption'
);
assert_true((int) ($hall['motion_changed_at'] ?? 0) > 0, 'Matter rising edge stores motion_changed_at');
$riseAt = (int) ($hall['motion_changed_at'] ?? 0);

// Clear motion — caption must stay with the same stamp.
$devices = json_decode((string) file_get_contents($root . '/data/home.json'), true);
foreach ($devices['last_devices'] as &$row) {
    if (($row['id'] ?? '') === '28:1') {
        $row['motion'] = false;
        $row['on'] = false;
        $row['status'] = 'OK';
        $row['motion_at'] = 0;
    }
}
unset($row);
file_put_contents($root . '/data/home.json', json_encode($devices, JSON_UNESCAPED_SLASHES));
$afterClear = (new YarboHome($root))->dashboard();
$hallClear = [];
foreach ($afterClear['devices'] as $row) {
    if (($row['id'] ?? '') === '28:1') {
        $hallClear = $row;
        break;
    }
}
assert_true(
    ($hallClear['motion_changed_text'] ?? '') === YarboHome::motionChangedText($riseAt, $tz),
    'idle Matter keeps Last motion after clear'
);
assert_true((int) ($hallClear['motion_changed_at'] ?? 0) === $riseAt, 'idle Matter keeps same stamp');
assert_true((int) ($hallClear['motion_at'] ?? 0) === $riseAt, 'idle Matter back-fills motion_at for automations');

$climate = [];
foreach ($afterClear['devices'] as $row) {
    if (($row['id'] ?? '') === '80:1') {
        $climate = $row;
        break;
    }
}
assert_true(empty($climate['motion_changed_text'] ?? null), 'climate-only sensor has no Last motion');

$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$css = (string) file_get_contents(__DIR__ . '/../public/assets/style.css');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($js, 'function homeMotionChangedHtml'), 'JS builds Last motion microcopy');
assert_true(str_contains($js, 'data-home-motion-changed'), 'JS marks Last motion element');
assert_true(str_contains($js, 'function patchHomeMotionChanged'), 'JS patches Last motion on refresh');
assert_true(str_contains($css, '.home-device-changed'), 'Last motion reuses muted microcopy CSS');
assert_true(str_contains($change, '## [5.0.117]'), 'changelog 5.0.117');
assert_true(str_contains($change, 'Last motion'), 'changelog mentions Last motion');

fwrite(STDOUT, "test_home_motion_changed.php ok\n");
