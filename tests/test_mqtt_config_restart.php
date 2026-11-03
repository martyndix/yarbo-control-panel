<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$clientPhp = (string) file_get_contents($root . '/src/YarboMqttAgentClient.php');
$settingsPhp = (string) file_get_contents($root . '/public/api/settings.php');
$commandPhp = (string) file_get_contents($root . '/public/api/command.php');
$agentPy = (string) file_get_contents($root . '/scripts/mqtt_agent.py');
$js = (string) file_get_contents($root . '/public/assets/app.js');
$index = (string) file_get_contents($root . '/public/index.php');
$change = (string) file_get_contents($root . '/CHANGELOG.md');

assert_true(str_contains($clientPhp, 'function restart'), 'YarboMqttAgentClient::restart');
assert_true(str_contains($clientPhp, 'waitForBroker'), 'restart can wait for MQTT connected');
assert_true(str_contains($settingsPhp, 'YarboMqttAgentClient'), 'settings imports MQTT agent client');
assert_true(str_contains($settingsPhp, 'brokerChanged'), 'settings detects broker/serial change');
assert_true(str_contains($settingsPhp, 'mqtt_agent_restarted'), 'settings reports mqtt_agent_restarted');
assert_true(str_contains($settingsPhp, '->restart()'), 'settings restarts agent when broker changes');
assert_true(str_contains($commandPhp, 'runWithMqttRetry') || str_contains($commandPhp, 'mqtt not connected'), 'command retries after MQTT not connected');
assert_true(str_contains($commandPhp, 'restart(true)'), 'command wait-for-broker restart on retry');
assert_true(str_contains($agentPy, 'Check Broker IP and serial'), 'agent error hints at Settings');
assert_true(str_contains($js, 'mqtt_agent_restarted'), 'UI notes MQTT agent restart after save');
assert_true(str_contains($index, 'replacement robot') || str_contains($index, 'restarts the MQTT agent'), 'Settings hint about replacement robot');
assert_true(str_contains($change, '## [4.0.202]'), 'changelog 4.0.202');
assert_true(str_contains($change, 'MQTT not connected') || str_contains($change, 'Replacement Yarbo'), 'changelog mentions replacement / MQTT');

require $root . '/vendor/autoload.php';

use Yarbo\YarboMqttAgentClient;

$ref = new ReflectionClass(YarboMqttAgentClient::class);
assert_true($ref->hasMethod('restart'), 'restart method exists');
$method = $ref->getMethod('restart');
assert_true($method->getNumberOfParameters() >= 1, 'restart accepts waitForBroker');

echo "test_mqtt_config_restart.php ok\n";
