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

echo "ok\n";
