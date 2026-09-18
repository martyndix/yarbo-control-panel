<?php

declare(strict_types=1);

function panel_version(): string
{
    $changelog = dirname(__DIR__) . '/CHANGELOG.md';
    if (!is_file($changelog)) {
        return '0';
    }

    $handle = fopen($changelog, 'rb');
    if ($handle === false) {
        return '0';
    }

    while (($line = fgets($handle)) !== false) {
        if (preg_match('/^## \[([^\]]+)\]/', $line, $matches) && $matches[1] !== 'Unreleased') {
            fclose($handle);

            return $matches[1];
        }
    }

    fclose($handle);

    return '0';
}

$panelVersion = panel_version();
$jsMtime = (int) (@filemtime(__DIR__ . '/assets/app.js') ?: 0);
$cssMtime = (int) (@filemtime(__DIR__ . '/assets/style.css') ?: 0);
$assetVersion = $panelVersion . '.' . (string) (max($jsMtime, $cssMtime) ?: time());
require_once dirname(__DIR__) . '/src/YarboHub.php';
$panelTitle = \Yarbo\YarboHub::panelTitle((new \Yarbo\YarboHub(dirname(__DIR__)))->houseName());
$panelTitleSafe = htmlspecialchars($panelTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $panelTitleSafe ?></title>
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('yarbo_theme') || 'auto';
                var resolved = theme;
                if (theme === 'auto') {
                    resolved = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                document.documentElement.setAttribute('data-theme', resolved);
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    <link rel="stylesheet" href="/assets/style.css?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>">
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
        crossorigin=""
    >
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css"
        crossorigin=""
    >
</head>
<body>
<?php
$config = require dirname(__DIR__) . '/config.php';
$camerasEnabled = (bool) ($config['cameras_enabled'] ?? true);
?>
    <main class="container">
        <header class="app-header">
            <div>
                <h1 id="panel-title"><?= $panelTitleSafe ?></h1>
                <p class="robot-name hidden" id="device-name"></p>
                <nav class="module-switcher hidden" id="module-switcher" aria-label="Modules"></nav>
            </div>
            <div class="settings-button-wrap">
                <button
                    type="button"
                    id="settings-open"
                    class="btn btn-secondary btn-settings"
                    aria-haspopup="dialog"
                    aria-controls="settings-modal"
                >Settings</button>
                <span
                    id="settings-update-badge"
                    class="settings-update-badge hidden"
                    aria-hidden="true"
                    title="Panel update available"
                ></span>
            </div>
        </header>

        <section id="error-banner" class="banner error hidden" role="alert"></section>

        <div id="panel-sections" class="panel-sections">

        <section class="card panel-section status-card" data-panel-id="status" data-module="yarbo">
            <div class="section-header section-header--simple">
                <h2>Status</h2>
                <button type="button" class="section-drag-handle" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button>
            </div>
            <div class="status-grid">
                <div class="stat">
                    <span class="label">Battery</span>
                    <span id="battery" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">State</span>
                    <span id="state" class="value badge">—</span>
                </div>
                <div class="stat">
                    <span class="label">Charging</span>
                    <span id="charging" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">Heading</span>
                    <span id="heading" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">Head</span>
                    <span id="head-type" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">Error</span>
                    <span id="error-code" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">Rain</span>
                    <span id="rain" class="value">—</span>
                </div>
            </div>
            <p class="updated">Last updated: <span id="updated-at">never</span></p>
        </section>

        <section class="card panel-section vestaboard-card hidden" id="vestaboard-card" data-panel-id="vestaboard" data-module="shared">
            <div class="section-header section-header--simple vestaboard-card-header">
                <div class="vestaboard-card-heading">
                    <h2>Vestaboard Note</h2>
                    <nav class="vestaboard-live-switch hidden" id="vestaboard-live-switch" aria-label="Vestaboard view">
                        <button type="button" class="module-switcher-btn" data-vestaboard-live="yarbo">Yarbo</button>
                        <button type="button" class="module-switcher-btn" data-vestaboard-live="powerwall">Powerwall</button>
                        <button type="button" class="module-switcher-btn" data-vestaboard-live="lymow">Lymow</button>
                        <button type="button" class="module-switcher-btn" data-vestaboard-live="batteries">ALL</button>
                        <button type="button" class="module-switcher-btn" id="vestaboard-rotate-open" aria-haspopup="dialog" aria-controls="vestaboard-rotate-modal">Rotate</button>
                    </nav>
                </div>
                <button type="button" class="section-drag-handle" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button>
            </div>
            <div class="vestaboard-preview vestaboard-preview--dashboard" id="vestaboard-board" aria-label="Vestaboard Note 3 by 15 live display"></div>
            <p class="updated">Last written: <span id="vestaboard-updated-at">never</span><span id="vestaboard-updated-detail"></span></p>
            <button type="button" class="btn btn-secondary vestaboard-resume hidden" id="vestaboard-resume">Resume previous status</button>
        </section>

        <section class="card panel-section diagnostics-card" data-panel-id="diagnostics" data-module="yarbo">
            <div class="section-header section-header--simple">
                <h2>Connection &amp; Health</h2>
                <button type="button" class="section-drag-handle" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button>
            </div>
            <div class="diagnostics-grid">
                <div class="stat">
                    <span class="label">Connection Type</span>
                    <span id="connection-type" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">Connection Status</span>
                    <span id="connection-status" class="value badge">—</span>
                </div>
                <div class="stat">
                    <span class="label">WiFi Network</span>
                    <span id="wifi-network" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">WiFi Signal</span>
                    <span id="wifi-signal" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">WiFi Security</span>
                    <span id="wifi-security" class="value">—</span>
                </div>
                <div class="stat" id="battery-temp-stat">
                    <span class="label">Battery Temp</span>
                    <button type="button" id="battery-temp" class="value value-button" disabled title="Cell temperatures">—</button>
                </div>
                <div class="stat">
                    <span class="label">Wireless Charge</span>
                    <span id="wireless-charge" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">RTK Status</span>
                    <span id="rtk-status" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">RTCM Age</span>
                    <span id="rtcm-age" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">Route Priority</span>
                    <span id="route-priority" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">Rain Sensor</span>
                    <span id="rain-sensor" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">Net Module</span>
                    <span id="net-module-status" class="value">—</span>
                </div>
            </div>
        </section>

        <section class="card panel-section map-card" data-panel-id="map" data-module="yarbo">
            <div class="section-header">
                <h2>Location Map</h2>
                <div class="section-header-actions">
                    <div class="map-mode">
                    <label>
                        <input type="radio" name="map-layer" value="street" checked>
                        Street
                    </label>
                    <label>
                        <input type="radio" name="map-layer" value="satellite">
                        Satellite
                    </label>
                    </div>
                    <button
                        type="button"
                        class="map-fullscreen-btn"
                        id="map-fullscreen"
                        aria-pressed="false"
                        title="Full screen"
                        aria-label="Full screen map"
                    >
                        <span class="map-fullscreen-btn__enter" aria-hidden="true">
                            <svg viewBox="0 0 16 16" width="16" height="16" focusable="false">
                                <path fill="currentColor" d="M2 6V2h4v1.5H3.5V6H2zm8-4h4v4h-1.5V3.5H10V2zM2 10h1.5v2.5H6V14H2v-4zm12 0v4h-4v-1.5h2.5V10H14z"/>
                            </svg>
                        </span>
                        <span class="map-fullscreen-btn__exit hidden" aria-hidden="true">
                            <svg viewBox="0 0 16 16" width="16" height="16" focusable="false">
                                <path fill="currentColor" d="M6 2v4H2V4.5h2.5V2H6zm4 0h1.5v2.5H14V6h-4V2zM2 11.5h2.5V14H6v-4H2v1.5zM10 10h4v1.5h-2.5V14H10v-4z"/>
                            </svg>
                        </span>
                    </button>
                    <button type="button" class="section-drag-handle" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button>
                </div>
            </div>
            <p class="hint">Live GPS from RTK telemetry. Valid GPS lock is required (outdoors).</p>
            <div class="map-actions">
                <label class="data-source-field">
                    Map data
                    <select id="map-data-source">
                        <option value="auto">Auto (local, then cloud)</option>
                        <option value="local">Local MQTT only</option>
                        <option value="cloud">Cloud only</option>
                    </select>
                </label>
                <button type="button" class="btn btn-secondary" id="map-load-areas">Load saved mowing areas</button>
            </div>
            <p id="map-edit-tip" class="map-edit-tip hidden">Drag vertices to reshape zones.</p>
            <div class="map-wrap">
                <div id="map" class="map"></div>
                <div id="map-loading" class="map-loading hidden" aria-live="polite" aria-busy="false">
                    <div class="map-loading-spinner" aria-hidden="true"></div>
                    <div class="map-loading-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                        <div class="map-loading-bar-fill"></div>
                    </div>
                    <p id="map-loading-text" class="map-loading-text">Loading saved map areas…</p>
                </div>
            </div>
            <p id="map-status" class="map-status">Waiting for GPS fix...</p>
            <p id="map-areas-status" class="map-areas-status">Saved areas: not loaded yet.</p>
            <div id="map-inspector" class="map-inspector hidden">
                <details>
                    <summary>Map zones</summary>
                    <ul id="map-zone-list" class="map-zone-list"></ul>
                    <div class="map-editor-actions">
                        <button type="button" class="btn btn-secondary" id="map-edit-toggle">Edit map (draft)</button>
                        <button type="button" class="btn btn-secondary" id="map-export">Export GeoJSON</button>
                        <button type="button" class="btn btn-secondary" id="map-export-draft" disabled>Export draft</button>
                        <button
                            type="button"
                            class="btn btn-secondary"
                            id="map-save-robot"
                            disabled
                            title="Map write MQTT commands are not yet verified — use the Yarbo app or export a draft"
                        >Save to robot</button>
                    </div>
                    <p class="hint map-editor-hint">Drag polygon corners to adjust boundaries. Changes are local until Save to robot is supported.</p>
                </details>
            </div>
        </section>

        <?php if ($camerasEnabled): ?>
        <section class="card panel-section cameras-card" data-panel-id="cameras" data-module="yarbo">
            <div class="section-header">
                <h2>Cameras</h2>
                <div class="section-header-actions">
                    <div class="camera-mode">
                    <label>
                        <input type="radio" name="camera-mode" value="stream" checked>
                        Live
                    </label>
                    <label>
                        <input type="radio" name="camera-mode" value="snapshot">
                        Snapshot
                    </label>
                    </div>
                    <button type="button" class="section-drag-handle" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button>
                </div>
            </div>
            <p class="hint">The Yarbo app uses cloud video. This panel needs a local RTSP tunnel — see steps below.</p>
            <div id="camera-alert" class="banner warning hidden" role="status"></div>
            <ol id="camera-setup" class="camera-setup hidden"></ol>
            <div class="camera-actions">
                <button type="button" class="btn btn-secondary" id="camera-prepare">Prepare cameras (MQTT)</button>
                <button type="button" class="btn btn-secondary" id="camera-recheck">Recheck streams</button>
            </div>
            <div id="camera-grid" class="camera-grid"></div>
            <p id="camera-note" class="camera-note hidden"></p>
        </section>
        <?php endif; ?>

        <section class="card panel-section drive-card" data-panel-id="drive" data-module="yarbo">
            <div class="section-header section-header--simple">
                <h2>Manual Drive</h2>
                <button type="button" class="section-drag-handle" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button>
            </div>
            <p class="hint">Connect the controller below first, then hold a direction to move. Release to stop. Use only on flat, clear ground. Watching the map and status does not need the controller.</p>
            <p class="drive-block-banner hidden" id="drive-block-banner" role="status"></p>
            <div class="drive-controller-row">
                <button type="button" class="control-tile" id="control-controller-drive" data-control="controller" aria-pressed="false" title="Connect app controller">
                    <span class="control-tile-icon" data-controller-icon aria-hidden="true">📴</span>
                    <span class="control-tile-label" data-controller-label>Off</span>
                </button>
                <p class="drive-controller-note" id="drive-controller-note">Controller required for drive.</p>
            </div>
            <div class="dpad" id="drive-pad">
                <button type="button" class="btn btn-drive" data-drive="forward" aria-label="Forward" disabled>▲</button>
                <button type="button" class="btn btn-drive" data-drive="left" aria-label="Turn left" disabled>◀</button>
                <button type="button" class="btn btn-drive btn-drive-stop" data-drive="stop" aria-label="Stop">■</button>
                <button type="button" class="btn btn-drive" data-drive="right" aria-label="Turn right" disabled>▶</button>
                <button type="button" class="btn btn-drive" data-drive="backward" aria-label="Backward" disabled>▼</button>
            </div>
            <p class="drive-status" id="drive-status">Ready</p>
        </section>

        <section class="card panel-section plans-card" data-panel-id="plans" data-module="yarbo">
            <div class="section-header section-header--simple">
                <h2>Work Plans</h2>
                <button type="button" class="section-drag-handle" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button>
            </div>
            <p class="hint">Load saved plans from the robot (read-only — does not stop a running job). Start at 0% means from the beginning. Starting a plan wakes the robot and holds control so the official app cannot cancel it.</p>
            <div class="plans-toolbar">
                <label class="plan-percent">
                    Start at
                    <input type="range" id="plan-start-percent" min="0" max="100" value="0">
                    <span id="plan-start-percent-label">0%</span>
                </label>
                <label class="data-source-field">
                    Plan data
                    <select id="plans-data-source">
                        <option value="auto">Auto (local, then cloud)</option>
                        <option value="local">Local MQTT only</option>
                        <option value="cloud">Cloud only</option>
                    </select>
                </label>
                <button type="button" class="btn btn-secondary" id="plans-load">Load plans</button>
                <button type="button" class="btn-text" id="plans-manage" disabled title="Load plans first">Manage…</button>
            </div>
            <div id="plans-status" class="plan-activity is-idle">
                <div class="plan-activity-row">
                    <span id="plans-activity-badge" class="badge">Idle</span>
                    <span id="plans-activity-title" class="plan-activity-title">No plan running</span>
                </div>
                <div id="plans-activity-progress" class="plan-activity-progress hidden">
                    <div
                        id="plans-activity-bar-wrap"
                        class="plan-activity-bar"
                        role="progressbar"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-valuenow="0"
                        aria-label="Work plan progress"
                    >
                        <div id="plans-activity-bar" class="plan-activity-bar-fill"></div>
                    </div>
                    <span id="plans-activity-pct" class="plan-activity-pct"></span>
                </div>
                <p id="plans-activity-detail" class="plan-activity-detail hidden"></p>
            </div>
            <p id="plans-note" class="plans-note">No plans loaded yet.</p>
            <div id="plans-list" class="plans-list"></div>
        </section>

        <section class="card panel-section waypoints-card" data-panel-id="waypoints" data-module="yarbo">
            <div class="section-header section-header--simple">
                <h2>Waypoints</h2>
                <button type="button" class="section-drag-handle" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button>
            </div>
            <p class="hint">The robot does not expose a documented MQTT command to list stored waypoints. Save friendly names here (mapped to robot indices) for one-click navigation via <code>start_way_point</code>.</p>
            <div id="waypoints-list" class="waypoints-list"></div>
            <p id="waypoints-note" class="waypoints-note">No saved waypoints yet.</p>
            <form id="waypoint-save-form" class="waypoint-save-form">
                <label class="settings-field">
                    <span class="label">Name</span>
                    <input type="text" id="waypoint-name" maxlength="80" placeholder="Front gate" required>
                </label>
                <label class="settings-field">
                    <span class="label">Robot index</span>
                    <input type="number" id="waypoint-index" min="0" max="9999" value="0" inputmode="numeric" required>
                </label>
                <button type="submit" class="btn btn-secondary" id="waypoint-save">Save waypoint</button>
            </form>
        </section>

        <section class="card panel-section head-card hidden" id="head-controls-card" data-panel-id="head" data-module="yarbo">
            <div class="section-header section-header--simple">
                <h2>Head controls</h2>
                <button type="button" class="section-drag-handle" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button>
            </div>
            <p class="hint" id="head-controls-hint">Controls for the attached Yarbo head (mower or snow blower).</p>
            <div id="head-mower-controls" class="head-controls hidden">
                <label class="settings-field">
                    <span class="label">Blade height</span>
                    <input type="range" id="mower-blade-height" min="0" max="100" value="50">
                    <span id="mower-blade-height-label">50</span>
                </label>
                <button type="button" class="btn btn-secondary" id="mower-blade-height-send">Set blade height</button>
                <label class="settings-field">
                    <span class="label">Blade speed</span>
                    <input type="range" id="mower-blade-speed" min="0" max="100" value="50">
                    <span id="mower-blade-speed-label">50</span>
                </label>
                <button type="button" class="btn btn-secondary" id="mower-blade-speed-send">Set blade speed</button>
            </div>
            <div id="head-snow-controls" class="head-controls hidden">
                <label class="settings-field">
                    <span class="label">Chute angle</span>
                    <input type="range" id="snow-chute-angle" min="0" max="180" value="90">
                    <span id="snow-chute-angle-label">90°</span>
                </label>
                <button type="button" class="btn btn-secondary" id="snow-chute-angle-send">Set chute angle</button>
            </div>
        </section>

        <section class="card panel-section controls-card" data-panel-id="controls" data-module="yarbo">
            <div class="section-header section-header--simple">
                <h2>Controls</h2>
                <button type="button" class="section-drag-handle" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button>
            </div>
            <p class="hint">Watching live status does not take control. Lights, drive, and buzzer need Controller On (that will take over from the phone app). Starting a plan or waypoint holds control quietly so the app cannot cancel the job.</p>
            <div class="control-tiles" id="control-tiles">
                <button type="button" class="control-tile" id="control-controller" data-control="controller" aria-pressed="false" title="Connect app controller">
                    <span class="control-tile-icon" data-controller-icon aria-hidden="true">📴</span>
                    <span class="control-tile-label" data-controller-label>Off</span>
                </button>
                <button type="button" class="control-tile" id="control-lights" data-control="lights" data-needs-controller aria-pressed="false" title="Turn lights on" disabled>
                    <span class="control-tile-icon" id="control-lights-icon" aria-hidden="true">🔅</span>
                    <span class="control-tile-label" id="control-lights-label">Off</span>
                </button>
                <button type="button" class="control-tile" data-action="buzzer" data-needs-controller title="Sound buzzer" disabled>
                    <span class="control-tile-icon" aria-hidden="true">🔊</span>
                    <span class="control-tile-label">Buzzer</span>
                </button>
                <button type="button" class="control-tile" id="control-pause-resume" data-control="pause_resume" data-needs-controller title="Pause or resume" disabled>
                    <span class="control-tile-icon" id="control-pause-resume-icon" aria-hidden="true">⏸</span>
                    <span class="control-tile-label" id="control-pause-resume-label">Pause</span>
                </button>
                <button type="button" class="control-tile" data-action="return_to_dock" data-needs-controller title="Return to dock" disabled>
                    <span class="control-tile-icon" aria-hidden="true">🏠</span>
                    <span class="control-tile-label">Dock</span>
                </button>
                <button type="button" class="control-tile control-tile-danger" data-action="stop" title="Stop immediately — no confirmation">
                    <span class="control-tile-icon" aria-hidden="true">⛔</span>
                    <span class="control-tile-label">Stop</span>
                </button>
            </div>
        </section>

        <section class="card panel-section module-pane-hidden" data-panel-id="powerwall" data-module="powerwall" id="powerwall-card">
            <div class="section-header section-header--simple">
                <h2>Powerwall</h2>
                <button type="button" class="section-drag-handle" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button>
            </div>
            <div class="status-grid">
                <div class="stat">
                    <span class="label">House draw</span>
                    <span id="powerwall-load" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">Solar</span>
                    <span id="powerwall-solar" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">Battery</span>
                    <span id="powerwall-battery" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">Grid</span>
                    <span id="powerwall-grid" class="value">—</span>
                </div>
            </div>
            <p class="updated">Source: <span id="powerwall-source">—</span> · <span id="powerwall-updated">never</span><span id="powerwall-error"></span></p>
        </section>

        <section class="card panel-section module-pane-hidden" data-panel-id="lymow" data-module="lymow" id="lymow-card">
            <div class="section-header section-header--simple">
                <div>
                    <h2 id="lymow-heading">Lymow</h2>
                    <p class="robot-name hidden" id="lymow-device-name"></p>
                </div>
                <button type="button" class="section-drag-handle" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button>
            </div>
            <div class="status-grid">
                <div class="stat">
                    <span class="label">Battery</span>
                    <span id="lymow-battery" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">State</span>
                    <span id="lymow-state" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">Progress</span>
                    <span id="lymow-progress" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">Charging</span>
                    <span id="lymow-charging" class="value">—</span>
                </div>
                <div class="stat">
                    <span class="label">Camera</span>
                    <span id="lymow-cam" class="value">—</span>
                </div>
            </div>
            <div class="camera-mode lymow-mode" role="radiogroup" aria-label="Lymow camera mode">
                <label>
                    <input type="radio" name="lymow-cam-mode" value="stills" checked>
                    Stills
                </label>
                <label>
                    <input type="radio" name="lymow-cam-mode" value="stream">
                    Stream
                </label>
            </div>
            <div class="lymow-video-wrap">
                <img id="lymow-stream" class="lymow-stream" alt="Lymow camera" width="640" height="480">
                <p id="lymow-stream-error" class="lymow-stream-error hidden" role="status"></p>
            </div>
        </section>

        </div>

        <div id="settings-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="settings-title">
            <button type="button" class="modal-backdrop" data-settings-close aria-label="Close settings"></button>
            <div class="modal-panel card settings-modal">
                <div class="settings-modal-header">
                    <h2 id="settings-title">Settings</h2>
                    <p class="hint settings-modal-lead">Connection, modules (Yarbo, Powerwall, Lymow), Vestaboard Note, PaperMono / Paper Colour companions, and panel updates.</p>
                </div>
                <form id="settings-form" class="settings-form">
                    <div class="settings-modal-scroll">
                        <section class="settings-section">
                            <h3 class="settings-subtitle">Panel</h3>
                            <label class="settings-field">
                                <span class="label">Panel name</span>
                                <input
                                    type="text"
                                    id="settings-house-name"
                                    name="house_name"
                                    maxlength="48"
                                    placeholder="e.g. 28LPC"
                                    autocomplete="off"
                                    spellcheck="true"
                                >
                            </label>
                            <p class="hint">Shown as the title. <strong>28LPC</strong> becomes <strong>28LPC Control Panel</strong>. Leave blank for <strong>Control Panel</strong>.</p>
                        </section>
                        <section class="settings-section">
                            <h3 class="settings-subtitle">Connection</h3>
                            <label class="settings-field">
                                <span class="label">Broker IP (Yarbo host)</span>
                                <input
                                    type="text"
                                    id="settings-host"
                                    name="broker_host"
                                    required
                                    placeholder="192.168.1.24"
                                    autocomplete="off"
                                    inputmode="decimal"
                                >
                            </label>
                            <label class="settings-field">
                                <span class="label">Serial number</span>
                                <input
                                    type="text"
                                    id="settings-serial"
                                    name="serial"
                                    required
                                    placeholder="24460102..."
                                    autocomplete="off"
                                    spellcheck="false"
                                >
                            </label>
                            <label class="settings-field">
                                <span class="label">Yarbo name</span>
                                <input
                                    type="text"
                                    id="settings-robot-name"
                                    name="robot_name"
                                    maxlength="48"
                                    placeholder="e.g. Lawnbot"
                                    autocomplete="off"
                                    spellcheck="true"
                                >
                            </label>
                            <p class="hint">Shown on the Yarbo page under the title, and on PaperMono / Paper Colour Yarbo pages. Leave blank to hide it. Do not use the serial number.</p>
                            <p id="settings-connection-result" class="settings-cloud-result hidden" role="status"></p>
                            <button type="button" class="btn btn-secondary" id="settings-connection-test">Test local connection</button>
                        </section>

                        <section class="settings-section">
                            <h3 class="settings-subtitle">Cloud reads (optional)</h3>
                            <p class="hint">Map/plan data from your Yarbo account when local MQTT returns nothing. Controls always use local MQTT.</p>
                            <label class="settings-field settings-checkbox">
                                <input type="checkbox" id="settings-cloud-enabled" name="cloud_enabled">
                                <span>Enable cloud fallback reads</span>
                            </label>
                            <label class="settings-field">
                                <span class="label">Yarbo account email</span>
                                <input type="email" id="settings-cloud-email" name="cloud_email" autocomplete="username">
                            </label>
                            <label class="settings-field">
                                <span class="label">Yarbo account password</span>
                                <input type="password" id="settings-cloud-password" name="cloud_password" autocomplete="current-password" placeholder="Leave blank to keep saved password">
                            </label>
                            <label class="settings-field">
                                <span class="label">Default data source</span>
                                <select id="settings-data-source" name="data_source">
                                    <option value="auto">Auto (local, then cloud)</option>
                                    <option value="local">Local MQTT only</option>
                                    <option value="cloud">Cloud only</option>
                                </select>
                            </label>
                            <p id="settings-cloud-status" class="hint">Cloud bridge: checking…</p>
                            <p id="settings-cloud-result" class="settings-cloud-result hidden" role="status"></p>
                            <button type="button" class="btn btn-secondary" id="settings-cloud-test">Test cloud connection</button>
                        </section>

                        <section class="settings-section" id="settings-rain-section">
                            <h3 class="settings-subtitle">Rain sensitivity</h3>
                            <p class="hint">Match the Yarbo app <strong>Detection &amp; Rain Sensitivity</strong> slider (20–1000). Status and the Vestaboard only show rain when the sensor reading is at or above this value. Readings below 20 always clear (the app never blocks mowing there). Leave blank to use 20. If the robot publishes its slider over MQTT, that value is used instead.</p>
                            <label class="settings-field">
                                <span class="label">App slider value</span>
                                <input type="number" id="settings-rain-sensitivity" name="rain_sensitivity" min="20" max="1000" step="1" placeholder="20" inputmode="numeric">
                            </label>
                        </section>

                        <section class="settings-section" id="settings-modules-section">
                            <h3 class="settings-subtitle">Modules</h3>
                            <p class="hint">Turn extra dashboards on or off. Yarbo stays available. The header switcher jumps between enabled modules. Vestaboard live buttons only list modules that are on (Quiet hours and Vestaboard-app hold still apply).</p>
                            <label class="settings-field settings-checkbox">
                                <input type="checkbox" id="settings-module-powerwall" name="module_powerwall">
                                <span>Tesla Powerwall (house draw, solar, battery)</span>
                            </label>
                            <label class="settings-field settings-checkbox">
                                <input type="checkbox" id="settings-module-lymow" name="module_lymow">
                                <span>Lymow (account, battery, camera)</span>
                            </label>
                            <p class="hint">Tick a module, then fill its login section that appears below. Lymow uses the same email and password as the Lymow phone app.</p>
                            <label class="settings-field">
                                <span class="label">Vestaboard live module</span>
                                <select id="settings-vestaboard-live" name="vestaboard_live">
                                    <option value="yarbo">Yarbo</option>
                                    <option value="powerwall">Powerwall</option>
                                    <option value="lymow">Lymow</option>
                                    <option value="batteries">ALL (Yarbo + Powerwall + Lymow)</option>
                                </select>
                            </label>
                        </section>

                        <section class="settings-section hidden" id="settings-lymow-section">
                            <h3 class="settings-subtitle">Lymow</h3>
                            <p class="hint">Unofficial Lymow app login (same account as the phone app) for battery and work status. Camera is the LAN IP — stream path is always <code>rtsp://IP:10022/h264ESVideoTest</code>. Needs <code>ffmpeg</code> on this host. This panel does not send start, dock, or pause. Protocol notes from <a href="https://github.com/8408323/ha-lymow" target="_blank" rel="noopener">ha-lymow</a> (MIT). See <code>docs/lymow.md</code>.</p>
                            <label class="settings-field">
                                <span class="label">Lymow email</span>
                                <input type="email" id="settings-lymow-email" name="lymow_email" autocomplete="username" spellcheck="false" placeholder="app login email">
                            </label>
                            <label class="settings-field">
                                <span class="label">Lymow password</span>
                                <input type="password" id="settings-lymow-password" name="lymow_password" autocomplete="new-password" placeholder="Leave blank to keep the saved password">
                            </label>
                            <label class="settings-field">
                                <span class="label">Region</span>
                                <select id="settings-lymow-region" name="lymow_region">
                                    <option value="auto">Auto (try EU, then others)</option>
                                    <option value="eu-west-1">Europe (eu-west-1)</option>
                                    <option value="us-east-2">North America (us-east-2)</option>
                                    <option value="ap-southeast-2">Australia (ap-southeast-2)</option>
                                    <option value="ap-east-1">Asia (ap-east-1)</option>
                                </select>
                            </label>
                            <p id="settings-lymow-result" class="settings-cloud-result hidden" role="status"></p>
                            <div class="settings-update-actions">
                                <button type="button" class="btn btn-secondary" id="settings-lymow-login">Sign in / Test Lymow</button>
                            </div>
                            <label class="settings-field">
                                <span class="label">Lymow name</span>
                                <input type="text" id="settings-lymow-name" name="lymow_display_name" maxlength="48" placeholder="e.g. Front lawn" autocomplete="off" spellcheck="true">
                            </label>
                            <p class="hint">Shown under the title on the Lymow page, and on PaperMono / Paper Colour Lymow pages. Leave blank to use the name from the Lymow app when it is available.</p>
                            <label class="settings-field">
                                <span class="label">Lymow IP (camera)</span>
                                <input type="text" id="settings-lymow-host" name="lymow_host" autocomplete="off" spellcheck="false" inputmode="decimal" placeholder="192.168.40.154">
                            </label>
                        </section>

                        <section class="settings-section hidden" id="settings-powerwall-section">
                            <h3 class="settings-subtitle">Tesla Powerwall</h3>
                            <p class="hint">You do <strong>not</strong> need the Gateway LAN password. Cloud (Tesla Fleet API) is the default. Local Gateway is optional if you later find the sticker password. Full walkthrough: <code>docs/powerwall.md</code>.</p>
                            <div class="map-mode vestaboard-transport" role="radiogroup" aria-label="Powerwall connection">
                                <label class="settings-inline-radio">
                                    <input type="radio" name="powerwall-transport" value="cloud" checked>
                                    <span>Tesla cloud</span>
                                </label>
                                <label class="settings-inline-radio">
                                    <input type="radio" name="powerwall-transport" value="local">
                                    <span>Local Gateway</span>
                                </label>
                            </div>
                            <div id="settings-powerwall-cloud-fields">
                                <p class="hint">1. Create an app at <a href="https://developer.tesla.com/dashboard" target="_blank" rel="noopener">developer.tesla.com</a> (energy products are free). Enable <strong>energy_device_data</strong>.<br>
                                2. Set the app’s allowed origin / redirect to this panel on <strong>HTTPS</strong> (Cloudflare Tunnel, Tailscale Funnel, or a reverse proxy). Redirect URI: <code>https://YOUR-HOST/api/tesla.php?action=callback</code>.<br>
                                3. Generate the Fleet public key here, then put the same origin in Tesla’s dashboard so they can GET <code>/.well-known/appspecific/com.tesla.3p.public-key.pem</code>.<br>
                                4. Save, then <strong>Sign in with Tesla</strong>. Or paste a refresh token if you already have one.</p>
                                <label class="settings-field">
                                    <span class="label">Region</span>
                                    <select id="settings-powerwall-region" name="powerwall_region">
                                        <option value="eu">Europe / Middle East / Africa</option>
                                        <option value="na">North America / Australia / NZ</option>
                                        <option value="cn">China</option>
                                    </select>
                                </label>
                                <label class="settings-field">
                                    <span class="label">Public panel URL (HTTPS)</span>
                                    <input type="url" id="settings-powerwall-public-url" name="powerwall_public_url" placeholder="https://panel.example.com" autocomplete="off">
                                </label>
                                <label class="settings-field">
                                    <span class="label">Client ID</span>
                                    <input type="text" id="settings-powerwall-client-id" name="powerwall_client_id" autocomplete="off" spellcheck="false">
                                </label>
                                <label class="settings-field">
                                    <span class="label">Client secret</span>
                                    <input type="password" id="settings-powerwall-client-secret" name="powerwall_client_secret" autocomplete="off" placeholder="Leave blank to keep the saved secret">
                                </label>
                                <label class="settings-field">
                                    <span class="label">Refresh token (optional)</span>
                                    <input type="password" id="settings-powerwall-refresh" name="powerwall_refresh_token" autocomplete="off" placeholder="Leave blank to keep the saved token">
                                </label>
                                <label class="settings-field">
                                    <span class="label">Energy site ID (optional)</span>
                                    <input type="text" id="settings-powerwall-site" name="powerwall_energy_site_id" autocomplete="off" placeholder="Filled automatically after sign-in">
                                </label>
                                <p id="settings-powerwall-result" class="settings-cloud-result hidden" role="status"></p>
                                <div class="settings-update-actions">
                                    <button type="button" class="btn btn-secondary" id="settings-powerwall-keys">Generate Tesla public key</button>
                                    <a class="btn btn-secondary" id="settings-powerwall-oauth" href="#">Sign in with Tesla</a>
                                    <button type="button" class="btn btn-secondary" id="settings-powerwall-test">Test Powerwall</button>
                                </div>
                            </div>
                            <div id="settings-powerwall-local-fields" class="hidden">
                                <p class="hint">Tesla app → energy site, or your router’s DHCP list (Tesla / Powerwall / Tegra). Customer password is usually the <strong>last 5 characters</strong> of the sticker inside the Backup Gateway door — not your Tesla.com password.</p>
                                <label class="settings-field">
                                    <span class="label">Gateway IP</span>
                                    <input type="text" id="settings-powerwall-host" name="powerwall_gateway_host" placeholder="192.168.1.50" autocomplete="off">
                                </label>
                                <label class="settings-field">
                                    <span class="label">Email (any Tesla-account email)</span>
                                    <input type="email" id="settings-powerwall-email" name="powerwall_gateway_email" autocomplete="off">
                                </label>
                                <label class="settings-field">
                                    <span class="label">Customer password</span>
                                    <input type="password" id="settings-powerwall-password" name="powerwall_gateway_password" autocomplete="off" placeholder="Leave blank to keep the saved password">
                                </label>
                            </div>
                        </section>

                        <section class="settings-section" id="settings-vestaboard-section">
                            <h3 class="settings-subtitle">Vestaboard Note <span class="settings-beta-badge">Optional</span></h3>
                            <p class="hint">Show live status on a <a href="https://docs.vestaboard.com/docs/read-write-api/introduction/" target="_blank" rel="noopener">Vestaboard Note</a> (3×15). Choose Local API on your LAN or Vestaboard’s Cloud API. Credentials stay hidden until enabled. When enabled, a matching 3×15 section appears on the main dashboard. The Note updates in the background while the panel (or MQTT agent) is running — the browser does not need to stay open. Writes happen when the message changes (at most every 15 seconds). A message from the Vestaboard app pauses live status for one hour (or until Quiet hours end overnight). Optional Quiet hours pause those writes overnight. See <code>docs/vestaboard.md</code>.</p>
                            <label class="settings-field settings-checkbox">
                                <input type="checkbox" id="settings-vestaboard-enabled" name="vestaboard_enabled">
                                <span>Enable Vestaboard Note</span>
                            </label>
                            <div id="settings-vestaboard-fields" class="hidden">
                                <div class="map-mode vestaboard-transport" role="radiogroup" aria-label="Vestaboard API">
                                    <label>
                                        <input type="radio" name="vestaboard-transport" value="local" checked>
                                        Local API
                                    </label>
                                    <label>
                                        <input type="radio" name="vestaboard-transport" value="cloud">
                                        Cloud API
                                    </label>
                                </div>
                                <div id="settings-vestaboard-local-fields">
                                <label class="settings-field">
                                    <span class="label">Board IP or hostname</span>
                                    <input type="text" id="settings-vestaboard-host" name="vestaboard_host" autocomplete="off" spellcheck="false" placeholder="vestaboard.local">
                                </label>
                                <label class="settings-field">
                                    <span class="label">Local API key</span>
                                    <input type="password" id="settings-vestaboard-key" name="vestaboard_api_key" autocomplete="off" placeholder="Leave blank to keep the saved key">
                                </label>
                                <p class="hint">The Local API key is not in the Vestaboard app. Request a one-time enablement token from Vestaboard’s <a href="https://www.vestaboard.com/local-api" target="_blank" rel="noopener">Local API request form</a> (the Note must be paired and online). Vestaboard emails that token; it is <strong>not</strong> the key. On the same LAN, exchange it once:</p>
                                <p class="hint"><code>curl -X POST -H "X-Vestaboard-Local-Api-Enablement-Token: YOUR_EMAIL_TOKEN" http://vestaboard.local:7000/local-api/enablement</code></p>
                                <p class="hint">Paste the JSON <code>apiKey</code> here. If <code>vestaboard.local</code> fails, use the Note’s IPv4 address. Official steps: <a href="https://docs.vestaboard.com/docs/local-api/authentication/" target="_blank" rel="noopener">Local API authentication</a>.</p>
                                </div>
                                <div id="settings-vestaboard-cloud-fields" class="hidden">
                                <label class="settings-field">
                                    <span class="label">Cloud API token</span>
                                    <input type="password" id="settings-vestaboard-cloud-token" name="vestaboard_cloud_token" autocomplete="off" placeholder="Leave blank to keep the saved token">
                                </label>
                                <p class="hint">Create a token in the Vestaboard app (<strong>Settings → Advanced</strong>) or the <a href="https://web.vestaboard.com/" target="_blank" rel="noopener">web app</a> API tab. Enable <strong>Read</strong> and <strong>Write</strong>. The token is shown once. Official docs: <a href="https://docs.vestaboard.com/docs/read-write-api/introduction/" target="_blank" rel="noopener">Cloud API</a> and <a href="https://docs.vestaboard.com/docs/read-write-api/authentication/" target="_blank" rel="noopener">authentication</a>. Test uses Read; Send uses Write. Quiet hours in the Vestaboard app can drop Cloud writes.</p>
                                </div>
                                <label class="settings-field">
                                    <span class="label">Preview</span>
                                    <select id="settings-vestaboard-sample">
                                        <option value="live">Live robot status</option>
                                        <option value="mowing">Sample: mowing</option>
                                        <option value="charging">Sample: charging</option>
                                        <option value="idle">Sample: idle charged</option>
                                        <option value="paused">Sample: paused / plan hold</option>
                                        <option value="rain">Sample: rain</option>
                                        <option value="error">Sample: error</option>
                                        <option value="quiet">Quiet hours message</option>
                                    </select>
                                </label>
                                <div class="vestaboard-preview" id="settings-vestaboard-preview" aria-label="Vestaboard Note 3 by 15 preview"></div>
                                <p id="settings-vestaboard-preview-caption" class="hint">YARBO status on a 3×15 Note</p>
                                <label class="settings-field settings-checkbox">
                                    <input type="checkbox" id="settings-vestaboard-quiet" name="vestaboard_quiet_hours">
                                    <span>Quiet hours</span>
                                </label>
                                <p class="hint" id="settings-vestaboard-quiet-hint">Stops live status writes overnight so the flaps stay still. At the start of the window the Note shows your quiet message once; live status resumes at the end, with no browser open. Times use your local timezone (not UTC). A custom message from the Vestaboard app during this window stays until quiet hours end (Resume previous status on the dashboard takes the panel back). Separate from Quiet Hours in the Vestaboard app, which can still drop Cloud writes.</p>
                                <div id="settings-vestaboard-quiet-fields" class="hidden">
                                    <div class="vestaboard-quiet-times">
                                        <label class="settings-field">
                                            <span class="label">Start</span>
                                            <input type="time" id="settings-vestaboard-quiet-start" name="vestaboard_quiet_start" value="22:00">
                                        </label>
                                        <label class="settings-field">
                                            <span class="label">End</span>
                                            <input type="time" id="settings-vestaboard-quiet-end" name="vestaboard_quiet_end" value="07:00">
                                        </label>
                                    </div>
                                    <p class="hint">Tap a flap, then type a letter or pick a colour. Overnight wrap is allowed (for example 22:00–07:00).</p>
                                    <div class="vestaboard-preview vestaboard-preview--edit" id="settings-vestaboard-quiet-board" tabindex="0" role="grid" aria-label="Quiet hours 3 by 15 message"></div>
                                    <div class="vestaboard-palette" id="settings-vestaboard-quiet-palette" role="toolbar" aria-label="Quiet hours colours">
                                        <button type="button" class="vestaboard-palette-btn" data-quiet-code="0">Blank</button>
                                        <button type="button" class="vestaboard-palette-btn vestaboard-palette-btn--red" data-quiet-code="63" title="Red"></button>
                                        <button type="button" class="vestaboard-palette-btn vestaboard-palette-btn--orange" data-quiet-code="64" title="Orange"></button>
                                        <button type="button" class="vestaboard-palette-btn vestaboard-palette-btn--yellow" data-quiet-code="65" title="Yellow"></button>
                                        <button type="button" class="vestaboard-palette-btn vestaboard-palette-btn--green" data-quiet-code="66" title="Green"></button>
                                        <button type="button" class="vestaboard-palette-btn vestaboard-palette-btn--blue" data-quiet-code="67" title="Blue"></button>
                                        <button type="button" class="vestaboard-palette-btn vestaboard-palette-btn--violet" data-quiet-code="68" title="Violet"></button>
                                        <button type="button" class="vestaboard-palette-btn vestaboard-palette-btn--white" data-quiet-code="69" title="White"></button>
                                    </div>
                                </div>
                                <p id="settings-vestaboard-result" class="settings-cloud-result hidden" role="status"></p>
                                <div class="papermono-actions">
                                    <button type="button" class="btn btn-secondary" id="settings-vestaboard-test">Test connection</button>
                                    <button type="button" class="btn btn-secondary" id="settings-vestaboard-send">Send now</button>
                                </div>
                            </div>
                        </section>

                        <section class="settings-section" id="settings-papermono-section">
                            <h3 class="settings-subtitle">E-paper companions <span class="settings-beta-badge">Beta</span></h3>
                            <p class="hint" id="papermono-kind-hint">Choose the tablet you plugged in. That choice is what gets flashed — Paper Colour is a different binary and a different on-screen UI (no touch, A/B/C keys).</p>
                            <div class="papermono-kind-switch" role="radiogroup" aria-label="E-paper hardware">
                                <label class="papermono-kind-card is-active">
                                    <input type="radio" name="papermono-kind" value="papermono" checked>
                                    <span class="papermono-kind-card-title">PaperMono</span>
                                    <span class="papermono-kind-card-meta">C153 · grayscale · touch · Stop / Dock / Pause</span>
                                </label>
                                <label class="papermono-kind-card">
                                    <input type="radio" name="papermono-kind" value="papercolor">
                                    <span class="papermono-kind-card-title">Paper Colour</span>
                                    <span class="papermono-kind-card-meta">Spectra 6 · no touch · buttons A / B / C</span>
                                </label>
                            </div>
                            <p class="hint hidden" id="papermono-color-extra">Paper Colour firmware is <code>0.2.3-color</code> in <code>firmware/papercolor/</code>. After flash the tablet says <strong>YARBO · COLOR</strong>. A/B change pages, C sleeps. Build first: <code>pio run -e papercolor -d firmware/papercolor</code>.</p>
                            <p id="papermono-fw-status" class="hint">Firmware: checking…</p>
                            <label class="settings-field">
                                <span class="label">USB serial port</span>
                                <select id="papermono-port">
                                    <option value="">Refresh ports with the tablet plugged in</option>
                                </select>
                            </label>
                            <div class="papermono-actions">
                                <button type="button" class="btn btn-secondary" id="papermono-ports-refresh">Refresh USB ports</button>
                                <button type="button" class="btn btn-secondary" id="papermono-install-tools">Install USB tools</button>
                            </div>
                            <p id="papermono-result" class="settings-cloud-result hidden" role="status"></p>
                            <label class="settings-field">
                                <span class="label">Wi-Fi name (SSID)</span>
                                <input type="text" id="papermono-ssid" name="papermono_ssid" autocomplete="off" spellcheck="false" placeholder="Home network 2.4 GHz">
                            </label>
                            <label class="settings-field">
                                <span class="label">Wi-Fi password</span>
                                <input type="password" id="papermono-wifi-password" name="papermono_wifi_password" autocomplete="new-password" placeholder="2.4 GHz only — these tablets have no 5 GHz">
                            </label>
                            <label class="settings-field">
                                <span class="label">Panel URL (this server, as the tablet will reach it)</span>
                                <input type="url" id="papermono-panel-url" name="papermono_panel_url" autocomplete="off" spellcheck="false" placeholder="http://192.168.1.50:8080">
                            </label>
                            <label class="settings-field">
                                <span class="label">Device name</span>
                                <input type="text" id="papermono-name" name="papermono_name" value="PaperMono" autocomplete="off">
                            </label>
                            <div class="papermono-actions">
                                <button type="button" class="btn" id="papermono-flash">Flash PaperMono firmware &amp; send Wi-Fi</button>
                                <button type="button" class="btn btn-secondary" id="papermono-config">Send Wi-Fi only (already flashed)</button>
                            </div>
                            <p class="hint" id="papermono-flash-hint">First flash takes one to two minutes. Leave this Settings page open. Build the binary on this host first: <code>pip3 install platformio && pio run -d firmware/papermono</code>. If the port list fails, click <strong>Install USB tools</strong> to add <code>pyserial</code> and <code>esptool</code> to this panel’s Python environment. The firmware keeps the SSD1677 healthy: full refresh every 10 partials, no redraw when nothing changed, 15s poll. Keep the tablet out of direct sun.</p>
                            <div class="papermono-preview-grid" id="papermono-preview-grid" aria-hidden="true">
                                <figure class="papermono-preview">
                                    <svg viewBox="0 0 480 800" role="img" aria-label="PaperMono home screen mock, portrait 480 by 800">
                                        <rect width="480" height="800" fill="#f4f1e8"/>
                                        <rect x="8" y="8" width="464" height="784" fill="none" stroke="#1a1a1a" stroke-width="2"/>
                                        <text x="24" y="40" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">YARBO  ·  BETA</text>
                                        <text x="24" y="64" font-family="ui-sans-serif, system-ui, sans-serif" font-size="14" fill="#333">Lawnbot  0.1.2-beta</text>
                                        <text x="24" y="92" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">HOME</text>
                                        <text x="24" y="180" font-family="ui-sans-serif, system-ui, sans-serif" font-size="72" font-weight="700" fill="#111">87%</text>
                                        <text x="24" y="240" font-family="ui-monospace, monospace" font-size="22" fill="#111">Charging  No</text>
                                        <text x="24" y="280" font-family="ui-monospace, monospace" font-size="22" fill="#111">State     idle</text>
                                        <text x="24" y="320" font-family="ui-monospace, monospace" font-size="22" fill="#111">Head      Mower</text>
                                        <text x="24" y="360" font-family="ui-monospace, monospace" font-size="22" fill="#111">Error     0</text>
                                        <rect x="24" y="500" width="208" height="88" rx="12" fill="#111"/>
                                        <text x="128" y="554" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="24" font-weight="700" fill="#f4f1e8">STOP</text>
                                        <rect x="248" y="500" width="208" height="88" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="352" y="554" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="24" font-weight="700" fill="#111">DOCK</text>
                                        <rect x="24" y="600" width="208" height="88" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="128" y="654" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">PAUSE</text>
                                        <rect x="248" y="600" width="208" height="88" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="352" y="654" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">LIGHTS</text>
                                        <rect x="16" y="760" width="100" height="18" fill="#111"/>
                                        <text x="66" y="773" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#f4f1e8">HOME</text>
                                        <text x="180" y="773" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#111">STATUS</text>
                                        <text x="300" y="773" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#111">HEALTH</text>
                                        <text x="414" y="773" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#111">PLANS</text>
                                        <text x="24" y="792" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#444">192.168.1.50</text>
                                        <text x="456" y="792" text-anchor="end" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#444">keys · pages</text>
                                    </svg>
                                    <figcaption>Home — battery and Stop / Dock / Pause / Lights</figcaption>
                                </figure>
                                <figure class="papermono-preview">
                                    <svg viewBox="0 0 480 800" role="img" aria-label="PaperMono status screen mock">
                                        <rect width="480" height="800" fill="#f4f1e8"/>
                                        <rect x="8" y="8" width="464" height="784" fill="none" stroke="#1a1a1a" stroke-width="2"/>
                                        <text x="24" y="40" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">YARBO  ·  BETA</text>
                                        <text x="24" y="64" font-family="ui-sans-serif, system-ui, sans-serif" font-size="14" fill="#333">Lawnbot  0.1.2-beta</text>
                                        <text x="24" y="92" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">STATUS</text>
                                        <text x="24" y="180" font-family="ui-sans-serif, system-ui, sans-serif" font-size="72" font-weight="700" fill="#111">100%</text>
                                        <text x="24" y="248" font-family="ui-monospace, monospace" font-size="22" fill="#111">State      idle</text>
                                        <text x="24" y="288" font-family="ui-monospace, monospace" font-size="22" fill="#111">Charging   Full</text>
                                        <text x="24" y="328" font-family="ui-monospace, monospace" font-size="22" fill="#111">Heading    219.6 deg</text>
                                        <text x="24" y="368" font-family="ui-monospace, monospace" font-size="22" fill="#111">Head       Lawn Mower Pro</text>
                                        <text x="24" y="408" font-family="ui-monospace, monospace" font-size="22" fill="#111">Error      0</text>
                                        <text x="24" y="448" font-family="ui-monospace, monospace" font-size="22" fill="#111">Rain       Dry 6</text>
                                        <text x="66" y="773" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#111">HOME</text>
                                        <rect x="130" y="760" width="100" height="18" fill="#111"/>
                                        <text x="180" y="773" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#f4f1e8">STATUS</text>
                                        <text x="300" y="773" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#111">HEALTH</text>
                                        <text x="414" y="773" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#111">PLANS</text>
                                        <text x="24" y="792" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#444">192.168.1.50</text>
                                        <text x="456" y="792" text-anchor="end" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#444">keys · pages</text>
                                    </svg>
                                    <figcaption>Status — same tiles as the web Status card</figcaption>
                                </figure>
                                <figure class="papermono-preview">
                                    <svg viewBox="0 0 480 800" role="img" aria-label="PaperMono connection and health screen mock">
                                        <rect width="480" height="800" fill="#f4f1e8"/>
                                        <rect x="8" y="8" width="464" height="784" fill="none" stroke="#1a1a1a" stroke-width="2"/>
                                        <text x="24" y="40" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">YARBO  ·  BETA</text>
                                        <text x="24" y="64" font-family="ui-sans-serif, system-ui, sans-serif" font-size="14" fill="#333">Lawnbot  0.1.2-beta</text>
                                        <text x="24" y="92" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">HEALTH</text>
                                        <text x="24" y="140" font-family="ui-monospace, monospace" font-size="20" fill="#111">Conn type  HaLow</text>
                                        <text x="24" y="176" font-family="ui-monospace, monospace" font-size="20" fill="#111">Conn stat  Connected</text>
                                        <text x="24" y="212" font-family="ui-monospace, monospace" font-size="20" fill="#111">WiFi       BarnNet</text>
                                        <text x="24" y="248" font-family="ui-monospace, monospace" font-size="20" fill="#111">Signal     82% (Excellent)</text>
                                        <text x="24" y="284" font-family="ui-monospace, monospace" font-size="20" fill="#111">Security   WPA2</text>
                                        <text x="24" y="320" font-family="ui-monospace, monospace" font-size="20" fill="#111">Batt temp  21.4°C · 16 cells</text>
                                        <text x="24" y="356" font-family="ui-monospace, monospace" font-size="20" fill="#111">Pad        20.10V / 0.40A</text>
                                        <text x="24" y="392" font-family="ui-monospace, monospace" font-size="20" fill="#111">RTK        4 (fix 4)</text>
                                        <text x="24" y="428" font-family="ui-monospace, monospace" font-size="20" fill="#111">RTCM age   1</text>
                                        <text x="24" y="464" font-family="ui-monospace, monospace" font-size="20" fill="#111">Route      HaLow</text>
                                        <text x="24" y="500" font-family="ui-monospace, monospace" font-size="20" fill="#111">Rain sns   6</text>
                                        <text x="24" y="536" font-family="ui-monospace, monospace" font-size="20" fill="#111">Net mod    LTE connected</text>
                                        <text x="66" y="773" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#111">HOME</text>
                                        <text x="180" y="773" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#111">STATUS</text>
                                        <rect x="250" y="760" width="100" height="18" fill="#111"/>
                                        <text x="300" y="773" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#f4f1e8">HEALTH</text>
                                        <text x="414" y="773" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#111">PLANS</text>
                                        <text x="24" y="792" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#444">192.168.1.50</text>
                                        <text x="456" y="792" text-anchor="end" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#444">keys · pages</text>
                                    </svg>
                                    <figcaption>Health — Connection &amp; Health tiles</figcaption>
                                </figure>
                                <figure class="papermono-preview">
                                    <svg viewBox="0 0 480 800" role="img" aria-label="PaperMono work plans screen mock">
                                        <rect width="480" height="800" fill="#f4f1e8"/>
                                        <rect x="8" y="8" width="464" height="784" fill="none" stroke="#1a1a1a" stroke-width="2"/>
                                        <text x="24" y="40" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">YARBO  ·  BETA</text>
                                        <text x="24" y="64" font-family="ui-sans-serif, system-ui, sans-serif" font-size="14" fill="#333">Lawnbot  0.1.2-beta</text>
                                        <text x="24" y="92" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">PLANS</text>
                                        <text x="24" y="124" font-family="ui-sans-serif, system-ui, sans-serif" font-size="14" fill="#333">idle</text>
                                        <rect x="24" y="150" width="432" height="44" rx="10" fill="#111"/>
                                        <text x="40" y="180" font-family="ui-sans-serif, system-ui, sans-serif" font-size="20" fill="#f4f1e8">Front lawn</text>
                                        <rect x="24" y="202" width="432" height="44" rx="10" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="40" y="232" font-family="ui-sans-serif, system-ui, sans-serif" font-size="20" fill="#111">Back garden</text>
                                        <rect x="24" y="254" width="432" height="44" rx="10" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="40" y="284" font-family="ui-sans-serif, system-ui, sans-serif" font-size="20" fill="#111">Orchard edge</text>
                                        <rect x="24" y="500" width="208" height="72" rx="12" fill="#111"/>
                                        <text x="128" y="544" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#f4f1e8">START</text>
                                        <text x="66" y="773" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#111">HOME</text>
                                        <text x="180" y="773" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#111">STATUS</text>
                                        <text x="300" y="773" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#111">HEALTH</text>
                                        <rect x="364" y="760" width="100" height="18" fill="#111"/>
                                        <text x="414" y="773" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#f4f1e8">PLANS</text>
                                        <text x="24" y="792" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#444">192.168.1.50</text>
                                        <text x="456" y="792" text-anchor="end" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#444">keys · pages</text>
                                    </svg>
                                    <figcaption>Plans — tap a row, then START</figcaption>
                                </figure>
                                <figure class="papermono-preview">
                                    <svg viewBox="0 0 480 800" role="img" aria-label="PaperMono setup screen mock, portrait">
                                        <rect width="480" height="800" fill="#f4f1e8"/>
                                        <rect x="8" y="8" width="464" height="784" fill="none" stroke="#1a1a1a" stroke-width="2"/>
                                        <text x="24" y="72" font-family="ui-sans-serif, system-ui, sans-serif" font-size="36" font-weight="700" fill="#111">PaperMono</text>
                                        <text x="24" y="116" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" fill="#111">setup  ·  BETA</text>
                                        <text x="24" y="190" font-family="ui-sans-serif, system-ui, sans-serif" font-size="18" fill="#222">1. Plug USB into the computer</text>
                                        <text x="24" y="216" font-family="ui-sans-serif, system-ui, sans-serif" font-size="18" fill="#222">running this Yarbo panel.</text>
                                        <text x="24" y="264" font-family="ui-sans-serif, system-ui, sans-serif" font-size="18" fill="#222">2. Open Settings, then</text>
                                        <text x="24" y="290" font-family="ui-sans-serif, system-ui, sans-serif" font-size="18" fill="#222">PaperMono companion.</text>
                                        <text x="24" y="338" font-family="ui-sans-serif, system-ui, sans-serif" font-size="18" fill="#222">3. Flash firmware and send</text>
                                        <text x="24" y="364" font-family="ui-sans-serif, system-ui, sans-serif" font-size="18" fill="#222">2.4 GHz Wi-Fi from that page.</text>
                                        <text x="24" y="430" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" fill="#444">Keep this cable connected</text>
                                        <text x="24" y="454" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" fill="#444">until CFG_OK.</text>
                                    </svg>
                                    <figcaption>First boot — until Wi-Fi is sent over USB</figcaption>
                                </figure>
                            </div>
                            <div class="papermono-preview-grid hidden" id="papercolor-preview-grid" aria-hidden="true">
                                <figure class="papermono-preview papercolor-preview">
                                    <svg viewBox="0 0 400 600" role="img" aria-label="Paper Colour home screen mock, 400 by 600 Spectra 6">
                                        <rect width="400" height="600" fill="#fffef6"/>
                                        <rect x="6" y="6" width="388" height="588" fill="none" stroke="#111" stroke-width="2"/>
                                        <text x="20" y="36" font-family="ui-sans-serif, system-ui, sans-serif" font-size="18" font-weight="700" fill="#111">YARBO  ·  COLOR</text>
                                        <text x="20" y="58" font-family="ui-sans-serif, system-ui, sans-serif" font-size="12" fill="#333">Lawnbot  0.2.1-color</text>
                                        <text x="20" y="86" font-family="ui-sans-serif, system-ui, sans-serif" font-size="20" font-weight="700" fill="#0b6b3a">HOME</text>
                                        <text x="20" y="160" font-family="ui-sans-serif, system-ui, sans-serif" font-size="64" font-weight="700" fill="#111">87%</text>
                                        <text x="20" y="210" font-family="ui-monospace, monospace" font-size="16" fill="#111">Charging  No</text>
                                        <text x="20" y="238" font-family="ui-monospace, monospace" font-size="16" fill="#111">State     idle</text>
                                        <text x="20" y="266" font-family="ui-monospace, monospace" font-size="16" fill="#111">Head      Mower</text>
                                        <rect x="20" y="300" width="18" height="18" fill="#c41e3a"/>
                                        <rect x="44" y="300" width="18" height="18" fill="#e6c200"/>
                                        <rect x="68" y="300" width="18" height="18" fill="#2e8b57"/>
                                        <rect x="92" y="300" width="18" height="18" fill="#1e5aa8"/>
                                        <text x="20" y="348" font-family="ui-sans-serif, system-ui, sans-serif" font-size="13" fill="#444">No touch · A/B pages · C sleep</text>
                                        <rect x="16" y="548" width="70" height="16" fill="#111"/>
                                        <text x="51" y="560" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="10" fill="#fffef6">HOME</text>
                                        <text x="140" y="560" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="10" fill="#111">STATUS</text>
                                        <text x="230" y="560" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="10" fill="#111">PWRWALL</text>
                                        <text x="325" y="560" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="10" fill="#111">LYMOW</text>
                                        <text x="20" y="586" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#444">A prev · B next · C sleep</text>
                                    </svg>
                                    <figcaption>Paper Colour Home — 400×600, no Stop/Dock tiles</figcaption>
                                </figure>
                                <figure class="papermono-preview papercolor-preview">
                                    <svg viewBox="0 0 400 600" role="img" aria-label="Paper Colour first-boot setup mock">
                                        <rect width="400" height="600" fill="#fffef6"/>
                                        <rect x="6" y="6" width="388" height="588" fill="none" stroke="#111" stroke-width="2"/>
                                        <text x="20" y="56" font-family="ui-sans-serif, system-ui, sans-serif" font-size="28" font-weight="700" fill="#0b6b3a">Paper Colour</text>
                                        <text x="20" y="88" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" fill="#111">setup  ·  BETA</text>
                                        <text x="20" y="150" font-family="ui-sans-serif, system-ui, sans-serif" font-size="15" fill="#222">1. Plug USB into the computer</text>
                                        <text x="20" y="174" font-family="ui-sans-serif, system-ui, sans-serif" font-size="15" fill="#222">running this Yarbo panel.</text>
                                        <text x="20" y="214" font-family="ui-sans-serif, system-ui, sans-serif" font-size="15" fill="#222">2. Settings → E-paper companions</text>
                                        <text x="20" y="238" font-family="ui-sans-serif, system-ui, sans-serif" font-size="15" fill="#222">→ choose Paper Colour.</text>
                                        <text x="20" y="278" font-family="ui-sans-serif, system-ui, sans-serif" font-size="15" fill="#222">3. Flash Paper Colour firmware</text>
                                        <text x="20" y="302" font-family="ui-sans-serif, system-ui, sans-serif" font-size="15" fill="#222">and send 2.4 GHz Wi-Fi.</text>
                                        <text x="20" y="360" font-family="ui-sans-serif, system-ui, sans-serif" font-size="13" fill="#444">Keep this cable connected</text>
                                        <text x="20" y="382" font-family="ui-sans-serif, system-ui, sans-serif" font-size="13" fill="#444">until CFG_OK.</text>
                                    </svg>
                                    <figcaption>First boot — pick Paper Colour in Settings before flashing</figcaption>
                                </figure>
                            </div>
                            <h4 class="settings-subtitle">Paired devices</h4>
                            <div id="papermono-devices" class="papermono-device-list"><p class="hint">None yet.</p></div>
                        </section>

                        <section class="settings-section">
                            <h3 class="settings-subtitle">Appearance</h3>
                            <p class="hint">Theme and dashboard layout are saved in this browser only.</p>
                            <fieldset class="settings-theme-fieldset">
                                <legend class="label">Colour scheme</legend>
                                <label class="settings-inline-radio">
                                    <input type="radio" name="panel_theme" value="light">
                                    <span>Light</span>
                                </label>
                                <label class="settings-inline-radio">
                                    <input type="radio" name="panel_theme" value="dark">
                                    <span>Dark</span>
                                </label>
                                <label class="settings-inline-radio">
                                    <input type="radio" name="panel_theme" value="auto" checked>
                                    <span>Auto (system)</span>
                                </label>
                            </fieldset>
                            <fieldset class="settings-panel-visibility" id="settings-panel-visibility">
                                <legend class="label">Visible sections</legend>
                                <p class="hint settings-panel-visibility-hint">Uncheck a section to hide it from the dashboard.</p>
                                <div class="settings-panel-visibility-grid">
                                    <label class="settings-checkbox"><input type="checkbox" data-panel-visible="status" checked><span>Status</span></label>
                                    <label class="settings-checkbox"><input type="checkbox" data-panel-visible="vestaboard" checked><span>Vestaboard Note</span></label>
                                    <label class="settings-checkbox"><input type="checkbox" data-panel-visible="diagnostics" checked><span>Diagnostics</span></label>
                                    <label class="settings-checkbox"><input type="checkbox" data-panel-visible="map" checked><span>Location map</span></label>
                                    <label class="settings-checkbox"><input type="checkbox" data-panel-visible="cameras" checked><span>Cameras</span></label>
                                    <label class="settings-checkbox"><input type="checkbox" data-panel-visible="drive" checked><span>Manual drive</span></label>
                                    <label class="settings-checkbox"><input type="checkbox" data-panel-visible="plans" checked><span>Work plans</span></label>
                                    <label class="settings-checkbox"><input type="checkbox" data-panel-visible="waypoints" checked><span>Waypoints</span></label>
                                    <label class="settings-checkbox"><input type="checkbox" data-panel-visible="head" checked><span>Head controls</span></label>
                                    <label class="settings-checkbox"><input type="checkbox" data-panel-visible="controls" checked><span>Controls</span></label>
                                    <label class="settings-checkbox"><input type="checkbox" data-panel-visible="powerwall" checked><span>Powerwall</span></label>
                                    <label class="settings-checkbox"><input type="checkbox" data-panel-visible="lymow" checked><span>Lymow</span></label>
                                </div>
                            </fieldset>
                            <button type="button" class="btn btn-secondary" id="settings-reset-layout">Reset dashboard layout</button>
                        </section>

                        <section class="settings-section" id="settings-update-section">
                            <div id="settings-update-callout" class="settings-update-callout hidden" role="status">
                                <strong>Panel update available</strong>
                                <span id="settings-update-callout-text"></span>
                            </div>
                            <h3 class="settings-subtitle">Panel updates</h3>
                            <p class="hint">Pull the latest code from GitHub. <code>config.php</code> and <code>data/</code> are preserved.</p>
                            <p id="settings-update-status" class="hint">Checking for updates…</p>
                            <div id="settings-update-notes" class="settings-update-notes hidden" aria-live="polite"></div>
                            <p id="settings-update-result" class="settings-cloud-result hidden" role="status"></p>
                            <div class="settings-update-actions">
                                <button type="button" class="btn btn-secondary" id="settings-update-check">Check for updates</button>
                                <button type="button" class="btn btn-secondary" id="settings-update-view-notes">View release notes</button>
                                <button type="button" class="btn" id="settings-update-run" disabled>Update to latest</button>
                            </div>
                        </section>

                        <p class="hint settings-trusted-note">Use only on a trusted home network.</p>
                    </div>

                    <div class="settings-modal-footer">
                        <p id="settings-error" class="settings-error hidden" role="alert"></p>
                        <div class="modal-actions">
                            <button type="submit" class="btn" id="settings-save">Save</button>
                            <button type="button" class="btn btn-secondary" data-settings-close>Cancel</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div id="vestaboard-rotate-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="vestaboard-rotate-title">
            <button type="button" class="modal-backdrop" data-vestaboard-rotate-close aria-label="Close rotate settings"></button>
            <div class="modal-panel card vestaboard-rotate-panel">
                <h2 id="vestaboard-rotate-title">Rotate views</h2>
                <p class="hint">Cycle the Note through the ticked views. Needs two or more.</p>
                <label class="vestaboard-rotate-option">
                    <input type="checkbox" id="vestaboard-rotate-enabled">
                    <span>Rotate views</span>
                </label>
                <fieldset class="vestaboard-rotate-views">
                    <legend class="label">Views</legend>
                    <div class="vestaboard-rotate-views-list">
                        <label class="vestaboard-rotate-option" data-rotate-choice="yarbo">
                            <input type="checkbox" data-rotate-view="yarbo">
                            <span>Yarbo</span>
                        </label>
                        <label class="vestaboard-rotate-option" data-rotate-choice="powerwall">
                            <input type="checkbox" data-rotate-view="powerwall">
                            <span>Powerwall</span>
                        </label>
                        <label class="vestaboard-rotate-option" data-rotate-choice="lymow">
                            <input type="checkbox" data-rotate-view="lymow">
                            <span>Lymow</span>
                        </label>
                        <label class="vestaboard-rotate-option" data-rotate-choice="batteries">
                            <input type="checkbox" data-rotate-view="batteries">
                            <span>ALL</span>
                        </label>
                    </div>
                </fieldset>
                <label class="settings-field">
                    <span class="label">Minutes per view</span>
                    <input type="number" id="vestaboard-rotate-minutes" min="1" max="60" step="1" value="5">
                </label>
                <p id="vestaboard-rotate-hint" class="hint hidden">Tick at least two views to rotate.</p>
                <div class="modal-actions">
                    <button type="button" class="btn" id="vestaboard-rotate-save">Save</button>
                    <button type="button" class="btn btn-secondary" data-vestaboard-rotate-close>Cancel</button>
                </div>
            </div>
        </div>

        <div id="battery-cells-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="battery-cells-title">
            <button type="button" class="modal-backdrop" data-battery-cells-close aria-label="Close cell temperatures"></button>
            <div class="modal-panel card battery-cells-panel">
                <h2 id="battery-cells-title">Battery cells</h2>
                <p class="hint" id="battery-cells-summary">Average of the last cell-temperature reading.</p>
                <div id="battery-cells-list" class="battery-cells-list"></div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-battery-cells-close>Close</button>
                </div>
            </div>
        </div>

        <div id="plans-manage-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="plans-manage-title">
            <button type="button" class="modal-backdrop" data-plans-manage-close aria-label="Close plan management"></button>
            <div class="modal-panel card plans-manage-panel">
                <h2 id="plans-manage-title">Manage plans</h2>
                <p class="hint">Deleting a plan cannot be undone. Start remains on the main Work Plans list.</p>
                <div id="plans-manage-list" class="plans-manage-list"></div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-plans-manage-close>Close</button>
                </div>
            </div>
        </div>

        <div id="update-confirm-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="update-confirm-title">
            <button type="button" class="modal-backdrop" data-update-confirm-close aria-label="Cancel update"></button>
            <div class="modal-panel card update-confirm-panel">
                <h2 id="update-confirm-title">Install panel update?</h2>
                <p id="update-confirm-summary" class="hint"></p>
                <div id="update-confirm-notes" class="update-confirm-notes"></div>
                <p class="hint update-confirm-footnote" id="update-confirm-footnote">The page will reload after the service restarts.</p>
                <div class="modal-actions" id="update-confirm-actions">
                    <button type="button" class="btn" id="update-confirm-run">Update now</button>
                    <button type="button" class="btn btn-secondary" data-update-confirm-close>Cancel</button>
                </div>
            </div>
        </div>

        <section id="toast" class="toast hidden" role="status"></section>
    </main>
    <script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""
    ></script>
    <script
        src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"
        crossorigin=""
    ></script>
    <script src="/assets/app.js?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
