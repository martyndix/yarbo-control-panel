const POLL_INTERVAL_MS = 5000;
const SNAPSHOT_INTERVAL_MS = 2000;
const DRIVE_REPEAT_MS = 300;
const LINEAR_SPEED = 0.3;
const ANGULAR_SPEED = 0.5;

const els = {
    battery: document.getElementById('battery'),
    state: document.getElementById('state'),
    charging: document.getElementById('charging'),
    heading: document.getElementById('heading'),
    headType: document.getElementById('head-type'),
    errorCode: document.getElementById('error-code'),
    updatedAt: document.getElementById('updated-at'),
    errorBanner: document.getElementById('error-banner'),
    toast: document.getElementById('toast'),
    cameraGrid: document.getElementById('camera-grid'),
    cameraNote: document.getElementById('camera-note'),
    cameraAlert: document.getElementById('camera-alert'),
    driveStatus: document.getElementById('drive-status'),
    map: document.getElementById('map'),
    mapStatus: document.getElementById('map-status'),
    mapAreasStatus: document.getElementById('map-areas-status'),
    connectionType: document.getElementById('connection-type'),
    connectionStatus: document.getElementById('connection-status'),
    batteryTemp: document.getElementById('battery-temp'),
    wirelessCharge: document.getElementById('wireless-charge'),
    rtkStatus: document.getElementById('rtk-status'),
    rtcmAge: document.getElementById('rtcm-age'),
    routePriority: document.getElementById('route-priority'),
    netModuleStatus: document.getElementById('net-module-status'),
};

const DRIVE_VECTORS = {
    forward: { linear: LINEAR_SPEED, angular: 0 },
    backward: { linear: -LINEAR_SPEED, angular: 0 },
    left: { linear: 0, angular: ANGULAR_SPEED },
    right: { linear: 0, angular: -ANGULAR_SPEED },
    stop: { linear: 0, angular: 0 },
};

let driveInterval = null;
let driveActive = false;
let manualModeEntered = false;

let toastTimer = null;
let polling = false;
let cameras = [];
let cameraMode = 'stream';
let snapshotTimer = null;
let streamsAvailable = false;
let map = null;
let mapLayers = { street: null, satellite: null };
let currentMapLayer = 'street';
let robotMarker = null;
let headingLine = null;
let mapHasCentered = false;
let areasLayer = null;

function initMap() {
    if (!els.map || typeof L === 'undefined') return;

    map = L.map(els.map, {
        zoomControl: true,
        attributionControl: true,
    }).setView([51.505, -0.09], 18);

    mapLayers.street = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 22,
        attribution: '&copy; OpenStreetMap contributors',
    });
    mapLayers.satellite = L.tileLayer(
        'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
        {
            maxZoom: 22,
            attribution: 'Tiles &copy; Esri',
        }
    );

    mapLayers.street.addTo(map);
    areasLayer = L.geoJSON([], {
        style: {
            color: '#67b3ff',
            weight: 2,
            fillColor: '#67b3ff',
            fillOpacity: 0.2,
        },
        pointToLayer(_feature, latlng) {
            return L.circleMarker(latlng, {
                radius: 5,
                color: '#67b3ff',
                weight: 2,
                fillColor: '#67b3ff',
                fillOpacity: 0.75,
            });
        },
    }).addTo(map);
}

function setMapLayer(layer) {
    if (!map || !mapLayers[layer]) return;
    if (currentMapLayer === layer) return;
    map.removeLayer(mapLayers[currentMapLayer]);
    mapLayers[layer].addTo(map);
    currentMapLayer = layer;
}

function headingEndpoint(lat, lon, headingDegrees, meters = 3) {
    const headingRad = (headingDegrees * Math.PI) / 180;
    const dLat = (meters * Math.cos(headingRad)) / 111320;
    const dLon = (meters * Math.sin(headingRad)) / (111320 * Math.cos((lat * Math.PI) / 180));
    return [lat + dLat, lon + dLon];
}

function updateMapStatus(message) {
    if (els.mapStatus) els.mapStatus.textContent = message;
}

function updateMapAreasStatus(message) {
    if (els.mapAreasStatus) els.mapAreasStatus.textContent = message;
}

function fmtOrDash(value) {
    return value == null || value === '' ? '—' : String(value);
}

function formatStructured(value) {
    if (value == null || value === '') return '—';
    if (Array.isArray(value)) {
        if (value.length === 0) return '—';
        return value.map((item) => formatStructured(item)).join(', ');
    }
    if (typeof value === 'object') {
        const entries = Object.entries(value);
        if (entries.length === 0) return '—';
        return entries
            .map(([k, v]) => `${k}: ${formatStructured(v)}`)
            .join(', ');
    }
    return String(value);
}

function toNumberOrNull(value) {
    return value == null || value === '' || Number.isNaN(Number(value)) ? null : Number(value);
}

function formatRoutePriority(value) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return formatStructured(value);
    }

    const ifaceNames = {
        hg0: 'HaLow',
        wlan0: 'WiFi',
        wwan0: '4G',
    };

    const ranked = Object.entries(value)
        .map(([iface, priority]) => ({
            iface,
            label: ifaceNames[iface] || iface,
            priority: toNumberOrNull(priority),
        }))
        .filter((row) => row.priority != null)
        .sort((a, b) => a.priority - b.priority);

    if (ranked.length === 0) {
        return 'No route data';
    }

    const primary = ranked[0];
    const backups = ranked.slice(1).map((r) => `${r.label} (${r.priority})`).join(', ');
    return backups
        ? `Primary: ${primary.label} (${primary.priority}) | Backup: ${backups}`
        : `Primary: ${primary.label} (${primary.priority})`;
}

function formatNetModuleStatus(value) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return formatStructured(value);
    }

    const statusRaw = toNumberOrNull(value.lte_status);
    const statusLabel = statusRaw === 1 ? 'Connected' : statusRaw === 0 ? 'Disconnected' : 'Unknown';

    const csq = toNumberOrNull(value.lte_csq);
    const signalLabel = csq == null || csq === 99
        ? 'Unknown'
        : `${Math.round((Math.max(0, Math.min(31, csq)) / 31) * 100)}%`;

    const rsrp = toNumberOrNull(value.lte_rsrp);
    const rssi = toNumberOrNull(value.lte_rssi);
    const radioLabel = rsrp && rsrp < 0
        ? `RSRP ${rsrp} dBm`
        : rssi && rssi < 0
            ? `RSSI ${rssi} dBm`
            : 'Radio n/a';

    const iccid = String(value.lte_iccid ?? '');
    const simLabel = iccid.length >= 4 ? `SIM ****${iccid.slice(-4)}` : 'SIM n/a';

    return `LTE: ${statusLabel} | Signal: ${signalLabel} | ${radioLabel} | ${simLabel}`;
}

function formatTemp(value) {
    if (value == null || Number.isNaN(Number(value))) return '—';
    return `${Number(value).toFixed(1)}°C`;
}

function formatBatteryTemp(diag) {
    const temp = formatTemp(diag?.temperature_c);
    if (temp === '—') return temp;
    if (diag?.temperature_source === 'avg_cells') return `${temp} (avg cells)`;
    return temp;
}

function formatWirelessCharge(diag) {
    const volts = diag?.wireless_charge_voltage;
    const amps = diag?.wireless_charge_current;
    if (volts == null && amps == null) return '—';
    if (volts != null && amps != null) {
        return `${Number(volts).toFixed(2)}V / ${Number(amps).toFixed(2)}A`;
    }
    if (volts != null) return `${Number(volts).toFixed(2)}V`;
    return `${Number(amps).toFixed(2)}A`;
}

function updateRobotOnMap(data) {
    if (!map || !els.mapStatus) return;

    const lat = Number(data.latitude);
    const lon = Number(data.longitude);
    const fixQuality = Number(data.fix_quality ?? 0);
    const heading = Number(data.heading ?? 0);
    const hasFix = Boolean(data.gps_valid) && Number.isFinite(lat) && Number.isFinite(lon);

    if (!hasFix) {
        updateMapStatus(`No GPS fix yet (fix_quality=${fixQuality}). Move outdoors and wait for RTK/GNSS lock.`);
        return;
    }

    if (!robotMarker) {
        robotMarker = L.circleMarker([lat, lon], {
            radius: 8,
            color: '#7ddea0',
            weight: 2,
            fillColor: '#3d9a5f',
            fillOpacity: 0.9,
        }).addTo(map);
    } else {
        robotMarker.setLatLng([lat, lon]);
    }

    const tip = headingEndpoint(lat, lon, heading);
    if (!headingLine) {
        headingLine = L.polyline([[lat, lon], tip], {
            color: '#7ddea0',
            weight: 3,
            opacity: 0.9,
        }).addTo(map);
    } else {
        headingLine.setLatLngs([[lat, lon], tip]);
    }

    if (!mapHasCentered) {
        map.setView([lat, lon], 20);
        mapHasCentered = true;
    }

    const altitudeLabel = data.altitude != null ? `${Number(data.altitude).toFixed(1)}m` : 'n/a';
    updateMapStatus(
        `GPS locked (fix_quality=${fixQuality}) at ${lat.toFixed(6)}, ${lon.toFixed(6)} | altitude ${altitudeLabel}`
    );
}

async function loadSavedAreas(button = null) {
    if (!map || !areasLayer) return;
    if (button) button.disabled = true;
    updateMapAreasStatus('Loading saved map areas...');

    try {
        const res = await fetch('/api/map.php');
        const data = await res.json();
        if (!data.ok) {
            updateMapAreasStatus(`Saved areas unavailable: ${data.error || 'request failed'}`);
            showToast(data.error || 'Failed to load saved map areas', 'error');
            return;
        }

        areasLayer.clearLayers();
        const featureCollection = data.geojson || { type: 'FeatureCollection', features: [] };
        const features = Array.isArray(featureCollection.features) ? featureCollection.features : [];

        if (features.length > 0) {
            areasLayer.addData(featureCollection);
            updateMapAreasStatus(`Saved areas loaded (${features.length} feature${features.length === 1 ? '' : 's'}) from ${data.source || 'map commands'}.`);
            showToast('Saved mowing areas loaded', 'success');
            if (!mapHasCentered) {
                const bounds = areasLayer.getBounds?.();
                if (bounds && bounds.isValid && bounds.isValid()) {
                    map.fitBounds(bounds.pad(0.15));
                }
            }
            return;
        }

        const warning = (data.warnings && data.warnings[0]) || null;
        if (data.status === 'empty') {
            updateMapAreasStatus('No saved map areas returned yet. Create/save a map in Yarbo app, then try again.');
            showToast('No saved map data yet', 'error');
        } else if (data.status === 'structured_no_geometry') {
            updateMapAreasStatus('Map data returned but no drawable geometry detected yet (firmware format may differ).');
            showToast('Map data found but not drawable yet', 'error');
        } else {
            updateMapAreasStatus(warning || 'Saved areas not available on this mower/firmware.');
            showToast('Saved area extraction not supported yet', 'error');
        }
    } catch (err) {
        updateMapAreasStatus(`Saved areas request failed: ${err.message || 'network error'}`);
        showToast(err.message || 'Network error', 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

function showToast(message, type = 'success') {
    els.toast.textContent = message;
    els.toast.className = `toast ${type}`;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => els.toast.classList.add('hidden'), 3000);
}

function setError(message) {
    if (message) {
        els.errorBanner.textContent = message;
        els.errorBanner.classList.remove('hidden');
    } else {
        els.errorBanner.classList.add('hidden');
    }
}

function formatUpdatedAt(iso) {
    if (!iso) return 'never';
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}

function updateStatus(data) {
    els.battery.textContent = data.battery != null ? `${data.battery}%` : '—';
    els.state.textContent = data.state ?? '—';
    els.state.className = `value badge${data.state === 'active' ? ' active' : ''}`;
    els.charging.textContent = data.charging ? 'Yes' : 'No';
    els.heading.textContent = data.heading != null ? `${data.heading}°` : '—';
    els.headType.textContent = data.head_type_name ?? '—';
    els.errorCode.textContent = data.error_code ?? '—';
    els.updatedAt.textContent = formatUpdatedAt(data.updated_at);
    updateRobotOnMap(data);
    renderDiagnostics(data);
}

function renderDiagnostics(data) {
    const network = data.network || {};
    const batteryDiag = data.battery_diagnostics || {};
    const rtkDiag = data.rtk_diagnostics || {};

    if (els.connectionType) {
        els.connectionType.textContent = fmtOrDash(data.connection_type);
    }
    if (els.connectionStatus) {
        const status = String(data.connection_status || 'Unknown');
        els.connectionStatus.textContent = status;
        const badgeClass = status.toLowerCase();
        els.connectionStatus.className = `value badge ${badgeClass}`;
    }
    if (els.batteryTemp) {
        els.batteryTemp.textContent = formatBatteryTemp(batteryDiag);
    }
    if (els.wirelessCharge) {
        els.wirelessCharge.textContent = formatWirelessCharge(batteryDiag);
    }
    if (els.rtkStatus) {
        const rtk = rtkDiag.rtk_status;
        const fix = rtkDiag.fix_quality;
        const suffix = fix != null ? ` (fix ${fix})` : '';
        els.rtkStatus.textContent = rtk != null ? `${rtk}${suffix}` : '—';
    }
    if (els.rtcmAge) {
        const age = network.rtcm_age;
        els.rtcmAge.textContent = age != null ? `${age}` : '—';
    }
    if (els.routePriority) {
        els.routePriority.textContent = formatRoutePriority(network.route_priority);
        els.routePriority.classList.add('compact');
    }
    if (els.netModuleStatus) {
        els.netModuleStatus.textContent = formatNetModuleStatus(network.net_module_status);
        els.netModuleStatus.classList.add('compact');
    }
}

async function fetchStatus() {
    if (polling) return;
    polling = true;
    try {
        const res = await fetch('/api/status.php');
        const data = await res.json();
        if (data.ok) {
            setError(null);
            updateStatus(data);
            updateCameraStatus(data.camera_state);
        } else {
            setError(data.error || 'Failed to fetch status');
        }
    } catch (err) {
        setError(err.message || 'Network error');
    } finally {
        polling = false;
    }
}

function cameraModeUrl(cameraId) {
    if (cameraMode === 'snapshot') {
        return `/api/camera_snapshot.php?camera=${encodeURIComponent(cameraId)}&t=${Date.now()}`;
    }
    return `/api/camera_stream.php?camera=${encodeURIComponent(cameraId)}`;
}

function renderCameras() {
    if (!cameras.length) {
        els.cameraGrid.innerHTML = '<p class="camera-placeholder">No cameras configured.</p>';
        return;
    }

    if (!streamsAvailable) {
        els.cameraGrid.innerHTML = cameras.map((camera) => {
            const statusClass = camera.online === true ? 'online' : camera.online === false ? 'offline' : '';
            const statusText = camera.online === true ? 'online' : camera.online === false ? 'offline' : 'unknown';
            return `
                <article class="camera-tile" data-camera="${camera.id}">
                    <div class="camera-head">
                        <span>${camera.name}</span>
                        <span class="camera-status ${statusClass}">${statusText}</span>
                    </div>
                    <div class="camera-viewport has-error">
                        <div class="camera-error">Stream unavailable.<br>RTSP not reachable on this host.</div>
                    </div>
                </article>
            `;
        }).join('');
        return;
    }

    els.cameraGrid.innerHTML = cameras.map((camera) => {
        const statusClass = camera.online === true ? 'online' : camera.online === false ? 'offline' : '';
        const statusText = camera.online === true ? 'online' : camera.online === false ? 'offline' : 'unknown';
        return `
            <article class="camera-tile" data-camera="${camera.id}">
                <div class="camera-head">
                    <span>${camera.name}</span>
                    <span class="camera-status ${statusClass}">${statusText}</span>
                </div>
                <div class="camera-viewport">
                    <img src="${cameraModeUrl(camera.id)}" alt="${camera.name} camera" loading="lazy"
                         onerror="this.closest('.camera-viewport').classList.add('has-error'); this.insertAdjacentHTML('afterend','<div class=\\'camera-error\\'>Could not load stream</div>'); this.remove();">
                </div>
            </article>
        `;
    }).join('');
}

function updateCameraStatus(cameraState) {
    if (!cameraState || !cameras.length) return;

    const stateKeys = {
        front: 'cam_m_state',
        left: 'cam_l_state',
        right: 'cam_r_state',
        rear: 'cam_b_state',
    };

    cameras = cameras.map((camera) => ({
        ...camera,
        online: stateKeys[camera.id]
            ? Number(cameraState[stateKeys[camera.id]] ?? 0) === 1
            : camera.online,
    }));

    document.querySelectorAll('.camera-tile').forEach((tile) => {
        const id = tile.dataset.camera;
        const stateKey = stateKeys[id];
        if (!stateKey) return;
        const online = Number(cameraState[stateKey] ?? 0) === 1;
        const badge = tile.querySelector('.camera-status');
        badge.textContent = online ? 'online' : 'offline';
        badge.className = `camera-status ${online ? 'online' : 'offline'}`;
    });
}

function refreshSnapshotImages() {
    if (cameraMode !== 'snapshot') return;
    document.querySelectorAll('.camera-tile img').forEach((img) => {
        const url = new URL(img.src, window.location.origin);
        url.searchParams.set('t', String(Date.now()));
        img.src = url.toString();
    });
}

function setCameraMode(mode) {
    cameraMode = mode;
    renderCameras();
    clearInterval(snapshotTimer);
    if (mode === 'snapshot') {
        snapshotTimer = setInterval(refreshSnapshotImages, SNAPSHOT_INTERVAL_MS);
    }
}

async function loadCameras() {
    try {
        const res = await fetch('/api/cameras.php');
        const data = await res.json();
        if (!data.ok) return;
        cameras = data.cameras || [];
        streamsAvailable = Boolean(data.ports_open);
        if (data.message) {
            els.cameraAlert.textContent = data.message;
            els.cameraAlert.classList.remove('hidden');
        } else {
            els.cameraAlert.classList.add('hidden');
        }
        const setup = document.getElementById('camera-setup');
        if (data.setup && !streamsAvailable) {
            setup.innerHTML = Object.values(data.setup)
                .map((step) => `<li>${step}</li>`)
                .join('');
            setup.classList.remove('hidden');
        } else {
            setup.classList.add('hidden');
        }
        renderCameras();
        if (streamsAvailable && cameraMode === 'snapshot') {
            snapshotTimer = setInterval(refreshSnapshotImages, SNAPSHOT_INTERVAL_MS);
        }
    } catch {
        els.cameraGrid.innerHTML = '<p class="camera-placeholder">Could not load camera config.</p>';
    }
}

async function prepareCameras(button) {
    button.disabled = true;
    try {
        const res = await fetch('/api/camera_prepare.php', { method: 'POST' });
        const data = await res.json();
        if (data.ok) {
            showToast('Camera MQTT commands sent', 'success');
            await loadCameras();
        } else {
            showToast(data.error || 'Prepare failed', 'error');
        }
    } catch (err) {
        showToast(err.message || 'Network error', 'error');
    } finally {
        button.disabled = false;
    }
}

async function sendDrive(linear, angular, enterManual = false) {
    try {
        const res = await fetch('/api/drive.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ linear, angular, enter_manual: enterManual }),
        });
        const data = await res.json();
        if (!data.ok) {
            showToast(data.error || 'Drive command failed', 'error');
            if (els.driveStatus) els.driveStatus.textContent = 'Error';
            return false;
        }
        if (enterManual) manualModeEntered = true;
        return true;
    } catch (err) {
        showToast(err.message || 'Network error', 'error');
        if (els.driveStatus) els.driveStatus.textContent = 'Error';
        return false;
    }
}

function sendDrivePulse(linear, angular) {
    const enterManual = !manualModeEntered;
    sendDrive(linear, angular, enterManual);
}

function driveLabel(direction) {
    return {
        forward: 'Forward',
        backward: 'Backward',
        left: 'Turning left',
        right: 'Turning right',
        stop: 'Stopped',
    }[direction] || 'Driving';
}

function stopDriveLoop() {
    clearInterval(driveInterval);
    driveInterval = null;
    driveActive = false;
    document.querySelectorAll('.btn-drive.active').forEach((btn) => btn.classList.remove('active'));
}

async function startDrive(direction, button) {
    const vector = DRIVE_VECTORS[direction];
    if (!vector) return;

    if (direction === 'stop') {
        stopDriveLoop();
        await sendDrive(0, 0, false);
        if (els.driveStatus) els.driveStatus.textContent = 'Stopped';
        return;
    }

    if (!sessionStorage.getItem('yarbo_drive_ack')) {
        const ok = confirm(
            'Manual drive takes control from the Yarbo app. Only drive on flat, clear ground. Continue?'
        );
        if (!ok) return;
        sessionStorage.setItem('yarbo_drive_ack', '1');
    }

    stopDriveLoop();
    driveActive = true;
    button.classList.add('active');
    if (els.driveStatus) els.driveStatus.textContent = driveLabel(direction);

    sendDrivePulse(vector.linear, vector.angular);
    driveInterval = setInterval(() => {
        if (!driveActive) return;
        sendDrivePulse(vector.linear, vector.angular);
    }, DRIVE_REPEAT_MS);
}

function setupDrivePad() {
    const pad = document.getElementById('drive-pad');
    if (!pad) return;

    pad.querySelectorAll('[data-drive]').forEach((button) => {
        const direction = button.dataset.drive;

        const endDrive = async () => {
            if (!driveActive || button.dataset.drive === 'stop') return;
            stopDriveLoop();
            await sendDrive(0, 0, false);
            if (els.driveStatus) els.driveStatus.textContent = 'Ready';
        };

        button.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            button.setPointerCapture(e.pointerId);
            if (direction === 'stop') {
                startDrive('stop', button);
            } else {
                startDrive(direction, button);
            }
        });

        button.addEventListener('pointerup', endDrive);
        button.addEventListener('pointercancel', endDrive);
        button.addEventListener('lostpointercapture', endDrive);
    });
}

async function sendCommand(action, button) {
    button.disabled = true;
    try {
        const res = await fetch('/api/command.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=${encodeURIComponent(action)}`,
        });
        const data = await res.json();
        if (data.ok) {
            showToast(`${action.replace(/_/g, ' ')} sent`, 'success');
            await fetchStatus();
        } else {
            showToast(data.error || 'Command failed', 'error');
        }
    } catch (err) {
        showToast(err.message || 'Network error', 'error');
    } finally {
        button.disabled = false;
    }
}

document.querySelectorAll('[data-action]').forEach((button) => {
    button.addEventListener('click', () => {
        const action = button.dataset.action;
        if (action === 'stop' && !confirm('Send graceful stop to Yarbo?')) return;
        if (action === 'return_to_dock' && !confirm('Send Yarbo back to the dock?')) return;
        sendCommand(action, button);
    });
});

document.querySelectorAll('input[name="camera-mode"]').forEach((input) => {
    input.addEventListener('change', () => {
        if (input.checked) setCameraMode(input.value);
    });
});

document.querySelectorAll('input[name="map-layer"]').forEach((input) => {
    input.addEventListener('change', () => {
        if (input.checked) setMapLayer(input.value);
    });
});

document.getElementById('camera-prepare')?.addEventListener('click', (e) => {
    prepareCameras(e.currentTarget);
});

document.getElementById('camera-recheck')?.addEventListener('click', async (e) => {
    const button = e.currentTarget;
    button.disabled = true;
    await loadCameras();
    button.disabled = false;
    showToast(streamsAvailable ? 'Streams available' : 'Still no RTSP — is the tunnel running?', streamsAvailable ? 'success' : 'error');
});

document.getElementById('map-load-areas')?.addEventListener('click', (e) => {
    loadSavedAreas(e.currentTarget);
});

if (document.getElementById('camera-grid')) {
    loadCameras();
}
if (document.getElementById('map')) {
    initMap();
}
setupDrivePad();
fetchStatus();
setInterval(fetchStatus, POLL_INTERVAL_MS);
