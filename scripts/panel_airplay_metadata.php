<?php

declare(strict_types=1);

/**
 * Read shairport-sync metadata pipe and keep data/airplay/session.json client_name fresh.
 * Runs until killed; started by panel_airplay_ensure / start_receiver.
 */

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "autoload missing\n");
    exit(1);
}
require $autoload;

use Yarbo\YarboPanelAirPlay;

$airplay = new YarboPanelAirPlay($root);
$pipe = $airplay->metadataPipePath();
$dir = dirname($pipe);
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
if (!file_exists($pipe)) {
    @posix_mkfifo($pipe, 0666);
    if (!file_exists($pipe)) {
        @shell_exec('mkfifo -m 666 ' . escapeshellarg($pipe) . ' 2>/dev/null');
    }
}
if (!file_exists($pipe)) {
    fwrite(STDERR, "metadata fifo missing\n");
    exit(1);
}

$fh = fopen($pipe, 'rb');
if ($fh === false) {
    fwrite(STDERR, "cannot open metadata fifo\n");
    exit(1);
}
stream_set_blocking($fh, true);

$buf = '';
while (!feof($fh)) {
    $chunk = fread($fh, 4096);
    if ($chunk === false || $chunk === '') {
        usleep(200000);
        continue;
    }
    $buf .= $chunk;
    while (true) {
        if (!preg_match(
            '/<item>\s*<type>([^<]*)<\/type>\s*<code>([^<]*)<\/code>\s*<length>(\d+)<\/length>/s',
            $buf,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            if (strlen($buf) > 65536) {
                $buf = substr($buf, -8192);
            }
            break;
        }
        $start = (int) $m[0][1];
        $headerLen = strlen($m[0][0]);
        $typeRaw = html_entity_decode(trim($m[1][0]));
        $codeRaw = html_entity_decode(trim($m[2][0]));
        $length = (int) $m[3][0];
        $dataStart = $start + $headerLen;
        // Optional data payload may be raw or base64 inside <data>.
        $need = $dataStart + $length + 32;
        if (strlen($buf) < $need && $length > 0) {
            break;
        }
        $slice = substr($buf, $dataStart, max(0, $length + 64));
        $buf = substr($buf, $dataStart + max($length, 0));
        $type = decodeMetaTag($typeRaw);
        $code = decodeMetaTag($codeRaw);
        $data = '';
        if (preg_match('/<data[^>]*>([^<]*)<\/data>/s', $slice, $dm)) {
            $enc = $dm[1];
            $decoded = base64_decode($enc, true);
            $data = $decoded !== false ? $decoded : $enc;
        } elseif ($length > 0) {
            $data = substr($slice, 0, $length);
        }
        // ssnc/snam = AirPlay client (source) device name.
        if ($type === 'ssnc' && $code === 'snam') {
            $name = trim(preg_replace('/\s+/', ' ', $data) ?? '');
            if ($name !== '') {
                $airplay->setClientName($name);
            }
        }
        // Clear stale name hints when session ends.
        if ($type === 'ssnc' && ($code === 'pend' || $code === 'end ')) {
            // keep last name until next snam; inactive hook clears active flag
        }
    }
}

fclose($fh);

function decodeMetaTag(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    // shairport often sends type/code as 4-char hex encoded ASCII (e.g. 73736e63 = ssnc).
    if (preg_match('/^[0-9a-fA-F]{8}$/', $raw)) {
        $bin = @hex2bin($raw);
        if (is_string($bin) && strlen($bin) === 4) {
            return $bin;
        }
    }
    $b64 = base64_decode($raw, true);
    if (is_string($b64) && strlen($b64) === 4) {
        return $b64;
    }

    return strlen($raw) >= 4 ? substr($raw, 0, 4) : $raw;
}
