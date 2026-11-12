<?php

declare(strict_types=1);

function assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$js = (string) file_get_contents(__DIR__ . '/../public/assets/app.js');
assert_true(str_contains($js, 'Only rewrite real gateway/proxy failures'), 'formatConnectionError documents no Retrying→504 rewrite');
assert_true(!str_contains($js, "lower.includes('retrying…')\n        || lower.includes('retrying...')"), 'formatConnectionError no longer maps all Retrying to 504');
assert_true(str_contains($js, "lower.includes('panel gateway timed out')"), 'gateway matcher is specific');
assert_true(str_contains($js, 'Keep polling while Settings is open'), 'status polls during Settings');
assert_true(str_contains($js, 'Do not abort status here'), 'opening Settings does not abort status');
assert_true(str_contains($js, 'Transient robot offline / miss cooldown is expected'), 'transient clears banner');
assert_true(str_contains($js, 'if (data.transient) {'), 'transient branch clears error');

$err = (string) file_get_contents(__DIR__ . '/../src/YarboErrors.php');
assert_true(str_contains($err, "str_contains(\$lower, 'telemetry timeout')"), 'telemetry timeout maps to robot reply message');

$change = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
assert_true(str_contains($change, '## [4.0.271]'), 'changelog 4.0.271');

echo "ok\n";
