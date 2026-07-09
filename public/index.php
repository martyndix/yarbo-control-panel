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
    <link rel="stylesheet" href="/assets/style.css">
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
                <div class="stat">
                    <span class="label">Battery Temp</span>
                    <span id="battery-temp" class="value">—</span>
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
            <p class="hint">Hold a direction to move. Release to stop. Takes controller from the Yarbo app — use with care.</p>
            <div class="dpad" id="drive-pad">
                <button type="button" class="btn btn-drive" data-drive="forward" aria-label="Forward">▲</button>
                <button type="button" class="btn btn-drive" data-drive="left" aria-label="Turn left">◀</button>
                <button type="button" class="btn btn-drive btn-drive-stop" data-drive="stop" aria-label="Stop">■</button>
                <button type="button" class="btn btn-drive" data-drive="right" aria-label="Turn right">▶</button>
                <button type="button" class="btn btn-drive" data-drive="backward" aria-label="Backward">▼</button>
            </div>
            <p class="drive-status" id="drive-status">Ready</p>
        </section>

        <section class="card panel-section plans-card" data-panel-id="plans">
            <div class="section-header section-header--simple">
                <h2>Work Plans</h2>
                <button type="button" class="section-drag-handle" draggable="true" aria-label="Drag to reorder" title="Drag to reorder">⋮⋮</button>
            </div>
            <p class="hint">Load saved plans from the robot, then start at a chosen progress percentage. Some firmware only responds to <code>read_all_plan</code> while the robot is active.</p>
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
            <p class="hint">Commands acquire controller role and may take control from the Yarbo app.</p>
            <div class="control-tiles" id="control-tiles">
                <button type="button" class="control-tile" id="control-lights" data-control="lights" aria-pressed="false" title="Turn lights on">
                    <span class="control-tile-icon" id="control-lights-icon" aria-hidden="true">🔅</span>
                    <span class="control-tile-label" id="control-lights-label">Off</span>
                </button>
                <button type="button" class="control-tile" data-action="buzzer" title="Sound buzzer">
                    <span class="control-tile-icon" aria-hidden="true">🔊</span>
                    <span class="control-tile-label">Buzzer</span>
                </button>
                <button type="button" class="control-tile" id="control-pause-resume" data-control="pause_resume" title="Pause or resume">
                    <span class="control-tile-icon" id="control-pause-resume-icon" aria-hidden="true">⏸</span>
                    <span class="control-tile-label" id="control-pause-resume-label">Pause</span>
                </button>
                <button type="button" class="control-tile" data-action="return_to_dock" title="Return to dock">
                    <span class="control-tile-icon" aria-hidden="true">🏠</span>
                    <span class="control-tile-label">Dock</span>
                </button>
                <button type="button" class="control-tile control-tile-danger" data-action="stop" title="Emergency stop">
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
                    <p class="hint settings-modal-lead">Connection, optional cloud reads, and panel updates.</p>
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
                                <button type="button" class="btn btn-secondary hidden" id="settings-update-view-notes">View release notes</button>
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
    <script src="/assets/app.js"></script>
</body>
</html>
