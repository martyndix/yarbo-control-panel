<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config.php';

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Yarbo\YarboCodec;

$sn = $config['serial'];
$host = $config['broker_host'];
$port = $config['broker_port'];

$topics = [
    "snowbot/{$sn}/device/data_feedback",
    "snowbot/{$sn}/device/DeviceMSG",
    "snowbot/{$sn}/device/heart_beat",
    "snowbot/+/device/DeviceMSG",
    "snowbot/+/device/heart_beat",
];

echo "Connecting to {$host}:{$port} SN={$sn}\n";

$client = new MqttClient($host, $port, 'yarbo-debug-' . bin2hex(random_bytes(4)));
$client->connect((new ConnectionSettings())->setConnectTimeout(5)->setSocketTimeout(5), true);

$count = 0;
foreach ($topics as $topic) {
    $client->subscribe($topic, function (string $topic, string $message) use (&$count): void {
        $count++;
        $decoded = YarboCodec::decode($message);
        $keys = array_keys($decoded);
        echo "\n--- [{$count}] {$topic} ---\n";
        echo 'Top-level keys: ' . implode(', ', $keys) . "\n";
        if (isset($decoded['topic'])) {
            echo "feedback topic: {$decoded['topic']}\n";
        }
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            echo 'data keys: ' . implode(', ', array_keys($decoded['data'])) . "\n";
        }
        echo substr(json_encode($decoded, JSON_PRETTY_PRINT), 0, 800) . "\n";
    }, 0);
}

// Allow subscriptions to register
$started = microtime(true);
while (microtime(true) - $started < 0.5) {
    $client->loopOnce($started, true);
}

$cmdTopic = "snowbot/{$sn}/app/get_device_msg";
echo "\nPublishing get_device_msg to {$cmdTopic}\n";
$client->publish($cmdTopic, YarboCodec::encode([]), 0);

$deadline = microtime(true) + 8;
while (microtime(true) < $deadline) {
    $client->loopOnce($started, true);
}

echo "\nDone. Received {$count} messages.\n";
$client->disconnect();
