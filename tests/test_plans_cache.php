<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$js = file_get_contents($root . '/public/assets/app.js');
$index = file_get_contents($root . '/public/index.php');
$change = file_get_contents($root . '/CHANGELOG.md');

if ($js === false || $index === false || $change === false) {
    fwrite(STDERR, "missing files\n");
    exit(1);
}

foreach ([
    "PLAN_CACHE_KEY = 'yarbo_plans_cache'",
    'PLAN_CACHE_TTL_MS = 24 * 60 * 60 * 1000',
    'function savePlansCache',
    'function restorePlansCache',
    'function maybeRefreshPlansCache',
    'restorePlansCache()',
    'maybeRefreshPlansCache({ force: !restoredPlans })',
    'quiet: true',
] as $needle) {
    if (!str_contains($js, $needle)) {
        fwrite(STDERR, "app.js missing: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($index, 'Auto-refreshes about once a day') && !str_contains($index, 'refresh quietly about once a day')) {
    fwrite(STDERR, "index hint should mention daily refresh\n");
    exit(1);
}

if (!str_contains($change, '## [4.0.213]')) {
    fwrite(STDERR, "changelog 4.0.213 missing\n");
    exit(1);
}

echo "plans cache ok\n";
