<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboHome;
use Yarbo\YarboMatterAgentClient;

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "ok: {$message}\n";
}

assert_true(YarboHome::normalizeMatterPairingCode('2462-204-0808') === '24622040808', 'dashed code');
assert_true(YarboHome::normalizeMatterPairingCode('2462 204 0808') === '24622040808', 'spaced code');
assert_true(YarboHome::normalizeMatterPairingCode('MT:abc') === 'MT:abc', 'MT code kept');
assert_true(YarboMatterAgentClient::MIN_VERSION === 72, 'min agent 72');

$index = (string) file_get_contents(__DIR__ . '/../public/index.php');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$home = (string) file_get_contents(__DIR__ . '/../src/YarboHome.php');
$agent = (string) file_get_contents(__DIR__ . '/../scripts/matter_agent.py');
$docs = (string) file_get_contents(__DIR__ . '/../docs/home.md');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');

assert_true(str_contains($index, 'Turn On Pairing Mode'), 'Home hint pairing mode');
assert_true(str_contains($index, 'IKEA'), 'Home hint mentions IKEA');
assert_true(str_contains($js, 'not IKEA/Matter plugs'), 'scan status clarifies Hue-only');
assert_true(str_contains($home, 'normalizeMatterPairingCode'), 'PHP normalizes codes');
assert_true(str_contains($home, 'Look for devices only finds Hue lights'), 'scan empty message');
assert_true(str_contains($agent, 'AGENT_VERSION = 72'), 'agent v72');
assert_true(str_contains($agent, 'normalize_pairing_code'), 'agent normalizes codes');
assert_true(str_contains($agent, 'friendly_commission_error'), 'friendly commission errors');
assert_true(str_contains($docs, 'Look for devices** only finds Hue'), 'docs clarify scan');
assert_true(str_contains($change, '## [4.0.324]'), 'changelog 4.0.324');

echo "test_matter_ikea_pair.php ok\n";
