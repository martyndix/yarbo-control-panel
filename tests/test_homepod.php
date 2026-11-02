<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHomepod;
use Yarbo\YarboUnifi;
use Yarbo\YarboUnifiRadio;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = dirname(__DIR__);

assert_true(YarboHomepod::isHomepodTargetId('homepod:abc'), 'prefix detected');
assert_true(!YarboHomepod::isHomepodTargetId('cam1'), 'protect id not homepod');
assert_true(YarboHomepod::stripPrefix('homepod:abc') === 'abc', 'strip prefix');
assert_true(YarboHomepod::withPrefix('abc') === 'homepod:abc', 'add prefix');
assert_true(YarboHomepod::withPrefix('homepod:abc') === 'homepod:abc', 'idempotent prefix');

$mixed = ['cam1', 'homepod:living', 'cam2', 'homepod:kitchen', '', 'homepod:living'];
assert_true(
    YarboHomepod::filterHomepodIds($mixed) === ['living', 'kitchen'],
    'filter homepod ids: ' . json_encode(YarboHomepod::filterHomepodIds($mixed))
);
assert_true(
    YarboHomepod::filterProtectIds($mixed) === ['cam1', 'cam2'],
    'filter protect ids: ' . json_encode(YarboHomepod::filterProtectIds($mixed))
);

$tmp = sys_get_temp_dir() . '/yarbo-homepod-' . bin2hex(random_bytes(3));
mkdir($tmp . '/data/homepod', 0775, true);
mkdir($tmp . '/src/defaults', 0775, true);
copy($root . '/src/defaults/unifi-radio-stations.json', $tmp . '/src/defaults/unifi-radio-stations.json');
file_put_contents($tmp . '/data/homepod/devices.json', json_encode([
    'devices' => [
        [
            'id' => 'aa:bb:cc:dd',
            'name' => 'Living HomePod',
            'model' => 'HomePod mini',
            'credentials' => 'secret',
            'enabled' => true,
        ],
        [
            'id' => 'ee:ff:00:11',
            'name' => 'Hidden Pod',
            'credentials' => 'secret',
            'enabled' => false,
        ],
    ],
], JSON_UNESCAPED_SLASHES));

$hp = new YarboHomepod($tmp);
$rows = $hp->speakerRows();
assert_true(count($rows) === 1, 'only enabled HomePods in speaker rows');
assert_true(($rows[0]['id'] ?? '') === 'homepod:aa:bb:cc:dd', 'speaker id prefixed');
assert_true(($rows[0]['kind'] ?? '') === 'homepod', 'speaker kind homepod');
assert_true(($rows[0]['name'] ?? '') === 'Living HomePod', 'speaker name');

$status = $hp->status();
assert_true(($status['ok'] ?? false) === true, 'status ok without agent');
assert_true(count($status['devices'] ?? []) === 2, 'status lists saved devices');
assert_true(($status['devices'][0]['credentials'] ?? true) === true, 'credentials redacted to bool');
assert_true(($status['devices'][0]['target_id'] ?? '') === 'homepod:aa:bb:cc:dd', 'status target_id');

$unifi = new YarboUnifi($tmp);
$radio = new YarboUnifiRadio($tmp, $unifi);
$unifi->setSpeakersEnabled(true);
file_put_contents($tmp . '/data/unifi-inventory.json', json_encode([
    'cameras' => [
        [
            'id' => 'unifi:camera:cam1',
            'native_id' => 'cam1',
            'name' => 'Front Reader',
            'kind' => 'camera',
            'product' => 'UA G3 Reader Pro',
            'has_speaker' => true,
            'available' => true,
        ],
    ],
    'lights' => [],
    'sensors' => [],
    'relays' => [],
    'doors' => [],
    'hubs' => [],
    'errors' => [],
], JSON_UNESCAPED_SLASHES));

$slice = $radio->dashboardSlice();
$speakerIds = array_map(static fn (array $s): string => (string) ($s['id'] ?? $s['native_id'] ?? ''), $slice['speakers'] ?? []);
assert_true(in_array('cam1', $speakerIds, true) || in_array('unifi:camera:cam1', $speakerIds, true), 'protect speaker listed');
assert_true(in_array('homepod:aa:bb:cc:dd', $speakerIds, true), 'homepod in dashboard speakers: ' . json_encode($speakerIds));
assert_true(isset($slice['homepod']), 'dashboardSlice includes homepod');

// HomePod-only announce state stays Playing until ends_at, then clears.
file_put_contents($tmp . '/data/unifi-radio-state.json', json_encode([
    'playing' => true,
    'station_id' => 'announce',
    'station_name' => 'Announcement',
    'volume' => 60,
    'streams' => [],
    'homepod_targets' => ['homepod:aa:bb:cc:dd'],
    'targets' => ['homepod:aa:bb:cc:dd'],
    'started_at' => time() - 10,
    'ends_at' => time() - 1,
    'resume_after' => null,
    'error' => null,
], JSON_UNESCAPED_SLASHES));
$done = $radio->status();
assert_true(($done['playing'] ?? true) === false, 'homepod announce ends_at clears playing');
assert_true(($done['homepod_targets'] ?? ['x']) === [], 'homepod targets cleared after announce');

// HomePod-only radio stays Playing without a local ffmpeg pid.
file_put_contents($tmp . '/data/unifi-radio-state.json', json_encode([
    'playing' => true,
    'station_id' => 'classic-fm',
    'station_name' => 'Classic FM',
    'volume' => 40,
    'streams' => [],
    'homepod_targets' => ['homepod:aa:bb:cc:dd'],
    'targets' => ['homepod:aa:bb:cc:dd'],
    'started_at' => time() - 30,
    'ends_at' => null,
    'resume_after' => null,
    'error' => null,
], JSON_UNESCAPED_SLASHES));
$live = $radio->status();
assert_true(($live['playing'] ?? false) === true, 'homepod-only radio stays playing');
assert_true(($live['active_targets'] ?? []) === ['homepod:aa:bb:cc:dd'], 'active includes homepod');

$index = (string) file_get_contents($root . '/public/index.php');
$js = (string) file_get_contents($root . '/public/assets/app.js');
$api = (string) file_get_contents($root . '/public/api/unifi.php');
$css = (string) file_get_contents($root . '/public/assets/style.css');
$panel = (string) file_get_contents($root . '/scripts/panel.sh');
$agent = (string) file_get_contents($root . '/scripts/homepod_agent.py');
$change = (string) file_get_contents($root . '/CHANGELOG.md');
$docs = (string) file_get_contents($root . '/docs/unifi.md');

assert_true(is_file($root . '/scripts/homepod_agent.py'), 'homepod agent script');
assert_true(is_file($root . '/scripts/lib/homepod_agent.sh'), 'homepod agent helper');
assert_true(is_file($root . '/src/YarboHomepod.php'), 'YarboHomepod class');
assert_true(str_contains($index, 'settings-unifi-homepod-scan'), 'Settings HomePod scan');
assert_true(str_contains($index, 'HomePod speakers'), 'Settings HomePod heading');
assert_true(str_contains($js, 'homepod_scan'), 'JS scan action');
assert_true(str_contains($js, 'homepod_pair_finish'), 'JS pair finish');
assert_true(str_contains($js, '· HomePod'), 'checklist HomePod label');
assert_true(str_contains($api, 'homepod_scan'), 'API homepod_scan');
assert_true(str_contains($api, 'use YarboHomepod') || str_contains($api, 'YarboHomepod::'), 'API uses YarboHomepod');
assert_true(str_contains($css, 'unifi-homepod-list'), 'HomePod settings CSS');
assert_true(str_contains($panel, 'homepod_agent.py'), 'panel.sh starts HomePod agent');
assert_true(str_contains($panel, 'homepod_agent.sh'), 'panel.sh sources helper');
assert_true(str_contains($agent, 'pyatv'), 'agent uses pyatv');
assert_true(str_contains($agent, 'stream_file'), 'agent streams files');
assert_true(str_contains($change, '## [4.0.185]'), 'changelog 4.0.185');
assert_true(str_contains($change, 'HomePod'), 'changelog mentions HomePod');
assert_true(str_contains($docs, 'HomePod'), 'docs mention HomePod');
assert_true(str_contains($docs, 'pyatv'), 'docs mention pyatv');

$radioPhp = (string) file_get_contents($root . '/src/YarboUnifiRadio.php');
assert_true(str_contains($radioPhp, 'filterHomepodIds'), 'radio splits HomePod targets');
assert_true(str_contains($radioPhp, 'homepodBridgeUrl'), 'AirPlay bridge HomePod HTTP');
assert_true(str_contains($radioPhp, 'streamToMany'), 'radio streams to HomePods');

echo "test_homepod.php ok\n";
