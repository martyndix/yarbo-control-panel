<?php

declare(strict_types=1);

require __DIR__ . '/../src/YarboMatterAgentClient.php';

use Yarbo\YarboMatterAgentClient;

$dead = ['ok' => false, 'error' => 'Matter agent is not running'];
if (!YarboMatterAgentClient::isNotRunningError($dead)) {
    fwrite(STDERR, "isNotRunningError missed dead agent\n");
    exit(1);
}
if (YarboMatterAgentClient::isNotRunningError(['ok' => false, 'error' => 'Hue timed out'])) {
    fwrite(STDERR, "isNotRunningError should ignore other errors\n");
    exit(1);
}

$src = file_get_contents(__DIR__ . '/../src/YarboMatterAgentClient.php');
$auto = file_get_contents(__DIR__ . '/../src/YarboHomeAutomations.php');
$runner = file_get_contents(__DIR__ . '/../scripts/home_automations.php');
$change = file_get_contents(__DIR__ . '/../CHANGELOG.md');
if ($src === false || $auto === false || $runner === false || $change === false) {
    fwrite(STDERR, "missing sources\n");
    exit(1);
}
if (!str_contains($src, 'shouldRestartDeadAgent') || !str_contains($src, 'YARBO_MATTER_AGENT_SCRIPT')) {
    fwrite(STDERR, "client must retry a dead agent\n");
    exit(1);
}
if (str_contains($src, 'Restart the panel after enabling Home')) {
    fwrite(STDERR, "do not tell the user to restart the panel when we auto-start\n");
    exit(1);
}
if (!str_contains($auto, 'YarboMatterAgentClient::fromEnv()->ensureStarted()')) {
    fwrite(STDERR, "automations tick must start the Matter agent\n");
    exit(1);
}
if (!str_contains($runner, 'YarboMatterAgentClient.php')) {
    fwrite(STDERR, "automations runner must reload when the Matter client changes\n");
    exit(1);
}
if (!str_contains($change, '## [4.0.47]')) {
    fwrite(STDERR, "changelog 4.0.47 missing\n");
    exit(1);
}

echo "ok\n";
