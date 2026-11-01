<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboUnifi;
use Yarbo\YarboUnifiRadio;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-unifi-radio-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
mkdir($root . '/src/defaults', 0775, true);
copy(
    dirname(__DIR__) . '/src/defaults/unifi-radio-stations.json',
    $root . '/src/defaults/unifi-radio-stations.json'
);

$unifi = new YarboUnifi($root);
$radio = new YarboUnifiRadio($root, $unifi);

assert_true($unifi->speakersEnabled() === true, 'speakers enabled by default');
$stations = $radio->stations();
assert_true(count($stations) >= 10, 'seeds UK stations: ' . count($stations));
assert_true(is_file($root . '/data/unifi-radio-stations.json'), 'writes stations file on first read');
$names = array_column($stations, 'name');
assert_true(in_array('Classic FM', $names, true), 'includes Classic FM');
assert_true(in_array('BBC Radio 2', $names, true), 'includes BBC Radio 2');
assert_true(in_array('talkSPORT', $names, true), 'includes talkSPORT');

$custom = $radio->saveStations([
    ['name' => 'Test FM', 'url' => 'https://example.com/stream.mp3'],
    ['name' => 'Bad', 'url' => 'not-a-url'],
]);
assert_true(count($custom) === 1 && $custom[0]['name'] === 'Test FM', 'saves valid stations only');
$restored = $radio->restoreDefaultStations();
assert_true(count($restored) >= 10, 'restore UK defaults');

assert_true($unifi->setSpeakersEnabled(false) === true, 'can disable speakers');
assert_true($unifi->speakersEnabled() === false, 'speakers disabled');
$view = $unifi->publicView();
assert_true(($view['speakers_enabled'] ?? true) === false, 'publicView reflects off');
assert_true(($view['stations'] ?? ['x']) === [], 'stations hidden when off');

$unifi->setSpeakersEnabled(true);
$unifi->save([
    'host' => '192.168.1.1',
    'protect_api_key' => 'test-protect-key',
]);
file_put_contents($root . '/data/unifi-inventory.json', json_encode([
    'cameras' => [
        [
            'id' => 'unifi:camera:cam1',
            'native_id' => 'cam1',
            'name' => 'Front G3 Reader Pro',
            'kind' => 'camera',
            'product' => 'UA G3 Reader Pro',
            'has_speaker' => true,
            'available' => true,
        ],
        [
            'id' => 'unifi:camera:cam2',
            'native_id' => 'cam2',
            'name' => 'Driveway',
            'kind' => 'camera',
            'product' => 'G4 Bullet',
            'has_speaker' => false,
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

$speakers = $unifi->speakerCameras();
assert_true(count($speakers) === 1, 'only speaker cameras: ' . json_encode($speakers));
assert_true(($speakers[0]['native_id'] ?? '') === 'cam1', 'reader pro listed');

$calls = [];
$unifi->setTransport(static function (string $method, string $url, array $headers, ?string $body, float $timeout, bool $binary) use (&$calls): array {
    $calls[] = [
        'method' => $method,
        'url' => $url,
        'body' => $body,
        'headers' => $headers,
    ];
    if (str_contains($url, '/api/auth/login')) {
        return [
            'status' => 200,
            'body' => '{}',
            'content_type' => 'application/json',
            'response_headers' => [
                'set-cookie' => ['TOKEN=test-token; Path=/; HttpOnly'],
                'x-csrf-token' => ['csrf-test'],
            ],
        ];
    }
    if (str_contains($url, 'talkback-session')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'url' => 'rtp://192.168.1.50:7004',
                'codec' => 'aac',
                'samplingRate' => 22050,
                'bitsPerSample' => 16,
            ]),
            'content_type' => 'application/json',
        ];
    }
    // API key on the private path must not be used for volume (real consoles 401).
    if (strtoupper($method) === 'PATCH' && str_contains($url, '/proxy/protect/api/cameras/')) {
        $hasCookie = isset($headers['Cookie']) && str_contains((string) $headers['Cookie'], 'TOKEN=');
        if (!$hasCookie) {
            return ['status' => 401, 'body' => '', 'content_type' => '', 'error' => 'HTTP 401'];
        }
        $decoded = is_string($body) ? json_decode($body, true) : null;
        $hasSpeakerVol = is_array($decoded)
            && isset($decoded['speakerSettings']['speakerVolume']);
        if (!$hasSpeakerVol) {
            return ['status' => 400, 'body' => '{"error":"missing speakerVolume"}', 'content_type' => 'application/json', 'error' => 'missing speakerVolume'];
        }

        return ['status' => 200, 'body' => '{}', 'content_type' => 'application/json'];
    }
    if (strtoupper($method) === 'PATCH' && str_contains($url, '/proxy/protect/integration/')) {
        return [
            'status' => 400,
            'body' => json_encode(['name' => 'AJV_PARSE_ERROR', 'error' => 'additional properties']),
            'content_type' => 'application/json',
            'error' => 'Protect rejected that command',
        ];
    }

    return ['status' => 404, 'body' => '', 'content_type' => '', 'error' => 'unexpected ' . $url];
});

$session = $unifi->createTalkbackSession('cam1');
assert_true(($session['ok'] ?? false) === true, 'talkback session ok: ' . json_encode($session));
assert_true(($session['url'] ?? '') === 'rtp://192.168.1.50:7004', 'talkback url');

$skipped = $unifi->setCameraSpeakerVolume('cam1', 55);
assert_true(($skipped['ok'] ?? true) === false, 'volume without local admin fails');
assert_true(!empty($skipped['skipped']), 'volume without local admin is skipped, not HTTP 401');
assert_true(!str_contains(strtolower((string) ($skipped['error'] ?? '')), '401'), 'no raw 401 without local admin');

$unifi->save([
    'host' => '192.168.1.1',
    'protect_api_key' => 'test-protect-key',
    'protect_username' => 'admin',
    'protect_password' => 'secret',
]);
$calls = [];
$vol = $unifi->setCameraSpeakerVolume('cam1', 55);
assert_true(($vol['ok'] ?? false) === true, 'volume ok via OS session: ' . json_encode($vol));
$volUrls = array_values(array_filter($calls, static fn (array $c): bool => strtoupper((string) $c['method']) === 'PATCH'));
assert_true($volUrls !== [], 'volume issued PATCH');
assert_true(str_contains((string) $volUrls[0]['url'], '/proxy/protect/api/cameras/'), 'volume uses private Protect API');
assert_true(str_contains((string) ($volUrls[0]['headers']['Cookie'] ?? ''), 'TOKEN='), 'volume uses session cookie, not API key alone');
assert_true(!str_contains((string) ($volUrls[0]['url'] ?? ''), '/integration/'), 'volume does not use Integration camera PATCH');

$radioPhp = (string) file_get_contents(dirname(__DIR__) . '/src/YarboUnifiRadio.php');
assert_true(str_contains($radioPhp, '-af'), 'ffmpeg applies stream volume filter');
assert_true(str_contains($radioPhp, 'volume='), 'ffmpeg volume gain from slider');

$ref = new ReflectionClass(YarboUnifi::class);
$method = $ref->getMethod('mapCamera');
$method->setAccessible(true);
$mapped = $method->invoke($unifi, [
    'id' => 'abc',
    'name' => 'Gate',
    'type' => 'UA G3 Reader Pro',
    'state' => 'CONNECTED',
    'featureFlags' => ['hasSpeaker' => false],
]);
assert_true(!empty($mapped['has_speaker']), 'Reader Pro name implies speaker');

$php = (string) file_get_contents(dirname(__DIR__) . '/src/YarboUnifiRadio.php');
$change = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
$index = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
assert_true(str_contains($php, 'class YarboUnifiRadio'), 'radio class present');
assert_true(str_contains($index, 'id="radio-player-modal"'), 'Radio Player modal');
assert_true(str_contains($index, 'id="radio-open"'), 'Radio header button');
assert_true(str_contains($index, 'id="unifi-speakers"'), 'Radio controls container');
assert_true(str_contains($index, 'settings-unifi-speakers-enabled'), 'Settings Radio Player toggle');
assert_true(str_contains($index, 'Show Radio Player in the header'), 'Settings Radio Player label');
assert_true(!str_contains($index, 'Show Speakers / radio on the UniFi page'), 'old Speakers page label gone');
$unifiSection = '';
if (preg_match('/<section[^>]*data-module="unifi"[^>]*>([\s\S]*?)<\/section>/', $index, $um)) {
    $unifiSection = $um[1];
}
assert_true($unifiSection !== '', 'UniFi page section present');
assert_true(!str_contains($unifiSection, 'id="unifi-speakers"'), 'Speakers not embedded on UniFi page section');
assert_true(str_contains($unifiSection, 'Radio</strong> button') || str_contains($unifiSection, 'Radio Player'), 'UniFi page points to header Radio');
assert_true(str_contains($change, '## [4.0.158]'), 'changelog 4.0.158');
assert_true(str_contains($change, '## [4.0.159]'), 'changelog 4.0.159');
assert_true(str_contains($change, '## [4.0.160]'), 'changelog 4.0.160');
assert_true(str_contains($change, '## [4.0.161]'), 'changelog 4.0.161');
assert_true(str_contains($change, '## [4.0.162]'), 'changelog 4.0.162');
assert_true(str_contains($change, '## [4.0.163]'), 'changelog 4.0.163');
assert_true(str_contains($change, '## [4.0.164]'), 'changelog 4.0.164');
assert_true(str_contains($change, '## [4.0.165]'), 'changelog 4.0.165');
assert_true(str_contains($change, '## [4.0.166]'), 'changelog 4.0.166');
assert_true(str_contains($change, '## [4.0.167]'), 'changelog 4.0.167');
assert_true(str_contains($change, 'Announce'), 'changelog mentions Announce');
assert_true(str_contains($change, '8443') || str_contains($change, 'HTTPS'), 'changelog mentions HTTPS :8443');
assert_true(str_contains($change, 'Radio Player'), 'changelog mentions Radio Player');
assert_true(str_contains($index, 'id="unifi-radio-rec"'), 'Announce Record button');
assert_true(str_contains($index, 'id="unifi-radio-rec-listen"'), 'Announce Listen button');
assert_true(str_contains($index, 'id="unifi-radio-rec-send"'), 'Announce Send button');
assert_true(str_contains($index, 'id="unifi-radio-rec-file"'), 'Announce Choose file button');
assert_true(str_contains($index, 'unifi-radio-announce'), 'Announce section');
$httpsProxy = (string) file_get_contents(dirname(__DIR__) . '/scripts/panel_https_proxy.py');
$httpsLib = (string) file_get_contents(dirname(__DIR__) . '/scripts/lib/panel_https.sh');
$panelSh = (string) file_get_contents(dirname(__DIR__) . '/scripts/panel.sh');
assert_true(str_contains($httpsProxy, 'panel_https_proxy'), 'HTTPS proxy script');
assert_true(str_contains($httpsLib, 'yarbo_panel_start_https_proxy'), 'HTTPS helper');
assert_true(str_contains($panelSh, 'panel_https.sh'), 'panel.sh loads HTTPS helper');
$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/style.css');
assert_true(str_contains($css, '.unifi-speakers'), 'Speakers panel CSS');
assert_true(preg_match('/\.unifi-speakers\s*\{[^}]*color:\s*var\(--text\)/s', $css) === 1, 'Speakers uses theme text colour');
assert_true(str_contains($css, 'radio-player-panel'), 'Radio Player modal panel CSS');
assert_true(str_contains($css, 'radio-playing-badge') || str_contains($css, '#radio-open.is-playing'), 'Radio playing chrome CSS');
assert_true(str_contains($css, '.unifi-radio-speaker span'), 'speaker name colour forced');
assert_true(preg_match('/#unifi-radio-status\s*\{[^}]*margin:\s*0\.55rem/s', $css) === 1, 'status cleared below Play/Stop');
assert_true(str_contains($css, 'unifi-radio-announce'), 'Announce CSS');
$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
assert_true(str_contains($js, 'prevModuleId !== \'unifi\''), 'UniFi poll not restarted every hub status');
assert_true(str_contains($js, 'syncRadioPlayerChrome'), 'Radio header chrome sync');
assert_true(str_contains($js, 'openRadioPlayerModal'), 'Radio Player modal open');
assert_true(str_contains($js, 'UNIFI_SPEAKER_PICK_KEY'), 'speaker ticks remembered');
assert_true(str_contains($js, 'fromDom'), 'speaker ticks preserved across Speakers re-render');
assert_true(str_contains($js, 'startRadioRecording'), 'Announce MediaRecorder start');
assert_true(str_contains($js, "action', 'radio_announce'") || str_contains($js, 'radio_announce'), 'Announce upload action');
assert_true(str_contains($js, 'radioAnnounceHttpsUrl') || str_contains($js, ':8443'), 'UI points at HTTPS :8443');
assert_true(str_contains($js, 'unifiRadioRecFile') || str_contains($js, 'unifi-radio-rec-file'), 'Choose file wired');
$api = (string) file_get_contents(dirname(__DIR__) . '/public/api/unifi.php');
assert_true(str_contains($api, 'catch (Throwable'), 'unifi status catches fatals as JSON');
assert_true(str_contains($api, "radio_announce"), 'API radio_announce action');
$php = (string) file_get_contents(dirname(__DIR__) . '/src/YarboUnifiRadio.php');
$unifiPhp = (string) file_get_contents(dirname(__DIR__) . '/src/YarboUnifi.php');
assert_true(str_contains($php, 'function_exists(\'mb_substr\')'), 'mb_substr guarded');
assert_true(str_contains($php, 'active_targets'), 'status exposes active stream targets');
assert_true(!preg_match('/udp:\/\/[^\'"]*pkt_size/', $php), 'ffmpeg ADTS UDP has no MPEG-TS pkt_size');
assert_true(str_contains($php, 'reconnect_streamed'), 'ffmpeg reconnects Icecast/HLS');
assert_true(str_contains($php, '-f adts'), 'ffmpeg outputs ADTS for AAC talkback');
assert_true(str_contains($php, 'function playAnnounce'), 'playAnnounce method');
assert_true(str_contains($php, 'storeAnnounceUpload'), 'announce upload helper');
assert_true(str_contains($php, '$localFile'), 'ffmpeg local-file path for announce');
assert_true(str_contains($unifiPhp, 'speakerVolume'), 'volume uses speakerVolume on private API');
assert_true(str_contains($unifiPhp, 'protectPrivateUrl'), 'volume helper can build private Protect URL');

$announceFail = $radio->playAnnounce(['targets' => ['cam1'], 'volume' => 50], []);
assert_true(($announceFail['ok'] ?? true) === false, 'announce without upload fails');
assert_true(str_contains((string) ($announceFail['error'] ?? ''), 'upload') || str_contains((string) ($announceFail['error'] ?? ''), 'Recording'), 'announce error mentions upload');

// Idle status keeps last chosen targets (so the UI does not treat empty as “all on”).
file_put_contents($root . '/data/unifi-radio-state.json', json_encode([
    'playing' => false,
    'station_id' => 'classic-fm',
    'station_name' => 'Classic FM',
    'volume' => 40,
    'streams' => [],
    'targets' => ['cam1', 'cam2'],
    'started_at' => null,
    'error' => null,
], JSON_UNESCAPED_SLASHES));
$idle = $radio->status();
assert_true(($idle['playing'] ?? true) === false, 'idle not playing');
assert_true(($idle['targets'] ?? []) === ['cam1', 'cam2'], 'idle keeps saved targets');
assert_true(($idle['active_targets'] ?? ['x']) === [], 'idle has no active streams');

echo "test_unifi_radio.php ok\n";
