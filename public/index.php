<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yarbo Control Panel</title>
    <link rel="stylesheet" href="/assets/style.css">
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
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
                <p class="subtitle">Local MQTT control</p>
            </div>
            <button
                type="button"
                id="settings-open"
                class="btn btn-secondary btn-settings"
                aria-haspopup="dialog"
                aria-controls="settings-modal"
            >Settings</button>
        </header>

        <section id="error-banner" class="banner error hidden" role="alert"></section>

        <section class="card status-card">
            <h2>Status</h2>
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

        <section class="card diagnostics-card">
            <h2>Connection &amp; Health</h2>
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

        <section class="card map-card">
            <div class="section-header">
                <h2>Location Map</h2>
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
            </div>
            <p class="hint">Live GPS from RTK telemetry. Valid GPS lock is required (outdoors).</p>
            <div class="map-actions">
                <button type="button" class="btn btn-secondary" id="map-load-areas">Load saved mowing areas (beta)</button>
            </div>
            <div id="map" class="map"></div>
            <p id="map-status" class="map-status">Waiting for GPS fix...</p>
            <p id="map-areas-status" class="map-areas-status">Saved areas: not loaded yet.</p>
        </section>

        <?php if ($camerasEnabled): ?>
        <section class="card cameras-card">
            <div class="section-header">
                <h2>Cameras</h2>
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

        <section class="card drive-card">
            <h2>Manual Drive</h2>
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

        <section class="card plans-card">
            <h2>Work Plans</h2>
            <p class="hint">Load saved plans from the robot, then start at a chosen progress percentage. Some firmware only responds to <code>read_all_plan</code> while the robot is active.</p>
            <div class="plans-toolbar">
                <label class="plan-percent">
                    Start at
                    <input type="range" id="plan-start-percent" min="0" max="100" value="0">
                    <span id="plan-start-percent-label">0%</span>
                </label>
                <button type="button" class="btn btn-secondary" id="plans-load">Load plans</button>
            </div>
            <p id="plans-status" class="plans-status">Plan activity: —</p>
            <p id="plans-note" class="plans-note">No plans loaded yet.</p>
            <div id="plans-list" class="plans-list"></div>
        </section>

        <section class="card waypoints-card">
            <h2>Waypoints</h2>
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

        <section class="card controls-card">
            <h2>Controls</h2>
            <p class="hint">Commands acquire controller role and may take control from the Yarbo app.</p>
            <div class="button-grid">
                <button type="button" class="btn" data-action="lights_on">Lights On</button>
                <button type="button" class="btn" data-action="lights_off">Lights Off</button>
                <button type="button" class="btn" data-action="buzzer">Buzzer</button>
                <button type="button" class="btn" data-action="pause">Pause</button>
                <button type="button" class="btn" data-action="resume">Resume</button>
                <button type="button" class="btn" data-action="return_to_dock">Return to Dock</button>
                <button type="button" class="btn btn-danger" data-action="stop">Stop</button>
            </div>
        </section>

        <div id="settings-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="settings-title">
            <button type="button" class="modal-backdrop" data-settings-close aria-label="Close settings"></button>
            <div class="modal-panel card">
                <div class="section-header">
                    <h2 id="settings-title">Connection settings</h2>
                </div>
                <form id="settings-form" class="settings-form">
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
                    <p class="hint">Updates <code>config.php</code> on this server. Use only on a trusted home network.</p>
                    <p id="settings-error" class="settings-error hidden" role="alert"></p>
                    <div class="modal-actions">
                        <button type="submit" class="btn" id="settings-save">Save</button>
                        <button type="button" class="btn btn-secondary" data-settings-close>Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <section id="toast" class="toast hidden" role="status"></section>
    </main>
    <script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""
    ></script>
    <script src="/assets/app.js"></script>
</body>
</html>
