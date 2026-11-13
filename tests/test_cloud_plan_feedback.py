#!/usr/bin/env python3
"""Cloud MQTT must cache plan_feedback so Vestaboard can show WORK DONE n%."""

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def load(name: str, relative: str):
    path = ROOT / relative
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec and spec.loader
    mod = importlib.util.module_from_spec(spec)
    sys.modules[name] = mod
    spec.loader.exec_module(mod)
    return mod


def main() -> None:
    cloud = load("yarbo_cloud_control_test", "scripts/yarbo_cloud_control.py")
    agent = load("mqtt_agent_plan_fb_test", "scripts/mqtt_agent.py")

    src = (ROOT / "scripts/yarbo_cloud_control.py").read_text(encoding="utf-8")
    assert "fetch_plan_feedback" in src, "cloud client fetches plan feedback"
    assert "take_cached_plan_feedback" in src, "cloud client exposes cached feedback"
    assert "_on_data_feedback" in src, "data_feedback callback must cache progress"
    assert "device/plan_feedback" in src, "subscribe legacy plan_feedback topic"
    assert "lambda _t, _d: None" not in src, "must not ignore data_feedback"

    agent_src = (ROOT / "scripts/mqtt_agent.py").read_text(encoding="utf-8")
    assert "plan_feedback cloud poll" in agent_src, "agent polls cloud plan feedback"
    assert "fetch_plan_feedback" in agent_src, "agent calls cloud fetch"
    assert "take_cached_plan_feedback" in agent_src, "agent drains cloud cache"

    fb = cloud._unwrap_plan_feedback(
        {
            "topic": "get_plan_feedback",
            "state": 0,
            "data": {
                "actualCleanArea": 42.5,
                "totalCleanArea": 100.0,
                "plan_id": "abc",
            },
        }
    )
    assert isinstance(fb, dict), "unwrap returns dict"
    assert fb.get("actualCleanArea") == 42.5, "actual area preserved"
    assert fb.get("totalCleanArea") == 100.0, "total area preserved"

    client = cloud.YarboCloudControlClient("a@b.c", "x", "SN")
    client._store_plan_feedback(fb)
    taken = client.take_cached_plan_feedback()
    assert taken == fb, "cached feedback returned"
    print("cloud plan feedback ok")


if __name__ == "__main__":
    import subprocess

    main()

    php = ROOT / "tests" / "_cloud_plan_feedback_php.php"
    php.write_text(
        """<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use Yarbo\\YarboTelemetry;
use Yarbo\\YarboVestaboard;
$raw = [
    'StateMSG' => [
        'on_going_planning' => 1,
        'working_state' => 1,
        'planning_paused' => 0,
        'error_code' => 0,
    ],
    'BatteryMSG' => ['battery_percent' => 96, 'charging_status' => 0],
    'HeadMsg' => ['head_type' => 1],
    'plan_feedback' => [
        'actualCleanArea' => 42.5,
        'totalCleanArea' => 100.0,
    ],
];
$parsed = YarboTelemetry::parse($raw);
if (empty($parsed['plan_running'])) { fwrite(STDERR, "plan_running false\\n"); exit(1); }
$pct = $parsed['plan_status']['plan_percent'] ?? null;
if (!is_numeric($pct)) { fwrite(STDERR, "no plan_percent\\n"); exit(1); }
$root = sys_get_temp_dir() . '/yarbo-vb-progress-' . bin2hex(random_bytes(3));
mkdir($root . '/data', 0775, true);
file_put_contents($root . '/data/hub-config.json', json_encode(['modules' => ['yarbo' => true]]));
$vb = new YarboVestaboard($root);
$layout = $vb->compose($parsed, true);
$line3 = $layout['lines'][2] ?? '';
if (!str_contains($line3, 'WORK DONE') || !str_contains($line3, '%')) {
    fwrite(STDERR, "expected WORK DONE percent, got: {$line3}\\n");
    exit(1);
}
echo "php work done ok\\n";
""",
        encoding="utf-8",
    )
    proc = subprocess.run(["php", str(php)], cwd=str(ROOT), capture_output=True, text=True)
    print(proc.stdout, end="")
    if proc.returncode != 0:
        sys.stderr.write(proc.stderr)
        php.unlink(missing_ok=True)
        raise SystemExit(proc.returncode)
    php.unlink(missing_ok=True)

    vb = (ROOT / "src/YarboVestaboard.php").read_text(encoding="utf-8")
    assert "applyActivityLivePolicy" in vb, "Smart Display policy exists"
    assert "smart_display" in vb, "smart_display setting exists"
    assert "resolveActivityFocus" in vb, "activity focus swap exists"
    assert "activity = $this->applyActivityLivePolicy()" in vb, "tick still applies Smart Display"
    change = (ROOT / "CHANGELOG.md").read_text(encoding="utf-8")
    assert "## [4.0.288]" in change, "changelog 4.0.288"
    print("smart display wiring ok")
