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
$unifi->setTransport(function (string $method, string $url, array $headers, ?string $body, float $timeout, bool $binary) use (&$calls): array {
    $calls[] = [$method, $url, $headers, $body];
    if (str_contains($url, '/cameras') && !str_contains($url, '/snapshot')) {
        return [
            'status' => 200,
            'body' => json_encode([['id' => 'cam1', 'name' => 'Driveway', 'isConnected' => true, 'type' => 'G5']]),
            'content_type' => 'application/json',
        ];
    }
    if (str_contains($url, '/lights')) {
        return [
            'status' => 200,
            'body' => json_encode(['data' => [['id' => 'light1', 'name' => 'Flood', 'lightMode' => 'off']]]),
            'content_type' => 'application/json',
        ];
    }
    if (str_contains($url, '/sensors') || str_contains($url, '/relays')) {
        return ['status' => 200, 'body' => '[]', 'content_type' => 'application/json'];
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
                    'door_position_status' => 'close',
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
    if ($method === 'PATCH' && str_contains($url, '/lights/')) {
        $decoded = json_decode((string) $body, true);
        if (($decoded['lightMode'] ?? '') !== 'on') {
            return ['status' => 400, 'body' => '', 'content_type' => '', 'error' => 'bad light'];
        }
        return ['status' => 200, 'body' => '{}', 'content_type' => 'application/json'];
    }

    return ['status' => 404, 'body' => '', 'content_type' => '', 'error' => 'unexpected ' . $url];
});

$probe = $unifi->probe();
if (!($probe['ok'] ?? false) || ($probe['counts']['cameras'] ?? 0) !== 1 || ($probe['counts']['doors'] ?? 0) !== 1) {
    fwrite(STDERR, 'probe ' . json_encode($probe) . "\n");
    exit(1);
}
if (($probe['counts']['hubs'] ?? 0) !== 1) {
    fwrite(STDERR, 'hubs ' . json_encode($probe['counts'] ?? []) . "\n");
    exit(1);
}
$probeIds = array_column($probe['devices'] ?? [], 'id');
if (!in_array('unifi:hub:7483c2773855', $probeIds, true) || !in_array('unifi:sensor:dps-door1', $probeIds, true)) {
    fwrite(STDERR, 'access extras ' . json_encode($probeIds) . "\n");
    exit(1);
}

$light = $unifi->command(['id' => 'unifi:light:light1', 'command' => 'on']);
if (!($light['ok'] ?? false) || empty($light['on'])) {
    fwrite(STDERR, 'light ' . json_encode($light) . "\n");
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

echo "ok: unifi\n";
