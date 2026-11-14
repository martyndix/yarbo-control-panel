<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHomeAutomations;
use Yarbo\YarboPowerwall;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "ok: {$message}\n";
}

assert_true(YarboPowerwall::isTransientHttpError('Could not reach https://x: timed out'), 'timeout is transient');
assert_true(YarboPowerwall::isTransientHttpError('HTTP 429: rate limited'), '429 is transient');
assert_true(YarboPowerwall::isTransientHttpError('HTTP 503: unavailable'), '503 is transient');
assert_true(!YarboPowerwall::isTransientHttpError('HTTP 401: invalid_grant'), 'invalid_grant not transient');
assert_true(!YarboPowerwall::isTransientHttpError('Tesla refused the refresh token.'), 'auth failure not transient');

$root = sys_get_temp_dir() . '/yarbo-pw-recover-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
$engine = new YarboHomeAutomations($root);
assert_true(!$engine->consumeRunnerNudge(), 'no nudge yet');
$engine->requestRunnerNudge();
assert_true(is_file($engine->runnerNudgePath()), 'nudge file written');
assert_true($engine->consumeRunnerNudge(), 'nudge consumed');
assert_true(!$engine->consumeRunnerNudge(), 'nudge gone after consume');

$engine->forcePowerwallDue();
$ref = new ReflectionClass($engine);
$load = $ref->getMethod('loadState');
$load->setAccessible(true);
$state = $load->invoke($engine);
assert_true((int) ($state['powerwall_at'] ?? -1) === 0, 'forcePowerwallDue clears stamp');
assert_true(empty($state['powerwall_ok']), 'forcePowerwallDue clears ok flag');

$src = (string) file_get_contents(__DIR__ . '/../src/YarboPowerwall.php');
assert_true(str_contains($src, 'withApiLock'), 'API lock helper');
assert_true(str_contains($src, 'powerwall-api.lock'), 'API lock path');
assert_true(str_contains($src, 'httpJsonOnce'), 'single-attempt HTTP');
assert_true(str_contains($src, 'isTransientHttpError'), 'transient classifier');

$runner = (string) file_get_contents(__DIR__ . '/../scripts/home_automations.php');
assert_true(
    strpos($runner, 'refreshPowerwallIfDue') < strpos($runner, '$engine->tick()'),
    'Powerwall refresh before Home tick'
);
assert_true(str_contains($runner, 'consumeRunnerNudge'), 'runner consumes status nudge');

$auto = (string) file_get_contents(__DIR__ . '/../src/YarboHomeAutomations.php');
assert_true(str_contains($auto, "interval = \$lastOk || \$at === 0 ? \$everySec : 45"), 'fail backoff 45s');
assert_true(str_contains($auto, 'powerwall_ok'), 'tracks last refresh ok');

$status = (string) file_get_contents(__DIR__ . '/../public/api/status.php');
assert_true(str_contains($status, 'requestRunnerNudge'), 'status nudges when Powerwall stale');
assert_true(str_contains($status, 'Cache-only status never calls Tesla'), 'documents no Tesla in status');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.296]'), 'changelog 4.0.296');

$pw = new YarboPowerwall($root);
assert_true(str_ends_with($pw->apiLockPath(), '/data/powerwall-api.lock'), 'lock path under data');

echo "test_powerwall_self_recover.php ok\n";
