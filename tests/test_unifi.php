<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$root = sys_get_temp_dir() . '/yarbo-unifi-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);

$unifi = new Yarbo\YarboUnifi($root);

if (Yarbo\YarboUnifi::homeId('camera', 'abc') !== 'unifi:camera:abc') {
    fwrite(STDERR, "homeId failed\n");
    exit(1);
}
$parsed = Yarbo\YarboUnifi::parseHomeId('unifi:door:xyz');
if (($parsed['kind'] ?? '') !== 'door' || ($parsed['native_id'] ?? '') !== 'xyz') {
    fwrite(STDERR, 'parse ' . json_encode($parsed) . "\n");
    exit(1);
}
if (Yarbo\YarboUnifi::parseHomeId('1:2') !== null) {
    fwrite(STDERR, "matter id parsed as unifi\n");
    exit(1);
}
if (Yarbo\YarboUnifi::normalizeHost('https://192.168.1.1/protect') !== '192.168.1.1') {
    fwrite(STDERR, "host normalize failed\n");
    exit(1);
}
if (Yarbo\YarboUnifi::unlockPath('door-1', 'open') !== '/doors/door-1/unlock?control_cmd=open') {
    fwrite(STDERR, "unlock path " . Yarbo\YarboUnifi::unlockPath('door-1', 'open') . "\n");
    exit(1);
}

if (Yarbo\YarboUnifi::flattenAccessItems([[['id' => 'a'], ['id' => 'b']], ['id' => 'c']]) !== [['id' => 'a'], ['id' => 'b'], ['id' => 'c']]) {
    fwrite(STDERR, "flattenAccessItems failed\n");
    exit(1);
}

if (!$unifi->save([
    'unifi_host' => '192.168.1.1',
    'unifi_protect_api_key' => 'protect-secret',
    'unifi_access_token' => 'access-secret',
    'show_on_home' => ['unifi:camera:cam1', 'not-a-unifi-id'],
])) {
    fwrite(STDERR, "save failed\n");
    exit(1);
}
$view = $unifi->publicView();
if ($view['host'] !== '192.168.1.1' || empty($view['protect_api_key_set']) || empty($view['access_token_set'])) {
    fwrite(STDERR, 'publicView ' . json_encode($view) . "\n");
    exit(1);
}
if (isset($view['protect_api_key']) || isset($view['access_token'])) {
    fwrite(STDERR, "secrets leaked in publicView\n");
    exit(1);
}
if (!is_array($view['devices'] ?? null)) {
    fwrite(STDERR, "publicView devices missing\n");
    exit(1);
}
if ($view['show_on_home'] !== ['unifi:camera:cam1']) {
    fwrite(STDERR, 'show_on_home ' . json_encode($view['show_on_home']) . "\n");
    exit(1);
}

$calls = [];
$doorDps = 'close';
$lightPrivateHtml = true;
$lightPublicFail = false;
$unifi->setTransport(function (string $method, string $url, array $headers, ?string $body, float $timeout, bool $binary) use (&$calls, &$doorDps, &$lightPrivateHtml, &$lightPublicFail): array {
    $calls[] = [$method, $url, $headers, $body];
    if (str_contains($url, '/cameras') && !str_contains($url, '/snapshot')) {
        return [
            'status' => 200,
            'body' => json_encode([['id' => 'cam1', 'name' => 'Driveway', 'isConnected' => true, 'type' => 'G5']]),
            'content_type' => 'application/json',
        ];
    }
    if ($method === 'PATCH' && str_contains($url, '/lights/')) {
        $decoded = json_decode((string) $body, true);
        $private = str_contains($url, '/proxy/protect/api/lights');
        if ($private) {
            if ($lightPrivateHtml) {
                return [
                    'status' => 200,
                    'body' => '<html><title>UniFi OS</title></html>',
                    'content_type' => 'text/html',
                ];
            }
            if (!is_array($decoded) || !array_key_exists('isLedForceOn', $decoded['lightOnSettings'] ?? [])) {
                return [
                    'status' => 400,
                    'body' => json_encode(['error' => 'need isLedForceOn']),
                    'content_type' => 'application/json',
                    'error' => 'bad private light',
                ];
            }
            $force = (bool) $decoded['lightOnSettings']['isLedForceOn'];
            return [
                'status' => 200,
                'body' => json_encode([
                    'id' => 'light1',
                    'name' => 'Flood',
                    'isLightOn' => $force,
                    'lightOnSettings' => ['isLedForceOn' => $force],
                    'isLightForceEnabled' => $force,
                    'lightModeSettings' => ['mode' => 'always'],
                ]),
                'content_type' => 'application/json',
            ];
        }
        if (!is_array($decoded) || array_key_exists('lightMode', $decoded) || !array_key_exists('isLightForceEnabled', $decoded) || $lightPublicFail) {
            return [
                'status' => 400,
                'body' => json_encode([
                    'error' => "Failed to parse 'request-body'",
                    'name' => 'AJV_PARSE_ERROR',
                    'entity' => 'request-body',
                    'issues' => [['instancePath' => '', 'message' => 'must NOT have additional properties']],
                ]),
                'content_type' => 'application/json',
                'error' => 'bad light',
            ];
        }
        $force = (bool) ($decoded['isLightForceEnabled'] ?? false);
        return [
            'status' => 200,
            'body' => json_encode([
                'id' => 'light1',
                'name' => 'Flood',
                'isLightOn' => $force,
                'isLightForceEnabled' => $force,
                'lightModeSettings' => ['mode' => 'always'],
            ]),
            'content_type' => 'application/json',
        ];
    }
    if (str_contains($url, '/lights')) {
        return [
            'status' => 200,
            'body' => json_encode(['data' => [[
                'id' => 'light1',
                'name' => 'Flood',
                'isLightOn' => false,
                'isLightForceEnabled' => false,
                'lightModeSettings' => ['mode' => 'always'],
            ]]]),
            'content_type' => 'application/json',
        ];
    }
    if (str_contains($url, '/sensors')) {
        return ['status' => 200, 'body' => '[]', 'content_type' => 'application/json'];
    }
    if ($method === 'POST' && str_contains($url, '/relays/') && str_contains($url, '/activate')) {
        $decoded = json_decode((string) $body, true);
        if (($decoded['state'] ?? '') !== 'on') {
            return ['status' => 400, 'body' => '', 'content_type' => '', 'error' => 'bad relay'];
        }
        if (!str_contains($url, '/relays/relay1/outputs/1/activate')) {
            return ['status' => 404, 'body' => '', 'content_type' => '', 'error' => 'bad relay path'];
        }
        return ['status' => 200, 'body' => '{}', 'content_type' => 'application/json'];
    }
    if (str_contains($url, '/relays')) {
        return [
            'status' => 200,
            'body' => json_encode([[
                'id' => 'relay1',
                'name' => 'Garage',
                'type' => 'UL-Relay',
                'state' => 'CONNECTED',
                'outputs' => [
                    ['id' => 1, 'name' => 'Gate', 'state' => 'off'],
                ],
            ]]),
            'content_type' => 'application/json',
        ];
    }
    if (str_contains($url, '/doors') && $method === 'GET') {
        return [
            'status' => 200,
            'body' => json_encode([
                'code' => 'SUCCESS',
                'data' => [[
                    'id' => 'door1',
                    'name' => 'Front',
                    'door_lock_relay_status' => 'lock',
                    'door_position_status' => $doorDps,
                    'is_bind_hub' => true,
                ]],
            ]),
            'content_type' => 'application/json',
        ];
    }
    if (str_contains($url, '/unlock')) {
        if ($method !== 'PUT' && $method !== 'POST') {
            return ['status' => 405, 'body' => '', 'content_type' => '', 'error' => 'method'];
        }
        if (!str_contains($url, 'control_cmd=open')) {
            return ['status' => 400, 'body' => '', 'content_type' => '', 'error' => 'missing control_cmd'];
        }
        return ['status' => 200, 'body' => json_encode(['code' => 'SUCCESS']), 'content_type' => 'application/json'];
    }
    if (str_contains($url, '/devices')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'code' => 'SUCCESS',
                'data' => [[
                    ['id' => '7483c2773855', 'name' => 'UA-HUB-3855', 'type' => 'UAH', 'location_id' => 'door1', 'full_name' => 'Front - UA-HUB-3855'],
                    ['id' => 'f492bfd28ced', 'name' => 'UA-LITE-8CED', 'type' => 'UDA-LITE'],
                ]],
            ]),
            'content_type' => 'application/json',
        ];
    }
    if (str_contains($url, '/snapshot')) {
        return ['status' => 200, 'body' => 'JFIF', 'content_type' => 'image/jpeg'];
    }

    return ['status' => 404, 'body' => '', 'content_type' => '', 'error' => 'unexpected ' . $url];
});

$probe = $unifi->probe();
if (!($probe['ok'] ?? false) || ($probe['counts']['cameras'] ?? 0) !== 1 || ($probe['counts']['doors'] ?? 0) !== 1) {
    fwrite(STDERR, 'probe ' . json_encode($probe) . "\n");
    exit(1);
}
if (($probe['counts']['hubs'] ?? 0) !== 1 || ($probe['counts']['relays'] ?? 0) !== 1) {
    fwrite(STDERR, 'hubs/relays ' . json_encode($probe['counts'] ?? []) . "\n");
    exit(1);
}
$probeIds = array_column($probe['devices'] ?? [], 'id');
if (!in_array('unifi:hub:7483c2773855', $probeIds, true) || !in_array('unifi:sensor:dps-door1', $probeIds, true) || !in_array('unifi:relay:relay1:1', $probeIds, true)) {
    fwrite(STDERR, 'access extras ' . json_encode($probeIds) . "\n");
    exit(1);
}
$hubRow = null;
$doorRow = null;
foreach ($probe['devices'] ?? [] as $row) {
    if (!is_array($row)) {
        continue;
    }
    if (($row['id'] ?? '') === 'unifi:hub:7483c2773855') {
        $hubRow = $row;
    }
    if (($row['id'] ?? '') === 'unifi:door:door1') {
        $doorRow = $row;
    }
}
if (($hubRow['dps_label'] ?? '') !== 'Closed' || ($doorRow['dps_label'] ?? '') !== 'Closed') {
    fwrite(STDERR, 'hub/door missing Closed DPS ' . json_encode([$hubRow, $doorRow]) . "\n");
    exit(1);
}
$doorDps = 'open';
$unifi->refreshAccessDoors();
$openedInv = json_decode((string) file_get_contents($root . '/data/unifi-inventory.json'), true);
$hubOpen = null;
$doorOpen = null;
foreach ($openedInv['hubs'] ?? [] as $row) {
    if (is_array($row) && ($row['id'] ?? '') === 'unifi:hub:7483c2773855') {
        $hubOpen = $row;
    }
}
foreach ($openedInv['doors'] ?? [] as $row) {
    if (is_array($row) && ($row['id'] ?? '') === 'unifi:door:door1') {
        $doorOpen = $row;
    }
}
if (($hubOpen['dps_label'] ?? '') !== 'Open' || ($hubOpen['open'] ?? false) !== true) {
    fwrite(STDERR, 'Access open did not reach the controller tile ' . json_encode($hubOpen) . "\n");
    exit(1);
}
if (($doorOpen['dps_label'] ?? '') !== 'Open') {
    fwrite(STDERR, 'Access open did not reach the door tile ' . json_encode($doorOpen) . "\n");
    exit(1);
}
$doorDps = 'opened';
$unifi->refreshAccessDoors();
$openedWord = json_decode((string) file_get_contents($root . '/data/unifi-inventory.json'), true);
$hubOpenedWord = null;
foreach ($openedWord['hubs'] ?? [] as $row) {
    if (is_array($row) && ($row['id'] ?? '') === 'unifi:hub:7483c2773855') {
        $hubOpenedWord = $row;
        break;
    }
}
if (($hubOpenedWord['dps_label'] ?? '') !== 'Open') {
    fwrite(STDERR, 'opened must map to Open ' . json_encode($hubOpenedWord) . "\n");
    exit(1);
}
$doorDps = 'close';
$floodRow = null;
foreach ($probe['devices'] ?? [] as $row) {
    if (is_array($row) && ($row['id'] ?? '') === 'unifi:light:light1') {
        $floodRow = $row;
        break;
    }
}
if (($floodRow['on'] ?? true) !== false || ($floodRow['status'] ?? '') !== 'Off') {
    fwrite(STDERR, 'schedule always must not count as On ' . json_encode($floodRow) . "\n");
    exit(1);
}

$light = $unifi->command(['id' => 'unifi:light:light1', 'command' => 'on']);
if (!($light['ok'] ?? false) || empty($light['on'])) {
    fwrite(STDERR, 'light ' . json_encode($light) . "\n");
    exit(1);
}
$lightPatched = false;
$lightForceLed = false;
$firstJsonLight = true;
foreach ($calls as $call) {
    if (($call[0] ?? '') !== 'PATCH' || !str_contains((string) ($call[1] ?? ''), '/lights/')) {
        continue;
    }
    $decoded = json_decode((string) ($call[3] ?? ''), true);
    $url = (string) ($call[1] ?? '');
    if (isset($decoded['lightModeSettings']) || array_key_exists('lightMode', $decoded ?: [])) {
        fwrite(STDERR, "light PATCH must not set the schedule " . json_encode($decoded) . "\n");
        exit(1);
    }
    if (($decoded['isLightForceEnabled'] ?? null) === true && !isset($decoded['lightModeSettings'])) {
        $lightPatched = true;
    }
    if ($firstJsonLight) {
        $firstJsonLight = false;
        if (str_contains($url, '/proxy/protect/integration/v1/lights')
            && ($decoded['isLightForceEnabled'] ?? null) === true
            && (int) ($decoded['lightDeviceSettings']['ledLevel'] ?? 0) === 6) {
            $lightForceLed = true;
        }
    }
}
if (!$lightPatched) {
    fwrite(STDERR, "light did not force the LED\n");
    exit(1);
}
if (!$lightForceLed) {
    fwrite(STDERR, "light did not PATCH public isLightForceEnabled with ledLevel first\n");
    exit(1);
}

$lightOff = $unifi->command(['id' => 'unifi:light:light1', 'command' => 'off']);
if (!($lightOff['ok'] ?? false) || !array_key_exists('on', $lightOff) || !empty($lightOff['on'])) {
    fwrite(STDERR, 'light off ' . json_encode($lightOff) . "\n");
    exit(1);
}
$lightForceOff = false;
foreach (array_reverse($calls) as $call) {
    if (($call[0] ?? '') !== 'PATCH' || !str_contains((string) ($call[1] ?? ''), '/lights/')) {
        continue;
    }
    $decoded = json_decode((string) ($call[3] ?? ''), true);
    $lightForceOff = (($decoded['lightOnSettings']['isLedForceOn'] ?? null) === false
        || ($decoded['isLightForceEnabled'] ?? null) === false)
        && !isset($decoded['lightModeSettings']);
    break;
}
if (!$lightForceOff) {
    fwrite(STDERR, "light off did not force the LED off\n");
    exit(1);
}

$lightPublicFail = true;
$lightPrivateHtml = true;
$htmlTrap = $unifi->command(['id' => 'unifi:light:light1', 'command' => 'on']);
if ($htmlTrap['ok'] ?? false) {
    fwrite(STDERR, "HTML login page must not count as floodlight on " . json_encode($htmlTrap) . "\n");
    exit(1);
}
$lightPrivateHtml = false;
$privateFallback = $unifi->command(['id' => 'unifi:light:light1', 'command' => 'on']);
if (!($privateFallback['ok'] ?? false) || empty($privateFallback['on'])) {
    fwrite(STDERR, 'private JSON fallback ' . json_encode($privateFallback) . "\n");
    exit(1);
}
$usedPrivateJson = false;
foreach (array_reverse($calls) as $call) {
    if (($call[0] ?? '') !== 'PATCH' || !str_contains((string) ($call[1] ?? ''), '/proxy/protect/api/lights')) {
        continue;
    }
    $decoded = json_decode((string) ($call[3] ?? ''), true);
    $usedPrivateJson = ($decoded['lightOnSettings']['isLedForceOn'] ?? null) === true;
    break;
}
if (!$usedPrivateJson) {
    fwrite(STDERR, "public fail should fall back to private isLedForceOn\n");
    exit(1);
}
$lightPublicFail = false;
$lightPrivateHtml = true;

$relay = $unifi->command(['id' => 'unifi:relay:relay1:1', 'command' => 'on']);
if (!($relay['ok'] ?? false) || empty($relay['on'])) {
    fwrite(STDERR, 'relay ' . json_encode($relay) . "\n");
    exit(1);
}
$relayPosted = false;
foreach ($calls as $call) {
    if (($call[0] ?? '') === 'POST' && str_contains((string) ($call[1] ?? ''), '/relays/relay1/outputs/1/activate')) {
        $relayPosted = true;
        break;
    }
}
if (!$relayPosted) {
    fwrite(STDERR, "relay did not POST activate\n");
    exit(1);
}

if (!$unifi->setShowOnHome('unifi:light:light1', true) || !$unifi->setShowOnHome('unifi:relay:relay1:1', true)) {
    fwrite(STDERR, "show on home after command failed\n");
    exit(1);
}
$lightOnAgain = $unifi->command(['id' => 'unifi:light:light1', 'command' => 'on']);
if (!($lightOnAgain['ok'] ?? false) || empty($lightOnAgain['on'])) {
    fwrite(STDERR, 'light on again ' . json_encode($lightOnAgain) . "\n");
    exit(1);
}
$homeRows = [];
foreach ($unifi->homeRows() as $row) {
    if (is_array($row) && isset($row['id'])) {
        $homeRows[$row['id']] = $row;
    }
}
if (($homeRows['unifi:light:light1']['on'] ?? false) !== true) {
    fwrite(STDERR, 'homeRows light after on ' . json_encode($homeRows) . "\n");
    exit(1);
}
if (($homeRows['unifi:relay:relay1:1']['on'] ?? false) !== true) {
    fwrite(STDERR, 'homeRows relay after on ' . json_encode($homeRows) . "\n");
    exit(1);
}
$liveLights = [];
foreach ($unifi->dashboardPayload(true)['lights'] ?? [] as $row) {
    if (is_array($row) && isset($row['id'])) {
        $liveLights[$row['id']] = $row;
    }
}
if (($liveLights['unifi:light:light1']['on'] ?? false) !== true) {
    fwrite(STDERR, 'refresh light after on ' . json_encode($liveLights) . "\n");
    exit(1);
}
if (!$unifi->setShowOnHome('unifi:hub:7483c2773855', true)) {
    fwrite(STDERR, "show hub on home failed\n");
    exit(1);
}
$callsBeforeHome = count($calls);
$unifi->homeRows();
$homePolledDoors = false;
foreach (array_slice($calls, $callsBeforeHome) as $call) {
    if (($call[0] ?? '') === 'GET' && str_contains((string) ($call[1] ?? ''), '/doors')) {
        $homePolledDoors = true;
        break;
    }
}
if ($homePolledDoors) {
    fwrite(STDERR, "homeRows must not poll Access doors (that blocks website light commands)\n");
    exit(1);
}

$unlock = $unifi->unlockDoor('door1', 'open');
if (!($unlock['ok'] ?? false)) {
    fwrite(STDERR, 'unlock ' . json_encode($unlock) . "\n");
    exit(1);
}
$putUnlock = false;
foreach ($calls as $call) {
    if (($call[0] ?? '') === 'PUT' && str_contains((string) ($call[1] ?? ''), '/unlock')) {
        $putUnlock = true;
        break;
    }
}
if (!$putUnlock) {
    fwrite(STDERR, "unlock did not PUT\n");
    exit(1);
}

$hubUnlock = $unifi->command(['id' => 'unifi:hub:7483c2773855', 'command' => 'unlock', 'control_cmd' => 'open']);
if (!($hubUnlock['ok'] ?? false)) {
    fwrite(STDERR, 'hub unlock ' . json_encode($hubUnlock) . "\n");
    exit(1);
}

$snap = $unifi->snapshotJpeg('cam1');
if ($snap !== 'JFIF') {
    fwrite(STDERR, "snapshot $snap\n");
    exit(1);
}

$unifi->setShowOnHome('unifi:light:light1', true);
$rows = $unifi->homeRows();
$ids = array_column($rows, 'id');
if (!in_array('unifi:camera:cam1', $ids, true) || !in_array('unifi:light:light1', $ids, true)) {
    fwrite(STDERR, 'homeRows ' . json_encode($ids) . "\n");
    exit(1);
}

$hub = new Yarbo\YarboHub($root);
if (!$hub->save(['modules' => ['yarbo' => false, 'home' => true, 'unifi' => true, 'powerwall' => false, 'lymow' => false]])) {
    fwrite(STDERR, "hub save failed\n");
    exit(1);
}
$loaded = $hub->load();
if (empty($loaded['modules']['unifi']) || empty($loaded['modules']['home'])) {
    fwrite(STDERR, 'hub modules ' . json_encode($loaded['modules']) . "\n");
    exit(1);
}

$home = new Yarbo\YarboHome($root);
$dash = $home->dashboard();
$homeIds = array_column($dash['devices'] ?? [], 'id');
if (!in_array('unifi:camera:cam1', $homeIds, true) || !in_array('unifi:light:light1', $homeIds, true)) {
    fwrite(STDERR, 'home dash ' . json_encode($homeIds) . json_encode($dash) . "\n");
    exit(1);
}

$hide = $home->hideDevice('unifi:camera:cam1', true);
if (!($hide['ok'] ?? false)) {
    fwrite(STDERR, 'hide ' . json_encode($hide) . "\n");
    exit(1);
}
$afterHide = array_column($home->dashboard()['devices'] ?? [], 'id');
if (in_array('unifi:camera:cam1', $afterHide, true)) {
    fwrite(STDERR, 'camera stayed after hide ' . json_encode($afterHide) . "\n");
    exit(1);
}

$accessRoot = sys_get_temp_dir() . '/yarbo-access-' . bin2hex(random_bytes(3));
mkdir($accessRoot . '/data', 0775, true);
$access = new Yarbo\YarboUnifi($accessRoot);
if (!$access->save([
    'unifi_host' => '192.168.1.1',
    'unifi_protect_api_key' => 'protect-secret',
    'unifi_access_token' => 'access-secret',
])) {
    fwrite(STDERR, "access save failed\n");
    exit(1);
}
$accessCalls = [];
$access->setTransport(function (string $method, string $url, array $headers, ?string $body, float $timeout, bool $binary) use (&$accessCalls): array {
    $accessCalls[] = [$method, $url];
    if (str_contains($url, '/proxy/protect/')) {
        return ['status' => 200, 'body' => '[]', 'content_type' => 'application/json'];
    }
    if (str_contains($url, '/proxy/access/')) {
        return ['status' => 404, 'body' => '', 'content_type' => '', 'error' => 'Access HTTP 404'];
    }
    if (str_contains($url, ':12445/') && str_contains($url, '/doors') && $method === 'GET') {
        return [
            'status' => 200,
            'body' => json_encode([
                'code' => 'SUCCESS',
                'data' => [['id' => 'door1', 'name' => 'Front', 'door_lock_relay_status' => 'lock']],
            ]),
            'content_type' => 'application/json',
        ];
    }
    if (str_contains($url, ':12445/') && str_contains($url, '/devices')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'code' => 'SUCCESS',
                'data' => [[['id' => 'hub1', 'name' => 'UA-HUB-1', 'type' => 'UAH', 'location_id' => 'door1']]],
            ]),
            'content_type' => 'application/json',
        ];
    }
    if (str_contains($url, ':12445/') && str_contains($url, '/unlock')) {
        return ['status' => 200, 'body' => json_encode(['code' => 'SUCCESS']), 'content_type' => 'application/json'];
    }

    return ['status' => 404, 'body' => '', 'content_type' => '', 'error' => 'unexpected ' . $url];
});
$accessProbe = $access->probe();
if (!($accessProbe['ok'] ?? false) || ($accessProbe['counts']['doors'] ?? 0) !== 1 || ($accessProbe['counts']['hubs'] ?? 0) !== 1) {
    fwrite(STDERR, '12445 probe ' . json_encode($accessProbe) . "\n");
    exit(1);
}
$usedStandalone = false;
foreach ($accessCalls as $call) {
    if (str_contains((string) ($call[1] ?? ''), ':12445/')) {
        $usedStandalone = true;
        break;
    }
}
if (!$usedStandalone) {
    fwrite(STDERR, "Access did not fall back to :12445\n");
    exit(1);
}
$accessUnlock = $access->unlockDoor('door1');
if (!($accessUnlock['ok'] ?? false)) {
    fwrite(STDERR, '12445 unlock ' . json_encode($accessUnlock) . "\n");
    exit(1);
}

$hintRoot = sys_get_temp_dir() . '/yarbo-access-hint-' . bin2hex(random_bytes(3));
mkdir($hintRoot . '/data', 0775, true);
$hint = new Yarbo\YarboUnifi($hintRoot);
$hint->save([
    'unifi_host' => '192.168.1.1',
    'unifi_protect_api_key' => 'protect-secret',
]);
$hint->setTransport(function (string $method, string $url) {
    if (str_contains($url, '/proxy/protect/')) {
        return [
            'status' => 200,
            'body' => json_encode([['id' => 'cam1', 'name' => 'Driveway', 'isConnected' => true]]),
            'content_type' => 'application/json',
        ];
    }

    return ['status' => 404, 'body' => '', 'content_type' => '', 'error' => 'no access'];
});
$hintProbe = $hint->probe();
if (!str_contains((string) ($hintProbe['message'] ?? ''), 'Access API token')) {
    fwrite(STDERR, 'missing Access token hint ' . json_encode($hintProbe) . "\n");
    exit(1);
}

$deniedBody = json_encode([
    'code' => 'CODE_UNAUTHORIZED',
    'msg' => 'You do not have permission to perform this action.',
]);
$permRoot = sys_get_temp_dir() . '/yarbo-access-perm-' . bin2hex(random_bytes(3));
mkdir($permRoot . '/data', 0775, true);
$perm = new Yarbo\YarboUnifi($permRoot);
$perm->save([
    'unifi_host' => '192.168.1.1',
    'unifi_protect_api_key' => 'protect-secret',
    'unifi_access_token' => 'access-no-scope',
]);
$perm->setTransport(function (string $method, string $url, array $headers) use ($deniedBody): array {
    if (str_contains($url, '/proxy/protect/')) {
        return [
            'status' => 200,
            'body' => json_encode([['id' => 'cam1', 'name' => 'Driveway', 'isConnected' => true]]),
            'content_type' => 'application/json',
        ];
    }
    $key = (string) ($headers['X-API-KEY'] ?? '');
    if ($key !== '' && (str_contains($url, '/doors') || str_contains($url, '/devices'))) {
        $data = str_contains($url, '/devices')
            ? [[['id' => 'hub1', 'name' => 'UA-HUB-1', 'type' => 'UAH', 'location_id' => 'door1']]]
            : [['id' => 'door1', 'name' => 'Front', 'door_lock_relay_status' => 'lock']];

        return [
            'status' => 200,
            'body' => json_encode(['code' => 'SUCCESS', 'data' => $data]),
            'content_type' => 'application/json',
        ];
    }

    return ['status' => 200, 'body' => $deniedBody, 'content_type' => 'application/json'];
});
$permProbe = $perm->probe();
if (($permProbe['counts']['doors'] ?? 0) !== 1 || ($permProbe['counts']['hubs'] ?? 0) !== 1) {
    fwrite(STDERR, 'X-API-KEY Access fallback ' . json_encode($permProbe) . "\n");
    exit(1);
}

$deniedRoot = sys_get_temp_dir() . '/yarbo-access-denied-' . bin2hex(random_bytes(3));
mkdir($deniedRoot . '/data', 0775, true);
$denied = new Yarbo\YarboUnifi($deniedRoot);
$denied->save([
    'unifi_host' => '192.168.1.1',
    'unifi_protect_api_key' => 'protect-secret',
    'unifi_access_token' => 'access-no-scope',
]);
$denied->setTransport(function (string $method, string $url) use ($deniedBody): array {
    if (str_contains($url, '/proxy/protect/')) {
        return [
            'status' => 200,
            'body' => json_encode([['id' => 'cam1', 'name' => 'Driveway', 'isConnected' => true]]),
            'content_type' => 'application/json',
        ];
    }

    return ['status' => 200, 'body' => $deniedBody, 'content_type' => 'application/json'];
});
$deniedProbe = $denied->probe();
$deniedMsg = (string) ($deniedProbe['message'] ?? '');
if (substr_count(strtolower($deniedMsg), 'you do not have permission') > 1) {
    fwrite(STDERR, "permission error not unique $deniedMsg\n");
    exit(1);
}
if (!str_contains($deniedMsg, 'view:space') || !str_contains($deniedMsg, 'view:device')) {
    fwrite(STDERR, 'missing Access scope hint ' . json_encode($deniedProbe) . "\n");
    exit(1);
}

$nomanBody = json_encode([
    'code' => 404,
    'codeS' => 'CODE_NOT_FOUND',
    'msg' => 'The API was not found.',
    'error' => 'you entered no-man zone',
]);
$nomanRoot = sys_get_temp_dir() . '/yarbo-access-noman-' . bin2hex(random_bytes(3));
mkdir($nomanRoot . '/data', 0775, true);
$noman = new Yarbo\YarboUnifi($nomanRoot);
$noman->save([
    'unifi_host' => '192.168.1.1',
    'unifi_protect_api_key' => 'same-console-key',
    'unifi_access_token' => 'same-console-key',
]);
$nomanCalls = [];
$noman->setTransport(function (string $method, string $url, array $headers) use ($nomanBody, &$nomanCalls): array {
    $nomanCalls[] = [$url, $headers];
    if (str_contains($url, '/proxy/protect/')) {
        return [
            'status' => 200,
            'body' => json_encode([['id' => 'cam1', 'name' => 'Driveway', 'isConnected' => true]]),
            'content_type' => 'application/json',
        ];
    }

    return ['status' => 404, 'body' => $nomanBody, 'content_type' => 'application/json', 'error' => 'you entered no-man zone'];
});
$nomanProbe = $noman->probe();
$nomanMsg = (string) ($nomanProbe['message'] ?? '');
if (($nomanProbe['counts']['doors'] ?? 1) !== 0 || ($nomanProbe['counts']['hubs'] ?? 1) !== 0) {
    fwrite(STDERR, 'same-key no-man still listed doors ' . json_encode($nomanProbe) . "\n");
    exit(1);
}
if (!str_contains($nomanMsg, 'Control Plane') || !str_contains($nomanMsg, 'inside the Access app')) {
    fwrite(STDERR, 'missing same-key Access hint ' . json_encode($nomanProbe) . "\n");
    exit(1);
}
if (substr_count(strtolower($nomanMsg), 'no-man zone') > 1) {
    fwrite(STDERR, "no-man zone not unique $nomanMsg\n");
    exit(1);
}
$nomanFirstAccess = '';
foreach ($nomanCalls as $call) {
    if (str_contains((string) ($call[0] ?? ''), '/access/') || str_contains((string) ($call[0] ?? ''), ':12445/')) {
        $nomanFirstAccess = (string) $call[0];
        $nomanFirstHeaders = is_array($call[1] ?? null) ? $call[1] : [];
        if (!str_contains($nomanFirstAccess, '/proxy/access/integration/') || !isset($nomanFirstHeaders['X-API-KEY'])) {
            fwrite(STDERR, "same-key Access should try proxy X-API-KEY first, got $nomanFirstAccess " . json_encode($nomanFirstHeaders) . "\n");
            exit(1);
        }
        break;
    }
}

$sameKeyOkRoot = sys_get_temp_dir() . '/yarbo-access-samekey-ok-' . bin2hex(random_bytes(3));
mkdir($sameKeyOkRoot . '/data', 0775, true);
$sameKeyOk = new Yarbo\YarboUnifi($sameKeyOkRoot);
$sameKeyOk->save([
    'unifi_host' => '192.168.1.1',
    'unifi_protect_api_key' => 'same-console-key',
    'unifi_access_token' => 'same-console-key',
]);
$sameKeyOk->setTransport(function (string $method, string $url, array $headers) use ($nomanBody): array {
    if (str_contains($url, '/proxy/protect/')) {
        return [
            'status' => 200,
            'body' => json_encode([['id' => 'cam1', 'name' => 'Driveway', 'isConnected' => true]]),
            'content_type' => 'application/json',
        ];
    }
    if (str_contains($url, ':12445/')) {
        return ['status' => 404, 'body' => $nomanBody, 'content_type' => 'application/json', 'error' => 'you entered no-man zone'];
    }
    if (($headers['X-API-KEY'] ?? '') === 'same-console-key' && str_contains($url, '/proxy/access/')) {
        $data = str_contains($url, '/devices')
            ? [[['id' => 'hub1', 'name' => 'UA-HUB-1', 'type' => 'UAH', 'location_id' => 'door1']]]
            : [['id' => 'door1', 'name' => 'Front', 'door_lock_relay_status' => 'lock']];

        return [
            'status' => 200,
            'body' => json_encode(['code' => 'SUCCESS', 'data' => $data]),
            'content_type' => 'application/json',
        ];
    }

    return ['status' => 404, 'body' => $nomanBody, 'content_type' => 'application/json', 'error' => 'you entered no-man zone'];
});
$sameKeyOkProbe = $sameKeyOk->probe();
if (($sameKeyOkProbe['counts']['doors'] ?? 0) !== 1 || ($sameKeyOkProbe['counts']['hubs'] ?? 0) !== 1) {
    fwrite(STDERR, 'same-key proxy X-API-KEY should list doors ' . json_encode($sameKeyOkProbe) . "\n");
    exit(1);
}

$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/style.css');
if (!str_contains($js, 'data-home-dps') || !str_contains($js, 'data-unifi-dps') || !str_contains($js, 'unifiDpsMetaHtml')) {
    fwrite(STDERR, "door position meta missing from controller actions\n");
    exit(1);
}
if (!str_contains($css, '.home-device-actions .home-device-meta')) {
    fwrite(STDERR, "actions-box meta CSS missing\n");
    exit(1);
}

echo "ok: unifi\n";
