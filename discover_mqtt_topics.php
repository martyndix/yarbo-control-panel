<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config.php';
$sn = $config['serial'];

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Yarbo\YarboCodec;

echo "Wildcard MQTT capture for 15s. Open Smart Vision in Yarbo app NOW if possible.\n";

$client = new MqttClient($config['broker_host'], (int) $config['broker_port'], 'yarbo-wild-' . bin2hex(random_bytes(3)));
$client->connect((new ConnectionSettings())->setConnectTimeout(5), true);

$seen = [];
$client->subscribe('snowbot/#', function (string $topic, string $message) use (&$seen): void {
    if (isset($seen[$topic])) {
        return;
    }
    $seen[$topic] = true;
    $decoded = YarboCodec::decode($message);
    $blob = json_encode($decoded);
    $short = strlen($blob) > 300 ? substr($blob, 0, 300) . '...' : $blob;
    echo "\n[NEW] {$topic}\n  {$short}\n";
    if (preg_match('/rtsp|webrtc|stream|video|m3u8|\.mp4/i', $blob)) {
        echo "  *** VIDEO HINT ***\n";
    }
}, 0);

$cmds = [
    ['smart_vision_control', ['state' => 1]],
    ['camera_toggle', ['enabled' => true]],
    ['enable_video_record', ['state' => 1]],
];
foreach ($cmds as [$cmd, $payload]) {
    $client->publish("snowbot/{$sn}/app/{$cmd}", YarboCodec::encode($payload), 0);
    echo "Published {$cmd}\n";
}

$started = microtime(true);
while (microtime(true) - $started < 15) {
    $client->loopOnce($started, true);
}

echo "\nTotal unique topics: " . count($seen) . "\n";
$client->disconnect();
