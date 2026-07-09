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
    settingsUpdateBadge: document.getElementById('settings-update-badge'),
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
    settingsUpdateSection: document.getElementById('settings-update-section'),
    settingsUpdateCallout: document.getElementById('settings-update-callout'),
    settingsUpdateCalloutText: document.getElementById('settings-update-callout-text'),
    settingsPanelVisibility: document.getElementById('settings-panel-visibility'),
    updateConfirmModal: document.getElementById('update-confirm-modal'),
    updateConfirmTitle: document.getElementById('update-confirm-title'),
    updateConfirmSummary: document.getElementById('update-confirm-summary'),
    updateConfirmNotes: document.getElementById('update-confirm-notes'),
    updateConfirmRun: document.getElementById('update-confirm-run'),
    mapDataSource: document.getElementById('map-data-source'),
    plansDataSource: document.getElementById('plans-data-source'),
    mapInspector: document.getElementById('map-inspector'),
    mapZoneList: document.getElementById('map-zone-list'),
    mapEditToggle: document.getElementById('map-edit-toggle'),
    mapExport: document.getElementById('map-export'),
    mapExportDraft: document.getElementById('map-export-draft'),
    mapSaveRobot: document.getElementById('map-save-robot'),
    mapLoadAreas: document.getElementById('map-load-areas'),
    mapLoading: document.getElementById('map-loading'),
    mapLoadingText: document.getElementById('map-loading-text'),
    mapEditTip: document.getElementById('map-edit-tip'),
    panelSections: document.getElementById('panel-sections'),
    controlLights: document.getElementById('control-lights'),
    controlLightsIcon: document.getElementById('control-lights-icon'),
    controlLightsLabel: document.getElementById('control-lights-label'),
    controlPauseResume: document.getElementById('control-pause-resume'),
    controlPauseResumeIcon: document.getElementById('control-pause-resume-icon'),
    controlPauseResumeLabel: document.getElementById('control-pause-resume-label'),
    settingsResetLayout: document.getElementById('settings-reset-layout'),
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

const MAP_CACHE_KEY = 'yarbo_map_cache';
const MAP_VIEW_KEY = 'yarbo_map_view';
const MAP_CENTER_ZOOM = 20;
const PANEL_ORDER_KEY = 'yarbo_panel_order';
const PANEL_HIDDEN_KEY = 'yarbo_panel_hidden';
const THEME_KEY = 'yarbo_theme';
const LIGHTS_ON_KEY = 'yarbo_lights_on';
const DEFAULT_PANEL_ORDER = ['status', 'diagnostics', 'map', 'cameras', 'drive', 'plans', 'waypoints', 'head', 'controls'];
const PANEL_LABELS = {
    status: 'Status',
    diagnostics: 'Diagnostics',
    map: 'Location map',
    cameras: 'Cameras',
    drive: 'Manual drive',
    plans: 'Work plans',
    waypoints: 'Waypoints',
    head: 'Head controls',
    controls: 'Controls',
};

const ZONE_COLORS = {
    clean: { color: '#67b3ff', fill: '#67b3ff' },
    path: { color: '#9b7dff', fill: '#9b7dff' },
    forbidden: { color: '#ff6b6b', fill: '#ff6b6b' },
    no_vision: { color: '#ffb347', fill: '#ffb347' },
    sidewalk: { color: '#c9a86c', fill: '#c9a86c' },
    obstacle: { color: '#ff8c69', fill: '#ff8c69' },
    recharge: { color: '#7ddea0', fill: '#7ddea0' },
    default: { color: '#67b3ff', fill: '#67b3ff' },
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
let lastRobotFix = null;
let mapZoneLayers = [];
let loadedMapFeatures = [];
let loadedMapMeta = null;
let draftLayer = null;
let drawControl = null;
let mapEditMode = false;
let mapViewSaveTimer = null;
let mapLoadingTimer = null;
let mapLoadingStartedAt = 0;
let lightsOn = false;
let draggedPanelId = null;
let themeMediaQuery = null;
let lastUpdateStatus = null;
let updateConfirmResolver = null;

function resolveTheme(mode) {
    if (mode === 'light' || mode === 'dark') return mode;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function applyTheme(mode) {
    document.documentElement.setAttribute('data-theme', resolveTheme(mode));
}

function loadThemePreference() {
    try {
        return localStorage.getItem(THEME_KEY) || 'auto';
    } catch {
        return 'auto';
    }
}

function saveThemePreference(mode) {
    try {
        localStorage.setItem(THEME_KEY, mode);
    } catch {
        // ignore
    }
    applyTheme(mode);
}

function initTheme() {
    const mode = loadThemePreference();
    applyTheme(mode);
    document.querySelectorAll('input[name="panel_theme"]').forEach((radio) => {
        radio.checked = radio.value === mode;
        radio.addEventListener('change', () => {
            if (radio.checked) saveThemePreference(radio.value);
        });
    });
    themeMediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    if (themeMediaQuery.addEventListener) {
        themeMediaQuery.addEventListener('change', () => {
            if (loadThemePreference() === 'auto') applyTheme('auto');
        });
    }
}

function getPanelSections() {
    return Array.from(document.querySelectorAll('#panel-sections .panel-section'));
}

function loadPanelOrder() {
    try {
        const raw = localStorage.getItem(PANEL_ORDER_KEY);
        if (!raw) return null;
        const order = JSON.parse(raw);
        return Array.isArray(order) ? order : null;
    } catch {
        return null;
    }
}

function savePanelOrder() {
    const order = getPanelSections().map((section) => section.dataset.panelId).filter(Boolean);
    try {
        localStorage.setItem(PANEL_ORDER_KEY, JSON.stringify(order));
    } catch {
        // ignore
    }
}

function applyPanelOrder(order) {
    const container = els.panelSections;
    if (!container || !Array.isArray(order)) return;

    const sections = new Map(
        getPanelSections().map((section) => [section.dataset.panelId, section]),
    );

    order.forEach((id) => {
        const section = sections.get(id);
        if (section) container.appendChild(section);
    });

    getPanelSections().forEach((section) => {
        const id = section.dataset.panelId;
        if (id && !order.includes(id)) {
            container.appendChild(section);
        }
    });
}

function resetPanelOrder() {
    try {
        localStorage.removeItem(PANEL_ORDER_KEY);
        localStorage.removeItem(PANEL_HIDDEN_KEY);
    } catch {
        // ignore
    }
    const order = DEFAULT_PANEL_ORDER.filter((id) => document.querySelector(`[data-panel-id="${id}"]`));
    applyPanelOrder(order);
    savePanelOrder();
    applyPanelVisibility([]);
    syncPanelVisibilityCheckboxes([]);
}

function loadHiddenPanels() {
    try {
        const raw = localStorage.getItem(PANEL_HIDDEN_KEY);
        if (!raw) return [];
        const hidden = JSON.parse(raw);
        return Array.isArray(hidden) ? hidden.filter((id) => typeof id === 'string') : [];
    } catch {
        return [];
    }
}

function saveHiddenPanels(hidden) {
    try {
        localStorage.setItem(PANEL_HIDDEN_KEY, JSON.stringify(hidden));
    } catch {
        // ignore
    }
}

function applyPanelVisibility(hidden) {
    const hiddenSet = new Set(hidden);
    getPanelSections().forEach((section) => {
        const id = section.dataset.panelId;
        if (!id) return;
        section.classList.toggle('panel-section--user-hidden', hiddenSet.has(id));
    });
}

function syncPanelVisibilityCheckboxes(hidden) {
    const hiddenSet = new Set(hidden);
    els.settingsPanelVisibility?.querySelectorAll('input[data-panel-visible]').forEach((input) => {
        const id = input.dataset.panelVisible;
        if (!id) return;
        input.checked = !hiddenSet.has(id);
    });
}

function initPanelVisibility() {
    const hidden = loadHiddenPanels();
    applyPanelVisibility(hidden);
    syncPanelVisibilityCheckboxes(hidden);

    els.settingsPanelVisibility?.querySelectorAll('input[data-panel-visible]').forEach((input) => {
        input.addEventListener('change', () => {
            const id = input.dataset.panelVisible;
            if (!id) return;
            const nextHidden = loadHiddenPanels().filter((panelId) => panelId !== id);
            if (!input.checked) {
                nextHidden.push(id);
            }
            saveHiddenPanels(nextHidden);
            applyPanelVisibility(nextHidden);
        });
    });
}

function initPanelDragDrop() {
    const container = els.panelSections;
    if (!container) return;

    const saved = loadPanelOrder();
    if (saved) {
        applyPanelOrder(saved.filter((id) => document.querySelector(`[data-panel-id="${id}"]`)));
    }

    container.querySelectorAll('.section-drag-handle').forEach((handle) => {
        handle.addEventListener('dragstart', (event) => {
            const section = handle.closest('.panel-section');
            if (!section) return;
            draggedPanelId = section.dataset.panelId || null;
            section.classList.add('panel-section--dragging');
            event.dataTransfer?.setData('text/plain', draggedPanelId || '');
            if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
        });

        handle.addEventListener('dragend', () => {
            getPanelSections().forEach((section) => {
                section.classList.remove('panel-section--dragging', 'panel-section--drop-target');
            });
            draggedPanelId = null;
            savePanelOrder();
        });
    });

    getPanelSections().forEach((section) => {
        section.addEventListener('dragover', (event) => {
            event.preventDefault();
            if (!draggedPanelId || section.dataset.panelId === draggedPanelId) return;
            section.classList.add('panel-section--drop-target');
        });

        section.addEventListener('dragleave', () => {
            section.classList.remove('panel-section--drop-target');
        });

        section.addEventListener('drop', (event) => {
            event.preventDefault();
            section.classList.remove('panel-section--drop-target');
            const source = document.querySelector(`[data-panel-id="${draggedPanelId}"]`);
            if (!source || source === section) return;

            const rect = section.getBoundingClientRect();
            const before = event.clientY < rect.top + rect.height / 2;
            if (before) {
                container.insertBefore(source, section);
            } else {
                container.insertBefore(source, section.nextSibling);
            }
            savePanelOrder();
        });
    });
}

function applyLightsStateFromStatus(data) {
    if (typeof data?.lights_on === 'boolean') {
        lightsOn = data.lights_on;
        try {
            localStorage.setItem(LIGHTS_ON_KEY, lightsOn ? '1' : '0');
        } catch {
            // ignore
        }
    }
}

function updateLightsTile() {
    if (!els.controlLights) return;
    els.controlLights.classList.toggle('is-active', lightsOn);
    els.controlLights.setAttribute('aria-pressed', lightsOn ? 'true' : 'false');
    els.controlLights.title = lightsOn ? 'Turn lights off' : 'Turn lights on';
    if (els.controlLightsIcon) {
        els.controlLightsIcon.textContent = lightsOn ? '💡' : '🔅';
    }
    if (els.controlLightsLabel) {
        els.controlLightsLabel.textContent = lightsOn ? 'On' : 'Off';
    }
}

function updateControlTiles(data) {
    applyLightsStateFromStatus(data);
    updateLightsTile();
    const paused = Boolean(data?.planning_paused);
    if (els.controlPauseResumeIcon) {
        els.controlPauseResumeIcon.textContent = paused ? '▶' : '⏸';
    }
    if (els.controlPauseResumeLabel) {
        els.controlPauseResumeLabel.textContent = paused ? 'Resume' : 'Pause';
    }
    if (els.controlPauseResume) {
        els.controlPauseResume.title = paused ? 'Resume plan' : 'Pause plan';
    }
}

async function toggleLights(button) {
    const action = lightsOn ? 'lights_off' : 'lights_on';
    button.disabled = true;
    try {
        const res = await fetch('/api/command.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=${encodeURIComponent(action)}`,
        });
        const data = await res.json();
        if (data.ok) {
            lightsOn = action === 'lights_on';
            try {
                localStorage.setItem(LIGHTS_ON_KEY, lightsOn ? '1' : '0');
            } catch {
                // ignore
            }
            updateLightsTile();
            showToast(lightsOn ? 'Lights on' : 'Lights off', 'success');
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

function initAppearance() {
    initTheme();
    initPanelDragDrop();
    initPanelVisibility();
    try {
        lightsOn = localStorage.getItem(LIGHTS_ON_KEY) === '1';
    } catch {
        lightsOn = false;
    }
    updateLightsTile();

    els.settingsResetLayout?.addEventListener('click', () => {
        resetPanelOrder();
        showToast('Dashboard layout reset', 'success');
    });

    els.controlLights?.addEventListener('click', (event) => {
        toggleLights(event.currentTarget);
    });

    els.controlPauseResume?.addEventListener('click', (event) => {
        const paused = els.controlPauseResumeLabel?.textContent === 'Resume';
        sendCommand(paused ? 'resume' : 'pause', event.currentTarget);
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            refreshUpdateBadge();
        }
    });
}

function zoneStyle(feature) {
    const zoneType = feature?.properties?.zone_type || 'default';
    const palette = ZONE_COLORS[zoneType] || ZONE_COLORS.default;
    return {
        color: palette.color,
        weight: 2,
        fillColor: palette.fill,
        fillOpacity: 0.2,
    };
}

function countFeaturePoints(feature) {
    const geom = feature?.geometry;
    if (!geom) return 0;
    if (geom.type === 'Polygon' && Array.isArray(geom.coordinates?.[0])) {
        return geom.coordinates[0].length;
    }
    if (geom.type === 'LineString' && Array.isArray(geom.coordinates)) {
        return geom.coordinates.length;
    }
    if (geom.type === 'Point') return 1;
    return 0;
}

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
        style: zoneStyle,
        pointToLayer(_feature, latlng) {
            return L.circleMarker(latlng, {
                radius: 5,
                color: '#67b3ff',
                weight: 2,
                fillColor: '#67b3ff',
                fillOpacity: 0.75,
            });
        },
        onEachFeature(feature, layer) {
            const index = mapZoneLayers.length;
            layer._yarboZoneIndex = index;
            mapZoneLayers.push({ feature, layer, visible: true });
        },
    }).addTo(map);

    draftLayer = L.featureGroup().addTo(map);

    const centerControl = L.control({ position: 'bottomright' });
    centerControl.onAdd = function () {
        const button = L.DomUtil.create('button', 'map-center-robot');
        button.type = 'button';
        button.title = 'Center on Yarbo';
        button.setAttribute('aria-label', 'Center on Yarbo');
        button.innerHTML = '&#8857;';
        L.DomEvent.disableClickPropagation(button);
        L.DomEvent.on(button, 'click', (event) => {
            L.DomEvent.preventDefault(event);
            centerOnRobot();
        });
        return button;
    };
    centerControl.addTo(map);

    map.on('moveend zoomend', () => {
        clearTimeout(mapViewSaveTimer);
        mapViewSaveTimer = setTimeout(saveMapView, 250);
    });

    restoreMapCache();
    restoreMapView();
}

function setMapLoading(active, message = 'Loading saved map areas') {
    if (!els.mapLoading) return;

    if (active) {
        els.mapLoading.classList.remove('hidden');
        els.mapLoading.setAttribute('aria-busy', 'true');
        mapLoadingStartedAt = Date.now();
        if (els.mapLoadingText) {
            els.mapLoadingText.textContent = `${message}…`;
        }
        clearInterval(mapLoadingTimer);
        mapLoadingTimer = setInterval(() => {
            const secs = Math.floor((Date.now() - mapLoadingStartedAt) / 1000);
            if (els.mapLoadingText) {
                els.mapLoadingText.textContent = `${message}… ${secs}s`;
            }
        }, 1000);
        if (els.mapDataSource) els.mapDataSource.disabled = true;
        if (els.mapEditToggle) els.mapEditToggle.disabled = true;
        if (els.mapLoadAreas) els.mapLoadAreas.disabled = true;
        return;
    }

    els.mapLoading.classList.add('hidden');
    els.mapLoading.setAttribute('aria-busy', 'false');
    clearInterval(mapLoadingTimer);
    mapLoadingTimer = null;
    if (els.mapDataSource) els.mapDataSource.disabled = false;
    if (els.mapEditToggle) els.mapEditToggle.disabled = false;
    if (els.mapLoadAreas) els.mapLoadAreas.disabled = false;
}

function setAreasLayerVisible(visible) {
    if (!map || !areasLayer) return;
    const onMap = map.hasLayer(areasLayer);
    if (visible && !onMap) {
        areasLayer.addTo(map);
    } else if (!visible && onMap) {
        map.removeLayer(areasLayer);
    }
}

function enableDraftVertexEditing() {
    draftLayer?.eachLayer((layer) => {
        if (layer.editing) {
            layer.editing.enable();
            return;
        }
        if (typeof L.Edit?.Poly === 'function' && typeof layer.getLatLngs === 'function') {
            layer._yarboEditHandler = new L.Edit.Poly(layer);
            layer._yarboEditHandler.enable();
        }
    });
}

function disableDraftVertexEditing() {
    draftLayer?.eachLayer((layer) => {
        if (layer.editing) {
            layer.editing.disable();
        }
        if (layer._yarboEditHandler) {
            layer._yarboEditHandler.disable();
            delete layer._yarboEditHandler;
        }
    });
}

function clearDraftHighlights() {
    draftLayer?.eachLayer((layer) => {
        if (layer.feature) {
            layer.setStyle(zoneStyle(layer.feature));
        }
    });
}

function applyDraftToView() {
    const geojson = draftLayerToGeoJson();
    if (!geojson.features.length) return;
    applyLoadedMapFeatures(geojson.features, {
        skipFit: true,
        meta: loadedMapMeta || {},
    });
    saveMapCache(
        {
            data_via: loadedMapMeta?.data_via ?? null,
            gps_ref: loadedMapMeta?.gps_ref ?? null,
        },
        geojson.features,
    );
}

function focusZoneForEditing(index) {
    if (!loadedMapFeatures.length) {
        showToast('Load map zones first', 'error');
        return;
    }

    if (!mapEditMode) {
        setMapEditMode(true);
    } else if (draftLayer.getLayers().length === 0) {
        copyFeaturesToDraft();
    }

    let target = null;
    draftLayer.eachLayer((layer) => {
        const feature = layer.feature || {};
        const style = zoneStyle(feature);
        if (layer._yarboZoneIndex === index) {
            layer.setStyle({ ...style, weight: 4, fillOpacity: 0.35 });
            target = layer;
        } else {
            layer.setStyle(style);
        }
    });

    enableDraftVertexEditing();

    if (target) {
        const bounds = target.getBounds?.();
        if (bounds?.isValid?.()) {
            map.fitBounds(bounds.pad(0.2));
        }
    }
}

function centerOnRobot() {
    if (!map) return;
    if (!lastRobotFix?.gps_valid) {
        showToast('No GPS fix yet — move outdoors and wait for RTK/GNSS lock', 'error');
        return;
    }
    map.setView([lastRobotFix.lat, lastRobotFix.lon], MAP_CENTER_ZOOM);
}

function saveMapView() {
    if (!map) return;
    const center = map.getCenter();
    try {
        localStorage.setItem(MAP_VIEW_KEY, JSON.stringify({
            center: [center.lat, center.lng],
            zoom: map.getZoom(),
            layer: currentMapLayer,
        }));
    } catch {
        // ignore quota errors
    }
}

function restoreMapView() {
    if (!map) return;
    try {
        const raw = localStorage.getItem(MAP_VIEW_KEY);
        if (!raw) return;
        const view = JSON.parse(raw);
        if (!Array.isArray(view.center) || view.center.length < 2) return;
        const zoom = Number(view.zoom);
        map.setView([Number(view.center[0]), Number(view.center[1])], Number.isFinite(zoom) ? zoom : 18);
        if (view.layer === 'satellite' || view.layer === 'street') {
            setMapLayer(view.layer);
            const radio = document.querySelector(`input[name="map-layer"][value="${view.layer}"]`);
            if (radio) radio.checked = true;
        }
    } catch {
        localStorage.removeItem(MAP_VIEW_KEY);
    }
}

function saveMapCache(apiData, features) {
    if (!features.length) return;
    try {
        localStorage.setItem(MAP_CACHE_KEY, JSON.stringify({
            geojson: { type: 'FeatureCollection', features },
            source: els.mapDataSource?.value || defaultDataSource,
            loaded_at: new Date().toISOString(),
            meta: {
                feature_count: features.length,
                data_via: apiData.data_via || null,
                gps_ref: apiData.gps_ref || null,
            },
        }));
    } catch {
        // ignore quota errors
    }
}

function restoreMapCache() {
    try {
        const raw = localStorage.getItem(MAP_CACHE_KEY);
        if (!raw) return;
        const cache = JSON.parse(raw);
        const features = cache?.geojson?.features;
        if (!Array.isArray(features) || features.length === 0) {
            localStorage.removeItem(MAP_CACHE_KEY);
            return;
        }
        applyLoadedMapFeatures(features, {
            restored: true,
            source: cache.source,
            loaded_at: cache.loaded_at,
            meta: cache.meta || {},
        });
    } catch {
        localStorage.removeItem(MAP_CACHE_KEY);
    }
}

function clearMapZones() {
    mapZoneLayers = [];
    loadedMapFeatures = [];
    loadedMapMeta = null;
    areasLayer?.clearLayers();
    if (els.mapInspector) els.mapInspector.classList.add('hidden');
    if (els.mapZoneList) els.mapZoneList.innerHTML = '';
}

function applyLoadedMapFeatures(features, context = {}) {
    if (!map || !areasLayer) return;
    clearMapZones();
    const featureCollection = { type: 'FeatureCollection', features };
    areasLayer.addData(featureCollection);
    loadedMapFeatures = features;
    loadedMapMeta = context.meta || null;

    if (context.restored) {
        const via = context.meta?.data_via ? ` via ${context.meta.data_via}` : '';
        const when = context.loaded_at ? ` from ${new Date(context.loaded_at).toLocaleString()}` : '';
        updateMapAreasStatus(`Restored from last session (${features.length} feature${features.length === 1 ? '' : 's'})${via}${when}.`);
    }

    renderMapInspector();
    if (!mapHasCentered && !context.skipFit) {
        const bounds = areasLayer.getBounds?.();
        if (bounds?.isValid?.()) {
            map.fitBounds(bounds.pad(0.15));
            mapHasCentered = true;
        }
    }
}

function renderMapInspector() {
    if (!els.mapInspector || !els.mapZoneList) return;
    if (mapZoneLayers.length === 0) {
        els.mapInspector.classList.add('hidden');
        els.mapZoneList.innerHTML = '';
        return;
    }

    els.mapInspector.classList.remove('hidden');
    els.mapZoneList.innerHTML = mapZoneLayers.map((entry, index) => {
        const props = entry.feature?.properties || {};
        const zoneType = props.zone_type || 'zone';
        const name = props.name || props.zone_id || `Zone ${index + 1}`;
        const points = countFeaturePoints(entry.feature);
        const palette = ZONE_COLORS[zoneType] || ZONE_COLORS.default;
        return `<li class="map-zone-item">
            <input type="checkbox" id="map-zone-vis-${index}" data-zone-index="${index}" ${entry.visible ? 'checked' : ''}>
            <span class="map-zone-swatch" style="background:${palette.color}"></span>
            <label class="map-zone-meta" for="map-zone-vis-${index}">${name} · ${zoneType} · ${points} pts</label>
            <button type="button" class="btn btn-secondary map-zone-edit" data-zone-edit="${index}">Edit</button>
        </li>`;
    }).join('');

    els.mapZoneList.querySelectorAll('input[type="checkbox"]').forEach((input) => {
        input.addEventListener('change', () => {
            const index = Number(input.dataset.zoneIndex);
            setMapZoneVisible(index, input.checked);
        });
    });

    els.mapZoneList.querySelectorAll('[data-zone-edit]').forEach((button) => {
        button.addEventListener('click', () => {
            focusZoneForEditing(Number(button.dataset.zoneEdit));
        });
    });
}

function setMapZoneVisible(index, visible) {
    const entry = mapZoneLayers[index];
    if (!entry || !map) return;
    entry.visible = visible;
    if (visible) {
        if (!areasLayer.hasLayer(entry.layer)) {
            areasLayer.addLayer(entry.layer);
        }
    } else if (areasLayer.hasLayer(entry.layer)) {
        areasLayer.removeLayer(entry.layer);
    }
}

function downloadGeoJson(geojson, filename) {
    const blob = new Blob([JSON.stringify(geojson, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
}

function exportLoadedMapGeoJson() {
    if (!loadedMapFeatures.length) {
        showToast('No map zones loaded to export', 'error');
        return;
    }
    const stamp = new Date().toISOString().slice(0, 10).replace(/-/g, '');
    downloadGeoJson({ type: 'FeatureCollection', features: loadedMapFeatures }, `yarbo-map-${stamp}.geojson`);
    showToast('Map GeoJSON exported', 'success');
}

function draftLayerToGeoJson() {
    const features = [];
    draftLayer?.eachLayer((layer) => {
        if (typeof layer.toGeoJSON === 'function') {
            features.push(layer.toGeoJSON());
        }
    });
    return { type: 'FeatureCollection', features };
}

function exportDraftGeoJson() {
    const geojson = draftLayerToGeoJson();
    if (!geojson.features.length) {
        showToast('Draft layer is empty', 'error');
        return;
    }
    const stamp = new Date().toISOString().slice(0, 10).replace(/-/g, '');
    downloadGeoJson(geojson, `yarbo-map-draft-${stamp}.geojson`);
    showToast('Draft GeoJSON exported', 'success');
}

function copyFeaturesToDraft() {
    if (!draftLayer || !loadedMapFeatures.length) return;
    disableDraftVertexEditing();
    draftLayer.clearLayers();
    let zoneIndex = 0;
    L.geoJSON({ type: 'FeatureCollection', features: loadedMapFeatures }, {
        style: zoneStyle,
        onEachFeature(feature, layer) {
            layer.feature = feature;
            layer._yarboZoneIndex = zoneIndex;
            zoneIndex += 1;
        },
    }).eachLayer((layer) => {
        draftLayer.addLayer(layer);
    });
}

function updateMapEditToggleStyle(editing) {
    const btn = els.mapEditToggle;
    if (!btn) return;
    btn.textContent = editing ? 'Stop editing (draft)' : 'Edit map (draft)';
    btn.classList.add('btn');
    btn.classList.toggle('btn-secondary', !editing);
}

function setMapEditMode(enabled) {
    if (!map || typeof L.Control?.Draw === 'undefined') {
        if (enabled) {
            showToast('Map editor requires Leaflet.draw to load', 'error');
        }
        return;
    }

    if (!enabled && mapEditMode) {
        disableDraftVertexEditing();
        clearDraftHighlights();
        setAreasLayerVisible(true);
        applyDraftToView();
        if (drawControl) {
            map.removeControl(drawControl);
        }
        if (els.mapEditTip) els.mapEditTip.classList.add('hidden');
    }

    mapEditMode = enabled;
    updateMapEditToggleStyle(enabled);
    if (els.mapExportDraft) els.mapExportDraft.disabled = !enabled;
    if (els.mapEditTip) {
        els.mapEditTip.classList.toggle('hidden', !enabled);
    }

    if (!enabled) {
        return;
    }

    if (!loadedMapFeatures.length) {
        showToast('Load map zones before editing', 'error');
        mapEditMode = false;
        updateMapEditToggleStyle(false);
        if (els.mapExportDraft) els.mapExportDraft.disabled = true;
        if (els.mapEditTip) els.mapEditTip.classList.add('hidden');
        return;
    }

    if (draftLayer.getLayers().length === 0) {
        copyFeaturesToDraft();
    }

    setAreasLayerVisible(false);

    if (!drawControl) {
        drawControl = new L.Control.Draw({
            position: 'topright',
            edit: {
                featureGroup: draftLayer,
                remove: true,
            },
            draw: {
                polygon: { allowIntersection: false, showArea: false },
                polyline: false,
                rectangle: false,
                circle: false,
                marker: false,
                circlemarker: false,
            },
        });
        map.on(L.Draw.Event.CREATED, (event) => {
            const layer = event.layer;
            layer._yarboZoneIndex = draftLayer.getLayers().length;
            layer.feature = layer.feature || {
                type: 'Feature',
                properties: { zone_type: 'clean', name: 'New zone' },
                geometry: layer.toGeoJSON().geometry,
            };
            draftLayer.addLayer(layer);
            enableDraftVertexEditing();
        });
        map.on(L.Draw.Event.EDITED, () => {
            enableDraftVertexEditing();
        });
        map.on(L.Draw.Event.DELETED, () => {
            enableDraftVertexEditing();
        });
    }

    map.addControl(drawControl);
    enableDraftVertexEditing();
}

function saveMapDraft() {
    showToast('Map write MQTT commands not yet verified — export draft or use Yarbo app', 'error');
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

    lastRobotFix = { lat, lon, gps_valid: hasFix };

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
    setMapLoading(true);
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

        const featureCollection = data.geojson || { type: 'FeatureCollection', features: [] };
        const features = Array.isArray(featureCollection.features) ? featureCollection.features : [];

        if (features.length > 0) {
            try {
                applyLoadedMapFeatures(features, {
                    meta: {
                        feature_count: features.length,
                        data_via: data.data_via || null,
                        gps_ref: data.gps_ref || null,
                    },
                });
            } catch (geoErr) {
                throw new Error(`Could not render map geometry: ${geoErr.message || 'invalid GeoJSON'}`);
            }
            saveMapCache(data, features);
            const via = data.data_via ? ` via ${data.data_via}` : '';
            updateMapAreasStatus(`Saved areas loaded (${features.length} feature${features.length === 1 ? '' : 's'})${via}.`);
            showToast(data.note || 'Saved mowing areas loaded', 'success');
            if (mapEditMode) {
                copyFeaturesToDraft();
                setAreasLayerVisible(false);
                enableDraftVertexEditing();
            }
            return;
        }

        const warning = (data.warnings && data.warnings[0]) || data.note || null;
        const probeHint = data.probes
            ? ` Probes: ${Object.entries(data.probes).map(([k, v]) => `${k}=${v.has_data ? 'data' : 'empty'}`).join(', ')}.`
            : '';
        if (data.status === 'empty') {
            const emptyMsg = probeHint.includes('get_map=data')
                ? 'Map data arrived but could not be drawn yet.'
                : 'No saved map areas returned yet.';
            updateMapAreasStatus(`${emptyMsg}${probeHint} Try cloud fallback in Settings, or create/save a map in the Yarbo app.`);
            showToast(data.note || (probeHint.includes('get_map=data') ? 'Could not draw map areas' : 'No saved map data yet'), 'error');
        } else if (data.status === 'structured_no_geometry') {
            updateMapAreasStatus(`Map data returned but no drawable geometry detected yet.${probeHint}`);
            showToast(warning || 'Map data found but not drawable yet', 'error');
        } else {
            updateMapAreasStatus((warning || 'Saved areas not available on this mower/firmware.') + probeHint);
            showToast(warning || 'Saved area extraction not supported yet', 'error');
        }
    } catch (err) {
        updateMapAreasStatus(`Saved areas request failed: ${err.message || 'network error'}`);
        showToast(err.message || 'Network error', 'error');
    } finally {
        setMapLoading(false);
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

function formatConnectionError(message) {
    if (!message) return message;
    const lower = message.toLowerCase();
    if (lower.includes('connection refused') || message.includes('[111]')) {
        return 'Cannot reach the Yarbo robot at the configured IP address (MQTT port 1883 refused the connection). Open Settings and check the broker IP matches your Yarbo base station, the robot is powered on, and this device is on the same home network.';
    }
    if (lower.includes('no route to host') || message.includes('[113]')) {
        return 'Cannot find the Yarbo robot on the network at the configured IP address. Verify the broker IP in Settings and that you are on the same Wi‑Fi or LAN.';
    }
    if (lower.includes('network is unreachable') || message.includes('[101]')) {
        return 'The network route to the Yarbo robot is unreachable. Check your Wi‑Fi connection and the broker IP in Settings.';
    }
    if (lower.includes('timed out') || lower.includes('timeout')) {
        return 'Connection to the Yarbo robot timed out. Check the broker IP and serial number in Settings, and make sure the robot is powered on and on your home network.';
    }
    if (lower.includes('establishing a connection to the mqtt broker failed')) {
        return 'Cannot connect to the Yarbo MQTT broker. Check the broker IP and port (1883) in Settings, and confirm the robot is powered on.';
    }
    return message;
}

function setError(message) {
    if (message) {
        els.errorBanner.textContent = formatConnectionError(message);
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
    updateControlTiles(data);
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

function setUpdateBadge(visible) {
    if (els.settingsUpdateBadge) {
        els.settingsUpdateBadge.classList.toggle('hidden', !visible);
    }
    if (els.settingsOpen) {
        els.settingsOpen.setAttribute('aria-label', visible ? 'Settings (update available)' : 'Settings');
    }
}

function setUpdateAvailableUi(data) {
    const available = Boolean(data?.git_install && data?.ok && data?.update_available);
    const version = data?.pending_version || data?.changelog_version;
    const versionLabel = version ? `v${version}` : 'a new version';

    if (els.settingsUpdateCallout) {
        els.settingsUpdateCallout.classList.toggle('hidden', !available);
    }
    if (els.settingsUpdateCalloutText) {
        els.settingsUpdateCalloutText.textContent = available
            ? ` — ${versionLabel} is ready to install.`
            : '';
    }
    if (els.settingsUpdateSection) {
        els.settingsUpdateSection.classList.toggle('settings-section--update-available', available);
    }
    if (els.settingsUpdateRun) {
        els.settingsUpdateRun.classList.toggle('btn-update-ready', available);
    }
}

function renderReleaseNotesHtml(releaseNotes) {
    if (!Array.isArray(releaseNotes) || releaseNotes.length === 0) {
        return '<p class="hint">Release notes are not available for this update.</p>';
    }

    return releaseNotes.map((release) => {
        const heading = release.date
            ? `Version ${release.version} (${release.date})`
            : `Version ${release.version}`;
        const sections = release.sections || {};
        const sectionHtml = ['Added', 'Changed', 'Fixed', 'Deprecated', 'Removed', 'Security']
            .filter((name) => Array.isArray(sections[name]) && sections[name].length > 0)
            .map((name) => {
                const items = sections[name].map((item) => `<li>${escapeHtml(item)}</li>`).join('');
                return `<div class="update-release-section"><h4>${name}</h4><ul>${items}</ul></div>`;
            })
            .join('');

        return `<article class="update-release-block"><h3>${escapeHtml(heading)}</h3>${sectionHtml || '<p class="hint">No detailed notes for this version.</p>'}</article>`;
    }).join('');
}

function closeUpdateConfirmModal(confirmed = false) {
    if (!els.updateConfirmModal) return;
    els.updateConfirmModal.classList.add('hidden');
    document.body.classList.remove('modal-open');
    if (updateConfirmResolver) {
        updateConfirmResolver(confirmed);
        updateConfirmResolver = null;
    }
}

function showUpdateConfirmModal(data) {
    return new Promise((resolve) => {
        if (!els.updateConfirmModal) {
            resolve(window.confirm('Update the panel to the latest version from GitHub? The page will reload after the service restarts.'));
            return;
        }

        updateConfirmResolver = resolve;
        const version = data?.pending_version || data?.changelog_version;
        const from = data?.current_commit_short || 'current';
        const to = data?.remote_commit_short || 'latest';
        const versionLabel = version ? `v${version}` : 'latest version';

        if (els.updateConfirmTitle) {
            els.updateConfirmTitle.textContent = `Install ${versionLabel}?`;
        }
        if (els.updateConfirmSummary) {
            els.updateConfirmSummary.textContent = `This will update the panel from ${from} to ${to}.`;
        }
        if (els.updateConfirmNotes) {
            els.updateConfirmNotes.innerHTML = renderReleaseNotesHtml(data?.release_notes);
        }

        els.updateConfirmModal.classList.remove('hidden');
        document.body.classList.add('modal-open');
        els.updateConfirmRun?.focus();
    });
}

function initUpdateConfirmModal() {
    els.updateConfirmRun?.addEventListener('click', () => closeUpdateConfirmModal(true));
    document.querySelectorAll('[data-update-confirm-close]').forEach((button) => {
        button.addEventListener('click', () => closeUpdateConfirmModal(false));
    });
}

async function refreshUpdateBadge() {
    try {
        const res = await fetch('/api/update.php', { cache: 'no-store' });
        const data = await parseJsonResponse(res);
        const available = Boolean(data?.git_install && data?.ok && data?.update_available);
        lastUpdateStatus = data;
        setUpdateBadge(available);
        setUpdateAvailableUi(data);
        return data;
    } catch {
        setUpdateBadge(false);
        setUpdateAvailableUi(null);
        return null;
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
        const pending = data.pending_version ? ` → v${data.pending_version}` : '';
        return `Update available: ${current} → ${remote}${pending || version}`;
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
    setUpdateAvailableUi(data);
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
        lastUpdateStatus = data;
        els.settingsUpdateStatus.textContent = formatUpdateStatus(data);
        setUpdateButtonState(data);
        setUpdateBadge(Boolean(data?.git_install && data?.ok && data?.update_available));
        if (data?.git_install && data?.ok && data?.update_available) {
            els.settingsUpdateSection?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    } catch (err) {
        els.settingsUpdateStatus.textContent = err.message || 'Could not check for updates';
        if (els.settingsUpdateRun) els.settingsUpdateRun.disabled = true;
        setUpdateBadge(false);
        setUpdateAvailableUi(null);
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
        lastUpdateStatus = data;
        if (!data.ok) throw new Error(data.error || 'Update check failed');
        if (els.settingsUpdateStatus) {
            els.settingsUpdateStatus.textContent = formatUpdateStatus(data);
        }
        setUpdateButtonState(data);
        if (data.update_available) {
            setUpdateResult('A newer version is available. Click Update to latest.', 'success');
            setUpdateBadge(true);
        } else {
            setUpdateResult('You are on the latest version.', 'success');
            setUpdateBadge(false);
        }
    } catch (err) {
        setUpdateResult(err.message || 'Update check failed', 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

function isUpdateNetworkError(err) {
    const message = String(err?.message || '');
    return message === 'Load failed'
        || message === 'Failed to fetch'
        || message.includes('NetworkError')
        || message.includes('network error');
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function fetchWithTimeout(url, options = {}, timeoutMs = 8000) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
        return await fetch(url, { ...options, signal: controller.signal });
    } finally {
        clearTimeout(timer);
    }
}

function isAbortError(err) {
    return err?.name === 'AbortError' || String(err?.message || '').includes('aborted');
}

async function fetchUpdateProgress() {
    try {
        const res = await fetchWithTimeout('/api/update.php?action=progress', { cache: 'no-store' }, 8000);
        return await parseJsonResponse(res);
    } catch {
        return null;
    }
}

async function fetchUpdateCheck() {
    try {
        const res = await fetchWithTimeout('/api/update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'check' }),
            cache: 'no-store',
        }, 20000);
        return await parseJsonResponse(res);
    } catch {
        return null;
    }
}

async function waitForPanelRestart(maxWaitMs = 120000, targetCommitShort = null) {
    const deadline = Date.now() + maxWaitMs;
    let sawDisconnect = false;
    let lastProgressState = null;
    let polls = 0;
    let lastCommitCheck = 0;

    while (Date.now() < deadline) {
        await sleep(polls === 0 ? 1000 : 2500);
        polls += 1;

        let progress = null;
        try {
            progress = await fetchUpdateProgress();
            if (progress?.state) {
                lastProgressState = progress.state;
            }
            if (progress?.message && els.settingsUpdateResult) {
                const suffix = targetCommitShort ? ` → ${targetCommitShort}` : '';
                setUpdateResult(`${progress.message}${suffix}. Waiting for panel to restart…`, 'success');
            }
            if (progress?.state === 'failed') {
                throw new Error(progress.error || progress.message || 'Update failed');
            }
            if (progress?.state === 'done') {
                window.location.reload();
                return;
            }
        } catch (err) {
            if (!isUpdateNetworkError(err) && !isAbortError(err)) {
                throw err;
            }
            sawDisconnect = true;
        }

        if (targetCommitShort && Date.now() - lastCommitCheck >= 10000) {
            lastCommitCheck = Date.now();
            const check = await fetchUpdateCheck();
            if (check?.ok && check.current_commit_short === targetCommitShort && !check.update_available) {
                window.location.reload();
                return;
            }
        }

        try {
            const res = await fetchWithTimeout('/api/status.php', { cache: 'no-store' }, 8000);
            if (!res.ok) {
                sawDisconnect = true;
                continue;
            }
            const data = await res.json();
            if (!data.ok) {
                sawDisconnect = true;
                continue;
            }

            const restartPhase = progress?.state === 'restarting'
                || lastProgressState === 'restarting'
                || progress?.state === 'done'
                || lastProgressState === 'done';

            if (sawDisconnect || restartPhase) {
                window.location.reload();
                return;
            }
        } catch (err) {
            if (isUpdateNetworkError(err) || isAbortError(err)) {
                sawDisconnect = true;
                continue;
            }
            throw err;
        }
    }

    throw new Error('Panel did not come back in time. Check: sudo systemctl status yarbo-panel and ~/yarbo/data/update.log');
}

async function runPanelUpdate(button) {
    const statusData = lastUpdateStatus || await refreshUpdateBadge();
    const confirmed = await showUpdateConfirmModal(statusData || {});
    if (!confirmed) {
        return;
    }
    if (button) button.disabled = true;
    if (els.settingsUpdateCheck) els.settingsUpdateCheck.disabled = true;
    if (els.settingsUpdateRun) els.settingsUpdateRun.disabled = true;
    setUpdateResult('Starting update…');
    try {
        const res = await fetch('/api/update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update', confirm: true }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Update failed');

        if (data.started) {
            const from = data.current_commit_short || 'current';
            const to = data.remote_commit_short || 'latest';
            setUpdateResult(`Update started (${from} → ${to}). Waiting for panel to restart…`, 'success');
            showToast('Update started', 'success');
            await waitForPanelRestart(120000, data.remote_commit_short || null);
            return;
        }

        if (data.updated) {
            const steps = Array.isArray(data.steps) ? data.steps.join('\n') : '';
            setUpdateResult(`${data.message || 'Updated'}${steps ? `\n${steps}` : ''}`, 'success');
            showToast(data.message || 'Panel updated', 'success');
            await loadUpdateStatus();
            return;
        }

        setUpdateResult(data.message || 'Already on latest version.', 'success');
        if (els.settingsUpdateStatus) {
            els.settingsUpdateStatus.textContent = formatUpdateStatus(data);
        }
        setUpdateButtonState(data);
        setUpdateBadge(false);
    } catch (err) {
        if (isUpdateNetworkError(err)) {
            setUpdateResult('Update may be in progress — waiting for panel to restart…', 'success');
            try {
                await waitForPanelRestart(120000, null);
                return;
            } catch (waitErr) {
                setUpdateResult(waitErr.message || 'Update status unknown', 'error');
                showToast(waitErr.message || 'Update status unknown', 'error');
                return;
            }
        }
        setUpdateResult(err.message || 'Update failed', 'error');
        showToast(err.message || 'Update failed', 'error');
    } finally {
        if (els.settingsUpdateCheck) els.settingsUpdateCheck.disabled = false;
        if (els.settingsUpdateRun) els.settingsUpdateRun.disabled = false;
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
        if (action === 'buzzer') {
            button.classList.add('is-pulse');
            setTimeout(() => button.classList.remove('is-pulse'), 350);
        }
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

document.getElementById('map-edit-toggle')?.addEventListener('click', () => {
    setMapEditMode(!mapEditMode);
});

document.getElementById('map-export')?.addEventListener('click', exportLoadedMapGeoJson);
document.getElementById('map-export-draft')?.addEventListener('click', exportDraftGeoJson);
document.getElementById('map-save-robot')?.addEventListener('click', saveMapDraft);

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
    if (event.key !== 'Escape') return;
    if (els.updateConfirmModal && !els.updateConfirmModal.classList.contains('hidden')) {
        closeUpdateConfirmModal(false);
        return;
    }
    if (els.settingsModal && !els.settingsModal.classList.contains('hidden')) {
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
initAppearance();
initUpdateConfirmModal();
loadSettings().catch(() => {});
refreshUpdateBadge();
fetchStatus();
setInterval(fetchStatus, POLL_INTERVAL_MS);
