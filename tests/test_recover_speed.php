<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

$script = $root . '/scripts/panel_recover_speed.sh';
$panelSh = (string) file_get_contents($root . '/scripts/panel.sh');
$updateSh = (string) file_get_contents($root . '/scripts/update.sh');
$change = (string) file_get_contents($root . '/CHANGELOG.md');
$docs = (string) file_get_contents($root . '/docs/unifi.md');

assert_true(is_file($script), 'panel_recover_speed.sh exists');
assert_true(is_executable($script) || (fileperms($script) & 0111) !== 0, 'recover script executable');

$body = (string) file_get_contents($script);
assert_true(str_contains($body, 'panel-airplay.log'), 'trims panel-airplay.log');
assert_true(str_contains($body, 'homepod-agent.log'), 'trims homepod-agent.log');
assert_true(str_contains($body, 'home.json') && str_contains($body, 'settings'), 'documents settings kept');
assert_true(str_contains($body, '--update'), 'supports --update');
assert_true(str_contains($body, 'update.sh'), '--update runs update.sh');
assert_true(!str_contains($body, 'rm -rf "${ROOT}/data"') && !str_contains($body, 'rm -f "${ROOT}/data/home.json"'), 'never deletes home.json / data tree');
assert_true(str_contains($body, 'pkill') && str_contains($body, 'shairport-sync'), 'stops shairport helpers');
assert_true(str_contains($body, 'playing') && str_contains($body, 'false'), 'clears stuck playing');

assert_true(str_contains($panelSh, 'panel_recover_speed.sh'), 'panel.sh runs recover on start');
assert_true(str_contains($updateSh, 'panel_recover_speed.sh'), 'update.sh runs recover before git');
assert_true(str_contains($change, '## [4.0.196]'), 'changelog 4.0.196');
assert_true(str_contains($change, 'panel_recover_speed.sh'), 'changelog names recover script');
assert_true(str_contains($docs, 'panel_recover_speed.sh') || str_contains($docs, 'too slow to update'), 'docs mention recover path');

// Functional: truncate fat logs, keep home.json and unifi-config.json.
$tmp = sys_get_temp_dir() . '/yarbo-recover-' . bin2hex(random_bytes(3));
mkdir($tmp . '/scripts', 0775, true);
mkdir($tmp . '/data/airplay', 0775, true);
mkdir($tmp . '/data/homepod', 0775, true);
copy($script, $tmp . '/scripts/panel_recover_speed.sh');
chmod($tmp . '/scripts/panel_recover_speed.sh', 0755);

$home = ['rooms' => [['id' => 'r1', 'name' => 'Kitchen', 'devices' => [['id' => 'd1', 'name' => 'Spot']]]]];
$unifi = ['host' => '192.168.1.1', 'api_key' => 'keep-me'];
file_put_contents($tmp . '/data/home.json', json_encode($home, JSON_PRETTY_PRINT) . "\n");
file_put_contents($tmp . '/data/unifi-config.json', json_encode($unifi, JSON_PRETTY_PRINT) . "\n");
file_put_contents($tmp . '/data/home-automations.json', "{\"rules\":[]}\n");
file_put_contents($tmp . '/data/panel-airplay.log', str_repeat("flood\n", 20000));
file_put_contents($tmp . '/data/homepod-agent.log', str_repeat("agent\n", 20000));
file_put_contents($tmp . '/data/unifi-radio-state.json', json_encode([
    'playing' => true,
    'station_id' => 'announce',
    'station_name' => 'Announcement',
    'volume' => 55,
    'targets' => ['sp1'],
    'streams' => [['pid' => 0]],
    'homepod_targets' => ['hp1'],
    'started_at' => time(),
    'ends_at' => null,
], JSON_UNESCAPED_SLASHES));
file_put_contents($tmp . '/data/airplay/session.json', json_encode([
    'active' => true,
    'client_name' => 'iPhone',
], JSON_UNESCAPED_SLASHES));

$cmd = 'bash ' . escapeshellarg($tmp . '/scripts/panel_recover_speed.sh');
exec($cmd . ' 2>&1', $out, $code);
assert_true($code === 0, 'recover exits 0: ' . implode("\n", $out));

$airplaySz = filesize($tmp . '/data/panel-airplay.log');
$agentSz = filesize($tmp . '/data/homepod-agent.log');
assert_true($airplaySz !== false && $airplaySz < 1024, 'panel-airplay.log truncated');
assert_true($agentSz !== false && $agentSz < 1024, 'homepod-agent.log truncated');

$homeAfter = json_decode((string) file_get_contents($tmp . '/data/home.json'), true);
$unifiAfter = json_decode((string) file_get_contents($tmp . '/data/unifi-config.json'), true);
$autoAfter = (string) file_get_contents($tmp . '/data/home-automations.json');
assert_true(($homeAfter['rooms'][0]['name'] ?? '') === 'Kitchen', 'home.json preserved');
assert_true(($unifiAfter['api_key'] ?? '') === 'keep-me', 'unifi-config.json preserved');
assert_true(str_contains($autoAfter, '"rules"'), 'home-automations.json preserved');

$radio = json_decode((string) file_get_contents($tmp . '/data/unifi-radio-state.json'), true);
assert_true(($radio['playing'] ?? true) === false, 'radio playing cleared');
assert_true(($radio['volume'] ?? 0) === 55, 'volume kept');
assert_true(($radio['targets'] ?? []) === ['sp1'], 'speaker targets kept');

$session = json_decode((string) file_get_contents($tmp . '/data/airplay/session.json'), true);
assert_true(($session['active'] ?? true) === false, 'airplay session cleared');

echo "ok: recover speed\n";
