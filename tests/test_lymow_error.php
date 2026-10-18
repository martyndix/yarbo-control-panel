<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHomeAutomations;
use Yarbo\YarboHub;
use Yarbo\YarboLymow;
use Yarbo\YarboPaperDevice;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

assert_true(YarboLymow::hasError(['work_status' => 7]) === true, 'work_status 7 is error');
assert_true(YarboLymow::hasError(['work_status' => 2]) === false, 'mowing without code is not error');
assert_true(YarboLymow::hasError(['work_status' => 2, 'error_code' => 77]) === true, 'E77 while mowing is error');
assert_true(YarboLymow::hasError(['work_status' => 2, 'error_codes' => [77]]) === true, 'error_codes 77 while mowing is error');
assert_true(YarboLymow::hasError(['work_status' => 2, 'error_code' => 0, 'error_codes' => []]) === false, 'zero code is not error');
assert_true(YarboHomeAutomations::lymowErrorFromData(['work_status' => 2, 'error_code' => 77]) === true, 'automations see E77');
assert_true(YarboHomeAutomations::lymowSnapshotRow(['work_status' => 2, 'error_code' => 77])['error'] === true, 'snapshot E77');
assert_true(YarboHomeAutomations::lymowSnapshotRow(['work_status' => 2])['error'] === false, 'snapshot mowing ok');

$root = sys_get_temp_dir() . '/yarbo-ly-e77-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => false, 'lymow' => true, 'home' => false, 'powerwall' => false],
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/lymow-state.json', json_encode([
    'ok' => true,
    'online' => true,
    'battery' => 92,
    'work_status' => 2,
    'error_code' => 77,
    'error_codes' => [77],
    'mow_progress' => 0,
    'is_charging' => false,
], JSON_UNESCAPED_SLASHES));

$ly = new YarboLymow($root);
$dash = $ly->dashboardPayload();
assert_true(($dash['work_label'] ?? '') === 'ERROR', 'card shows ERROR not MOWING: ' . json_encode($dash['work_label'] ?? null));
assert_true((int) ($dash['error_code'] ?? 0) === 77, 'dashboard keeps E77');
assert_true(($dash['mow_progress_label'] ?? '') === '—', 'error hides mow progress');
assert_true(YarboHomeAutomations::lymowErrorFromData($dash) === true, 'live dashboard is error for automations');

$auto = new YarboHomeAutomations($root);
$row = $auto->lymowLiveRow();
assert_true(is_array($row) && ($row['error'] ?? false) === true, 'lymowLiveRow error from E77: ' . json_encode($row));

$layout = $ly->vestaboardLayout();
assert_true(($layout['verb'] ?? '') === 'ERROR', 'Note verb ERROR: ' . json_encode($layout['verb'] ?? null));

file_put_contents($root . '/data/papermono-devices.json', json_encode([
    'devices' => [['id' => 'tab1', 'name' => 'Kitchen', 'kind' => 'papermono', 'token' => 'tok1']],
    'prefs' => [],
    'messages' => [],
], JSON_UNESCAPED_SLASHES));
$compact = new ReflectionMethod(YarboPaperDevice::class, 'companionCompact');
$compact->setAccessible(true);
$paper = new YarboPaperDevice($root);
$out = $compact->invoke($paper, null, false, ['id' => 'tab1', 'name' => 'Kitchen']);
assert_true(($out['lymow_error'] ?? false) === true, 'paper lymow_error from E77: ' . json_encode($out['lymow_error'] ?? null));
assert_true(str_starts_with((string) ($out['lymow_state'] ?? ''), 'ERROR'), 'paper state ERROR: ' . json_encode($out['lymow_state'] ?? null));

$okRoot = sys_get_temp_dir() . '/yarbo-ly-ok-' . bin2hex(random_bytes(3));
mkdir($okRoot . '/data', 0775, true);
file_put_contents($okRoot . '/data/lymow-state.json', json_encode([
    'ok' => true,
    'online' => true,
    'battery' => 90,
    'work_status' => 2,
    'error_code' => 0,
    'error_codes' => [],
    'mow_progress' => 11,
], JSON_UNESCAPED_SLASHES));
$okDash = (new YarboLymow($okRoot))->dashboardPayload();
assert_true(($okDash['work_label'] ?? '') === 'MOWING', 'mowing stays MOWING with no code');
assert_true(YarboLymow::hasError($okDash) === false, 'mowing dashboard is not error');

$php = (string) file_get_contents(__DIR__ . '/../src/YarboLymow.php');
$autoPhp = (string) file_get_contents(__DIR__ . '/../src/YarboHomeAutomations.php');
$paperPhp = (string) file_get_contents(__DIR__ . '/../src/YarboPaperDevice.php');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($php, 'function hasError'), 'shared Lymow error helper');
assert_true(str_contains($autoPhp, 'YarboLymow::hasError'), 'automations use shared helper');
assert_true(str_contains($paperPhp, 'YarboLymow::hasError'), 'paper uses shared helper');
assert_true(str_contains($change, '## [4.0.119]'), 'changelog 4.0.119');

echo "test_lymow_error.php ok\n";
