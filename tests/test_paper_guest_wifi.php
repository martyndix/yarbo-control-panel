<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$fw = (string) file_get_contents($root . '/firmware/papermono/src/main.cpp');
$ver = (string) file_get_contents($root . '/firmware/papermono/src/version.h');
$php = (string) file_get_contents($root . '/src/YarboPaperDevice.php');
$docs = (string) file_get_contents($root . '/docs/papermono.md');
$change = (string) file_get_contents($root . '/CHANGELOG.md');
$html = (string) file_get_contents($root . '/public/index.php');

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

assert_true(str_contains($ver, '#define PAPERMONO_FW_VERSION "0.1.62"'), 'firmware 0.1.62');
assert_true(str_contains($php, "public const FIRMWARE_VERSION = '0.1.62';"), 'panel firmware pin 0.1.62');
assert_true(str_contains($ver, '#define PAPERMONO_WIFI_SCAN'), 'wifi scan UI constant');
assert_true(str_contains($ver, '#define PAPERMONO_WIFI_SCAN_MS 400'), 'longer dwell per channel');
assert_true(str_contains($fw, 'prefs.putString("gssid", guestSsid)'), 'guest SSID NVS');
assert_true(str_contains($fw, 'prefs.putString("gpass", guestPass)'), 'guest password NVS');
assert_true(str_contains($fw, 'prefs.getString("gssid", "")'), 'load guest SSID');
assert_true(str_contains($fw, 'REMOTE WIFI'), 'DEVICE remote wifi button');
assert_true(str_contains($fw, 'void startWifiScan()'), 'scan nearby APs');
assert_true(str_contains($fw, 'void wifiAbortJoin()'), 'scan stops the home join first');
assert_true(str_contains($fw, 'WiFi.scanNetworks(false, true, false, PAPERMONO_WIFI_SCAN_MS)'), 'full 2.4 GHz dwell');
assert_true(str_contains($fw, 'drawButton(168, 136, 144, 48, "TYPE", false)'), 'type SSID when scan is empty');
assert_true(str_contains($fw, 'void saveGuestWifi()'), 'save guest without touching home');
assert_true(str_contains($fw, 'void wifiStartHome()'), 'prefer home SSID');
assert_true(str_contains($fw, 'void wifiStartGuest()'), 'fall back to guest SSID');
assert_true(str_contains($fw, 'if (wifiSsid.length() && ssid == wifiSsid)'), 'scan skips flashed home SSID');
assert_true(str_contains($fw, "if (WiFi.status() != WL_CONNECTED) {\n        wifiStartGuest();"), 'saving at home does not join guest');
assert_true(str_contains($fw, 'drawKeyboard(int y0, bool wifiKeys)'), 'password keyboard has SAVE/shift');
assert_true(str_contains($fw, 'kbShift'), 'shift for mixed-case passwords');
assert_true(!str_contains($fw, 'wifiSsid = wifiPickSsid'), 'guest save must not overwrite home SSID');
assert_true(str_contains($fw, 'wifiSsid = doc["ssid"] | wifiSsid'), 'USB CFG still writes home SSID');
assert_true(str_contains($docs, 'REMOTE WIFI'), 'docs DEVICE remote wifi');
assert_true(str_contains($docs, 'does not overwrite USB'), 'docs home credentials stay');
assert_true(str_contains($change, '## [4.0.58]'), 'changelog 4.0.58');
assert_true(str_contains($change, '## [4.0.59]'), 'changelog 4.0.59');
assert_true(str_contains($html, 'firmware 0.1.62'), 'settings hint 0.1.62');

echo "test_paper_guest_wifi.php ok\n";
