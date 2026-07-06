<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yarbo\YarboMqtt;

$config = require __DIR__ . '/../config.php';

$commands = [
    'get_map',
    'read_clean_area',
    'read_all_plan',
    'read_recharge_point',
];

$writeCandidates = [
    'set_map',
    'save_map',
    'write_clean_area',
    'set_clean_area',
    'del_map',
    'update_map',
];

$probeWrites = in_array('--probe-writes', $argv, true);
$sendProbes = in_array('--send-probes', $argv, true);

$attempts = 3;
$timeoutSeconds = 6.0;
$dumpDir = __DIR__ . '/../debug/map-dumps';

if (!is_dir($dumpDir) && !mkdir($dumpDir, 0775, true) && !is_dir($dumpDir)) {
    fwrite(STDERR, "Failed to create dump directory: {$dumpDir}\n");
    exit(1);
}

$summary = [
    'host' => (string) ($config['broker_host'] ?? ''),
    'port' => (int) ($config['broker_port'] ?? 1883),
    'serial' => (string) ($config['serial'] ?? ''),
    'started_at' => gmdate('c'),
    'attempts_per_command' => $attempts,
    'timeout_seconds' => $timeoutSeconds,
    'probe_writes' => $probeWrites,
    'send_probes' => $sendProbes,
    'results' => [],
];

$client = new YarboMqtt(
    (string) $config['broker_host'],
    (int) ($config['broker_port'] ?? 1883),
    (string) ($config['serial'] ?? '')
);

echo "Connecting to {$summary['host']}:{$summary['port']} SN={$summary['serial']}\n";

try {
    $client->connect();

    foreach ($commands as $cmd) {
        echo "\n=== {$cmd} ===\n";
        $summary['results'][$cmd] = [
            'attempts' => [],
            'classification' => 'not_supported',
        ];

        for ($i = 1; $i <= $attempts; $i++) {
            echo "Attempt {$i}/{$attempts}... ";
            $response = $client->requestDataFeedback($cmd, [], $timeoutSeconds, true);

            if ($response === null) {
                echo "no response\n";
                $summary['results'][$cmd]['attempts'][] = [
                    'attempt' => $i,
                    'ok' => false,
                    'error' => 'timeout_or_no_match',
                ];
                continue;
            }

            $data = $response['data'] ?? null;
            $dataKeys = is_array($data) ? array_keys($data) : [];
            $nonEmptyData = is_array($data) && $data !== [];

            echo 'response topic=' . ($response['topic'] ?? 'n/a')
                . ' state=' . (string) ($response['state'] ?? 'n/a')
                . ' data_keys=' . implode(',', $dataKeys) . "\n";

            $summary['results'][$cmd]['attempts'][] = [
                'attempt' => $i,
                'ok' => true,
                'topic' => $response['topic'] ?? null,
                'state' => $response['state'] ?? null,
                'data_non_empty' => $nonEmptyData,
                'data_keys' => $dataKeys,
                'response' => $response,
            ];
        }

        $attemptRows = $summary['results'][$cmd]['attempts'];
        $successful = array_values(array_filter(
            $attemptRows,
            static fn (array $row): bool => (bool) ($row['ok'] ?? false)
        ));
        $nonEmpty = array_values(array_filter(
            $successful,
            static fn (array $row): bool => (bool) ($row['data_non_empty'] ?? false)
        ));

        if ($successful === []) {
            $summary['results'][$cmd]['classification'] = 'not_supported';
        } elseif ($nonEmpty === []) {
            $summary['results'][$cmd]['classification'] = 'supported_but_empty';
        } else {
            $summary['results'][$cmd]['classification'] = 'geometry_or_structured_data_present';
        }
    }

    if ($probeWrites) {
        echo "\n=== Write command probes ===\n";
        if (!$sendProbes) {
            echo "Dry run — listing candidate write commands only. Re-run with --send-probes to send empty payloads.\n";
        } else {
            echo "Sending empty [] payloads to candidate write commands (safe probe only).\n";
        }

        $summary['write_probes'] = [];
        foreach ($writeCandidates as $cmd) {
            echo "\n--- {$cmd} ---\n";
            $probe = [
                'command' => $cmd,
                'sent' => false,
                'classification' => 'documented_only',
            ];

            if ($sendProbes) {
                $probe['sent'] = true;
                $response = $client->requestDataFeedback($cmd, [], $timeoutSeconds, true);
                if ($response === null) {
                    $probe['classification'] = 'not_supported';
                    $probe['error'] = 'timeout_or_no_match';
                    echo "no response\n";
                } else {
                    $state = $response['state'] ?? null;
                    $probe['response'] = $response;
                    if ($state === 0 || $state === '0') {
                        $probe['classification'] = 'acknowledged';
                    } else {
                        $probe['classification'] = 'error';
                    }
                    echo 'state=' . (string) $state . ' topic=' . ($response['topic'] ?? 'n/a') . "\n";
                }
            } else {
                echo "candidate (not sent)\n";
            }

            $summary['write_probes'][$cmd] = $probe;
        }
    }
} catch (Throwable $e) {
    $summary['fatal_error'] = $e->getMessage();
    fwrite(STDERR, "Discovery failed: {$e->getMessage()}\n");
} finally {
    try {
        $client->disconnect();
    } catch (Throwable) {
    }
}

$summary['completed_at'] = gmdate('c');
$timestamp = gmdate('Ymd_His');
$outPath = "{$dumpDir}/map_discovery_{$timestamp}.json";
file_put_contents($outPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\nSaved discovery dump:\n{$outPath}\n";
echo "\nClassifications:\n";
foreach ($summary['results'] as $cmd => $result) {
    echo " - {$cmd}: {$result['classification']}\n";
}

if ($probeWrites && isset($summary['write_probes'])) {
    echo "\nWrite probe classifications:\n";
    foreach ($summary['write_probes'] as $cmd => $probe) {
        echo " - {$cmd}: {$probe['classification']}\n";
    }
}
