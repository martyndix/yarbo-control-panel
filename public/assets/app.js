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
    wifiNetwork: document.getElementById('wifi-network'),
    wifiSignal: document.getElementById('wifi-signal'),
    wifiSecurity: document.getElementById('wifi-security'),
    batteryTemp: document.getElementById('battery-temp'),
    wirelessCharge: document.getElementById('wireless-charge'),
    rtkStatus: document.getElementById('rtk-status'),
    rtcmAge: document.getElementById('rtcm-age'),
    routePriority: document.getElementById('route-priority'),
    netModuleStatus: document.getElementById('net-module-status'),
    planStartPercent: document.getElementById('plan-start-percent'),
    planStartPercentLabel: document.getElementById('plan-start-percent-label'),
    plansLoad: document.getElementById('plans-load'),
    plansStatus: document.getElementById('plans-status'),
    plansNote: document.getElementById('plans-note'),
    plansList: document.getElementById('plans-list'),
    waypointIndex: document.getElementById('waypoint-index'),
    waypointName: document.getElementById('waypoint-name'),
    waypointSaveForm: document.getElementById('waypoint-save-form'),
    waypointSave: document.getElementById('waypoint-save'),
    waypointsList: document.getElementById('waypoints-list'),
    waypointsNote: document.getElementById('waypoints-note'),
    settingsOpen: document.getElementById('settings-open'),
    settingsModal: document.getElementById('settings-modal'),
    settingsForm: document.getElementById('settings-form'),
    settingsHost: document.getElementById('settings-host'),
    settingsSerial: document.getElementById('settings-serial'),
    settingsCloudEnabled: document.getElementById('settings-cloud-enabled'),
    settingsCloudEmail: document.getElementById('settings-cloud-email'),
    settingsCloudPassword: document.getElementById('settings-cloud-password'),
    settingsDataSource: document.getElementById('settings-data-source'),
    settingsCloudStatus: document.getElementById('settings-cloud-status'),
    settingsCloudResult: document.getElementById('settings-cloud-result'),
    settingsCloudTest: document.getElementById('settings-cloud-test'),
    settingsError: document.getElementById('settings-error'),
    settingsSave: document.getElementById('settings-save'),
    settingsUpdateStatus: document.getElementById('settings-update-status'),
    settingsUpdateResult: document.getElementById('settings-update-result'),
    settingsUpdateCheck: document.getElementById('settings-update-check'),
    settingsUpdateRun: document.getElementById('settings-update-run'),
    mapDataSource: document.getElementById('map-data-source'),
    plansDataSource: document.getElementById('plans-data-source'),
    headControlsCard: document.getElementById('head-controls-card'),
    headMowerControls: document.getElementById('head-mower-controls'),
    headSnowControls: document.getElementById('head-snow-controls'),
    mowerBladeHeight: document.getElementById('mower-blade-height'),
    mowerBladeHeightLabel: document.getElementById('mower-blade-height-label'),
    mowerBladeSpeed: document.getElementById('mower-blade-speed'),
    mowerBladeSpeedLabel: document.getElementById('mower-blade-speed-label'),
    snowChuteAngle: document.getElementById('snow-chute-angle'),
    snowChuteAngleLabel: document.getElementById('snow-chute-angle-label'),
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
let currentHeadType = null;
let defaultDataSource = 'auto';
let areasLayer = null;
let loadedPlans = [];

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

function formatWifiNetwork(wifi) {
    if (!wifi?.available) return '—';
    const name = wifi.network_name || 'Unknown network';
    if (wifi.ip) return `${name} (${wifi.ip})`;
    return name;
}

function formatWifiSignal(wifi) {
    if (!wifi?.available || wifi.signal_percent == null) return '—';
    const label = wifi.signal_label ? ` (${wifi.signal_label})` : '';
    return `${wifi.signal_percent}%${label}`;
}

function formatWifiSecurity(wifi) {
    if (!wifi?.available) return '—';
    const parts = [];
    if (wifi.security) parts.push(wifi.security);
    if (wifi.saved === true) parts.push('saved');
    if (wifi.saved === false) parts.push('unsaved');
    return parts.length ? parts.join(' · ') : '—';
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
        const source = els.mapDataSource?.value || defaultDataSource;
        const res = await fetch(`/api/map.php?source=${encodeURIComponent(source)}`);
        const data = await parseJsonResponse(res);
        if (!data.ok) {
            updateMapAreasStatus(`Saved areas unavailable: ${data.error || 'request failed'}`);
            showToast(data.error || 'Failed to load saved map areas', 'error');
            return;
        }

        areasLayer.clearLayers();
        const featureCollection = data.geojson || { type: 'FeatureCollection', features: [] };
        const features = Array.isArray(featureCollection.features) ? featureCollection.features : [];

        if (features.length > 0) {
            try {
                areasLayer.addData(featureCollection);
            } catch (geoErr) {
                throw new Error(`Could not render map geometry: ${geoErr.message || 'invalid GeoJSON'}`);
            }
            const via = data.data_via ? ` via ${data.data_via}` : '';
            updateMapAreasStatus(`Saved areas loaded (${features.length} feature${features.length === 1 ? '' : 's'})${via}.`);
            showToast(data.note || 'Saved mowing areas loaded', 'success');
            if (!mapHasCentered) {
                const bounds = areasLayer.getBounds?.();
                if (bounds && bounds.isValid && bounds.isValid()) {
                    map.fitBounds(bounds.pad(0.15));
                }
            }
            return;
        }

        const warning = (data.warnings && data.warnings[0]) || data.note || null;
        if (data.status === 'empty') {
            updateMapAreasStatus('No saved map areas returned yet. Try cloud fallback in Settings, or create/save a map in the Yarbo app.');
            showToast(data.note || 'No saved map data yet', 'error');
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
    els.toast.classList.remove('hidden');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => els.toast.classList.add('hidden'), 3000);
}

async function parseJsonResponse(res) {
    const text = await res.text();
    if (!text) {
        throw new Error(`Empty response from server (${res.status})`);
    }
    try {
        return JSON.parse(text);
    } catch {
        const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 140);
        throw new Error(`Server returned non-JSON (${res.status}): ${snippet || 'no body'}`);
    }
}

function setCloudTestResult(message, type = null) {
    if (!els.settingsCloudResult) return;
    if (!message) {
        els.settingsCloudResult.textContent = '';
        els.settingsCloudResult.className = 'settings-cloud-result hidden';
        return;
    }
    els.settingsCloudResult.textContent = message;
    els.settingsCloudResult.className = `settings-cloud-result ${type || ''}`.trim();
    els.settingsCloudResult.classList.remove('hidden');
    els.settingsCloudResult.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
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
    updatePlanActivity(data);
    updateHeadControls(data);
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
    if (els.wifiNetwork) {
        els.wifiNetwork.textContent = formatWifiNetwork(data.wifi);
    }
    if (els.wifiSignal) {
        els.wifiSignal.textContent = formatWifiSignal(data.wifi);
    }
    if (els.wifiSecurity) {
        els.wifiSecurity.textContent = formatWifiSecurity(data.wifi);
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

function updatePlanActivity(data) {
    if (!els.plansStatus) return;

    const parts = [];
    if (data.plan_running) parts.push('plan running');
    if (data.planning_paused) parts.push('paused');
    if (data.returning_to_dock) parts.push('returning to dock');

    const planStatus = data.plan_status || {};
    if (planStatus.plan_name) parts.push(`"${planStatus.plan_name}"`);
    if (planStatus.plan_id != null) parts.push(`id ${planStatus.plan_id}`);
    if (planStatus.plan_percent != null) parts.push(`${planStatus.plan_percent}%`);
    if (planStatus.pause_reason) parts.push(`pause: ${planStatus.pause_reason}`);
    if (planStatus.error_message) parts.push(`error: ${planStatus.error_message}`);

    els.plansStatus.textContent = parts.length
        ? `Plan activity: ${parts.join(', ')}`
        : 'Plan activity: idle';
}

function updateHeadControls(data) {
    const headType = data.head_type != null ? Number(data.head_type) : null;
    currentHeadType = headType;

    if (!els.headControlsCard) return;

    const isMower = headType === 3 || headType === 5;
    const isSnow = headType === 1;
    const show = isMower || isSnow;

    els.headControlsCard.classList.toggle('hidden', !show);
    els.headMowerControls?.classList.toggle('hidden', !isMower);
    els.headSnowControls?.classList.toggle('hidden', !isSnow);
}

function formatCloudStatus(cloudStatus) {
    if (!cloudStatus) return 'Cloud bridge: unknown';
    const parts = [];
    if (cloudStatus.sdk_installed) parts.push('SDK installed');
    else parts.push('SDK not installed (run ./scripts/install.sh)');
    if (cloudStatus.configured) parts.push('credentials saved');
    if (cloudStatus.error) parts.push(cloudStatus.error);
    return `Cloud bridge: ${parts.join(' · ')}`;
}

async function sendHeadControl(action, value, button) {
    if (button) button.disabled = true;
    try {
        const res = await fetch('/api/head.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action,
                value,
                head_type: currentHeadType,
            }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Head command failed');
        showToast(`${action.replace(/_/g, ' ')} sent`, 'success');
    } catch (err) {
        showToast(err.message || 'Head command failed', 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

function renderPlansList(plans, note) {
    if (!els.plansList || !els.plansNote) return;

    loadedPlans = plans;
    els.plansNote.textContent = note || (plans.length ? `${plans.length} plan(s) loaded.` : 'No saved plans returned.');

    if (!plans.length) {
        els.plansList.innerHTML = '';
        return;
    }

    els.plansList.innerHTML = plans.map((plan) => {
        const areas = Array.isArray(plan.area_ids) && plan.area_ids.length
            ? `Areas: ${plan.area_ids.join(', ')}`
            : 'No area IDs';
        return `
            <article class="plan-item">
                <div>
                    <strong>${escapeHtml(plan.name)}</strong>
                    <p class="hint">ID ${escapeHtml(String(plan.id))} · ${escapeHtml(areas)}</p>
                </div>
                <div class="plan-actions">
                    <button type="button" class="btn" data-plan-start="${escapeHtml(String(plan.id))}">Start</button>
                    <button type="button" class="btn btn-danger" data-plan-delete="${escapeHtml(String(plan.id))}">Delete</button>
                </div>
            </article>
        `;
    }).join('');

    els.plansList.querySelectorAll('[data-plan-start]').forEach((button) => {
        button.addEventListener('click', () => startPlan(button.dataset.planStart, button));
    });
    els.plansList.querySelectorAll('[data-plan-delete]').forEach((button) => {
        button.addEventListener('click', () => deletePlan(button.dataset.planDelete, button));
    });
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function planStartPercent() {
    return Number(els.planStartPercent?.value ?? 0);
}

async function loadPlans(button) {
    if (button) button.disabled = true;
    if (els.plansNote) els.plansNote.textContent = 'Loading plans from robot...';

    try {
        const source = els.plansDataSource?.value || defaultDataSource;
        const res = await fetch(`/api/plans.php?source=${encodeURIComponent(source)}`);
        const data = await res.json();
        if (!data.ok) {
            throw new Error(data.error || 'Failed to load plans');
        }
        const note = data.note
            ? (data.source ? `${data.note} (${data.source})` : data.note)
            : null;
        renderPlansList(data.plans || [], note);
        if ((data.plans || []).length) {
            showToast(`Loaded ${data.plans.length} plan(s)`, 'success');
        } else if (!data.responded) {
            showToast('No response — try again while the robot is active', 'error');
        }
    } catch (err) {
        if (els.plansNote) els.plansNote.textContent = err.message || 'Could not load plans';
        showToast(err.message || 'Could not load plans', 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

async function startPlan(planId, button) {
    if (!planId) return;
    if (!confirm(`Start plan ${planId} at ${planStartPercent()}%?`)) return;

    button.disabled = true;
    try {
        const res = await fetch('/api/plans.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'start',
                plan_id: planId,
                percent: planStartPercent(),
            }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Start failed');
        showToast(`Plan ${planId} started`, 'success');
        await fetchStatus();
    } catch (err) {
        showToast(err.message || 'Start failed', 'error');
    } finally {
        button.disabled = false;
    }
}

async function deletePlan(planId, button) {
    if (!planId) return;
    if (!confirm(`Delete plan ${planId}? This cannot be undone.`)) return;

    button.disabled = true;
    try {
        const res = await fetch('/api/plans.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'delete',
                plan_id: planId,
                confirm: true,
            }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Delete failed');
        showToast(`Plan ${planId} deleted`, 'success');
        await loadPlans();
    } catch (err) {
        showToast(err.message || 'Delete failed', 'error');
    } finally {
        button.disabled = false;
    }
}

async function goToWaypointIndex(index, label, button) {
    if (!Number.isInteger(index) || index < 0 || index > 9999) {
        showToast('Waypoint index must be between 0 and 9999', 'error');
        return;
    }

    const targetLabel = label || `waypoint ${index}`;
    if (!confirm(`Send Yarbo to ${targetLabel}?`)) return;

    if (button) button.disabled = true;
    try {
        const res = await fetch('/api/waypoints.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'go', index }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Waypoint command failed');
        showToast(`Sent to ${targetLabel}`, 'success');
        await fetchStatus();
    } catch (err) {
        showToast(err.message || 'Waypoint command failed', 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

function closeWaypointMenus() {
    document.querySelectorAll('.item-menu-dropdown').forEach((menu) => {
        menu.classList.add('hidden');
    });
    document.querySelectorAll('[data-waypoint-menu][aria-expanded="true"]').forEach((button) => {
        button.setAttribute('aria-expanded', 'false');
    });
}

function closeWaypointEdits() {
    document.querySelectorAll('.waypoint-item.is-editing').forEach((item) => {
        item.classList.remove('is-editing');
        item.querySelector('.waypoint-view')?.classList.remove('hidden');
        item.querySelector('.waypoint-edit')?.classList.add('hidden');
    });
}

function bindWaypointItemEvents() {
    if (!els.waypointsList) return;

    els.waypointsList.querySelectorAll('[data-waypoint-go]').forEach((button) => {
        button.addEventListener('click', () => {
            closeWaypointMenus();
            goToWaypointIndex(
                Number(button.dataset.waypointGo),
                button.dataset.waypointLabel,
                button
            );
        });
    });

    els.waypointsList.querySelectorAll('[data-waypoint-menu]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const menu = button.parentElement?.querySelector('.item-menu-dropdown');
            const isOpen = button.getAttribute('aria-expanded') === 'true';
            closeWaypointMenus();
            if (!isOpen && menu) {
                menu.classList.remove('hidden');
                button.setAttribute('aria-expanded', 'true');
            }
        });
    });

    els.waypointsList.querySelectorAll('[data-waypoint-edit]').forEach((button) => {
        button.addEventListener('click', () => {
            const item = button.closest('.waypoint-item');
            if (!item) return;
            closeWaypointMenus();
            closeWaypointEdits();
            item.classList.add('is-editing');
            item.querySelector('.waypoint-view')?.classList.add('hidden');
            item.querySelector('.waypoint-edit')?.classList.remove('hidden');
            item.querySelector('.waypoint-edit-name')?.focus();
        });
    });

    els.waypointsList.querySelectorAll('[data-waypoint-delete]').forEach((button) => {
        button.addEventListener('click', () => {
            closeWaypointMenus();
            deleteWaypointBookmark(button.dataset.waypointDelete, button);
        });
    });

    els.waypointsList.querySelectorAll('.waypoint-edit-form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const item = form.closest('.waypoint-item');
            const id = item?.dataset.waypointId;
            const name = form.querySelector('.waypoint-edit-name')?.value.trim() ?? '';
            const index = Number(form.querySelector('.waypoint-edit-index')?.value ?? NaN);
            if (!id) return;
            updateWaypointBookmark(id, name, index, form.querySelector('button[type="submit"]'));
        });
    });

    els.waypointsList.querySelectorAll('[data-waypoint-edit-cancel]').forEach((button) => {
        button.addEventListener('click', () => {
            const item = button.closest('.waypoint-item');
            if (!item) return;
            item.classList.remove('is-editing');
            item.querySelector('.waypoint-view')?.classList.remove('hidden');
            item.querySelector('.waypoint-edit')?.classList.add('hidden');
        });
    });
}

function renderWaypointsList(waypoints, note) {
    if (!els.waypointsList || !els.waypointsNote) return;

    els.waypointsNote.textContent = note
        || (waypoints.length ? `${waypoints.length} saved waypoint(s).` : 'No saved waypoints yet.');

    if (!waypoints.length) {
        els.waypointsList.innerHTML = '';
        return;
    }

    els.waypointsList.innerHTML = waypoints.map((waypoint) => `
        <article class="waypoint-item" data-waypoint-id="${escapeHtml(waypoint.id)}">
            <div class="waypoint-view">
                <div class="waypoint-summary">
                    <strong>${escapeHtml(waypoint.name)}</strong>
                    <p class="hint">Index ${escapeHtml(String(waypoint.index))}</p>
                </div>
                <div class="waypoint-actions">
                    <button type="button" class="btn" data-waypoint-go="${escapeHtml(String(waypoint.index))}" data-waypoint-label="${escapeHtml(waypoint.name)}">Go</button>
                    <div class="item-menu">
                        <button
                            type="button"
                            class="btn-menu"
                            data-waypoint-menu
                            aria-label="Waypoint options for ${escapeHtml(waypoint.name)}"
                            aria-expanded="false"
                            aria-haspopup="menu"
                        >⋯</button>
                        <div class="item-menu-dropdown hidden" role="menu">
                            <button type="button" role="menuitem" data-waypoint-edit="${escapeHtml(waypoint.id)}">Edit</button>
                            <button type="button" role="menuitem" class="menu-danger" data-waypoint-delete="${escapeHtml(waypoint.id)}">Delete</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="waypoint-edit hidden">
                <form class="waypoint-edit-form">
                    <label class="settings-field">
                        <span class="label">Name</span>
                        <input type="text" class="waypoint-edit-name" maxlength="80" value="${escapeHtml(waypoint.name)}" required>
                    </label>
                    <label class="settings-field">
                        <span class="label">Robot index</span>
                        <input type="number" class="waypoint-edit-index" min="0" max="9999" value="${escapeHtml(String(waypoint.index))}" required>
                    </label>
                    <div class="waypoint-edit-actions">
                        <button type="submit" class="btn btn-secondary">Save</button>
                        <button type="button" class="btn btn-secondary" data-waypoint-edit-cancel>Cancel</button>
                    </div>
                </form>
            </div>
        </article>
    `).join('');

    bindWaypointItemEvents();
}

async function loadWaypoints() {
    try {
        const res = await fetch('/api/waypoints.php');
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Could not load waypoints');
        renderWaypointsList(data.waypoints || [], data.note || null);
    } catch (err) {
        if (els.waypointsNote) els.waypointsNote.textContent = err.message || 'Could not load waypoints';
    }
}

async function saveWaypointBookmark(event) {
    event.preventDefault();

    const name = els.waypointName?.value.trim() ?? '';
    const index = Number(els.waypointIndex?.value ?? NaN);
    if (!name) {
        showToast('Enter a waypoint name', 'error');
        return;
    }
    if (!Number.isInteger(index) || index < 0 || index > 9999) {
        showToast('Enter a valid robot index (0-9999)', 'error');
        return;
    }

    if (els.waypointSave) els.waypointSave.disabled = true;
    try {
        const res = await fetch('/api/waypoints.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'save', name, index }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Save failed');
        renderWaypointsList(data.waypoints || [], null);
        if (els.waypointName) els.waypointName.value = '';
        showToast(`Saved "${name}"`, 'success');
    } catch (err) {
        showToast(err.message || 'Save failed', 'error');
    } finally {
        if (els.waypointSave) els.waypointSave.disabled = false;
    }
}

async function updateWaypointBookmark(id, name, index, button) {
    if (!id) return;
    if (!name) {
        showToast('Enter a waypoint name', 'error');
        return;
    }
    if (!Number.isInteger(index) || index < 0 || index > 9999) {
        showToast('Enter a valid robot index (0-9999)', 'error');
        return;
    }

    if (button) button.disabled = true;
    try {
        const res = await fetch('/api/waypoints.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update', id, name, index }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Update failed');
        renderWaypointsList(data.waypoints || [], null);
        showToast(`Updated "${name}"`, 'success');
    } catch (err) {
        showToast(err.message || 'Update failed', 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

async function deleteWaypointBookmark(id, button) {
    if (!id) return;
    if (!confirm('Delete this saved waypoint?')) return;

    button.disabled = true;
    try {
        const res = await fetch('/api/waypoints.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Delete failed');
        renderWaypointsList(data.waypoints || [], null);
        showToast('Waypoint deleted', 'success');
    } catch (err) {
        showToast(err.message || 'Delete failed', 'error');
    } finally {
        button.disabled = false;
    }
}

function setSettingsError(message) {
    if (!els.settingsError) return;
    if (!message) {
        els.settingsError.textContent = '';
        els.settingsError.classList.add('hidden');
        return;
    }
    els.settingsError.textContent = message;
    els.settingsError.classList.remove('hidden');
}

function openSettingsModal() {
    if (!els.settingsModal) return;
    els.settingsModal.classList.remove('hidden');
    document.body.classList.add('modal-open');
    setCloudTestResult(null);
    setUpdateResult(null);
    loadSettings();
    loadUpdateStatus();
    els.settingsHost?.focus();
}

function closeSettingsModal() {
    if (!els.settingsModal) return;
    els.settingsModal.classList.add('hidden');
    document.body.classList.remove('modal-open');
    setSettingsError(null);
    setCloudTestResult(null);
    setUpdateResult(null);
}

async function loadSettings() {
    setSettingsError(null);
    try {
        const res = await fetch('/api/settings.php');
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Could not load settings');
        if (els.settingsHost) els.settingsHost.value = data.broker_host || '';
        if (els.settingsSerial) els.settingsSerial.value = data.serial || '';
        if (els.settingsCloudEnabled) els.settingsCloudEnabled.checked = Boolean(data.cloud?.cloud_enabled);
        if (els.settingsCloudEmail) els.settingsCloudEmail.value = data.cloud?.cloud_email || '';
        if (els.settingsCloudPassword) els.settingsCloudPassword.value = '';
        if (els.settingsDataSource) {
            defaultDataSource = data.cloud?.data_source || 'auto';
            els.settingsDataSource.value = defaultDataSource;
            if (els.mapDataSource) els.mapDataSource.value = defaultDataSource;
            if (els.plansDataSource) els.plansDataSource.value = defaultDataSource;
        }
        if (els.settingsCloudStatus) {
            els.settingsCloudStatus.textContent = formatCloudStatus(data.cloud_status);
        }
        if (!data.writable) {
            setSettingsError('config.php is not writable on the server.');
        }
    } catch (err) {
        setSettingsError(err.message || 'Could not load settings');
    }
}

async function saveSettings(event) {
    event.preventDefault();
    setSettingsError(null);

    const brokerHost = els.settingsHost?.value.trim() ?? '';
    const serial = els.settingsSerial?.value.trim() ?? '';
    const cloudEnabled = Boolean(els.settingsCloudEnabled?.checked);
    const cloudEmail = els.settingsCloudEmail?.value.trim() ?? '';
    const cloudPassword = els.settingsCloudPassword?.value ?? '';
    const dataSource = els.settingsDataSource?.value || 'auto';
    if (!brokerHost || !serial) {
        setSettingsError('Broker IP and serial number are required.');
        return;
    }

    if (els.settingsSave) els.settingsSave.disabled = true;
    try {
        const payload = {
            broker_host: brokerHost,
            serial,
            cloud_enabled: cloudEnabled,
            cloud_email: cloudEmail,
            data_source: dataSource,
        };
        if (cloudPassword !== '') {
            payload.cloud_password = cloudPassword;
        }
        const res = await fetch('/api/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Save failed');
        defaultDataSource = data.cloud?.data_source || dataSource;
        if (els.mapDataSource) els.mapDataSource.value = defaultDataSource;
        if (els.plansDataSource) els.plansDataSource.value = defaultDataSource;
        if (els.settingsCloudStatus) {
            els.settingsCloudStatus.textContent = formatCloudStatus(data.cloud_status);
        }
        showToast('Settings saved', 'success');
        closeSettingsModal();
        await fetchStatus();
    } catch (err) {
        setSettingsError(err.message || 'Save failed');
    } finally {
        if (els.settingsSave) els.settingsSave.disabled = false;
    }
}

async function testCloudConnection(button) {
    if (button) button.disabled = true;
    setCloudTestResult('Testing cloud connection…');
    try {
        const res = await fetch('/api/cloud.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'test',
                cloud_enabled: Boolean(els.settingsCloudEnabled?.checked),
                cloud_email: els.settingsCloudEmail?.value.trim() ?? '',
                cloud_password: els.settingsCloudPassword?.value ?? '',
                data_source: els.settingsDataSource?.value || 'auto',
            }),
        });
        const data = await parseJsonResponse(res);
        if (els.settingsCloudStatus) {
            els.settingsCloudStatus.textContent = formatCloudStatus(data.status);
        }
        const message = data.message || data.error || (data.ok ? 'Cloud bridge ready' : 'Cloud test failed');
        if (!data.ok) {
            setCloudTestResult(message, 'error');
            return;
        }
        setCloudTestResult(message, 'success');
        showToast(message, 'success');
    } catch (err) {
        const message = err.message || 'Cloud test failed';
        setCloudTestResult(message, 'error');
        showToast(message, 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

function formatUpdateStatus(data) {
    if (!data?.git_install) {
        return 'Not a git clone — reinstall with git clone to enable updates.';
    }
    if (!data.ok) {
        return data.error || 'Could not check for updates';
    }
    const version = data.changelog_version ? ` (v${data.changelog_version})` : '';
    const current = data.current_commit_short || data.current_commit || 'unknown';
    if (data.update_available) {
        const remote = data.remote_commit_short || data.remote_commit || 'latest';
        return `Update available: ${current} → ${remote}${version}`;
    }
    return `Up to date at ${current}${version}`;
}

function setUpdateResult(message, type) {
    if (!els.settingsUpdateResult) return;
    if (!message) {
        els.settingsUpdateResult.textContent = '';
        els.settingsUpdateResult.className = 'settings-cloud-result hidden';
        return;
    }
    els.settingsUpdateResult.textContent = message;
    els.settingsUpdateResult.className = `settings-cloud-result ${type || ''}`.trim();
    els.settingsUpdateResult.classList.remove('hidden');
    els.settingsUpdateResult.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function setUpdateButtonState(data) {
    if (!els.settingsUpdateRun) return;
    const canUpdate = Boolean(data?.git_install && data?.ok && data?.update_available);
    els.settingsUpdateRun.disabled = !canUpdate;
}

async function loadUpdateStatus() {
    if (!els.settingsUpdateStatus) return;
    els.settingsUpdateStatus.textContent = 'Checking for updates…';
    setUpdateResult(null);
    if (els.settingsUpdateCheck) els.settingsUpdateCheck.disabled = true;
    if (els.settingsUpdateRun) els.settingsUpdateRun.disabled = true;
    try {
        const res = await fetch('/api/update.php');
        const data = await parseJsonResponse(res);
        els.settingsUpdateStatus.textContent = formatUpdateStatus(data);
        setUpdateButtonState(data);
    } catch (err) {
        els.settingsUpdateStatus.textContent = err.message || 'Could not check for updates';
        if (els.settingsUpdateRun) els.settingsUpdateRun.disabled = true;
    } finally {
        if (els.settingsUpdateCheck) els.settingsUpdateCheck.disabled = false;
    }
}

async function checkPanelUpdates(button) {
    if (button) button.disabled = true;
    setUpdateResult('Checking for updates…');
    try {
        const res = await fetch('/api/update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'check' }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Update check failed');
        if (els.settingsUpdateStatus) {
            els.settingsUpdateStatus.textContent = formatUpdateStatus(data);
        }
        setUpdateButtonState(data);
        if (data.update_available) {
            setUpdateResult('A newer version is available. Click Update to latest.', 'success');
        } else {
            setUpdateResult('You are on the latest version.', 'success');
        }
    } catch (err) {
        setUpdateResult(err.message || 'Update check failed', 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

async function runPanelUpdate(button) {
    if (!window.confirm('Update the panel to the latest version from GitHub? The page will reload after the service restarts.')) {
        return;
    }
    if (button) button.disabled = true;
    if (els.settingsUpdateCheck) els.settingsUpdateCheck.disabled = true;
    setUpdateResult('Updating… this may take a minute.');
    try {
        const res = await fetch('/api/update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update', confirm: true }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Update failed');
        if (data.updated) {
            const steps = Array.isArray(data.steps) ? data.steps.join('\n') : '';
            const restartNote = data.restarted
                ? 'Service restarted — reloading…'
                : 'Update complete. Restart the panel if it is still running old code.';
            setUpdateResult(`${data.message || 'Updated'}\n${restartNote}${steps ? `\n${steps}` : ''}`, 'success');
            showToast(data.message || 'Panel updated', 'success');
            if (data.restarted) {
                setTimeout(() => window.location.reload(), 2500);
            } else {
                await loadUpdateStatus();
            }
        } else {
            setUpdateResult(data.message || 'Already on latest version.', 'success');
            if (els.settingsUpdateStatus) {
                els.settingsUpdateStatus.textContent = formatUpdateStatus(data);
            }
            setUpdateButtonState(data);
        }
    } catch (err) {
        setUpdateResult(err.message || 'Update failed', 'error');
        showToast(err.message || 'Update failed', 'error');
    } finally {
        if (button) button.disabled = false;
        if (els.settingsUpdateCheck) els.settingsUpdateCheck.disabled = false;
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

els.planStartPercent?.addEventListener('input', () => {
    if (els.planStartPercentLabel) {
        els.planStartPercentLabel.textContent = `${els.planStartPercent.value}%`;
    }
});

els.plansLoad?.addEventListener('click', (e) => {
    loadPlans(e.currentTarget);
});

els.waypointSaveForm?.addEventListener('submit', saveWaypointBookmark);

if (document.getElementById('waypoints-list')) {
    loadWaypoints();
    document.addEventListener('click', (event) => {
        if (!event.target.closest('.item-menu')) {
            closeWaypointMenus();
        }
    });
}

els.settingsOpen?.addEventListener('click', openSettingsModal);
els.settingsForm?.addEventListener('submit', saveSettings);
els.settingsCloudTest?.addEventListener('click', (e) => testCloudConnection(e.currentTarget));
els.settingsUpdateCheck?.addEventListener('click', (e) => checkPanelUpdates(e.currentTarget));
els.settingsUpdateRun?.addEventListener('click', (e) => runPanelUpdate(e.currentTarget));
document.querySelectorAll('[data-settings-close]').forEach((el) => {
    el.addEventListener('click', closeSettingsModal);
});

els.mowerBladeHeight?.addEventListener('input', () => {
    if (els.mowerBladeHeightLabel) els.mowerBladeHeightLabel.textContent = els.mowerBladeHeight.value;
});
els.mowerBladeSpeed?.addEventListener('input', () => {
    if (els.mowerBladeSpeedLabel) els.mowerBladeSpeedLabel.textContent = els.mowerBladeSpeed.value;
});
els.snowChuteAngle?.addEventListener('input', () => {
    if (els.snowChuteAngleLabel) els.snowChuteAngleLabel.textContent = `${els.snowChuteAngle.value}°`;
});
document.getElementById('mower-blade-height-send')?.addEventListener('click', (e) => {
    sendHeadControl('mower_blade_height', Number(els.mowerBladeHeight?.value ?? 0), e.currentTarget);
});
document.getElementById('mower-blade-speed-send')?.addEventListener('click', (e) => {
    sendHeadControl('mower_blade_speed', Number(els.mowerBladeSpeed?.value ?? 0), e.currentTarget);
});
document.getElementById('snow-chute-angle-send')?.addEventListener('click', (e) => {
    sendHeadControl('snow_chute_angle', Number(els.snowChuteAngle?.value ?? 0), e.currentTarget);
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && els.settingsModal && !els.settingsModal.classList.contains('hidden')) {
        closeSettingsModal();
    }
});

if (document.getElementById('camera-grid')) {
    loadCameras();
}
if (document.getElementById('map')) {
    initMap();
}
setupDrivePad();
loadSettings().catch(() => {});
fetchStatus();
setInterval(fetchStatus, POLL_INTERVAL_MS);
