<?php

declare(strict_types=1);

/**
 * Background map-save listener for the web UI. Does not publish anything.
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Yarbo\YarboCloud;
use Yarbo\YarboCloudSettings;
use Yarbo\YarboCodec;
use Yarbo\YarboMapCapture;

$projectRoot = dirname(__DIR__);
$config = require $projectRoot . '/config.php';
$capture = new YarboMapCapture($projectRoot);
$duration = YarboMapCapture::DURATION_SECONDS;
$deadline = microtime(true) + $duration;

$sn = (string) ($config['serial'] ?? '');
$host = (string) ($config['broker_host'] ?? '');
$port = (int) ($config['broker_port'] ?? 1883);

$cloudPid = null;
$cloudSettings = new YarboCloudSettings($projectRoot . '/data');
$cloudCfg = $cloudSettings->load();
$cloud = new YarboCloud($cloudSettings, $projectRoot);
$cloudStatus = $cloud->status();
$cloudReady = ($cloudCfg['enabled'] ?? false)
    && ($cloudCfg['email'] ?? '') !== ''
    && ($cloudCfg['password'] ?? '') !== ''
    && ($cloudStatus['sdk_installed'] ?? false);

if ($cloudReady) {
    $python = (string) ($cloudStatus['python_executable'] ?? $cloudStatus['python'] ?? 'python3');
    $script = $projectRoot . '/scripts/capture_map_cloud.py';
    $cmd = sprintf(
        '%s %s %s --commands-file %s --stop-file %s >/dev/null 2>&1 & echo $!',
        escapeshellarg($python),
        escapeshellarg($script),
        (string) $duration,
        escapeshellarg($capture->cloudCommandsPath()),
        escapeshellarg($capture->stopPath())
    );
    $cloudPid = (int) trim((string) shell_exec($cmd));
    if ($cloudPid > 0) {
        $capture->patch(['cloud_pid' => $cloudPid]);
        $capture->addVia('cloud');
    }
}

$client = null;
$localOk = false;
if ($sn !== '' && $host !== '') {
    try {
        $client = new MqttClient($host, $port, 'yarbo-map-ui-capture-' . bin2hex(random_bytes(4)));
        $client->connect(
            (new ConnectionSettings())->setConnectTimeout(5)->setSocketTimeout(5)->setKeepAliveInterval(30),
            true
        );
        $client->subscribe("snowbot/{$sn}/#", static function (string $topic, string $message) use ($capture, $sn): void {
            $decoded = YarboCodec::decode($message);
            if (preg_match('#^snowbot/' . preg_quote($sn, '#') . '/app/(.+)$#', $topic, $matches)) {
                $capture->recordAppCommand($matches[1], 'local');
                return;
            }
            $feedback = is_string($decoded['topic'] ?? null) ? (string) $decoded['topic'] : '';
            if ($feedback !== '') {
                $capture->recordFeedbackTopic($feedback);
            }
        }, 0);
        $localOk = true;
        $capture->addVia('local');
    } catch (Throwable $e) {
        $capture->patch(['error' => 'Local MQTT: ' . $e->getMessage()]);
    }
}

$lastTick = 0;
while (microtime(true) < $deadline) {
    if (is_file($capture->stopPath())) {
        break;
    }
    if ($client !== null) {
        $client->loopOnce(microtime(true), true);
    }
    if (is_file($capture->cloudCommandsPath())) {
        $cloudCmds = json_decode((string) file_get_contents($capture->cloudCommandsPath()), true);
        if (is_array($cloudCmds)) {
            $capture->mergeCloudCommands($cloudCmds);
        }
    }
    $now = time();
    if ($now !== $lastTick) {
        $lastTick = $now;
        $left = max(0, (int) ceil($deadline - microtime(true)));
        $capture->patch([
            'remaining_s' => $left,
            'message' => sprintf(
                'Listening… %ds left. Save a map in the Yarbo app or Yardstick. This does not change the robot map.',
                $left
            ),
        ]);
    }
    usleep(80_000);
}

if ($client !== null) {
    try {
        $client->disconnect();
    } catch (Throwable) {
    }
}
if ($cloudPid && $cloudPid > 1 && function_exists('posix_kill')) {
    posix_kill($cloudPid, SIGTERM);
}
if (is_file($capture->cloudCommandsPath())) {
    $cloudCmds = json_decode((string) file_get_contents($capture->cloudCommandsPath()), true);
    if (is_array($cloudCmds)) {
        $capture->mergeCloudCommands($cloudCmds);
    }
}

$final = $capture->status();
$unknown = $final['unknown_commands'] ?? [];
$app = $final['app_commands'] ?? [];
if ($unknown !== []) {
    $message = 'Heard unpublished command: ' . implode(', ', $unknown) . '. Leave this on screen — that is the map-write name.';
} elseif ($app !== []) {
    $message = 'Heard only documented commands (' . implode(', ', array_keys($app)) . '). Try again and save (not just load) the map.';
} else {
    $hint = $cloudReady
        ? 'No app commands seen. Save the map while this is listening. Cloud ACL may hide app publishes.'
        : 'No app commands seen. Enable Settings → cloud fallback (Yardstick uses the cloud), then listen again.';
    $message = $hint;
}

$capture->patch([
    'state' => 'done',
    'remaining_s' => 0,
    'finished_at' => gmdate('c'),
    'message' => $message,
    'error' => $localOk || $cloudReady ? ($final['error'] ?? null) : 'Could not listen on local MQTT or cloud',
]);
