const POLL_INTERVAL_MS = 5000;
const SNAPSHOT_INTERVAL_MS = 2000;
const DRIVE_REPEAT_MS = 120;
const LINEAR_SPEED = 0.35;
const ANGULAR_SPEED = 0.55;
const COMMAND_QUIET_MS = 4000;

function isCommandAckError(msg) {
    if (!msg || typeof msg !== 'string') return false;
    return /fail|error|denied|reject|busy|invalid|unable|not allowed/i.test(msg);
}

const els = {
    battery: document.getElementById('battery'),
    robotName: document.getElementById('device-name'),
    vestaboardLiveSwitch: document.getElementById('vestaboard-live-switch'),
    state: document.getElementById('state'),
    charging: document.getElementById('charging'),
    heading: document.getElementById('heading'),
    headType: document.getElementById('head-type'),
    errorCode: document.getElementById('error-code'),
    rain: document.getElementById('rain'),
    rainSensor: document.getElementById('rain-sensor'),
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
    batteryTempStat: document.getElementById('battery-temp-stat'),
    batteryCellsModal: document.getElementById('battery-cells-modal'),
    batteryCellsList: document.getElementById('battery-cells-list'),
    batteryCellsSummary: document.getElementById('battery-cells-summary'),
    wirelessCharge: document.getElementById('wireless-charge'),
    rtkStatus: document.getElementById('rtk-status'),
    rtcmAge: document.getElementById('rtcm-age'),
    routePriority: document.getElementById('route-priority'),
    netModuleStatus: document.getElementById('net-module-status'),
    planStartPercent: document.getElementById('plan-start-percent'),
    planStartPercentLabel: document.getElementById('plan-start-percent-label'),
    plansLoad: document.getElementById('plans-load'),
    plansStatus: document.getElementById('plans-status'),
    plansActivityBadge: document.getElementById('plans-activity-badge'),
    plansActivityTitle: document.getElementById('plans-activity-title'),
    plansActivityProgress: document.getElementById('plans-activity-progress'),
    plansActivityPct: document.getElementById('plans-activity-pct'),
    plansActivityBarWrap: document.getElementById('plans-activity-bar-wrap'),
    plansActivityBar: document.getElementById('plans-activity-bar'),
    plansActivityDetail: document.getElementById('plans-activity-detail'),
    plansNote: document.getElementById('plans-note'),
    plansList: document.getElementById('plans-list'),
    plansManage: document.getElementById('plans-manage'),
    plansManageModal: document.getElementById('plans-manage-modal'),
    plansManageList: document.getElementById('plans-manage-list'),
    waypointIndex: document.getElementById('waypoint-index'),
    waypointName: document.getElementById('waypoint-name'),
    waypointSaveForm: document.getElementById('waypoint-save-form'),
    waypointSave: document.getElementById('waypoint-save'),
    waypointsList: document.getElementById('waypoints-list'),
    waypointsNote: document.getElementById('waypoints-note'),
    settingsOpen: document.getElementById('settings-open'),
    settingsUpdateBadge: document.getElementById('settings-update-badge'),
    settingsModal: document.getElementById('settings-page'),
    settingsForm: document.getElementById('settings-form'),
    settingsHost: document.getElementById('settings-host'),
    settingsSerial: document.getElementById('settings-serial'),
    settingsRobotName: document.getElementById('settings-robot-name'),
    settingsHouseName: document.getElementById('settings-house-name'),
    settingsConnectionResult: document.getElementById('settings-connection-result'),
    settingsConnectionTest: document.getElementById('settings-connection-test'),
    settingsCloudEnabled: document.getElementById('settings-cloud-enabled'),
    settingsCloudEmail: document.getElementById('settings-cloud-email'),
    settingsCloudPassword: document.getElementById('settings-cloud-password'),
    settingsDataSource: document.getElementById('settings-data-source'),
    settingsCloudStatus: document.getElementById('settings-cloud-status'),
    settingsCloudResult: document.getElementById('settings-cloud-result'),
    settingsCloudTest: document.getElementById('settings-cloud-test'),
    settingsVestaboardEnabled: document.getElementById('settings-vestaboard-enabled'),
    settingsVestaboardFields: document.getElementById('settings-vestaboard-fields'),
    settingsVestaboardLocalFields: document.getElementById('settings-vestaboard-local-fields'),
    settingsVestaboardCloudFields: document.getElementById('settings-vestaboard-cloud-fields'),
    settingsVestaboardHost: document.getElementById('settings-vestaboard-host'),
    settingsVestaboardKey: document.getElementById('settings-vestaboard-key'),
    settingsVestaboardCloudToken: document.getElementById('settings-vestaboard-cloud-token'),
    settingsVestaboardSample: document.getElementById('settings-vestaboard-sample'),
    settingsVestaboardPreview: document.getElementById('settings-vestaboard-preview'),
    settingsVestaboardPreviewCaption: document.getElementById('settings-vestaboard-preview-caption'),
    settingsVestaboardResult: document.getElementById('settings-vestaboard-result'),
    settingsVestaboardTest: document.getElementById('settings-vestaboard-test'),
    settingsVestaboardSend: document.getElementById('settings-vestaboard-send'),
    settingsVestaboardQuiet: document.getElementById('settings-vestaboard-quiet'),
    settingsVestaboardQuietFields: document.getElementById('settings-vestaboard-quiet-fields'),
    settingsVestaboardQuietStart: document.getElementById('settings-vestaboard-quiet-start'),
    settingsVestaboardQuietEnd: document.getElementById('settings-vestaboard-quiet-end'),
    settingsVestaboardQuietBoard: document.getElementById('settings-vestaboard-quiet-board'),
    settingsVestaboardQuietPalette: document.getElementById('settings-vestaboard-quiet-palette'),
    settingsRainSensitivity: document.getElementById('settings-rain-sensitivity'),
    vestaboardCard: document.getElementById('vestaboard-card'),
    vestaboardBoard: document.getElementById('vestaboard-board'),
    vestaboardUpdatedAt: document.getElementById('vestaboard-updated-at'),
    vestaboardUpdatedDetail: document.getElementById('vestaboard-updated-detail'),
    vestaboardResume: document.getElementById('vestaboard-resume'),
    vestaboardRotateOpen: document.getElementById('vestaboard-rotate-open'),
    vestaboardRotateModal: document.getElementById('vestaboard-rotate-modal'),
    vestaboardRotateEnabled: document.getElementById('vestaboard-rotate-enabled'),
    vestaboardRotateMinutes: document.getElementById('vestaboard-rotate-minutes'),
    vestaboardRotateHint: document.getElementById('vestaboard-rotate-hint'),
    vestaboardRotateSave: document.getElementById('vestaboard-rotate-save'),
    moduleSwitcher: document.getElementById('module-switcher'),
    settingsModuleYarbo: document.getElementById('settings-module-yarbo'),
    settingsModulePowerwall: document.getElementById('settings-module-powerwall'),
    settingsModuleLymow: document.getElementById('settings-module-lymow'),
    settingsVestaboardLive: document.getElementById('settings-vestaboard-live'),
    settingsPowerwallRegion: document.getElementById('settings-powerwall-region'),
    settingsPowerwallPublicUrl: document.getElementById('settings-powerwall-public-url'),
    settingsPowerwallClientId: document.getElementById('settings-powerwall-client-id'),
    settingsPowerwallClientSecret: document.getElementById('settings-powerwall-client-secret'),
    settingsPowerwallRefresh: document.getElementById('settings-powerwall-refresh'),
    settingsPowerwallSite: document.getElementById('settings-powerwall-site'),
    settingsPowerwallHost: document.getElementById('settings-powerwall-host'),
    settingsPowerwallEmail: document.getElementById('settings-powerwall-email'),
    settingsPowerwallPassword: document.getElementById('settings-powerwall-password'),
    settingsPowerwallResult: document.getElementById('settings-powerwall-result'),
    settingsPowerwallKeys: document.getElementById('settings-powerwall-keys'),
    settingsPowerwallOauth: document.getElementById('settings-powerwall-oauth'),
    settingsPowerwallTest: document.getElementById('settings-powerwall-test'),
    settingsLymowHost: document.getElementById('settings-lymow-host'),
    settingsLymowName: document.getElementById('settings-lymow-name'),
    settingsLymowEmail: document.getElementById('settings-lymow-email'),
    settingsLymowPassword: document.getElementById('settings-lymow-password'),
    settingsLymowRegion: document.getElementById('settings-lymow-region'),
    settingsLymowLogin: document.getElementById('settings-lymow-login'),
    settingsLymowResult: document.getElementById('settings-lymow-result'),
    powerwallLoad: document.getElementById('powerwall-load'),
    powerwallSolar: document.getElementById('powerwall-solar'),
    powerwallBattery: document.getElementById('powerwall-battery'),
    powerwallGrid: document.getElementById('powerwall-grid'),
    powerwallSource: document.getElementById('powerwall-source'),
    powerwallUpdated: document.getElementById('powerwall-updated'),
    powerwallError: document.getElementById('powerwall-error'),
    lymowBattery: document.getElementById('lymow-battery'),
    lymowDeviceName: document.getElementById('lymow-device-name'),
    lymowState: document.getElementById('lymow-state'),
    lymowProgress: document.getElementById('lymow-progress'),
    lymowCharging: document.getElementById('lymow-charging'),
    lymowCam: document.getElementById('lymow-cam'),
    lymowStream: document.getElementById('lymow-stream'),
    lymowStreamError: document.getElementById('lymow-stream-error'),
    settingsError: document.getElementById('settings-error'),
    settingsSave: document.getElementById('settings-save'),
    settingsUpdateStatus: document.getElementById('settings-update-status'),
    settingsUpdateNotes: document.getElementById('settings-update-notes'),
    settingsUpdateResult: document.getElementById('settings-update-result'),
    settingsUpdateCheck: document.getElementById('settings-update-check'),
    settingsUpdateViewNotes: document.getElementById('settings-update-view-notes'),
    settingsUpdateRun: document.getElementById('settings-update-run'),
    settingsUpdateSection: document.getElementById('settings-update-section'),
    settingsUpdateCallout: document.getElementById('settings-update-callout'),
    settingsUpdateCalloutText: document.getElementById('settings-update-callout-text'),
    settingsPanelVisibility: document.getElementById('settings-panel-visibility'),
    updateConfirmModal: document.getElementById('update-confirm-modal'),
    updateConfirmTitle: document.getElementById('update-confirm-title'),
    updateConfirmSummary: document.getElementById('update-confirm-summary'),
    updateConfirmNotes: document.getElementById('update-confirm-notes'),
    updateConfirmFootnote: document.getElementById('update-confirm-footnote'),
    updateConfirmActions: document.getElementById('update-confirm-actions'),
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
    mapListenSave: document.getElementById('map-listen-save'),
    mapListenStatus: document.getElementById('map-listen-status'),
    mapLoadBackups: document.getElementById('map-load-backups'),
    mapLoading: document.getElementById('map-loading'),
    mapLoadingText: document.getElementById('map-loading-text'),
    mapEditTip: document.getElementById('map-edit-tip'),
    mapFullscreen: document.getElementById('map-fullscreen'),
    mapCard: document.querySelector('.map-card'),
    panelSections: document.getElementById('panel-sections'),
    controlController: document.getElementById('control-controller'),
    controlLights: document.getElementById('control-lights'),
    controlLightsIcon: document.getElementById('control-lights-icon'),
    controlLightsLabel: document.getElementById('control-lights-label'),
    controlPauseResume: document.getElementById('control-pause-resume'),
    controlPauseResumeIcon: document.getElementById('control-pause-resume-icon'),
    controlPauseResumeLabel: document.getElementById('control-pause-resume-label'),
    driveControllerNote: document.getElementById('drive-controller-note'),
    driveBlockBanner: document.getElementById('drive-block-banner'),
    settingsResetLayout: document.getElementById('settings-reset-layout'),
    papermonoPort: document.getElementById('papermono-port'),
    papermonoSsid: document.getElementById('papermono-ssid'),
    papermonoWifiPassword: document.getElementById('papermono-wifi-password'),
    papermonoPanelUrl: document.getElementById('papermono-panel-url'),
    papermonoName: document.getElementById('papermono-name'),
    papermonoLogo: document.getElementById('papermono-logo'),
    papermonoLogoThumb: document.getElementById('papermono-logo-thumb'),
    papermonoLogoClear: document.getElementById('papermono-logo-clear'),
    papermonoLogoResult: document.getElementById('papermono-logo-result'),
    papermonoLockScreen: document.getElementById('papermono-lock-screen'),
    papermonoLockAfter: document.getElementById('papermono-lock-after'),
    papermonoLightOff: document.getElementById('papermono-light-off'),
    papermonoBrightness: document.getElementById('papermono-brightness'),
    papermonoAlertMessage: document.getElementById('papermono-alert-message'),
    papermonoAlertYarbo: document.getElementById('papermono-alert-yarbo'),
    papermonoAlertLymow: document.getElementById('papermono-alert-lymow'),
    papermonoAlertPowerwall: document.getElementById('papermono-alert-powerwall'),
    papermonoPrefsSave: document.getElementById('papermono-prefs-save'),
    papermonoPrefsResult: document.getElementById('papermono-prefs-result'),
    papermonoResult: document.getElementById('papermono-result'),
    papermonoFwStatus: document.getElementById('papermono-fw-status'),
    papermonoDevices: document.getElementById('papermono-devices'),
    settingsPaperOtaList: document.getElementById('settings-paper-ota-list'),
    settingsPaperOtaAll: document.getElementById('settings-paper-ota-all'),
    settingsPaperOtaResult: document.getElementById('settings-paper-ota-result'),
    papermonoPortsRefresh: document.getElementById('papermono-ports-refresh'),
    papermonoInstallTools: document.getElementById('papermono-install-tools'),
    papermonoBuild: document.getElementById('papermono-build'),
    papermonoFlash: document.getElementById('papermono-flash'),
    papermonoConfig: document.getElementById('papermono-config'),
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
const ACTIVE_MODULE_KEY = 'yarbo_active_module';
let activeModuleId = 'yarbo';
let yarboDeviceName = '';
let lymowPageName = '';
const LIGHTS_ON_KEY = 'yarbo_lights_on';
const CONTROLLER_HOLD_KEY = 'yarbo_hold_controller';
const DEFAULT_PANEL_ORDER = ['status', 'vestaboard', 'diagnostics', 'map', 'cameras', 'drive', 'plans', 'waypoints', 'head', 'controls', 'powerwall', 'lymow'];
const PANEL_LABELS = {
    status: 'Status',
    vestaboard: 'Vestaboard Note',
    diagnostics: 'Diagnostics',
    map: 'Location map',
    cameras: 'Cameras',
    drive: 'Manual drive',
    plans: 'Work plans',
    waypoints: 'Waypoints',
    head: 'Head controls',
    controls: 'Controls',
    powerwall: 'Powerwall',
    lymow: 'Lymow',
};

const ZONE_COLORS = {
    clean: { color: '#67b3ff', fill: '#67b3ff' },
    path: { color: '#9b7dff', fill: '#9b7dff' },
    forbidden: { color: '#ff6b6b', fill: '#ff6b6b' },
    no_vision: { color: '#ffb347', fill: '#ffb347' },
    sidewalk: { color: '#c9a86c', fill: '#c9a86c' },
    obstacle: { color: '#ff8c69', fill: '#ff8c69' },
    recharge: { color: '#7ddea0', fill: '#7ddea0' },
    charging: { color: '#7ddea0', fill: '#7ddea0' },
    default: { color: '#67b3ff', fill: '#67b3ff' },
};

let driveInterval = null;
let driveActive = false;
let driveInFlight = false;
/** @type {{ linear: number, angular: number, enterManual: boolean }|null} */
let pendingDrive = null;
let manualModeEntered = false;

let toastTimer = null;
let polling = false;
let hasStatusSnapshot = false;
let commandQuietUntil = 0;
let settingsModalOpen = false;
let statusAbort = null;
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
let mapFullscreen = false;
let vestaboardSyncAt = 0;
let vestaboardSyncInFlight = false;
let lastVestaboardRotate = { rotate_enabled: false, rotate_views: ['yarbo'], rotate_minutes: 5, rotating: false };
let currentHeadType = null;
let defaultDataSource = 'auto';
let areasLayer = null;
let loadedPlans = [];
let lastStatusData = null;
let pendingPlanDeleteId = null;
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
let mapListenTimer = null;
let mapListenShowResult = false;
let mapBackupCompatible = false;
let lightsOn = false;
let holdController = false;
let controlAwake = false;
let onChargePad = false;
let driveBlockedReason = null;
let draggedPanelId = null;
let themeMediaQuery = null;
let lastUpdateStatus = null;
let updateConfirmResolver = null;

const SETTINGS_PANES = ['connection', 'cloud', 'rain', 'modules', 'lymow', 'powerwall', 'vestaboard', 'papermono', 'appearance', 'updates'];

function syncBodyModalClass() {
    const open = [
        els.batteryCellsModal,
        els.plansManageModal,
        els.updateConfirmModal,
        els.vestaboardRotateModal,
    ].some((modal) => modal && !modal.classList.contains('hidden'));
    document.body.classList.toggle('modal-open', open);
}

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
        const order = saved.filter((id) => document.querySelector(`[data-panel-id="${id}"]`));
        if (!order.includes('vestaboard') && document.querySelector('[data-panel-id="vestaboard"]')) {
            const afterStatus = order.indexOf('status');
            order.splice(afterStatus >= 0 ? afterStatus + 1 : 0, 0, 'vestaboard');
        }
        applyPanelOrder(order);
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
    // Agent reports desired lights state (LedInfoMSG is unreliable). Sync both on/off.
    if (typeof data?.lights_on === 'boolean') {
        lightsOn = data.lights_on;
        try {
            localStorage.setItem(LIGHTS_ON_KEY, lightsOn ? '1' : '0');
        } catch {
            // ignore
        }
    }
}

function applyControllerStateFromStatus(data) {
    if (typeof data?.hold_controller === 'boolean') {
        holdController = data.hold_controller;
        try {
            localStorage.setItem(CONTROLLER_HOLD_KEY, holdController ? '1' : '0');
        } catch {
            // ignore
        }
    }
    if (typeof data?.control_awake === 'boolean') {
        controlAwake = data.control_awake;
    } else if (typeof data?.working_state === 'number') {
        controlAwake = data.working_state === 1;
    }
    // Only StateMSG.charging_status means charging — ignore unreliable recharge_state.
    onChargePad = Boolean(data?.charging) || Boolean(data?.on_charge_pad);
    driveBlockedReason =
        typeof data?.drive_blocked_reason === 'string' && data.drive_blocked_reason
            ? data.drive_blocked_reason
            : null;
}

function isControllerLive() {
    // Agent hold is authoritative; working_state can lag or stay 0 during manual drive.
    return holdController;
}

function canDrive() {
    // Do not block on BodyMsg.recharge_state (false positives). Only require controller.
    return isControllerLive();
}

function updateControllerTile() {
    const live = isControllerLive();
    const pending = false;
    const title = live
        ? 'Controller active — click to release'
        : pending
          ? 'Handshake sent; waiting for robot to leave idle'
          : 'Connect controller (required for lights/drive)';
    const label = live ? 'Connected' : pending ? 'Pending' : 'Off';
    const icon = live ? '📡' : '📴';

    document.querySelectorAll('[data-control="controller"]').forEach((btn) => {
        btn.classList.toggle('is-active', live);
        btn.classList.remove('is-blocked');
        btn.classList.toggle('is-pending', pending);
        btn.setAttribute('aria-pressed', holdController ? 'true' : 'false');
        btn.title = title;
        const iconEl = btn.querySelector('[data-controller-icon]');
        const labelEl = btn.querySelector('[data-controller-label]');
        if (iconEl) iconEl.textContent = icon;
        if (labelEl) labelEl.textContent = label;
    });

    if (els.driveBlockBanner) {
        if (driveBlockedReason) {
            els.driveBlockBanner.textContent = driveBlockedReason;
            els.driveBlockBanner.classList.remove('hidden');
        } else {
            els.driveBlockBanner.textContent = '';
            els.driveBlockBanner.classList.add('hidden');
        }
    }

    if (els.driveControllerNote) {
        els.driveControllerNote.textContent = live
            ? 'Controller connected — hold a direction to drive.'
            : 'Connect the controller to enable the drive pad.';
        els.driveControllerNote.classList.toggle('is-ready', canDrive());
    }

    updateControllerGatedControls();
}

function updateControllerGatedControls() {
    const live = isControllerLive();
    const driveOk = canDrive();
    document.querySelectorAll('[data-needs-controller]').forEach((el) => {
        el.disabled = !live;
        if (!live && !el.title?.includes('Connect controller')) {
            el.dataset.titleUnlocked = el.title || '';
            el.title = 'Connect controller first';
        } else if (live && el.dataset.titleUnlocked) {
            el.title = el.dataset.titleUnlocked;
        }
    });
    document.querySelectorAll('#drive-pad [data-drive]').forEach((btn) => {
        if (btn.dataset.drive === 'stop') {
            btn.disabled = false;
            return;
        }
        btn.disabled = !driveOk;
        btn.title = !live ? 'Connect controller first' : '';
    });
    // Head controls (attached module) also need a controller session.
    [
        'mower-blade-height-send',
        'mower-blade-speed-send',
        'snow-chute-angle-send',
    ].forEach((id) => {
        const btn = document.getElementById(id);
        if (btn) btn.disabled = !live;
    });
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
    applyControllerStateFromStatus(data);
    applyLightsStateFromStatus(data);
    updateControllerTile();
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

async function toggleController(button) {
    if (button.disabled) return;
    const nextOn = !holdController;
    const action = nextOn ? 'controller_on' : 'controller_off';
    holdController = nextOn;
    try {
        localStorage.setItem(CONTROLLER_HOLD_KEY, holdController ? '1' : '0');
    } catch {
        // ignore
    }
    updateControllerTile();
    button.disabled = true;
    try {
        const res = await fetch('/api/command.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=${encodeURIComponent(action)}`,
        });
        const data = await res.json();
        if (data.ok) {
            applyControllerStateFromStatus(data);
            applyLightsStateFromStatus(data);
            updateControllerTile();
            updateLightsTile();
            if (data.warning) {
                showToast(data.warning, 'error');
            } else if (holdController) {
                showToast('Controller connected', 'success');
            } else {
                showToast('Controller hold released', 'success');
            }
        } else {
            holdController = !nextOn;
            try {
                localStorage.setItem(CONTROLLER_HOLD_KEY, holdController ? '1' : '0');
            } catch {
                // ignore
            }
            updateControllerTile();
            showToast(data.error || 'Controller command failed', 'error');
        }
    } catch (err) {
        holdController = !nextOn;
        try {
            localStorage.setItem(CONTROLLER_HOLD_KEY, holdController ? '1' : '0');
        } catch {
            // ignore
        }
        updateControllerTile();
        showToast(err.message || 'Network error', 'error');
    } finally {
        button.disabled = false;
    }
}

async function toggleLights(button) {
    if (button.disabled) return;
    if (!isControllerLive()) {
        showToast('Connect the controller first', 'error');
        return;
    }
    const nextOn = !lightsOn;
    const action = nextOn ? 'lights_on' : 'lights_off';
    // Optimistic UI + disable avoids double-click sending on then off.
    lightsOn = nextOn;
    if (nextOn) {
        holdController = true;
    }
    try {
        localStorage.setItem(LIGHTS_ON_KEY, lightsOn ? '1' : '0');
        if (nextOn) {
            localStorage.setItem(CONTROLLER_HOLD_KEY, '1');
        }
    } catch {
        // ignore
    }
    updateLightsTile();
    updateControllerTile();
    button.disabled = true;
    try {
        const res = await fetch('/api/command.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=${encodeURIComponent(action)}`,
        });
        const data = await res.json();
        if (data.ok) {
            applyControllerStateFromStatus(data);
            updateControllerTile();
            if (lightsOn && data.charging) {
                showToast(
                    'Light command sent, but the robot is charging — firmware often only flashes lights briefly while charging. Unplug the cable and try again.',
                    'error'
                );
            } else {
                showToast(lightsOn ? 'Lights on' : 'Lights off', 'success');
            }
        } else {
            lightsOn = !nextOn;
            try {
                localStorage.setItem(LIGHTS_ON_KEY, lightsOn ? '1' : '0');
            } catch {
                // ignore
            }
            updateLightsTile();
            showToast(data.error || 'Command failed', 'error');
        }
    } catch (err) {
        lightsOn = !nextOn;
        try {
            localStorage.setItem(LIGHTS_ON_KEY, lightsOn ? '1' : '0');
        } catch {
            // ignore
        }
        updateLightsTile();
        showToast(err.message || 'Network error', 'error');
    } finally {
        button.disabled = false;
    }
}

function initAppearance() {
    initTheme();
    initPanelDragDrop();
    initPanelVisibility();
    // Always start Off until status/agent confirms — stale localStorage was showing On wrongly.
    lightsOn = false;
    holdController = false;
    try {
        localStorage.setItem(LIGHTS_ON_KEY, '0');
        localStorage.setItem(CONTROLLER_HOLD_KEY, '0');
    } catch {
        // ignore
    }
    updateControllerTile();
    updateLightsTile();

    els.settingsResetLayout?.addEventListener('click', () => {
        resetPanelOrder();
        showToast('Dashboard layout reset', 'success');
    });

    document.querySelectorAll('[data-control="controller"]').forEach((btn) => {
        btn.addEventListener('click', (event) => {
            toggleController(event.currentTarget);
        });
    });

    els.controlLights?.addEventListener('click', (event) => {
        toggleLights(event.currentTarget);
    });

    els.controlPauseResume?.addEventListener('click', (event) => {
        if (!isControllerLive()) {
            showToast('Connect the controller first', 'error');
            return;
        }
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
    const isLine = feature?.geometry?.type === 'LineString';
    return {
        color: palette.color,
        weight: isLine ? 3 : 2,
        fillColor: palette.fill,
        fillOpacity: isLine ? 0 : 0.2,
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

    draftLayer = L.featureGroup();

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

function fullscreenElement() {
    return document.fullscreenElement || document.webkitFullscreenElement || null;
}

function requestElementFullscreen(el) {
    const fn = el.requestFullscreen || el.webkitRequestFullscreen;
    if (!fn) return Promise.resolve();
    try {
        return Promise.resolve(fn.call(el));
    } catch (err) {
        return Promise.reject(err);
    }
}

function exitDocumentFullscreen() {
    const fn = document.exitFullscreen || document.webkitExitFullscreen;
    if (!fn) return Promise.resolve();
    try {
        return Promise.resolve(fn.call(document));
    } catch (err) {
        return Promise.reject(err);
    }
}

function resizeMapSoon() {
    const run = () => map?.invalidateSize();
    requestAnimationFrame(() => {
        run();
        setTimeout(run, 200);
    });
}

function applyMapFullscreenUi(active) {
    if (els.mapCard) {
        els.mapCard.classList.toggle('map-card--fullscreen', active);
    }
    document.body.classList.toggle('map-is-fullscreen', active);
    if (els.mapFullscreen) {
        els.mapFullscreen.setAttribute('aria-pressed', active ? 'true' : 'false');
        els.mapFullscreen.setAttribute('aria-label', active ? 'Exit full screen' : 'Full screen map');
        els.mapFullscreen.title = active ? 'Exit full screen' : 'Full screen';
        els.mapFullscreen.querySelector('.map-fullscreen-btn__enter')?.classList.toggle('hidden', active);
        els.mapFullscreen.querySelector('.map-fullscreen-btn__exit')?.classList.toggle('hidden', !active);
    }
    resizeMapSoon();
}

function setMapFullscreen(active) {
    if (mapFullscreen === active) {
        resizeMapSoon();
        return;
    }
    mapFullscreen = active;
    applyMapFullscreenUi(active);
    if (active) {
        if (els.mapCard && fullscreenElement() !== els.mapCard) {
            requestElementFullscreen(els.mapCard).catch(() => {});
        }
    } else if (fullscreenElement()) {
        exitDocumentFullscreen().catch(() => {});
    }
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
        if (els.mapLoadBackups) els.mapLoadBackups.disabled = true;
        return;
    }

    els.mapLoading.classList.add('hidden');
    els.mapLoading.setAttribute('aria-busy', 'false');
    clearInterval(mapLoadingTimer);
    mapLoadingTimer = null;
    if (els.mapDataSource) els.mapDataSource.disabled = false;
    if (els.mapEditToggle) els.mapEditToggle.disabled = false;
    if (els.mapLoadAreas) els.mapLoadAreas.disabled = false;
    if (els.mapLoadBackups) els.mapLoadBackups.disabled = false;
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

function hideOriginalMapLayers() {
    setAreasLayerVisible(false);
    mapZoneLayers.forEach((entry) => {
        if (entry?.layer && map.hasLayer(entry.layer)) {
            map.removeLayer(entry.layer);
        }
    });
    if (draftLayer && map.hasLayer(draftLayer)) {
        draftLayer.bringToFront();
    }
}

function enableDraftVertexEditing(onlyLayer) {
    const editOptions = {
        selectedPathOptions: {
            maintainColor: true,
            dashArray: null,
            fillOpacity: 0.3,
            weight: 3,
        },
    };
    draftLayer?.eachLayer((layer) => {
        const shouldEdit = onlyLayer != null && layer === onlyLayer;
        if (layer.editing) {
            if (shouldEdit) {
                layer.editing.enable();
            } else {
                layer.editing.disable();
            }
            return;
        }
        if (!shouldEdit) {
            return;
        }
        if (typeof L.Edit?.Poly === 'function' && typeof layer.getLatLngs === 'function') {
            layer._yarboEditHandler = new L.Edit.Poly(layer, editOptions);
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

    hideOriginalMapLayers();

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

    enableDraftVertexEditing(target);

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
    disableDraftVertexEditing();
    draftLayer?.clearLayers();
    if (els.mapInspector) els.mapInspector.classList.add('hidden');
    if (els.mapZoneList) els.mapZoneList.innerHTML = '';
}

function dedupeMapFeatures(features) {
    const seen = new Set();
    const out = [];
    (Array.isArray(features) ? features : []).forEach((feature) => {
        if (!feature || feature.type !== 'Feature') return;
        const props = feature.properties || {};
        const geom = feature.geometry || {};
        const id = [
            props.zone_type || '',
            props.name || '',
            geom.type || '',
            JSON.stringify(geom.coordinates ?? null),
        ].join('|');
        if (seen.has(id)) return;
        seen.add(id);
        out.push(feature);
    });
    return out;
}

function applyLoadedMapFeatures(features, context = {}) {
    if (!map || !areasLayer) return;
    const unique = dedupeMapFeatures(features);
    clearMapZones();
    const featureCollection = { type: 'FeatureCollection', features: unique };
    areasLayer.addData(featureCollection);
    loadedMapFeatures = unique;
    loadedMapMeta = context.meta || null;

    if (context.restored) {
        const via = context.meta?.data_via ? ` via ${context.meta.data_via}` : '';
        const when = context.loaded_at ? ` from ${new Date(context.loaded_at).toLocaleString()}` : '';
        updateMapAreasStatus(`Restored from last session (${unique.length} feature${unique.length === 1 ? '' : 's'})${via}${when}.`);
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

function featureMapPath(feature) {
    return String(feature?.properties?.path || '');
}

function featureCoordinatePairs(feature) {
    const geom = feature?.geometry;
    if (!geom) return [];
    if (geom.type === 'Polygon' && Array.isArray(geom.coordinates?.[0])) {
        return geom.coordinates[0];
    }
    if (geom.type === 'LineString' && Array.isArray(geom.coordinates)) {
        return geom.coordinates;
    }
    if (geom.type === 'Point' && Array.isArray(geom.coordinates)) {
        return [geom.coordinates];
    }
    return [];
}

function featureMoveScore(a, b) {
    const left = featureCoordinatePairs(a);
    const right = featureCoordinatePairs(b);
    const n = Math.min(left.length, right.length);
    let max = left.length === right.length ? 0 : 1;
    for (let i = 0; i < n; i++) {
        const lon = Number(left[i]?.[0]) - Number(right[i]?.[0]);
        const lat = Number(left[i]?.[1]) - Number(right[i]?.[1]);
        const metres = Math.hypot(
            lon * 111320 * Math.cos((Number(left[i]?.[1]) * Math.PI) / 180),
            lat * 111320,
        );
        max = Math.max(max, metres);
    }
    return max;
}

function splitDraftFeatures(features) {
    const originalByPath = new Map();
    loadedMapFeatures.forEach((feature) => {
        const path = featureMapPath(feature);
        if (path) originalByPath.set(path, feature);
    });
    const best = new Map();
    let skippedNew = 0;
    features.forEach((feature) => {
        if (!feature || feature.type !== 'Feature') return;
        const path = featureMapPath(feature);
        if (!path) {
            skippedNew += 1;
            return;
        }
        const original = originalByPath.get(path);
        const score = original ? featureMoveScore(feature, original) : 1;
        const prev = best.get(path);
        if (!prev || score >= prev.score) {
            best.set(path, { feature, score });
        }
    });
    return {
        kept: [...best.values()].map((entry) => entry.feature),
        skippedNew,
    };
}

function latLngsToCoords(latlngs, closeRing) {
    const ring = Array.isArray(latlngs?.[0]) ? latlngs[0] : latlngs;
    const coords = [];
    (Array.isArray(ring) ? ring : []).forEach((ll) => {
        if (ll && Number.isFinite(ll.lng) && Number.isFinite(ll.lat)) {
            coords.push([ll.lng, ll.lat]);
        }
    });
    if (closeRing && coords.length >= 3) {
        const first = coords[0];
        const last = coords[coords.length - 1];
        if (first[0] !== last[0] || first[1] !== last[1]) {
            coords.push([first[0], first[1]]);
        }
    }
    return coords;
}

function layerToDraftFeature(layer) {
    const props = { ...(layer.feature?.properties || {}) };
    if (typeof L !== 'undefined' && layer instanceof L.Polygon && typeof layer.getLatLngs === 'function') {
        const coords = latLngsToCoords(layer.getLatLngs(), true);
        if (coords.length >= 4) {
            return {
                type: 'Feature',
                properties: props,
                geometry: { type: 'Polygon', coordinates: [coords] },
            };
        }
    }
    if (typeof L !== 'undefined' && layer instanceof L.Polyline && typeof layer.getLatLngs === 'function') {
        const coords = latLngsToCoords(layer.getLatLngs(), false);
        if (coords.length >= 2) {
            return {
                type: 'Feature',
                properties: props,
                geometry: { type: 'LineString', coordinates: coords },
            };
        }
    }
    if (typeof layer.getLatLng === 'function' && typeof L !== 'undefined' && layer instanceof L.CircleMarker) {
        const ll = layer.getLatLng();
        return {
            type: 'Feature',
            properties: props,
            geometry: { type: 'Point', coordinates: [ll.lng, ll.lat] },
        };
    }
    if (typeof layer.toGeoJSON !== 'function') return null;
    const geo = layer.toGeoJSON();
    const feature = geo?.type === 'FeatureCollection' ? geo.features?.[0] : geo;
    if (!feature || feature.type !== 'Feature') return null;
    feature.properties = { ...props, ...(feature.properties || {}) };
    return feature;
}

function rawDraftFeatures() {
    const features = [];
    draftLayer?.eachLayer((layer) => {
        const feature = layerToDraftFeature(layer);
        if (feature) {
            features.push(feature);
        }
    });
    return features;
}

function draftLayerToGeoJson() {
    const { kept } = splitDraftFeatures(rawDraftFeatures());
    return { type: 'FeatureCollection', features: kept };
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
        layer.on('click', (event) => {
            L.DomEvent.stopPropagation(event);
            focusZoneForEditing(layer._yarboZoneIndex);
        });
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
    if (!map || typeof L.Edit?.Poly === 'undefined') {
        if (enabled) {
            showToast('Map editor requires Leaflet.draw to load', 'error');
        }
        return;
    }

    if (!enabled && mapEditMode) {
        disableDraftVertexEditing();
        clearDraftHighlights();
        applyDraftToView();
        draftLayer?.clearLayers();
        if (draftLayer && map.hasLayer(draftLayer)) {
            map.removeLayer(draftLayer);
        }
        setAreasLayerVisible(true);
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

    hideOriginalMapLayers();
    if (draftLayer && !map.hasLayer(draftLayer)) {
        draftLayer.addTo(map);
    }

    if (drawControl) {
        map.removeControl(drawControl);
        drawControl = null;
    }
}

function saveMapDraft() {
    restoreMapBackupDraft();
}

function currentMapDraftCollection() {
    const source = mapEditMode ? rawDraftFeatures() : loadedMapFeatures;
    const { kept, skippedNew } = splitDraftFeatures(Array.isArray(source) ? source : []);
    if (skippedNew > 0) {
        showToast('Ignored a newly drawn shape. Drag vertices of the existing zone — do not draw a new polygon on top.', 'error');
    }
    if (kept.length) {
        return { type: 'FeatureCollection', features: kept };
    }
    return { type: 'FeatureCollection', features: loadedMapFeatures };
}

function setMapSaveEnabled(enabled) {
    mapBackupCompatible = Boolean(enabled);
    if (!els.mapSaveRobot) return;
    els.mapSaveRobot.disabled = !mapBackupCompatible;
    els.mapSaveRobot.title = mapBackupCompatible
        ? 'Restore the edited backup to the robot (must be docked)'
        : 'Load map backups first';
}

function formatBackupCounts(summary) {
    const counts = summary?.counts || {};
    return Object.entries(counts)
        .filter(([, n]) => n > 0)
        .map(([key, n]) => `${key} ${n}`)
        .join(', ') || 'no zones';
}

async function loadMapBackups() {
    setMapLoading(true, 'Loading map backups');
    try {
        const res = await fetch('/api/map_backup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'list' }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) {
            setMapSaveEnabled(false);
            showToast(data.error || 'Could not load map backups', 'error');
            if (els.mapListenStatus) els.mapListenStatus.textContent = data.error || 'Could not load map backups';
            return;
        }
        setMapSaveEnabled(Boolean(data.compatible));
        const counts = data.summary ? formatBackupCounts(data.summary) : '';
        const extra = [counts, data.map_source ? `source ${data.map_source}` : ''].filter(Boolean).join('. ');
        const message = data.message || 'Backup read finished.';
        if (els.mapListenStatus) els.mapListenStatus.textContent = extra ? `${message} ${extra}` : message;
        const featureCollection = data.geojson || { type: 'FeatureCollection', features: [] };
        const features = Array.isArray(featureCollection.features) ? featureCollection.features : [];
        if (features.length > 0 && map && areasLayer) {
            applyLoadedMapFeatures(features, {
                meta: {
                    feature_count: features.length,
                    data_via: data.via || null,
                },
            });
            saveMapCache({ data_via: data.via || 'backup' }, features);
            if (mapEditMode) {
                copyFeaturesToDraft();
                hideOriginalMapLayers();
            }
            updateMapAreasStatus(`Map backup loaded (${features.length} feature${features.length === 1 ? '' : 's'}).`);
        }
        showToast(data.compatible ? 'Backup file extracted. Edit these zones, then Save to robot while docked.' : (data.message || 'Backup file was not in the list'), data.compatible ? 'success' : 'error');
    } catch (err) {
        setMapSaveEnabled(false);
        showToast(err.message || 'Could not load map backups', 'error');
    } finally {
        setMapLoading(false);
    }
}

async function restoreMapBackupDraft() {
    if (!mapBackupCompatible) {
        showToast('Load map backups first', 'error');
        return;
    }
    const collection = currentMapDraftCollection();
    if (!collection.features.length) {
        showToast('Load saved mowing areas (or edit a draft) first', 'error');
        return;
    }
    if (!onChargePad) {
        showToast('Dock the robot before restoring a map', 'error');
        return;
    }
    if (!window.confirm('Replace the map on the robot with this draft? Keep it docked. A copy of the original is saved on the Pi. It is put back only if the robot map is read and does not match the draft.')) {
        return;
    }
    if (els.mapSaveRobot) els.mapSaveRobot.disabled = true;
    setMapLoading(true, 'Saving to robot');
    const saveAbort = new AbortController();
    const saveTimer = setTimeout(() => saveAbort.abort(), 90000);
    try {
        const res = await fetch('/api/map_backup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            signal: saveAbort.signal,
            body: JSON.stringify({
                action: 'restore',
                confirm: true,
                geojson: collection,
            }),
        });
        const data = await parseJsonResponse(res);
        const text = data.message || data.error || 'Restore finished';
        if (els.mapListenStatus) els.mapListenStatus.textContent = text;
        showToast(text, data.ok ? 'success' : 'error');
        if (data.ok && mapEditMode) {
            setMapEditMode(false);
        }
    } catch (err) {
        const aborted = err && (err.name === 'AbortError' || /abort/i.test(String(err.message || '')));
        showToast(aborted ? 'Save timed out after 90s. Try again; the robot may already have the edit.' : (err.message || 'Restore failed'), 'error');
    } finally {
        clearTimeout(saveTimer);
        setMapLoading(false);
        setMapSaveEnabled(mapBackupCompatible);
    }
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
        .filter((row) => row.priority != null);

    const up = ranked.filter((row) => row.priority >= 0).sort((a, b) => a.priority - b.priority);
    const down = ranked.filter((row) => row.priority < 0);

    if (up.length === 0 && down.length === 0) {
        return 'No route data';
    }

    const parts = [];
    if (up.length) {
        const primary = up[0];
        const backups = up.slice(1).map((r) => `${r.label} (${r.priority})`).join(', ');
        parts.push(backups
            ? `Primary: ${primary.label} (${primary.priority}) | Backup: ${backups}`
            : `Primary: ${primary.label} (${primary.priority})`);
    }
    if (down.length) {
        parts.push(`Down: ${down.map((r) => r.label).join(', ')}`);
    }
    return parts.join(' | ');
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

let lastBatteryTempC = null;
/** @type {Array<{index: number, label: string, temperature_c: number}>} */
let lastBatteryCells = [];

function formatBatteryTemp(diag) {
    const incoming = diag?.temperature_c;
    if (incoming != null && !Number.isNaN(Number(incoming))) {
        lastBatteryTempC = Number(incoming);
    }
    const cells = Array.isArray(diag?.cells) ? diag.cells.filter((cell) => cell && Number.isFinite(Number(cell.temperature_c))) : [];
    if (cells.length) {
        lastBatteryCells = cells;
    }
    const temp = formatTemp(lastBatteryTempC ?? incoming);
    if (temp === '—') return temp;
    if ((diag?.temperature_source === 'avg_cells' || lastBatteryCells.length) && lastBatteryCells.length) {
        return `${temp} · ${lastBatteryCells.length} cells`;
    }
    if (diag?.temperature_source === 'avg_cells') return `${temp} (avg cells)`;
    return temp;
}

function openBatteryCellsModal() {
    if (!els.batteryCellsModal) return;
    const cells = lastBatteryCells;
    if (!cells.length) {
        showToast('No cell temperatures yet — wait for the next reading', 'error');
        return;
    }
    if (els.batteryCellsSummary) {
        const avg = lastBatteryTempC != null ? formatTemp(lastBatteryTempC) : '—';
        els.batteryCellsSummary.textContent = `Average ${avg} from the last ${cells.length} cell reading${cells.length === 1 ? '' : 's'}.`;
    }
    if (els.batteryCellsList) {
        els.batteryCellsList.innerHTML = cells.map((cell) => {
            const label = escapeHtml(cell.label || `Cell ${cell.index}`);
            const value = escapeHtml(formatTemp(cell.temperature_c));
            return `<div class="battery-cell-row"><span>${label}</span><strong>${value}</strong></div>`;
        }).join('');
    }
    els.batteryCellsModal.classList.remove('hidden');
    document.body.classList.add('modal-open');
}

function closeBatteryCellsModal() {
    if (!els.batteryCellsModal) return;
    els.batteryCellsModal.classList.add('hidden');
    syncBodyModalClass();
}

function setupBatteryTempClick() {
    const open = () => {
        if (!lastBatteryCells.length && lastBatteryTempC == null) return;
        openBatteryCellsModal();
    };
    els.batteryTemp?.addEventListener('click', open);
    document.querySelectorAll('[data-battery-cells-close]').forEach((el) => {
        el.addEventListener('click', closeBatteryCellsModal);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && els.batteryCellsModal && !els.batteryCellsModal.classList.contains('hidden')) {
            closeBatteryCellsModal();
        }
    });
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
    const heading = Number(data.heading);
    const hasHeading = Number.isFinite(heading);
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

    if (hasHeading) {
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
    } else if (headingLine) {
        map.removeLayer(headingLine);
        headingLine = null;
    }

    if (!mapHasCentered) {
        map.setView([lat, lon], 20);
        mapHasCentered = true;
    }

    const altitudeLabel = data.altitude != null ? `${Number(data.altitude).toFixed(1)}m` : 'n/a';
    const headingLabel = hasHeading ? ` | heading ${heading.toFixed(0)}°` : '';
    updateMapStatus(
        `GPS locked (fix_quality=${fixQuality}) at ${lat.toFixed(6)}, ${lon.toFixed(6)} | altitude ${altitudeLabel}${headingLabel}`
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
                hideOriginalMapLayers();
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

function renderMapListenStatus(data) {
    const el = els.mapListenStatus;
    const btn = els.mapListenSave;
    if (!el || !data) return;
    const state = data.state || 'idle';
    const unknown = Array.isArray(data.unknown_commands) ? data.unknown_commands : [];
    const commands = data.app_commands && typeof data.app_commands === 'object'
        ? Object.keys(data.app_commands)
        : [];
    if (state === 'listening') {
        el.textContent = data.message || `Listening… ${data.remaining_s || 0}s left. Save a map in the Yarbo app now.`;
        if (btn) btn.textContent = 'Stop listening';
        return;
    }
    if (btn) btn.textContent = 'Listen for map save';
    if (state === 'done') {
        if (!mapListenShowResult) {
            return;
        }
        let text = data.message || 'Listen finished.';
        if (unknown.length) {
            text = `Heard unpublished command: ${unknown.join(', ')}. Leave this on screen.`;
        } else if (commands.length) {
            text = `${data.message || 'Listen finished.'} Commands: ${commands.join(', ')}.`;
        }
        el.textContent = text;
        return;
    }
    if (state === 'error') {
        el.textContent = data.error || data.message || 'Listen failed.';
        return;
    }
}

function scheduleMapListenPoll(state) {
    clearTimeout(mapListenTimer);
    mapListenTimer = null;
    if (state === 'listening') {
        mapListenTimer = setTimeout(() => {
            pollMapListen();
        }, 1000);
    }
}

async function pollMapListen() {
    if (!els.mapListenSave) return;
    try {
        const res = await fetch('/api/map_capture.php', { cache: 'no-store' });
        const data = await parseJsonResponse(res);
        renderMapListenStatus(data);
        scheduleMapListenPoll(data.state);
    } catch (err) {
        if (els.mapListenStatus) {
            els.mapListenStatus.textContent = err.message || 'Could not check listen status.';
        }
        if (els.mapListenSave?.textContent === 'Stop listening') {
            scheduleMapListenPoll('listening');
        }
    }
}

async function toggleMapListen() {
    const btn = els.mapListenSave;
    if (btn) btn.disabled = true;
    try {
        const res = await fetch('/api/map_capture.php', { cache: 'no-store' });
        const current = await parseJsonResponse(res);
        const action = current.state === 'listening' ? 'stop' : 'start';
        mapListenShowResult = true;
        const start = await fetch('/api/map_capture.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action }),
        });
        const data = await parseJsonResponse(start);
        renderMapListenStatus(data);
        scheduleMapListenPoll(data.state);
        if (action === 'start' && data.state === 'listening') {
            showToast('Listening for 2 minutes. Save a map in the Yarbo app or Yardstick now.', 'success');
        }
    } catch (err) {
        showToast(err.message || 'Could not start listen', 'error');
    } finally {
        if (btn) btn.disabled = false;
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

function setConnectionTestResult(message, type = null) {
    if (!els.settingsConnectionResult) return;
    if (!message) {
        els.settingsConnectionResult.textContent = '';
        els.settingsConnectionResult.className = 'settings-cloud-result hidden';
        return;
    }
    els.settingsConnectionResult.textContent = message;
    els.settingsConnectionResult.className = `settings-cloud-result ${type || ''}`.trim();
    els.settingsConnectionResult.classList.remove('hidden');
    els.settingsConnectionResult.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function formatDiagnosticsSteps(steps) {
    if (!steps || typeof steps !== 'object') return '';
    const order = ['tcp', 'mqtt_connect', 'telemetry', 'cloud_sdk'];
    return order
        .filter((key) => steps[key])
        .map((key) => {
            const step = steps[key];
            const icon = step.ok ? '✓' : '✗';
            return `${icon} ${step.label}: ${step.message}`;
        })
        .join('\n');
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
    if (lower.includes('telemetry_timeout') || lower.includes('no telemetry received') || lower.includes('connected to the yarbo mqtt broker but the robot did not respond')) {
        return 'Connected to the Yarbo MQTT broker but the robot did not respond. Check the serial number in Settings, wake the robot, and try again.';
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

function noteCommandQuiet(ms = COMMAND_QUIET_MS) {
    commandQuietUntil = Date.now() + ms;
}

function formatUpdatedAt(iso) {
    if (!iso) return 'never';
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}

function formatTimeAgo(iso) {
    if (!iso) return { text: 'never', title: '' };
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return { text: 'never', title: String(iso) };
    const secs = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));
    let text = 'just now';
    if (secs >= 30 && secs < 90) text = '1 minute ago';
    else if (secs >= 90 && secs < 3600) text = `${Math.round(secs / 60)} minutes ago`;
    else if (secs >= 3600 && secs < 5400) text = '1 hour ago';
    else if (secs >= 5400 && secs < 86400) text = `${Math.round(secs / 3600)} hours ago`;
    else if (secs >= 86400 && secs < 172800) text = '1 day ago';
    else if (secs >= 172800) text = `${Math.round(secs / 86400)} days ago`;
    return { text, title: date.toLocaleString() };
}

function batteryLevelName(percent, chargingLabel) {
    if (chargingLabel === 'Full') return 'green';
    if (percent == null || percent === '') return '';
    const n = Number(percent);
    if (!Number.isFinite(n)) return '';
    if (n >= 60) return 'green';
    if (n >= 40) return 'yellow';
    if (n >= 20) return 'orange';
    return 'red';
}

function applyBatteryLevel(el, percent, chargingLabel) {
    if (!el) return;
    const level = batteryLevelName(percent, chargingLabel);
    el.className = level ? `value battery-level battery-level--${level}` : 'value';
}

function looksLikeRobotSerial(name, serial) {
    const value = typeof name === 'string' ? name.trim() : '';
    if (!value) return false;
    const sn = typeof serial === 'string' ? serial.trim() : '';
    if (sn && value.toLowerCase() === sn.toLowerCase()) return true;
    return /^[0-9]{8}[0-9A-Za-z]{8}$/.test(value) || /^[0-9A-Fa-f]{8,}$/.test(value);
}

function applyPanelTitle(hub) {
    const title = String(hub?.panel_title || '').trim() || 'Control Panel';
    const h1 = document.getElementById('panel-title');
    if (h1) h1.textContent = title;
    document.title = title;
}

function applyDeviceNameSubtitle() {
    if (!els.robotName) return;
    const serial = els.settingsSerial?.value.trim() || '';
    let show = '';
    if (activeModuleId === 'yarbo') {
        show = yarboDeviceName;
        if (looksLikeRobotSerial(show, serial)) show = '';
    } else if (activeModuleId === 'lymow') {
        show = lymowPageName;
    }
    const visible = Boolean(show);
    els.robotName.textContent = visible ? show : '';
    els.robotName.classList.toggle('hidden', !visible);
}

function applyLymowDeviceName() {
    if (!els.lymowDeviceName) return;
    const show = lymowPageName;
    els.lymowDeviceName.textContent = show;
    els.lymowDeviceName.classList.toggle('hidden', !show);
}

function applyRobotNameSubtitle(name) {
    yarboDeviceName = typeof name === 'string' ? name.trim() : '';
    applyDeviceNameSubtitle();
}

function applyHubFromStatus(data) {
    const hub = data?.hub;
    if (!hub) return;
    const enabled = Array.isArray(hub.enabled) ? hub.enabled : [];
    const ids = enabled.map((m) => m.id);
    if (els.moduleSwitcher) {
        els.moduleSwitcher.classList.toggle('hidden', ids.length < 2);
        els.moduleSwitcher.innerHTML = enabled.map((m) => (
            `<button type="button" class="module-switcher-btn" data-module-id="${m.id}">${m.label}</button>`
        )).join('');
    }
    updatePowerwallDashboard(data.powerwall);
    updateLymowDashboard(data.lymow);
    if (data.vestaboard) {
        applyVestaboardLiveSwitch(data);
    }
    applyPanelTitle(hub);
    let active = localStorage.getItem(ACTIVE_MODULE_KEY) || hub.active_module || ids[0] || 'yarbo';
    if (!ids.includes(active)) active = ids[0] || 'yarbo';
    setActiveModule(active, false);
}

function setActiveModule(id, persist = true) {
    const moduleId = id || 'yarbo';
    activeModuleId = moduleId;
    if (persist) {
        try { localStorage.setItem(ACTIVE_MODULE_KEY, moduleId); } catch { /* ignore */ }
    }
    document.querySelectorAll('#panel-sections .panel-section[data-module]').forEach((section) => {
        const owner = section.getAttribute('data-module');
        const show = owner === 'shared' || owner === moduleId;
        section.classList.toggle('module-pane-hidden', !show);
    });
    els.moduleSwitcher?.querySelectorAll('[data-module-id]').forEach((btn) => {
        btn.classList.toggle('is-active', btn.getAttribute('data-module-id') === moduleId);
    });
    applyDeviceNameSubtitle();
    if (moduleId === 'lymow') {
        startLymowCamera();
        startLymowCloudPoll();
    } else {
        stopLymowCamera();
        stopLymowCloudPoll();
    }
}

function updatePowerwallDashboard(pw) {
    if (!els.powerwallLoad) return;
    if (!pw) {
        els.powerwallLoad.textContent = '—';
        if (els.powerwallBattery) {
            els.powerwallBattery.textContent = '—';
            applyBatteryLevel(els.powerwallBattery, null);
        }
        return;
    }
    els.powerwallLoad.textContent = pw.load_label || '—';
    els.powerwallSolar.textContent = pw.solar_label || '—';
    els.powerwallBattery.textContent = pw.battery_label || '—';
    applyBatteryLevel(els.powerwallBattery, pw.battery_percent);
    els.powerwallGrid.textContent = pw.grid_label || '—';
    if (els.powerwallSource) els.powerwallSource.textContent = pw.source || '—';
    if (els.powerwallUpdated) els.powerwallUpdated.textContent = pw.fetched_at ? formatUpdatedAt(pw.fetched_at) : 'never';
    if (els.powerwallError) els.powerwallError.textContent = pw.error ? ` · ${pw.error}` : '';
}

function updateLymowDashboard(ly) {
    if (!els.lymowBattery && !els.lymowState && !els.lymowCharging && !els.lymowCam) return;
    if (!ly) {
        if (els.lymowBattery) {
            els.lymowBattery.textContent = '—';
            applyBatteryLevel(els.lymowBattery, null);
        }
        if (els.lymowState) els.lymowState.textContent = '—';
        if (els.lymowProgress) els.lymowProgress.textContent = '—';
        if (els.lymowCharging) els.lymowCharging.textContent = '—';
        if (els.lymowCam) els.lymowCam.textContent = '—';
        lymowPageName = '';
        applyLymowDeviceName();
        return;
    }
    const camOk = Boolean(ly.camera_ok);
    if (els.lymowBattery) {
        els.lymowBattery.textContent = ly.battery_label || '—';
        applyBatteryLevel(els.lymowBattery, ly.battery, ly.charging_label);
    }
    if (els.lymowState) els.lymowState.textContent = ly.work_label || '—';
    if (els.lymowProgress) els.lymowProgress.textContent = ly.mow_progress_label || '—';
    if (els.lymowCharging) els.lymowCharging.textContent = ly.charging_label || '—';
    if (els.lymowCam) els.lymowCam.textContent = camOk ? 'Up' : 'Down';
    lymowPageName = String(ly.page_name || ly.display_name || ly.device_name || '').trim();
    applyLymowDeviceName();
    applyDeviceNameSubtitle();
}

function lymowCamMode() {
    const checked = document.querySelector('input[name="lymow-cam-mode"]:checked');
    return checked?.value === 'stream' ? 'stream' : 'stills';
}

let lymowSnapTimer = null;
let lymowSnapBusy = false;
let lymowSnapObjectUrl = '';
let lymowCloudTimer = null;
let lymowCameraModeStarted = '';

function stopLymowSnapshots() {
    if (lymowSnapTimer) {
        clearInterval(lymowSnapTimer);
        lymowSnapTimer = null;
    }
}

function stopLymowCloudPoll() {
    if (lymowCloudTimer) {
        clearInterval(lymowCloudTimer);
        lymowCloudTimer = null;
    }
}

function stopLymowCamera() {
    lymowCameraModeStarted = '';
    stopLymowSnapshots();
    fetch('/api/lymow.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'stop_live' }),
    }).catch(() => {});
}

async function grabLymowSnapshot() {
    if (!els.lymowStream || lymowSnapBusy || document.hidden) return;
    if (els.lymowStream.closest('.module-pane-hidden')) return;
    lymowSnapBusy = true;
    const stream = lymowCamMode() === 'stream';
    const action = stream ? 'live' : 'snapshot';
    try {
        const res = await fetch(`/api/lymow.php?action=${action}&t=${Date.now()}`, { cache: 'no-store' });
        const type = (res.headers.get('content-type') || '').toLowerCase();
        if (!res.ok || !type.includes('jpeg')) {
            let message = `Camera ${stream ? 'stream' : 'snapshot'} failed (${res.status})`;
            if (type.includes('json')) {
                try {
                    const data = await res.json();
                    if (data.error) message = String(data.error);
                } catch { /* keep message */ }
            }
            throw new Error(message);
        }
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        els.lymowStream.src = url;
        if (lymowSnapObjectUrl) URL.revokeObjectURL(lymowSnapObjectUrl);
        lymowSnapObjectUrl = url;
        if (els.lymowStreamError) {
            els.lymowStreamError.textContent = '';
            els.lymowStreamError.classList.add('hidden');
        }
        if (els.lymowCam) els.lymowCam.textContent = 'Up';
    } catch (err) {
        if (els.lymowStreamError) {
            els.lymowStreamError.textContent = err.message || 'Could not load Lymow camera.';
            els.lymowStreamError.classList.remove('hidden');
        }
        if (els.lymowCam) els.lymowCam.textContent = 'Down';
    } finally {
        lymowSnapBusy = false;
    }
}

async function startLymowCamera() {
    if (!els.lymowStream || document.hidden) return;
    if (els.lymowStream.closest('.module-pane-hidden')) return;
    const stream = lymowCamMode() === 'stream';
    const key = stream ? 'stream' : 'stills';
    if (lymowSnapTimer && lymowCameraModeStarted === key) return;
    lymowCameraModeStarted = key;
    if (stream) {
        try {
            await fetch('/api/lymow.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'start_live' }),
            });
        } catch { /* grab will surface the error */ }
    } else {
        fetch('/api/lymow.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'stop_live' }),
        }).catch(() => {});
    }
    const interval = stream ? 280 : 2500;
    if (lymowSnapTimer) {
        clearInterval(lymowSnapTimer);
        lymowSnapTimer = null;
    }
    grabLymowSnapshot();
    lymowSnapTimer = setInterval(grabLymowSnapshot, interval);
}

function startLymowCloudPoll() {
    if (lymowCloudTimer || document.hidden) return;
    if (!document.querySelector('#lymow-card:not(.module-pane-hidden)')) return;
    const tick = async () => {
        try {
            const res = await fetch('/api/lymow.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'refresh_cloud' }),
            });
            const data = await parseJsonResponse(res);
            updateLymowDashboard(data);
        } catch { /* keep last reading */ }
    };
    tick();
    lymowCloudTimer = setInterval(tick, 4000);
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        stopLymowCamera();
        stopLymowCloudPoll();
    } else if (document.querySelector('#lymow-card:not(.module-pane-hidden)')) {
        startLymowCamera();
        startLymowCloudPoll();
    }
});

function powerwallTransport() {
    const checked = document.querySelector('input[name="powerwall-transport"]:checked');
    return checked?.value === 'local' ? 'local' : 'cloud';
}

function applyPowerwallTransport() {
    const local = powerwallTransport() === 'local';
    document.getElementById('settings-powerwall-local-fields')?.classList.toggle('hidden', !local);
    document.getElementById('settings-powerwall-cloud-fields')?.classList.toggle('hidden', local);
}

function applyCompanionSettingsVisibility() {
    const yarboOn = Boolean(els.settingsModuleYarbo?.checked);
    const powerwallOn = Boolean(els.settingsModulePowerwall?.checked);
    const lymowOn = Boolean(els.settingsModuleLymow?.checked);
    document.getElementById('settings-yarbo-connection-fields')?.classList.toggle('hidden', !yarboOn);
    document.getElementById('settings-cloud-section')?.classList.toggle('hidden', !yarboOn);
    document.getElementById('settings-rain-section')?.classList.toggle('hidden', !yarboOn);
    document.querySelector('[data-settings-nav="cloud"]')?.classList.toggle('hidden', !yarboOn);
    document.querySelector('[data-settings-nav="rain"]')?.classList.toggle('hidden', !yarboOn);
    if (els.settingsHost) els.settingsHost.required = yarboOn;
    if (els.settingsSerial) els.settingsSerial.required = yarboOn;
    document.getElementById('settings-powerwall-section')?.classList.toggle(
        'hidden',
        !powerwallOn,
    );
    document.getElementById('settings-lymow-section')?.classList.toggle(
        'hidden',
        !lymowOn,
    );
    document.querySelector('[data-settings-nav="powerwall"]')?.classList.toggle('hidden', !powerwallOn);
    document.querySelector('[data-settings-nav="lymow"]')?.classList.toggle('hidden', !lymowOn);
    document.getElementById('papermono-alert-yarbo')?.closest('label')?.classList.toggle('hidden', !yarboOn);
    document.getElementById('papermono-alert-powerwall')?.closest('label')?.classList.toggle('hidden', !powerwallOn);
    document.getElementById('papermono-alert-lymow')?.closest('label')?.classList.toggle('hidden', !lymowOn);
    const active = document.querySelector('.settings-section.is-active');
    if (active?.classList.contains('hidden')) {
        showSettingsPane('modules');
    }
    applyVestaboardLiveChoices({
        yarbo: yarboOn,
        powerwall: powerwallOn,
        lymow: lymowOn,
    }, els.settingsVestaboardLive?.value || '');
    applyPaperPreviewModules();
}

function onModuleCheckboxChange(event) {
    const yarboOn = Boolean(els.settingsModuleYarbo?.checked);
    const powerwallOn = Boolean(els.settingsModulePowerwall?.checked);
    const lymowOn = Boolean(els.settingsModuleLymow?.checked);
    if (!yarboOn && !powerwallOn && !lymowOn) {
        if (event?.currentTarget) event.currentTarget.checked = true;
        showToast('Keep at least one module on', 'error');
        return;
    }
    applyCompanionSettingsVisibility();
}

function updateStatus(data) {
    applyRobotNameSubtitle(typeof data.robot_name === 'string' ? data.robot_name : '');
    applyHubFromStatus(data);
    els.battery.textContent = data.battery != null ? `${data.battery}%` : '—';
    applyBatteryLevel(els.battery, data.battery, data.charging_label);
    {
        const state = data.state ?? '';
        els.state.textContent = state.toLowerCase() === 'rain' ? 'Rain' : (state || '—');
        let stateClass = 'value badge';
        if (state === 'active') stateClass += ' active';
        else if (state.toLowerCase() === 'rain') stateClass += ' rain';
        els.state.className = stateClass;
    }
    {
        const chargeLabel = data.charging_label || (data.charging ? 'Yes' : 'No');
        els.charging.textContent = chargeLabel;
        els.charging.className = `value${chargeLabel === 'Full' ? ' badge active' : ''}`;
    }
    els.heading.textContent = data.heading != null ? `${data.heading}°` : '—';
    els.headType.textContent = data.head_type_name ?? '—';
    {
        const err = data.error_code ?? '—';
        const pf = typeof data.power_fault === 'number' ? data.power_fault : null;
        els.errorCode.textContent = pf > 0 ? `${err} (power ${pf})` : String(err);
        const hasError = Number(data.error_code) > 0 || pf > 0;
        els.errorCode.className = hasError ? 'value is-error' : 'value';
    }
    updateRainStatus(data);
    els.updatedAt.textContent = formatUpdatedAt(data.updated_at);
    updateRobotOnMap(data);
    renderDiagnostics(data);
    updatePlanActivity(data);
    updateHeadControls(data);
    updateVestaboardDashboard(data);
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
        const hasCells = lastBatteryCells.length > 0;
        els.batteryTemp.disabled = !hasCells;
        els.batteryTemp.classList.toggle('is-clickable', hasCells);
        els.batteryTemp.title = hasCells ? 'Show individual cell temperatures' : 'Cell temperatures';
        els.batteryTempStat?.classList.toggle('is-clickable', hasCells);
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

function formatRainLabel(data) {
    const fields = data?.rain_fields && typeof data.rain_fields === 'object' ? data.rain_fields : {};
    const hasFields = Object.keys(fields).length > 0;
    const reading = data?.rain_sensor_data;
    const detected = Boolean(data?.rain_detected);
    const num = reading != null && Number.isFinite(Number(reading)) ? String(Number(reading)) : null;
    if (!hasFields && num == null && !detected) {
        return { text: '—', className: 'value' };
    }
    if (detected) {
        return { text: num ? `Wet ${num}` : 'Wet', className: 'value badge rain' };
    }
    return { text: num ? `Dry ${num}` : 'Dry', className: 'value' };
}

function updateRainStatus(data) {
    const label = formatRainLabel(data);
    if (els.rain) {
        els.rain.textContent = label.text;
        els.rain.className = label.className;
    }
    if (els.rainSensor) {
        const fields = data?.rain_fields && typeof data.rain_fields === 'object' ? data.rain_fields : {};
        const paths = Object.entries(fields).map(([k, v]) => `${k}=${v}`).join(', ');
        if (paths) {
            els.rainSensor.textContent = paths;
            els.rainSensor.classList.add('compact');
            els.rainSensor.title = paths;
        } else if (data?.rain_sensor_data != null) {
            els.rainSensor.textContent = String(data.rain_sensor_data);
        } else {
            els.rainSensor.textContent = '—';
            els.rainSensor.removeAttribute('title');
        }
    }
}

function planActivityName(planStatus) {
    const named = String(planStatus.plan_name || '').trim();
    const id = planStatus.plan_id;
    const loaded = id != null
        ? loadedPlans.find((item) => String(item.id) === String(id))
        : null;
    if (named && named !== String(id ?? '')) {
        return named;
    }
    if (loaded) {
        return planDisplayName(loaded);
    }
    if (id != null && String(id) !== '') {
        return `Work plan ${id}`;
    }
    return '';
}

function formatPlanPercent(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return '';
    }
    return `${n.toFixed(1)}%`;
}

function updatePlanActivity(data) {
    lastStatusData = data;
    if (!els.plansStatus || !els.plansActivityBadge || !els.plansActivityTitle) return;

    const planStatus = data.plan_status || {};
    const name = planActivityName(planStatus);
    const pctLabel = formatPlanPercent(planStatus.plan_percent);
    const remaining = Number(planStatus.remaining_m2);
    const hasRemaining = Number.isFinite(remaining);
    const details = [];

    let badge = 'Idle';
    let badgeClass = 'badge';
    let title = 'No plan running';
    let mode = 'idle';

    if (data.planning_paused) {
        badge = 'Paused';
        badgeClass = 'badge degraded';
        title = name || 'Work plan paused';
        mode = 'paused';
        if (planStatus.pause_reason) {
            details.push(String(planStatus.pause_reason));
        }
    } else if (data.plan_running) {
        badge = 'Running';
        badgeClass = 'badge active';
        title = name || 'Work plan in progress';
        mode = 'running';
    } else if (data.returning_to_dock) {
        badge = 'Docking';
        badgeClass = 'badge';
        title = 'Returning to dock';
        mode = 'docking';
    }

    if (data.rain_detected) {
        details.push('Rain detected');
    }
    if (planStatus.error_message) {
        details.push(String(planStatus.error_message));
    }
    if (hasRemaining && (mode === 'running' || mode === 'paused')) {
        details.unshift(`${remaining.toFixed(1)} m² left`);
    }

    els.plansStatus.className = `plan-activity is-${mode}`;
    els.plansActivityBadge.className = badgeClass;
    els.plansActivityBadge.textContent = badge;
    els.plansActivityTitle.textContent = title;

    if (els.plansActivityPct) {
        els.plansActivityPct.textContent = pctLabel;
    }
    if (els.plansActivityProgress && els.plansActivityBarWrap && els.plansActivityBar) {
        const n = Number(planStatus.plan_percent);
        const showBar = Number.isFinite(n) && (mode === 'running' || mode === 'paused');
        els.plansActivityProgress.classList.toggle('hidden', !showBar);
        if (showBar) {
            const width = Math.max(0, Math.min(100, n));
            els.plansActivityBar.style.width = `${width}%`;
            els.plansActivityBarWrap.setAttribute('aria-valuenow', String(Math.round(width)));
            els.plansActivityBarWrap.setAttribute('aria-valuetext', pctLabel);
        }
    }
    if (els.plansActivityDetail) {
        els.plansActivityDetail.textContent = details.join(' · ');
        els.plansActivityDetail.classList.toggle('hidden', details.length === 0);
    }
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

function updateVestaboardDashboard(data) {
    if (!els.vestaboardCard) return;
    const board = data.vestaboard;
    const enabled = Boolean(board?.enabled);
    els.vestaboardCard.classList.toggle('hidden', !enabled);
    applyVestaboardLiveSwitch(data);
    applyVestaboardRotateState(board);
    if (!enabled) return;
    renderVestaboardPreview(board.lines, els.vestaboardBoard, board.codes);
    if (els.vestaboardUpdatedAt) {
        const rel = formatTimeAgo(board.last_sent_at);
        els.vestaboardUpdatedAt.textContent = rel.text;
        els.vestaboardUpdatedAt.title = rel.title;
    }
    if (els.vestaboardResume) {
        els.vestaboardResume.classList.toggle('hidden', !board.external_hold);
    }
    if (els.vestaboardUpdatedDetail) {
        if (board.last_error) {
            els.vestaboardUpdatedDetail.textContent = ` · ${board.last_error}`;
        } else if (board.external_hold) {
            if (board.external_hold_quiet) {
                const until = board.external_hold_until_hm || board.quiet_until || '';
                els.vestaboardUpdatedDetail.textContent = until
                    ? ` · Vestaboard app until quiet hours end (${until})`
                    : ' · Vestaboard app until quiet hours end';
            } else {
                const until = board.external_hold_until_hm || '';
                els.vestaboardUpdatedDetail.textContent = until
                    ? ` · Vestaboard app until ${until}`
                    : ' · Vestaboard app (paused)';
            }
        } else if (board.pending) {
            els.vestaboardUpdatedDetail.textContent = ' · sending…';
        } else if (board.watcher_ok === false) {
            els.vestaboardUpdatedDetail.textContent = ' · background updater idle — restart the panel';
        } else if (board.quiet_hours) {
            els.vestaboardUpdatedDetail.textContent = ` · quiet hours until ${board.quiet_until || ''}`;
        } else if (board.rotating) {
            els.vestaboardUpdatedDetail.textContent = ' · rotating';
        } else {
            els.vestaboardUpdatedDetail.textContent = '';
        }
    }
    if (board.pending && !board.external_hold) {
        syncVestaboardIfPending();
    }
}

function applyVestaboardLiveSwitch(data) {
    if (!els.vestaboardLiveSwitch) return;
    const enabled = Boolean(data?.vestaboard?.enabled);
    els.vestaboardLiveSwitch.classList.toggle('hidden', !enabled);
    const extras = vestaboardExtraModules(data?.hub);
    applyVestaboardLiveChoices(extras, data?.hub?.vestaboard_live || data?.vestaboard_live || '');
}

function applyVestaboardRotateState(board) {
    lastVestaboardRotate = {
        rotate_enabled: Boolean(board?.rotate_enabled),
        rotate_views: Array.isArray(board?.rotate_views) ? board.rotate_views : lastVestaboardRotate.rotate_views,
        rotate_minutes: Number(board?.rotate_minutes) > 0 ? Number(board.rotate_minutes) : lastVestaboardRotate.rotate_minutes,
        rotating: Boolean(board?.rotating),
    };
    els.vestaboardRotateOpen?.classList.toggle('is-active', lastVestaboardRotate.rotating);
}

function rotateViewCheckboxes() {
    return [...document.querySelectorAll('[data-rotate-view]')];
}

function applyRotateViewChoices(extras) {
    const yarbo = extras?.yarbo !== false;
    const pw = Boolean(extras?.powerwall);
    const ly = Boolean(extras?.lymow);
    const enabledCount = [yarbo, pw, ly].filter(Boolean).length;
    const show = {
        yarbo,
        powerwall: pw,
        lymow: ly,
        batteries: enabledCount >= 2,
    };
    document.querySelectorAll('[data-rotate-choice]').forEach((label) => {
        const id = label.getAttribute('data-rotate-choice') || '';
        label.classList.toggle('hidden', !show[id]);
        const box = label.querySelector('[data-rotate-view]');
        if (box && !show[id]) box.checked = false;
    });
}

function fillVestaboardRotateForm() {
    applyRotateViewChoices(vestaboardExtraModules(lastStatusData?.hub || {}));
    if (els.vestaboardRotateEnabled) {
        els.vestaboardRotateEnabled.checked = lastVestaboardRotate.rotate_enabled;
    }
    if (els.vestaboardRotateMinutes) {
        els.vestaboardRotateMinutes.value = String(lastVestaboardRotate.rotate_minutes || 5);
    }
    const selected = new Set(lastVestaboardRotate.rotate_views || []);
    rotateViewCheckboxes().forEach((box) => {
        const id = box.getAttribute('data-rotate-view') || '';
        const label = box.closest('[data-rotate-choice]');
        const visible = !label?.classList.contains('hidden');
        box.checked = visible && selected.has(id);
    });
    updateVestaboardRotateHint();
}

function selectedRotateViews() {
    return rotateViewCheckboxes()
        .filter((box) => box.checked && !box.closest('[data-rotate-choice]')?.classList.contains('hidden'))
        .map((box) => box.getAttribute('data-rotate-view') || '')
        .filter(Boolean);
}

function updateVestaboardRotateHint() {
    const views = selectedRotateViews();
    const needTwo = Boolean(els.vestaboardRotateEnabled?.checked) && views.length < 2;
    els.vestaboardRotateHint?.classList.toggle('hidden', !needTwo);
}

function openVestaboardRotateModal() {
    if (!els.vestaboardRotateModal) return;
    fillVestaboardRotateForm();
    els.vestaboardRotateModal.classList.remove('hidden');
    document.body.classList.add('modal-open');
}

function closeVestaboardRotateModal() {
    if (!els.vestaboardRotateModal) return;
    els.vestaboardRotateModal.classList.add('hidden');
    syncBodyModalClass();
}

async function saveVestaboardRotate(button) {
    const views = selectedRotateViews();
    const enabled = Boolean(els.vestaboardRotateEnabled?.checked);
    if (enabled && views.length < 2) {
        updateVestaboardRotateHint();
        showToast('Tick at least two views to rotate', 'error');
        return;
    }
    const minutes = Math.max(1, Math.min(60, parseInt(els.vestaboardRotateMinutes?.value || '5', 10) || 5));
    if (button) button.disabled = true;
    try {
        const res = await fetch('/api/vestaboard.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'rotate',
                rotate_enabled: enabled,
                rotate_views: views,
                rotate_minutes: minutes,
            }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Could not save rotation');
        applyVestaboardRotateState(data);
        if (data.hub || data.vestaboard_live) {
            applyVestaboardLiveSwitch({
                hub: data.hub || { vestaboard_live: data.vestaboard_live },
                vestaboard: { enabled: true, rotating: data.rotating },
                vestaboard_live: data.vestaboard_live,
            });
        }
        if (data.lines) {
            renderVestaboardPreview(data.lines, els.vestaboardBoard, data.codes);
        }
        closeVestaboardRotateModal();
        showToast(data.rotating ? 'Vestaboard rotation on' : 'Vestaboard rotation off', 'success');
        fetchStatus().catch(() => {});
    } catch (err) {
        showToast(err.message || 'Could not save rotation', 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

function vestaboardExtraModules(hub) {
    return {
        yarbo: hub?.modules?.yarbo !== false,
        powerwall: Boolean(hub?.modules?.powerwall),
        lymow: Boolean(hub?.modules?.lymow),
    };
}

function firstShownVestaboardLive(show) {
    return ['yarbo', 'powerwall', 'lymow', 'batteries'].find((id) => show[id]) || 'yarbo';
}

function applyVestaboardLiveChoices(extras, live) {
    const yarbo = extras?.yarbo !== false;
    const pw = Boolean(extras?.powerwall);
    const ly = Boolean(extras?.lymow);
    const enabledCount = [yarbo, pw, ly].filter(Boolean).length;
    const show = {
        yarbo,
        powerwall: pw,
        lymow: ly,
        batteries: enabledCount >= 2,
    };
    let chosen = live || firstShownVestaboardLive(show);
    if (!show[chosen]) chosen = firstShownVestaboardLive(show);
    els.vestaboardLiveSwitch?.querySelectorAll('[data-vestaboard-live]').forEach((btn) => {
        const id = btn.getAttribute('data-vestaboard-live') || '';
        btn.classList.toggle('hidden', !show[id]);
        btn.classList.toggle('is-active', show[id] && id === chosen);
    });
    if (els.settingsVestaboardLive) {
        [...els.settingsVestaboardLive.options].forEach((opt) => {
            const hide = !show[opt.value];
            opt.hidden = hide;
            opt.disabled = hide;
        });
        if ([...els.settingsVestaboardLive.options].some((o) => o.value === chosen && !o.hidden)) {
            els.settingsVestaboardLive.value = chosen;
        } else {
            els.settingsVestaboardLive.value = firstShownVestaboardLive(show);
        }
    }
}

async function setVestaboardLiveView(id, button) {
    if (button) button.disabled = true;
    els.vestaboardLiveSwitch?.querySelectorAll('[data-vestaboard-live]').forEach((btn) => {
        btn.classList.toggle('is-active', btn.getAttribute('data-vestaboard-live') === id);
    });
    try {
        const res = await fetch('/api/vestaboard.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'live', vestaboard_live: id }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Could not change Vestaboard view');
        applyVestaboardLiveSwitch({
            hub: data.hub || { vestaboard_live: data.vestaboard_live || id },
            vestaboard: { enabled: true },
            vestaboard_live: data.vestaboard_live || id,
        });
        if (data.lines) {
            renderVestaboardPreview(data.lines, els.vestaboardBoard, data.codes);
        }
        const label = { yarbo: 'Yarbo', powerwall: 'Powerwall', lymow: 'Lymow', batteries: 'ALL' }[id] || id;
        showToast(`Vestaboard: ${label}`, 'success');
        fetchStatus().catch(() => {});
    } catch (err) {
        showToast(err.message || 'Could not change Vestaboard view', 'error');
        fetchStatus().catch(() => {});
    } finally {
        if (button) button.disabled = false;
    }
}

async function syncVestaboardIfPending() {
    if (vestaboardSyncInFlight) return;
    const now = Date.now();
    if (now - vestaboardSyncAt < 15000) return;
    vestaboardSyncAt = now;
    vestaboardSyncInFlight = true;
    try {
        const res = await fetch('/api/vestaboard.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'send' }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) {
            vestaboardSyncAt = 0;
        }
    } catch {
        vestaboardSyncAt = 0;
    } finally {
        vestaboardSyncInFlight = false;
    }
}

function formatCloudStatus(cloudStatus) {
    if (!cloudStatus) return 'Cloud bridge: unknown';
    const parts = [];
    if (cloudStatus.sdk_installed) {
        const py = cloudStatus.python_executable || cloudStatus.python;
        parts.push(py ? `SDK installed (${py})` : 'SDK installed');
    } else {
        parts.push(cloudStatus.sdk_path_hint || 'SDK not installed (run ./scripts/install.sh)');
    }
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
    updatePlansManageButton();
    if (lastStatusData) {
        updatePlanActivity(lastStatusData);
    }

    if (!plans.length) {
        els.plansList.innerHTML = '';
        renderPlansManageList();
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
                </div>
            </article>
        `;
    }).join('');

    els.plansList.querySelectorAll('[data-plan-start]').forEach((button) => {
        button.addEventListener('click', () => startPlan(button.dataset.planStart, button));
    });
    renderPlansManageList();
}

function updatePlansManageButton() {
    if (!els.plansManage) return;
    const hasPlans = loadedPlans.length > 0;
    els.plansManage.disabled = !hasPlans;
    els.plansManage.title = hasPlans ? 'Delete saved plans' : 'Load plans first';
}

function planDisplayName(plan) {
    const name = plan?.name ? String(plan.name) : '';
    const id = plan?.id != null ? String(plan.id) : '';
    if (name && name !== id) return name;
    return id ? `Plan ${id}` : 'this plan';
}

function renderPlansManageList() {
    if (!els.plansManageList) return;
    if (!loadedPlans.length) {
        els.plansManageList.innerHTML = '<p class="hint">No saved plans loaded.</p>';
        return;
    }

    els.plansManageList.innerHTML = loadedPlans.map((plan) => {
        const id = String(plan.id);
        const confirming = pendingPlanDeleteId === id;
        const areas = Array.isArray(plan.area_ids) && plan.area_ids.length
            ? `Areas: ${plan.area_ids.join(', ')}`
            : 'No area IDs';
        const actions = confirming
            ? `
                <div class="plan-manage-confirm">
                    <p class="plan-manage-confirm-note">Delete permanently? This cannot be undone.</p>
                    <button type="button" class="btn btn-danger" data-plan-delete-confirm="${escapeHtml(id)}">Delete</button>
                    <button type="button" class="btn btn-secondary" data-plan-delete-cancel>Cancel</button>
                </div>
            `
            : `<button type="button" class="btn-text-danger" data-plan-delete="${escapeHtml(id)}">Delete</button>`;
        return `
            <article class="plan-manage-item">
                <div>
                    <strong>${escapeHtml(planDisplayName(plan))}</strong>
                    <p class="hint">ID ${escapeHtml(id)} · ${escapeHtml(areas)}</p>
                </div>
                ${actions}
            </article>
        `;
    }).join('');

    els.plansManageList.querySelectorAll('[data-plan-delete]').forEach((button) => {
        button.addEventListener('click', () => {
            pendingPlanDeleteId = button.dataset.planDelete;
            renderPlansManageList();
        });
    });
    els.plansManageList.querySelectorAll('[data-plan-delete-cancel]').forEach((button) => {
        button.addEventListener('click', () => {
            pendingPlanDeleteId = null;
            renderPlansManageList();
        });
    });
    els.plansManageList.querySelectorAll('[data-plan-delete-confirm]').forEach((button) => {
        button.addEventListener('click', () => deletePlan(button.dataset.planDeleteConfirm, button));
    });
}

function openPlansManageModal() {
    if (!els.plansManageModal) return;
    if (!loadedPlans.length) {
        showToast('Load plans first', 'error');
        return;
    }
    pendingPlanDeleteId = null;
    renderPlansManageList();
    els.plansManageModal.classList.remove('hidden');
    document.body.classList.add('modal-open');
}

function closePlansManageModal() {
    if (!els.plansManageModal) return;
    pendingPlanDeleteId = null;
    els.plansManageModal.classList.add('hidden');
    syncBodyModalClass();
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
    const plan = loadedPlans.find((item) => String(item.id) === String(planId));
    const label = planDisplayName(plan || { id: planId });
    if (!confirm(`Start ${label} at ${planStartPercent()}%?`)) return;

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
        if (isCommandAckError(data.ack_msg)) throw new Error(data.ack_msg);
        if (typeof data.hold_controller === 'boolean') {
            applyControllerStateFromStatus(data);
            updateControllerTile();
        }
        if (data.ack_msg) {
            showToast(`${label} started (${data.ack_msg})`, 'success');
        } else if (data.via === 'official_payload') {
            showToast(`${label} start sent (no robot ack)`, 'success');
        } else {
            showToast(`${label} started`, 'success');
        }
        noteCommandQuiet();
    } catch (err) {
        showToast(err.message || 'Start failed', 'error');
    } finally {
        button.disabled = false;
    }
}

async function deletePlan(planId, button) {
    if (!planId) return;

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
        pendingPlanDeleteId = null;
        showToast(`Plan ${planId} deleted`, 'success');
        await loadPlans();
        if (!loadedPlans.length) {
            closePlansManageModal();
        }
    } catch (err) {
        showToast(err.message || 'Delete failed', 'error');
        renderPlansManageList();
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
        if (typeof data.hold_controller === 'boolean') {
            applyControllerStateFromStatus(data);
            updateControllerTile();
        }
        showToast(`Sent to ${targetLabel}`, 'success');
        noteCommandQuiet();
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

function settingsHashPane() {
    const hash = (location.hash || '').replace(/^#/, '');
    if (hash === 'settings') return 'connection';
    if (hash.startsWith('settings/')) {
        const pane = hash.slice('settings/'.length);
        return SETTINGS_PANES.includes(pane) ? pane : 'connection';
    }
    return null;
}

function showSettingsPane(pane, { updateHash = true } = {}) {
    let id = SETTINGS_PANES.includes(pane) ? pane : 'connection';
    const section = document.querySelector(`[data-settings-pane="${id}"]`);
    if (section?.classList.contains('hidden')) {
        id = 'modules';
    }
    document.querySelectorAll('[data-settings-pane]').forEach((el) => {
        el.classList.toggle('is-active', el.getAttribute('data-settings-pane') === id);
    });
    document.querySelectorAll('[data-settings-nav]').forEach((el) => {
        el.classList.toggle('is-active', el.getAttribute('data-settings-nav') === id);
    });
    if (updateHash && settingsModalOpen) {
        const next = id === 'connection' ? '#settings' : `#settings/${id}`;
        if (location.hash !== next) {
            history.replaceState(null, '', `${location.pathname}${location.search}${next}`);
        }
    }
}

function openSettingsModal(pane) {
    if (!els.settingsModal) return;
    const alreadyOpen = settingsModalOpen;
    settingsModalOpen = true;
    if (statusAbort) {
        statusAbort.abort();
        statusAbort = null;
        polling = false;
    }
    els.settingsModal.classList.remove('hidden');
    document.body.classList.add('settings-page-open');
    if (els.settingsOpen) {
        els.settingsOpen.textContent = 'Dashboard';
        els.settingsOpen.setAttribute('aria-expanded', 'true');
    }
    if (!alreadyOpen) {
        setCloudTestResult(null);
        setConnectionTestResult(null);
        setUpdateResult(null);
        loadSettings();
        loadPaperMonoDashboard();
        loadVestaboardPreview();
        if (lastUpdateStatus) {
            applyUpdateAvailability(lastUpdateStatus);
        }
        loadUpdateStatus();
    }
    const hashPane = typeof pane === 'string' ? pane : settingsHashPane();
    const start = hashPane
        || (isUpdateAvailable(lastUpdateStatus) ? 'updates' : 'connection');
    showSettingsPane(start);
    if (!alreadyOpen) {
        els.settingsHost?.focus();
    }
}

function closeSettingsModal() {
    if (!els.settingsModal) return;
    els.settingsModal.classList.add('hidden');
    document.body.classList.remove('settings-page-open');
    settingsModalOpen = false;
    if (els.settingsOpen) {
        els.settingsOpen.textContent = 'Settings';
        els.settingsOpen.setAttribute('aria-expanded', 'false');
    }
    setSettingsError(null);
    setCloudTestResult(null);
    setConnectionTestResult(null);
    setUpdateResult(null);
    if ((location.hash || '').startsWith('#settings')) {
        history.replaceState(null, '', `${location.pathname}${location.search}`);
    }
}

async function loadCloudStatusHint() {
    if (!els.settingsCloudStatus) return;
    try {
        const res = await fetch('/api/cloud.php');
        const data = await parseJsonResponse(res);
        if (data.status) {
            els.settingsCloudStatus.textContent = formatCloudStatus(data.status);
        }
    } catch {
        // Keep previous hint; cloud status is optional
    }
}

async function loadSettings() {
    setSettingsError(null);
    try {
        const res = await fetch('/api/settings.php');
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Could not load settings');
        if (els.settingsHost) els.settingsHost.value = data.broker_host || '';
        if (els.settingsSerial) els.settingsSerial.value = data.serial || '';
        if (els.settingsHouseName) els.settingsHouseName.value = data.hub?.house_name || '';
        applyPanelTitle(data.hub);
        if (els.settingsRobotName) els.settingsRobotName.value = data.robot_name || '';
        if (els.settingsCloudEnabled) els.settingsCloudEnabled.checked = Boolean(data.cloud?.cloud_enabled);
        if (els.settingsCloudEmail) els.settingsCloudEmail.value = data.cloud?.cloud_email || '';
        if (els.settingsCloudPassword) els.settingsCloudPassword.value = '';
        if (els.settingsDataSource) {
            defaultDataSource = data.cloud?.data_source || 'auto';
            els.settingsDataSource.value = defaultDataSource;
            if (els.mapDataSource) els.mapDataSource.value = defaultDataSource;
            if (els.plansDataSource) els.plansDataSource.value = defaultDataSource;
        }
        if (els.settingsVestaboardEnabled) {
            els.settingsVestaboardEnabled.checked = Boolean(data.vestaboard?.enabled);
        }
        setVestaboardTransport(data.vestaboard?.transport === 'cloud' ? 'cloud' : 'local');
        if (els.settingsVestaboardHost) {
            els.settingsVestaboardHost.value = data.vestaboard?.host || 'vestaboard.local';
        }
        if (els.settingsVestaboardKey) els.settingsVestaboardKey.value = '';
        if (els.settingsVestaboardCloudToken) els.settingsVestaboardCloudToken.value = '';
        if (els.settingsVestaboardQuiet) {
            els.settingsVestaboardQuiet.checked = Boolean(data.vestaboard?.quiet_hours_enabled);
        }
        if (els.settingsVestaboardQuietStart) {
            els.settingsVestaboardQuietStart.value = data.vestaboard?.quiet_start || '22:00';
        }
        if (els.settingsVestaboardQuietEnd) {
            els.settingsVestaboardQuietEnd.value = data.vestaboard?.quiet_end || '07:00';
        }
        setQuietCodes(data.vestaboard?.quiet_codes);
        updateQuietHoursClockHint(data.vestaboard);
        if (els.settingsRainSensitivity) {
            const n = data.rain?.sensitivity;
            els.settingsRainSensitivity.value = n != null ? String(n) : '';
        }
        if (els.settingsModuleYarbo) {
            els.settingsModuleYarbo.checked = data.hub?.modules?.yarbo !== false;
        }
        if (els.settingsModulePowerwall) {
            els.settingsModulePowerwall.checked = Boolean(data.hub?.modules?.powerwall);
        }
        if (els.settingsModuleLymow) {
            els.settingsModuleLymow.checked = Boolean(data.hub?.modules?.lymow);
        }
        if (els.settingsVestaboardLive) {
            els.settingsVestaboardLive.value = data.hub?.vestaboard_live || 'yarbo';
        }
        applyCompanionSettingsVisibility();
        const pw = data.powerwall || {};
        document.querySelectorAll('input[name="powerwall-transport"]').forEach((radio) => {
            radio.checked = radio.value === (pw.transport || 'cloud');
        });
        applyPowerwallTransport();
        if (els.settingsPowerwallRegion) els.settingsPowerwallRegion.value = pw.region || 'eu';
        if (els.settingsPowerwallPublicUrl) els.settingsPowerwallPublicUrl.value = pw.public_panel_url || '';
        if (els.settingsPowerwallClientId) els.settingsPowerwallClientId.value = pw.client_id || '';
        if (els.settingsPowerwallClientSecret) els.settingsPowerwallClientSecret.value = '';
        if (els.settingsPowerwallRefresh) els.settingsPowerwallRefresh.value = '';
        if (els.settingsPowerwallSite) els.settingsPowerwallSite.value = pw.energy_site_id || '';
        if (els.settingsPowerwallHost) els.settingsPowerwallHost.value = pw.gateway_host || '';
        if (els.settingsPowerwallEmail) els.settingsPowerwallEmail.value = pw.gateway_email || '';
        if (els.settingsPowerwallPassword) els.settingsPowerwallPassword.value = '';
        if (els.settingsPowerwallOauth) {
            if (pw.oauth_url) {
                els.settingsPowerwallOauth.href = pw.oauth_url;
                els.settingsPowerwallOauth.classList.remove('is-disabled');
            } else {
                els.settingsPowerwallOauth.href = '#';
            }
        }
        if (els.settingsLymowHost) els.settingsLymowHost.value = data.lymow?.host || '192.168.40.154';
        if (els.settingsLymowName) {
            const saved = data.lymow?.display_name || '';
            const discovered = data.lymow?.page_name || data.lymow?.device_name || '';
            els.settingsLymowName.value = saved || discovered;
            els.settingsLymowName.placeholder = discovered || 'e.g. Front lawn';
        }
        if (els.settingsLymowEmail) els.settingsLymowEmail.value = data.lymow?.email || '';
        if (els.settingsLymowPassword) els.settingsLymowPassword.value = '';
        if (els.settingsLymowRegion) els.settingsLymowRegion.value = data.lymow?.region || 'auto';
        applyVestaboardEnabled();
        if (els.settingsCloudStatus) {
            if (data.cloud_status) {
                els.settingsCloudStatus.textContent = formatCloudStatus(data.cloud_status);
            } else {
                els.settingsCloudStatus.textContent = 'Cloud bridge: checking…';
                loadCloudStatusHint();
            }
        }
        if (!data.writable) {
            setSettingsError('config.php is not writable on the server.');
        }
    } catch (err) {
        setSettingsError(err.message || 'Could not load settings');
    }
}

function setPaperMonoResult(message, type) {
    if (!els.papermonoResult) return;
    if (!message) {
        els.papermonoResult.textContent = '';
        els.papermonoResult.className = 'settings-cloud-result hidden';
        return;
    }
    els.papermonoResult.textContent = message;
    els.papermonoResult.className = `settings-cloud-result ${type || ''}`.trim();
    els.papermonoResult.classList.remove('hidden');
    els.papermonoResult.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function paperMonoSelectedKind() {
    const checked = document.querySelector('input[name="papermono-kind"]:checked');
    return checked?.value === 'papercolor' ? 'papercolor' : 'papermono';
}

function paperMonoLabel(kind = paperMonoSelectedKind()) {
    return kind === 'papercolor' ? 'Paper Colour' : 'PaperMono';
}

function paperMonoDefaultName(kind = paperMonoSelectedKind()) {
    return paperMonoLabel(kind);
}

function applyPaperMonoKindUi(dashboard) {
    const kind = paperMonoSelectedKind();
    const label = paperMonoLabel(kind);
    const fw = dashboard?.firmware?.[kind];
    const nameEl = els.papermonoName;
    if (nameEl) {
        const current = nameEl.value.trim();
        if (current === '' || current === 'PaperMono' || current === 'Paper Colour') {
            nameEl.value = paperMonoDefaultName(kind);
        }
    }
    document.getElementById('papermono-preview-grid')?.classList.toggle('hidden', kind === 'papercolor');
    document.getElementById('papercolor-preview-grid')?.classList.toggle('hidden', kind !== 'papercolor');
    document.getElementById('papermono-color-extra')?.classList.toggle('hidden', kind !== 'papercolor');
    document.querySelectorAll('.papermono-kind-card').forEach((card) => {
        const input = card.querySelector('input[name="papermono-kind"]');
        card.classList.toggle('is-active', input?.value === kind);
    });
    if (els.papermonoFlash) {
        els.papermonoFlash.textContent = kind === 'papercolor'
            ? 'Flash Paper Colour firmware & send Wi-Fi'
            : 'Flash PaperMono firmware & send Wi-Fi';
    }
    if (els.papermonoBuild) {
        els.papermonoBuild.textContent = `Build ${label} firmware`;
    }
    if (els.papermonoFwStatus) {
        if (fw) {
            let built = 'not built yet — click Build firmware';
            if (fw.built && fw.needs_build) {
                built = 'built, but source is newer — click Build firmware';
            } else if (fw.built) {
                built = 'built on this host';
            }
            els.papermonoFwStatus.textContent = `${label} firmware ${fw.version} (${built}).`;
        } else if (dashboard) {
            els.papermonoFwStatus.textContent = `Could not read ${label} firmware status.`;
        }
    }
    const hint = document.getElementById('papermono-flash-hint');
    if (hint) {
        const extra = kind === 'papercolor'
            ? ' Paper Colour is Spectra 6: A/B change pages, C locks. It is slow — do not expect 15-second redraws.'
            : ' The firmware keeps the SSD1677 healthy: full refresh every 10 partials, no redraw when nothing changed, 15s poll.';
        hint.innerHTML = `Leave this Settings page open. Click <strong>Build firmware</strong> for this tablet (first build can take several minutes and installs PlatformIO if needed). <strong>Flash</strong> builds automatically if the binary is missing or stale, then sends Wi-Fi over USB. If the port list fails, click <strong>Install USB tools</strong>.${extra} Keep the tablet out of direct sun.`;
    }
    applyPaperPreviewModules();
}

function applyPaperPreviewModules() {
    const yarbo = Boolean(els.settingsModuleYarbo?.checked);
    const pw = Boolean(els.settingsModulePowerwall?.checked);
    const ly = Boolean(els.settingsModuleLymow?.checked);
    document.querySelectorAll('[data-preview-for]').forEach((fig) => {
        const kind = fig.getAttribute('data-preview-for');
        let show = true;
        if (kind === 'yarbo') show = yarbo;
        else if (kind === 'lymow') show = ly;
        else if (kind === 'powerwall') show = pw;
        fig.classList.toggle('hidden', !show);
    });
    refreshPaperLockPreview();
}

function fillPaperLockBoards() {
    const source = els.settingsVestaboardPreview?.innerHTML || '';
    document.querySelectorAll('[data-lock-board]').forEach((el) => {
        el.innerHTML = source;
    });
}

function lockPreviewName(kind) {
    const generic = paperMonoDefaultName(kind);
    const row = [...document.querySelectorAll('#papermono-devices .papermono-device-row')]
        .find((el) => (el.querySelector('[data-papermono-kind]')?.getAttribute('data-papermono-kind') || 'papermono') === kind);
    const paired = row?.querySelector('[data-papermono-name]')?.value.trim();
    if (paired) return paired;
    if (paperMonoSelectedKind() === kind) {
        const typed = els.papermonoName?.value.trim() || '';
        if (typed !== '' && typed !== 'PaperMono' && typed !== 'Paper Colour') {
            return typed;
        }
        if (typed) return typed;
    }
    return generic;
}

function refreshPaperLockPreview() {
    document.querySelectorAll('.paper-lock-mock--mono [data-lock-name]').forEach((el) => {
        el.textContent = lockPreviewName('papermono');
    });
    document.querySelectorAll('.paper-lock-mock--color [data-lock-name]').forEach((el) => {
        el.textContent = lockPreviewName('papercolor');
    });
    const lock = els.papermonoLockScreen?.value || 'logo';
    const vestaboardOn = Boolean(els.settingsVestaboardEnabled?.checked);
    const showLogo = lock === 'logo' || lock === 'both';
    const showBoard = vestaboardOn && (lock === 'vestaboard' || lock === 'both');
    document.querySelectorAll('.paper-lock-logo').forEach((el) => {
        el.classList.toggle('hidden', !showLogo);
    });
    document.querySelectorAll('[data-lock-board]').forEach((el) => {
        el.classList.toggle('hidden', !showBoard);
    });
    fillPaperLockBoards();
    const clock = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    document.querySelectorAll('.paper-lock-clock').forEach((el) => {
        el.textContent = clock;
    });
}

function setPaperLogoResult(message, type) {
    if (!els.papermonoLogoResult) return;
    if (!message) {
        els.papermonoLogoResult.textContent = '';
        els.papermonoLogoResult.className = 'settings-cloud-result hidden';
        return;
    }
    els.papermonoLogoResult.textContent = message;
    els.papermonoLogoResult.className = `settings-cloud-result ${type || ''}`.trim();
    els.papermonoLogoResult.classList.remove('hidden');
}

function applyPaperLogoPreview(url) {
    const href = url || '';
    document.querySelectorAll('.paper-logo-preview').forEach((el) => {
        if (el.tagName === 'IMG') {
            if (href) el.src = href;
            else el.removeAttribute('src');
            el.classList.toggle('is-empty', !href);
            return;
        }
        if (href) {
            el.setAttribute('href', href);
            el.setAttributeNS('http://www.w3.org/1999/xlink', 'href', href);
        } else {
            el.removeAttribute('href');
            el.removeAttributeNS('http://www.w3.org/1999/xlink', 'href');
        }
    });
    if (els.papermonoLogoThumb) {
        if (href) {
            els.papermonoLogoThumb.src = href;
            els.papermonoLogoThumb.classList.remove('hidden');
        } else {
            els.papermonoLogoThumb.removeAttribute('src');
            els.papermonoLogoThumb.classList.add('hidden');
        }
    }
}

async function uploadPaperLogo(file) {
    if (!file) return;
    setPaperLogoResult('Saving logo…');
    const body = new FormData();
    body.append('action', 'logo_upload');
    body.append('logo', file);
    try {
        const res = await fetch('/api/device.php', { method: 'POST', body });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Could not save the logo');
        applyPaperLogoPreview(data.logo_url || null);
        setPaperLogoResult(data.message || 'Logo saved.', 'success');
        showToast('Logo saved', 'success');
        if (els.papermonoLogo) els.papermonoLogo.value = '';
    } catch (err) {
        setPaperLogoResult(err.message || 'Could not save the logo', 'error');
    }
}

async function clearPaperLogo() {
    setPaperLogoResult('Removing logo…');
    try {
        const res = await fetch('/api/device.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'logo_clear' }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Could not remove the logo');
        applyPaperLogoPreview(null);
        setPaperLogoResult(data.message || 'Logo removed.', 'success');
        if (els.papermonoLogo) els.papermonoLogo.value = '';
    } catch (err) {
        setPaperLogoResult(err.message || 'Could not remove the logo', 'error');
    }
}

function paperMonoFormPayload() {
    const kind = paperMonoSelectedKind();
    return {
        kind,
        port: els.papermonoPort?.value.trim() ?? '',
        wifi_ssid: els.papermonoSsid?.value.trim() ?? '',
        wifi_password: els.papermonoWifiPassword?.value ?? '',
        panel_url: els.papermonoPanelUrl?.value.trim() ?? '',
        name: els.papermonoName?.value.trim() || paperMonoDefaultName(kind),
    };
}

function paperOtaUpdateButton(device) {
    const id = escapeHtml(device.id || '');
    if (device.ota_pending) {
        return `<button type="button" class="btn btn-compact" disabled>Updating…</button>`;
    }
    if (!device.ota_available) {
        return '';
    }
    if (!device.online) {
        return `<button type="button" class="btn btn-secondary btn-compact" disabled title="Tablet not seen recently">Update</button>`;
    }
    return `<button type="button" class="btn btn-compact" data-papermono-ota="${id}">Update</button>`;
}

function bindPaperOtaButtons(root) {
    if (!root) return;
    root.querySelectorAll('[data-papermono-ota]').forEach((button) => {
        button.addEventListener('click', () => queuePaperOta(button.dataset.papermonoOta, button));
    });
}

function renderPaperOtaPanel(devices) {
    if (!els.settingsPaperOtaList) return;
    if (!Array.isArray(devices) || devices.length === 0) {
        els.settingsPaperOtaList.innerHTML = '<p class="hint">No paired tablets yet. Flash over USB once, then updates can go over Wi-Fi.</p>';
        if (els.settingsPaperOtaAll) els.settingsPaperOtaAll.disabled = true;
        return;
    }
    const ready = devices.filter((d) => d.ota_available && d.online && !d.ota_pending);
    els.settingsPaperOtaList.innerHTML = devices.map((device) => {
        const last = device.last_seen_at
            ? `Last seen ${escapeHtml(String(device.last_seen_at).replace('T', ' ').replace('Z', ' UTC'))}`
            : 'Never seen';
        const reported = device.fw_reported ? escapeHtml(String(device.fw_reported)) : 'unknown';
        const latest = escapeHtml(String(device.firmware_latest || ''));
        const status = device.ota_pending
            ? 'Update queued'
            : (device.online ? 'Online' : 'Offline');
        return `<div class="papermono-device-row">
            <div class="papermono-device-meta">
                <p class="papermono-device-ota-name">${escapeHtml(device.name || device.kind_label || 'Tablet')}</p>
                <p class="hint">${escapeHtml(device.kind_label || '')} · ${escapeHtml(status)} · fw ${reported} → ${latest} · ${escapeHtml(last)}</p>
            </div>
            <div class="papermono-device-actions">${paperOtaUpdateButton(device)}</div>
        </div>`;
    }).join('');
    bindPaperOtaButtons(els.settingsPaperOtaList);
    if (els.settingsPaperOtaAll) {
        els.settingsPaperOtaAll.disabled = ready.length === 0;
    }
}

function renderPaperMonoDevices(devices) {
    if (!els.papermonoDevices) return;
    if (!Array.isArray(devices) || devices.length === 0) {
        els.papermonoDevices.innerHTML = '<p class="hint">None yet. Flash a tablet to pair it.</p>';
        renderPaperOtaPanel(devices);
        return;
    }
    els.papermonoDevices.innerHTML = devices.map((device) => {
        const last = device.last_seen_at
            ? `Last seen ${escapeHtml(String(device.last_seen_at).replace('T', ' ').replace('Z', ' UTC'))}`
            : 'Never seen';
        const kindLabel = device.kind_label ? `${escapeHtml(String(device.kind_label))} · ` : '';
        const fw = device.fw_reported ? ` · fw ${escapeHtml(String(device.fw_reported))}` : '';
        const online = device.online ? 'online' : 'offline';
        const revokeLabel = device.kind_label || device.name || 'companion';
        return `<div class="papermono-device-row">
            <div class="papermono-device-meta">
                <label class="settings-field papermono-device-name-field">
                    <span class="label">Tablet name</span>
                    <input type="text" maxlength="40" value="${escapeHtml(device.name || 'PaperMono')}" data-papermono-name="${escapeHtml(device.id)}" data-papermono-kind="${escapeHtml(device.kind || 'papermono')}">
                </label>
                <p class="hint">${kindLabel}${escapeHtml(online)} · ${escapeHtml(last)}${fw}</p>
            </div>
            <div class="papermono-device-actions">
                ${paperOtaUpdateButton(device)}
                <button type="button" class="btn btn-secondary btn-compact" data-papermono-rename="${escapeHtml(device.id)}">Save name</button>
                <button type="button" class="btn btn-secondary btn-compact" data-papermono-revoke="${escapeHtml(device.id)}" data-papermono-revoke-label="${escapeHtml(String(revokeLabel))}">Revoke</button>
            </div>
        </div>`;
    }).join('');
    els.papermonoDevices.querySelectorAll('[data-papermono-revoke]').forEach((button) => {
        button.addEventListener('click', () => revokePaperMono(button.dataset.papermonoRevoke, button));
    });
    els.papermonoDevices.querySelectorAll('[data-papermono-rename]').forEach((button) => {
        button.addEventListener('click', () => renamePaperMono(button.dataset.papermonoRename, button));
    });
    els.papermonoDevices.querySelectorAll('[data-papermono-name]').forEach((input) => {
        input.addEventListener('input', () => refreshPaperLockPreview());
    });
    bindPaperOtaButtons(els.papermonoDevices);
    refreshPaperLockPreview();
    renderPaperOtaPanel(devices);
}

async function queuePaperOta(id, button) {
    const all = id === '*';
    const label = all ? 'all online tablets' : 'this tablet';
    if (!window.confirm(`Push firmware to ${label}? The tablet stays on Wi-Fi, shows UPDATING, then reboots. PaperMono beeps and lights green.`)) {
        return;
    }
    if (button) button.disabled = true;
    if (els.settingsPaperOtaResult) {
        els.settingsPaperOtaResult.textContent = 'Queuing update…';
        els.settingsPaperOtaResult.className = 'settings-cloud-result';
        els.settingsPaperOtaResult.classList.remove('hidden');
    }
    try {
        const res = await fetch('/api/device.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'ota', id: id || '*' }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Could not queue the update');
        showToast(data.message || 'Update queued', 'success');
        if (els.settingsPaperOtaResult) {
            els.settingsPaperOtaResult.textContent = data.message || 'Update queued.';
            els.settingsPaperOtaResult.className = 'settings-cloud-result success';
        }
        loadPaperMonoDashboard();
    } catch (err) {
        showToast(err.message || 'Could not queue the update', 'error');
        if (els.settingsPaperOtaResult) {
            els.settingsPaperOtaResult.textContent = err.message || 'Could not queue the update';
            els.settingsPaperOtaResult.className = 'settings-cloud-result error';
        }
        if (button) button.disabled = false;
    }
}

let paperMonoDashboardCache = null;

async function loadPaperMonoDashboard() {
    if (els.papermonoPanelUrl && !els.papermonoPanelUrl.value) {
        els.papermonoPanelUrl.value = window.location.origin;
    }
    try {
        const res = await fetch('/api/device.php?action=dashboard');
        const data = await parseJsonResponse(res);
        paperMonoDashboardCache = data;
        applyPaperMonoKindUi(data);
        applyPaperLogoPreview(data.logo_url || null);
        applyPaperMonoPrefs(data.prefs);
        renderPaperMonoDevices(data.devices);
    } catch (err) {
        if (els.papermonoFwStatus) {
            els.papermonoFwStatus.textContent = err.message || 'Could not load e-paper companion status.';
        }
    }
    refreshPaperMonoPorts();
}

async function refreshPaperMonoPorts() {
    if (!els.papermonoPort) return { ok: false, count: 0 };
    const previous = els.papermonoPort.value;
    const label = paperMonoLabel();
    try {
        const res = await fetch('/api/device.php?action=ports');
        const data = await parseJsonResponse(res);
        const ports = Array.isArray(data.ports) ? data.ports : [];
        if (!data.ok) {
            els.papermonoPort.innerHTML = '<option value="">Could not list USB ports</option>';
            const missing = data.needs_usb_tools
                ? `${data.error || 'USB serial tools are missing.'} Click Install USB tools.`
                : (data.error || 'Could not list USB ports');
            setPaperMonoResult(missing, 'error');
            return { ok: false, count: 0 };
        }
        if (els.papermonoResult?.classList.contains('error')) {
            setPaperMonoResult('');
        }
        if (ports.length === 0) {
            els.papermonoPort.innerHTML = `<option value="">No USB serial ports found — plug in the ${label} and refresh</option>`;
            return { ok: true, count: 0 };
        }
        els.papermonoPort.innerHTML = ports.map((port) => {
            const device = port.device || '';
            const portLabel = port.label || device;
            return `<option value="${escapeHtml(device)}">${escapeHtml(portLabel)}</option>`;
        }).join('');
        if (previous && ports.some((port) => port.device === previous)) {
            els.papermonoPort.value = previous;
        }
        return { ok: true, count: ports.length };
    } catch (err) {
        els.papermonoPort.innerHTML = '<option value="">Could not list USB ports</option>';
        setPaperMonoResult(err.message || 'Could not list USB ports', 'error');
        return { ok: false, count: 0 };
    }
}

async function installPaperMonoUsbTools(button) {
    if (button) button.disabled = true;
    if (els.papermonoPortsRefresh) els.papermonoPortsRefresh.disabled = true;
    const label = paperMonoLabel();
    setPaperMonoResult('Installing pyserial and esptool into this panel’s Python environment. This can take a minute…');
    try {
        const res = await fetch('/api/device.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'install_usb_tools' }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) {
            const extra = typeof data.log === 'string' && data.log.trim() !== ''
                ? `\n${data.log.trim().slice(-400)}`
                : '';
            throw new Error((data.error || 'Could not install USB tools') + extra);
        }
        setPaperMonoResult('Installed pyserial and esptool. Refreshing USB ports…', 'success');
        showToast('USB tools installed', 'success');
        const listed = await refreshPaperMonoPorts();
        if (els.papermonoResult && !els.papermonoResult.classList.contains('error')) {
            setPaperMonoResult(
                listed?.count
                    ? 'Installed pyserial and esptool. USB ports are ready.'
                    : `Installed pyserial and esptool. Plug the ${label} in over USB, then click Refresh USB ports.`,
                'success',
            );
        }
    } catch (err) {
        setPaperMonoResult(err.message || 'Could not install USB tools', 'error');
    } finally {
        if (button) button.disabled = false;
        if (els.papermonoPortsRefresh) els.papermonoPortsRefresh.disabled = false;
    }
}

async function buildPaperMonoFirmware(button) {
    const kind = paperMonoSelectedKind();
    const label = paperMonoLabel(kind);
    if (button) button.disabled = true;
    if (els.papermonoFlash) els.papermonoFlash.disabled = true;
    setPaperMonoResult(`Building ${label} firmware on this host. First time can take several minutes (PlatformIO and the ESP32 toolchain)…`);
    try {
        const res = await fetch('/api/device.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'build_firmware', kind }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) {
            const extra = typeof data.log === 'string' && data.log.trim() !== ''
                ? `\n${data.log.trim().slice(-600)}`
                : '';
            throw new Error((data.error || 'Could not build firmware') + extra);
        }
        paperMonoDashboardCache = data;
        applyPaperMonoKindUi(data);
        setPaperMonoResult(data.message || `${label} firmware is built.`, 'success');
        showToast(`${label} firmware built`, 'success');
    } catch (err) {
        setPaperMonoResult(err.message || 'Could not build firmware', 'error');
    } finally {
        if (button) button.disabled = false;
        if (els.papermonoFlash) els.papermonoFlash.disabled = false;
    }
}

async function runPaperMonoUsb(action, button) {
    const payload = paperMonoFormPayload();
    const label = paperMonoLabel(payload.kind);
    if (!payload.port) {
        setPaperMonoResult(`Select the USB serial port for the ${label}.`, 'error');
        return;
    }
    if (!payload.wifi_ssid) {
        setPaperMonoResult(`Wi-Fi name (SSID) is required. ${label} is 2.4 GHz only.`, 'error');
        return;
    }
    if (!payload.panel_url) {
        setPaperMonoResult(`Panel URL is required so the ${label} can reach this server.`, 'error');
        return;
    }
    if (button) button.disabled = true;
    if (els.papermonoBuild) els.papermonoBuild.disabled = true;
    setPaperMonoResult(
        action === 'flash'
            ? `Building if needed, then flashing ${label} over USB and sending Wi-Fi. Leave this page open…`
            : 'Sending Wi-Fi and panel URL over USB…',
    );
    try {
        const res = await fetch('/api/device.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...payload }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) {
            const extra = typeof data.log === 'string' && data.log.trim() !== ''
                ? `\n${data.log.trim().slice(-600)}`
                : '';
            throw new Error((data.error || 'USB step failed') + extra);
        }
        const tokenHint = data.device?.token
            ? `\nPaired as ${data.device.name || label}. Keep that cable in until the setup screen clears.`
            : '';
        const builtHint = action === 'flash' && data.built
            ? ' Firmware was built first, then flashed.'
            : '';
        setPaperMonoResult(
            action === 'flash'
                ? `${label} firmware flashed and Wi-Fi sent.${builtHint}${tokenHint}`
                : `Wi-Fi sent.${tokenHint}`,
            'success',
        );
        showToast(action === 'flash' ? `${label} flashed` : `${label} Wi-Fi sent`, 'success');
        loadPaperMonoDashboard();
    } catch (err) {
        setPaperMonoResult(err.message || 'USB step failed', 'error');
    } finally {
        if (button) button.disabled = false;
        if (els.papermonoBuild) els.papermonoBuild.disabled = false;
    }
}

function applyPaperMonoPrefs(prefs) {
    if (!prefs || typeof prefs !== 'object') return;
    if (els.papermonoLockScreen) els.papermonoLockScreen.value = prefs.lock_screen || 'logo';
    if (els.papermonoLockAfter) els.papermonoLockAfter.value = String(prefs.lock_after_s ?? 60);
    if (els.papermonoLightOff) els.papermonoLightOff.value = String(prefs.light_off_s ?? 15);
    if (els.papermonoBrightness) els.papermonoBrightness.value = String(prefs.brightness ?? 80);
    if (els.papermonoAlertMessage) els.papermonoAlertMessage.checked = prefs.alert_message !== false;
    if (els.papermonoAlertYarbo) els.papermonoAlertYarbo.checked = prefs.alert_yarbo !== false;
    if (els.papermonoAlertLymow) els.papermonoAlertLymow.checked = prefs.alert_lymow !== false;
    if (els.papermonoAlertPowerwall) els.papermonoAlertPowerwall.checked = prefs.alert_powerwall !== false;
    applyPaperPreviewModules();
}

function paperMonoPrefsPayload() {
    return {
        action: 'prefs',
        lock_screen: els.papermonoLockScreen?.value || 'logo',
        lock_after_s: Number(els.papermonoLockAfter?.value || 60),
        light_off_s: Number(els.papermonoLightOff?.value || 15),
        brightness: Number(els.papermonoBrightness?.value || 80),
        alert_message: !!els.papermonoAlertMessage?.checked,
        alert_yarbo: !!els.papermonoAlertYarbo?.checked,
        alert_lymow: !!els.papermonoAlertLymow?.checked,
        alert_powerwall: !!els.papermonoAlertPowerwall?.checked,
    };
}

function setPaperMonoPrefsResult(message, type) {
    if (!els.papermonoPrefsResult) return;
    if (!message) {
        els.papermonoPrefsResult.textContent = '';
        els.papermonoPrefsResult.className = 'settings-cloud-result hidden';
        return;
    }
    els.papermonoPrefsResult.textContent = message;
    els.papermonoPrefsResult.className = `settings-cloud-result ${type || ''}`.trim();
    els.papermonoPrefsResult.classList.remove('hidden');
}

async function savePaperMonoPrefs(button) {
    if (button) button.disabled = true;
    setPaperMonoPrefsResult('');
    try {
        const res = await fetch('/api/device.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(paperMonoPrefsPayload()),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Could not save companion settings');
        applyPaperMonoPrefs(data.prefs);
        setPaperMonoPrefsResult('Companion settings saved. Tablets pick them up on the next poll.', 'success');
        showToast('Companion settings saved', 'success');
    } catch (err) {
        setPaperMonoPrefsResult(err.message || 'Could not save companion settings', 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

async function renamePaperMono(id, button) {
    if (!id || !els.papermonoDevices) return;
    const input = els.papermonoDevices.querySelector(`[data-papermono-name="${id}"]`);
    const name = input?.value.trim() || '';
    if (name === '') {
        setPaperMonoResult('Give the tablet a name', 'error');
        return;
    }
    if (button) button.disabled = true;
    try {
        const res = await fetch('/api/device.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'rename', id, name }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Could not rename');
        showToast(`Renamed to ${data.device?.name || name}`, 'success');
        loadPaperMonoDashboard();
    } catch (err) {
        setPaperMonoResult(err.message || 'Could not rename', 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

async function revokePaperMono(id, button) {
    if (!id) return;
    const label = button?.dataset?.papermonoRevokeLabel || 'companion';
    if (!window.confirm(`Revoke this ${label}? It will stop receiving status until you flash or pair it again.`)) {
        return;
    }
    if (button) button.disabled = true;
    try {
        const res = await fetch('/api/device.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'revoke', id }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Revoke failed');
        showToast(`${label} revoked`, 'success');
        loadPaperMonoDashboard();
    } catch (err) {
        setPaperMonoResult(err.message || 'Revoke failed', 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

function applyVestaboardEnabled() {
    const on = Boolean(els.settingsVestaboardEnabled?.checked);
    els.settingsVestaboardFields?.classList.toggle('hidden', !on);
    if (on) {
        applyVestaboardTransport();
        applyVestaboardQuietHours();
        renderQuietBoard();
    }
}

function applyVestaboardQuietHours() {
    const on = Boolean(els.settingsVestaboardQuiet?.checked);
    els.settingsVestaboardQuietFields?.classList.toggle('hidden', !on);
    if (on) renderQuietBoard();
}

const DEFAULT_QUIET_CODES = [
    [7, 15, 15, 4, 0, 14, 9, 7, 8, 20, 0, 0, 0, 0, 0],
    [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
    [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
];
const VESTABOARD_COLOR_CLASS = {
    63: 'red',
    64: 'orange',
    65: 'yellow',
    66: 'green',
    67: 'blue',
    68: 'violet',
    69: 'white',
};
let quietCodes = DEFAULT_QUIET_CODES.map((row) => row.slice());
let quietCell = { r: 0, c: 0 };

function cloneQuietCodes(raw) {
    const fallback = DEFAULT_QUIET_CODES.map((row) => row.slice());
    if (!Array.isArray(raw) || raw.length < 3) return fallback;
    return [0, 1, 2].map((r) => {
        const row = Array.isArray(raw[r]) ? raw[r] : [];
        return Array.from({ length: 15 }, (_, c) => {
            const n = Number(row[c]);
            return Number.isInteger(n) && n >= 0 && n <= 69 ? n : 0;
        });
    });
}

function setQuietCodes(raw) {
    quietCodes = cloneQuietCodes(raw);
    renderQuietBoard();
}

function quietCodesSnapshot() {
    return quietCodes.map((row) => row.slice());
}

function vestaboardCharToCode(ch) {
    if (!ch) return 0;
    const up = ch.toUpperCase();
    if (up === ' ') return 0;
    const ord = up.charCodeAt(0);
    if (ord >= 65 && ord <= 90) return ord - 64;
    if (up === '0') return 36;
    if (ord >= 49 && ord <= 57) return ord - 22;
    const extra = {
        '!': 37, '@': 38, '#': 39, '$': 40, '(': 41, ')': 42, '-': 44, '+': 46,
        '&': 47, '=': 48, ';': 49, ':': 50, "'": 52, '"': 53, '%': 54, ',': 55,
        '.': 56, '/': 59, '?': 60,
    };
    return extra[up] ?? extra[ch] ?? null;
}

function vestaboardCodeToChar(code) {
    if (code >= 1 && code <= 26) return String.fromCharCode(64 + code);
    if (code >= 27 && code <= 35) return String.fromCharCode(code + 22);
    if (code === 36) return '0';
    const extra = {
        37: '!', 38: '@', 39: '#', 40: '$', 41: '(', 42: ')', 44: '-', 46: '+',
        47: '&', 48: '=', 49: ';', 50: ':', 52: "'", 53: '"', 54: '%', 55: ',',
        56: '.', 59: '/', 60: '?',
    };
    return extra[code] || '';
}

function advanceQuietCell() {
    quietCell.c += 1;
    if (quietCell.c >= 15) {
        quietCell.c = 0;
        quietCell.r = (quietCell.r + 1) % 3;
    }
}

function setQuietCellCode(code, advance) {
    quietCodes[quietCell.r][quietCell.c] = code;
    if (advance) advanceQuietCell();
    renderQuietBoard();
}

function renderQuietBoard() {
    const root = els.settingsVestaboardQuietBoard;
    if (!root) return;
    const colorClass = VESTABOARD_COLOR_CLASS;
    const cells = [];
    for (let r = 0; r < 3; r += 1) {
        for (let c = 0; c < 15; c += 1) {
            const code = quietCodes[r][c];
            const color = colorClass[code];
            const sel = r === quietCell.r && c === quietCell.c ? ' is-selected' : '';
            if (color) {
                cells.push(`<span class="vestaboard-cell vestaboard-cell--${color}${sel}" data-quiet-r="${r}" data-quiet-c="${c}"></span>`);
            } else {
                cells.push(`<span class="vestaboard-cell${sel}" data-quiet-r="${r}" data-quiet-c="${c}">${escapeHtml(vestaboardCodeToChar(code))}</span>`);
            }
        }
    }
    root.innerHTML = cells.join('');
}

function clientTimezone() {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch {
        return '';
    }
}

function clientTimezoneHeaders() {
    const tz = clientTimezone();
    return tz ? { 'X-Client-Timezone': tz } : {};
}

function updateQuietHoursClockHint(board) {
    const hint = document.getElementById('settings-vestaboard-quiet-hint');
    if (!hint) return;
    const tz = board?.quiet_timezone || clientTimezone();
    const now = board?.quiet_clock_now;
    const clock = tz && now ? ` Times use ${tz} (now ${now}), not UTC.` : ' Times use your local timezone (not UTC).';
    hint.textContent = 'Stops live status writes overnight so the flaps stay still. At the start of the window the Note shows your quiet message once; live status resumes at the end, with no browser open.'
        + clock
        + ' A custom message from the Vestaboard app during this window stays until quiet hours end (Resume previous status on the dashboard takes the panel back). Separate from Quiet Hours in the Vestaboard app, which can still drop Cloud writes.';
}

function vestaboardTransport() {
    const checked = document.querySelector('input[name="vestaboard-transport"]:checked');
    return checked?.value === 'cloud' ? 'cloud' : 'local';
}

function setVestaboardTransport(value) {
    const next = value === 'cloud' ? 'cloud' : 'local';
    document.querySelectorAll('input[name="vestaboard-transport"]').forEach((radio) => {
        radio.checked = radio.value === next;
    });
    applyVestaboardTransport();
}

function applyVestaboardTransport() {
    const cloud = vestaboardTransport() === 'cloud';
    els.settingsVestaboardLocalFields?.classList.toggle('hidden', cloud);
    els.settingsVestaboardCloudFields?.classList.toggle('hidden', !cloud);
}

function setVestaboardResult(message, type = null) {
    if (!els.settingsVestaboardResult) return;
    if (!message) {
        els.settingsVestaboardResult.textContent = '';
        els.settingsVestaboardResult.className = 'settings-cloud-result hidden';
        return;
    }
    els.settingsVestaboardResult.textContent = message;
    els.settingsVestaboardResult.className = `settings-cloud-result ${type || ''}`.trim();
    els.settingsVestaboardResult.classList.remove('hidden');
}

function renderVestaboardPreview(lines, target, codes) {
    const root = target || els.settingsVestaboardPreview;
    if (!root) return;
    const rows = Array.isArray(lines) ? lines : [];
    const grid = Array.isArray(codes) ? codes : [];
    const colorClass = VESTABOARD_COLOR_CLASS;
    const cells = [];
    for (let r = 0; r < 3; r++) {
        const line = String(rows[r] || '').padEnd(15).slice(0, 15);
        for (let c = 0; c < 15; c++) {
            const code = grid[r] && grid[r][c] != null ? Number(grid[r][c]) : null;
            const color = colorClass[code];
            if (color) {
                cells.push(`<span class="vestaboard-cell vestaboard-cell--${color}" title="${color}"></span>`);
                continue;
            }
            const ch = line[c] === ' ' ? '' : line[c];
            cells.push(`<span class="vestaboard-cell">${escapeHtml(ch)}</span>`);
        }
    }
    root.innerHTML = cells.join('');
}

function vestaboardOverride() {
    const payload = {
        transport: vestaboardTransport(),
        host: els.settingsVestaboardHost?.value.trim() || 'vestaboard.local',
    };
    const key = els.settingsVestaboardKey?.value ?? '';
    if (key !== '') payload.api_key = key;
    const token = els.settingsVestaboardCloudToken?.value ?? '';
    if (token !== '') payload.cloud_token = token;
    return payload;
}

async function loadVestaboardPreview() {
    if (!els.settingsVestaboardPreview) return;
    const sample = els.settingsVestaboardSample?.value || 'live';
    try {
        const res = await fetch(`/api/vestaboard.php?action=preview&sample=${encodeURIComponent(sample)}`);
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Could not preview Vestaboard');
        renderVestaboardPreview(data.lines, els.settingsVestaboardPreview, data.codes);
        fillPaperLockBoards();
        if (els.settingsVestaboardPreviewCaption) {
            const verb = data.verb ? ` ${data.verb}` : '';
            const source = sample === 'live' ? (data.online ? 'live' : 'offline') : 'sample';
            els.settingsVestaboardPreviewCaption.textContent = `YARBO${verb} · ${source} · 3×15 Note`;
        }
    } catch (err) {
        renderVestaboardPreview(['YARBO   OFFLINE', 'BATTERY      --', 'NO TELEMETRY  ']);
        if (els.settingsVestaboardPreviewCaption) {
            els.settingsVestaboardPreviewCaption.textContent = err.message || 'Could not preview Vestaboard';
        }
    }
}

async function testVestaboardConnection(button) {
    if (button) button.disabled = true;
    setVestaboardResult(vestaboardTransport() === 'cloud'
        ? 'Testing Vestaboard Cloud API…'
        : 'Testing Vestaboard Local API…');
    try {
        const res = await fetch('/api/vestaboard.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'test', ...vestaboardOverride() }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Vestaboard test failed');
        setVestaboardResult(data.message || 'Reached the Vestaboard Local API.', 'success');
    } catch (err) {
        setVestaboardResult(err.message || 'Vestaboard test failed', 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

async function sendVestaboardNow(button) {
    if (button) button.disabled = true;
    setVestaboardResult('Sending current status to the Note…');
    try {
        const res = await fetch('/api/vestaboard.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'send', ...vestaboardOverride() }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Send failed');
        const lines = Array.isArray(data.lines) ? data.lines.join('\n') : '';
        setVestaboardResult(lines ? `Sent:\n${lines}` : 'Sent to Vestaboard.', 'success');
        showToast('Vestaboard updated', 'success');
        if (els.settingsVestaboardSample) els.settingsVestaboardSample.value = 'live';
        await loadVestaboardPreview();
    } catch (err) {
        setVestaboardResult(err.message || 'Send failed', 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

async function resumeVestaboardStatus(button) {
    if (button) button.disabled = true;
    try {
        const res = await fetch('/api/vestaboard.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'resume' }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Could not resume previous status');
        showToast('Previous status resumed on Vestaboard', 'success');
        els.vestaboardResume?.classList.add('hidden');
    } catch (err) {
        showToast(err.message || 'Could not resume previous status', 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

async function saveSettings(event) {
    event.preventDefault();
    setSettingsError(null);

    const brokerHost = els.settingsHost?.value.trim() ?? '';
    const serial = els.settingsSerial?.value.trim() ?? '';
    const robotName = els.settingsRobotName?.value.trim() ?? '';
    const cloudEnabled = Boolean(els.settingsCloudEnabled?.checked);
    const cloudEmail = els.settingsCloudEmail?.value.trim() ?? '';
    const cloudPassword = els.settingsCloudPassword?.value ?? '';
    const dataSource = els.settingsDataSource?.value || 'auto';
    const yarboOn = Boolean(els.settingsModuleYarbo?.checked);
    if (yarboOn && (!brokerHost || !serial)) {
        setSettingsError('Broker IP and serial number are required.');
        return;
    }
    if (yarboOn && robotName && looksLikeRobotSerial(robotName, serial)) {
        setSettingsError('Robot name cannot be the serial number.');
        return;
    }

    if (els.settingsSave) els.settingsSave.disabled = true;
    try {
        const payload = {
            broker_host: brokerHost,
            serial,
            robot_name: robotName,
            house_name: els.settingsHouseName?.value.trim() || '',
            cloud_enabled: cloudEnabled,
            cloud_email: cloudEmail,
            data_source: dataSource,
            vestaboard_enabled: Boolean(els.settingsVestaboardEnabled?.checked),
            vestaboard_transport: vestaboardTransport(),
            vestaboard_host: els.settingsVestaboardHost?.value.trim() || 'vestaboard.local',
            vestaboard_quiet_hours: Boolean(els.settingsVestaboardQuiet?.checked),
            vestaboard_quiet_start: els.settingsVestaboardQuietStart?.value || '22:00',
            vestaboard_quiet_end: els.settingsVestaboardQuietEnd?.value || '07:00',
            vestaboard_quiet_codes: quietCodesSnapshot(),
            vestaboard_quiet_timezone: clientTimezone(),
            module_yarbo: Boolean(els.settingsModuleYarbo?.checked),
            module_powerwall: Boolean(els.settingsModulePowerwall?.checked),
            module_lymow: Boolean(els.settingsModuleLymow?.checked),
            vestaboard_live: els.settingsVestaboardLive?.value || 'yarbo',
            powerwall_transport: powerwallTransport(),
            powerwall_region: els.settingsPowerwallRegion?.value || 'eu',
            powerwall_public_url: els.settingsPowerwallPublicUrl?.value.trim() || '',
            powerwall_client_id: els.settingsPowerwallClientId?.value.trim() || '',
            powerwall_energy_site_id: els.settingsPowerwallSite?.value.trim() || '',
            powerwall_gateway_host: els.settingsPowerwallHost?.value.trim() || '',
            powerwall_gateway_email: els.settingsPowerwallEmail?.value.trim() || '',
            lymow_host: els.settingsLymowHost?.value.trim() || '',
            lymow_display_name: els.settingsLymowName?.value.trim() || '',
            lymow_email: els.settingsLymowEmail?.value.trim() || '',
            lymow_region: els.settingsLymowRegion?.value || 'auto',
        };
        const rainRaw = els.settingsRainSensitivity?.value.trim() ?? '';
        payload.rain_sensitivity = rainRaw === '' ? '' : rainRaw;
        if (cloudPassword !== '') {
            payload.cloud_password = cloudPassword;
        }
        const vestaboardKey = els.settingsVestaboardKey?.value ?? '';
        if (vestaboardKey !== '') {
            payload.vestaboard_api_key = vestaboardKey;
        }
        const vestaboardCloudToken = els.settingsVestaboardCloudToken?.value ?? '';
        if (vestaboardCloudToken !== '') {
            payload.vestaboard_cloud_token = vestaboardCloudToken;
        }
        const pwSecret = els.settingsPowerwallClientSecret?.value ?? '';
        if (pwSecret !== '') payload.powerwall_client_secret = pwSecret;
        const pwRefresh = els.settingsPowerwallRefresh?.value ?? '';
        if (pwRefresh !== '') payload.powerwall_refresh_token = pwRefresh;
        const pwPass = els.settingsPowerwallPassword?.value ?? '';
        if (pwPass !== '') payload.powerwall_gateway_password = pwPass;
        const lymowPass = els.settingsLymowPassword?.value ?? '';
        if (lymowPass !== '') payload.lymow_password = lymowPass;
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
        if (els.settingsCloudStatus && data.cloud_status) {
            els.settingsCloudStatus.textContent = formatCloudStatus(data.cloud_status);
        }
        applyRobotNameSubtitle(data.robot_name || robotName);
        lymowPageName = els.settingsLymowName?.value.trim()
            || data.lymow?.page_name
            || lymowPageName;
        applyLymowDeviceName();
        applyDeviceNameSubtitle();
        applyPanelTitle(data.hub);
        showToast('Settings saved', 'success');
        // Stay on Settings so Build firmware and other actions still work.
        fetchStatus().catch(() => {});
    } catch (err) {
        setSettingsError(err.message || 'Save failed');
    } finally {
        if (els.settingsSave) els.settingsSave.disabled = false;
    }
}

async function testLocalConnection(button) {
    if (button) button.disabled = true;
    const brokerHost = els.settingsHost?.value.trim() ?? '';
    const serial = els.settingsSerial?.value.trim() ?? '';
    if (!brokerHost || !serial) {
        setConnectionTestResult('Broker IP and serial number are required.', 'error');
        if (button) button.disabled = false;
        return;
    }

    setConnectionTestResult('Testing local MQTT connection…');
    try {
        const res = await fetch('/api/diagnostics.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                broker_host: brokerHost,
                serial,
            }),
        });
        const data = await parseJsonResponse(res);
        const stepsText = formatDiagnosticsSteps(data.steps);
        const summary = data.message || data.error || (data.ok ? 'Local MQTT connection successful.' : 'Connection test failed');
        const message = stepsText ? `${summary}\n\n${stepsText}` : summary;
        if (!data.ok) {
            setConnectionTestResult(message, 'error');
            return;
        }
        setConnectionTestResult(message, 'success');
        showToast('Local MQTT connection successful', 'success');
    } catch (err) {
        const message = err.message || 'Connection test failed';
        setConnectionTestResult(message, 'error');
        showToast(message, 'error');
    } finally {
        if (button) button.disabled = false;
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

function isUpdateAvailable(data) {
    return Boolean(data?.git_install && data?.ok && data?.update_available);
}

function setUpdateBadge(visible) {
    if (els.settingsUpdateBadge) {
        els.settingsUpdateBadge.classList.toggle('hidden', !visible);
    }
    if (els.settingsOpen) {
        els.settingsOpen.setAttribute('aria-label', visible ? 'Settings (update available)' : 'Settings');
    }
}

function applyUpdateAvailability(data, { enableUpdateButton = true } = {}) {
    const available = isUpdateAvailable(data);
    const version = data?.pending_version || data?.changelog_version;
    const versionLabel = version ? `v${version}` : 'a new version';

    setUpdateBadge(available);
    moveUpdateSectionToTop(available);
    showSettingsReleaseNotes(data, false);

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
        if (enableUpdateButton) {
            els.settingsUpdateRun.disabled = !available;
        }
    }
}

function moveUpdateSectionToTop(available) {
    document.querySelector('[data-settings-nav="updates"]')?.classList.toggle('has-update', Boolean(available));
}

function showSettingsReleaseNotes(data, showPanel = true) {
    if (!els.settingsUpdateNotes) return;
    const available = Boolean(data?.git_install && data?.ok && data?.update_available);
    if (!available) {
        els.settingsUpdateNotes.innerHTML = '';
        els.settingsUpdateNotes.classList.add('hidden');
        return;
    }

    const version = data?.pending_version || data?.changelog_version;
    const heading = version ? `What’s new in v${version}` : 'What’s new in this update';
    els.settingsUpdateNotes.innerHTML = `<h4 class="settings-update-notes-title">${escapeHtml(heading)}</h4>${renderReleaseNotesHtml(data?.release_notes)}`;
    els.settingsUpdateNotes.classList.toggle('hidden', !showPanel);
    if (showPanel) {
        els.settingsUpdateNotes.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

function setUpdateModalViewOnly(viewOnly) {
    els.updateConfirmModal?.classList.toggle('update-confirm-panel--view-only', viewOnly);
    els.updateConfirmRun?.classList.toggle('hidden', viewOnly);
    els.updateConfirmFootnote?.classList.toggle('hidden', viewOnly);
    const closeButton = els.updateConfirmActions?.querySelector('[data-update-confirm-close]');
    if (closeButton) {
        closeButton.textContent = viewOnly ? 'Close' : 'Cancel';
    }
}

async function viewReleaseNotes(button) {
    if (button) button.disabled = true;
    try {
        const res = await fetch('/api/update.php?action=release-notes', { cache: 'no-store' });
        const data = await parseJsonResponse(res);
        if (!data?.ok) throw new Error(data?.error || 'Could not load release notes');
        showReleaseNotesModal(data);
    } catch (err) {
        showToast(err.message || 'Could not load release notes', 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

function showReleaseNotesModal(data) {
    if (!els.updateConfirmModal) return;

    const version = data?.version || data?.pending_version || data?.changelog_version;
    const versionLabel = version ? `v${version}` : 'this version';
    const isPending = data?.mode === 'pending' || Boolean(data?.update_available);

    if (els.updateConfirmTitle) {
        els.updateConfirmTitle.textContent = isPending
            ? `Release notes for ${versionLabel}`
            : `Installed release notes (${versionLabel})`;
    }
    if (els.updateConfirmSummary) {
        if (isPending) {
            const from = data?.current_commit_short || 'current';
            const to = data?.remote_commit_short || 'latest';
            els.updateConfirmSummary.textContent = `Update available: ${from} → ${to}`;
            els.updateConfirmSummary.classList.remove('hidden');
        } else {
            els.updateConfirmSummary.textContent = 'You are on the latest installed version.';
            els.updateConfirmSummary.classList.remove('hidden');
        }
    }
    if (els.updateConfirmNotes) {
        els.updateConfirmNotes.innerHTML = renderReleaseNotesHtml(data?.release_notes);
    }

    setUpdateModalViewOnly(true);
    els.updateConfirmModal.classList.remove('hidden');
    document.body.classList.add('modal-open');
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
    setUpdateModalViewOnly(false);
    syncBodyModalClass();
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
        setUpdateModalViewOnly(false);
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
        if (data?.ok) {
            lastUpdateStatus = data;
        }
        applyUpdateAvailability(lastUpdateStatus);
        return lastUpdateStatus;
    } catch {
        if (isUpdateAvailable(lastUpdateStatus)) {
            applyUpdateAvailability(lastUpdateStatus);
        } else {
            applyUpdateAvailability(null);
        }
        return lastUpdateStatus;
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
    applyUpdateAvailability(data);
}

async function loadUpdateStatus() {
    if (!els.settingsUpdateStatus) return;
    els.settingsUpdateStatus.textContent = 'Checking for updates…';
    setUpdateResult(null);
    if (els.settingsUpdateCheck) els.settingsUpdateCheck.disabled = true;
    applyUpdateAvailability(lastUpdateStatus, { enableUpdateButton: false });
    try {
        const res = await fetch('/api/update.php');
        const data = await parseJsonResponse(res);
        lastUpdateStatus = data;
        els.settingsUpdateStatus.textContent = formatUpdateStatus(data);
        applyUpdateAvailability(data);
        if (isUpdateAvailable(data)) {
            els.settingsUpdateSection?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    } catch (err) {
        els.settingsUpdateStatus.textContent = err.message || 'Could not check for updates';
        if (isUpdateAvailable(lastUpdateStatus)) {
            applyUpdateAvailability(lastUpdateStatus);
        } else {
            applyUpdateAvailability(null);
        }
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
            showSettingsReleaseNotes(data, true);
            setUpdateResult('A newer version is available. Review the notes below, then click Update to latest.', 'success');
        } else {
            setUpdateResult('You are on the latest version.', 'success');
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
    if (polling || settingsModalOpen || driveActive) return;
    if (Date.now() < commandQuietUntil) return;
    polling = true;
    if (statusAbort) {
        statusAbort.abort();
    }
    statusAbort = new AbortController();
    const { signal } = statusAbort;
    try {
        const res = await fetch('/api/status.php', { signal, headers: clientTimezoneHeaders() });
        const data = await res.json();
        if (settingsModalOpen || driveActive) return;
        if (data.ok) {
            hasStatusSnapshot = true;
            setError(null);
            updateStatus(data);
            updateCameraStatus(data.camera_state);
        } else if (data.transient && hasStatusSnapshot) {
            applyHubFromStatus(data);
            return;
        } else {
            applyHubFromStatus(data);
            setError(data.error || 'Failed to fetch status');
        }
    } catch (err) {
        if (err?.name === 'AbortError' || settingsModalOpen || driveActive) return;
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
        if (data.warning && !sessionStorage.getItem('yarbo_drive_dock_warn')) {
            sessionStorage.setItem('yarbo_drive_dock_warn', '1');
            showToast(data.warning, 'error');
        }
        if (enterManual) manualModeEntered = true;
        return true;
    } catch (err) {
        showToast(err.message || 'Network error', 'error');
        if (els.driveStatus) els.driveStatus.textContent = 'Error';
        return false;
    }
}

async function flushDriveQueue() {
    if (driveInFlight) return;
    driveInFlight = true;
    // Abort a long status poll so drive can use the single-threaded php -S server.
    if (statusAbort) {
        statusAbort.abort();
        statusAbort = null;
        polling = false;
    }
    try {
        while (pendingDrive) {
            const next = pendingDrive;
            pendingDrive = null;
            await sendDrive(next.linear, next.angular, next.enterManual);
        }
    } finally {
        driveInFlight = false;
        if (pendingDrive) {
            flushDriveQueue();
        }
    }
}

function sendDrivePulse(linear, angular) {
    pendingDrive = {
        linear,
        angular,
        enterManual: !manualModeEntered,
    };
    flushDriveQueue();
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

    if (!isControllerLive()) {
        showToast('Connect the controller first (in Manual Drive or Controls)', 'error');
        if (els.driveStatus) els.driveStatus.textContent = 'Controller required';
        return;
    }
    if (!sessionStorage.getItem('yarbo_drive_ack')) {
        sessionStorage.setItem('yarbo_drive_ack', '1');
        showToast('Manual drive can move the robot immediately — keep the area clear and release or press Stop to halt.', 'error');
    }

    stopDriveLoop();
    driveActive = true;
    button.classList.add('active');
    if (els.driveStatus) els.driveStatus.textContent = driveLabel(direction);

    sendDrivePulse(vector.linear, vector.angular);
    driveInterval = setInterval(() => {
        if (!driveActive) return;
        if (!isControllerLive()) {
            stopDriveLoop();
            sendDrive(0, 0, false);
            if (els.driveStatus) els.driveStatus.textContent = 'Controller lost';
            return;
        }
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
    const keepEnabled = action === 'stop';
    if (!keepEnabled) button.disabled = true;
    try {
        const res = await fetch('/api/command.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=${encodeURIComponent(action)}`,
        });
        const data = await res.json();
        if (data.ok) {
            if (isCommandAckError(data.ack_msg)) {
                showToast(data.ack_msg, 'error');
            } else if (action === 'buzzer' && data.note) {
                showToast(data.note, 'error');
            } else if (data.ack_msg) {
                showToast(data.ack_msg, 'success');
            } else if (action === 'return_to_dock' && data.via === 'official_payload') {
                showToast('return to dock sent (no robot ack)', 'success');
            } else {
                showToast(`${action.replace(/_/g, ' ')} sent`, 'success');
            }
            if (data.agent_warning) {
                showToast('Start MQTT agent for reliable controls: ./scripts/dev.sh', 'error');
            }
            if (typeof data.hold_controller === 'boolean') {
                applyControllerStateFromStatus(data);
                updateControllerTile();
            }
            if (['return_to_dock', 'pause', 'resume', 'stop'].includes(action)) {
                noteCommandQuiet();
            } else {
                fetchStatus().catch(() => {});
            }
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
        if (action !== 'stop' && !isControllerLive()) {
            showToast('Connect the controller first', 'error');
            return;
        }
        if (action === 'return_to_dock' && !confirm('Send Yarbo back to the dock?')) return;
        if (action === 'stop') {
            stopDriveLoop();
            if (els.driveStatus) els.driveStatus.textContent = 'Stopped';
        }
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
document.getElementById('map-listen-save')?.addEventListener('click', () => {
    toggleMapListen();
});
document.getElementById('map-load-backups')?.addEventListener('click', () => {
    loadMapBackups();
});
if (document.getElementById('map-listen-save')) {
    pollMapListen();
}
if (document.getElementById('map-load-backups')) {
    fetch('/api/map_backup.php', { cache: 'no-store' })
        .then((res) => parseJsonResponse(res))
        .then((data) => {
            setMapSaveEnabled(Boolean(data.compatible));
            if (data.loaded && els.mapListenStatus && data.summary) {
                els.mapListenStatus.textContent = `Last extracted backup (${formatBackupCounts(data.summary)}). Edit, then Save to robot while docked.`;
            }
        })
        .catch(() => {});
}

document.getElementById('map-edit-toggle')?.addEventListener('click', () => {
    setMapEditMode(!mapEditMode);
});

els.mapFullscreen?.addEventListener('click', () => {
    setMapFullscreen(!mapFullscreen);
});

document.addEventListener('fullscreenchange', () => {
    const native = fullscreenElement() === els.mapCard;
    if (!native && mapFullscreen) {
        mapFullscreen = false;
        applyMapFullscreenUi(false);
    }
});
document.addEventListener('webkitfullscreenchange', () => {
    const native = fullscreenElement() === els.mapCard;
    if (!native && mapFullscreen) {
        mapFullscreen = false;
        applyMapFullscreenUi(false);
    }
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
els.plansManage?.addEventListener('click', openPlansManageModal);
document.querySelectorAll('[data-plans-manage-close]').forEach((button) => {
    button.addEventListener('click', closePlansManageModal);
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

els.settingsOpen?.addEventListener('click', () => {
    if (settingsModalOpen) closeSettingsModal();
    else openSettingsModal();
});
document.querySelector('.settings-nav')?.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-settings-nav]');
    if (!btn) return;
    showSettingsPane(btn.getAttribute('data-settings-nav') || 'connection');
});
window.addEventListener('hashchange', () => {
    const pane = settingsHashPane();
    if (pane) openSettingsModal(pane);
    else if (settingsModalOpen) closeSettingsModal();
});
els.settingsForm?.addEventListener('submit', saveSettings);
els.settingsConnectionTest?.addEventListener('click', (e) => testLocalConnection(e.currentTarget));
els.settingsCloudTest?.addEventListener('click', (e) => testCloudConnection(e.currentTarget));
els.settingsVestaboardEnabled?.addEventListener('change', () => {
    applyVestaboardEnabled();
    applyPaperPreviewModules();
    if (els.settingsVestaboardEnabled.checked) loadVestaboardPreview();
});
document.querySelectorAll('input[name="vestaboard-transport"]').forEach((radio) => {
    radio.addEventListener('change', () => applyVestaboardTransport());
});
els.settingsVestaboardSample?.addEventListener('change', () => loadVestaboardPreview());
els.settingsVestaboardTest?.addEventListener('click', (e) => testVestaboardConnection(e.currentTarget));
els.settingsVestaboardSend?.addEventListener('click', (e) => sendVestaboardNow(e.currentTarget));
els.vestaboardResume?.addEventListener('click', (e) => resumeVestaboardStatus(e.currentTarget));
els.vestaboardRotateOpen?.addEventListener('click', openVestaboardRotateModal);
els.vestaboardRotateSave?.addEventListener('click', (e) => saveVestaboardRotate(e.currentTarget));
els.vestaboardRotateEnabled?.addEventListener('change', updateVestaboardRotateHint);
document.querySelectorAll('[data-rotate-view]').forEach((box) => {
    box.addEventListener('change', updateVestaboardRotateHint);
});
document.querySelectorAll('[data-vestaboard-rotate-close]').forEach((btn) => {
    btn.addEventListener('click', closeVestaboardRotateModal);
});
els.moduleSwitcher?.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-module-id]');
    if (!btn) return;
    setActiveModule(btn.getAttribute('data-module-id') || 'yarbo');
});
els.vestaboardLiveSwitch?.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-vestaboard-live]');
    if (!btn) return;
    const id = btn.getAttribute('data-vestaboard-live') || 'yarbo';
    setVestaboardLiveView(id, btn);
});
document.querySelectorAll('input[name="powerwall-transport"]').forEach((radio) => {
    radio.addEventListener('change', () => applyPowerwallTransport());
});
els.settingsPowerwallKeys?.addEventListener('click', async (e) => {
    const button = e.currentTarget;
    button.disabled = true;
    try {
        const res = await fetch('/api/powerwall.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'generate_keys' }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Could not generate keys');
        if (els.settingsPowerwallResult) {
            els.settingsPowerwallResult.textContent = data.message || 'Keys created.';
            els.settingsPowerwallResult.classList.remove('hidden');
        }
        showToast('Tesla public key created', 'success');
    } catch (err) {
        showToast(err.message || 'Key generation failed', 'error');
    } finally {
        button.disabled = false;
    }
});
els.settingsPowerwallTest?.addEventListener('click', async (e) => {
    const button = e.currentTarget;
    button.disabled = true;
    try {
        const res = await fetch('/api/powerwall.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'test' }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Powerwall test failed');
        showToast(data.message || 'Powerwall OK', 'success');
    } catch (err) {
        showToast(err.message || 'Powerwall test failed', 'error');
    } finally {
        button.disabled = false;
    }
});
els.settingsPowerwallOauth?.addEventListener('click', (event) => {
    if ((els.settingsPowerwallOauth.getAttribute('href') || '#') === '#') {
        event.preventDefault();
        showToast('Save a Client ID and HTTPS public panel URL first.', 'error');
    }
});
els.settingsModuleYarbo?.addEventListener('change', onModuleCheckboxChange);
els.settingsModulePowerwall?.addEventListener('change', onModuleCheckboxChange);
els.settingsModuleLymow?.addEventListener('change', onModuleCheckboxChange);
els.settingsLymowLogin?.addEventListener('click', async (e) => {
    const button = e.currentTarget;
    button.disabled = true;
    if (els.settingsLymowResult) {
        els.settingsLymowResult.textContent = 'Installing MQTT libraries if needed, then signing in to Lymow…';
        els.settingsLymowResult.className = 'settings-cloud-result';
        els.settingsLymowResult.classList.remove('hidden');
    }
    try {
        const payload = {
            action: 'save',
            lymow_host: els.settingsLymowHost?.value.trim() || '',
            lymow_display_name: els.settingsLymowName?.value.trim() || '',
            lymow_email: els.settingsLymowEmail?.value.trim() || '',
            lymow_region: els.settingsLymowRegion?.value || 'auto',
        };
        const password = els.settingsLymowPassword?.value ?? '';
        if (password !== '') payload.lymow_password = password;
        await fetch('/api/lymow.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const res = await fetch('/api/lymow.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'login' }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Lymow login failed');
        const msg = data.message || 'Signed in to Lymow.';
        if (els.settingsLymowResult) {
            els.settingsLymowResult.textContent = msg;
            els.settingsLymowResult.className = 'settings-cloud-result success';
        }
        if (els.settingsLymowHost && data.host) els.settingsLymowHost.value = data.host;
        if (els.settingsLymowName) {
            const found = data.page_name || data.display_name || data.device_name || '';
            if (found && !els.settingsLymowName.value.trim()) {
                els.settingsLymowName.value = found;
            }
        }
        if (els.settingsLymowPassword) els.settingsLymowPassword.value = '';
        showToast(msg, 'success');
        updateLymowDashboard(data);
    } catch (err) {
        const message = err.message || 'Lymow login failed';
        if (els.settingsLymowResult) {
            els.settingsLymowResult.textContent = message;
            els.settingsLymowResult.className = 'settings-cloud-result error';
        }
        showToast(message, 'error');
    } finally {
        button.disabled = false;
    }
});
document.querySelectorAll('input[name="lymow-cam-mode"]').forEach((input) => {
    input.addEventListener('change', () => {
        lymowCameraModeStarted = '';
        startLymowCamera();
    });
});
els.settingsVestaboardQuiet?.addEventListener('change', () => applyVestaboardQuietHours());
els.settingsVestaboardQuietBoard?.addEventListener('click', (event) => {
    const cell = event.target.closest('[data-quiet-r]');
    if (!cell) return;
    quietCell = { r: Number(cell.dataset.quietR), c: Number(cell.dataset.quietC) };
    els.settingsVestaboardQuietBoard.focus();
    renderQuietBoard();
});
els.settingsVestaboardQuietBoard?.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') return;
    if (event.key === 'Backspace' || event.key === 'Delete') {
        event.preventDefault();
        setQuietCellCode(0, event.key === 'Backspace');
        return;
    }
    if (event.key === 'ArrowRight') {
        event.preventDefault();
        advanceQuietCell();
        renderQuietBoard();
        return;
    }
    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        quietCell.c = quietCell.c > 0 ? quietCell.c - 1 : 14;
        if (quietCell.c === 14) quietCell.r = quietCell.r > 0 ? quietCell.r - 1 : 2;
        renderQuietBoard();
        return;
    }
    if (event.key.length === 1) {
        const code = vestaboardCharToCode(event.key);
        if (code === null) return;
        event.preventDefault();
        setQuietCellCode(code, true);
    }
});
els.settingsVestaboardQuietPalette?.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-quiet-code]');
    if (!btn) return;
    setQuietCellCode(Number(btn.dataset.quietCode), true);
    els.settingsVestaboardQuietBoard?.focus();
});
els.papermonoPortsRefresh?.addEventListener('click', () => refreshPaperMonoPorts());
els.papermonoInstallTools?.addEventListener('click', (e) => installPaperMonoUsbTools(e.currentTarget));
els.papermonoBuild?.addEventListener('click', (e) => buildPaperMonoFirmware(e.currentTarget));
els.papermonoFlash?.addEventListener('click', (e) => runPaperMonoUsb('flash', e.currentTarget));
els.papermonoConfig?.addEventListener('click', (e) => runPaperMonoUsb('configure_usb', e.currentTarget));
els.papermonoLogo?.addEventListener('change', (e) => {
    const file = e.currentTarget?.files?.[0];
    if (!file) return;
    const localUrl = URL.createObjectURL(file);
    applyPaperLogoPreview(localUrl);
    uploadPaperLogo(file);
});
els.papermonoLogoClear?.addEventListener('click', () => clearPaperLogo());
els.papermonoLockScreen?.addEventListener('change', () => applyPaperPreviewModules());
els.papermonoName?.addEventListener('input', () => refreshPaperLockPreview());
els.papermonoPrefsSave?.addEventListener('click', (e) => savePaperMonoPrefs(e.currentTarget));
document.querySelectorAll('input[name="papermono-kind"]').forEach((input) => {
    input.addEventListener('change', () => {
        applyPaperMonoKindUi(paperMonoDashboardCache);
        refreshPaperMonoPorts();
    });
});
els.settingsUpdateCheck?.addEventListener('click', (e) => checkPanelUpdates(e.currentTarget));
els.settingsUpdateViewNotes?.addEventListener('click', (e) => viewReleaseNotes(e.currentTarget));
els.settingsUpdateRun?.addEventListener('click', (e) => runPanelUpdate(e.currentTarget));
els.settingsPaperOtaAll?.addEventListener('click', (e) => queuePaperOta('*', e.currentTarget));
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
    if (els.plansManageModal && !els.plansManageModal.classList.contains('hidden')) {
        closePlansManageModal();
        return;
    }
    if (els.settingsModal && !els.settingsModal.classList.contains('hidden')) {
        closeSettingsModal();
        return;
    }
    if (mapFullscreen) {
        setMapFullscreen(false);
    }
});

if (document.getElementById('camera-grid')) {
    loadCameras();
}
if (document.getElementById('map')) {
    initMap();
}
setupDrivePad();
setupBatteryTempClick();
initAppearance();
initUpdateConfirmModal();
loadSettings().catch(() => {});
refreshUpdateBadge();
if (settingsHashPane()) {
    openSettingsModal();
}
fetchStatus();
setInterval(fetchStatus, POLL_INTERVAL_MS);
