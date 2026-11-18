<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHisense;
use Yarbo\YarboHomeAutomations;
use Yarbo\YarboHub;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$creds = YarboHisense::dynamicCredentials('56:b8:88:4e:f7:19', 1766974704);
assert_true($creds['client_id'] === '56:b8:88:4e:f7:19$his$256DBF_vidaacommon_001', 'client id ' . $creds['client_id']);
assert_true($creds['username'] === 'his$6239759786168176024', 'user ' . $creds['username']);
assert_true($creds['password'] === 'C3BA44782E18ABF4892AC44D79A622D2', 'password ' . $creds['password']);

$sources = YarboHisense::parseSources([
    'sources' => [
        ['sourceid' => '1', 'displayname' => 'Home'],
        ['sourceid' => '2', 'sourcename' => 'AppleTV'],
        ['displayname' => 'skip'],
    ],
]);
assert_true(count($sources) === 2 && $sources[0]['name'] === 'Home' && $sources[1]['name'] === 'AppleTV', 'sources');
assert_true(YarboHisense::powerFromState(['power' => 'standby']) === false, 'standby');
assert_true(YarboHisense::powerFromState(['powerstate' => 1]) === true, 'on');
assert_true(YarboHisense::powerFromState([]) === null, 'unknown power');
assert_true(YarboHisense::powerFromState(['statetype' => 'fake_sleep_0']) === false, 'standby statetype');
assert_true(YarboHisense::powerFromState(['statetype' => 'fake_sleep_1']) === true, 'waking is on');
assert_true(YarboHisense::powerFromState(['statetype' => 'remote_launcher']) === true, 'home screen is on');
assert_true(YarboHisense::powerFromState(['statetype' => 'sourceswitch']) === true, 'input change is on');
assert_true(YarboHisense::powerFromState(['statetype' => 'livetv']) === true, 'live tv is on');
assert_true(YarboHisense::powerFromState(['statetype' => 'remote_setting']) === true, 'settings menu is on');
$rawList = YarboHisense::parseSources([
    ['sourceid' => '1', 'displayname' => 'Home'],
    ['sourceid' => '4', 'displayname' => 'AppleTV'],
]);
assert_true(count($rawList) === 2 && $rawList[1]['name'] === 'AppleTV', 'raw source list');
$wrapped = YarboHisense::parseSources([
    'sourcelist' => [['sourceid' => '2', 'sourcename' => 'HDMI1']],
]);
assert_true(count($wrapped) === 1 && $wrapped[0]['name'] === 'HDMI1', 'wrapped source list');
$launcher = YarboHisense::interpretMessages([[
    'topic' => '/remoteapp/mobile/broadcast/ui_service/state',
    'payload' => '{"statetype":"remote_launcher"}',
]]);
assert_true(($launcher['on'] ?? null) === true, 'broadcast home means on');
$switched = YarboHisense::interpretMessages([[
    'topic' => '/remoteapp/mobile/broadcast/ui_service/state',
    'payload' => '{"statetype":"sourceswitch","sourceid":"4","displayname":"AppleTV","sourcename":"HDMI1"}',
]]);
assert_true(($switched['on'] ?? null) === true && ($switched['source_id'] ?? '') === '4', 'broadcast input');
$nested = YarboHisense::interpretMessages([[
    'topic' => '/remoteapp/mobile/broadcast/ui_service/state',
    'payload' => '{"data":{"statetype":"fake_sleep_0"}}',
]]);
assert_true(($nested['on'] ?? null) === false, 'nested standby');
$plain = YarboHisense::interpretMessages([[
    'topic' => '/remoteapp/mobile/broadcast/ui_service/state',
    'payload' => 'statetype=remote_launcher',
]]);
assert_true(($plain['on'] ?? null) === true, 'plain state text');
assert_true(YarboHisense::heardSummary([]) === '', 'empty heard');
$heard = YarboHisense::heardSummary([[
    'topic' => '/remoteapp/mobile/broadcast/ui_service/state',
    'payload' => '{"statetype":"remote_launcher","accesstoken":"secret"}',
]]);
assert_true(str_contains($heard, 'broadcast/ui_service/state') && !str_contains($heard, 'secret'), 'heard hides the token ' . $heard);

$root = sys_get_temp_dir() . '/yarbo-hisense-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
mkdir($root . '/resources/hisense', 0775, true);
$repoCert = dirname(__DIR__) . '/resources/hisense/vidaa_client.pem';
$repoKey = dirname(__DIR__) . '/resources/hisense/vidaa_client.key';
copy($repoCert, $root . '/resources/hisense/vidaa_client.pem');
copy($repoKey, $root . '/resources/hisense/vidaa_client.key');
$parsed = openssl_x509_parse((string) file_get_contents($repoCert));
assert_true(is_array($parsed) && ($parsed['subject']['CN'] ?? '') === 'VidaaAppAndroidV01', 'VIDAA app certificate');
assert_true(openssl_x509_check_private_key((string) file_get_contents($repoCert), (string) file_get_contents($repoKey)), 'certificate matches key');
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['home' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));

$tv = new YarboHisense($root);
$profiles = (new ReflectionClass($tv))->getMethod('profiles');
$profiles->setAccessible(true);
$first = $profiles->invoke($tv, '56:b8:88:4e:f7:19')[0] ?? [];
assert_true(($first['mode'] ?? '') === 'vidaa' && !empty($first['tls']), 'firmware 9 login is tried first');
assert_true(str_ends_with((string) ($first['cert'] ?? ''), 'vidaa_client.pem'), 'uses the VIDAA app certificate');
$publishes = [];
$tv->setTransport(static function (string $host, array $profile, array $outgoing) use (&$publishes): array {
    foreach ($outgoing as $item) {
        $publishes[] = $item;
    }
    $topics = array_map(static fn (array $item): string => (string) ($item['topic'] ?? ''), $outgoing);
    if (in_array('sendkey', $topics, true)) {
        return ['ok' => true, 'messages' => []];
    }

    return [
        'ok' => true,
        'messages' => [
            [
                'topic' => '/remoteapp/mobile/x/ui_service/data/sourcelist',
                'payload' => json_encode(['sources' => [
                    ['sourceid' => '1', 'displayname' => 'Home'],
                    ['sourceid' => '4', 'displayname' => 'AppleTV'],
                ]]),
            ],
            [
                'topic' => '/remoteapp/mobile/x/ui_service/data/state',
                'payload' => json_encode(['power' => 'on', 'sourceid' => '1', 'displayname' => 'Home']),
            ],
        ],
    ];
});
$added = $tv->add('192.168.1.50');
assert_true(($added['ok'] ?? false) === true, 'add ' . json_encode($added));
assert_true(($added['tv']['needs_pin'] ?? true) === false, 'pin not required when sources arrived');
$rows = $tv->homeRows();
assert_true(count($rows) === 1 && $rows[0]['id'] === 'hisense:192.168.1.50' && $rows[0]['kind'] === 'tv', 'home row');
assert_true(count($rows[0]['inputs']) === 2, 'inputs stored');

$publishes = [];
$on = $tv->command('hisense:192.168.1.50', 'on', []);
assert_true(($on['ok'] ?? false) === true, 'already on ' . json_encode($on));
$keys = array_map(static fn (array $item): string => (string) ($item['payload'] ?? ''), $publishes);
assert_true(!in_array('KEY_POWER', $keys, true), 'already on must not toggle power ' . json_encode($publishes));

$publishes = [];
$input = $tv->command('hisense:192.168.1.50', 'on', ['input' => '4']);
assert_true(($input['ok'] ?? false) === true, 'input ' . json_encode($input));
$topics = array_map(static fn (array $item): string => (string) ($item['topic'] ?? ''), $publishes);
assert_true(in_array('changesource', $topics, true), 'changesource sent');
assert_true(!in_array('KEY_POWER', array_map(static fn (array $item): string => (string) ($item['payload'] ?? ''), $publishes), true), 'input while on skips power');

$publishes = [];
$tv->setTransport(static function (string $host, array $profile, array $outgoing) use (&$publishes): array {
    foreach ($outgoing as $item) {
        $publishes[] = $item;
    }

    return [
        'ok' => true,
        'messages' => [[
            'topic' => '/remoteapp/mobile/broadcast/ui_service/state',
            'payload' => '{"statetype":"fake_sleep_0"}',
        ]],
    ];
});
$wake = $tv->command('hisense:192.168.1.50', 'on', []);
assert_true(($wake['ok'] ?? false) === true && ($wake['on'] ?? null) === true, 'wake ' . json_encode($wake));
assert_true(in_array('KEY_POWER', array_map(static fn (array $item): string => (string) ($item['payload'] ?? ''), $publishes), true), 'standby sends power');

$storePath = $root . '/data/hisense.json';
$stored = json_decode((string) file_get_contents($storePath), true);
assert_true(is_array($stored), 'store');
$stored['tvs']['hisense:192.168.1.50']['token'] = 'ACCESS';
$stored['tvs']['hisense:192.168.1.50']['on'] = true;
file_put_contents($storePath, json_encode($stored));
$seenProfile = [];
$tv->setTransport(static function (string $host, array $profile, array $outgoing) use (&$seenProfile): array {
    $seenProfile[] = $profile;

    return [
        'ok' => true,
        'messages' => [
            [
                'topic' => '/remoteapp/mobile/broadcast/ui_service/state',
                'payload' => '{"statetype":"remote_launcher"}',
            ],
            [
                'topic' => '/remoteapp/mobile/x/ui_service/data/sourcelist',
                'payload' => json_encode([
                    ['sourceid' => '1', 'displayname' => 'Home'],
                    ['sourceid' => '4', 'displayname' => 'AppleTV'],
                    ['sourceid' => '5', 'displayname' => 'HDMI'],
                ]),
            ],
        ],
    ];
});
$again = $tv->command('hisense:192.168.1.50', 'on', []);
assert_true(($again['ok'] ?? false) === true && ($again['tv']['on'] ?? null) === true, 'launcher is on ' . json_encode($again));
assert_true(($seenProfile[0]['mode'] ?? '') === 'token' && ($seenProfile[0]['password'] ?? '') === 'ACCESS', 'saved code is the login');
assert_true(count($again['tv']['inputs'] ?? []) === 3, 'inputs from the set ' . json_encode($again['tv']['inputs'] ?? []));
$rows = $tv->homeRows();
assert_true(($rows[0]['on'] ?? null) === true && ($rows[0]['power_known'] ?? false) === true, 'tile knows it is on ' . json_encode($rows[0]));
assert_true(!in_array((string) ($rows[0]['status'] ?? ''), ['Off', 'Power unknown'], true), 'tile does not say off ' . json_encode($rows[0]['status'] ?? ''));

$stored = json_decode((string) file_get_contents($storePath), true);
$stored['tvs']['hisense:192.168.1.50']['on'] = false;
$stored['tvs']['hisense:192.168.1.50']['state_at'] = 0;
$stored['tvs']['hisense:192.168.1.50']['source_id'] = '4';
$stored['tvs']['hisense:192.168.1.50']['source_name'] = 'AppleTV';
file_put_contents($storePath, json_encode($stored));
$refreshed = $tv->refreshStale(0);
assert_true(($refreshed['ok'] ?? false) === true, 'refresh');
$rows = $tv->homeRows();
assert_true(($rows[0]['on'] ?? null) === true, 'refresh sees the remote turned it on ' . json_encode($rows[0]['on'] ?? null));
assert_true(($rows[0]['input'] ?? '') === '4' || ($rows[0]['status'] ?? '') !== 'Off', 'refresh kept the picture');

$auto = new YarboHomeAutomations($root);
assert_true((new YarboHub($root))->enabled(YarboHub::MODULE_HOME), 'home module');
$saved = $auto->save([
    'name' => 'TV with button',
    'off_after_sec' => 60,
    'cooldown_sec' => 30,
    'triggers' => [[
        'type' => 'device',
        'id' => '9:1',
        'event' => 'press',
        'stop_event' => 'hold',
    ]],
    'actions' => [[
        'kind' => 'device',
        'id' => 'hisense:192.168.1.50',
        'command' => 'on',
        'device_kind' => 'tv',
        'input' => '4',
        'input_name' => 'AppleTV',
        'off_after_sec' => 60,
    ]],
]);
assert_true(($saved['ok'] ?? false) === true, 'save ' . json_encode($saved));
$rule = $saved['automations'][0] ?? [];
assert_true(($rule['triggers'][0]['stop_event'] ?? '') === 'hold', 'stop stored');
assert_true(
    str_contains(YarboHomeAutomations::whenPhrase($rule['triggers'][0], ['9:1' => 'Bilresa']), 'held turns it off'),
    'button phrase names the off gesture'
);
assert_true(($rule['actions'][0]['input'] ?? '') === '4', 'input stored');
assert_true((int) ($rule['actions'][0]['off_after_sec'] ?? 0) === 60, 'off after stored');

$commands = [];
$auto->setCommandHandler(static function (array $action) use (&$commands): array {
    $commands[] = $action;

    return ['ok' => true];
});
$button = [
    'id' => '9:1',
    'kind' => 'button',
    'name' => 'Bilresa',
    'on' => false,
    'press_at' => 0,
    'press_gesture' => '',
];
$now = 1_700_000_000;
$auto->tick([$button], $now);
$button['press_at'] = 1000;
$button['press_gesture'] = 'press';
$first = $auto->tick([$button], $now + 1);
assert_true(in_array($rule['id'], $first['fired'] ?? [], true), 'press fires ' . json_encode($first));
assert_true(count($commands) === 1 && ($commands[0]['input'] ?? '') === '4', 'then includes input ' . json_encode($commands));
$commands = [];
$second = $auto->tick([$button], $now + 5);
assert_true(($second['fired'] ?? []) === [], 'same press does not fire again ' . json_encode($second));
assert_true($commands === [], 'no extra command');
$button['press_at'] = 1010;
$button['press_gesture'] = 'hold';
$third = $auto->tick([$button], $now + 6);
assert_true(($third['fired'] ?? []) === [], 'stop does not start again');
assert_true(count($commands) === 1 && ($commands[0]['command'] ?? '') === 'off', 'hold runs off ' . json_encode($commands));

$savedTv = $auto->save([
    'name' => 'When the TV comes on',
    'triggers' => [[
        'type' => 'device',
        'id' => 'hisense:192.168.1.50',
        'event' => 'turns_on',
    ]],
    'actions' => [[
        'kind' => 'device',
        'id' => 'hisense:192.168.1.50',
        'command' => 'off',
        'device_kind' => 'tv',
    ]],
]);
assert_true(($savedTv['ok'] ?? false) === true, 'tv when save');
$tvRule = $savedTv['automations'][count($savedTv['automations']) - 1] ?? [];
$picture = [
    'id' => 'hisense:192.168.1.50',
    'kind' => 'tv',
    'name' => 'Hisense TV',
    'on' => null,
];
$commands = [];
$auto->tick([$button, $picture], $now + 30);
$picture['on'] = true;
$learned = $auto->tick([$button, $picture], $now + 31);
assert_true(!in_array($tvRule['id'] ?? '', $learned['fired'] ?? [], true), 'learning an already-on TV does not fire ' . json_encode($learned));
$picture['on'] = false;
$auto->tick([$button, $picture], $now + 32);
$commands = [];
$picture['on'] = true;
$rose = $auto->tick([$button, $picture], $now + 33);
assert_true(in_array($tvRule['id'] ?? '', $rose['fired'] ?? [], true), 'remote on fires ' . json_encode($rose));
assert_true(count($commands) === 1 && ($commands[0]['command'] ?? '') === 'off', 'then runs ' . json_encode($commands));

$tries = 0;
$tv->setTransport(static function (string $host, array $profile, array $outgoing) use (&$tries): array {
    $topics = array_map(static fn (array $item): string => (string) ($item['topic'] ?? ''), $outgoing);
    if (in_array('sendkey', $topics, true) || in_array('gettoken', $topics, true)) {
        return ['ok' => true, 'messages' => []];
    }
    $tries++;
    if ($tries === 1) {
        return ['ok' => true, 'messages' => []];
    }

    return [
        'ok' => true,
        'messages' => [[
            'topic' => '/remoteapp/mobile/broadcast/ui_service/state',
            'payload' => '{"statetype":"fake_sleep_0"}',
        ]],
    ];
});
$stored = json_decode((string) file_get_contents($storePath), true);
$stored['tvs']['hisense:192.168.1.50']['on'] = true;
$stored['tvs']['hisense:192.168.1.50']['token'] = '';
$stored['tvs']['hisense:192.168.1.50']['refresh_token'] = '';
file_put_contents($storePath, json_encode($stored));
$silent = $tv->command('hisense:192.168.1.50', 'off', []);
assert_true(($silent['ok'] ?? false) === true && ($silent['on'] ?? null) === false, 'second login hears standby ' . json_encode($silent));
assert_true($tries >= 2, 'a silent login is not the last try ' . $tries);

echo "ok\n";
