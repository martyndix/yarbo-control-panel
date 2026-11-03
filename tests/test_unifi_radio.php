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
assert_true(str_contains($change, '## [4.0.168]'), 'changelog 4.0.168');
assert_true(str_contains($change, '## [4.0.172]'), 'changelog 4.0.172');
assert_true(str_contains($change, 'Announcement'), 'changelog mentions Announcement');
assert_true(str_contains($change, '8443') || str_contains($change, 'HTTPS'), 'changelog mentions HTTPS :8443');
assert_true(str_contains($change, 'Radio Player'), 'changelog mentions Radio Player');
assert_true(str_contains($index, 'id="unifi-radio-rec"'), 'Announcement Record button');
assert_true(str_contains($index, 'Hold to record'), 'Announcement hold-to-record label');
assert_true(!str_contains($index, 'id="unifi-radio-rec-stop"'), 'Announcement Stop button removed');
assert_true(str_contains($index, 'id="unifi-radio-rec-meter-fill"'), 'Announcement level meter');
assert_true(str_contains($index, 'id="unifi-radio-rec-listen"'), 'Announcement Listen button');
assert_true(str_contains($index, 'id="unifi-radio-rec-send"'), 'Announcement Send button');
assert_true(str_contains($index, 'id="unifi-radio-rec-file"'), 'Announcement Choose file button');
$listenPos = strpos($index, 'id="unifi-radio-rec-listen"');
$filePos = strpos($index, 'id="unifi-radio-rec-file"');
assert_true($listenPos !== false && $filePos !== false && $listenPos < $filePos, 'Listen before Choose file');
assert_true(str_contains($index, 'unifi-radio-announce'), 'Announcement section');
assert_true(str_contains($index, '>Announcement<'), 'Announcement heading label');
assert_true(!preg_match('/settings-subtitle">Announce</', $index), 'old Announce heading gone');
$announcePos = strpos($index, 'unifi-radio-announce');
$radioStreamPos = strpos($index, 'unifi-radio-stream');
assert_true($announcePos !== false && $radioStreamPos !== false && $announcePos < $radioStreamPos, 'Announcement above Radio section');
assert_true(str_contains($change, '## [4.0.174]'), 'changelog 4.0.174');
assert_true(str_contains($change, '## [4.0.175]'), 'changelog 4.0.175');
assert_true(str_contains($change, '🔊') && str_contains($change, '📣'), 'changelog mentions radio badge emojis');
assert_true(!str_contains($index, 'Live Record needs HTTPS'), 'HTTPS blurb removed from Announcement hint');
$httpsProxy = (string) file_get_contents(dirname(__DIR__) . '/scripts/panel_https_proxy.py');
$httpsLib = (string) file_get_contents(dirname(__DIR__) . '/scripts/lib/panel_https.sh');
$panelSh = (string) file_get_contents(dirname(__DIR__) . '/scripts/panel.sh');
assert_true(str_contains($httpsProxy, 'panel_https_proxy'), 'HTTPS proxy script');
assert_true(str_contains($httpsProxy, 'ThreadingHTTPServer') || str_contains($httpsProxy, 'BaseHTTPRequestHandler'), 'HTTPS proxy is HTTP-aware');
assert_true(str_contains($httpsLib, 'yarbo_panel_start_https_proxy'), 'HTTPS helper');
assert_true(str_contains($panelSh, 'panel_https.sh'), 'panel.sh loads HTTPS helper');
assert_true(str_contains($panelSh, 'PHP_PID'), 'panel.sh backgrounds PHP then starts HTTPS');
assert_true(str_contains($panelSh, 'Watching HTTPS proxy') || str_contains($panelSh, 'HTTPS_WATCH_PID'), 'panel.sh watches HTTPS');
assert_true(str_contains($httpsLib, '--no-wait-upstream'), 'HTTPS helper assumes upstream is up');
$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/style.css');
assert_true(str_contains($css, '.unifi-speakers'), 'Speakers panel CSS');
assert_true(preg_match('/\.unifi-speakers\s*\{[^}]*color:\s*var\(--text\)/s', $css) === 1, 'Speakers uses theme text colour');
assert_true(str_contains($css, 'radio-player-panel'), 'Radio Player modal panel CSS');
assert_true(str_contains($css, 'radio-playing-badge') || str_contains($css, '#radio-open.is-playing'), 'Radio playing chrome CSS');
assert_true(str_contains($css, '.unifi-radio-speaker span'), 'speaker name colour forced');
assert_true(
    preg_match('/#unifi-radio-status[^{]*\{[^}]*margin:\s*0\.55rem/s', $css) === 1
        || preg_match('/#unifi-radio-status,\s*#unifi-airplay-status\s*\{[^}]*margin:\s*0\.55rem/s', $css) === 1,
    'status cleared below Play/Stop'
);
assert_true(str_contains($css, '#unifi-airplay-status'), 'AirPlay status has overlap-safe margin');
assert_true(str_contains($css, 'unifi-radio-announce'), 'Announcement CSS');
assert_true(str_contains($css, 'unifi-radio-rec-meter'), 'Announcement meter CSS');
$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
assert_true(str_contains($js, 'prevModuleId !== \'unifi\''), 'UniFi poll not restarted every hub status');
assert_true(str_contains($js, 'syncRadioPlayerChrome'), 'Radio header chrome sync');
assert_true(str_contains($js, 'openRadioPlayerModal'), 'Radio Player modal open');
assert_true(str_contains($js, 'UNIFI_SPEAKER_PICK_KEY'), 'speaker ticks remembered');
assert_true(str_contains($js, 'fromDom'), 'speaker ticks preserved across Speakers re-render');
assert_true(str_contains($js, 'startRadioRecording'), 'Announcement MediaRecorder start');
assert_true(str_contains($js, 'beginRadioRecHold'), 'Announcement hold-to-record');
assert_true(str_contains($js, 'startRadioRecMeter'), 'Announcement level meter');
assert_true(str_contains($js, "action', 'radio_announce'") || str_contains($js, 'radio_announce'), 'Announcement upload action');
assert_true(str_contains($js, 'unifiRadioRecFile') || str_contains($js, 'unifi-radio-rec-file'), 'Choose file wired');
assert_true(str_contains($js, 'startRadioStatusPoll'), 'radio status poll while playing');
assert_true(str_contains($js, 'applyRadioCommandResult'), 'play/stop invalidate stale radio polls');
assert_true(str_contains($js, 'radioStatusPollGen'), 'radio poll generation guard');
assert_true(str_contains($js, 'pickRadioState'), 'prefers fresh remote radio for other devices');
assert_true(str_contains($js, 'radioLocalCommandUntil'), 'local Play/Stop grace before remote Idle');
assert_true(str_contains($js, 'Stay subscribed while Idle') || str_contains($js, 'Keep watching while'), 'Idle watch for remote Play');
assert_true(str_contains($js, "action: 'radio_status'"), 'polls radio_status');
assert_true(str_contains($js, "'📣'") && str_contains($js, "'🔊'"), 'playing badge emojis');
assert_true(str_contains($change, '## [4.0.176]'), 'changelog 4.0.176');
assert_true(str_contains($change, '## [4.0.177]'), 'changelog 4.0.177');
$api = (string) file_get_contents(dirname(__DIR__) . '/public/api/unifi.php');
assert_true(str_contains($api, 'catch (Throwable'), 'unifi status catches fatals as JSON');
assert_true(str_contains($api, "radio_announce"), 'API radio_announce action');
assert_true(str_contains($api, "radio_status"), 'API radio_status action');
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
assert_true(str_contains($php, 'ends_at'), 'announce ends_at for Idle');
assert_true(str_contains($php, 'probeClipDurationSeconds'), 'ffprobe duration for announce');
assert_true(str_contains($php, "'Announcement'"), 'station name Announcement');
assert_true(str_contains($php, 'resume_after'), 'resume radio after announcement');
assert_true(str_contains($php, 'captureResumeAfter'), 'captures playing station before announce');
assert_true(str_contains($change, '## [4.0.178]'), 'changelog 4.0.178');
assert_true(str_contains($change, '## [4.0.179]'), 'changelog 4.0.179');
assert_true(str_contains($change, '## [4.0.185]'), 'changelog 4.0.185');
assert_true(str_contains($change, '## [4.0.186]'), 'changelog 4.0.186');
assert_true(str_contains($change, '## [4.0.187]'), 'changelog 4.0.187');
assert_true(str_contains($change, '## [4.0.188]'), 'changelog 4.0.188');
assert_true(str_contains($change, '## [4.0.189]'), 'changelog 4.0.189');
assert_true(str_contains($change, '## [4.0.190]'), 'changelog 4.0.190');
assert_true(str_contains($change, '## [4.0.191]'), 'changelog 4.0.191');
assert_true(str_contains($change, '## [4.0.192]'), 'changelog 4.0.192');
assert_true(str_contains($change, '## [4.0.193]'), 'changelog 4.0.193');
assert_true(str_contains($change, '## [4.0.194]'), 'changelog 4.0.194');
assert_true(str_contains($change, 'HomePod'), 'changelog mentions HomePod');
assert_true(str_contains($change, 'pyatv'), 'changelog mentions pyatv install');
assert_true(str_contains($change, 'resumes') || str_contains($change, 'resume'), 'changelog mentions resume after announcement');
assert_true(str_contains($change, 'AirPlay'), 'changelog mentions AirPlay bridge');
assert_true(str_contains($php, 'playAirplay'), 'radio class has playAirplay');
assert_true(str_contains($php, 'homepod_targets') && str_contains($php, 'filterHomepodIds'), 'radio fans out to HomePods');
assert_true(str_contains($index, 'settings-unifi-homepod-scan'), 'Settings HomePod scan UI');
assert_true(str_contains($js, 'homepod_pair_start'), 'JS HomePod pair');
assert_true(str_contains($panelSh, 'homepod_agent.py'), 'panel.sh starts HomePod agent');
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
    'ends_at' => null,
    'error' => null,
], JSON_UNESCAPED_SLASHES));
$idle = $radio->status();
assert_true(($idle['playing'] ?? true) === false, 'idle not playing');
assert_true(($idle['targets'] ?? []) === ['cam1', 'cam2'], 'idle keeps saved targets');
assert_true(($idle['active_targets'] ?? ['x']) === [], 'idle has no active streams');

// Finished announcement (dead pid / expired ends_at) must clear playing.
file_put_contents($root . '/data/unifi-radio-state.json', json_encode([
    'playing' => true,
    'station_id' => 'announce',
    'station_name' => 'Announcement',
    'volume' => 60,
    'streams' => [['camera_id' => 'cam1', 'pid' => 999999991]],
    'targets' => ['cam1'],
    'started_at' => time() - 10,
    'ends_at' => time() - 1,
    'resume_after' => null,
    'error' => null,
], JSON_UNESCAPED_SLASHES));
$done = $radio->status();
assert_true(($done['playing'] ?? true) === false, 'announce ends_at clears playing');
assert_true(empty($done['ends_at']), 'announce ends_at cleared');
assert_true(($done['station_name'] ?? '') === 'Announcement', 'keeps Announcement name while Idle');

// Capture resume snapshot while a station is marked playing.
file_put_contents($root . '/data/unifi-radio-state.json', json_encode([
    'playing' => true,
    'station_id' => 'classic-fm',
    'station_name' => 'Classic FM',
    'station_url' => 'https://example.com/classic.mp3',
    'volume' => 55,
    'streams' => [['camera_id' => 'cam1', 'pid' => 999999992]],
    'targets' => ['cam1'],
    'started_at' => time() - 30,
    'ends_at' => null,
    'resume_after' => null,
    'error' => null,
], JSON_UNESCAPED_SLASHES));
$capture = (new ReflectionClass(YarboUnifiRadio::class))->getMethod('captureResumeAfter');
$capture->setAccessible(true);
$snap = $capture->invoke($radio);
assert_true(is_array($snap), 'captureResumeAfter returns snapshot');
assert_true(($snap['station_id'] ?? '') === 'classic-fm', 'resume snapshot station');
assert_true(($snap['targets'] ?? []) === ['cam1'], 'resume snapshot targets');
assert_true((int) ($snap['volume'] ?? 0) === 55, 'resume snapshot volume');

// Explicit Stop cancels a pending resume_after.
file_put_contents($root . '/data/unifi-radio-state.json', json_encode([
    'playing' => true,
    'station_id' => 'announce',
    'station_name' => 'Announcement',
    'volume' => 60,
    'streams' => [],
    'targets' => ['cam1'],
    'started_at' => time(),
    'ends_at' => time() + 10,
    'resume_after' => [
        'station_id' => 'classic-fm',
        'url' => 'https://example.com/classic.mp3',
        'targets' => ['cam1'],
        'volume' => 55,
    ],
    'error' => null,
], JSON_UNESCAPED_SLASHES));
$radio->stop(true);
$afterStop = json_decode((string) file_get_contents($root . '/data/unifi-radio-state.json'), true);
assert_true(empty($afterStop['resume_after']), 'Stop clears resume_after');

// When an announcement ends with resume_after, status tries to restart the station.
$callsBeforeResume = count($calls);
file_put_contents($root . '/data/unifi-radio-state.json', json_encode([
    'playing' => true,
    'station_id' => 'announce',
    'station_name' => 'Announcement',
    'volume' => 60,
    'streams' => [['camera_id' => 'cam1', 'pid' => 999999993]],
    'targets' => ['cam1'],
    'started_at' => time() - 10,
    'ends_at' => time() - 1,
    'resume_after' => [
        'station_id' => 'classic-fm',
        'url' => 'https://example.com/classic.mp3',
        'targets' => ['cam1'],
        'volume' => 55,
    ],
    'error' => null,
], JSON_UNESCAPED_SLASHES));
$radio->status();
$afterResume = json_decode((string) file_get_contents($root . '/data/unifi-radio-state.json'), true);
assert_true(empty($afterResume['resume_after']), 'resume_after cleared after announce end');
$talkbacks = array_values(array_filter(
    array_slice($calls, $callsBeforeResume),
    static fn (array $c): bool => str_contains((string) ($c['url'] ?? ''), 'talkback-session')
));
assert_true($talkbacks !== [], 'resume after announce starts talkback');

echo "test_unifi_radio.php ok\n";
