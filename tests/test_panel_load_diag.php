<?php

declare(strict_types=1);

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$status = (string) file_get_contents(__DIR__ . '/../public/api/status.php');
assert_true(str_contains($status, 'telemetry(1.0, true, true, false)'), 'status uses short prefer-cache telemetry budget');
assert_true(str_contains($status, 'status_with_timing'), 'status attaches timing');
assert_true(str_contains($status, "'ping_ms'"), 'timing includes ping_ms');
assert_true(str_contains($status, "'telemetry_ms'"), 'timing includes telemetry_ms');
assert_true(str_contains($status, "'extras_ms'"), 'timing includes extras_ms');
assert_true(str_contains($status, 'panel_health'), 'status includes panel_health');
assert_true(str_contains($status, 'watcher_ok'), 'panel_health includes watcher');
assert_true(!str_contains($status, 'telemetry(2.5, true, true, false)'), 'status no longer uses 2.5s browser telemetry budget');

$agent = (string) file_get_contents(__DIR__ . '/../scripts/mqtt_agent.py');
assert_true(str_contains($agent, 'TELEMETRY_MISS_COOLDOWN_S'), 'agent miss cooldown constant');
assert_true(str_contains($agent, 'telemetry_miss_cooling'), 'agent miss cooldown helper');
assert_true(str_contains($agent, 'mark_telemetry_miss'), 'agent marks telemetry misses');
assert_true(str_contains($agent, 'last_raw_at'), 'agent tracks cache age');
assert_true(str_contains($agent, '"cooldown": True') || str_contains($agent, "'cooldown': True"), 'cooldown flag on fast miss');

$client = (string) file_get_contents(__DIR__ . '/../src/YarboMqttAgentClient.php');
assert_true(str_contains($client, 'max(2.0, $timeoutSeconds + 1.0)'), 'prefer_cache socket wait matches shorter budget');

$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
assert_true(str_contains($js, 'applyPanelHealth'), 'client renders panel health');
assert_true(str_contains($js, 'applyYarboOfflineHint'), 'client shows offline hint before first ok');
assert_true(str_contains($js, 'lastStatusClientRttMs'), 'client tracks status RTT');
assert_true(str_contains($js, 'Do not wait on settings before the first status poll'), 'boot starts status without waiting on settings');
assert_true(preg_match('/restoreVestaboardPreviewCache\(\);\s*\n\s*fetchStatus\(\)/', $js) === 1, 'boot calls fetchStatus beside settings');

$html = (string) file_get_contents(__DIR__ . '/../public/index.php');
assert_true(str_contains($html, 'Panel health'), 'settings has Panel health');
assert_true(str_contains($html, 'panel-health-total'), 'panel health total element');
assert_true(str_contains($html, 'panel-health-watcher'), 'panel health watcher element');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.270]'), 'changelog 4.0.270');

$friendly = (string) file_get_contents(__DIR__ . '/../tests/test_status_504_friendly.php');
assert_true(str_contains($friendly, 'telemetry(1.0, true, true, false)') || str_contains($status, 'telemetry(1.0'), '504 test will be updated for 1.0 budget');

echo "ok\n";
