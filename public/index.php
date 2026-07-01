<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yarbo Control Panel</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php
$config = require dirname(__DIR__) . '/config.php';
$camerasEnabled = (bool) ($config['cameras_enabled'] ?? true);
?>
    <main class="container">
        <header>
            <h1>Yarbo Control Panel</h1>
            <p class="subtitle">Local MQTT control</p>
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

        <section id="toast" class="toast hidden" role="status"></section>
    </main>
    <script src="/assets/app.js"></script>
</body>
</html>
