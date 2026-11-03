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

$prevHomepodEnv = getenv('YARBO_HOMEPOD');
putenv('YARBO_HOMEPOD=0');
assert_true(YarboHomepod::isEnabled() === false, 'YARBO_HOMEPOD=0 disables');
assert_true((new YarboHomepod($tmp))->speakerRows() === [], 'kill-switch hides speakers');
$disabledStatus = (new YarboHomepod($tmp))->status();
assert_true(($disabledStatus['enabled'] ?? true) === false, 'status reports disabled');
assert_true(str_contains((string) ($disabledStatus['message'] ?? ''), 'YARBO_HOMEPOD=0'), 'status mentions kill-switch');
$disabledScan = (new YarboHomepod($tmp))->scan();
assert_true(($disabledScan['ok'] ?? true) === false, 'scan blocked when disabled');
if ($prevHomepodEnv === false) {
    putenv('YARBO_HOMEPOD');
} else {
    putenv('YARBO_HOMEPOD=' . $prevHomepodEnv);
}
assert_true(YarboHomepod::isEnabled() === true, 'HomePods re-enabled after env restore');

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

// Missing ends_at must not leave a HomePod announcement Playing forever.
file_put_contents($tmp . '/data/unifi-radio-state.json', json_encode([
    'playing' => true,
    'station_id' => 'announce',
    'station_name' => 'Announcement',
    'volume' => 60,
    'streams' => [],
    'homepod_targets' => ['homepod:aa:bb:cc:dd'],
    'targets' => ['homepod:aa:bb:cc:dd'],
    'started_at' => time() - 120,
    'ends_at' => null,
    'resume_after' => null,
    'error' => null,
], JSON_UNESCAPED_SLASHES));
$stuck = $radio->status();
assert_true(($stuck['playing'] ?? true) === false, 'homepod announce without ends_at clears after started_at bound');

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
assert_true(is_file($root . '/scripts/lib/homepod_deps.sh'), 'homepod deps helper');
assert_true(is_file($root . '/scripts/panel_homepod_ensure.sh'), 'homepod ensure script');
assert_true(is_file($root . '/src/YarboHomepod.php'), 'YarboHomepod class');
$deps = (string) file_get_contents($root . '/scripts/lib/homepod_deps.sh');
$ensure = (string) file_get_contents($root . '/scripts/panel_homepod_ensure.sh');
$update = (string) file_get_contents($root . '/scripts/update.sh');
assert_true(str_contains($deps, 'yarbo_ensure_pyatv'), 'deps defines yarbo_ensure_pyatv');
assert_true(str_contains($ensure, 'yarbo_ensure_pyatv'), 'ensure script installs pyatv');
assert_true(str_contains($update, 'yarbo_ensure_pyatv'), 'update.sh installs pyatv via helper');
assert_true(str_contains($api, 'homepod_ensure') || str_contains((string) file_get_contents($root . '/src/YarboHomepod.php'), 'ensureDeps'), 'ensure path available');
assert_true(str_contains((string) file_get_contents($root . '/src/YarboHomepod.php'), 'needsPyatvInstall'), 'scan auto-installs pyatv');
assert_true(str_contains($change, '## [4.0.186]'), 'changelog 4.0.186');
assert_true(str_contains($change, '## [4.0.188]'), 'changelog 4.0.188');
assert_true(str_contains($change, '## [4.0.189]'), 'changelog 4.0.189');
assert_true(str_contains($change, '## [4.0.190]'), 'changelog 4.0.190');
assert_true(str_contains($change, '## [4.0.191]'), 'changelog 4.0.191');
assert_true(str_contains($change, '## [4.0.192]'), 'changelog 4.0.192');
assert_true(str_contains($change, '## [4.0.193]'), 'changelog 4.0.193');
assert_true(str_contains($change, '## [4.0.194]'), 'changelog 4.0.194');
assert_true(str_contains($change, '## [4.0.195]'), 'changelog 4.0.195');
assert_true(str_contains($change, '## [4.0.196]'), 'changelog 4.0.196');
assert_true(str_contains($change, '## [4.0.197]'), 'changelog 4.0.197');
assert_true(str_contains($deps, 'pyatv>=0.15.1') || str_contains($deps, '0.15.1'), 'deps pin pyatv >= 0.15.1');
assert_true(str_contains($deps, 'srptools') && str_contains($deps, '<2'), 'deps pin srptools < 2');
assert_true(str_contains($deps, 'yarbo_srptools_ok'), 'deps checks srptools compatibility');
assert_true(str_contains($agent, '_prepare_playable') || str_contains($agent, 'transcode'), 'agent prepares announce audio');
assert_true(str_contains($agent, '_ffmpeg_pipe') || str_contains($agent, 'pipe:1'), 'agent pipes ffmpeg into stream_file');
assert_true(str_contains($agent, 'user_agent') || str_contains($agent, 'User-Agent'), 'agent sets ffmpeg User-Agent for ice streams');
assert_true(str_contains($change, '401') || str_contains($change, 'Classic FM'), 'changelog mentions ice 401 fix');
assert_true(str_contains($change, 'pipe') || str_contains($change, 'stdout'), 'changelog mentions ffmpeg pipe');
assert_true(str_contains($js, 'nqptp'), 'UI mentions nqptp when missing');
assert_true(str_contains($agent, 'MrpTunnel') || str_contains($agent, 'mrp_tunnel'), 'agent disables AirPlay MRP tunnel');
assert_true(str_contains($agent, 'remote control') || str_contains($agent, '_disable_non_stream'), 'agent skips remote-control protocols');
assert_true(str_contains($index, 'settings-unifi-homepod-scan'), 'Settings HomePod scan');
assert_true(str_contains($index, 'HomePod speakers'), 'Settings HomePod heading');
assert_true(str_contains($index, 'settings-unifi-homepod-pair-skip'), 'No PIN add button');
assert_true(str_contains($index, 'Anyone on the Same Network'), 'Settings hint open AirPlay access');
assert_true(str_contains($js, 'homepod_scan'), 'JS scan action');
assert_true(str_contains($js, 'homepod_pair_finish'), 'JS pair finish');
assert_true(str_contains($js, 'homepod_add') || str_contains($js, 'pin_required'), 'JS handles no-PIN pair');
assert_true(str_contains($js, '· HomePod'), 'checklist HomePod label');
assert_true(str_contains($api, 'homepod_scan'), 'API homepod_scan');
assert_true(str_contains($api, 'homepod_add') || str_contains($api, 'addWithoutPin'), 'API add without PIN');
assert_true(str_contains($api, 'use YarboHomepod') || str_contains($api, 'YarboHomepod::'), 'API uses YarboHomepod');
assert_true(str_contains($css, 'unifi-homepod-list'), 'HomePod settings CSS');
assert_true(str_contains($panel, 'homepod_agent.py'), 'panel.sh starts HomePod agent');
assert_true(str_contains($panel, 'homepod_agent.sh'), 'panel.sh sources helper');
assert_true(str_contains($panel, 'YARBO_HOMEPOD'), 'panel.sh respects HomePod kill-switch');
assert_true(str_contains($docs, 'YARBO_HOMEPOD=0'), 'docs document HomePod soft rollback');
assert_true(str_contains($docs, '4.0.184') || str_contains($change, '4.0.184'), 'docs/changelog mention full revert target');
assert_true(str_contains($agent, 'pyatv'), 'agent uses pyatv');
assert_true(str_contains($agent, 'stream_file'), 'agent streams files');
assert_true(str_contains($agent, 'add_without_pin') || str_contains($agent, 'NotNeeded'), 'agent skips PIN when NotNeeded');
assert_true(str_contains($change, '## [4.0.185]'), 'changelog 4.0.185');
assert_true(str_contains($change, 'HomePod'), 'changelog mentions HomePod');
assert_true(str_contains($docs, 'HomePod'), 'docs mention HomePod');
assert_true(str_contains($docs, 'pyatv'), 'docs mention pyatv');
assert_true(str_contains($docs, 'does not speak') || str_contains($docs, 'No PIN'), 'docs explain silent pairing');

$radioPhp = (string) file_get_contents($root . '/src/YarboUnifiRadio.php');
assert_true(str_contains($radioPhp, 'filterHomepodIds'), 'radio splits HomePod targets');
assert_true(str_contains($radioPhp, 'homepodBridgeUrl'), 'AirPlay bridge HomePod URL helper');
assert_true(str_contains($radioPhp, 'udp://127.0.0.1:'), 'AirPlay bridge uses loopback UDP for HomePods');
assert_true(str_contains($radioPhp, 'mpegts'), 'AirPlay bridge encodes MPEG-TS for HomePods');
assert_true(str_contains($radioPhp, 'exec 3<>'), 'AirPlay fanout holds FIFO open RDWR');
assert_true(!str_contains($radioPhp, '-listen 1'), 'AirPlay bridge no longer uses ffmpeg HTTP -listen');
assert_true(str_contains($agent, '_is_local_loopback') || str_contains($agent, 'udp://'), 'agent accepts loopback UDP bridge');
assert_true(str_contains($radioPhp, 'streamToMany'), 'radio streams to HomePods');

echo "test_homepod.php ok\n";
