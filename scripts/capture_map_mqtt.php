<?php

declare(strict_types=1);

/**
 * Capture MQTT traffic while editing/saving maps in the official Yarbo app.
 *
 * Usage:
 *   php scripts/capture_map_mqtt.php [seconds]
 *
 * While this script runs, save or edit a map in the Yarbo app, then inspect:
 *   debug/map-dumps/mqtt_capture_YYYYMMDD_HHMMSS.jsonl
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Yarbo\YarboCodec;

$config = require __DIR__ . '/../config.php';

$durationSeconds = 300;
if (isset($argv[1]) && is_numeric($argv[1])) {
    $durationSeconds = max(10, (int) $argv[1]);
}

$sn = (string) ($config['serial'] ?? '');
$host = (string) ($config['broker_host'] ?? '');
$port = (int) ($config['broker_port'] ?? 1883);
$dumpDir = __DIR__ . '/../debug/map-dumps';

if ($sn === '' || $host === '') {
    fwrite(STDERR, "config.php must set broker_host and serial\n");
    exit(1);
}

if (!is_dir($dumpDir) && !mkdir($dumpDir, 0775, true) && !is_dir($dumpDir)) {
    fwrite(STDERR, "Failed to create dump directory: {$dumpDir}\n");
    exit(1);
}

$topics = [
    "snowbot/{$sn}/device/data_feedback",
    "snowbot/{$sn}/app/+",
];

$timestamp = gmdate('Ymd_His');
$outPath = "{$dumpDir}/mqtt_capture_{$timestamp}.jsonl";

echo "Connecting to {$host}:{$port} SN={$sn}\n";
echo "Capturing for {$durationSeconds}s → {$outPath}\n";
echo "Now save or edit a map in the Yarbo app. Press Ctrl+C to stop early.\n\n";

$client = new MqttClient($host, $port, 'yarbo-map-capture-' . bin2hex(random_bytes(4)));
$client->connect(
    (new ConnectionSettings())->setConnectTimeout(5)->setSocketTimeout(5)->setKeepAliveInterval(30),
    true
);

$count = 0;
$logLine = static function (array $entry) use ($outPath, &$count): void {
    $count++;
    file_put_contents($outPath, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    $cmd = $entry['command'] ?? $entry['feedback_topic'] ?? $entry['topic'];
    $size = $entry['payload_bytes'] ?? 0;
    echo '[' . $entry['captured_at'] . "] {$cmd} ({$size} bytes)\n";
};

foreach ($topics as $topic) {
    $client->subscribe($topic, function (string $topic, string $message) use ($logLine, $sn): void {
        $payloadBytes = strlen($message);
        $decoded = YarboCodec::decode($message);
        $entry = [
            'captured_at' => gmdate('c'),
            'topic' => $topic,
            'payload_bytes' => $payloadBytes,
            'decoded_keys' => array_keys($decoded),
        ];

        if (preg_match('#^snowbot/' . preg_quote($sn, '#') . '/app/(.+)$#', $topic, $matches)) {
            $entry['direction'] = 'app_publish';
            $entry['command'] = $matches[1];
        } else {
            $entry['direction'] = 'device_feedback';
            $entry['feedback_topic'] = is_string($decoded['topic'] ?? null) ? $decoded['topic'] : null;
            $entry['state'] = $decoded['state'] ?? null;
            if (isset($decoded['data']) && is_array($decoded['data'])) {
                $entry['data_keys'] = array_keys($decoded['data']);
            }
        }

        if ($payloadBytes <= 4096) {
            $entry['decoded'] = $decoded;
        }

        $logLine($entry);
    }, 0);
}

$started = microtime(true);
while (microtime(true) - $started < 0.5) {
    $client->loopOnce($started, true);
}

$deadline = microtime(true) + $durationSeconds;
while (microtime(true) < $deadline) {
    $client->loopOnce($started, true);
    usleep(50_000);
}

$client->disconnect();

echo "\nCapture complete. {$count} message(s) written to:\n{$outPath}\n";
