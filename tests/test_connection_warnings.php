<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHub;
use Yarbo\YarboLymow;
use Yarbo\YarboPowerwall;
use Yarbo\YarboUnifi;
use Yarbo\YarboVestaboard;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$rootFiles = dirname(__DIR__);
$html = (string) file_get_contents($rootFiles . '/public/index.php');
$js = (string) file_get_contents($rootFiles . '/public/assets/app.js');
$css = (string) file_get_contents($rootFiles . '/public/assets/style.css');
$change = (string) file_get_contents($rootFiles . '/CHANGELOG.md');

assert_true(str_contains($html, 'id="credential-banner"'), 'credential banner in index');
assert_true(str_contains($html, 'connectionWarnings()'), 'index renders warnings before JS/MQTT');
assert_true(str_contains($html, 'id="settings-powerwall-health"'), 'powerwall settings health');
assert_true(str_contains($html, 'id="settings-lymow-health"'), 'lymow settings health');
assert_true(str_contains($html, 'id="settings-unifi-health"'), 'unifi settings health');
assert_true(str_contains($html, 'id="settings-vestaboard-health"'), 'vestaboard settings health');
assert_true(str_contains($js, 'function applyConnectionWarnings'), 'JS applies connection warnings');
assert_true(str_contains($js, 'connection_warnings'), 'JS reads hub.connection_warnings');
assert_true(str_contains($css, '.settings-health-warn'), 'settings health warn colour');
assert_true(str_contains($change, '## [4.0.111]'), 'changelog 4.0.111');

assert_true(YarboHub::keepNonEmpty(['client_id' => ''], ['client_id'], 'abc-123') === 'abc-123', 'keepNonEmpty blank keeps');
assert_true(YarboHub::keepNonEmpty(['client_id' => ' new '], ['client_id'], 'abc-123') === 'new', 'keepNonEmpty writes');
assert_true(YarboHub::keepNonEmpty([], ['client_id'], 'abc-123') === 'abc-123', 'keepNonEmpty omitted keeps');
assert_true(YarboHub::looksLikeCredentialError('HTTP 401: unauthorized'), '401 is credential');
assert_true(YarboHub::looksLikeCredentialError('Tesla refused the refresh token'), 'refresh refused is credential');
assert_true(YarboHub::looksLikeCredentialError('invalid_grant'), 'invalid_grant is credential');
assert_true(!YarboHub::looksLikeCredentialError('Could not reach 192.168.1.1: timeout'), 'timeout is not credential');

$root = sys_get_temp_dir() . '/yarbo-conn-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);

$hub = new YarboHub($root);
$pw = new YarboPowerwall($root);
$ly = new YarboLymow($root);
$unifi = new YarboUnifi($root);
$board = new YarboVestaboard($root);

assert_true($pw->save([
    'powerwall_transport' => 'local',
    'powerwall_gateway_host' => '192.168.1.50',
    'powerwall_gateway_email' => 'owner@example.com',
    'powerwall_gateway_password' => 'abcde',
    'powerwall_client_id' => 'tesla-client',
    'powerwall_public_url' => 'https://panel.example.com',
    'powerwall_energy_site_id' => '12345',
]), 'powerwall first save');

assert_true($pw->save([
    'powerwall_transport' => 'local',
    'powerwall_gateway_host' => '',
    'powerwall_gateway_email' => '',
    'powerwall_gateway_password' => '',
    'powerwall_client_id' => '',
    'powerwall_public_url' => '',
    'powerwall_energy_site_id' => '',
    'powerwall_client_secret' => '',
]), 'powerwall blank save');

$kept = $pw->load();
assert_true($kept['gateway_host'] === '192.168.1.50', 'gateway host kept');
assert_true($kept['gateway_email'] === 'owner@example.com', 'gateway email kept');
assert_true($kept['gateway_password'] === 'abcde', 'gateway password kept');
assert_true($kept['client_id'] === 'tesla-client', 'client id kept');
assert_true($kept['public_panel_url'] === 'https://panel.example.com', 'public url kept');
assert_true($kept['energy_site_id'] === '12345', 'site id kept');
assert_true($pw->connectionWarning() === null, 'local powerwall with password has no warning');

assert_true($pw->save([
    'powerwall_transport' => 'cloud',
    'refresh_token' => 'rt-1',
    'powerwall_client_id' => 'tesla-client',
]), 'switch to cloud with token');
assert_true($pw->connectionWarning() === null, 'linked tesla cloud has no warning');

assert_true($pw->save(['powerwall_client_id' => '']), 'blank client id after link');
assert_true($pw->load()['client_id'] === 'tesla-client', 'linked client id still kept');

$pwMissing = new YarboPowerwall($root . '-pw-miss');
mkdir($root . '-pw-miss/data', 0775, true);
assert_true($pwMissing->save([
    'powerwall_transport' => 'local',
    'powerwall_gateway_host' => '',
    'powerwall_gateway_password' => '',
]), 'empty local save');
$warn = (string) $pwMissing->connectionWarning();
assert_true(str_contains($warn, 'Gateway IP') || str_contains($warn, 'sticker password'), 'missing gateway warns: ' . $warn);

$pwCloud = new YarboPowerwall($root . '-pw-cloud');
mkdir($root . '-pw-cloud/data', 0775, true);
assert_true($pwCloud->save(['powerwall_transport' => 'cloud']), 'empty cloud save');
$cloudWarn = (string) $pwCloud->connectionWarning();
assert_true(str_contains($cloudWarn, 'not linked'), 'unlinked tesla warns: ' . $cloudWarn);

assert_true($pwCloud->save([
    'powerwall_transport' => 'cloud',
    'refresh_token' => 'rt-keep',
    'powerwall_client_id' => 'cid',
]), 'cloud with token and client');
assert_true($pwCloud->save(['refresh_token' => '', 'powerwall_client_id' => '']), 'blank token/id keep');
assert_true($pwCloud->load()['refresh_token'] === 'rt-keep', 'refresh token kept');
assert_true($pwCloud->save(['last_error' => 'HTTP 401: unauthorized']), 'store 401');
$authWarn = (string) $pwCloud->connectionWarning();
assert_true(str_contains($authWarn, '401'), '401 last_error warns: ' . $authWarn);

$staleRoot = $root . '-pw-stale';
mkdir($staleRoot . '/data', 0775, true);
$stalePw = new YarboPowerwall($staleRoot);
assert_true($stalePw->save([
    'powerwall_transport' => 'local',
    'powerwall_gateway_host' => '192.168.1.50',
    'powerwall_gateway_password' => 'abcde',
    'last_error' => 'Gateway login did not return a token. Check the last-5 sticker password.',
]), 'stale powerwall config');
file_put_contents($stalePw->cachePath(), json_encode([
    'ok' => true,
    'online' => true,
    'source' => 'local',
    'battery_percent' => 40,
    'solar_w' => 0,
    'grid_w' => -8,
    'load_w' => 958,
    'fetched_at' => gmdate('c', time() - 18 * 3600),
], JSON_PRETTY_PRINT) . "\n");
$dash = $stalePw->dashboardPayload(false);
assert_true(empty($dash['online']), 'stale cache is not online');
assert_true(!empty($dash['stale']), 'stale cache flagged');
$layout = $stalePw->vestaboardLayout(false);
assert_true(empty($layout['online']), 'vestaboard layout not live');
assert_true(str_contains(implode("\n", $layout['lines'] ?? []), 'OFFLINE'), 'vestaboard shows OFFLINE not 40%');
assert_true(!str_contains(implode("\n", $layout['lines'] ?? []), '40%'), 'stale 40% not printed as live');
$staleWarn = (string) $stalePw->connectionWarning();
assert_true(str_contains($staleWarn, 'login did not') || str_contains($staleWarn, 'sticker'), 'stale auth error warns');

assert_true($ly->save([
    'lymow_email' => 'mow@example.com',
    'lymow_password' => 'secret',
]), 'lymow first save');
assert_true($ly->save(['lymow_email' => '', 'lymow_password' => '']), 'lymow blank save');
assert_true($ly->load()['email'] === 'mow@example.com', 'lymow email kept');
assert_true($ly->load()['password'] === 'secret', 'lymow password kept');
$lyWarn = (string) $ly->connectionWarning();
assert_true(str_contains($lyWarn, 'sign-in expired') || str_contains($lyWarn, 'not signed in'), 'lymow unsigned warns');

$lyEmpty = new YarboLymow($root . '-ly-empty');
mkdir($root . '-ly-empty/data', 0775, true);
$lyEmptyWarn = (string) $lyEmpty->connectionWarning();
assert_true(str_contains($lyEmptyWarn, 'not signed in'), 'lymow missing login warns');

assert_true($unifi->save([
    'unifi_host' => '192.168.1.1',
    'unifi_protect_api_key' => 'protect-secret',
    'unifi_protect_username' => 'admin',
    'unifi_access_token' => 'access-secret',
]), 'unifi first save');
assert_true($unifi->save([
    'unifi_host' => '',
    'unifi_protect_api_key' => '',
    'unifi_protect_username' => '',
    'unifi_access_token' => '',
]), 'unifi blank save');
$unifiKept = $unifi->load();
assert_true($unifiKept['host'] === '192.168.1.1', 'unifi host kept');
assert_true($unifiKept['protect_api_key'] === 'protect-secret', 'protect key kept');
assert_true($unifiKept['protect_username'] === 'admin', 'protect user kept');
assert_true($unifiKept['access_token'] === 'access-secret', 'access token kept');
assert_true($unifi->connectionWarning() === null, 'unifi with keys has no warning');

$unifiEmpty = new YarboUnifi($root . '-unifi-empty');
mkdir($root . '-unifi-empty/data', 0775, true);
$unifiWarn = (string) $unifiEmpty->connectionWarning();
assert_true(str_contains($unifiWarn, 'console host is missing'), 'unifi missing host warns');

assert_true($unifiEmpty->save(['unifi_host' => '192.168.1.1']), 'unifi host only');
$unifiKeyWarn = (string) $unifiEmpty->connectionWarning();
assert_true(str_contains($unifiKeyWarn, 'Protect API key') || str_contains($unifiKeyWarn, 'Access token'), 'unifi missing key warns');

assert_true($board->save(['enabled' => false, 'api_key' => '']), 'vestaboard off');
assert_true($board->connectionWarning() === null, 'disabled vestaboard no warning');
assert_true($board->save(['enabled' => true, 'transport' => 'local', 'api_key' => '']), 'vestaboard on no key');
$vbWarn = (string) $board->connectionWarning();
assert_true(str_contains($vbWarn, 'Local API key'), 'enabled vestaboard missing key warns: ' . $vbWarn);
assert_true($board->save(['enabled' => true, 'vestaboard_api_key' => 'note-key']), 'vestaboard key');
assert_true($board->save(['vestaboard_api_key' => '']), 'vestaboard blank key');
assert_true($board->load()['api_key'] === 'note-key', 'vestaboard key kept');
assert_true($board->connectionWarning() === null, 'vestaboard with key has no warning');
assert_true($board->save(['last_error' => 'HTTP 403: forbidden']), 'vestaboard 403');
$vbAuth = (string) $board->connectionWarning();
assert_true(str_contains($vbAuth, '403') || str_contains($vbAuth, 'forbidden'), 'vestaboard auth warns');

assert_true($hub->save([
    'modules' => [
        'yarbo' => true,
        'powerwall' => false,
        'lymow' => false,
        'home' => false,
        'unifi' => false,
    ],
]), 'hub yarbo only');
$none = $hub->connectionWarnings();
$ids = array_column($none, 'id');
assert_true(!in_array('powerwall', $ids, true), 'disabled powerwall omitted from hub warnings');
assert_true(!in_array('lymow', $ids, true), 'disabled lymow omitted');
assert_true(!in_array('unifi', $ids, true), 'disabled unifi omitted');

assert_true($hub->save([
    'modules' => [
        'yarbo' => true,
        'powerwall' => true,
        'lymow' => true,
        'home' => false,
        'unifi' => true,
    ],
]), 'hub enable credential modules');

$pwOffCloud = new YarboPowerwall($root);
assert_true($pwOffCloud->save([
    'powerwall_transport' => 'cloud',
    'refresh_token' => '',
    'access_token' => '',
    'last_error' => '',
]), 'unlink tesla on shared root (tokens keep — force by file)');
file_put_contents($pwOffCloud->configPath(), json_encode([
    'transport' => 'cloud',
    'gateway_host' => '',
    'gateway_email' => '',
    'gateway_password' => '',
    'region' => 'eu',
    'client_id' => '',
    'client_secret' => '',
    'refresh_token' => '',
    'access_token' => '',
    'access_expires_at' => 0,
    'energy_site_id' => '',
    'public_panel_url' => '',
    'last_error' => '',
], JSON_PRETTY_PRINT) . "\n");

$rows = $hub->connectionWarnings();
$byId = [];
foreach ($rows as $row) {
    $byId[$row['id']] = $row['message'];
}
assert_true(isset($byId['powerwall']), 'enabled powerwall listed: ' . json_encode($rows));
assert_true(isset($byId['lymow']), 'enabled lymow listed');
assert_true(!isset($byId['unifi']), 'unifi with keys omitted from hub warnings');
assert_true(isset($byId['vestaboard']), 'enabled vestaboard 403 listed');
$view = $hub->publicView();
assert_true(isset($view['connection_warnings']) && is_array($view['connection_warnings']), 'publicView includes warnings');
assert_true($view['connection_warnings'] !== [], 'publicView warnings not empty when modules fail');

echo "ok: connection warnings\n";
