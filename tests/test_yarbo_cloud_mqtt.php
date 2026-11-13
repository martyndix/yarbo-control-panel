<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboErrors;
use Yarbo\YarboMqttAgentClient;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$py = $root . '/.venv/bin/python3';
if (!is_executable($py)) {
    $py = trim((string) shell_exec('command -v python3'));
}
assert_true($py !== '', 'python3 available');

$code = 1;
$out = [];
exec(escapeshellarg($py) . ' -c ' . escapeshellarg('import yarbo_robot_sdk') . ' 2>/dev/null', $out, $code);
assert_true($code === 0, 'yarbo_robot_sdk importable for cloud agent');

$agentPy = (string) file_get_contents($root . '/scripts/mqtt_agent.py');
assert_true(str_contains($agentPy, 'YarboCloudControlClient'), 'mqtt_agent uses cloud control client');
assert_true(str_contains($agentPy, 'yarbo-cloud'), 'ping engine is yarbo-cloud');
assert_true(!str_contains($agentPy, 'YarboLocalClient(broker='), 'mqtt_agent no longer constructs local broker client');

$cloudPy = (string) file_get_contents($root . '/scripts/yarbo_cloud_control.py');
assert_true(str_contains($cloudPy, 'class YarboCloudControlClient'), 'cloud control client module exists');
assert_true(str_contains($cloudPy, 'mqtt_publish_command'), 'cloud client publishes via SDK');
assert_true(str_contains($cloudPy, 'snowbot/'), 'cloud client can raw-publish app topics');
assert_true(str_contains($cloudPy, 'fetch_plan_feedback'), 'cloud client fetches plan progress');
assert_true(str_contains($cloudPy, '_on_data_feedback'), 'cloud client caches data_feedback progress');
assert_true(str_contains($agentPy, 'plan_feedback cloud poll'), 'agent polls cloud plan feedback');

$agentPhp = (string) file_get_contents($root . '/src/YarboMqttAgentClient.php');
assert_true(str_contains($agentPhp, 'import yarbo_robot_sdk'), 'PHP spawn requires official SDK');
assert_true(str_contains($agentPhp, 'yarbo-cloud'), 'PHP prefers yarbo-cloud engine');
assert_true(str_contains($agentPhp, 'Local PHP MQTT agent is retired'), 'PHP local fallback retired');

$settings = (string) file_get_contents($root . '/public/api/settings.php');
assert_true(str_contains($settings, 'Yarbo account email and password are required'), 'settings require cloud login');
assert_true(str_contains($settings, 'cloudCredChanged'), 'settings restart agent on cloud cred change');

$html = (string) file_get_contents($root . '/public/index.php');
assert_true(str_contains($html, 'cloud MQTT'), 'Settings copy mentions cloud MQTT');
assert_true(str_contains($html, 'id="settings-cloud-email"'), 'email field present');
assert_true(!str_contains($html, 'Broker IP (Yarbo host)'), 'broker IP label removed from Yarbo settings');
assert_true(!str_contains($html, 'id="map-data-source"'), 'map data-source dropdown removed');
assert_true(!str_contains($html, 'id="plans-data-source"'), 'plans data-source dropdown removed');
assert_true(!str_contains($html, 'Local MQTT only'), 'Local MQTT only option removed from UI');

$mapApi = (string) file_get_contents($root . '/public/api/map.php');
assert_true(str_contains($mapApi, 'load_map_cloud'), 'map API loads via cloud');
assert_true(!str_contains($mapApi, 'function load_map_local'), 'map API local loader removed');
assert_true(str_contains($mapApi, 'maps always load via cloud'), 'map API documents cloud-only');

$plansApi = (string) file_get_contents($root . '/public/api/plans.php');
assert_true(str_contains($plansApi, 'load_plans_cloud'), 'plans API loads via cloud');
assert_true(!str_contains($plansApi, 'function load_plans_local'), 'plans API local loader removed');
assert_true(str_contains($plansApi, 'plans always load via cloud'), 'plans API documents cloud-only');

$diag = (string) file_get_contents($root . '/public/api/diagnostics.php');
assert_true(str_contains($diag, 'transport') && str_contains($diag, 'cloud'), 'diagnostics are cloud-based');
assert_true(!str_contains($diag, 'probeTcp'), 'diagnostics no longer TCP-probe 1883');

$status = (string) file_get_contents($root . '/public/api/status.php');
assert_true(str_contains($status, 'yarbo-cloud'), 'status prefers yarbo-cloud agent');
assert_true(!str_contains($status, 'probeTcp'), 'status no longer TCP-probes local 1883');
assert_true(!str_contains($status, 'yarbo_client('), 'status no longer opens a direct local MQTT client');

assert_true(
    str_contains(YarboErrors::MSG_REFUSED, 'cloud MQTT')
    || str_contains(YarboErrors::MSG_REFUSED, 'account email'),
    'refused message points at cloud account'
);
assert_true(
    YarboErrors::friendly("No data_feedback response for 'get_device_msg' within 4.0s")
        === YarboErrors::MSG_TELEMETRY_TIMEOUT,
    'SDK get_device_msg timeout maps to friendly telemetry message'
);
assert_true(str_contains($cloudPy, 'except TimeoutError'), 'cloud client catches get_device_msg TimeoutError');
assert_true(str_contains($agentPy, 'No data_feedback response'), 'agent comments/handles SDK timeout');
assert_true(str_contains($agentPy, '"transient": True'), 'agent marks telemetry misses transient');
assert_true(str_contains($status, 'get_device_msg'), 'status treats get_device_msg as transient');
$js = (string) file_get_contents($root . '/public/assets/app.js');
assert_true(str_contains($js, 'no data_feedback response'), 'UI maps data_feedback timeout');

$client = new YarboMqttAgentClient();
$ping = $client->ping(0.2);
assert_true(is_array($ping), 'agent ping returns array even when down');

$change = (string) file_get_contents($root . '/CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.218]'), 'changelog 4.0.218');
assert_true(str_contains($change, '## [4.0.220]'), 'changelog 4.0.220');
assert_true(str_contains($change, '## [4.0.252]'), 'changelog 4.0.252');
assert_true(str_contains($change, 'cloud MQTT only'), 'changelog mentions cloud MQTT only');
assert_true(str_contains($change, 'closed LAN port'), 'changelog explains Yarbo closed local MQTT');
assert_true(str_contains($change, 'Map / Plans cloud-only UI'), 'changelog notes dropdown removal');

fwrite(STDOUT, "test_yarbo_cloud_mqtt.php ok\n");
