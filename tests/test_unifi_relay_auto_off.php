<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboUnifi;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-unifi-auto-off-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);

$relayId = 'unifi:relay:usl1:0';
$unifi = new YarboUnifi($root);
assert_true($unifi->save([
    'unifi_host' => '192.168.1.50',
    'unifi_protect_api_key' => 'protect-secret',
]), 'save base config');

assert_true($unifi->autoOffSec($relayId) === 0, 'default auto-off is off');
assert_true($unifi->setAutoOff($relayId, 300), 'can enable 5 min auto-off');
assert_true($unifi->autoOffSec($relayId) === 300, 'auto-off stores seconds');
assert_true(($unifi->autoOffMap()[$relayId] ?? 0) === 300, 'auto-off map includes relay');
assert_true(!$unifi->setAutoOff('unifi:light:flood1', 60), 'lights cannot use relay auto-off');
assert_true(!$unifi->setAutoOff('1:2', 60), 'Matter ids rejected');

$view = $unifi->publicView();
assert_true(($view['auto_off_sec'][$relayId] ?? 0) === 300, 'publicView exposes auto_off_sec');

file_put_contents($root . '/data/unifi-inventory.json', json_encode([
    'cameras' => [],
    'lights' => [],
    'sensors' => [],
    'doors' => [],
    'hubs' => [],
    'relays' => [[
        'id' => $relayId,
        'native_id' => 'usl1:0',
        'name' => 'Workshop plug',
        'kind' => 'relay',
        'source' => 'unifi',
        'on' => false,
        'status' => 'Off',
    ]],
    'errors' => [],
], JSON_UNESCAPED_SLASHES));

$catalog = $unifi->catalogFromInventory(
    json_decode((string) file_get_contents($root . '/data/unifi-inventory.json'), true),
    []
);
$found = null;
foreach ($catalog as $row) {
    if (($row['id'] ?? '') === $relayId) {
        $found = $row;
        break;
    }
}
assert_true(is_array($found), 'catalog includes relay');
assert_true(($found['auto_off_sec'] ?? 0) === 300, 'catalog attaches auto_off_sec');

$posted = [];
$unifi->setTransport(function (string $method, string $url, array $headers, ?string $body) use (&$posted): array {
    if ($method === 'POST' && str_contains($url, '/relays/') && str_contains($url, '/activate')) {
        $posted[] = ['url' => $url, 'body' => $body];
        return ['status' => 200, 'body' => '{}', 'content_type' => 'application/json'];
    }

    return ['status' => 404, 'body' => '', 'content_type' => 'text/plain', 'error' => 'unexpected'];
});

$on = $unifi->command(['id' => $relayId, 'command' => 'on']);
assert_true(($on['ok'] ?? false) === true, 'relay on succeeds');
$state = json_decode((string) file_get_contents($unifi->autoOffStatePath()), true);
assert_true(isset($state['pending'][$relayId]['at']), 'turning on schedules auto-off');
$at = (int) $state['pending'][$relayId]['at'];
assert_true($at > time(), 'auto-off is in the future');
assert_true((int) ($state['pending'][$relayId]['sec'] ?? 0) === 300, 'pending remembers duration');

$again = $unifi->command(['id' => $relayId, 'command' => 'on']);
assert_true(($again['ok'] ?? false) === true, 'second on restarts timer');
$state2 = json_decode((string) file_get_contents($unifi->autoOffStatePath()), true);
assert_true((int) ($state2['pending'][$relayId]['at'] ?? 0) >= $at, 're-on does not move timer backwards');

$off = $unifi->command(['id' => $relayId, 'command' => 'off']);
assert_true(($off['ok'] ?? false) === true, 'manual off succeeds');
$state3 = json_decode((string) file_get_contents($unifi->autoOffStatePath()), true);
assert_true(empty($state3['pending'][$relayId] ?? null), 'manual off cancels pending auto-off');

$unifi->command(['id' => $relayId, 'command' => 'on']);
$state4 = json_decode((string) file_get_contents($unifi->autoOffStatePath()), true);
$state4['pending'][$relayId]['at'] = time() - 1;
file_put_contents($unifi->autoOffStatePath(), json_encode($state4, JSON_UNESCAPED_SLASHES));
$posted = [];
$flushed = $unifi->flushAutoOff(time());
assert_true($flushed === [$relayId], 'flush turns due relay off');
assert_true(count($posted) === 1, 'flush POSTs activate off');
$body = json_decode((string) ($posted[0]['body'] ?? ''), true);
assert_true(($body['state'] ?? '') === 'off', 'flush sends state off');
$state5 = json_decode((string) file_get_contents($unifi->autoOffStatePath()), true);
assert_true(empty($state5['pending'][$relayId] ?? null), 'flush clears pending');

assert_true($unifi->setAutoOff($relayId, 0), 'can clear auto-off');
assert_true($unifi->autoOffSec($relayId) === 0, 'cleared auto-off is 0');
$unifi->command(['id' => $relayId, 'command' => 'on']);
$state6 = json_decode((string) file_get_contents($unifi->autoOffStatePath()), true);
assert_true(empty($state6['pending'][$relayId] ?? null), 'no schedule when auto-off disabled');

assert_true(YarboUnifi::clampAutoOffSec(0) === 0, 'clamp 0');
assert_true(YarboUnifi::clampAutoOffSec(90) === 90, 'clamp keeps mid value');
assert_true(YarboUnifi::clampAutoOffSec(100000) === 86400, 'clamp caps at 24h');

$api = (string) file_get_contents(dirname(__DIR__) . '/public/api/unifi.php');
$homeApi = (string) file_get_contents(dirname(__DIR__) . '/public/api/home.php');
$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/style.css');
$html = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$change = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
assert_true(str_contains($api, "action === 'auto_off'"), 'API has auto_off action');
assert_true(str_contains($homeApi, 'flushAutoOff'), 'Home GET flushes relay auto-off');
assert_true(str_contains($js, 'function unifiAutoOffHtml'), 'JS builds relay auto-off controls');
assert_true(str_contains($js, "action: 'auto_off'"), 'JS saves auto-off');
assert_true(str_contains($js, 'data-unifi-auto-off-enabled'), 'JS marks auto-off checkbox');
assert_true(str_contains($css, '.unifi-pick-auto-off'), 'auto-off has CSS');
assert_true(str_contains($html, 'auto-off'), 'Settings hint mentions auto-off');
assert_true(str_contains($change, '## [4.0.219]'), 'changelog 4.0.219');

fwrite(STDOUT, "test_unifi_relay_auto_off.php ok\n");
