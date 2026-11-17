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
assert_true(YarboMatterAgentClient::MIN_VERSION === 79, 'min agent 79');

$index = (string) file_get_contents(__DIR__ . '/../public/index.php');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
$home = (string) file_get_contents(__DIR__ . '/../src/YarboHome.php');
$api = (string) file_get_contents(__DIR__ . '/../public/api/home.php');
$agent = (string) file_get_contents(__DIR__ . '/../scripts/matter_agent.py');
$docs = (string) file_get_contents(__DIR__ . '/../docs/home.md');
$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');

assert_true(str_contains($index, 'Turn On Pairing Mode'), 'Home hint pairing mode');
assert_true(str_contains($index, 'IKEA'), 'Home hint mentions IKEA');
assert_true(str_contains($js, 'not IKEA/Matter plugs'), 'scan status clarifies Hue-only');
assert_true(str_contains($js, "action: 'commission_start'"), 'UI starts async commission');
assert_true(str_contains($js, "action: 'commission_status'"), 'UI polls commission status');
assert_true(!str_contains($js, "action: 'commission', code }, 135000"), 'UI does not sync-wait 135s');
assert_true(str_contains($home, 'normalizeMatterPairingCode'), 'PHP normalizes codes');
assert_true(str_contains($home, 'function commissionStart'), 'PHP commissionStart');
assert_true(str_contains($home, 'function commissionStatus'), 'PHP commissionStatus');
assert_true(str_contains($home, 'Look for devices only finds Hue lights'), 'scan empty message');
assert_true(str_contains($api, "'commission_start'"), 'API commission_start');
assert_true(str_contains($api, "'commission_status'"), 'API commission_status');
assert_true(str_contains($agent, 'AGENT_VERSION = 79'), 'agent v79');
assert_true(str_contains($agent, 'normalize_pairing_code'), 'agent normalizes codes');
assert_true(str_contains($agent, 'friendly_commission_error'), 'friendly commission errors');
assert_true(str_contains($agent, 'prepare_for_commission'), 'warm Matter before pair');
assert_true(str_contains($agent, 'start_commission_job'), 'async commission job');
assert_true(str_contains($agent, 'commission_status'), 'agent commission_status op');
assert_true(str_contains($agent, 'cleanup_failed_commission_node'), 'cleanup failed node');
assert_true(str_contains($agent, 'ensure_matter_primary_interface'), 'rebind primary iface');
assert_true(str_contains($agent, 'container_primary_interface_state'), 'iface state missing vs unknown');
assert_true(str_contains($agent, 'commission_error_looks_discovery'), 'discovery retry helper');
assert_true(str_contains($agent, 'ff00::/8'), 'ipv6 multicast route');
assert_true(str_contains($agent, 'leaving healthy server alone'), 'do not tear down healthy server');
assert_true(str_contains($agent, 'discovery_preflight_error'), 'discovery preflight');
assert_true(str_contains($agent, 'OCCUPANCY_SENSING = 0x0406'), 'occupancy sensing');
assert_true(str_contains($docs, 'Look for devices** only finds Hue'), 'docs clarify scan');
assert_true(str_contains($docs, 'Motion / occupancy'), 'docs motion parity');
assert_true(str_contains($docs, 'error 28'), 'docs error 28');
assert_true(str_contains($docs, 'error_code 1'), 'docs error_code 1');
assert_true(str_contains($docs, 'gateway 524'), 'docs gateway 524');
assert_true(str_contains($docs, '4.0.331+'), 'docs 4.0.331 discovery');
assert_true(str_contains($change, '## [4.0.324]'), 'changelog 4.0.324');
assert_true(str_contains($change, '## [4.0.325]'), 'changelog 4.0.325');
assert_true(str_contains($change, '## [4.0.326]'), 'changelog 4.0.326');
assert_true(str_contains($change, '## [4.0.327]'), 'changelog 4.0.327');
assert_true(str_contains($change, '## [4.0.328]'), 'changelog 4.0.328');
assert_true(str_contains($change, '## [4.0.329]'), 'changelog 4.0.329');
assert_true(str_contains($change, '## [4.0.330]'), 'changelog 4.0.330');
assert_true(str_contains($change, '## [4.0.331]'), 'changelog 4.0.331');

echo "test_matter_ikea_pair.php ok\n";
