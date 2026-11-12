<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboVestaboard;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-vb-watcher-ok-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode([
    'modules' => ['yarbo' => true, 'powerwall' => true],
    'vestaboard_live' => 'powerwall',
], JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'transport' => 'local',
    'api_key' => 'test-key',
    'last_sent_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES));

$vb = new YarboVestaboard($root);
assert_true($vb->watcherOk(), 'recent last_sent_at counts as watcher ok without heartbeat file');
assert_true(!$vb->watcherStale(), 'watcherStale false when last write is fresh');

file_put_contents($root . '/data/vestaboard-config.json', json_encode([
    'enabled' => true,
    'transport' => 'local',
    'api_key' => 'test-key',
    'last_sent_at' => gmdate('c', time() - 600),
], JSON_UNESCAPED_SLASHES));
$stale = new YarboVestaboard($root);
assert_true(!$stale->watcherOk(), 'old last_sent_at alone is not ok');
$stale->ensureWatcherAwake();
assert_true(is_file($root . '/data/vestaboard-watch.nudge'), 'idle watcher gets a nudge');

$stale->touchHeartbeat();
assert_true($stale->watcherOk(), 'fresh heartbeat is ok');

$src = (string) file_get_contents(__DIR__ . '/../src/YarboVestaboard.php');
assert_true(str_contains($src, 'function watcherOk'), 'watcherOk helper');
assert_true(str_contains($src, 'ensureWatcherAwake'), 'ensureWatcherAwake helper');
assert_true(str_contains($src, 'Any successful write counts as updater activity'), 'sendLayout touches heartbeat');

$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
assert_true(str_contains($js, 'background updater catching up'), 'UI catch-up copy');
assert_true(!str_contains($js, 'background updater idle — if this stays, restart the panel'), 'no restart-panel nag on Vestaboard card');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.272]'), 'changelog 4.0.272');

echo "ok\n";
