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
            <div class="header-actions">
                <div id="mail-open-wrap" class="header-action-wrap hidden">
                    <button
                        type="button"
                        id="mail-open"
                        class="btn btn-secondary btn-settings"
                        aria-controls="mail-page"
                        aria-expanded="false"
                    >Messages</button>
                    <span
                        id="mail-unread-badge"
                        class="settings-update-badge hidden"
                        aria-hidden="true"
                        title="Unread messages"
                    ></span>
                </div>
                <div class="header-action-wrap">
                    <button
                        type="button"
                        id="settings-open"
                        class="btn btn-secondary btn-settings"
                        aria-controls="settings-page"
                        aria-expanded="false"
                    >Settings</button>
                    <span
                        id="settings-update-badge"
                        class="settings-update-badge hidden"
                        aria-hidden="true"
                        title="Panel update available"
                    ></span>
                </div>
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
                <button type="button" class="btn btn-secondary" id="map-load-backups">Load map backups</button>
            </div>
            <p id="map-listen-status" class="map-areas-status">Load the live map, edit a zone, then Save while docked.</p>
            <p id="map-edit-tip" class="map-edit-tip hidden">Click a zone, then drag its vertices.</p>
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
                            title="Save while docked"
                        >Save to robot</button>
                    </div>
                    <p class="hint map-editor-hint">Load saved mowing areas, edit, dock, then Save. Load map backups is optional if you want a Previous Maps file instead.</p>
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

        <section class="card panel-section module-pane-hidden" data-panel-id="home" data-module="home" id="home-card">
            <div class="section-header section-header--simple">
                <h2>Home</h2>
                <div class="section-header-actions">
                    <button type="button" class="btn btn-secondary btn-compact" id="home-automations-open" aria-controls="home-automations-page" aria-expanded="false">Automations</button>
                    <button type="button" class="home-manage-toggle" id="home-manage-toggle" aria-pressed="false" aria-label="Show device settings" title="Rename, rooms, hide, and remove devices">⚙️</button>
                    <button type="button" class="section-drag-handle" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button>
                </div>
            </div>
                            <p class="hint">Matter devices. Pair with a code from the Hue app, Apple Home, or the device itself. Tick UniFi devices in Settings → UniFi to show them here too. A Hue Bridge is one pairing: every Hue light then appears as its own row. This is not a clone of the Home app — Hue scenes and Apple scenes stay in those apps. Tap ⚙️ to drag rooms, groups, lights, and scenes into the order you want.</p>
            <p id="home-server-status" class="updated">Matter server: —</p>
            <p id="home-setup-status" class="hint hidden"></p>
            <button type="button" class="btn btn-secondary hidden" id="home-setup">Set up Matter server</button>
            <div class="home-add-wrap">
                <div class="home-add">
                    <label class="settings-field">
                        <span class="label">Pairing code or QR text</span>
                        <input type="text" id="home-pair-code" spellcheck="false" autocomplete="off" placeholder="11-digit code or MT:…">
                    </label>
                    <button type="button" class="btn" id="home-pair">Add device</button>
                </div>
                <p class="hint">Hue: Hue app → Settings → Smart Home → Matter code (adds the bridge and its lights). Already in Apple Home: accessory → Turn On Pairing Mode. Do not pair the same Hue Bridge twice.</p>
            </div>
            <div id="home-room-add" class="home-room-add">
                <input type="text" id="home-room-name" placeholder="Kitchen" maxlength="32" aria-label="New room name">
                <button type="button" class="btn btn-secondary btn-compact" id="home-room-save">Add room</button>
            </div>
            <div id="home-devices" class="home-device-grid"></div>
            <div id="home-hidden-wrap" class="home-hidden-wrap hidden">
                <h3 class="settings-subtitle">Hidden</h3>
                <p class="hint">Hidden lights stay paired (Hue Bridge bulbs cannot be unpaired one at a time). Unhide to show them on Home and PaperMono again.</p>
                <div id="home-hidden-devices" class="home-device-grid"></div>
            </div>
            <div id="home-scenes-block" class="home-scenes-block" hidden>
                <h3 class="settings-subtitle">Scenes</h3>
                <div id="home-scenes" class="home-scene-list"></div>
            </div>
            <div class="home-manage-extras">
            <h3 class="settings-subtitle">Scene editor</h3>
            <p class="hint">Pick which lights belong to a scene and set on/off, brightness, and colour. PaperMono can run a scene and tap again to turn those lights off. These are not Apple or Hue scenes.</p>
            <div id="home-scene-editor" class="home-scene-editor">
                <div class="home-scene-add">
                    <input type="text" id="home-scene-name" placeholder="Evening" maxlength="32" aria-label="Scene name">
                    <button type="button" class="btn btn-secondary" id="home-scene-from-on">Use lights that are on</button>
                    <button type="button" class="btn" id="home-scene-save">Save scene</button>
                    <button type="button" class="btn btn-secondary hidden" id="home-scene-cancel">Cancel edit</button>
                </div>
                <p class="hint" id="home-scene-editor-hint">Tick only the lights this scene should change. Unticked lights are left alone.</p>
                <div id="home-scene-members" class="home-scene-members"></div>
            </div>
            <h3 class="settings-subtitle">PaperMono HOUSE</h3>
            <p class="hint">Each tablet has its own 12 HOUSE buttons. Tap a tablet to see what is on it, then tick lights or scenes. Drag or use ▲/▼ for button order. Changes save as you go.</p>
            <div id="home-paper-tablets" class="home-paper-tablets" role="tablist" aria-label="PaperMono tablets"></div>
            <div id="home-paper-assign" class="home-paper-assign"></div>
            </div>
        </section>

        <section class="card panel-section module-pane-hidden" data-panel-id="unifi" data-module="unifi" id="unifi-card">
            <div class="section-header section-header--simple">
                <h2>UniFi</h2>
                <div class="section-header-actions">
                    <button type="button" class="section-drag-handle" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button>
                </div>
            </div>
            <p class="hint">Protect cameras, floodlights, relays, and sensors, plus Access doors and door controllers. Tick a device below to put it on the Home grid. Credentials stay in Settings → UniFi.</p>
            <p id="unifi-status" class="updated">UniFi: —</p>
            <div id="unifi-home-picker" class="unifi-pick" aria-label="Show on Home"></div>
            <div id="unifi-cameras" class="unifi-camera-grid"></div>
            <div id="unifi-lights" class="home-device-grid unifi-device-grid"></div>
            <div id="unifi-relays" class="home-device-grid unifi-device-grid"></div>
            <div id="unifi-hubs" class="home-device-grid unifi-device-grid"></div>
            <div id="unifi-doors" class="home-device-grid unifi-device-grid"></div>
            <div id="unifi-sensors" class="home-device-grid unifi-device-grid"></div>
        </section>

        </div>

        <div id="home-automations-page" class="mail-page auto-page hidden" role="region" aria-labelledby="home-automations-title">
            <div class="mail-page-toolbar">
                <div>
                    <h2 id="home-automations-title">Automations</h2>
                    <p class="hint settings-page-lead" id="home-automations-tz">Times follow this panel.</p>
                </div>
                <button type="button" class="btn btn-secondary" id="home-automations-close">Home</button>
            </div>
            <div id="home-automations-list" class="auto-list"></div>
            <div id="home-automations-editor" class="auto-editor hidden">
                <label class="settings-field">
                    <span class="label">Name</span>
                    <input type="text" id="auto-name" maxlength="64" autocomplete="off" placeholder="Front door open 5 min → Porch on">
                </label>
                <p class="hint" id="auto-coords-hint" hidden>Sunrise and sunset need a location. The last Yarbo GPS is used when it exists; otherwise enter latitude and longitude.</p>
                <div id="auto-coords" class="auto-coords hidden">
                    <label class="settings-field">
                        <span class="label">Latitude</span>
                        <input type="number" id="auto-lat" step="0.0001" min="-90" max="90">
                    </label>
                    <label class="settings-field">
                        <span class="label">Longitude</span>
                        <input type="number" id="auto-lon" step="0.0001" min="-180" max="180">
                    </label>
                </div>
                <div class="auto-builder">
                    <section class="auto-drop" id="auto-when" data-auto-zone="when" aria-label="When">
                        <h3 class="settings-subtitle">When</h3>
                        <p class="hint">Drag a sensor, door, Time, or Sunset here. Tap a chip if you cannot drag.</p>
                        <div class="auto-drop-chips" id="auto-when-chips"></div>
                    </section>
                    <details class="auto-if" id="auto-if">
                        <summary>Only if…</summary>
                        <p class="hint">Optional. All of these must be true when the When fires.</p>
                        <div id="auto-if-chips" class="auto-drop-chips"></div>
                        <div class="auto-if-add">
                            <button type="button" class="btn btn-secondary btn-compact" data-auto-if="window">Time window</button>
                            <button type="button" class="btn btn-secondary btn-compact" data-auto-if="device">Device is…</button>
                        </div>
                    </details>
                    <section class="auto-drop" id="auto-then" data-auto-zone="then" aria-label="Then">
                        <h3 class="settings-subtitle">Then</h3>
                        <p class="hint">Drag lights, scenes, or Unlock here. Reorder by dragging.</p>
                        <div class="auto-drop-chips" id="auto-then-chips"></div>
                    </section>
                </div>
                <div class="auto-tray-wrap">
                    <h3 class="settings-subtitle">Add</h3>
                    <div id="auto-tray" class="auto-tray"></div>
                </div>
                <div class="auto-editor-actions">
                    <button type="button" class="btn btn-secondary" id="auto-cancel">Cancel</button>
                    <button type="button" class="btn" id="auto-save">Save</button>
                </div>
            </div>
        </div>

        <div id="mail-page" class="mail-page hidden" role="region" aria-labelledby="mail-title">
            <div class="mail-page-toolbar">
                <div>
                    <h2 id="mail-title">Messages</h2>
                    <p class="hint settings-page-lead">This computer is a MAIL device. Tablets see it by the desktop name. Notes wait on the panel if a PaperMono is off, then arrive when it next polls. Messages older than 7 days are removed.</p>
                </div>
                <div class="settings-field mail-page-name">
                    <span class="label">Desktop name</span>
                    <div class="mail-name-controls">
                        <input type="text" id="paper-mail-name" maxlength="40" autocomplete="off" spellcheck="false" value="Desktop">
                        <button type="button" class="btn btn-secondary" id="paper-mail-name-save">Save</button>
                    </div>
                </div>
            </div>
            <div class="mail-page-layout">
                <div class="mail-inbox-col">
                    <h3 class="settings-subtitle">Inbox</h3>
                    <div id="paper-mail-inbox" class="paper-mail-inbox" aria-live="polite">
                        <p class="hint">No messages yet.</p>
                    </div>
                </div>
                <form id="paper-mail-compose" class="mail-compose">
                    <h3 class="settings-subtitle">Write</h3>
                    <label class="settings-field">
                        <span class="label">To</span>
                        <select id="paper-mail-to" aria-label="Send to">
                            <option value="*">ALL</option>
                        </select>
                    </label>
                    <label class="settings-field">
                        <span class="label">Message</span>
                        <textarea id="paper-mail-text" maxlength="180" rows="6" placeholder="Write a note"></textarea>
                    </label>
                    <div class="mail-compose-actions">
                        <span id="paper-mail-count" class="paper-mail-count">0/180</span>
                        <button type="submit" class="btn" id="paper-mail-send">Send</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="settings-page" class="settings-page hidden" role="region" aria-labelledby="settings-title">
                <form id="settings-form" class="settings-form" method="post" action="/api/settings.php" novalidate>
                    <div class="settings-page-toolbar">
                        <div>
                            <h2 id="settings-title">Settings</h2>
                            <p class="hint settings-page-lead">Panel name, modules, Vestaboard, PaperMono / Paper Colour, and panel updates.</p>
                        </div>
                        <div class="settings-page-toolbar-actions">
                            <p id="settings-error" class="settings-error hidden" role="alert"></p>
                            <button type="submit" class="btn" id="settings-save">Save</button>
                        </div>
                    </div>
                    <div class="settings-page-layout">
                    <nav class="settings-nav" aria-label="Settings sections">
                        <button type="button" class="settings-nav-btn is-active" data-settings-nav="connection">Connection</button>
                        <button type="button" class="settings-nav-btn" data-settings-nav="modules">Modules</button>
                        <button type="button" class="settings-nav-btn hidden" data-settings-nav="yarbo">Yarbo</button>
                        <button type="button" class="settings-nav-btn hidden" data-settings-nav="lymow">Lymow</button>
                        <button type="button" class="settings-nav-btn hidden" data-settings-nav="powerwall">Powerwall</button>
                        <button type="button" class="settings-nav-btn hidden" data-settings-nav="home">Home</button>
                        <button type="button" class="settings-nav-btn hidden" data-settings-nav="unifi">UniFi</button>
                        <button type="button" class="settings-nav-btn" data-settings-nav="vestaboard">Vestaboard</button>
                        <button type="button" class="settings-nav-btn" data-settings-nav="papermono">E-paper</button>
                        <button type="button" class="settings-nav-btn" data-settings-nav="appearance">Appearance</button>
                        <button type="button" class="settings-nav-btn" data-settings-nav="updates">Updates</button>
                    </nav>
                    <div class="settings-page-pane">
                        <section class="settings-section is-active" id="settings-connection-section" data-settings-pane="connection">
                            <h3 class="settings-subtitle">Connection</h3>
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

                        <section class="settings-section hidden" id="settings-yarbo-section" data-settings-pane="yarbo">
                            <h3 class="settings-subtitle">Yarbo</h3>
                            <p class="hint">Local MQTT for the robot, optional cloud map fallback, and rain sensitivity. Same fields as before — they now live on this Yarbo page instead of Connection / Cloud / Rain.</p>
                            <div id="settings-yarbo-connection-fields">
                            <label class="settings-field">
                                <span class="label">Broker IP (Yarbo host)</span>
                                <input
                                    type="text"
                                    id="settings-host"
                                    name="broker_host"
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
                            </div>

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

                            <h3 class="settings-subtitle">Rain sensitivity</h3>
                            <p class="hint">Match the Yarbo app <strong>Detection &amp; Rain Sensitivity</strong> slider (20–1000). Status and the Vestaboard only show rain when the sensor reading is at or above this value. Readings below 20 always clear (the app never blocks mowing there). Leave blank to use 20. If the robot publishes its slider over MQTT, that value is used instead.</p>
                            <label class="settings-field">
                                <span class="label">App slider value</span>
                                <input type="number" id="settings-rain-sensitivity" name="rain_sensitivity" min="20" max="1000" step="1" placeholder="20" inputmode="numeric">
                            </label>
                        </section>

                        <section class="settings-section" id="settings-modules-section" data-settings-pane="modules">
                            <h3 class="settings-subtitle">Modules</h3>
                            <p class="hint">Turn dashboards on or off. Keep at least one. The header switcher jumps between enabled modules. Off modules leave the website, PaperMono, Paper Colour, and Vestaboard live / rotate lists. Quiet hours and Vestaboard-app hold still apply.</p>
                            <label class="settings-field settings-checkbox">
                                <input type="checkbox" id="settings-module-yarbo" name="module_yarbo" checked>
                                <span>Yarbo (mower / snow, local MQTT)</span>
                            </label>
                            <label class="settings-field settings-checkbox">
                                <input type="checkbox" id="settings-module-powerwall" name="module_powerwall">
                                <span>Tesla Powerwall (house draw, solar, battery)</span>
                            </label>
                            <label class="settings-field settings-checkbox">
                                <input type="checkbox" id="settings-module-lymow" name="module_lymow">
                                <span>Lymow (account, battery, camera)</span>
                            </label>
                            <label class="settings-field settings-checkbox">
                                <input type="checkbox" id="settings-module-home" name="module_home">
                                <span>Home (Matter lights, Hue Bridge, heaters, plugs)</span>
                            </label>
                            <label class="settings-field settings-checkbox">
                                <input type="checkbox" id="settings-module-unifi" name="module_unifi">
                                <span>UniFi (Protect cameras, lights, sensors, Access doors)</span>
                            </label>
                            <p class="hint">Tick a module, then open its section in the sidebar. Lymow uses the same email and password as the Lymow phone app. UniFi uses a local console API key (Protect) and optional Access token.</p>
                            <label class="settings-field">
                                <span class="label">Vestaboard live module</span>
                                <select id="settings-vestaboard-live" name="vestaboard_live">
                                    <option value="yarbo">Yarbo</option>
                                    <option value="powerwall">Powerwall</option>
                                    <option value="lymow">Lymow</option>
                                    <option value="batteries">ALL (enabled modules)</option>
                                </select>
                            </label>
                        </section>

                        <section class="settings-section hidden" id="settings-lymow-section" data-settings-pane="lymow">
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

                        <section class="settings-section hidden" id="settings-powerwall-section" data-settings-pane="powerwall">
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

                        <section class="settings-section hidden" id="settings-home-section" data-settings-pane="home">
                            <h3 class="settings-subtitle">Home (Matter)</h3>
                            <p class="hint">This panel is a Matter controller. Add devices from the Home dashboard with a pairing code. It does not read Apple Home or Hue inventories.</p>
                            <p id="settings-home-setup-status" class="updated">Matter server: —</p>
                            <button type="button" class="btn" id="settings-home-setup">Set up Matter server</button>
                            <p class="hint">On a Raspberry Pi, Settings → Panel updates installs Docker and starts the Matter server. Tap the button if pairing is not ready yet. Pairing works on the Pi (IPv6 on). See <code>docs/home.md</code>.</p>
                        </section>

                        <section class="settings-section hidden" id="settings-unifi-section" data-settings-pane="unifi">
                            <h3 class="settings-subtitle">UniFi Access &amp; Protect</h3>
                            <p class="hint">Talks to a <strong>local UniFi OS console</strong> (Dream Machine, Cloud Gateway, UNVR) — not unifi.ui.com. Create a Protect key at UniFi OS → Settings → Control Plane → Integrations (<code>X-API-KEY</code>). That Control Plane page is also labelled for Access, but the key it creates cannot list doors (it returns “you entered no-man zone”). Create an Access token <strong>inside the Access app</strong>: Access → Settings → General → Advanced → API Token, tick <code>view:space</code> (doors) and <code>view:device</code> (hubs); unlock needs <code>edit:space</code>. Paste that into Access API token, not the Control Plane key. Leave a secret blank on later saves to keep the stored value. See <code>docs/unifi.md</code>.</p>
                            <label class="settings-field">
                                <span class="label">Console host</span>
                                <input type="text" id="settings-unifi-host" name="unifi_host" placeholder="192.168.1.1" autocomplete="off" spellcheck="false">
                            </label>
                            <label class="settings-field settings-checkbox">
                                <input type="checkbox" id="settings-unifi-verify-tls" name="unifi_verify_tls">
                                <span>Verify TLS (off for the usual self-signed UniFi certificate)</span>
                            </label>
                            <label class="settings-field">
                                <span class="label">Protect API key</span>
                                <input type="password" id="settings-unifi-protect-key" name="unifi_protect_api_key" autocomplete="new-password" placeholder="Leave blank to keep the saved key">
                            </label>
                            <label class="settings-field">
                                <span class="label">Protect local username (optional)</span>
                                <input type="text" id="settings-unifi-protect-user" name="unifi_protect_username" autocomplete="username" spellcheck="false" placeholder="Local UniFi OS admin">
                            </label>
                            <label class="settings-field">
                                <span class="label">Protect local password (optional)</span>
                                <input type="password" id="settings-unifi-protect-password" name="unifi_protect_password" autocomplete="new-password" placeholder="Leave blank to keep the saved password">
                            </label>
                            <label class="settings-field">
                                <span class="label">Access API token</span>
                                <input type="password" id="settings-unifi-access-token" name="unifi_access_token" autocomplete="new-password" placeholder="Leave blank to keep the saved token">
                            </label>
                            <label class="settings-field settings-checkbox">
                                <input type="checkbox" id="settings-unifi-access-standalone" name="unifi_access_standalone">
                                <span>Access is standalone (port 12445) instead of UniFi OS proxy</span>
                            </label>
                            <p id="settings-unifi-result" class="settings-cloud-result hidden" role="status"></p>
                            <div class="settings-update-actions">
                                <button type="button" class="btn btn-secondary" id="settings-unifi-test">Test UniFi connection</button>
                            </div>
                            <h3 class="settings-subtitle">Show on Home</h3>
                            <p class="hint">Ticks save as you click — no need to wait on Settings → Save. Protect floodlights use On/Off to force the LED (not the motion schedule). Protect relays (UL-Relay) are switches, not Access doors. Door controllers (UA Hub / Gate Hub) unlock the bound Access door. If a wired door-position sensor shows <strong>Open</strong>, that button is labelled <strong>Lock</strong> but still sends unlock. With no DPS it stays Unlock. If Test shows 0 doors and “permission”, the Access token is missing <code>view:space</code> / <code>view:device</code> — recreate it in Access → Settings → General → Advanced → API Token.</p>
                            <div id="settings-unifi-devices" class="unifi-pick"></div>
                        </section>

                        <section class="settings-section" id="settings-vestaboard-section" data-settings-pane="vestaboard">
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

                        <section class="settings-section" id="settings-papermono-section" data-settings-pane="papermono">
                            <h3 class="settings-subtitle">E-paper companions <span class="settings-beta-badge">Optional</span></h3>
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
                            <p class="hint hidden" id="papermono-color-extra">Paper Colour firmware is <code>0.2.13-colour</code> in <code>firmware/papercolor/</code>. The top line follows the module: <strong>YARBO · COLOUR</strong>, <strong>POWERWALL</strong>, <strong>LYMOW</strong>, or <strong>VESTABOARD</strong>. A/B change pages, C locks / unlocks the screensaver (logo, Vestaboard, or both). Click <strong>Build firmware</strong> on this page before the first flash (or after a panel update).</p>
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
                                <button type="button" class="btn" id="papermono-build">Build firmware</button>
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
                            <label class="settings-field">
                                <span class="label">Header logo</span>
                                <input type="file" id="papermono-logo" name="papermono_logo" accept="image/png,image/jpeg,.png,.jpg,.jpeg">
                            </label>
                            <p class="hint">One PNG or JPEG for both PaperMono and Paper Colour. It sits large in the top-right of every page, and on the lock screen when you choose Logo or Both. Prefer a PNG with a transparent background. Max 2 MB. Re-upload if you already saved a logo, then reflash the tablet.</p>
                            <div class="papermono-logo-row">
                                <img id="papermono-logo-thumb" class="papermono-logo-thumb hidden" alt="Header logo preview">
                                <button type="button" class="btn btn-secondary" id="papermono-logo-clear">Remove logo</button>
                            </div>
                            <p id="papermono-logo-result" class="settings-cloud-result hidden" role="status"></p>
                            <h4 class="settings-subtitle">Desktop MAIL client</h4>
                            <p class="hint">This computer shows up on tablets as another MAIL device. They can send to it by this name.</p>
                            <label class="settings-field">
                                <span class="label">Desktop name</span>
                                <input type="text" id="papermono-web-name" maxlength="40" autocomplete="off" spellcheck="false" value="Desktop">
                            </label>
                            <p class="hint">Saved with companion settings, or from Messages next to Settings.</p>
                            <h4 class="settings-subtitle">Companion settings</h4>
                            <p class="hint">These apply to every paired tablet. PaperMono also uses them for pocket lock, frontlight, buzzer, and RGB. Paper Colour uses lock layout and the Vestaboard preview page. Sleep timers and alerts are not set on the glass. Brightness, timezone, and lock layout are written onto the tablet at USB flash, so they still work before it can reach the Pi.</p>
                            <label class="settings-field">
                                <span class="label">Lock screen</span>
                                <select id="papermono-lock-screen">
                                    <option value="logo">Logo</option>
                                    <option value="vestaboard">Vestaboard</option>
                                    <option value="both" selected>Logo and Vestaboard</option>
                                </select>
                            </label>
                            <p class="hint">PaperMono Unlock always opens the page menu. On a Yarbo screen, A/B step Status, Health, and Plans. Home is still on A/B, not on the menu.</p>
                            <h3 class="settings-subtitle">Menu buttons</h3>
                            <p class="hint">Tick a page to show it on the unlock menu. Use ▲/▼ to change order. One <strong>Yarbo</strong> button opens Status; side buttons A/B then step Health and Plans. NOTE is the live Vestaboard plus the view buttons. The name on each button is also the title at the top of that page. Names wrap on the tablet and apply on the next poll — no reflash.</p>
                            <div id="paper-menu-list" class="paper-menu-list">
                                <div class="paper-menu-row" data-menu-id="yarbo">
                                    <span class="paper-menu-reorder">
                                        <button type="button" class="home-move-btn" data-menu-move="-1" title="Move up" aria-label="Move Yarbo up">▲</button>
                                        <button type="button" class="home-move-btn" data-menu-move="1" title="Move down" aria-label="Move Yarbo down">▼</button>
                                    </span>
                                    <input type="checkbox" data-menu-visible="yarbo" checked aria-label="Show Yarbo">
                                    <label class="settings-field"><span class="label">Yarbo</span><input type="text" data-menu-label="yarbo" maxlength="20" placeholder="YARBO" autocomplete="off"></label>
                                </div>
                                <div class="paper-menu-row" data-menu-id="note">
                                    <span class="paper-menu-reorder">
                                        <button type="button" class="home-move-btn" data-menu-move="-1" title="Move up" aria-label="Move Note up">▲</button>
                                        <button type="button" class="home-move-btn" data-menu-move="1" title="Move down" aria-label="Move Note down">▼</button>
                                    </span>
                                    <input type="checkbox" data-menu-visible="note" checked aria-label="Show Note">
                                    <label class="settings-field"><span class="label">Note</span><input type="text" data-menu-label="note" maxlength="20" placeholder="NOTE" autocomplete="off"></label>
                                </div>
                                <div class="paper-menu-row" data-menu-id="powerwall">
                                    <span class="paper-menu-reorder">
                                        <button type="button" class="home-move-btn" data-menu-move="-1" title="Move up" aria-label="Move Powerwall up">▲</button>
                                        <button type="button" class="home-move-btn" data-menu-move="1" title="Move down" aria-label="Move Powerwall down">▼</button>
                                    </span>
                                    <input type="checkbox" data-menu-visible="powerwall" checked aria-label="Show Powerwall">
                                    <label class="settings-field"><span class="label">Powerwall</span><input type="text" data-menu-label="powerwall" maxlength="20" placeholder="POWER" autocomplete="off"></label>
                                </div>
                                <div class="paper-menu-row" data-menu-id="lymow">
                                    <span class="paper-menu-reorder">
                                        <button type="button" class="home-move-btn" data-menu-move="-1" title="Move up" aria-label="Move Lymow up">▲</button>
                                        <button type="button" class="home-move-btn" data-menu-move="1" title="Move down" aria-label="Move Lymow down">▼</button>
                                    </span>
                                    <input type="checkbox" data-menu-visible="lymow" checked aria-label="Show Lymow">
                                    <label class="settings-field"><span class="label">Lymow</span><input type="text" data-menu-label="lymow" maxlength="20" placeholder="LYMOW" autocomplete="off"></label>
                                </div>
                                <div class="paper-menu-row" data-menu-id="radio">
                                    <span class="paper-menu-reorder">
                                        <button type="button" class="home-move-btn" data-menu-move="-1" title="Move up" aria-label="Move Mail up">▲</button>
                                        <button type="button" class="home-move-btn" data-menu-move="1" title="Move down" aria-label="Move Mail down">▼</button>
                                    </span>
                                    <input type="checkbox" data-menu-visible="radio" checked aria-label="Show Mail">
                                    <label class="settings-field"><span class="label">Mail</span><input type="text" data-menu-label="radio" maxlength="20" placeholder="MAIL" autocomplete="off"></label>
                                </div>
                                <div class="paper-menu-row" data-menu-id="device">
                                    <span class="paper-menu-reorder">
                                        <button type="button" class="home-move-btn" data-menu-move="-1" title="Move up" aria-label="Move Device up">▲</button>
                                        <button type="button" class="home-move-btn" data-menu-move="1" title="Move down" aria-label="Move Device down">▼</button>
                                    </span>
                                    <input type="checkbox" data-menu-visible="device" checked aria-label="Show Device">
                                    <label class="settings-field"><span class="label">Device</span><input type="text" data-menu-label="device" maxlength="20" placeholder="DEVICE" autocomplete="off"></label>
                                </div>
                                <div class="paper-menu-row" data-menu-id="house">
                                    <span class="paper-menu-reorder">
                                        <button type="button" class="home-move-btn" data-menu-move="-1" title="Move up" aria-label="Move House up">▲</button>
                                        <button type="button" class="home-move-btn" data-menu-move="1" title="Move down" aria-label="Move House down">▼</button>
                                    </span>
                                    <input type="checkbox" data-menu-visible="house" checked aria-label="Show House">
                                    <label class="settings-field"><span class="label">House</span><input type="text" data-menu-label="house" maxlength="20" placeholder="HOUSE" autocomplete="off"></label>
                                </div>
                            </div>
                            <label class="settings-field">
                                <span class="label">Timezone</span>
                                <select id="papermono-timezone">
                                    <option value="">Auto (this browser)</option>
                                    <optgroup label="Europe">
                                        <option value="Europe/Amsterdam">Amsterdam</option>
                                        <option value="Europe/Brussels">Brussels</option>
                                        <option value="Europe/Berlin">Berlin</option>
                                        <option value="Europe/Paris">Paris</option>
                                        <option value="Europe/London">London</option>
                                        <option value="Europe/Dublin">Dublin</option>
                                        <option value="Europe/Rome">Rome</option>
                                        <option value="Europe/Madrid">Madrid</option>
                                        <option value="Europe/Lisbon">Lisbon</option>
                                        <option value="Europe/Stockholm">Stockholm</option>
                                        <option value="Europe/Copenhagen">Copenhagen</option>
                                        <option value="Europe/Oslo">Oslo</option>
                                        <option value="Europe/Vienna">Vienna</option>
                                        <option value="Europe/Zurich">Zurich</option>
                                        <option value="Europe/Warsaw">Warsaw</option>
                                        <option value="Europe/Prague">Prague</option>
                                        <option value="Europe/Athens">Athens</option>
                                        <option value="Europe/Helsinki">Helsinki</option>
                                    </optgroup>
                                    <optgroup label="Americas">
                                        <option value="America/New_York">New York</option>
                                        <option value="America/Chicago">Chicago</option>
                                        <option value="America/Denver">Denver</option>
                                        <option value="America/Los_Angeles">Los Angeles</option>
                                        <option value="America/Toronto">Toronto</option>
                                        <option value="America/Vancouver">Vancouver</option>
                                    </optgroup>
                                    <optgroup label="Other">
                                        <option value="UTC">UTC</option>
                                        <option value="Pacific/Auckland">Auckland</option>
                                        <option value="Australia/Sydney">Sydney</option>
                                        <option value="Australia/Perth">Perth</option>
                                        <option value="Asia/Tokyo">Tokyo</option>
                                        <option value="Asia/Singapore">Singapore</option>
                                        <option value="Asia/Dubai">Dubai</option>
                                    </optgroup>
                                </select>
                            </label>
                            <p class="hint">Used for the clock on PaperMono. Until a zone is saved, the tablet uses Central European time (CET/CEST) so it is not two hours behind in summer. Auto uses this browser’s zone when you save. Pick Amsterdam, Brussels, or Berlin for UTC+2 / CEST. NTP can use any internet Wi-Fi; it does not need the Pi.</p>
                            <label class="settings-field">
                                <span class="label">Lock after (seconds)</span>
                                <input type="number" id="papermono-lock-after" min="10" max="600" step="5" value="60">
                            </label>
                            <label class="settings-field">
                                <span class="label">Frontlight off after (seconds)</span>
                                <input type="number" id="papermono-light-off" min="5" max="300" step="5" value="15">
                            </label>
                            <label class="settings-field">
                                <span class="label">Frontlight brightness (0–100)</span>
                                <input type="number" id="papermono-brightness" min="0" max="100" step="5" value="80">
                            </label>
                            <p class="hint">PaperMono frontlight. Needs firmware 0.1.28 (earlier builds ignored this because LoRa init reset the light PWM).</p>
                            <fieldset class="settings-theme-fieldset">
                                <legend class="label">Alerts</legend>
                                <label class="settings-checkbox"><input type="checkbox" id="papermono-alert-message" checked><span>Message received (buzzer + RGB)</span></label>
                                <label class="settings-checkbox"><input type="checkbox" id="papermono-alert-yarbo" checked><span>Yarbo error (red + buzzer)</span></label>
                                <label class="settings-checkbox"><input type="checkbox" id="papermono-alert-lymow" checked><span>Lymow error (red + buzzer)</span></label>
                                <label class="settings-checkbox"><input type="checkbox" id="papermono-alert-powerwall" checked><span>Powerwall error (red + buzzer)</span></label>
                            </fieldset>
                            <div class="papermono-actions">
                                <button type="button" class="btn" id="papermono-prefs-save">Save companion settings</button>
                            </div>
                            <p id="papermono-prefs-result" class="settings-cloud-result hidden" role="status"></p>
                            <div class="papermono-actions">
                                <button type="button" class="btn" id="papermono-flash">Flash PaperMono firmware &amp; send Wi-Fi</button>
                                <button type="button" class="btn btn-secondary" id="papermono-config">Send Wi-Fi only (already flashed)</button>
                                <button type="button" class="btn btn-secondary" id="papermono-setup-kit">Download USB setup kit</button>
                            </div>
                            <p class="hint" id="papermono-flash-hint">Leave this Settings page open. Click <strong>Build firmware</strong> for the tablet you selected (first build can take several minutes). If the port list fails, click <strong>Install USB tools</strong>. The first flash is USB. After esptool resets the tablet, USB serial drops and comes back — wait for the setup screen. If Wi-Fi is not acknowledged, keep USB in and click <strong>Send Wi-Fi only</strong> — do not hold the power button. Later firmware can go over Wi-Fi from the paired list or Settings → Updates. Keep the tablet out of direct sun.</p>
                            <p class="hint" id="papermono-kit-hint">To prepare a tablet away from this host: enter the <strong>site</strong> 2.4 GHz Wi-Fi and the panel URL the tablet will use (not localhost), then <strong>Download USB setup kit</strong>. Unzip on a Mac or Windows PC, plug the tablet in, and run <code>python3 flash.py</code> (or <code>py flash.py</code>). The script installs esptool in a local <code>.venv</code> — do not use Homebrew <code>pip install</code>. The zip contains the Wi-Fi password and pairing token — keep it private. Mac and Windows steps: <a href="https://github.com/martyndix/yarbo-control-panel/blob/main/docs/papermono.md#set-up-away-from-the-panel" target="_blank" rel="noopener">docs/papermono.md</a>.</p>
                            <details class="papermono-preview-fold">
                                <summary>Screen previews</summary>
                                <p class="hint">Mocks of the current tablet pages. They follow the modules you have switched on.</p>
                            <div class="papermono-preview-grid" id="papermono-preview-grid">
                                <figure class="papermono-preview" data-preview-for="yarbo">
                                    <svg viewBox="0 0 480 800" role="img" aria-label="PaperMono unlock menu mock with page buttons and mail notification blob">
                                        <rect width="480" height="800" fill="#f4f1e8"/>
                                        <rect x="8" y="8" width="464" height="784" fill="none" stroke="#1a1a1a" stroke-width="2"/>
                                        <text x="24" y="52" font-family="ui-sans-serif, system-ui, sans-serif" font-size="28" font-weight="700" fill="#111">MENU</text>
                                        <rect x="378" y="16" width="82" height="82" rx="14" fill="none" stroke="#111" stroke-width="3"/>
                                        <rect x="400" y="50" width="38" height="32" rx="5" fill="#111"/>
                                        <path d="M409 50 v-10 a10 10 0 0 1 20 0 v10" fill="none" stroke="#111" stroke-width="6"/>
                                        <rect x="24" y="118" width="208" height="112" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="128" y="184" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">YARBO</text>
                                        <rect x="248" y="118" width="208" height="112" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="352" y="184" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">NOTE</text>
                                        <rect x="24" y="242" width="208" height="112" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="128" y="308" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">POWER</text>
                                        <rect x="248" y="242" width="208" height="112" rx="12" fill="#111"/>
                                        <text x="352" y="308" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#f4f1e8">MAIL</text>
                                        <circle cx="436" cy="262" r="12" fill="#f4f1e8"/>
                                        <rect x="24" y="366" width="208" height="112" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="128" y="432" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">LYMOW</text>
                                        <rect x="248" y="366" width="208" height="112" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="352" y="432" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">DEVICE</text>
                                        <rect x="24" y="490" width="208" height="112" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="128" y="556" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">HOUSE</text>
                                    </svg>
                                    <figcaption>Unlock menu — one YARBO button; A/B steps Status, Health, Plans. Reorder in Settings. MAIL blob if unread</figcaption>
                                </figure>
                                <figure class="papermono-preview" data-preview-for="yarbo">
                                    <svg viewBox="0 0 480 800" role="img" aria-label="PaperMono home screen mock, portrait 480 by 800">
                                        <rect width="480" height="800" fill="#f4f1e8"/>
                                        <rect x="8" y="8" width="464" height="784" fill="none" stroke="#1a1a1a" stroke-width="2"/>
                                        <text x="24" y="52" font-family="ui-sans-serif, system-ui, sans-serif" font-size="28" font-weight="700" fill="#111">YARBO</text>
                                        <rect x="24" y="62" width="118" height="40" rx="10" fill="none" stroke="#111" stroke-width="2"/>
                                        <text x="83" y="90" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" font-weight="700" fill="#111">MENU</text>
                                        <rect x="252" y="18" width="100" height="40" rx="10" fill="none" stroke="#111" stroke-width="5"/>
                                        <rect x="262" y="28" width="62" height="20" fill="#111"/>
                                        <rect x="324" y="32" width="8" height="12" fill="#111"/>
                                        <path d="M370 58 a22 22 0 0 1 44 0" fill="none" stroke="#111" stroke-width="5"/>
                                        <path d="M382 58 a10 10 0 0 1 20 0" fill="none" stroke="#111" stroke-width="5"/>
                                        <rect x="392" y="62" width="8" height="8" fill="#111"/>
                                        <rect x="378" y="16" width="82" height="82" rx="14" fill="none" stroke="#111" stroke-width="3"/>
                                        <rect x="400" y="50" width="38" height="32" rx="5" fill="#111"/>
                                        <path d="M409 50 v-10 a10 10 0 0 1 20 0 v10" fill="none" stroke="#111" stroke-width="6"/>
                                        <text x="24" y="188" font-family="ui-sans-serif, system-ui, sans-serif" font-size="84" font-weight="700" fill="#111">87%</text>
                                        <text x="24" y="248" font-family="ui-monospace, monospace" font-size="22" fill="#111">Charging  No</text>
                                        <text x="24" y="288" font-family="ui-monospace, monospace" font-size="22" fill="#111">State     idle</text>
                                        <text x="24" y="328" font-family="ui-monospace, monospace" font-size="22" fill="#111">Head      Mower</text>
                                        <text x="24" y="368" font-family="ui-monospace, monospace" font-size="22" fill="#111">Error     0</text>
                                        <rect x="24" y="500" width="208" height="88" rx="12" fill="#111"/>
                                        <text x="128" y="554" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="24" font-weight="700" fill="#f4f1e8">STOP</text>
                                        <rect x="248" y="500" width="208" height="88" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="352" y="554" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="24" font-weight="700" fill="#111">DOCK</text>
                                        <rect x="24" y="600" width="208" height="88" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="128" y="654" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">PAUSE</text>
                                        <rect x="248" y="600" width="208" height="88" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="352" y="654" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">LIGHTS</text>
                                    </svg>
                                    <figcaption>Home — MENU (blob if mail is waiting), padlock, Stop / Dock / Pause / Lights (A/B still change pages)</figcaption>
                                </figure>
                                <figure class="papermono-preview" data-preview-for="yarbo">
                                    <svg viewBox="0 0 480 800" role="img" aria-label="PaperMono status screen mock">
                                        <rect width="480" height="800" fill="#f4f1e8"/>
                                        <rect x="8" y="8" width="464" height="784" fill="none" stroke="#1a1a1a" stroke-width="2"/>
                                        <text x="24" y="40" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">YARBO</text>
                                        <image class="paper-logo-preview" href="" x="284" y="16" width="180" height="180" preserveAspectRatio="xMaxYMin meet"/>
                                        <text x="24" y="64" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">STATUS</text>
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
                                <figure class="papermono-preview" data-preview-for="yarbo">
                                    <svg viewBox="0 0 480 800" role="img" aria-label="PaperMono connection and health screen mock">
                                        <rect width="480" height="800" fill="#f4f1e8"/>
                                        <rect x="8" y="8" width="464" height="784" fill="none" stroke="#1a1a1a" stroke-width="2"/>
                                        <text x="24" y="40" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">YARBO</text>
                                        <image class="paper-logo-preview" href="" x="284" y="16" width="180" height="180" preserveAspectRatio="xMaxYMin meet"/>
                                        <text x="24" y="64" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">HEALTH</text>
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
                                <figure class="papermono-preview" data-preview-for="yarbo">
                                    <svg viewBox="0 0 480 800" role="img" aria-label="PaperMono work plans screen mock">
                                        <rect width="480" height="800" fill="#f4f1e8"/>
                                        <rect x="8" y="8" width="464" height="784" fill="none" stroke="#1a1a1a" stroke-width="2"/>
                                        <text x="24" y="40" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">YARBO</text>
                                        <image class="paper-logo-preview" href="" x="284" y="16" width="180" height="180" preserveAspectRatio="xMaxYMin meet"/>
                                        <text x="24" y="64" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">PLANS</text>
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
                                <figure class="papermono-preview" data-preview-for="pages">
                                    <svg viewBox="0 0 480 800" role="img" aria-label="PaperMono HOUSE page mock with twelve buttons">
                                        <rect width="480" height="800" fill="#f4f1e8"/>
                                        <rect x="8" y="8" width="464" height="784" fill="none" stroke="#1a1a1a" stroke-width="2"/>
                                        <text x="24" y="52" font-family="ui-sans-serif, system-ui, sans-serif" font-size="28" font-weight="700" fill="#111">HOUSE</text>
                                        <rect x="378" y="16" width="82" height="82" rx="14" fill="none" stroke="#111" stroke-width="3"/>
                                        <rect x="400" y="50" width="38" height="32" rx="5" fill="#111"/>
                                        <path d="M409 50 v-10 a10 10 0 0 1 20 0 v10" fill="none" stroke="#111" stroke-width="6"/>
                                        <rect x="24" y="118" width="208" height="88" rx="14" fill="#111"/>
                                        <text x="128" y="172" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="20" font-weight="700" fill="#f4f1e8">*ALL OFF</text>
                                        <rect x="248" y="118" width="208" height="88" rx="14" fill="#f4f1e8" stroke="#111" stroke-width="3"/>
                                        <text x="352" y="172" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="18" font-weight="700" fill="#111">Fountain</text>
                                        <rect x="24" y="218" width="208" height="88" rx="14" fill="#f4f1e8" stroke="#111" stroke-width="3"/>
                                        <text x="128" y="272" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="18" font-weight="700" fill="#111">Kitchen</text>
                                        <rect x="248" y="218" width="208" height="88" rx="14" fill="#111"/>
                                        <text x="352" y="272" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="18" font-weight="700" fill="#f4f1e8">Evening</text>
                                        <rect x="24" y="318" width="208" height="88" rx="14" fill="#f4f1e8" stroke="#111" stroke-width="3"/>
                                        <text x="128" y="372" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" font-weight="700" fill="#111">Hall lamp</text>
                                        <rect x="248" y="318" width="208" height="88" rx="14" fill="#f4f1e8" stroke="#111" stroke-width="3"/>
                                        <text x="352" y="372" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" font-weight="700" fill="#111">Porch</text>
                                        <rect x="24" y="418" width="208" height="88" rx="14" fill="#f4f1e8" stroke="#111" stroke-width="3"/>
                                        <text x="128" y="472" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" font-weight="700" fill="#111">Study</text>
                                        <rect x="248" y="418" width="208" height="88" rx="14" fill="#f4f1e8" stroke="#111" stroke-width="3"/>
                                        <text x="352" y="472" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" font-weight="700" fill="#111">Landing</text>
                                        <rect x="24" y="518" width="208" height="88" rx="14" fill="#f4f1e8" stroke="#111" stroke-width="3"/>
                                        <text x="128" y="572" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" font-weight="700" fill="#111">Bedroom</text>
                                        <rect x="248" y="518" width="208" height="88" rx="14" fill="#f4f1e8" stroke="#111" stroke-width="3"/>
                                        <text x="352" y="572" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" font-weight="700" fill="#111">Garage</text>
                                        <rect x="24" y="618" width="208" height="88" rx="14" fill="#f4f1e8" stroke="#111" stroke-width="3"/>
                                        <text x="128" y="672" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" font-weight="700" fill="#111">Drive</text>
                                        <rect x="248" y="618" width="208" height="88" rx="14" fill="#f4f1e8" stroke="#111" stroke-width="3"/>
                                        <text x="352" y="672" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" font-weight="700" fill="#111">Utility</text>
                                    </svg>
                                    <figcaption>HOUSE — same tile shape as the unlock menu; long names wrap</figcaption>
                                </figure>
                                <figure class="papermono-preview" data-preview-for="pages">
                                    <svg viewBox="0 0 480 800" role="img" aria-label="PaperMono MAIL inbox mock">
                                        <rect width="480" height="800" fill="#f4f1e8"/>
                                        <rect x="8" y="8" width="464" height="784" fill="none" stroke="#1a1a1a" stroke-width="2"/>
                                        <text x="24" y="52" font-family="ui-sans-serif, system-ui, sans-serif" font-size="28" font-weight="700" fill="#111">MAIL</text>
                                        <rect x="378" y="16" width="82" height="82" rx="14" fill="none" stroke="#111" stroke-width="3"/>
                                        <rect x="400" y="50" width="38" height="32" rx="5" fill="#111"/>
                                        <path d="M409 50 v-10 a10 10 0 0 1 20 0 v10" fill="none" stroke="#111" stroke-width="6"/>
                                        <text x="24" y="108" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" fill="#111">Inbox</text>
                                        <rect x="24" y="126" width="432" height="84" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="40" y="154" font-family="ui-sans-serif, system-ui, sans-serif" font-size="20" font-weight="700" fill="#111">Kitchen</text>
                                        <text x="40" y="176" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" fill="#333">Can you pick up milk</text>
                                        <text x="40" y="198" font-family="ui-sans-serif, system-ui, sans-serif" font-size="13" fill="#555">20:14 · Tue 29 Sep</text>
                                        <rect x="24" y="222" width="432" height="84" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="40" y="250" font-family="ui-sans-serif, system-ui, sans-serif" font-size="20" font-weight="700" fill="#111">To Study</text>
                                        <text x="40" y="272" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" fill="#333">On my way</text>
                                        <text x="40" y="294" font-family="ui-sans-serif, system-ui, sans-serif" font-size="13" fill="#555">Sent · 20:11</text>
                                        <rect x="248" y="680" width="208" height="72" rx="12" fill="#111"/>
                                        <text x="352" y="724" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#f4f1e8">WRITE</text>
                                    </svg>
                                    <figcaption>MAIL — inbox with time stamps, read receipts, and WRITE</figcaption>
                                </figure>
                                <figure class="papermono-preview" data-preview-for="lock">
                                    <div class="paper-lock-mock paper-lock-mock--mono" aria-label="PaperMono lock screen from current settings">
                                        <div class="paper-lock-top">
                                            <span class="paper-lock-envelope" title="Unread mail"></span>
                                            <span class="paper-lock-batt" title="Battery"></span>
                                        </div>
                                        <p class="paper-lock-name" data-lock-name>PaperMono</p>
                                        <p class="paper-lock-clock">14:32</p>
                                        <p class="paper-lock-date">Tue 29 Sep</p>
                                        <img class="paper-logo-preview paper-lock-logo" alt="">
                                        <div class="paper-lock-board vestaboard-preview" data-lock-board></div>
                                        <div class="paper-lock-actions">
                                            <span class="paper-lock-unlock">Unlock</span>
                                            <span class="paper-lock-off">OFF</span>
                                        </div>
                                    </div>
                                    <figcaption>Lock screen — envelope when mail is waiting, Unlock, and OFF</figcaption>
                                </figure>
                                <figure class="papermono-preview" data-preview-for="lymow">
                                    <svg viewBox="0 0 480 800" role="img" aria-label="PaperMono Lymow page mock">
                                        <rect width="480" height="800" fill="#f4f1e8"/>
                                        <rect x="8" y="8" width="464" height="784" fill="none" stroke="#1a1a1a" stroke-width="2"/>
                                        <text x="24" y="40" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">LYMOW</text>
                                        <image class="paper-logo-preview" href="" x="284" y="16" width="180" height="180" preserveAspectRatio="xMaxYMin meet"/>
                                        <text x="24" y="180" font-family="ui-sans-serif, system-ui, sans-serif" font-size="72" font-weight="700" fill="#111">64%</text>
                                        <text x="24" y="248" font-family="ui-monospace, monospace" font-size="22" fill="#111">State      Mowing</text>
                                        <text x="24" y="288" font-family="ui-monospace, monospace" font-size="22" fill="#111">Charging   No</text>
                                        <text x="24" y="792" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#444">LYMOW page when the module is on</text>
                                    </svg>
                                    <figcaption>Lymow — battery and work state</figcaption>
                                </figure>
                                <figure class="papermono-preview" data-preview-for="powerwall">
                                    <svg viewBox="0 0 480 800" role="img" aria-label="PaperMono Powerwall page mock">
                                        <rect width="480" height="800" fill="#f4f1e8"/>
                                        <rect x="8" y="8" width="464" height="784" fill="none" stroke="#1a1a1a" stroke-width="2"/>
                                        <text x="24" y="40" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">POWERWALL</text>
                                        <image class="paper-logo-preview" href="" x="284" y="16" width="180" height="180" preserveAspectRatio="xMaxYMin meet"/>
                                        <text x="24" y="180" font-family="ui-sans-serif, system-ui, sans-serif" font-size="72" font-weight="700" fill="#111">81%</text>
                                        <text x="24" y="248" font-family="ui-monospace, monospace" font-size="22" fill="#111">Solar      1.2 kW</text>
                                        <text x="24" y="288" font-family="ui-monospace, monospace" font-size="22" fill="#111">Draw       0.4 kW</text>
                                        <text x="24" y="792" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#444">WALL page when Powerwall is on</text>
                                    </svg>
                                    <figcaption>Powerwall — house battery, solar, and draw</figcaption>
                                </figure>
                                <figure class="papermono-preview" data-preview-for="setup">
                                    <svg viewBox="0 0 480 800" role="img" aria-label="PaperMono setup screen mock, portrait">
                                        <rect width="480" height="800" fill="#f4f1e8"/>
                                        <rect x="8" y="8" width="464" height="784" fill="none" stroke="#1a1a1a" stroke-width="2"/>
                                        <text x="24" y="72" font-family="ui-sans-serif, system-ui, sans-serif" font-size="36" font-weight="700" fill="#111">PaperMono</text>
                                        <text x="24" y="116" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" fill="#111">setup</text>
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
                            <div class="papermono-preview-grid hidden" id="papercolor-preview-grid">
                                <figure class="papermono-preview papercolor-preview" data-preview-for="yarbo">
                                    <svg viewBox="0 0 400 600" role="img" aria-label="Paper Colour home screen mock, 400 by 600 Spectra 6">
                                        <rect width="400" height="600" fill="#fffef6"/>
                                        <rect x="6" y="6" width="388" height="588" fill="none" stroke="#111" stroke-width="2"/>
                                        <text x="20" y="36" font-family="ui-sans-serif, system-ui, sans-serif" font-size="18" font-weight="700" fill="#111">YARBO  ·  COLOUR</text>
                                        <image class="paper-logo-preview" href="" x="244" y="12" width="140" height="140" preserveAspectRatio="xMaxYMin meet"/>
                                        <text x="20" y="58" font-family="ui-sans-serif, system-ui, sans-serif" font-size="20" font-weight="700" fill="#0b6b3a">HOME</text>
                                        <text x="20" y="160" font-family="ui-sans-serif, system-ui, sans-serif" font-size="64" font-weight="700" fill="#111">87%</text>
                                        <text x="20" y="210" font-family="ui-monospace, monospace" font-size="16" fill="#111">Charging  No</text>
                                        <text x="20" y="238" font-family="ui-monospace, monospace" font-size="16" fill="#111">State     idle</text>
                                        <text x="20" y="266" font-family="ui-monospace, monospace" font-size="16" fill="#111">Head      Mower</text>
                                        <text x="20" y="348" font-family="ui-sans-serif, system-ui, sans-serif" font-size="13" fill="#444">No touch · A/B pages · C lock</text>
                                        <rect x="16" y="548" width="70" height="16" fill="#111"/>
                                        <text x="51" y="560" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="10" fill="#fffef6">HOME</text>
                                        <text x="140" y="560" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="10" fill="#111">STATUS</text>
                                        <text x="230" y="560" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="10" fill="#111">HEALTH</text>
                                        <text x="325" y="560" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="10" fill="#111">PLANS</text>
                                        <text x="20" y="586" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11" fill="#444">A prev · B next · C lock</text>
                                    </svg>
                                    <figcaption>Paper Colour Home — 400×600, no Stop/Dock tiles</figcaption>
                                </figure>
                                <figure class="papermono-preview papercolor-preview" data-preview-for="lock">
                                    <div class="paper-lock-mock paper-lock-mock--color" aria-label="Paper Colour lock screen from current settings">
                                        <p class="paper-lock-name" data-lock-name>Paper Colour</p>
                                        <p class="paper-lock-clock">14:32</p>
                                        <p class="paper-lock-date">Tue 29 Sep</p>
                                        <img class="paper-logo-preview paper-lock-logo" alt="">
                                        <div class="paper-lock-board vestaboard-preview" data-lock-board></div>
                                        <p class="paper-lock-hint">C unlocks · hold C for OFF</p>
                                    </div>
                                    <figcaption>Lock screen — logo, Vestaboard, or both from companion settings</figcaption>
                                </figure>
                                <figure class="papermono-preview papercolor-preview" data-preview-for="lymow">
                                    <svg viewBox="0 0 400 600" role="img" aria-label="Paper Colour Lymow page mock">
                                        <rect width="400" height="600" fill="#fffef6"/>
                                        <rect x="6" y="6" width="388" height="588" fill="none" stroke="#111" stroke-width="2"/>
                                        <text x="20" y="36" font-family="ui-sans-serif, system-ui, sans-serif" font-size="18" font-weight="700" fill="#111">LYMOW</text>
                                        <text x="20" y="58" font-family="ui-sans-serif, system-ui, sans-serif" font-size="12" fill="#333">Lymow  0.2.11-colour</text>
                                        <text x="20" y="160" font-family="ui-sans-serif, system-ui, sans-serif" font-size="64" font-weight="700" fill="#111">64%</text>
                                        <text x="20" y="210" font-family="ui-monospace, monospace" font-size="16" fill="#111">State     Mowing</text>
                                        <text x="20" y="238" font-family="ui-monospace, monospace" font-size="16" fill="#111">Charging  No</text>
                                    </svg>
                                    <figcaption>Lymow — shown when the Lymow module is on</figcaption>
                                </figure>
                                <figure class="papermono-preview papercolor-preview" data-preview-for="powerwall">
                                    <svg viewBox="0 0 400 600" role="img" aria-label="Paper Colour Powerwall page mock">
                                        <rect width="400" height="600" fill="#fffef6"/>
                                        <rect x="6" y="6" width="388" height="588" fill="none" stroke="#111" stroke-width="2"/>
                                        <text x="20" y="36" font-family="ui-sans-serif, system-ui, sans-serif" font-size="18" font-weight="700" fill="#111">POWERWALL</text>
                                        <text x="20" y="58" font-family="ui-sans-serif, system-ui, sans-serif" font-size="12" fill="#333">Paper Colour  0.2.11-colour</text>
                                        <text x="20" y="160" font-family="ui-sans-serif, system-ui, sans-serif" font-size="64" font-weight="700" fill="#111">81%</text>
                                        <text x="20" y="210" font-family="ui-monospace, monospace" font-size="16" fill="#111">Solar     1.2 kW</text>
                                        <text x="20" y="238" font-family="ui-monospace, monospace" font-size="16" fill="#111">Draw      0.4 kW</text>
                                    </svg>
                                    <figcaption>Powerwall — shown when the Powerwall module is on</figcaption>
                                </figure>
                                <figure class="papermono-preview papercolor-preview" data-preview-for="setup">
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
                            </details>
                            <h4 class="settings-subtitle">Paired devices</h4>
                            <p class="hint">Battery percent and charging update when a tablet polls the panel (about every 15 seconds on PaperMono, 60 on Paper Colour). USB power shows as Charging even at 100%. Tablets need firmware 0.1.56 / 0.2.17-colour. Tap ⚙️ to rename or revoke — revoke asks twice, then for the tablet name.</p>
                            <div id="papermono-devices" class="papermono-device-list"><p class="hint">None yet.</p></div>
                        </section>

                        <section class="settings-section" id="settings-appearance-section" data-settings-pane="appearance">
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

                        <section class="settings-section" id="settings-update-section" data-settings-pane="updates">
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
                            <h3 class="settings-subtitle">E-paper firmware</h3>
                            <p class="hint">First flash is USB. After that, queue a Wi-Fi update for an online tablet. It shows <strong>UPDATING</strong>, stays on Wi-Fi, then reboots. PaperMono beeps and lights green. Build firmware on the E-paper page first.</p>
                            <div id="settings-paper-ota-list" class="papermono-device-list"></div>
                            <p id="settings-paper-ota-result" class="settings-cloud-result hidden" role="status"></p>
                            <div class="settings-update-actions">
                                <button type="button" class="btn" id="settings-paper-ota-all" disabled>Update all online tablets</button>
                            </div>
                            <h3 class="settings-subtitle">Anonymous usage ping</h3>
                            <p class="hint">While this panel is running it sends an anonymous ping: a random install id, panel version, which modules are on, how many PaperMono / Paper Colour tablets are paired, and Linux or Mac. It pings after a version change and about once a day. It does not include your serial, location, names, or secrets. Set <code>YARBO_METRICS=0</code> to turn it off.</p>
                        </section>

                        <p class="hint settings-trusted-note">Use only on a trusted home network.</p>
                    </div>
                    </div>
                </form>
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
