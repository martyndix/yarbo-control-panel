<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHomeAutomations;

function assert_true(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "ok: {$msg}\n";
}

$rootFs = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/yarbo-resume-' . bin2hex(random_bytes(3));
mkdir($tmp . '/data', 0775, true);
file_put_contents($tmp . '/data/hub-config.json', json_encode([
    'modules' => ['home' => true],
    'active_module' => 'home',
], JSON_UNESCAPED_SLASHES));
file_put_contents($tmp . '/data/home.json', json_encode([
    'names' => ['1:2' => 'Lamp'],
    'last_devices' => [[
        'id' => '1:2',
        'name' => 'Lamp',
        'kind' => 'light',
        'on' => true,
        'brightness' => 40,
        'color_hex' => '#ffffff',
        'color_temp' => 2700,
        'dimmable' => true,
        'colorable' => true,
        'color_ct' => true,
    ]],
    'automations' => [],
], JSON_UNESCAPED_SLASHES));
file_put_contents($tmp . '/data/home-nodes-cache.json', json_encode([
    [
        'id' => '1:2',
        'name' => 'Lamp',
        'kind' => 'light',
        'on' => true,
        'brightness' => 40,
        'color_hex' => '#ffffff',
        'color_temp' => 2700,
        'dimmable' => true,
        'colorable' => true,
        'color_ct' => true,
    ],
], JSON_UNESCAPED_SLASHES));

$auto = new YarboHomeAutomations($tmp);
$commands = [];
$auto->setCommandHandler(static function (array $action) use (&$commands): array {
    $commands[] = $action;

    return ['ok' => true];
});

$save = $auto->save([
    'id' => 'a-resume',
    'enabled' => true,
    'name' => '',
    'trigger' => ['type' => 'device', 'id' => 'unifi:camera:drive', 'event' => 'person'],
    'actions' => [[
        'kind' => 'device',
        'id' => '1:2',
        'command' => 'on',
        'hex' => '#0000ff',
        'brightness' => 100,
    ]],
    'off_after_sec' => 5,
    'resume_previous' => true,
    'cooldown_sec' => 0,
    'names' => ['1:2' => 'Lamp', 'unifi:camera:drive' => 'Driveway'],
]);
assert_true(!empty($save['ok']), 'save resume timer rule: ' . json_encode($save));
assert_true(!empty($save['automation']['resume_previous']), 'resume_previous persisted');
assert_true((int) ($save['automation']['off_after_sec'] ?? 0) === 5, 'off_after_sec persisted');
assert_true(
    str_contains((string) ($save['automation']['name'] ?? ''), 'resume previous state'),
    'auto-name mentions resume: ' . ($save['automation']['name'] ?? '')
);

$t0 = 2_000_000_000;
$snap = static function (bool $person, int $personAt = 0): array {
    return [
        [
            'id' => 'unifi:camera:drive',
            'kind' => 'camera',
            'person' => $person,
            'detect_person_at' => $personAt,
            'motion' => $person,
            'motion_at' => $personAt,
        ],
        [
            'id' => '1:2',
            'kind' => 'light',
            'on' => true,
            'brightness' => 40,
            'color_hex' => '#ffffff',
            'color_temp' => 2700,
        ],
    ];
};

$commands = [];
$r = $auto->tick($snap(false), $t0);
assert_true(($r['fired'] ?? []) === [], 'idle before person');
$r = $auto->tick($snap(true, $t0 + 1), $t0 + 1);
assert_true(($r['fired'] ?? []) === ['a-resume'], 'Then fires on person: ' . json_encode($r));
$thenCmds = array_values(array_filter(
    $commands,
    static fn (array $c): bool => ($c['id'] ?? '') === '1:2' && (($c['hex'] ?? '') === '#0000ff' || ($c['command'] ?? '') === 'on' || ($c['command'] ?? '') === 'color' || ($c['command'] ?? '') === 'brightness')
));
assert_true($thenCmds !== [], 'Then commanded blue/on: ' . json_encode($commands));

$state = json_decode((string) file_get_contents($auto->statePath()), true);
assert_true(is_array($state['resume']['a-resume']['looks']['1:2'] ?? null), 'resume snapshot stored');
$look = $state['resume']['a-resume']['looks']['1:2'];
assert_true(!empty($look['on']), 'snapshot was on');
assert_true((string) ($look['hex'] ?? '') === '#ffffff' || (int) ($look['kelvin'] ?? 0) === 2700 || (int) ($look['brightness'] ?? 0) === 40, 'snapshot kept previous look: ' . json_encode($look));
assert_true(is_array($state['delayed']['a-resume'] ?? null), 'timer job queued');

$commands = [];
$r = $auto->tick($snap(true, $t0 + 1), $t0 + 3);
assert_true(($r['turned_off'] ?? []) === [] && $commands === [], 'before timer: no resume yet');
$r = $auto->tick($snap(true, $t0 + 1), $t0 + 7);
assert_true(in_array('a-resume', $r['turned_off'] ?? [], true), 'timer fired resume: ' . json_encode($r));
$resumeCmds = array_values(array_filter(
    $commands,
    static fn (array $c): bool => ($c['id'] ?? '') === '1:2'
));
assert_true($resumeCmds !== [], 'resume issued commands: ' . json_encode($commands));
$joined = json_encode($resumeCmds);
assert_true(
    str_contains($joined, '#ffffff') || str_contains($joined, '2700') || str_contains($joined, '"brightness":40'),
    'resume restored white/warm/40%: ' . $joined
);
assert_true(!str_contains($joined, '#0000ff'), 'resume did not keep blue: ' . $joined);

$js = (string) file_get_contents($rootFs . '/public/assets/app.js');
$index = (string) file_get_contents($rootFs . '/public/index.php');
assert_true(str_contains($index, 'value="resume-1"'), 'UI has resume after timer');
assert_true(str_contains($js, 'homeAutoReadResumePrevious'), 'JS resume helper');
assert_true(str_contains($js, "mode !== '1' && mode !== 'resume-1'"), 'timer fields work for resume-1');
$change = (string) file_get_contents($rootFs . '/CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.275]'), 'changelog 4.0.275');

echo "ALL PASS\n";
