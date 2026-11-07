<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHome;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/yarbo-home-plug-auto-off-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);

$plugId = '12:1';
$home = new YarboHome($root);

assert_true($home->autoOffSec($plugId) === 0, 'default auto-off is off');
assert_true($home->setAutoOff($plugId, 300), 'can enable 5 min auto-off for Matter plug id');
assert_true($home->autoOffSec($plugId) === 300, 'auto-off stores seconds');
assert_true(($home->autoOffMap()[$plugId] ?? 0) === 300, 'auto-off map includes plug');
assert_true(!$home->setAutoOff('unifi:relay:usl1:0', 60), 'UniFi ids rejected');
assert_true(!$home->setAutoOff('bad-id', 60), 'non Matter ids rejected when kind unknown');

$store = json_decode((string) file_get_contents($root . '/data/home.json'), true);
assert_true(is_array($store), 'home.json written');
assert_true(($store['auto_off_sec'][$plugId] ?? 0) === 300, 'persisted in home.json');

$homePhp = (string) file_get_contents(dirname(__DIR__) . '/src/YarboHome.php');
assert_true(str_contains($homePhp, 'function flushAutoOff'), 'Home has flushAutoOff');
assert_true(str_contains($homePhp, 'maybeSchedulePlugAutoOff'), 'Home schedules on plug on');
assert_true(str_contains($homePhp, 'home-auto-off.json'), 'pending state file');

$api = (string) file_get_contents(dirname(__DIR__) . '/public/api/home.php');
assert_true(str_contains($api, "'auto_off'"), 'API has auto_off action');
assert_true(str_contains($api, 'flushAutoOff'), 'Home GET flushes plug auto-off');

$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
assert_true(str_contains($js, 'function homePlugAutoOffHtml'), 'JS builds plug auto-off controls');
assert_true(str_contains($js, "action: 'auto_off'"), 'JS saves auto-off');
assert_true(str_contains($js, 'data-home-auto-off-enabled'), 'JS marks auto-off checkbox');

$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/style.css');
assert_true(str_contains($css, '.home-plug-auto-off'), 'auto-off has CSS');

$change = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.221]'), 'changelog 4.0.221');
assert_true(str_contains($change, 'Load map backups over cloud MQTT'), 'changelog mentions map backup fix');
assert_true(str_contains($change, 'Hue / Matter plug auto-off'), 'changelog mentions plug auto-off');

$backupApi = (string) file_get_contents(dirname(__DIR__) . '/public/api/map_backup.php');
assert_true(str_contains($backupApi, 'listAndFetch(null'), 'map backups cloud-only');
assert_true(!str_contains($backupApi, 'yarbo_client($config)'), 'map backups no local MQTT client');

fwrite(STDOUT, "test_home_plug_auto_off.php ok\n");
