<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = file_get_contents($root . '/public/index.php');
$js = file_get_contents($root . '/public/assets/app.js');
$api = file_get_contents($root . '/public/api/plans.php');
$change = file_get_contents($root . '/CHANGELOG.md');

if ($index === false || $js === false || $api === false || $change === false) {
    fwrite(STDERR, "missing files\n");
    exit(1);
}

foreach (['plans-manage', 'plans-manage-modal', 'Manage…'] as $needle) {
    if (str_contains($index, $needle)) {
        fwrite(STDERR, "index still has {$needle}\n");
        exit(1);
    }
}

foreach (['openPlansManageModal', 'deletePlan(', 'plansManage:', 'data-plan-delete'] as $needle) {
    if (str_contains($js, $needle)) {
        fwrite(STDERR, "app.js still has {$needle}\n");
        exit(1);
    }
}

if (!str_contains($api, 'Plan delete is disabled') || !str_contains($api, '403')) {
    fwrite(STDERR, "plans API must reject delete with 403\n");
    exit(1);
}
if (str_contains($api, "publish('del_plan'") || str_contains($api, "publish('del_all_plan'")) {
    fwrite(STDERR, "plans API must not publish del_plan\n");
    exit(1);
}

if (!str_contains($change, '## [4.0.214]')) {
    fwrite(STDERR, "changelog 4.0.214 missing\n");
    exit(1);
}

echo "plans no manage ok\n";
