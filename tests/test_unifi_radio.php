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
    $calls[] = compact('method', 'url', 'body');
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
    if (strtoupper($method) === 'PATCH' && str_contains($url, '/cameras/')) {
        return ['status' => 200, 'body' => '{}', 'content_type' => 'application/json'];
    }

    return ['status' => 404, 'body' => '', 'content_type' => '', 'error' => 'unexpected ' . $url];
});

$session = $unifi->createTalkbackSession('cam1');
assert_true(($session['ok'] ?? false) === true, 'talkback session ok: ' . json_encode($session));
assert_true(($session['url'] ?? '') === 'rtp://192.168.1.50:7004', 'talkback url');
$vol = $unifi->setCameraSpeakerVolume('cam1', 55);
assert_true(($vol['ok'] ?? false) === true, 'volume ok');

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
assert_true(str_contains($index, 'id="unifi-speakers"'), 'UniFi Speakers UI');
assert_true(str_contains($index, 'settings-unifi-speakers-enabled'), 'Settings Speakers toggle');
assert_true(str_contains($change, '## [4.0.158]'), 'changelog 4.0.158');
assert_true(str_contains($change, '## [4.0.159]'), 'changelog 4.0.159');
assert_true(str_contains($change, '## [4.0.160]'), 'changelog 4.0.160');
$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/style.css');
assert_true(str_contains($css, '.unifi-speakers'), 'Speakers panel CSS');
assert_true(preg_match('/\.unifi-speakers\s*\{[^}]*color:\s*var\(--text\)/s', $css) === 1, 'Speakers uses theme text colour');
assert_true(preg_match('/\.unifi-speakers\s*\{[^}]*background:\s*var\(--card\)/s', $css) === 1, 'Speakers uses theme card background');
assert_true(str_contains($css, '.unifi-radio-speaker span'), 'speaker name colour forced');
$api = (string) file_get_contents(dirname(__DIR__) . '/public/api/unifi.php');
assert_true(str_contains($api, 'catch (Throwable'), 'unifi status catches fatals as JSON');
assert_true(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/YarboUnifiRadio.php'), 'function_exists(\'mb_substr\')'), 'mb_substr guarded');

echo "test_unifi_radio.php ok\n";
