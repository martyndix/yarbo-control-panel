<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboPanelAirPlay;
use Yarbo\YarboUnifi;
use Yarbo\YarboUnifiRadio;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-unifi-airplay-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
mkdir($root . '/scripts', 0775, true);
mkdir($root . '/scripts/lib', 0775, true);

// Copy AirPlay scripts into the temp tree so ensure/stop can run.
foreach (['scripts/lib/panel_airplay.sh', 'scripts/panel_airplay_ensure.sh', 'scripts/panel_airplay_stop.sh'] as $rel) {
    $src = dirname(__DIR__) . '/' . $rel;
    $dst = $root . '/' . $rel;
    assert_true(is_file($src), 'source script exists: ' . $rel);
    copy($src, $dst);
    chmod($dst, 0755);
}

$airplay = new YarboPanelAirPlay($root);
$status = $airplay->status();
assert_true(($status['ok'] ?? false) === true, 'airplay status ok');
assert_true(($status['name'] ?? '') === 'Yarbo Speakers', 'default AirPlay name');
assert_true(($status['running'] ?? true) === false, 'receiver not running by default');

$cfg = $airplay->saveConfig(['name' => 'Patio Readers']);
assert_true(($cfg['name'] ?? '') === 'Patio Readers', 'saves AirPlay name');
assert_true(is_file($root . '/data/airplay/config.json'), 'writes airplay config.json');
assert_true(($airplay->status()['name'] ?? '') === 'Patio Readers', 'status reflects saved name');

$ensured = $airplay->ensure();
assert_true(array_key_exists('installed', $ensured), 'ensure returns installed flag');
assert_true(($ensured['running'] ?? true) === false || ($ensured['installed'] ?? false) === true, 'ensure coherent');
// Without shairport-sync in CI/dev, ensure should fail softly.
if (!($ensured['installed'] ?? false)) {
    assert_true(($ensured['ok'] ?? true) === false, 'ensure fails when shairport missing');
    assert_true(str_contains((string) ($ensured['error'] ?? ''), 'shairport'), 'ensure error mentions shairport');
}

$stopped = $airplay->stopReceiver();
assert_true(($stopped['ok'] ?? false) === true, 'stopReceiver ok');
assert_true(($stopped['running'] ?? true) === false, 'stopReceiver not running');

$unifi = new YarboUnifi($root);
$radio = new YarboUnifiRadio($root, $unifi);
$slice = $radio->dashboardSlice();
assert_true(isset($slice['airplay']), 'dashboardSlice includes airplay');
assert_true(($slice['airplay']['name'] ?? '') === 'Patio Readers', 'dashboardSlice airplay name');

$view = $unifi->publicView();
assert_true(isset($view['airplay']), 'publicView includes airplay');

// Conflict: Speakers off rejects playAirplay.
$unifi->setSpeakersEnabled(false);
$denied = $radio->playAirplay(['targets' => ['cam1'], 'volume' => 40]);
assert_true(($denied['ok'] ?? true) === false, 'playAirplay blocked when speakers off');
assert_true(str_contains(strtolower((string) ($denied['error'] ?? '')), 'speakers'), 'error mentions speakers');
$unifi->setSpeakersEnabled(true);

// Missing targets.
$noTargets = $radio->playAirplay(['targets' => [], 'volume' => 40, 'ensure_receiver' => false]);
assert_true(($noTargets['ok'] ?? true) === false, 'playAirplay needs speakers');

// Source / API / UI wiring.
$php = (string) file_get_contents(dirname(__DIR__) . '/src/YarboUnifiRadio.php');
$panelPhp = (string) file_get_contents(dirname(__DIR__) . '/src/YarboPanelAirPlay.php');
$api = (string) file_get_contents(dirname(__DIR__) . '/public/api/unifi.php');
$index = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
$change = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
$docs = (string) file_get_contents(dirname(__DIR__) . '/docs/unifi.md');
$ensureSh = (string) file_get_contents(dirname(__DIR__) . '/scripts/panel_airplay_ensure.sh');
$libSh = (string) file_get_contents(dirname(__DIR__) . '/scripts/lib/panel_airplay.sh');

assert_true(str_contains($panelPhp, 'class YarboPanelAirPlay'), 'YarboPanelAirPlay class');
assert_true(str_contains($php, 'function playAirplay'), 'playAirplay method');
assert_true(str_contains($php, 'spawnAirplayFanout'), 'AirPlay fan-out ffmpeg');
assert_true(str_contains($php, 'stopAirplay'), 'stopAirplay method');
assert_true(str_contains($php, "'airplay'"), 'airplay station id');
assert_true(str_contains($api, 'airplay_start'), 'API airplay_start');
assert_true(str_contains($api, 'airplay_stop'), 'API airplay_stop');
assert_true(str_contains($api, 'airplay_config'), 'API airplay_config');
assert_true(str_contains($api, 'stopAirplay(true)'), 'speakers off stops AirPlay');
assert_true(str_contains($index, 'id="unifi-airplay-start"'), 'AirPlay Start button');
assert_true(str_contains($index, 'id="unifi-airplay-stop"'), 'AirPlay Stop button');
assert_true(str_contains($index, 'id="settings-unifi-airplay-name"'), 'Settings AirPlay name');
assert_true(str_contains($js, "action: 'airplay_start'"), 'JS airplay_start');
assert_true(str_contains($js, 'renderUnifiAirplayStatus'), 'JS AirPlay status renderer');
assert_true(str_contains($ensureSh, 'shairport-sync'), 'ensure script mentions shairport');
assert_true(str_contains($ensureSh, 'yarbo_try_install_airplay_deps'), 'ensure tries auto apt install');
assert_true(str_contains($libSh, 'audio.fifo'), 'helper creates FIFO');
assert_true(str_contains($libSh, 'yarbo_install_airplay_deps'), 'apt install helper');
assert_true(str_contains($libSh, 'shairport-sync avahi-daemon'), 'installs both packages');
$updateSh = (string) file_get_contents(dirname(__DIR__) . '/scripts/update.sh');
$matterSh = (string) file_get_contents(dirname(__DIR__) . '/scripts/lib/matter_server.sh');
$installSh = (string) file_get_contents(dirname(__DIR__) . '/scripts/install.sh');
assert_true(str_contains($updateSh, 'airplay-deps'), 'update.sh runs airplay-deps');
assert_true(str_contains($matterSh, 'airplay-deps'), 'matter-setup handles airplay-deps');
assert_true(str_contains($installSh, 'shairport-sync avahi-daemon'), 'install.sh --deps includes AirPlay packages');
assert_true(str_contains($installSh, 'apt-get install -y shairport-sync avahi-daemon'), 'sudoers allows AirPlay apt');
assert_true(str_contains($change, '## [4.0.179]'), 'changelog 4.0.179');
assert_true(str_contains($change, '## [4.0.180]'), 'changelog 4.0.180');
assert_true(str_contains($change, '## [4.0.181]'), 'changelog 4.0.181');
assert_true(str_contains($change, '## [4.0.182]'), 'changelog 4.0.182');
assert_true(str_contains($js, 'Install & start AirPlay'), 'JS keeps Start enabled for install retry');
assert_true(str_contains($js, 'Available as'), 'JS waiting status when armed');
assert_true(str_contains($js, 'Playing from'), 'JS playing-from client status');
assert_true(str_contains($updateSh, '/usr/bin/apt-get install -y shairport-sync avahi-daemon'), 'update.sh sudoers apt fallback');
assert_true(str_contains($libSh, '/usr/bin/apt-get install -y shairport-sync avahi-daemon'), 'panel_airplay sudoers apt fallback');
assert_true(str_contains($libSh, 'run_this_before_entering_active_state'), 'shairport session active hook');
assert_true(str_contains($libSh, 'run_this_after_exiting_active_state'), 'shairport session inactive hook');
assert_true(str_contains($php, 'function armAirplay'), 'armAirplay method');
assert_true(str_contains($php, 'function onAirplayClientActive'), 'client active handler');
assert_true(str_contains($php, 'function onAirplayClientInactive'), 'client inactive handler');
assert_true(str_contains($api, 'armAirplay'), 'API airplay_start arms receiver');
$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/style.css');
assert_true(str_contains($css, '#unifi-airplay-status'), 'CSS clears AirPlay status overlap');
assert_true(is_file(dirname(__DIR__) . '/scripts/panel_airplay_hook.sh'), 'session hook script');
assert_true(is_file(dirname(__DIR__) . '/scripts/panel_airplay_bridge.php'), 'bridge CLI script');
assert_true(is_file(dirname(__DIR__) . '/scripts/panel_airplay_metadata.php'), 'metadata reader script');
assert_true(str_contains($change, 'AirPlay'), 'changelog mentions AirPlay');
assert_true(str_contains($docs, 'AirPlay bridge'), 'docs AirPlay bridge');
assert_true(str_contains($docs, 'shairport-sync'), 'docs install hint');
assert_true(str_contains($docs, 'no SSH'), 'docs say no SSH required for apt');
assert_true(str_contains($docs, 'Announcement'), 'docs mention announcement interrupt/resume');

// armAirplay without shairport stays soft-fail; with mocked bridge prefs.
$airplay->saveBridge(['cam1'], 55, true);
$bridge = $airplay->bridge();
assert_true($bridge['armed'] === true, 'bridge armed');
assert_true($bridge['targets'] === ['cam1'], 'bridge targets');
assert_true((int) $bridge['volume'] === 55, 'bridge volume');
$airplay->setClientName("Martyn's iPhone");
$airplay->markSessionActive(true);
$session = $airplay->session();
assert_true($session['active'] === true, 'session active');
assert_true(($session['client_name'] ?? '') === "Martyn's iPhone", 'client name stored');
$statusArmed = $airplay->status();
assert_true(($statusArmed['client_name'] ?? '') === "Martyn's iPhone", 'status exposes client_name');
assert_true(($statusArmed['session_active'] ?? false) === true, 'status exposes session_active');
$airplay->markSessionActive(false);
$airplay->clearBridge();
assert_true($airplay->bridge()['armed'] === false, 'bridge cleared');

// Announcement resume keeps airplay snapshot.
file_put_contents($root . '/data/unifi-radio-state.json', json_encode([
    'playing' => true,
    'station_id' => 'airplay',
    'station_name' => 'Patio Readers',
    'station_url' => $root . '/data/airplay/audio.fifo',
    'volume' => 42,
    'streams' => [['camera_id' => 'cam1', 'pid' => 999999994]],
    'targets' => ['cam1'],
    'started_at' => time() - 5,
    'ends_at' => null,
    'resume_after' => null,
    'error' => null,
], JSON_UNESCAPED_SLASHES));
$capture = (new ReflectionClass(YarboUnifiRadio::class))->getMethod('captureResumeAfter');
$capture->setAccessible(true);
$snap = $capture->invoke($radio);
assert_true(is_array($snap), 'captureResumeAfter for airplay');
assert_true(($snap['station_id'] ?? '') === 'airplay', 'resume snapshot is airplay');
assert_true((int) ($snap['volume'] ?? 0) === 42, 'resume snapshot volume');

echo "test_unifi_airplay.php ok\n";
