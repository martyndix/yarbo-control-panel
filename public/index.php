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
$assetVersion = $panelVersion . '.' . (string) (@filemtime(__DIR__ . '/assets/app.js') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yarbo Control Panel</title>
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
                <h1>Yarbo Control Panel</h1>
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

        <section class="card panel-section status-card" data-panel-id="status">
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
            </div>
            <p class="updated">Last updated: <span id="updated-at">never</span></p>
        </section>

        <section class="card panel-section vestaboard-card hidden" id="vestaboard-card" data-panel-id="vestaboard">
            <div class="section-header section-header--simple">
                <h2>Vestaboard Note</h2>
                <button type="button" class="section-drag-handle" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button>
            </div>
            <div class="vestaboard-preview vestaboard-preview--dashboard" id="vestaboard-board" aria-label="Vestaboard Note 3 by 15 live display"></div>
            <p class="updated">Last written: <span id="vestaboard-updated-at">never</span><span id="vestaboard-updated-detail"></span></p>
        </section>

        <section class="card panel-section diagnostics-card" data-panel-id="diagnostics">
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
                    <span class="label">Net Module</span>
                    <span id="net-module-status" class="value">—</span>
                </div>
            </div>
        </section>

        <section class="card panel-section map-card" data-panel-id="map">
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
                <details open>
                    <summary>Map zones</summary>
                    <ul id="map-zone-list" class="map-zone-list"></ul>
                </details>
            </div>
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
        </section>

        <?php if ($camerasEnabled): ?>
        <section class="card panel-section cameras-card" data-panel-id="cameras">
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

        <section class="card panel-section drive-card" data-panel-id="drive">
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

        <section class="card panel-section plans-card" data-panel-id="plans">
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
            <p id="plans-status" class="plans-status">Plan activity: —</p>
            <p id="plans-note" class="plans-note">No plans loaded yet.</p>
            <div id="plans-list" class="plans-list"></div>
        </section>

        <section class="card panel-section waypoints-card" data-panel-id="waypoints">
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

        <section class="card panel-section head-card hidden" id="head-controls-card" data-panel-id="head">
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

        <section class="card panel-section controls-card" data-panel-id="controls">
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

        </div>

        <div id="settings-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="settings-title">
            <button type="button" class="modal-backdrop" data-settings-close aria-label="Close settings"></button>
            <div class="modal-panel card settings-modal">
                <div class="settings-modal-header">
                    <h2 id="settings-title">Settings</h2>
                    <p class="hint settings-modal-lead">Connection, optional cloud reads, Vestaboard Note, PaperMono companion (beta), and panel updates.</p>
                </div>
                <form id="settings-form" class="settings-form">
                    <div class="settings-modal-scroll">
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

                        <section class="settings-section" id="settings-vestaboard-section">
                            <h3 class="settings-subtitle">Vestaboard Note <span class="settings-beta-badge">Optional</span></h3>
                            <p class="hint">Show Yarbo status on a <a href="https://docs.vestaboard.com/docs/read-write-api/introduction/" target="_blank" rel="noopener">Vestaboard Note</a> (3×15). Choose Local API on your LAN or Vestaboard’s Cloud API. Credentials stay hidden until enabled. When enabled, a matching 3×15 section appears on the main dashboard. The panel pushes when the message changes (at most every 15 seconds). See <code>docs/vestaboard.md</code>.</p>
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
                                        <option value="error">Sample: error</option>
                                    </select>
                                </label>
                                <div class="vestaboard-preview" id="settings-vestaboard-preview" aria-label="Vestaboard Note 3 by 15 preview"></div>
                                <p id="settings-vestaboard-preview-caption" class="hint">YARBO status on a 3×15 Note</p>
                                <p id="settings-vestaboard-result" class="settings-cloud-result hidden" role="status"></p>
                                <div class="papermono-actions">
                                    <button type="button" class="btn btn-secondary" id="settings-vestaboard-test">Test connection</button>
                                    <button type="button" class="btn btn-secondary" id="settings-vestaboard-send">Send now</button>
                                </div>
                            </div>
                        </section>

                        <section class="settings-section" id="settings-papermono-section">
                            <h3 class="settings-subtitle">PaperMono companion <span class="settings-beta-badge">Beta</span></h3>
                            <p class="hint">Built for <a href="https://docs.m5stack.com/en/core/PaperMono" target="_blank" rel="noopener">M5Stack PaperMono SKU C153</a> (<a href="https://shop.m5stack.com/products/m5papermono-with-lora-nfc-800x480-3-97-eink-display" target="_blank" rel="noopener">shop</a>): ESP32-S3R8, 3.97″ 480×800 SSD1677 e-paper, FT6336G touch, 2.4 GHz Wi-Fi. Not PaperMono-Lite. It has no browser — this panel flashes native firmware over USB, then the device talks HTTP JSON to the panel (the panel stays the MQTT brain). Status plus Stop / Dock / Pause / Lights only. No map, cameras, plans, or hold-to-drive. See <code>docs/papermono.md</code>.</p>
                            <div class="papermono-preview-grid" aria-hidden="true">
                                <figure class="papermono-preview">
                                    <svg viewBox="0 0 480 800" role="img" aria-label="PaperMono home screen mock, portrait 480 by 800">
                                        <rect width="480" height="800" fill="#f4f1e8"/>
                                        <rect x="8" y="8" width="464" height="784" fill="none" stroke="#1a1a1a" stroke-width="2"/>
                                        <text x="24" y="48" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">YARBO  ·  BETA</text>
                                        <text x="24" y="76" font-family="ui-sans-serif, system-ui, sans-serif" font-size="14" fill="#333">Barn PaperMono  0.1.0-beta</text>
                                        <text x="24" y="180" font-family="ui-sans-serif, system-ui, sans-serif" font-size="72" font-weight="700" fill="#111">87%</text>
                                        <text x="24" y="240" font-family="ui-monospace, monospace" font-size="22" fill="#111">Charging  No</text>
                                        <text x="24" y="280" font-family="ui-monospace, monospace" font-size="22" fill="#111">State     idle</text>
                                        <text x="24" y="320" font-family="ui-monospace, monospace" font-size="22" fill="#111">Head      Mower</text>
                                        <text x="24" y="360" font-family="ui-monospace, monospace" font-size="22" fill="#111">Error     0</text>
                                        <rect x="24" y="520" width="208" height="88" rx="12" fill="#111"/>
                                        <text x="128" y="574" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="24" font-weight="700" fill="#f4f1e8">STOP</text>
                                        <rect x="248" y="520" width="208" height="88" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="352" y="574" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="24" font-weight="700" fill="#111">DOCK</text>
                                        <rect x="24" y="620" width="208" height="88" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="128" y="674" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">PAUSE</text>
                                        <rect x="248" y="620" width="208" height="88" rx="12" fill="#f4f1e8" stroke="#111" stroke-width="2"/>
                                        <text x="352" y="674" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif" font-size="22" font-weight="700" fill="#111">LIGHTS</text>
                                        <text x="24" y="776" font-family="ui-sans-serif, system-ui, sans-serif" font-size="12" fill="#444">192.168.1.50</text>
                                        <text x="456" y="776" text-anchor="end" font-family="ui-sans-serif, system-ui, sans-serif" font-size="12" fill="#444">tap · e-paper</text>
                                    </svg>
                                    <figcaption>Home — 480×800 portrait, battery and four buttons</figcaption>
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
                            <p id="papermono-fw-status" class="hint">Firmware: checking…</p>
                            <label class="settings-field">
                                <span class="label">USB serial port</span>
                                <select id="papermono-port">
                                    <option value="">Refresh ports with the PaperMono plugged in</option>
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
                                <input type="password" id="papermono-wifi-password" name="papermono_wifi_password" autocomplete="new-password" placeholder="2.4 GHz only — PaperMono has no 5 GHz">
                            </label>
                            <label class="settings-field">
                                <span class="label">Panel URL (this server, as the PaperMono will reach it)</span>
                                <input type="url" id="papermono-panel-url" name="papermono_panel_url" autocomplete="off" spellcheck="false" placeholder="http://192.168.1.50:8080">
                            </label>
                            <label class="settings-field">
                                <span class="label">Device name</span>
                                <input type="text" id="papermono-name" name="papermono_name" value="PaperMono" autocomplete="off">
                            </label>
                            <div class="papermono-actions">
                                <button type="button" class="btn" id="papermono-flash">Flash firmware &amp; send Wi-Fi</button>
                                <button type="button" class="btn btn-secondary" id="papermono-config">Send Wi-Fi only (already flashed)</button>
                            </div>
                            <p class="hint">First flash takes one to two minutes. Leave this Settings page open. Build the binary on this host first: <code>pip3 install platformio && pio run -d firmware/papermono</code>. If the port list fails, click <strong>Install USB tools</strong> to add <code>pyserial</code> and <code>esptool</code> to this panel’s Python environment. The firmware keeps the SSD1677 healthy: full refresh every 10 partials, no redraw when nothing changed, 15s poll. Keep the tablet out of direct sun.</p>
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
