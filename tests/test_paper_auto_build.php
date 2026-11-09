<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboPaperDevice;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$rootDir = dirname(__DIR__);
$src = (string) file_get_contents($rootDir . '/src/YarboPaperDevice.php');
$api = (string) file_get_contents($rootDir . '/public/api/device.php');
$js = (string) file_get_contents($rootDir . '/public/assets/app.js');
$panel = (string) file_get_contents($rootDir . '/scripts/panel.sh');
$script = (string) file_get_contents($rootDir . '/scripts/paper_firmware_build.php');
$install = (string) file_get_contents($rootDir . '/scripts/install.sh');
$change = (string) file_get_contents($rootDir . '/CHANGELOG.md');
$docs = (string) file_get_contents($rootDir . '/docs/papermono.md');
$colorDocs = (string) file_get_contents($rootDir . '/docs/papercolor.md');
$index = (string) file_get_contents($rootDir . '/public/index.php');

assert_true(is_file($rootDir . '/scripts/paper_firmware_build.php'), 'auto-build script exists');
assert_true(str_contains($script, 'runAutoBuild'), 'script calls runAutoBuild');
assert_true(str_contains($script, '--loop'), 'script supports loop mode');
assert_true(str_contains($src, 'function queueAutoBuild'), 'queueAutoBuild method');
assert_true(str_contains($src, 'function runAutoBuild'), 'runAutoBuild method');
assert_true(str_contains($src, 'function autoBuildPublic'), 'autoBuildPublic method');
assert_true(str_contains($src, "'auto_build' => \$this->autoBuildPublic()"), 'dashboard exposes auto_build');
assert_true(str_contains($api, "'ensure_firmware_build'"), 'device API ensure_firmware_build');
assert_true(str_contains($js, 'ensurePaperFirmwareAutoBuild'), 'UI queues auto-build');
assert_true(str_contains($js, 'ensure_firmware_build'), 'UI posts ensure_firmware_build');
assert_true(str_contains($js, 'building automatically'), 'UI status mentions auto-build');
assert_true(str_contains($panel, 'paper_firmware_build.php'), 'panel.sh starts auto-build');
assert_true(str_contains($panel, 'PAPER_FW_PID'), 'panel.sh tracks auto-build pid');
assert_true(str_contains($install, 'paper_firmware_build.php'), 'install.sh chmods auto-build script');
assert_true(str_contains($change, '## [4.0.242]'), 'changelog 4.0.242');
assert_true(str_contains($change, 'auto-build'), 'changelog mentions auto-build');
assert_true(str_contains($docs, 'builds PaperMono / Paper Colour automatically'), 'papermono docs auto-build');
assert_true(str_contains($colorDocs, 'auto-build'), 'papercolor docs auto-build');
assert_true(str_contains($index, 'builds automatically in the background'), 'settings hint auto-build');

$tmp = sys_get_temp_dir() . '/yarbo-paper-auto-build-' . bin2hex(random_bytes(3));
mkdir($tmp . '/data', 0775, true);
mkdir($tmp . '/scripts', 0775, true);
file_put_contents($tmp . '/scripts/paper_firmware_build.php', "<?php\n");
$paper = new YarboPaperDevice($tmp);
$dash = $paper->dashboard();
assert_true(isset($dash['auto_build']['needed']), 'dashboard auto_build.needed');
assert_true(($dash['auto_build']['state'] ?? '') === 'idle', 'auto_build starts idle');
assert_true(($dash['firmware']['papermono']['needs_build'] ?? false) === true, 'missing mono needs build');
assert_true(($dash['firmware']['papercolor']['needs_build'] ?? false) === true, 'missing color needs build');

$queued = $paper->queueAutoBuild();
assert_true(($queued['ok'] ?? false) === true, 'queueAutoBuild ok: ' . json_encode($queued));
assert_true(($queued['queued'] ?? false) === true, 'queueAutoBuild queued');
assert_true(($queued['needed'] ?? false) === true, 'queueAutoBuild needed');
assert_true(is_file($paper->autoBuildStatusPath()), 'status file written');

$status = $paper->autoBuildPublic();
assert_true(in_array($status['state'], ['queued', 'running', 'failed'], true), 'status after queue: ' . $status['state']);

$again = $paper->queueAutoBuild();
assert_true(($again['ok'] ?? false) === true, 'second queue still ok');
assert_true(($again['queued'] ?? true) === false || ($again['running'] ?? false) === true, 'second queue does not double-spawn when running/queued');

echo "test_paper_auto_build.php ok\n";
