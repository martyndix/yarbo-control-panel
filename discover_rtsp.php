<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config.php';

use Yarbo\YarboCodec;
use Yarbo\YarboMqtt;

$host = $config['broker_host'];
$serial = $config['serial'];
$ffprobe = $config['ffmpeg_path'] ?? 'ffprobe';
$ffprobe = str_replace('ffmpeg', 'ffprobe', $ffprobe);
if (!str_contains($ffprobe, 'ffprobe')) {
    $ffprobe = 'ffprobe';
}

echo "=== Yarbo RTSP Discovery ===\n";
echo "Broker: {$host}  SN: {$serial}\n\n";

// 1. Port scan broker
$ports = [22, 80, 443, 554, 1883, 1935, 8080, 8081, 8083, 8088, 8554, 8888, 10554, 22220, 19201, 19202, 19203, 19204];
echo "--- TCP ports on {$host} ---\n";
$open = [];
foreach ($ports as $port) {
    $fp = @fsockopen($host, $port, $errno, $errstr, 1.5);
    if ($fp) {
        fclose($fp);
        $open[] = $port;
        echo "  OPEN  {$port}\n";
    }
}
if (!$open) {
    echo "  (only checking list — none open except maybe 1883)\n";
}
echo "\n";

// 2. RTSP URL patterns to probe with ffprobe
$paths = ['/live/chn0', '/live/ch0', '/stream1', '/h264', '/cam/realmonitor?channel=1&subtype=0', '/'];
$hosts = array_unique([$host, '127.0.0.1']);
$rtspPorts = [554, 8554, 1935, 8080, 19201, 19202, 19203, 19204];

echo "--- ffprobe RTSP probes (quick) ---\n";
$found = [];
foreach ($hosts as $h) {
    foreach ($rtspPorts as $port) {
        foreach ($paths as $path) {
            $url = "rtsp://{$h}:{$port}{$path}";
            $cmd = sprintf(
                '%s -v error -rtsp_transport tcp -stimeout 2000000 -i %s -f null - 2>&1',
                escapeshellcmd($ffprobe),
                escapeshellarg($url),
            );
            exec($cmd, $out, $code);
            $text = implode("\n", $out);
            if ($code === 0 || str_contains($text, 'Video:') || str_contains($text, 'Stream #0')) {
                echo "  FOUND? {$url}\n";
                if ($text) {
                    echo "    " . substr($text, 0, 120) . "\n";
                }
                $found[] = $url;
            }
            $out = [];
        }
    }
}
if (!$found) {
    echo "  No RTSP streams responded on broker/localhost.\n";
}
echo "\n";

// 3. HTTP probes
echo "--- HTTP probes ---\n";
$httpPorts = [80, 8080, 8088, 8888, 22220];
foreach ($httpPorts as $port) {
    $ctx = stream_context_create(['http' => ['timeout' => 2]]);
    foreach (["http://{$host}:{$port}/", "http://{$host}:{$port}/live/chn0"] as $url) {
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false) {
            echo "  HTTP OK {$url} (" . strlen($body) . " bytes)\n";
            echo "    " . substr(preg_replace('/\s+/', ' ', $body), 0, 100) . "\n";
        }
    }
}
echo "\n";

// 4. MQTT: dump telemetry for URLs/IPs + try read commands
echo "--- MQTT telemetry IP/URL search ---\n";
$client = new YarboMqtt($host, (int) $config['broker_port'], $serial);
$client->connect();
$raw = $client->requestTelemetry(5);

$json = json_encode($raw, JSON_PRETTY_PRINT);
preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $json, $ips);
$ips = array_unique($ips[0] ?? []);
echo "  IPs in telemetry: " . (empty($ips) ? 'none' : implode(', ', $ips)) . "\n";
if (isset($raw['camera_state'])) {
    echo "  camera_state: " . json_encode($raw['camera_state']) . "\n";
}
if (isset($raw['NetMSG'])) {
    echo "  NetMSG: " . json_encode($raw['NetMSG']) . "\n";
}

$readCmds = [
    'get_connect_wifi_name',
    'read_global_params',
    'read_device_info',
    'get_device_msg',
];
echo "\n--- MQTT read commands (data_feedback) ---\n";
$feedbackTopic = "snowbot/{$serial}/device/data_feedback";
$responses = [];
$client2 = new YarboMqtt($host, (int) $config['broker_port'], $serial);
$client2->connect();

// Use reflection-free approach: re-use YarboMqtt by publishing via new minimal client
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

$mqtt = new MqttClient($host, (int) $config['broker_port'], 'yarbo-discover-' . bin2hex(random_bytes(3)));
$mqtt->connect((new ConnectionSettings())->setConnectTimeout(5), true);
$mqtt->subscribe($feedbackTopic, function (string $topic, string $msg) use (&$responses): void {
    $responses[] = YarboCodec::decode($msg);
}, 0);
$started = microtime(true);
while (microtime(true) - $started < 0.3) {
    $mqtt->loopOnce($started, true);
}

foreach ($readCmds as $cmd) {
    $payload = $cmd === 'read_global_params' ? ['id' => 1] : [];
    $topic = "snowbot/{$serial}/app/{$cmd}";
    $mqtt->publish($topic, YarboCodec::encode($payload), 0);
    $deadline = microtime(true) + 2;
    while (microtime(true) < $deadline) {
        $mqtt->loopOnce($started, true);
    }
}

$mqtt->disconnect();
$client->disconnect();

foreach ($responses as $i => $resp) {
    $topic = $resp['topic'] ?? "resp{$i}";
    $blob = json_encode($resp);
    echo "  [{$topic}] " . substr($blob, 0, 200) . (strlen($blob) > 200 ? '...' : '') . "\n";
    preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $blob, $m);
    foreach (array_unique($m[0] ?? []) as $ip) {
        echo "    -> IP found: {$ip}\n";
    }
    if (preg_match('/rtsp:\/\/[^\s"\']+/i', $blob, $rtsp)) {
        echo "    -> RTSP found: {$rtsp[0]}\n";
    }
}

echo "\n--- Scan local /24 for RTSP ports (may take ~30s) ---\n";
$subnet = preg_replace('/\.\d+$/', '', $host);
$rtspCandidates = [];
for ($i = 1; $i <= 254; $i++) {
    $ip = "{$subnet}.{$i}";
    if ($ip === $host) {
        continue;
    }
    foreach ([554, 8554, 19201] as $port) {
        $fp = @fsockopen($ip, $port, $errno, $errstr, 0.15);
        if ($fp) {
            fclose($fp);
            echo "  OPEN {$ip}:{$port}\n";
            $rtspCandidates[] = "{$ip}:{$port}";
        }
    }
}
if (!$rtspCandidates) {
    echo "  No extra RTSP-like ports found on {$subnet}.0/24\n";
}

echo "\n=== Done ===\n";
if ($found) {
    echo "Working RTSP URLs to try in config.php:\n";
    foreach ($found as $url) {
        echo "  'rtsp' => '{$url}'\n";
    }
} else {
    echo "No RTSP found. Cameras are likely only on internal 37.38.39.x (needs SSH tunnel).\n";
    echo "Tip: Open Smart Vision in the Yarbo app, then re-run this script — ports may appear while streaming.\n";
}
