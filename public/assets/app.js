const POLL_INTERVAL_MS = 5000;
const SNAPSHOT_INTERVAL_MS = 2000;
const DRIVE_REPEAT_MS = 120;
const LINEAR_SPEED = 0.35;
const ANGULAR_SPEED = 0.55;
const COMMAND_QUIET_MS = 4000;
const HOME_PAPER_MAX = 12;

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
    mailOpen: document.getElementById('mail-open'),
    mailOpenWrap: document.getElementById('mail-open-wrap'),
    mailUnreadBadge: document.getElementById('mail-unread-badge'),
    mailPage: document.getElementById('mail-page'),
    homeAutomationsPage: document.getElementById('home-automations-page'),
    homeAutomationsOpen: document.getElementById('home-automations-open'),
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
    settingsModuleHome: document.getElementById('settings-module-home'),
    settingsModuleUnifi: document.getElementById('settings-module-unifi'),
    settingsUnifiHost: document.getElementById('settings-unifi-host'),
    settingsUnifiVerifyTls: document.getElementById('settings-unifi-verify-tls'),
    settingsUnifiProtectKey: document.getElementById('settings-unifi-protect-key'),
    settingsUnifiProtectUser: document.getElementById('settings-unifi-protect-user'),
    settingsUnifiProtectPassword: document.getElementById('settings-unifi-protect-password'),
    settingsUnifiAccessToken: document.getElementById('settings-unifi-access-token'),
    settingsUnifiAccessStandalone: document.getElementById('settings-unifi-access-standalone'),
    settingsUnifiResult: document.getElementById('settings-unifi-result'),
    settingsUnifiTest: document.getElementById('settings-unifi-test'),
    settingsUnifiDevices: document.getElementById('settings-unifi-devices'),
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
    papermonoRemoteEnabled: document.getElementById('papermono-remote-enabled'),
    papermonoRemoteUrl: document.getElementById('papermono-remote-url'),
    papermonoRemoteStatus: document.getElementById('papermono-remote-status'),
    papermonoRemoteAuthWrap: document.getElementById('papermono-remote-auth-wrap'),
    papermonoRemoteAuth: document.getElementById('papermono-remote-auth'),
    papermonoRemoteFunnelUrlWrap: document.getElementById('papermono-remote-funnel-url-wrap'),
    papermonoRemoteFunnelUrl: document.getElementById('papermono-remote-funnel-url'),
    papermonoRemoteFunnelHelp: document.getElementById('papermono-remote-funnel-help'),
    papermonoRemoteResult: document.getElementById('papermono-remote-result'),
    papermonoRemoteSave: document.getElementById('papermono-remote-save'),
    papermonoRemoteInstall: document.getElementById('papermono-remote-install'),
    papermonoRemoteLogin: document.getElementById('papermono-remote-login'),
    papermonoRemoteFunnel: document.getElementById('papermono-remote-funnel'),
    papermonoName: document.getElementById('papermono-name'),
    papermonoLogo: document.getElementById('papermono-logo'),
    papermonoLogoThumb: document.getElementById('papermono-logo-thumb'),
    papermonoLogoClear: document.getElementById('papermono-logo-clear'),
    papermonoLogoResult: document.getElementById('papermono-logo-result'),
    papermonoLockScreen: document.getElementById('papermono-lock-screen'),
    papermonoTimezone: document.getElementById('papermono-timezone'),
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
    papermonoSetupKit: document.getElementById('papermono-setup-kit'),
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

const ZONE_TYPE_LABELS = {
    clean: 'area',
    path: 'path',
    forbidden: 'no-go',
    no_vision: 'no-vision',
    sidewalk: 'sidewalk',
    obstacle: 'obstacle',
    recharge: 'dock',
    charging: 'dock',
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
let mailPageOpen = false;
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
let lastHub = null;
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

const SETTINGS_PANES = ['connection', 'modules', 'yarbo', 'lymow', 'powerwall', 'home', 'unifi', 'vestaboard', 'papermono', 'appearance', 'updates'];

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
        setMapSaveEnabled(true);
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

function zoneTypeLabel(type) {
    return ZONE_TYPE_LABELS[type] || type || 'zone';
}

function mapNameCollisionWarning(features) {
    const byName = {};
    (Array.isArray(features) ? features : []).forEach((feature) => {
        const name = String(feature?.properties?.name || '').trim();
        const type = String(feature?.properties?.zone_type || '').trim();
        if (!name || !type) return;
        if (!byName[name]) byName[name] = new Set();
        byName[name].add(type);
    });
    const hits = Object.entries(byName).filter(([, types]) => types.size > 1);
    if (!hits.length) return '';
    const parts = hits.map(([name, types]) => {
        const labels = [...types].map(zoneTypeLabel);
        return `${name} is both ${labels[0]} and ${labels[1]}`;
    });
    return `${parts.join('. ')}. Delete the extra area in the Yarbo app.`;
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
    setMapSaveEnabled(unique.length > 0);
    const collisionText = mapNameCollisionWarning(unique);
    if (collisionText) {
        updateMapAreasStatus(collisionText);
        if (els.mapListenStatus) els.mapListenStatus.textContent = collisionText;
        showToast(collisionText, 'error');
    }
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
        const typeLabel = ZONE_TYPE_LABELS[zoneType] || zoneType;
        const name = props.name || props.zone_id || `Zone ${index + 1}`;
        const points = countFeaturePoints(entry.feature);
        const palette = ZONE_COLORS[zoneType] || ZONE_COLORS.default;
        return `<li class="map-zone-item">
            <input type="checkbox" id="map-zone-vis-${index}" data-zone-index="${index}" ${entry.visible ? 'checked' : ''}>
            <span class="map-zone-swatch" style="background:${palette.color}"></span>
            <label class="map-zone-meta" for="map-zone-vis-${index}">${name} · ${typeLabel} · ${points} pts</label>
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
        ? 'Save the edited zones to the robot (must be docked)'
        : 'Load the map first';
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
            updateMapAreasStatus(data.error || 'Could not load map backups');
            return;
        }
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
            const collisionText = mapNameCollisionWarning(features);
            updateMapAreasStatus(
                collisionText || `Backup loaded (${features.length} zone${features.length === 1 ? '' : 's'}).`
            );
        }
        showToast(data.compatible ? 'Backup loaded' : (data.message || 'Could not extract a backup'), data.compatible ? 'success' : 'error');
    } catch (err) {
        setMapSaveEnabled(false);
        showToast(err.message || 'Could not load map backups', 'error');
    } finally {
        setMapLoading(false);
    }
}

async function restoreMapBackupDraft() {
    if (!mapBackupCompatible) {
        showToast('Load the map first', 'error');
        return;
    }
    const collection = currentMapDraftCollection();
    if (!collection.features.length) {
        showToast('Load or edit a map first', 'error');
        return;
    }
    if (!onChargePad) {
        showToast('Dock the robot before restoring a map', 'error');
        return;
    }
    if (!window.confirm('Save these edits to the robot? Keep it docked.')) {
        return;
    }
    if (els.mapSaveRobot) els.mapSaveRobot.disabled = true;
    setMapLoading(true, 'Saving to robot');
    try {
        const res = await fetchWithTimeout('/api/map_backup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'restore',
                confirm: true,
                geojson: collection,
            }),
        }, 50000);
        const data = await parseJsonResponse(res);
        const text = data.message || data.error || 'Save finished';
        updateMapAreasStatus(text);
        showToast(text, data.ok ? 'success' : 'error');
        if (data.ok && mapEditMode) {
            setMapEditMode(false);
        }
    } catch (err) {
        const aborted = isAbortError(err);
        showToast(aborted ? 'Save timed out. Wait until the robot is idle, then try again.' : (err.message || 'Save failed'), 'error');
    } finally {
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
        const res = await fetchWithTimeout(`/api/map.php?source=${encodeURIComponent(source)}`, { cache: 'no-store' }, 45000);
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
            const collisionText = mapNameCollisionWarning(features);
            if (collisionText) {
                updateMapAreasStatus(collisionText);
            } else {
                updateMapAreasStatus(`Live map loaded (${features.length} zone${features.length === 1 ? '' : 's'})${via}.`);
                showToast(data.note || 'Live map loaded', 'success');
            }
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
        const aborted = isAbortError(err);
        const text = aborted
            ? 'Map load timed out. Wait until the robot is idle after an app edit, then try again.'
            : `Saved areas request failed: ${err.message || 'network error'}`;
        updateMapAreasStatus(text);
        showToast(text, 'error');
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

function rememberHub(hub) {
    if (hub && hub.modules && typeof hub.modules === 'object') {
        lastHub = hub;
    }
}

function applyModuleSwitcher(hub) {
    if (!hub) return [];
    rememberHub(hub);
    const enabled = Array.isArray(hub.enabled) ? hub.enabled : [];
    const ids = enabled.map((m) => m.id);
    if (els.moduleSwitcher) {
        els.moduleSwitcher.classList.toggle('hidden', ids.length < 2);
        els.moduleSwitcher.innerHTML = enabled.map((m) => (
            `<button type="button" class="module-switcher-btn" data-module-id="${m.id}">${m.label}</button>`
        )).join('');
    }
    applyPanelTitle(hub);
    let active = localStorage.getItem(ACTIVE_MODULE_KEY) || hub.active_module || ids[0] || 'yarbo';
    if (!ids.includes(active)) active = ids[0] || 'yarbo';
    setActiveModule(active, false);
    return ids;
}

function applyHubFromStatus(data) {
    const hub = data?.hub;
    if (!hub) return;
    const ids = applyModuleSwitcher(hub);
    updatePowerwallDashboard(data.powerwall);
    updateLymowDashboard(data.lymow);
    if (ids.includes('home') && !homeStateTimer && !homeLoadBusy && !homePollBlocked()) {
        loadHomeDashboard();
    }
    if (ids.includes('unifi') && activeModuleId === 'unifi' && !settingsModalOpen) {
        loadUnifiDashboard({ silent: true });
    }
    if (data.vestaboard) {
        applyVestaboardLiveSwitch(data);
    }
}

function setActiveModule(id, persist = true) {
    const enabledIds = Array.isArray(lastHub?.enabled)
        ? lastHub.enabled.map((m) => m.id)
        : null;
    let moduleId = id || 'yarbo';
    if (enabledIds && enabledIds.length && !enabledIds.includes(moduleId)) {
        moduleId = enabledIds[0];
    }
    activeModuleId = moduleId;
    if (persist) {
        try { localStorage.setItem(ACTIVE_MODULE_KEY, moduleId); } catch { /* ignore */ }
    }
    document.querySelectorAll('#panel-sections .panel-section[data-module]').forEach((section) => {
        const owner = section.getAttribute('data-module');
        const ownerOn = !enabledIds || owner === 'shared' || enabledIds.includes(owner);
        const show = owner === 'shared' || (owner === moduleId && ownerOn);
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
    if (moduleId === 'unifi') {
        startUnifiPoll();
    } else {
        stopUnifiPoll();
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

let unifiPollTimer = 0;
let unifiDash = { cameras: [], lights: [], sensors: [], relays: [], doors: [], hubs: [], devices: [] };
let unifiPickerDirty = false;
let unifiSnapGen = 0;

function unifiShowOnHomeSnapshot() {
    const ids = [...document.querySelectorAll('input[data-unifi-home]:checked')]
        .map((el) => el.getAttribute('data-unifi-home') || '')
        .filter(Boolean);
    return [...new Set(ids)];
}

function unifiKindGroup(d) {
    const kind = String(d?.kind || '');
    if (kind === 'camera') return 'cameras';
    if (kind === 'light') return 'lights';
    if (kind === 'relay') return 'relays';
    if (kind === 'hub') return 'hubs';
    if (kind === 'door') return 'doors';
    if (kind === 'sensor' && (d.product === 'Door position sensor' || String(d.native_id || '').startsWith('dps-'))) {
        return 'dps';
    }
    if (kind === 'sensor') return 'sensors';
    return 'other';
}

function unifiKindLabel(d) {
    switch (unifiKindGroup(d)) {
        case 'cameras': return 'Camera';
        case 'lights': return 'Light';
        case 'relays': return 'Relay';
        case 'hubs': return 'Controller';
        case 'doors': return 'Door';
        case 'dps': return 'Door sensor';
        case 'sensors': return 'Sensor';
        default: return homeKindLabel(d) || 'UniFi';
    }
}

function renderUnifiHomePicker(data, { replace = false } = {}) {
    const wraps = [els.settingsUnifiDevices, document.getElementById('unifi-home-picker')].filter(Boolean);
    if (!wraps.length) return;
    const devices = Array.isArray(data?.devices) ? data.devices : [];
    const existing = document.querySelectorAll('input[data-unifi-home]');
    if (!replace && existing.length && unifiPickerDirty) {
        return;
    }
    if (!replace && existing.length) {
        wraps.forEach((wrap) => {
            wrap.querySelectorAll('input[data-unifi-home]').forEach((input) => {
                const id = input.getAttribute('data-unifi-home') || '';
                const meta = wrap.querySelector(`[data-unifi-pick="${CSS.escape(id)}"] .unifi-pick-meta`);
                const row = devices.find((d) => String(d.id) === id);
                if (meta && row) meta.textContent = String(row.status || unifiKindLabel(row));
            });
        });
        return;
    }
    const html = unifiPickerHtml(devices);
    wraps.forEach((wrap) => {
        wrap.innerHTML = html;
    });
}

function unifiPickerHtml(devices) {
    if (!devices.length) {
        return '<p class="hint">Test the connection to list cameras, lights, relays, sensors, doors, and controllers.</p>';
    }
    const groups = [
        { id: 'cameras', title: 'Cameras' },
        { id: 'lights', title: 'Lights' },
        { id: 'relays', title: 'Relays' },
        { id: 'hubs', title: 'Door controllers' },
        { id: 'doors', title: 'Doors' },
        { id: 'dps', title: 'Door position sensors' },
        { id: 'sensors', title: 'Sensors' },
        { id: 'other', title: 'Other' },
    ];
    const byGroup = new Map();
    devices.forEach((d) => {
        const g = unifiKindGroup(d);
        if (!byGroup.has(g)) byGroup.set(g, []);
        byGroup.get(g).push(d);
    });
    const preserved = unifiPickerDirty ? new Set(unifiShowOnHomeSnapshot()) : null;
    let out = '<p class="unifi-pick-lead">Show on Home</p>';
    groups.forEach((group) => {
        const rows = byGroup.get(group.id) || [];
        if (!rows.length) return;
        out += `<section class="unifi-pick-group"><h4 class="unifi-pick-title">${escapeHtml(group.title)} <span class="unifi-pick-count">${rows.length}</span></h4><div class="unifi-pick-rows">`;
        rows.forEach((d) => {
            const id = String(d.id || '');
            const checked = preserved ? preserved.has(id) : Boolean(d.show_on_home);
            const meta = d.status || unifiKindLabel(d);
            out += `<label class="unifi-pick-row" data-unifi-pick="${escapeHtml(id)}">
                <input type="checkbox" data-unifi-home="${escapeHtml(id)}" ${checked ? 'checked' : ''}>
                <span class="unifi-pick-name">${escapeHtml(d.name || id)}</span>
                <span class="unifi-pick-meta">${escapeHtml(meta)}</span>
            </label>`;
        });
        out += '</div></section>';
    });
    return out;
}

async function saveUnifiShowOnHome(id, show) {
    unifiPickerDirty = true;
    document.querySelectorAll(`input[data-unifi-home="${CSS.escape(id)}"]`).forEach((el) => {
        el.checked = show;
    });
    try {
        const data = await unifiApi({ action: 'show_on_home', id, show }, 8000);
        if (!data?.ok) throw new Error(data?.error || 'Could not update Home list');
        unifiPickerDirty = false;
    } catch (err) {
        showToast(err.message || 'Could not update Home list', 'error');
    }
}

async function unifiApi(body, timeoutMs = 20000) {
    const isCommand = Boolean(body && (body.action === 'command' || body.action === 'unlock' || body.action === 'light'));
    if (isCommand) beginHomeControl();
    try {
        const res = await fetchWithTimeout('/api/unifi.php', {
            method: body ? 'POST' : 'GET',
            headers: body ? { 'Content-Type': 'application/json' } : undefined,
            body: body ? JSON.stringify(body) : undefined,
        }, timeoutMs);
        return parseJsonResponse(res);
    } finally {
        if (isCommand) endHomeControl();
    }
}

function unifiSnapSrc(d, bust) {
    const id = d.native_id || d.id || '';
    const base = `/api/unifi.php?action=snapshot&id=${encodeURIComponent(id)}`;
    return bust ? `${base}&r=${unifiSnapGen}` : base;
}

function unifiDoorHasPosition(d) {
    if (!d || typeof d !== 'object') return false;
    if (d.has_dps) return true;
    const label = String(d.dps_label || '').trim();
    if (/^(Open|Closed)$/i.test(label)) return true;
    const dps = String(d.dps || '').toLowerCase();
    return dps === 'open' || dps === 'opened' || dps === 'close' || dps === 'closed';
}

function unifiDoorIsOpen(d) {
    if (!unifiDoorHasPosition(d)) return false;
    if (d.open === true) return true;
    if (d.open === false) return false;
    const label = String(d.dps_label || '').trim();
    if (/^Open$/i.test(label)) return true;
    if (/^Closed$/i.test(label)) return false;
    const dps = String(d.dps || '').toLowerCase();
    return dps === 'open' || dps === 'opened';
}

function unifiDoorLockLabel(d) {
    return unifiDoorIsOpen(d) ? 'Lock' : 'Unlock';
}

function unifiDoorLockButtonHtml(d, { home = false } = {}) {
    const label = unifiDoorLockLabel(d);
    if (home) {
        return `<button type="button" class="btn btn-secondary btn-compact" data-home-unifi-unlock>${escapeHtml(label)}</button>`;
    }
    return `<button type="button" class="btn btn-secondary btn-compact" data-unifi-door="${escapeHtml(d.id)}" data-unifi-cmd="unlock">${escapeHtml(label)}</button>`;
}

function patchUnifiDoorLockButton(root, d) {
    if (!root) return;
    const btn = root.querySelector('[data-unifi-cmd="unlock"], [data-unifi-cmd="lock"], [data-home-unifi-cmd="unlock"], [data-home-unifi-cmd="lock"], [data-home-unifi-unlock]');
    if (!btn) return;
    btn.textContent = unifiDoorLockLabel(d);
    if (btn.hasAttribute('data-unifi-cmd')) btn.setAttribute('data-unifi-cmd', 'unlock');
    if (btn.hasAttribute('data-home-unifi-cmd') && !['open', 'close', 'stop'].includes(String(btn.getAttribute('data-home-unifi-cmd') || ''))) {
        btn.setAttribute('data-home-unifi-cmd', 'unlock');
    }
}

function unifiDoorCommandToast(cmd) {
    if (cmd === 'unlock' || cmd === 'lock') return 'Unlock sent';
    return `${cmd} sent`;
}

function unifiDpsMetaHtml(d, attr) {
    let label = String(d?.dps_label || '').trim()
        || (d?.dps === 'open' || d?.dps === 'opened' ? 'Open' : (d?.dps === 'close' || d?.dps === 'closed' ? 'Closed' : ''));
    if (!label && d?.open === true) label = 'Open';
    if (!label && d?.open === false && d?.has_dps) label = 'Closed';
    if (!label) {
        const status = String(d?.status || '');
        if (/(^|[·\s])Open(\s|$)/i.test(status)) label = 'Open';
        else if (/(^|[·\s])Closed(\s|$)/i.test(status)) label = 'Closed';
    }
    if (!label && !d?.has_dps) return '';
    const open = label === 'Open' || d?.open === true;
    return `<span class="home-device-meta${open ? ' is-open' : ''}" ${attr}>${escapeHtml(label || '—')}</span>`;
}

function unifiDeviceCardHtml(d) {
    const kind = String(d.kind || '');
    const on = Boolean(d.on);
    if (kind === 'camera') {
        const src = unifiSnapSrc(d, false);
        return `<article class="unifi-camera-card" data-unifi-id="${escapeHtml(d.id)}">
            <img class="unifi-camera-still" src="${escapeHtml(src)}" alt="${escapeHtml(d.name || 'Camera')}" decoding="async">
            <p class="unifi-camera-name">${escapeHtml(d.name || 'Camera')}</p>
            <p class="hint unifi-card-status">${escapeHtml(d.status || '')}</p>
        </article>`;
    }
    if (kind === 'light' || kind === 'relay') {
        const label = kind === 'relay' ? 'Relay' : 'Light';
        const attr = kind === 'relay' ? 'data-unifi-relay' : 'data-unifi-light';
        return `<article class="home-device${on ? ' is-on' : ''}" data-unifi-id="${escapeHtml(d.id)}">
            <div class="home-device-label">
                <span class="home-device-dot" aria-hidden="true"></span>
                <p class="home-device-name">${escapeHtml(d.name || label)}</p>
                <span class="home-device-kind">${label}</span>
            </div>
            <div class="home-device-actions">
                <button type="button" class="btn btn-secondary btn-compact" ${attr}="${escapeHtml(d.id)}">${on ? 'Off' : 'On'}</button>
            </div>
        </article>`;
    }
    if (kind === 'door' || kind === 'hub') {
        const bound = kind !== 'hub' || Boolean(d.door_id);
        const gate = d.gate
            ? `<button type="button" class="btn btn-secondary btn-compact" data-unifi-door="${escapeHtml(d.id)}" data-unifi-cmd="open">Open</button>
               <button type="button" class="btn btn-secondary btn-compact" data-unifi-door="${escapeHtml(d.id)}" data-unifi-cmd="close">Close</button>
               <button type="button" class="btn btn-secondary btn-compact" data-unifi-door="${escapeHtml(d.id)}" data-unifi-cmd="stop">Stop</button>`
            : '';
        const unlock = bound
            ? unifiDoorLockButtonHtml(d)
            : '';
        const kindLabel = kind === 'hub' ? 'Controller' : 'Door';
        const dps = unifiDpsMetaHtml(d, 'data-unifi-dps');
        return `<article class="home-device${on ? ' is-on' : ''}" data-unifi-id="${escapeHtml(d.id)}">
            <div class="home-device-label">
                <span class="home-device-dot" aria-hidden="true"></span>
                <p class="home-device-name">${escapeHtml(d.name || kindLabel)}</p>
                <span class="home-device-kind">${kindLabel}</span>
            </div>
            <div class="home-device-actions">${dps}${unlock}${gate}</div>
        </article>`;
    }
    return `<article class="home-device${on ? ' is-on' : ''}" data-unifi-id="${escapeHtml(d.id)}">
        <div class="home-device-label">
            <span class="home-device-dot" aria-hidden="true"></span>
            <p class="home-device-name">${escapeHtml(d.name || 'Sensor')}</p>
            <span class="home-device-kind">${escapeHtml(unifiKindLabel(d))}</span>
        </div>
        <div class="home-device-actions"><span class="home-device-meta" data-unifi-dps>${escapeHtml(d.status || '—')}</span></div>
    </article>`;
}

function patchUnifiWrap(wrap, items, htmlFn) {
    if (!wrap) return;
    if (!items.length) {
        if (wrap.children.length) wrap.innerHTML = '';
        return;
    }
    const seen = new Set();
    items.forEach((d) => {
        const id = String(d.id || '');
        if (id === '') return;
        seen.add(id);
        let el = wrap.querySelector(`[data-unifi-id="${CSS.escape(id)}"]`);
        if (!el) {
            wrap.insertAdjacentHTML('beforeend', htmlFn(d));
            return;
        }
        el.classList.toggle('is-on', Boolean(d.on));
        const name = el.querySelector('.unifi-camera-name, .home-device-name');
        if (name && d.name) name.textContent = d.name;
        const status = el.querySelector('[data-unifi-dps], .unifi-card-status, .home-device-meta');
        if (status) {
            const label = String(d.dps_label || d.status || '').trim();
            if (label) status.textContent = label;
            status.classList.toggle('is-open', d.open === true || d.dps === 'open' || d.dps === 'opened' || /^Open\b/i.test(label));
        }
        const toggleBtn = el.querySelector('[data-unifi-light], [data-unifi-relay]');
        if (toggleBtn) toggleBtn.textContent = d.on ? 'Off' : 'On';
        patchUnifiDoorLockButton(el, d);
    });
    [...wrap.querySelectorAll('[data-unifi-id]')].forEach((el) => {
        const id = el.getAttribute('data-unifi-id') || '';
        if (id && !seen.has(id)) el.remove();
    });
}

function renderUnifiDashboard(data, { replace = false } = {}) {
    unifiDash = data || unifiDash;
    const status = document.getElementById('unifi-status');
    if (status) {
        const err = data?.error || data?.config?.last_error;
        const n = (data?.devices || []).length;
        status.textContent = data?.ok
            ? `UniFi: connected · ${n} device${n === 1 ? '' : 's'}`
            : `UniFi: ${err || 'not configured'}`;
    }
    const cameras = data?.cameras || [];
    const camWrap = document.getElementById('unifi-cameras');
    if (camWrap) {
        if (!replace && camWrap.querySelector('[data-unifi-id]')) {
            patchUnifiWrap(camWrap, cameras, unifiDeviceCardHtml);
        } else {
            camWrap.innerHTML = cameras.length
                ? cameras.map((d) => unifiDeviceCardHtml(d)).join('')
                : '<p class="hint">No Protect cameras yet. Add an API key in Settings → UniFi and tap Test.</p>';
        }
    }
    patchUnifiWrap(document.getElementById('unifi-lights'), data?.lights || [], unifiDeviceCardHtml);
    patchUnifiWrap(document.getElementById('unifi-relays'), data?.relays || [], unifiDeviceCardHtml);
    patchUnifiWrap(document.getElementById('unifi-hubs'), data?.hubs || [], unifiDeviceCardHtml);
    patchUnifiWrap(document.getElementById('unifi-doors'), data?.doors || [], unifiDeviceCardHtml);
    patchUnifiWrap(document.getElementById('unifi-sensors'), data?.sensors || [], unifiDeviceCardHtml);
    renderUnifiHomePicker(data, { replace });
}

async function loadUnifiDashboard(opts = {}) {
    try {
        const data = await unifiApi(null, 12000);
        if (data && data.ok !== false) renderUnifiDashboard(data, { replace: Boolean(opts.replace) });
        else if (data?.error && !opts.silent) {
            const status = document.getElementById('unifi-status');
            if (status) status.textContent = `UniFi: ${data.error}`;
        }
    } catch (err) {
        if (opts.silent) return;
        const status = document.getElementById('unifi-status');
        if (status) status.textContent = `UniFi: ${err.message || 'unavailable'}`;
    }
}

function startUnifiPoll() {
    stopUnifiPoll();
    loadUnifiDashboard({ replace: true });
    unifiPollTimer = window.setInterval(() => {
        if (activeModuleId !== 'unifi' || settingsModalOpen) return;
        loadUnifiDashboard({ silent: true });
    }, 15000);
}

function stopUnifiPoll() {
    if (unifiPollTimer) {
        clearInterval(unifiPollTimer);
        unifiPollTimer = 0;
    }
}

let homeDash = { devices: [], scenes: [], paper_devices: [], setup: {} };
let homePaperTabletId = '';
let homePaperFilter = '';
let homePaperSavingId = '';
let homeLoadBusy = false;
let homeLoadAborts = 0;
let homeStateTimer = 0;
let homeSetupPollTimer = 0;
let homeManageOpen = false;
let homeDrag = null;
const homeExpandedRooms = new Set();
const homeExpandedGroups = new Set();
let homeSceneDraft = { id: '', name: '', included: {}, states: {} };
let autoPageOpen = false;
let autoDraft = null;
let autoNameLocked = false;
let autoDrag = null;
let homeAutoGeoBusy = false;

function homeResetSceneDraft() {
    homeSceneDraft = { id: '', name: '', included: {}, states: {} };
}

function homeSceneStateForDevice(d, fallback) {
    const current = homeSceneDraft.states[d.id] || fallback || {};
    return {
        on: current.on !== undefined ? Boolean(current.on) : Boolean(d.on),
        brightness: current.brightness !== undefined && current.brightness !== null
            ? Number(current.brightness)
            : Number(d.brightness ?? (d.on ? 100 : 0)),
        hex: current.hex || d.color_hex || '#ffd27a',
        kelvin: current.kelvin || d.color_temp || 2700,
        setpoint: current.setpoint !== undefined && current.setpoint !== null
            ? Number(current.setpoint)
            : (d.heating_setpoint != null ? Number(d.heating_setpoint) : 21),
    };
}

function homeFillSceneDraftFromOn() {
    const included = {};
    const states = {};
    (homeDash.devices || []).forEach((d) => {
        if (homeIsUnifi(d) || !d.on) return;
        included[d.id] = true;
        states[d.id] = {
            on: true,
            brightness: d.dimmable ? Number(d.brightness ?? 100) : null,
            hex: d.colorable ? (d.color_hex || '#ffd27a') : null,
            kelvin: d.color_ct && !d.colorable ? Number(d.color_temp || 2700) : null,
            setpoint: d.kind === 'heater' ? Number(d.heating_setpoint ?? 21) : null,
        };
    });
    homeSceneDraft.included = included;
    homeSceneDraft.states = states;
}

function homeLoadSceneDraft(scene) {
    const included = {};
    const states = {};
    (scene.actions || []).forEach((action) => {
        if (!action?.id) return;
        included[action.id] = true;
        states[action.id] = {
            on: Boolean(action.on),
            brightness: action.brightness,
            hex: action.color_hex || null,
            kelvin: action.color_temp || null,
            setpoint: action.heating_setpoint != null ? Number(action.heating_setpoint) : null,
        };
    });
    homeSceneDraft = {
        id: scene.id || '',
        name: scene.name || '',
        included,
        states,
    };
}

function readHomeSceneDraftFromDom() {
    const nameEl = document.getElementById('home-scene-name');
    if (nameEl) homeSceneDraft.name = String(nameEl.value || '');
    document.querySelectorAll('#home-scene-members [data-scene-member]').forEach((row) => {
        const id = row.getAttribute('data-scene-member');
        if (!id) return;
        const included = Boolean(row.querySelector('[data-scene-include]')?.checked);
        homeSceneDraft.included[id] = included;
        homeSceneDraft.states[id] = {
            on: Boolean(row.querySelector('[data-scene-on]')?.checked),
            brightness: Number(row.querySelector('[data-scene-bright]')?.value ?? 100),
            hex: row.querySelector('[data-scene-color]')?.value || null,
            kelvin: Number(row.querySelector('[data-scene-kelvin]')?.value || 0) || null,
            setpoint: Number(row.querySelector('[data-scene-setpoint]')?.value || 0) || null,
        };
    });
}

function collectHomeSceneActions() {
    readHomeSceneDraftFromDom();
    const actions = [];
    (homeDash.devices || []).forEach((d) => {
        if (homeIsUnifi(d) || !homeSceneDraft.included[d.id]) return;
        const st = homeSceneStateForDevice(d);
        const on = Boolean(st.on);
        const heater = String(d.kind || '') === 'heater';
        actions.push({
            id: d.id,
            on,
            brightness: on && d.dimmable && !heater ? Number(st.brightness ?? 100) : null,
            color_hex: on && d.colorable && !heater ? (st.hex || null) : null,
            color_temp: on && d.color_ct && !d.colorable && !heater ? Number(st.kelvin || 0) || null : null,
            heating_setpoint: on && heater ? Number(st.setpoint || d.heating_setpoint || 21) : null,
        });
    });
    return actions;
}

function homeIsUnifi(d) {
    return d?.source === 'unifi' || String(d?.id || '').startsWith('unifi:');
}

function homeDeviceCanToggle(d) {
    if (!d) return false;
    if (homeIsUnifi(d)) {
        const kind = String(d.kind || '');
        return kind === 'light' || kind === 'relay';
    }
    const kind = String(d.kind || 'light');
    return kind !== 'camera' && kind !== 'sensor' && kind !== 'door' && kind !== 'hub';
}

function homeIsLight(d) {
    if (homeIsUnifi(d)) return d.kind === 'light';
    return !d?.kind || d.kind === 'light';
}

function homeKindLabel(d) {
    if (homeIsUnifi(d)) {
        switch (String(d?.kind || '')) {
            case 'camera':
                return 'Camera';
            case 'door':
                return 'Door';
            case 'hub':
                return 'Controller';
            case 'sensor':
                if (d.product === 'Door position sensor' || String(d.native_id || '').startsWith('dps-')) {
                    return 'Door sensor';
                }
                return 'Sensor';
            case 'relay':
                return 'Relay';
            case 'light':
                return 'Light';
            default:
                return 'UniFi';
        }
    }
    switch (String(d?.kind || '')) {
        case 'heater':
            return 'Heater';
        case 'vacuum':
            return 'Vacuum';
        case 'plug':
            return 'Plug';
        case 'switch':
            return 'Switch';
        default:
            return '';
    }
}

function homeColorInputsHtml(d, on, hex, kelvin, colorAttr, kelvinAttr) {
    if (!homeIsLight(d) || homeIsUnifi(d)) {
        return '';
    }
    if (d.colorable || d.color_hs || d.color_xy) {
        return `<input type="color" value="${escapeHtml(hex || d.color_hex || '#ffd27a')}" ${colorAttr} title="Colour" aria-label="Colour">`;
    }
    if (d.color_ct) {
        const min = Number(d.color_temp_min || 2000);
        const max = Number(d.color_temp_max || 6500);
        const value = Number(kelvin || d.color_temp || 2700);
        return `<input type="range" min="${min}" max="${max}" step="50" value="${value}" ${kelvinAttr} class="home-kelvin" ${on ? '' : 'disabled'} title="Colour temperature (warm to cool)" aria-label="Colour temperature">`;
    }
    return '';
}

const HOME_CONTROL_ACTIONS = new Set(['command', 'room_command', 'group_command', 'scene_run', 'scene_off']);
let homeAbort = null;
let homeControlBusy = 0;
const homeSticky = new Map();

function abortPanelGets() {
    if (statusAbort) {
        statusAbort.abort();
        statusAbort = null;
        polling = false;
    }
    if (homeAbort) {
        homeAbort.abort();
        homeAbort = null;
        homeLoadBusy = false;
    }
}

function beginHomeControl() {
    homeControlBusy += 1;
    noteCommandQuiet();
    abortPanelGets();
}

function endHomeControl() {
    homeControlBusy = Math.max(0, homeControlBusy - 1);
    noteCommandQuiet();
}

function homePollBlocked() {
    return homeControlBusy > 0 || Date.now() < commandQuietUntil;
}

function rememberHomeSticky(id, on) {
    if (!id) return;
    const ms = String(id).startsWith('unifi:') ? 8000 : COMMAND_QUIET_MS;
    homeSticky.set(id, { on: Boolean(on), until: Date.now() + ms });
}

function homeStickyOn(id) {
    const row = homeSticky.get(id);
    if (!row) return null;
    if (Date.now() > row.until) {
        homeSticky.delete(id);
        return null;
    }
    return row.on;
}

async function homeApi(body, timeoutMs = 20000) {
    const isCommand = Boolean(body && HOME_CONTROL_ACTIONS.has(body.action));
    if (isCommand) beginHomeControl();
    try {
        const extra = {};
        if (!body) {
            if (homeAbort) homeAbort.abort();
            homeAbort = new AbortController();
            extra.signal = homeAbort.signal;
        }
        const res = await fetchWithTimeout('/api/home.php', {
            method: body ? 'POST' : 'GET',
            headers: {
                ...(body ? { 'Content-Type': 'application/json' } : {}),
                ...clientTimezoneHeaders(),
            },
            body: body ? JSON.stringify(body) : undefined,
            ...extra,
        }, timeoutMs);
        return parseJsonResponse(res);
    } finally {
        if (isCommand) endHomeControl();
    }
}

function applyHomeSetupUi(data) {
    const setup = data?.setup || {};
    const ready = Boolean(setup.ready || data?.server?.ok);
    const running = setup.state === 'running' && !ready;
    const err = setup.error || (!ready && !running ? (data?.server?.error || '') : '');
    const message = ready
        ? (setup.message || 'Matter server is running')
        : (running ? (setup.message || 'Setting up the Matter server…') : (err || 'Matter server is not running yet'));
    const dashStatus = document.getElementById('home-setup-status');
    const dashBtn = document.getElementById('home-setup');
    if (dashStatus) {
        dashStatus.textContent = ready ? '' : message;
        dashStatus.classList.toggle('hidden', ready && !running);
    }
    if (dashBtn) {
        dashBtn.classList.toggle('hidden', ready);
        dashBtn.disabled = running;
        dashBtn.textContent = running ? 'Setting up…' : 'Set up Matter server';
    }
    const settingsStatus = document.getElementById('settings-home-setup-status');
    const settingsBtn = document.getElementById('settings-home-setup');
    if (settingsStatus) settingsStatus.textContent = `Matter server: ${message}`;
    if (settingsBtn) {
        settingsBtn.disabled = running;
        settingsBtn.textContent = running ? 'Setting up…' : (ready ? 'Matter server is ready' : 'Set up Matter server');
    }
    if (running) {
        if (!homeSetupPollTimer) {
            homeSetupPollTimer = window.setTimeout(() => {
                homeSetupPollTimer = 0;
                loadHomeDashboard();
            }, 3000);
        }
    } else if (homeSetupPollTimer) {
        window.clearTimeout(homeSetupPollTimer);
        homeSetupPollTimer = 0;
    }
}

async function startHomeSetup(button) {
    if (button) button.disabled = true;
    const running = {
        state: 'running',
        ready: false,
        message: 'Setting up the Matter server. First time can take a few minutes.',
        error: null,
    };
    homeDash = { ...homeDash, setup: running, server: { ok: false, error: running.message } };
    applyHomeSetupUi(homeDash);
    try {
        const data = await homeApi({ action: 'setup' }, 12000);
        if (!data.ok) throw new Error(data.error || 'Could not start Matter setup');
        showToast(data.message || 'Setting up Matter server', 'success');
        homeDash = { ...homeDash, setup: data.setup || running };
        applyHomeSetupUi(homeDash);
    } catch (err) {
        if (isAbortError(err)) {
            showToast('Setup is running in the background. First time can take a few minutes.', 'success');
            applyHomeSetupUi(homeDash);
            return;
        }
        showToast(err.message || 'Matter setup failed', 'error');
        if (button) button.disabled = false;
    }
}

async function loadHomeDashboard(opts = {}) {
    if (homeDrag) return;
    if (opts.patch && homePollBlocked()) return;
    if (homeLoadBusy && !opts.force) return;
    const card = document.getElementById('home-card');
    const homeEnabled = Boolean(document.querySelector('[data-module-id="home"]'));
    const settingsHomeOpen = document.getElementById('settings-home-section')?.classList.contains('is-active');
    if (!homeEnabled && !settingsHomeOpen) {
        if (!card || card.classList.contains('module-pane-hidden')) return;
    }
    homeLoadBusy = true;
    let aborted = false;
    try {
        const data = await homeApi(null, 12000);
        const savingId = homePaperSavingId;
        const localAssigned = savingId
            ? [...(((homeDash.paper_devices || []).find((p) => p.id === savingId) || {}).assigned || [])]
            : null;
        homeDash = data;
        if (savingId && localAssigned) {
            const paper = (homeDash.paper_devices || []).find((p) => p.id === savingId);
            if (paper) paper.assigned = localAssigned;
        }
        if (opts.patch && !homeManageOpen && !homeDrag && document.querySelector('#home-devices [data-home-id]')) {
            patchHomeDashboard(data);
        } else {
            renderHomeDashboard(data);
        }
        if (autoPageOpen) {
            if (autoDraft) renderHomeAutoTray();
            else renderHomeAutomations();
        }
        homeLoadAborts = 0;
        ensureHomeStatePoll();
        if (!opts.patch) {
            homeAutoEnsureTimezone().then(() => {
                if (autoPageOpen) renderHomeAutomations();
            }).catch(() => {});
        }
    } catch (err) {
        if (isAbortError(err)) {
            aborted = true;
            if (homePollBlocked()) {
                homeLoadBusy = false;
                return;
            }
            const status = document.getElementById('home-server-status');
            homeLoadAborts += 1;
            if (status) {
                status.textContent = homeLoadAborts < 3
                    ? 'Loading Home…'
                    : 'Home is still starting. Refresh after a minute, or run the Pi diagnostic from the update notes.';
            }
            if (homeLoadAborts < 3) {
                window.setTimeout(() => {
                    homeLoadBusy = false;
                    loadHomeDashboard();
                }, 2500);
            } else {
                homeLoadBusy = false;
            }
            return;
        }
        const status = document.getElementById('home-server-status');
        if (status) status.textContent = err.message || 'Could not load Home';
        applyHomeSetupUi({ setup: { state: 'failed', error: err.message || 'Could not load Home' } });
    } finally {
        if (!aborted) {
            homeLoadBusy = false;
        }
    }
}

function homeCardIsWatching() {
    const card = document.getElementById('home-card');
    if (!card || card.classList.contains('module-pane-hidden')) return false;
    if (homeManageOpen || homeDrag) return false;
    const active = document.activeElement;
    if (active && card.contains(active) && ['INPUT', 'SELECT', 'TEXTAREA'].includes(active.tagName)) {
        return false;
    }
    return true;
}

function ensureHomeStatePoll() {
    if (homeStateTimer) return;
    homeStateTimer = window.setInterval(() => {
        if (homePollBlocked()) return;
        if (homeCardIsWatching() || autoPageOpen) loadHomeDashboard({ patch: true });
    }, 3000);
}

function homeDeviceRecord(id) {
    return [...(homeDash.devices || []), ...(homeDash.hidden_devices || [])]
        .find((d) => d.id === id);
}

function patchHomeDeviceVisual(id, on, extras = {}) {
    const card = document.querySelector(`[data-home-id="${CSS.escape(id)}"]`);
    if (!card) return;
    card.classList.toggle('is-on', Boolean(on));
    const btn = card.querySelector('[data-home-toggle]');
    if (btn) btn.textContent = on ? 'Off' : 'On';
    card.querySelectorAll('[data-home-power]').forEach((el) => {
        const want = el.getAttribute('data-home-power') === 'on';
        el.classList.toggle('is-active', Boolean(on) === want);
    });
    card.querySelectorAll('[data-home-color], [data-home-kelvin]').forEach((el) => {
        el.disabled = !on;
    });
    if (extras.brightness != null) {
        const device = homeDeviceRecord(id);
        if (device) device.brightness = extras.brightness;
        const bright = card.querySelector('[data-home-bright]');
        if (bright && document.activeElement !== bright) bright.value = String(extras.brightness);
    }
    if (extras.color_hex) {
        const device = homeDeviceRecord(id);
        if (device) device.color_hex = extras.color_hex;
        const picker = card.querySelector('[data-home-color]');
        if (picker && document.activeElement !== picker) picker.value = extras.color_hex;
        const dot = card.querySelector('.home-device-dot');
        if (dot && on) {
            dot.style.background = extras.color_hex;
            dot.style.boxShadow = `0 0 0.35rem ${extras.color_hex}`;
        }
    }
    if (extras.color_temp != null) {
        const device = homeDeviceRecord(id);
        if (device) device.color_temp = extras.color_temp;
        const kelvin = card.querySelector('[data-home-kelvin]');
        if (kelvin && document.activeElement !== kelvin) kelvin.value = String(extras.color_temp);
    }
    if (extras.local_temperature != null && extras.local_temperature !== '') {
        const device = homeDeviceRecord(id);
        if (device) device.local_temperature = extras.local_temperature;
        const room = card.querySelector('[data-home-room-temp]');
        if (room) {
            room.textContent = `${Number(extras.local_temperature).toFixed(1)}°`;
            room.classList.remove('is-empty');
        }
    }
    if (extras.heating_setpoint != null && extras.heating_setpoint !== '') {
        const device = homeDeviceRecord(id);
        if (device) device.heating_setpoint = extras.heating_setpoint;
        const set = card.querySelector('[data-home-setpoint]');
        if (set && document.activeElement !== set) set.value = String(extras.heating_setpoint);
    }
    const dpsLabel = String(extras.dps_label || '').trim();
    if (dpsLabel || extras.dps != null || extras.open != null || extras.status) {
        const device = homeDeviceRecord(id);
        if (device) {
            if (dpsLabel) device.dps_label = dpsLabel;
            if (extras.dps != null) device.dps = extras.dps;
            if (extras.open != null) device.open = extras.open;
            if (extras.status) device.status = extras.status;
        }
        const meta = card.querySelector('[data-home-dps], [data-home-status]');
        if (meta) {
            const isDps = meta.hasAttribute('data-home-dps');
            const label = isDps
                ? (dpsLabel
                    || (extras.open === true ? 'Open' : (extras.open === false && (extras.has_dps || extras.dps) ? 'Closed' : ''))
                    || extras.status
                    || (extras.dps === 'open' || extras.dps === 'opened' ? 'Open' : (extras.dps === 'close' || extras.dps === 'closed' ? 'Closed' : meta.textContent)))
                : (extras.status || dpsLabel || meta.textContent);
            meta.textContent = label;
            meta.classList.toggle(
                'is-open',
                extras.open === true
                    || extras.dps === 'open'
                    || extras.dps === 'opened'
                    || /^Open\b/i.test(String(label || ''))
            );
        }
        patchUnifiDoorLockButton(card, device || extras);
    }
}

function setHomeDeviceOn(id, on) {
    rememberHomeSticky(id, on);
    const device = homeDeviceRecord(id);
    if (device) device.on = Boolean(on);
    patchHomeDeviceVisual(id, on);
}

function patchHomeDashboard(data) {
    applyHomeSetupUi(data);
    applyHomeManageUi();
    const shown = [...document.querySelectorAll('#home-devices [data-home-id], #home-hidden-devices [data-home-id]')]
        .map((el) => el.getAttribute('data-home-id') || '')
        .filter(Boolean);
    const next = [...(data.devices || []), ...(data.hidden_devices || [])];
    const nextIds = new Set(next.map((d) => String(d.id || '')).filter(Boolean));
    if (shown.length !== nextIds.size || shown.some((id) => !nextIds.has(id))) {
        renderHomeDashboard(data);
        return;
    }
    next.forEach((d) => {
        const sticky = homeStickyOn(d.id);
        if (sticky !== null) d.on = sticky;
        const rec = homeDeviceRecord(d.id);
        if (rec) {
            if (typeof d.on === 'boolean') rec.on = Boolean(d.on);
            if (d.brightness != null) rec.brightness = d.brightness;
            if (d.color_hex) rec.color_hex = d.color_hex;
            if (d.color_temp != null) rec.color_temp = d.color_temp;
            if (d.local_temperature != null) rec.local_temperature = d.local_temperature;
            if (d.heating_setpoint != null) rec.heating_setpoint = d.heating_setpoint;
            if (d.dps_label) rec.dps_label = d.dps_label;
            if (d.dps != null) rec.dps = d.dps;
            if (d.open != null) rec.open = d.open;
            if (d.status) rec.status = d.status;
        }
        patchHomeDeviceVisual(d.id, d.on, d);
    });
    (data.rooms || []).forEach((room) => {
        const el = document.querySelector(`[data-home-room="${CSS.escape(room.id)}"]`);
        if (!el) return;
        el.classList.toggle('is-on', Boolean(room.on));
        const btn = el.querySelector('[data-home-room-toggle]');
        if (btn) btn.textContent = room.on ? 'Off' : 'On';
        (room.groups || []).forEach((group) => {
            const wrap = document.querySelector(`[data-home-group="${CSS.escape(group.id)}"]`);
            if (!wrap) return;
            wrap.querySelector('.home-group-heading')?.classList.toggle('is-on', Boolean(group.on));
            const gbtn = wrap.querySelector('[data-home-group-toggle]');
            if (gbtn) gbtn.textContent = group.on ? 'Off' : 'On';
        });
    });
    if (!homeManageOpen) {
        (data.scenes || []).forEach((scene) => {
            const btn = document.querySelector(`#home-scenes [data-home-scene="${CSS.escape(scene.id)}"]`);
            if (!btn) return;
            btn.classList.toggle('btn-secondary', !scene.on);
        });
    }
}

function applyHomeManageUi() {
    const card = document.getElementById('home-card');
    const btn = document.getElementById('home-manage-toggle');
    card?.classList.toggle('home-card--manage', homeManageOpen);
    if (btn) {
        btn.setAttribute('aria-pressed', homeManageOpen ? 'true' : 'false');
        btn.setAttribute('aria-label', homeManageOpen ? 'Hide Home settings' : 'Show Home settings');
        btn.title = homeManageOpen
            ? 'Done with names, rooms, order, scenes, and PaperMono assignment'
            : 'Rename, reorder, rooms, scenes, PaperMono assignment, hide, and add devices';
    }
}

function homeReorderHandleHtml() {
    if (!homeManageOpen) return '';
    return `<span class="home-reorder-controls">
        <button type="button" class="home-drag-handle" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</button>
        <button type="button" class="home-move-btn" data-home-move="-1" title="Move up" aria-label="Move up">▲</button>
        <button type="button" class="home-move-btn" data-home-move="1" title="Move down" aria-label="Move down">▼</button>
    </span>`;
}

function homeReorderItemFromHandle(handle) {
    return handle.closest('[data-paper-item], [data-home-scene-card], [data-home-id], [data-home-group], [data-home-room-group]');
}

function homeReorderSiblings(item) {
    const parent = item.parentElement;
    if (!parent) return [];
    if (item.hasAttribute('data-home-room-group')) {
        return [...parent.querySelectorAll(':scope > [data-home-room-group]')];
    }
    if (item.hasAttribute('data-home-group')) {
        return [...parent.querySelectorAll(':scope > [data-home-group]')];
    }
    if (item.hasAttribute('data-home-id')) {
        return [...parent.querySelectorAll(':scope > [data-home-id]')];
    }
    if (item.hasAttribute('data-home-scene-card')) {
        return [...parent.querySelectorAll(':scope > [data-home-scene-card]')];
    }
    if (item.hasAttribute('data-paper-item')) {
        return [...parent.querySelectorAll(':scope > [data-paper-item]')];
    }
    return [];
}

function homeReorderKind(item) {
    if (item.hasAttribute('data-paper-item')) return 'paper';
    if (item.hasAttribute('data-home-scene-card')) return 'scenes';
    if (item.hasAttribute('data-home-id')) return 'devices';
    if (item.hasAttribute('data-home-group')) return 'groups';
    if (item.hasAttribute('data-home-room-group')) return 'rooms';
    return '';
}

function homePaperCatalog(data) {
    const items = [
        ...(data.devices || []).map((d) => ({ id: d.id, name: d.name, kind: d.kind || 'light' })),
        ...(data.scenes || []).map((s) => ({ id: `scene:${s.id}`, name: s.name, kind: 'scene' })),
    ];
    return new Map(items.filter((c) => c.id).map((c) => [c.id, c]));
}

function homePaperAssignedChoices(paper, catalog) {
    return [...(paper?.assigned || [])].map((id) => catalog.get(id)).filter(Boolean);
}

function homeSelectedPaper(data) {
    const papers = data.paper_devices || [];
    if (!papers.length) return null;
    return papers.find((p) => p.id === homePaperTabletId) || papers[0];
}

function homePaperRowHtml(c, index, on) {
    return `<div class="home-paper-item" data-paper-item="${escapeHtml(c.id)}">
        ${on ? homeReorderHandleHtml() : ''}
        ${on ? `<span class="home-paper-index">${index + 1}</span>` : ''}
        <label class="home-paper-item-pick">
            <input type="checkbox" value="${escapeHtml(c.id)}" ${on ? 'checked' : ''}>
            <span class="home-paper-item-name">${escapeHtml(c.name)}</span>
            <span class="hint">(${escapeHtml(c.kind)})</span>
        </label>
    </div>`;
}

function renderHomePaperAssign(data) {
    const tabs = document.getElementById('home-paper-tablets');
    const assign = document.getElementById('home-paper-assign');
    const papers = data.paper_devices || [];
    const catalog = homePaperCatalog(data);
    if (papers.length && !papers.some((p) => p.id === homePaperTabletId)) {
        homePaperTabletId = papers[0].id;
    }
    if (!homePaperTabletId && papers[0]) {
        homePaperTabletId = papers[0].id;
    }
    if (tabs) {
        tabs.innerHTML = papers.length
            ? papers.map((p) => {
                const assigned = homePaperAssignedChoices(p, catalog);
                const preview = assigned.map((c) => c.name).join(', ') || 'No HOUSE buttons yet';
                const active = p.id === homePaperTabletId;
                return `<button type="button" class="home-paper-tab${active ? ' is-active' : ''}" data-paper-tablet="${escapeHtml(p.id)}" role="tab" aria-selected="${active ? 'true' : 'false'}">
                    <span class="home-paper-tab-top">
                        <span class="home-paper-tab-name">${escapeHtml(p.name)}</span>
                        <span class="home-paper-tab-count">${assigned.length}/${HOME_PAPER_MAX}</span>
                    </span>
                    <span class="home-paper-tab-preview">${escapeHtml(preview)}</span>
                </button>`;
            }).join('')
            : '<p class="hint">No PaperMono paired yet. Flash one in Settings, then assign HOUSE buttons here.</p>';
    }
    if (!assign) return;

    const filterFocused = document.activeElement?.id === 'home-paper-filter';
    const filterPos = filterFocused ? document.activeElement.selectionStart : null;
    if (!papers.length) {
        assign.innerHTML = '';
        return;
    }
    const selected = homeSelectedPaper(data);
    if (selected && selected.id !== homePaperTabletId) {
        homePaperTabletId = selected.id;
    }
    const assigned = homePaperAssignedChoices(selected, catalog);
    const assignedIds = assigned.map((c) => c.id);
    const filter = homePaperFilter.trim().toLowerCase();
    const available = [...catalog.values()].filter((c) => {
        if (assignedIds.includes(c.id)) return false;
        if (!filter) return true;
        return `${c.name} ${c.kind}`.toLowerCase().includes(filter);
    });
    const scenes = available.filter((c) => c.kind === 'scene');
    const others = available.filter((c) => c.kind !== 'scene');
    const othersLabel = (c) => homePaperRowHtml(c, 0, false);
    const copyOptions = papers.filter((p) => p.id !== selected?.id);
    const copyHtml = copyOptions.length
        ? `<label class="home-paper-copy">Copy from
            <select id="home-paper-copy-from" aria-label="Copy HOUSE buttons from another tablet">
                ${copyOptions.map((p) => `<option value="${escapeHtml(p.id)}">${escapeHtml(p.name)}</option>`).join('')}
            </select>
            <button type="button" class="btn btn-secondary btn-compact" id="home-paper-copy">Copy</button>
        </label>`
        : '';
    const availableHtml = (!scenes.length && !others.length)
        ? `<p class="hint">${filter ? 'No matches.' : 'Everything is assigned.'}</p>`
        : `${scenes.length ? `<p class="home-paper-group-label">Scenes</p>${scenes.map(othersLabel).join('')}` : ''}
           ${others.length ? `<p class="home-paper-group-label">Lights and plugs</p>${others.map(othersLabel).join('')}` : ''}`;
    assign.innerHTML = `
        <div class="home-paper-selected-head">
            <p class="home-paper-selected-title"><strong>${escapeHtml(selected?.name || 'PaperMono')}</strong> · ${assigned.length} of ${HOME_PAPER_MAX} HOUSE buttons</p>
            ${copyHtml}
        </div>
        <div id="home-paper-assigned" class="home-paper-list">${assigned.map((c, i) => homePaperRowHtml(c, i, true)).join('') || '<p class="hint">Tick lights or scenes below. Top of this list is the first HOUSE button.</p>'}</div>
        <p class="home-paper-available-label">Add to this tablet</p>
        <input type="search" id="home-paper-filter" class="home-paper-filter" placeholder="Filter lights and scenes" value="${escapeHtml(homePaperFilter)}" autocomplete="off">
        <div id="home-paper-available" class="home-paper-list home-paper-available">${availableHtml}</div>
    `;
    const filterEl = document.getElementById('home-paper-filter');
    if (filterEl) {
        filterEl.value = homePaperFilter;
        if (filterFocused) {
            filterEl.focus();
            if (typeof filterPos === 'number') {
                try {
                    filterEl.setSelectionRange(filterPos, filterPos);
                } catch (err) {
                    /* ignore */
                }
            }
        }
    }
}

function homePaperAssignedIds() {
    return [...document.querySelectorAll('#home-paper-assigned [data-paper-item]')]
        .map((row) => row.getAttribute('data-paper-item') || '')
        .filter(Boolean)
        .slice(0, HOME_PAPER_MAX);
}

async function homeSavePaperAssignment(ids, tabletId = homePaperTabletId) {
    const paper = (homeDash.paper_devices || []).find((p) => p.id === tabletId);
    if (paper) paper.assigned = ids;
    renderHomePaperAssign(homeDash);
    homePaperSavingId = tabletId;
    try {
        await homeCommitReorder('paper', ids, { tablet_id: tabletId });
        showToast('PaperMono assignment saved', 'success');
    } catch (err) {
        showToast(err.message || 'Could not save assignment', 'error');
    } finally {
        if (homePaperSavingId === tabletId) homePaperSavingId = '';
    }
}

async function homeCommitReorder(kind, ids, extra = {}) {
    if (!kind || (kind !== 'paper' && !ids.length)) return;
    const data = await homeApi({ action: 'reorder', kind, ids, ...extra });
    if (!data.ok) throw new Error(data.error || 'Could not save order');
    if (kind === 'rooms' && Array.isArray(homeDash.rooms)) {
        const map = new Map(homeDash.rooms.map((r) => [r.id, r]));
        homeDash.rooms = ids.map((id) => map.get(id)).filter(Boolean)
            .concat(homeDash.rooms.filter((r) => !ids.includes(r.id)));
    } else if (kind === 'scenes' && Array.isArray(homeDash.scenes)) {
        const map = new Map(homeDash.scenes.map((s) => [s.id, s]));
        homeDash.scenes = ids.map((id) => map.get(id)).filter(Boolean)
            .concat(homeDash.scenes.filter((s) => !ids.includes(s.id)));
    } else if (kind === 'devices' && Array.isArray(homeDash.devices)) {
        const map = new Map(homeDash.devices.map((d) => [d.id, d]));
        homeDash.devices = ids.map((id) => map.get(id)).filter(Boolean)
            .concat(homeDash.devices.filter((d) => !ids.includes(d.id)));
    } else if (kind === 'paper') {
        const tabletId = extra.tablet_id || homePaperTabletId;
        const paper = (homeDash.paper_devices || []).find((p) => p.id === tabletId);
        if (paper) paper.assigned = ids;
    }
}

async function homeCommitItemOrder(item) {
    const kind = homeReorderKind(item);
    if (kind === 'rooms') {
        const ids = [...document.querySelectorAll('#home-devices [data-home-room-group]')]
            .map((el) => el.getAttribute('data-home-room-group')).filter(Boolean);
        await homeCommitReorder('rooms', ids);
        return;
    }
    if (kind === 'groups') {
        const room = item.closest('[data-home-room-group]');
        const roomId = room?.getAttribute('data-home-room-group') || '';
        const ids = homeReorderSiblings(item).map((el) => el.getAttribute('data-home-group')).filter(Boolean);
        await homeCommitReorder('groups', ids, { room_id: roomId });
        return;
    }
    if (kind === 'devices') {
        const ids = [...document.querySelectorAll('#home-devices [data-home-id]')]
            .map((el) => el.getAttribute('data-home-id')).filter(Boolean);
        await homeCommitReorder('devices', ids);
        return;
    }
    if (kind === 'scenes') {
        const ids = [...document.querySelectorAll('#home-scenes [data-home-scene-card]')]
            .map((el) => el.getAttribute('data-home-scene-card')).filter(Boolean);
        await homeCommitReorder('scenes', ids);
        return;
    }
    if (kind === 'paper') {
        const tabletId = homePaperTabletId || '';
        await homeCommitReorder('paper', homePaperAssignedIds(), { tablet_id: tabletId });
    }
}

function bindHomeReorder() {
    const card = document.getElementById('home-card');
    if (!card || card.dataset.homeReorderBound === '1') return;
    card.dataset.homeReorderBound = '1';

    const onMove = (event) => {
        if (!homeDrag) return;
        const y = event.clientY;
        const siblings = homeReorderSiblings(homeDrag.item);
        for (const sib of siblings) {
            if (sib === homeDrag.item) continue;
            const rect = sib.getBoundingClientRect();
            const mid = rect.top + rect.height / 2;
            if (y < mid && (homeDrag.item.compareDocumentPosition(sib) & Node.DOCUMENT_POSITION_FOLLOWING)) {
                sib.parentElement?.insertBefore(homeDrag.item, sib);
                homeDrag.moved = true;
                break;
            }
            if (y > mid && (homeDrag.item.compareDocumentPosition(sib) & Node.DOCUMENT_POSITION_PRECEDING)) {
                sib.parentElement?.insertBefore(homeDrag.item, sib.nextSibling);
                homeDrag.moved = true;
                break;
            }
        }
    };

    const onUp = async () => {
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', onUp);
        window.removeEventListener('pointercancel', onUp);
        if (!homeDrag) return;
        const item = homeDrag.item;
        const moved = homeDrag.moved;
        item.classList.remove('home-item--dragging');
        document.body.classList.remove('home-is-reordering');
        homeDrag = null;
        if (!moved) return;
        try {
            await homeCommitItemOrder(item);
            showToast('Order saved', 'success');
        } catch (err) {
            showToast(err.message || 'Could not save order', 'error');
            await loadHomeDashboard();
        }
    };

    card.addEventListener('pointerdown', (event) => {
        if (!homeManageOpen) return;
        const handle = event.target.closest?.('.home-drag-handle');
        if (!handle || event.button) return;
        const item = homeReorderItemFromHandle(handle);
        if (!item) return;
        event.preventDefault();
        homeDrag = { item, moved: false };
        item.classList.add('home-item--dragging');
        document.body.classList.add('home-is-reordering');
        handle.setPointerCapture?.(event.pointerId);
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
        window.addEventListener('pointercancel', onUp);
    });
}

function homeRoomSelectHtml(d, rooms) {
    if (!homeManageOpen) return '';
    const current = d.room_id || '';
    const opts = ['<option value="">No room</option>'].concat(
        (rooms || []).map((r) => (
            `<option value="${escapeHtml(r.id)}"${r.id === current ? ' selected' : ''}>${escapeHtml(r.name)}</option>`
        ))
    );
    return `<select class="home-device-room-select" data-home-room-assign="${escapeHtml(d.id)}" aria-label="Room">${opts.join('')}</select>`;
}

function homeGroupSelectHtml(d, rooms) {
    if (!homeManageOpen || !d.room_id) return '';
    const room = (rooms || []).find((r) => r.id === d.room_id);
    const groups = room?.groups || [];
    const current = d.group_id || '';
    const opts = ['<option value="">No group</option>'].concat(
        groups.map((g) => (
            `<option value="${escapeHtml(g.id)}"${g.id === current ? ' selected' : ''}>${escapeHtml(g.name)}</option>`
        ))
    );
    return `<select class="home-device-group-select" data-home-group-assign="${escapeHtml(d.id)}" aria-label="Group">${opts.join('')}</select>`;
}

function homeRoomHeadingHtml(room) {
    const on = Boolean(room.on);
    const expanded = homeExpandedRooms.has(room.id);
    const bright = room.dimmable
        ? `<input type="range" min="0" max="100" value="${Number(room.brightness ?? (on ? 100 : 0))}" data-home-room-bright="${escapeHtml(room.id)}">`
        : '';
    const name = homeManageOpen
        ? `<input type="text" class="home-device-name-input" data-home-room-name="${escapeHtml(room.id)}" value="${escapeHtml(room.name)}" maxlength="32" aria-label="Room name">`
        : `<h3 class="home-room-title">${escapeHtml(room.name)}</h3>`;
    const count = Number(room.count || 0);
    return `<div class="home-room-group${expanded ? ' is-open' : ''}" data-home-room-group="${escapeHtml(room.id)}">
        <div class="home-room${on ? ' is-on' : ''}" data-home-room="${escapeHtml(room.id)}">
        ${homeReorderHandleHtml()}
        <button type="button" class="home-room-expand" data-home-room-expand="${escapeHtml(room.id)}" aria-expanded="${expanded ? 'true' : 'false'}" title="${expanded ? 'Hide lights in this room' : 'Show lights in this room'}" aria-label="${expanded ? 'Hide lights in this room' : 'Show lights in this room'}">${expanded ? '−' : '+'}</button>
        <div class="home-device-label">
            <span class="home-device-dot" aria-hidden="true"></span>
            ${name}
            ${count ? `<span class="home-room-count">${count}</span>` : ''}
        </div>
        <div class="home-device-actions">
            <button type="button" class="btn btn-secondary btn-compact" data-home-room-toggle="${escapeHtml(room.id)}">${on ? 'Off' : 'On'}</button>
            ${bright}
        </div>
        <div class="home-device-manage">
            <button type="button" class="btn btn-secondary btn-compact" data-home-room-del="${escapeHtml(room.id)}">Delete room</button>
        </div>
    </div>`;
}

function homeGroupHeadingHtml(group) {
    const on = Boolean(group.on);
    const expanded = homeExpandedGroups.has(group.id);
    const bright = group.dimmable
        ? `<input type="range" min="0" max="100" value="${Number(group.brightness ?? (on ? 100 : 0))}" data-home-group-bright="${escapeHtml(group.id)}">`
        : '';
    const name = homeManageOpen
        ? `<input type="text" class="home-device-name-input" data-home-group-name="${escapeHtml(group.id)}" value="${escapeHtml(group.name)}" maxlength="32" aria-label="Group name">`
        : `<h3 class="home-room-title">${escapeHtml(group.name)}</h3>`;
    const count = Number(group.count || 0);
    return `<div class="home-group${expanded ? ' is-open' : ''}" data-home-group="${escapeHtml(group.id)}">
        <div class="home-room home-group-heading${on ? ' is-on' : ''}">
        ${homeReorderHandleHtml()}
        <button type="button" class="home-room-expand" data-home-group-expand="${escapeHtml(group.id)}" aria-expanded="${expanded ? 'true' : 'false'}" title="${expanded ? 'Hide lights in this group' : 'Show lights in this group'}" aria-label="${expanded ? 'Hide lights in this group' : 'Show lights in this group'}">${expanded ? '−' : '+'}</button>
        <div class="home-device-label">
            <span class="home-device-dot" aria-hidden="true"></span>
            ${name}
            ${count ? `<span class="home-room-count">${count}</span>` : ''}
        </div>
        <div class="home-device-actions">
            <button type="button" class="btn btn-secondary btn-compact" data-home-group-toggle="${escapeHtml(group.id)}">${on ? 'Off' : 'On'}</button>
            ${bright}
        </div>
        <div class="home-device-manage">
            <button type="button" class="btn btn-secondary btn-compact" data-home-group-del="${escapeHtml(group.id)}">Delete group</button>
        </div>
    </div>`;
}

function homeGroupAddHtml(roomId) {
    if (!homeManageOpen) return '';
    return `<div class="home-group-add">
        <input type="text" maxlength="32" placeholder="Spots" data-home-group-new="${escapeHtml(roomId)}" aria-label="New group name">
        <button type="button" class="btn btn-secondary btn-compact" data-home-group-add="${escapeHtml(roomId)}">Add group</button>
    </div>`;
}

function homeNodeGroupsHtml(devices) {
    const groups = [];
    const byNode = new Map();
    devices.forEach((d) => {
        const key = String(d.node_id || d.id);
        if (!byNode.has(key)) {
            byNode.set(key, []);
            groups.push(key);
        }
        byNode.get(key).push(d);
    });
    const rooms = homeDash.rooms || [];
    return groups.map((key) => {
        const list = byNode.get(key) || [];
        const first = list[0] || {};
        const source = first.source || first.vendor || first.product || 'Matter device';
        const many = list.length > 1 || (homeManageOpen && Boolean(first.bridge));
        const heading = many
            ? `<div class="home-node-heading">
                    <h3 class="home-node-title">${escapeHtml(source)} · ${list.length} ${list.length === 1 ? 'item' : 'items'}</h3>
                    <button type="button" class="btn btn-secondary btn-compact" data-home-forget="${escapeHtml(String(first.node_id || ''))}" title="Unpair this whole Matter node (all lights on a Hue Bridge)">Remove all</button>
                </div>`
            : '';
        const cards = list.map((d) => homeDeviceCardHtml(d, false, rooms)).join('');
        return heading + cards;
    }).join('');
}

function homeDeviceCardHtml(d, hidden, rooms) {
    const on = Boolean(d.on);
    const unifi = homeIsUnifi(d);
    const bright = !hidden && !unifi && homeIsLight(d) && d.dimmable
        ? `<input type="range" min="0" max="100" value="${Number(d.brightness ?? (on ? 100 : 0))}" data-home-bright="${escapeHtml(d.id)}" class="home-bright" title="Brightness" aria-label="Brightness">`
        : '';
    const color = hidden || unifi ? '' : homeColorInputsHtml(
        d,
        on,
        d.color_hex,
        d.color_temp,
        `data-home-color="${escapeHtml(d.id)}"`,
        `data-home-kelvin="${escapeHtml(d.id)}"`
    );
    const meta = [d.kind, d.room, d.product && d.product !== d.name ? d.product : '']
        .filter(Boolean)
        .join(' · ');
    const defaultName = d.default_name || d.name || '';
    const kindName = homeKindLabel(d);
    const kindChip = kindName ? `<span class="home-device-kind">${escapeHtml(kindName)}</span>` : '';
    const heater = String(d.kind || '') === 'heater';
    const canToggle = !hidden && homeDeviceCanToggle(d) && !heater;
    const toggleClass = canToggle ? ' home-device--toggle' : '';
    const manage = hidden
        ? `<button type="button" class="btn btn-secondary btn-compact" data-home-unhide="${escapeHtml(d.id)}">Unhide</button>
           <button type="button" class="btn btn-secondary btn-compact" data-home-remove="${escapeHtml(d.id)}">Remove</button>`
        : `<button type="button" class="btn btn-secondary btn-compact" data-home-hide="${escapeHtml(d.id)}">Hide</button>
           <button type="button" class="btn btn-secondary btn-compact" data-home-remove="${escapeHtml(d.id)}">Remove</button>`;
    const label = homeManageOpen
        ? `<input type="text" class="home-device-name-input" data-home-name="${escapeHtml(d.id)}" value="${escapeHtml(d.name)}" placeholder="${escapeHtml(defaultName)}" maxlength="48" aria-label="Device name">`
        : `<p class="home-device-name">${escapeHtml(d.name)}</p>`;
    const roomSelect = hidden ? '' : homeRoomSelectHtml(d, rooms || homeDash.rooms || []);
    const groupSelect = hidden ? '' : homeGroupSelectHtml(d, rooms || homeDash.rooms || []);
    const dotStyle = on && d.color_hex
        ? ` style="background:${escapeHtml(d.color_hex)};box-shadow:0 0 0.35rem ${escapeHtml(d.color_hex)}"`
        : '';
    const actions = hidden ? '' : homeDeviceActionsHtml(d, bright, color);
    return `<article class="home-device${on ? ' is-on' : ''}${hidden ? ' home-device--hidden' : ''}${unifi ? ' home-device--unifi' : ''}${toggleClass}" data-home-id="${escapeHtml(d.id)}" title="${escapeHtml(meta)}">
        ${hidden ? '' : homeReorderHandleHtml()}
        <div class="home-device-label">
            <span class="home-device-dot" aria-hidden="true"${dotStyle}></span>
            ${label}
            ${kindChip}
        </div>
        ${roomSelect}
        ${groupSelect}
        ${actions}
        <div class="home-device-manage">${manage}</div>
    </article>`;
}

function homeDeviceActionsHtml(d, bright, color) {
    if (homeIsUnifi(d)) {
        const kind = String(d.kind || '');
        if (kind === 'camera') {
            const src = d.snapshot || `/api/unifi.php?action=snapshot&id=${encodeURIComponent(d.id)}`;
            return `<div class="home-device-actions home-device-actions--camera">
                <img class="unifi-thumb" src="${escapeHtml(src)}" alt="" loading="lazy">
            </div>`;
        }
        if (kind === 'sensor') {
            const status = String(d.status || '');
            const open = d.open === true || /^Open\b/i.test(status);
            return `<div class="home-device-actions"><span class="home-device-meta${open ? ' is-open' : ''}" data-home-status>${escapeHtml(d.status || '—')}</span></div>`;
        }
        if (kind === 'door' || kind === 'hub') {
            const gate = d.gate
                ? `<button type="button" class="btn btn-secondary btn-compact" data-home-unifi-cmd="open">Open</button>
                   <button type="button" class="btn btn-secondary btn-compact" data-home-unifi-cmd="close">Close</button>
                   <button type="button" class="btn btn-secondary btn-compact" data-home-unifi-cmd="stop">Stop</button>`
                : '';
            return `<div class="home-device-actions">
                ${unifiDpsMetaHtml(d, 'data-home-dps')}
                ${unifiDoorLockButtonHtml(d, { home: true })}
                ${gate}
            </div>`;
        }
        const on = Boolean(d.on);
        return `<div class="home-device-actions">
            <button type="button" class="btn btn-secondary btn-compact" data-home-toggle="${escapeHtml(d.id)}">${on ? 'Off' : 'On'}</button>
        </div>`;
    }
    if (String(d.kind || '') === 'heater') {
        return homeHeaterActionsHtml(d);
    }
    const on = Boolean(d.on);
    return `<div class="home-device-actions">
            <button type="button" class="btn btn-secondary btn-compact" data-home-toggle="${escapeHtml(d.id)}">${on ? 'Off' : 'On'}</button>
            ${bright}
            ${color}
        </div>`;
}

function homeFormatTemp(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '';
    return n.toFixed(1);
}

function homeHeaterActionsHtml(d) {
    const on = Boolean(d.on);
    const min = Number(d.heating_min ?? 5);
    const max = Number(d.heating_max ?? 35);
    const set = d.heating_setpoint != null && d.heating_setpoint !== ''
        ? Number(d.heating_setpoint)
        : 21;
    const room = d.local_temperature != null && d.local_temperature !== ''
        ? homeFormatTemp(d.local_temperature)
        : '';
    const roomHtml = room
        ? `<span class="home-heater-room" data-home-room-temp title="Room temperature">${escapeHtml(room)}°</span>`
        : `<span class="home-heater-room is-empty" data-home-room-temp title="Room temperature">—°</span>`;
    return `<div class="home-device-actions home-device-actions--heater">
            <button type="button" class="btn btn-secondary btn-compact${on ? ' is-active' : ''}" data-home-power="on">On</button>
            <button type="button" class="btn btn-secondary btn-compact${on ? '' : ' is-active'}" data-home-power="off">Off</button>
            ${roomHtml}
            <label class="home-heater-set">Set <input type="number" class="home-heater-setpoint" min="${min}" max="${max}" step="0.5" value="${escapeHtml(String(set))}" data-home-setpoint="${escapeHtml(d.id)}" inputmode="decimal" aria-label="Heating setpoint">°</label>
        </div>`;
}

function renderHomeSceneEditor() {
    const wrap = document.getElementById('home-scene-members');
    if (!wrap) return;
    const nameEl = document.getElementById('home-scene-name');
    if (nameEl && document.activeElement !== nameEl) {
        nameEl.value = homeSceneDraft.name || '';
    }
    const cancel = document.getElementById('home-scene-cancel');
    cancel?.classList.toggle('hidden', !homeSceneDraft.id);
    const save = document.getElementById('home-scene-save');
    if (save) save.textContent = homeSceneDraft.id ? 'Save changes' : 'Save scene';
    const hint = document.getElementById('home-scene-editor-hint');
    const selected = Object.values(homeSceneDraft.included).filter(Boolean).length;
    if (hint) {
        hint.textContent = homeSceneDraft.id
            ? `Editing “${homeSceneDraft.name || 'scene'}”. Tick lights this scene should change.`
            : (selected
                ? `${selected} light${selected === 1 ? '' : 's'} in this scene. Unticked lights are left alone.`
                : 'Tick only the lights this scene should change, or use lights that are on.');
    }
    const devices = (homeDash.devices || []).filter((d) => !homeIsUnifi(d));
    if (!devices.length) {
        wrap.innerHTML = '<p class="hint">Add Matter lights first, then pick which ones belong to the scene.</p>';
        return;
    }
    const rooms = homeDash.rooms || [];
    const byRoom = new Map(rooms.map((r) => [r.id, []]));
    const ungrouped = [];
    devices.forEach((d) => {
        if (d.room_id && byRoom.has(d.room_id)) byRoom.get(d.room_id).push(d);
        else ungrouped.push(d);
    });
    let html = '';
    rooms.forEach((room) => {
        const list = byRoom.get(room.id) || [];
        if (!list.length) return;
        html += `<p class="home-scene-room">${escapeHtml(room.name)}</p>`;
        html += list.map((d) => homeSceneMemberRowHtml(d)).join('');
    });
    if (ungrouped.length) {
        if (html) html += '<p class="home-scene-room">Ungrouped</p>';
        html += ungrouped.map((d) => homeSceneMemberRowHtml(d)).join('');
    }
    wrap.innerHTML = html;
}

function homeSceneMemberRowHtml(d) {
    const included = Boolean(homeSceneDraft.included[d.id]);
    const st = homeSceneStateForDevice(d);
    const on = Boolean(st.on);
    if (String(d.kind || '') === 'heater') {
        const min = Number(d.heating_min ?? 5);
        const max = Number(d.heating_max ?? 35);
        const set = st.setpoint != null ? Number(st.setpoint) : Number(d.heating_setpoint ?? 21);
        return `<div class="home-scene-member${included ? ' is-in' : ''}${included && on ? ' is-on' : ''}" data-scene-member="${escapeHtml(d.id)}">
        <label class="home-scene-member-pick"><input type="checkbox" data-scene-include ${included ? 'checked' : ''}> <span class="home-scene-member-name">${escapeHtml(d.name)}</span></label>
        <label class="home-scene-on"><input type="checkbox" data-scene-on ${on ? 'checked' : ''} ${included ? '' : 'disabled'}> On</label>
        <label class="home-heater-set">Set <input type="number" min="${min}" max="${max}" step="0.5" value="${escapeHtml(String(set))}" data-scene-setpoint ${included && on ? '' : 'disabled'} aria-label="Heating setpoint">°</label>
    </div>`;
    }
    const bright = d.dimmable
        ? `<input type="range" min="0" max="100" value="${Number(st.brightness ?? 100)}" data-scene-bright ${included && on ? '' : 'disabled'}>`
        : '';
    const color = homeColorInputsHtml(
        d,
        included && on,
        st.hex,
        st.kelvin,
        'data-scene-color',
        'data-scene-kelvin'
    );
    return `<div class="home-scene-member${included ? ' is-in' : ''}${included && on ? ' is-on' : ''}" data-scene-member="${escapeHtml(d.id)}">
        <label class="home-scene-member-pick"><input type="checkbox" data-scene-include ${included ? 'checked' : ''}> <span class="home-scene-member-name">${escapeHtml(d.name)}</span></label>
        <label class="home-scene-on"><input type="checkbox" data-scene-on ${on ? 'checked' : ''} ${included ? '' : 'disabled'}> On</label>
        ${bright}
        ${color}
    </div>`;
}

function renderHomeDashboard(data) {
    applyHomeSetupUi(data);
    applyHomeManageUi();
    const naming = document.activeElement?.closest?.('[data-home-name], [data-home-room-name], [data-home-group-name], [data-home-room-assign], [data-home-group-assign], [data-home-group-new], [data-home-color], [data-home-kelvin], [data-home-setpoint]');
    if (homeDrag) return;
    const card = document.getElementById('home-card');
    const devices = data.devices || [];
    card?.classList.toggle('home-card--empty', devices.length === 0 && !(data.fabric?.unreadable_files || []).length);
    const status = document.getElementById('home-server-status');
    if (status) {
        const err = data.server?.error;
        const fabric = data.fabric || {};
        let line = data.server?.ok
            ? `Matter server: connected · ${devices.length} device${devices.length === 1 ? '' : 's'}`
            : `Matter server: ${err || 'not running'}`;
        if (devices.length === 0) {
            const files = fabric.storage_files || [];
            const unread = fabric.unreadable_files || [];
            line += ` · disk ${files.length} file${files.length === 1 ? '' : 's'}`;
            if (unread.length) line += `, ${unread.length} unreadable`;
            if (fabric.storage_nodes) line += `, ${fabric.storage_nodes} node${fabric.storage_nodes === 1 ? '' : 's'}`;
            if (fabric.source) line += ` (${fabric.source})`;
        }
        status.textContent = line;
    }
    const hint = document.getElementById('home-setup-status');
    if (hint && data.fabric?.hint && devices.length === 0) {
        hint.textContent = data.fabric.hint;
        hint.classList.remove('hidden');
    }
    const grid = document.getElementById('home-devices');
    if (grid && !naming) {
        const devices = data.devices || [];
        if (!devices.length && !(data.rooms || []).length) {
            grid.innerHTML = '<p class="hint">No Matter devices yet. Add the Hue Bridge or another pairing code above. UniFi devices appear after you tick Show on Home in Settings → UniFi.</p>';
        } else {
            const rooms = data.rooms || [];
            const byRoom = new Map(rooms.map((r) => [r.id, []]));
            const ungrouped = [];
            devices.forEach((d) => {
                if (d.room_id && byRoom.has(d.room_id)) byRoom.get(d.room_id).push(d);
                else ungrouped.push(d);
            });
            let html = '';
            rooms.forEach((room) => {
                const list = byRoom.get(room.id) || [];
                if (!list.length && !homeManageOpen) return;
                const expanded = homeExpandedRooms.has(room.id);
                const groups = room.groups || [];
                html += homeRoomHeadingHtml(room);
                html += `<div class="home-room-devices"${expanded ? '' : ' hidden'}>`;
                groups.forEach((group) => {
                    const members = list.filter((d) => d.group_id === group.id);
                    if (!members.length && !homeManageOpen) return;
                    const groupOpen = homeExpandedGroups.has(group.id);
                    html += homeGroupHeadingHtml(group);
                    html += `<div class="home-group-devices"${groupOpen ? '' : ' hidden'}>`;
                    html += members.length
                        ? members.map((d) => homeDeviceCardHtml(d, false, rooms)).join('')
                        : (homeManageOpen ? '<p class="hint home-room-empty">No devices in this group yet. Pick it from a light’s group menu.</p>' : '');
                    html += '</div></div>';
                });
                const loose = list.filter((d) => !d.group_id || !groups.some((g) => g.id === d.group_id));
                html += loose.length
                    ? loose.map((d) => homeDeviceCardHtml(d, false, rooms)).join('')
                    : ((!groups.length && homeManageOpen) ? '<p class="hint home-room-empty">No devices in this room yet. Pick it from a light’s room menu.</p>' : '');
                html += homeGroupAddHtml(room.id);
                html += '</div></div>';
            });
            if (ungrouped.length) {
                if (html) {
                    html += '<div class="home-node-heading"><h3 class="home-node-title">Ungrouped</h3></div>';
                }
                html += homeNodeGroupsHtml(ungrouped);
            }
            grid.innerHTML = html || '<p class="hint">No Matter devices yet. Add the Hue Bridge or another pairing code above.</p>';
        }
    }
    const hiddenWrap = document.getElementById('home-hidden-wrap');
    const hiddenGrid = document.getElementById('home-hidden-devices');
    const hiddenDevices = data.hidden_devices || [];
    if (hiddenWrap && hiddenGrid && !naming) {
        hiddenWrap.classList.toggle('hidden', hiddenDevices.length === 0);
        hiddenGrid.innerHTML = hiddenDevices.map((d) => homeDeviceCardHtml(d, true)).join('');
    }
    const scenesEl = document.getElementById('home-scenes');
    const scenesBlock = document.getElementById('home-scenes-block');
    const scenes = data.scenes || [];
    if (scenesBlock) {
        scenesBlock.hidden = !homeManageOpen && scenes.length === 0;
    }
    if (scenesEl) {
        if (!homeManageOpen) {
            scenesEl.innerHTML = scenes.map((s) => (
                `<button type="button" class="btn${s.on ? '' : ' btn-secondary'}" data-home-scene="${escapeHtml(s.id)}">${escapeHtml(s.name)}</button>`
            )).join('');
        } else {
            scenesEl.innerHTML = scenes.map((s) => (
                `<article class="home-scene-card${s.on ? ' is-on' : ''}" data-home-scene-card="${escapeHtml(s.id)}">
                    ${homeReorderHandleHtml()}
                    <button type="button" class="home-scene-edit" data-home-scene-edit="${escapeHtml(s.id)}" title="Edit scene">
                        <strong>${escapeHtml(s.name)}</strong>
                        <span class="hint">${Number(s.count || (s.actions || []).length)} light${Number(s.count || (s.actions || []).length) === 1 ? '' : 's'}${s.on ? ' · on' : ''}</span>
                    </button>
                    <button type="button" class="btn btn-compact" data-home-scene="${escapeHtml(s.id)}">${s.on ? 'Off' : 'Run'}</button>
                    <button type="button" class="btn btn-secondary btn-compact" data-home-scene-del="${escapeHtml(s.id)}" title="Delete">×</button>
                </article>`
            )).join('') || '<p class="hint">No panel scenes yet. Tick lights below or use lights that are on, then save.</p>';
        }
    }
    const sceneEditorBusy = Boolean(document.activeElement?.closest?.('#home-scene-editor'));
    if (homeManageOpen && !sceneEditorBusy) {
        renderHomeSceneEditor();
    }
    renderHomePaperAssign(data);
}

async function saveHomeDeviceName(input) {
    const id = input.getAttribute('data-home-name') || '';
    if (!id || input.dataset.homeNameSaving === '1') return;
    const name = String(input.value || '').trim();
    const current = [...(homeDash.devices || []), ...(homeDash.hidden_devices || [])]
        .find((d) => d.id === id);
    if (current && String(current.name || '') === name) return;
    input.dataset.homeNameSaving = '1';
    try {
        const data = await homeApi({ action: 'rename', id, name });
        if (!data.ok) throw new Error(data.error || 'Could not rename');
        const next = name || String(current?.default_name || name);
        if (current) current.name = next;
        if (!name && current?.default_name) input.value = current.default_name;
        showToast('Name saved', 'success');
    } catch (err) {
        showToast(err.message || 'Could not rename', 'error');
        if (current) input.value = current.name || '';
    } finally {
        delete input.dataset.homeNameSaving;
    }
}

async function saveHomeRoomName(input) {
    const id = input.getAttribute('data-home-room-name') || '';
    if (!id || input.dataset.homeNameSaving === '1') return;
    const name = String(input.value || '').trim();
    const current = (homeDash.rooms || []).find((r) => r.id === id);
    if (current && String(current.name || '') === name) return;
    input.dataset.homeNameSaving = '1';
    try {
        const data = await homeApi({ action: 'room_save', id, name });
        if (!data.ok) throw new Error(data.error || 'Could not rename room');
        if (current && data.room?.name) current.name = data.room.name;
        showToast('Room name saved', 'success');
    } catch (err) {
        showToast(err.message || 'Could not rename room', 'error');
        if (current) input.value = current.name || '';
    } finally {
        delete input.dataset.homeNameSaving;
    }
}

async function saveHomeGroupName(input) {
    const id = input.getAttribute('data-home-group-name') || '';
    if (!id || input.dataset.homeNameSaving === '1') return;
    const name = String(input.value || '').trim();
    const current = (homeDash.rooms || []).flatMap((r) => r.groups || []).find((g) => g.id === id);
    if (current && String(current.name || '') === name) return;
    input.dataset.homeNameSaving = '1';
    try {
        const data = await homeApi({ action: 'group_save', id, name });
        if (!data.ok) throw new Error(data.error || 'Could not rename group');
        if (current && data.group?.name) current.name = data.group.name;
        showToast('Group name saved', 'success');
    } catch (err) {
        showToast(err.message || 'Could not rename group', 'error');
        if (current) input.value = current.name || '';
    } finally {
        delete input.dataset.homeNameSaving;
    }
}

async function sendHomeDeviceToggle(id, button, forceOn) {
    const card = document.querySelector(`[data-home-id="${CSS.escape(id)}"]`);
    const device = homeDeviceRecord(id);
    if (!homeDeviceCanToggle(device || { id })) return;
    const currentlyOn = card?.classList.contains('is-on') || Boolean(device?.on);
    const nextOn = typeof forceOn === 'boolean' ? forceOn : !currentlyOn;
    setHomeDeviceOn(id, nextOn);
    if (button) button.disabled = true;
    try {
        const data = await homeApi({
            action: 'command',
            id,
            command: nextOn ? 'on' : 'off',
        }, 25000);
        if (!data.ok) throw new Error(data.error || 'Failed');
        if (typeof data.on === 'boolean') setHomeDeviceOn(id, data.on);
    } catch (err) {
        setHomeDeviceOn(id, currentlyOn);
        showToast(err.message || 'Home command failed', 'error');
    } finally {
        if (button) button.disabled = false;
    }
}

async function sendHomeHeaterSetpoint(id, celsius, input) {
    const device = homeDeviceRecord(id);
    const previous = device?.heating_setpoint;
    const currentlyOn = Boolean(device?.on);
    if (device) device.heating_setpoint = celsius;
    setHomeDeviceOn(id, true);
    if (input) input.disabled = true;
    try {
        const data = await homeApi({
            action: 'command',
            id,
            command: 'setpoint',
            celsius,
        }, 25000);
        if (!data.ok) throw new Error(data.error || 'Failed');
        if (data.heating_setpoint != null && device) device.heating_setpoint = data.heating_setpoint;
        if (typeof data.on === 'boolean') setHomeDeviceOn(id, data.on);
        if (input && data.heating_setpoint != null) input.value = String(data.heating_setpoint);
    } catch (err) {
        if (device) device.heating_setpoint = previous;
        if (input && previous != null) input.value = String(previous);
        setHomeDeviceOn(id, currentlyOn);
        showToast(err.message || 'Could not set temperature', 'error');
    } finally {
        if (input) input.disabled = false;
    }
}

function bindHomeDashboard() {
    bindHomeReorder();
    document.getElementById('home-manage-toggle')?.addEventListener('click', () => {
        homeManageOpen = !homeManageOpen;
        applyHomeManageUi();
        if (homeDash) renderHomeDashboard(homeDash);
    });
    document.getElementById('home-card')?.addEventListener('focusout', (event) => {
        const deviceName = event.target.closest?.('[data-home-name]');
        if (deviceName) saveHomeDeviceName(deviceName);
        const roomName = event.target.closest?.('[data-home-room-name]');
        if (roomName) saveHomeRoomName(roomName);
        const groupName = event.target.closest?.('[data-home-group-name]');
        if (groupName) saveHomeGroupName(groupName);
    });
    document.getElementById('home-card')?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        const groupNew = event.target.closest?.('[data-home-group-new]');
        if (groupNew) {
            event.preventDefault();
            groupNew.closest('.home-group-add')?.querySelector('[data-home-group-add]')?.click();
            return;
        }
        const input = event.target.closest?.('[data-home-name], [data-home-room-name], [data-home-group-name]');
        if (!input) return;
        event.preventDefault();
        input.blur();
    });
    document.getElementById('home-room-save')?.addEventListener('click', async () => {
        const input = document.getElementById('home-room-name');
        const name = input?.value.trim() || '';
        const btn = document.getElementById('home-room-save');
        if (btn) btn.disabled = true;
        try {
            const data = await homeApi({ action: 'room_save', name });
            if (!data.ok) throw new Error(data.error || 'Could not add room');
            if (input) input.value = '';
            homeManageOpen = true;
            showToast('Room added', 'success');
            await loadHomeDashboard();
        } catch (err) {
            showToast(err.message || 'Could not add room', 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    });
    document.getElementById('home-room-name')?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        document.getElementById('home-room-save')?.click();
    });
    document.getElementById('home-card')?.addEventListener('click', async (event) => {
        if (event.target.closest('.home-drag-handle')) return;
        const moveBtn = event.target.closest('[data-home-move]');
        if (moveBtn) {
            const item = homeReorderItemFromHandle(moveBtn);
            if (!item) return;
            const dir = Number(moveBtn.getAttribute('data-home-move') || 0);
            const siblings = homeReorderSiblings(item);
            const index = siblings.indexOf(item);
            const next = index + dir;
            if (index < 0 || next < 0 || next >= siblings.length) return;
            if (dir < 0) siblings[next].before(item);
            else siblings[next].after(item);
            try {
                await homeCommitItemOrder(item);
                showToast('Order saved', 'success');
            } catch (err) {
                showToast(err.message || 'Could not save order', 'error');
                await loadHomeDashboard();
            }
            return;
        }
        const groupExpand = event.target.closest('[data-home-group-expand]');
        if (groupExpand) {
            const id = groupExpand.getAttribute('data-home-group-expand') || '';
            if (!id) return;
            if (homeExpandedGroups.has(id)) homeExpandedGroups.delete(id);
            else homeExpandedGroups.add(id);
            const wrap = groupExpand.closest('[data-home-group]');
            const open = homeExpandedGroups.has(id);
            wrap?.classList.toggle('is-open', open);
            const devices = wrap?.querySelector(':scope > .home-group-devices');
            if (devices) devices.hidden = !open;
            groupExpand.setAttribute('aria-expanded', open ? 'true' : 'false');
            groupExpand.textContent = open ? '−' : '+';
            groupExpand.title = open ? 'Hide lights in this group' : 'Show lights in this group';
            groupExpand.setAttribute('aria-label', groupExpand.title);
            return;
        }
        const expand = event.target.closest('[data-home-room-expand]');
        if (expand) {
            const id = expand.getAttribute('data-home-room-expand') || '';
            if (!id) return;
            if (homeExpandedRooms.has(id)) homeExpandedRooms.delete(id);
            else homeExpandedRooms.add(id);
            const group = expand.closest('[data-home-room-group]');
            const open = homeExpandedRooms.has(id);
            group?.classList.toggle('is-open', open);
            const devices = group?.querySelector(':scope > .home-room-devices');
            if (devices) devices.hidden = !open;
            expand.setAttribute('aria-expanded', open ? 'true' : 'false');
            expand.textContent = open ? '−' : '+';
            expand.title = open ? 'Hide lights in this room' : 'Show lights in this room';
            expand.setAttribute('aria-label', expand.title);
            return;
        }
        const forget = event.target.closest('[data-home-forget]');
        if (forget) {
            const nodeId = forget.getAttribute('data-home-forget') || '';
            if (!nodeId) return;
            if (!window.confirm('Remove this Matter device from the panel? Lights on a Hue Bridge are all removed together.')) {
                return;
            }
            forget.disabled = true;
            try {
                const data = await homeApi({ action: 'forget', node_id: Number(nodeId) }, 30000);
                if (!data.ok) throw new Error(data.error || 'Could not remove');
                showToast(data.message || 'Removed', 'success');
                await loadHomeDashboard();
            } catch (err) {
                showToast(err.message || 'Could not remove', 'error');
                forget.disabled = false;
            }
            return;
        }
        const hide = event.target.closest('[data-home-hide]');
        if (hide) {
            hide.disabled = true;
            try {
                const data = await homeApi({ action: 'hide', id: hide.getAttribute('data-home-hide') });
                if (!data.ok) throw new Error(data.error || 'Could not hide');
                showToast(data.message || 'Hidden', 'success');
                await loadHomeDashboard();
            } catch (err) {
                showToast(err.message || 'Could not hide', 'error');
                hide.disabled = false;
            }
            return;
        }
        const unhide = event.target.closest('[data-home-unhide]');
        if (unhide) {
            unhide.disabled = true;
            try {
                const data = await homeApi({ action: 'unhide', id: unhide.getAttribute('data-home-unhide') });
                if (!data.ok) throw new Error(data.error || 'Could not unhide');
                showToast(data.message || 'Shown again', 'success');
                await loadHomeDashboard();
            } catch (err) {
                showToast(err.message || 'Could not unhide', 'error');
                unhide.disabled = false;
            }
            return;
        }
        const removeBtn = event.target.closest('[data-home-remove]');
        if (removeBtn) {
            const id = removeBtn.getAttribute('data-home-remove') || '';
            if (!id) return;
            if (!window.confirm('Remove this from the panel? A Hue Bridge light cannot be unpaired on its own — that hides it. Removing a whole bridge unpairs every light on it.')) {
                return;
            }
            removeBtn.disabled = true;
            try {
                let data = await homeApi({ action: 'remove', id }, 30000);
                if (!data.ok && data.needs_hide) {
                    data = await homeApi({ action: 'hide', id });
                }
                if (!data.ok) throw new Error(data.error || 'Could not remove');
                showToast(data.message || 'Removed', 'success');
                await loadHomeDashboard();
            } catch (err) {
                showToast(err.message || 'Could not remove', 'error');
                removeBtn.disabled = false;
            }
            return;
        }
        const roomDel = event.target.closest('[data-home-room-del]');
        if (roomDel) {
            const id = roomDel.getAttribute('data-home-room-del') || '';
            if (!id) return;
            if (!window.confirm('Delete this room? Lights stay paired; they just become ungrouped.')) {
                return;
            }
            roomDel.disabled = true;
            try {
                const data = await homeApi({ action: 'room_delete', id });
                if (!data.ok) throw new Error(data.error || 'Could not delete room');
                showToast('Room deleted', 'success');
                await loadHomeDashboard();
            } catch (err) {
                showToast(err.message || 'Could not delete room', 'error');
                roomDel.disabled = false;
            }
            return;
        }
        const groupDel = event.target.closest('[data-home-group-del]');
        if (groupDel) {
            const id = groupDel.getAttribute('data-home-group-del') || '';
            if (!id) return;
            if (!window.confirm('Delete this group? Lights stay in the room.')) {
                return;
            }
            groupDel.disabled = true;
            try {
                const data = await homeApi({ action: 'group_delete', id });
                if (!data.ok) throw new Error(data.error || 'Could not delete group');
                showToast('Group deleted', 'success');
                await loadHomeDashboard();
            } catch (err) {
                showToast(err.message || 'Could not delete group', 'error');
                groupDel.disabled = false;
            }
            return;
        }
        const groupAdd = event.target.closest('[data-home-group-add]');
        if (groupAdd) {
            const roomId = groupAdd.getAttribute('data-home-group-add') || '';
            const input = groupAdd.closest('.home-group-add')?.querySelector('[data-home-group-new]');
            const name = input?.value.trim() || '';
            groupAdd.disabled = true;
            try {
                const data = await homeApi({ action: 'group_save', room_id: roomId, name });
                if (!data.ok) throw new Error(data.error || 'Could not add group');
                if (input) input.value = '';
                if (data.group?.id) homeExpandedGroups.add(data.group.id);
                homeExpandedRooms.add(roomId);
                showToast('Group added', 'success');
                await loadHomeDashboard();
            } catch (err) {
                showToast(err.message || 'Could not add group', 'error');
            } finally {
                groupAdd.disabled = false;
            }
            return;
        }
        const groupToggle = event.target.closest('[data-home-group-toggle]');
        if (groupToggle) {
            const id = groupToggle.getAttribute('data-home-group-toggle') || '';
            const group = (homeDash.rooms || []).flatMap((r) => r.groups || []).find((g) => g.id === id);
            const currentlyOn = Boolean(group?.on);
            const nextOn = !currentlyOn;
            if (group) group.on = nextOn;
            groupToggle.textContent = nextOn ? 'Off' : 'On';
            groupToggle.closest('.home-group-heading')?.classList.toggle('is-on', nextOn);
            (homeDash.devices || []).filter((d) => d.group_id === id).forEach((d) => setHomeDeviceOn(d.id, nextOn));
            groupToggle.disabled = true;
            try {
                const data = await homeApi({
                    action: 'group_command',
                    group_id: id,
                    command: nextOn ? 'on' : 'off',
                }, 90000);
                if (!data.ok) throw new Error(data.error || 'Failed');
            } catch (err) {
                if (group) group.on = currentlyOn;
                (homeDash.devices || []).filter((d) => d.group_id === id).forEach((d) => setHomeDeviceOn(d.id, currentlyOn));
                showToast(err.message || 'Group command failed', 'error');
            } finally {
                groupToggle.disabled = false;
            }
            return;
        }
        const roomToggle = event.target.closest('[data-home-room-toggle]');
        if (roomToggle) {
            const id = roomToggle.getAttribute('data-home-room-toggle') || '';
            const room = (homeDash.rooms || []).find((r) => r.id === id);
            const currentlyOn = Boolean(room?.on);
            const nextOn = !currentlyOn;
            if (room) room.on = nextOn;
            roomToggle.textContent = nextOn ? 'Off' : 'On';
            roomToggle.closest('[data-home-room]')?.classList.toggle('is-on', nextOn);
            (homeDash.devices || []).filter((d) => d.room_id === id).forEach((d) => setHomeDeviceOn(d.id, nextOn));
            roomToggle.disabled = true;
            try {
                const data = await homeApi({
                    action: 'room_command',
                    room_id: id,
                    command: nextOn ? 'on' : 'off',
                }, 90000);
                if (!data.ok) throw new Error(data.error || 'Failed');
            } catch (err) {
                if (room) room.on = currentlyOn;
                (homeDash.devices || []).filter((d) => d.room_id === id).forEach((d) => setHomeDeviceOn(d.id, currentlyOn));
                showToast(err.message || 'Room command failed', 'error');
            } finally {
                roomToggle.disabled = false;
            }
            return;
        }
        const unifiBtn = event.target.closest('[data-home-unifi-unlock], [data-home-unifi-cmd]');
        if (unifiBtn) {
            const card = unifiBtn.closest('[data-home-id]');
            const id = card?.getAttribute('data-home-id') || '';
            const cmd = unifiBtn.getAttribute('data-home-unifi-cmd') || 'unlock';
            unifiBtn.disabled = true;
            try {
                const data = await homeApi({
                    action: 'command',
                    id,
                    command: (cmd === 'lock' ? 'unlock' : cmd),
                    control_cmd: (cmd === 'unlock' || cmd === 'lock') ? '' : cmd,
                });
                if (!data.ok) throw new Error(data.error || 'Failed');
                showToast(unifiDoorCommandToast(cmd), 'success');
                await loadHomeDashboard();
            } catch (err) {
                showToast(err.message || 'UniFi command failed', 'error');
            } finally {
                unifiBtn.disabled = false;
            }
            return;
        }
        const btn = event.target.closest('[data-home-toggle]');
        if (btn) {
            await sendHomeDeviceToggle(btn.getAttribute('data-home-toggle') || '', btn);
            return;
        }
        const power = event.target.closest('[data-home-power]');
        if (power) {
            const card = power.closest('[data-home-id]');
            const id = card?.getAttribute('data-home-id') || '';
            await sendHomeDeviceToggle(id, power, power.getAttribute('data-home-power') === 'on');
            return;
        }
        const row = event.target.closest('[data-home-id].home-device--toggle');
        if (!row || event.target.closest('button, input, select, a, .home-drag-handle, .home-reorder-controls, .home-device-manage')) {
            return;
        }
        await sendHomeDeviceToggle(row.getAttribute('data-home-id') || '');
    });
    document.getElementById('home-devices')?.addEventListener('change', async (event) => {
        const assign = event.target.closest('[data-home-room-assign]');
        if (assign) {
            try {
                const data = await homeApi({
                    action: 'room',
                    id: assign.getAttribute('data-home-room-assign'),
                    room_id: assign.value,
                });
                if (!data.ok) throw new Error(data.error || 'Could not assign room');
                await loadHomeDashboard();
            } catch (err) {
                showToast(err.message || 'Could not assign room', 'error');
            }
            return;
        }
        const groupAssign = event.target.closest('[data-home-group-assign]');
        if (groupAssign) {
            try {
                const data = await homeApi({
                    action: 'group',
                    id: groupAssign.getAttribute('data-home-group-assign'),
                    group_id: groupAssign.value,
                });
                if (!data.ok) throw new Error(data.error || 'Could not assign group');
                await loadHomeDashboard();
            } catch (err) {
                showToast(err.message || 'Could not assign group', 'error');
            }
            return;
        }
        const groupBright = event.target.closest('[data-home-group-bright]');
        if (groupBright) {
            try {
                const data = await homeApi({
                    action: 'group_command',
                    group_id: groupBright.getAttribute('data-home-group-bright'),
                    command: 'brightness',
                    brightness: Number(groupBright.value),
                }, 90000);
                if (!data.ok) throw new Error(data.error || 'Failed');
            } catch (err) {
                showToast(err.message || 'Group brightness failed', 'error');
            }
            return;
        }
        const roomBright = event.target.closest('[data-home-room-bright]');
        if (roomBright) {
            try {
                const data = await homeApi({
                    action: 'room_command',
                    room_id: roomBright.getAttribute('data-home-room-bright'),
                    command: 'brightness',
                    brightness: Number(roomBright.value),
                }, 90000);
                if (!data.ok) throw new Error(data.error || 'Failed');
            } catch (err) {
                showToast(err.message || 'Room brightness failed', 'error');
            }
            return;
        }
        const input = event.target.closest('[data-home-bright]');
        if (input) {
            try {
                const data = await homeApi({
                    action: 'command',
                    id: input.getAttribute('data-home-bright'),
                    command: 'brightness',
                    brightness: Number(input.value),
                });
                if (!data.ok) throw new Error(data.error || 'Failed');
            } catch (err) {
                showToast(err.message || 'Brightness failed', 'error');
            }
            return;
        }
        const setpoint = event.target.closest('[data-home-setpoint]');
        if (setpoint) {
            const celsius = Number(setpoint.value);
            if (!Number.isFinite(celsius) || celsius <= 0) {
                showToast('Set a heating temperature', 'error');
                return;
            }
            await sendHomeHeaterSetpoint(setpoint.getAttribute('data-home-setpoint') || '', celsius, setpoint);
            return;
        }
        const color = event.target.closest('[data-home-color]');
        if (color) {
            const id = color.getAttribute('data-home-color');
            const hex = color.value;
            try {
                setHomeDeviceOn(id, true);
                patchHomeDeviceVisual(id, true, { color_hex: hex });
                const data = await homeApi({
                    action: 'command',
                    id,
                    command: 'color',
                    hex,
                });
                if (!data.ok) throw new Error(data.error || 'Failed');
            } catch (err) {
                showToast(err.message || 'Colour failed', 'error');
            }
            return;
        }
        const kelvin = event.target.closest('[data-home-kelvin]');
        if (kelvin) {
            try {
                const data = await homeApi({
                    action: 'command',
                    id: kelvin.getAttribute('data-home-kelvin'),
                    command: 'color_temp',
                    kelvin: Number(kelvin.value),
                });
                if (!data.ok) throw new Error(data.error || 'Failed');
            } catch (err) {
                showToast(err.message || 'Colour temperature failed', 'error');
            }
        }
    });
    bindHomeAutomations();
}

function homeAutoHashOpen() {
    return (location.hash || '').replace(/^#/, '') === 'automations';
}

function homeAutoNames() {
    const names = {};
    (homeDash.devices || []).forEach((d) => {
        if (d?.id) names[d.id] = d.name || d.id;
    });
    (homeDash.scenes || []).forEach((s) => {
        if (s?.id) names[`scene:${s.id}`] = s.name || 'Scene';
    });
    return names;
}

function homeAutoThenOk(d) {
    if (!d) return false;
    if (homeIsUnifi(d)) {
        const kind = String(d.kind || '');
        return kind === 'light' || kind === 'relay' || kind === 'door' || kind === 'hub';
    }
    const kind = String(d.kind || 'light');
    return kind !== 'camera' && kind !== 'sensor' && kind !== 'door' && kind !== 'hub';
}

function homeAutoDurationEvent(event) {
    return ['stays_on', 'stays_off', 'stays_open', 'stays_closed', 'no_motion'].includes(event);
}

function homeAutoKind(d) {
    return String(d?.kind || '');
}

function homeAutoLooksLikeMotionName(d) {
    return /motion|occupancy|presence|pir/i.test(`${d?.product || ''} ${d?.name || ''}`);
}

function homeAutoLooksLikeContactName(d) {
    return /contact|door|window|magnet|leak/i.test(`${d?.product || ''} ${d?.name || ''}`);
}

function homeAutoCanOpen(d) {
    if (!d) return false;
    const kind = homeAutoKind(d);
    if (kind === 'door' || kind === 'hub') return true;
    if (['light', 'plug', 'switch', 'heater', 'relay', 'vacuum', 'scene', 'camera'].includes(kind)) return false;
    if (homeAutoLooksLikeMotionName(d) && !homeAutoLooksLikeContactName(d)) return false;
    if (d.has_open === true || d.has_dps === true) return true;
    return kind === 'sensor' && homeAutoLooksLikeContactName(d);
}

function homeAutoCanMotion(d) {
    if (!d) return false;
    const kind = homeAutoKind(d);
    if (kind === 'camera') return true;
    if (['light', 'plug', 'switch', 'heater', 'relay', 'vacuum', 'door', 'hub', 'scene'].includes(kind)) return false;
    if (d.has_motion === true) return true;
    if (homeAutoLooksLikeMotionName(d)) return true;
    return kind === 'sensor' && !homeAutoCanOpen(d);
}

function homeAutoCanOnOff(d) {
    return ['light', 'plug', 'switch', 'heater', 'relay', 'vacuum'].includes(homeAutoKind(d));
}

function homeAutoWhenEvents(d) {
    if (!d) return [];
    const events = [];
    if (homeAutoCanMotion(d)) {
        events.push(['motion', 'motion'], ['no_motion', 'no motion for']);
    }
    if (homeAutoCanOpen(d)) {
        events.push(['opens', 'opens'], ['closes', 'closes'], ['stays_open', 'is open for'], ['stays_closed', 'is closed for']);
    }
    if (d.kind === 'sensor' || d.kind === 'camera') {
        if (d.temperature != null) {
            events.push(['temp_above', 'temperature above'], ['temp_below', 'temperature below']);
        }
        if (d.humidity != null) {
            events.push(['hum_above', 'humidity above'], ['hum_below', 'humidity below']);
        }
    }
    if (homeAutoCanOnOff(d) || events.length === 0) {
        events.push(['turns_on', 'turns on'], ['turns_off', 'turns off'], ['stays_on', 'is on for'], ['stays_off', 'is off for']);
    }
    return events;
}

function homeAutoThenCanOffAfter(action) {
    const cmd = String(action?.command || (action?.kind === 'scene' ? 'run' : 'on'));
    return !['off', 'stop', 'unlock', 'lock', 'open', 'close'].includes(cmd);
}

function homeAutoOffAfterChoices(sec) {
    const n = Math.max(0, Number(sec) || 0);
    const opts = [
        [0, 'stay on'],
        [60, 'off 1 min'],
        [120, 'off 2 min'],
        [180, 'off 3 min'],
        [300, 'off 5 min'],
        [600, 'off 10 min'],
        [900, 'off 15 min'],
        [1200, 'off 20 min'],
        [1800, 'off 30 min'],
        [3600, 'off 1 hr'],
    ];
    if (n > 0 && !opts.some(([v]) => v === n)) {
        opts.splice(1, 0, [n, `off ${homeAutoFormatDuration(n)}`]);
    }
    return opts;
}

function homeAutoApplyOffAfterToActions(sec) {
    if (!autoDraft || !Array.isArray(autoDraft.actions)) return;
    const n = Math.max(0, Number(sec) || 0);
    autoDraft.actions = autoDraft.actions.map((action) => {
        if (!homeAutoThenCanOffAfter(action)) return action;
        return { ...action, off_after_sec: n };
    });
}

function homeAutoThenCommands(d) {
    if (!d) return [['on', 'On']];
    if (d.kind === 'scene' || String(d.id || '').startsWith('scene:')) {
        return [['run', 'Run'], ['stop', 'Off']];
    }
    if (homeIsUnifi(d) && (d.kind === 'door' || d.kind === 'hub')) {
        if (d.gate) return [['open', 'Open'], ['close', 'Close'], ['stop', 'Stop']];
        return [['unlock', 'Unlock']];
    }
    return [['on', 'On'], ['off', 'Off']];
}

function homeAutoThenCanBright(d) {
    return Boolean(d && !homeIsUnifi(d) && d.kind !== 'heater' && d.kind !== 'vacuum' && d.dimmable);
}

function homeAutoThenCanColor(d) {
    return Boolean(d && !homeIsUnifi(d) && d.kind !== 'heater' && (d.colorable || d.color_hs || d.color_xy));
}

function homeAutoThenCanKelvin(d) {
    return Boolean(d && !homeIsUnifi(d) && d.kind !== 'heater' && d.color_ct && !homeAutoThenCanColor(d));
}

function homeAutoThenCanSetpoint(d) {
    return Boolean(d && d.kind === 'heater');
}

function homeAutoThenShowsLook(cmd) {
    return !['off', 'stop', 'unlock', 'lock', 'open', 'close'].includes(String(cmd || 'on'));
}

function homeAutoThenParamsHtml(d, action, cmd) {
    if (!homeAutoThenShowsLook(cmd)) return '';
    let html = '';
    if (homeAutoThenCanBright(d)) {
        const n = Number(action.brightness ?? d.brightness ?? 100);
        const bright = Number.isFinite(n) && n > 0 ? n : 100;
        html += `<label class="auto-then-set"><input type="number" min="1" max="100" data-auto-bright value="${bright}" aria-label="Brightness" title="Brightness">%</label>`;
    }
    if (homeAutoThenCanColor(d)) {
        const hex = action.hex || action.color_hex || d.color_hex || '#ffd27a';
        html += `<input type="color" data-auto-hex value="${escapeHtml(hex)}" aria-label="Colour" title="Colour">`;
    } else if (homeAutoThenCanKelvin(d)) {
        const min = Number(d.color_temp_min || 2000);
        const max = Number(d.color_temp_max || 6500);
        const kelvinRaw = Number(action.kelvin ?? action.color_temp ?? d.color_temp ?? 2700);
        const kelvin = Number.isFinite(kelvinRaw) ? kelvinRaw : 2700;
        html += `<label class="auto-then-set"><input type="number" min="${min}" max="${max}" step="50" data-auto-kelvin value="${kelvin}" aria-label="Colour temperature" title="Colour temperature (K)">K</label>`;
    }
    if (homeAutoThenCanSetpoint(d)) {
        const min = Number(d.heating_min ?? 5);
        const max = Number(d.heating_max ?? 35);
        const setRaw = Number(action.celsius ?? action.heating_setpoint ?? d.heating_setpoint ?? 21);
        const set = Number.isFinite(setRaw) ? setRaw : 21;
        html += `<label class="auto-then-set"><input type="number" min="${min}" max="${max}" step="0.5" data-auto-celsius value="${escapeHtml(String(set))}" aria-label="Heating setpoint">°</label>`;
    }
    return html;
}

function homeAutoDefaultWhen(d) {
    if (!d) return null;
    if (homeAutoCanMotion(d)) {
        return { type: 'device', id: d.id, event: 'motion' };
    }
    if (homeAutoCanOpen(d)) {
        return { type: 'device', id: d.id, event: 'opens' };
    }
    return { type: 'device', id: d.id, event: 'turns_on' };
}

function homeAutoLooksLikeMotion(d) {
    return homeAutoCanMotion(d);
}

function homeAutoDefaultThen(d) {
    if (!d) return null;
    if (d.kind === 'scene' || String(d.id || '').startsWith('scene:')) {
        return { kind: 'scene', id: String(d.id).replace(/^scene:/, ''), command: 'run' };
    }
    if (homeIsUnifi(d) && (d.kind === 'door' || d.kind === 'hub')) {
        return { kind: 'device', id: d.id, command: d.gate ? 'open' : 'unlock' };
    }
    if (!homeAutoThenOk(d)) return null;
    const action = { kind: 'device', id: d.id, command: 'on', device_kind: d.kind || 'light' };
    if (homeAutoThenCanBright(d)) {
        const n = Number(d.brightness);
        action.brightness = Number.isFinite(n) && n > 0 ? n : 100;
    }
    if (homeAutoThenCanColor(d)) action.hex = d.color_hex || '#ffd27a';
    if (homeAutoThenCanKelvin(d)) action.kelvin = Number(d.color_temp || 2700);
    if (homeAutoThenCanSetpoint(d)) action.celsius = Number(d.heating_setpoint ?? 21);
    return action;
}

function homeAutoAllDevices() {
    const seen = {};
    const out = [];
    const lists = [
        homeDash.automation_devices || [],
        homeDash.devices || [],
        homeDash.hidden_devices || [],
    ];
    const overlay = ['dimmable', 'colorable', 'color_hs', 'color_xy', 'color_ct', 'color_hex', 'color_temp',
        'color_temp_min', 'color_temp_max', 'brightness', 'kind', 'heating_setpoint', 'heating_min', 'heating_max',
        'has_thermostat', 'product', 'name'];
    lists.forEach((list) => {
        list.forEach((d) => {
            if (!d || !d.id) return;
            if (seen[d.id]) {
                overlay.forEach((key) => {
                    if (d[key] != null && d[key] !== '') seen[d.id][key] = d[key];
                });
                return;
            }
            const row = { ...d };
            seen[d.id] = row;
            out.push(row);
        });
    });
    return out;
}

function homeAutoDeviceById(id) {
    return homeAutoAllDevices().find((row) => row.id === id) || null;
}

function homeAutoSceneById(id) {
    return (homeDash.scenes || []).find((row) => row.id === id) || null;
}

function homeAutoFormatDuration(sec) {
    const n = Number(sec) || 0;
    if (n % 3600 === 0 && n >= 3600) return `${n / 3600} hr`;
    if (n % 60 === 0 && n >= 60) return `${n / 60} min`;
    return `${n} sec`;
}

function homeAutoWhenPhrase(trigger, names) {
    if (!trigger) return 'When';
    if (trigger.type === 'time') return trigger.at ? `At ${trigger.at}` : 'At a time';
    if (trigger.type === 'sun') {
        const label = trigger.event === 'sunrise' ? 'Sunrise' : 'Sunset';
        const off = Number(trigger.offset_min || 0);
        if (!off) return label;
        return `${label} ${off < 0 ? '' : '+'}${off} min`;
    }
    if (trigger.type === 'threshold') {
        const name = names[trigger.id] || 'Sensor';
        const unit = trigger.metric === 'humidity' ? '%' : '°';
        return `${name} ${trigger.metric} ${trigger.op} ${trigger.value}${unit}`;
    }
    const name = names[trigger.id] || 'Device';
    const event = trigger.event || 'turns_on';
    const labels = {
        turns_on: 'turns on',
        turns_off: 'turns off',
        opens: 'opens',
        closes: 'closes',
        motion: 'motion',
        stays_on: 'on',
        stays_off: 'off',
        stays_open: 'open',
        stays_closed: 'closed',
        no_motion: 'no motion',
    };
    if (homeAutoDurationEvent(event) && trigger.for_sec) {
        return `${name} ${labels[event] || event} ${homeAutoFormatDuration(trigger.for_sec)}`;
    }
    return `${name} ${labels[event] || event}`;
}

function homeAutoThenLookIndex(el) {
    return Number(el.closest('[data-auto-then]')?.getAttribute('data-auto-then') || 0);
}

function homeAutoApplyThenLook(el) {
    if (!autoDraft || !el) return;
    const action = autoDraft.actions?.[homeAutoThenLookIndex(el)];
    if (!action) return;
    if (el.matches('[data-auto-bright]')) {
        const n = Number(el.value);
        if (Number.isFinite(n)) action.brightness = Math.max(1, Math.min(100, Math.round(n)));
    }
    if (el.matches('[data-auto-hex]')) action.hex = el.value;
    if (el.matches('[data-auto-kelvin]')) {
        const n = Number(el.value);
        if (Number.isFinite(n)) action.kelvin = Math.round(n);
    }
    if (el.matches('[data-auto-celsius]')) {
        const n = Number(el.value);
        if (Number.isFinite(n)) action.celsius = n;
    }
}

function homeAutoReadThenLooks() {
    if (!autoDraft) return;
    document.querySelectorAll('#auto-then-chips [data-auto-bright], #auto-then-chips [data-auto-hex], #auto-then-chips [data-auto-kelvin], #auto-then-chips [data-auto-celsius]').forEach((el) => {
        homeAutoApplyThenLook(el);
    });
}

function homeAutoThenPhrase(action, names) {
    if (action.kind === 'scene') {
        const name = names[`scene:${action.id}`] || names[action.id] || 'Scene';
        let text = action.command === 'stop' ? `${name} off` : name;
        const off = Number(action.off_after_sec || 0);
        if (off > 0) text += `, off after ${homeAutoFormatDuration(off)}`;
        return text;
    }
    const name = names[action.id] || 'Device';
    if (action.command === 'off') return `${name} off`;
    if (action.command === 'unlock') return `${name} unlock`;
    if (action.celsius != null || action.heating_setpoint != null) {
        let text = `${name} ${action.celsius ?? action.heating_setpoint}°`;
        const off = Number(action.off_after_sec || 0);
        if (off > 0) text += `, off after ${homeAutoFormatDuration(off)}`;
        return text;
    }
    if (action.brightness != null || action.command === 'brightness') {
        let text = `${name} ${action.brightness || 100}%`;
        const off = Number(action.off_after_sec || 0);
        if (off > 0) text += `, off after ${homeAutoFormatDuration(off)}`;
        return text;
    }
    let text = `${name} on`;
    const off = Number(action.off_after_sec || 0);
    if (off > 0) text += `, off after ${homeAutoFormatDuration(off)}`;
    return text;
}

function homeAutoSentence(rule, names) {
    const then = (rule.actions || []).map((action) => homeAutoThenPhrase(action, names)).join(', ') || '…';
    let text = `${homeAutoWhenJoinPhrase(rule, names)} → ${then}`;
    const actionOff = (rule.actions || []).some((action) => Number(action.off_after_sec || 0) > 0);
    const off = Number(rule.off_after_sec || 0);
    if (!actionOff && off > 0) text += `, off after ${homeAutoFormatDuration(off)}`;
    return text;
}

function homeAutoTriggers(rule = autoDraft) {
    if (!rule) return [];
    if (Array.isArray(rule.triggers) && rule.triggers.length) return rule.triggers.filter(Boolean);
    return rule.trigger ? [rule.trigger] : [];
}

function homeAutoWhenMatch(rule = autoDraft) {
    return String(rule?.when_match || 'any') === 'all' ? 'all' : 'any';
}

function homeAutoSetTriggers(list) {
    if (!autoDraft) return;
    autoDraft.triggers = (list || []).filter(Boolean);
    autoDraft.trigger = autoDraft.triggers[0] || null;
    if (autoDraft.triggers.length < 2) autoDraft.when_match = autoDraft.when_match === 'all' ? 'all' : 'any';
}

function homeAutoWhenJoinPhrase(rule, names) {
    const parts = homeAutoTriggers(rule).map((trigger) => homeAutoWhenPhrase(trigger, names));
    if (!parts.length) return 'When';
    return parts.join(homeAutoWhenMatch(rule) === 'all' ? ' and ' : ' or ');
}

function homeAutoBlankDraft() {
    return {
        id: '',
        name: '',
        enabled: true,
        trigger: null,
        triggers: [],
        when_match: 'any',
        conditions: [],
        actions: [],
        off_after_sec: 0,
        cooldown_sec: 30,
    };
}

function homeAutoSyncName() {
    if (!autoDraft || autoNameLocked) return;
    autoDraft.name = homeAutoSentence(autoDraft, homeAutoNames());
    const input = document.getElementById('auto-name');
    if (input && document.activeElement !== input) input.value = autoDraft.name;
}

function homeAutoSunNeedsCoords() {
    return homeAutoTriggers().some((trigger) => trigger?.type === 'sun') && Boolean((homeDash.sun_coords || {}).needs_coords);
}

function homeAutoHasCoords() {
    const coords = homeDash.sun_coords || {};
    return Number.isFinite(Number(coords.latitude)) && Number.isFinite(Number(coords.longitude));
}

function homeAutoRoundCoord(n) {
    return Math.round(Number(n) * 10000) / 10000;
}

async function homeAutoApplyBrowserCoords(lat, lon) {
    const latitude = homeAutoRoundCoord(lat);
    const longitude = homeAutoRoundCoord(lon);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;
    const latEl = document.getElementById('auto-lat');
    const lonEl = document.getElementById('auto-lon');
    if (latEl && document.activeElement !== latEl) latEl.value = String(latitude);
    if (lonEl && document.activeElement !== lonEl) lonEl.value = String(longitude);
    homeDash.sun_coords = {
        ...(homeDash.sun_coords || {}),
        latitude,
        longitude,
        source: 'browser',
        needs_coords: false,
    };
    try {
        const data = await homeApi({ action: 'automation_save', latitude, longitude });
        if (data.sun_coords) homeDash.sun_coords = data.sun_coords;
    } catch {
        // Fields still hold the browser GPS if save fails.
    }
    if (autoDraft) renderHomeAutoEditor();
}

function homeAutoRequestBrowserGps({ force = false } = {}) {
    const coords = homeDash.sun_coords || {};
    if (!force && homeAutoHasCoords() && !coords.needs_coords) return;
    const hint = document.getElementById('auto-coords-hint');
    if (!navigator.geolocation) {
        if (hint && (force || homeAutoSunNeedsCoords())) {
            hint.hidden = false;
            hint.textContent = 'This browser cannot share GPS. Enter latitude and longitude, or use the last Yarbo GPS.';
        }
        return;
    }
    if (homeAutoGeoBusy) return;
    homeAutoGeoBusy = true;
    navigator.geolocation.getCurrentPosition(
        async (pos) => {
            homeAutoGeoBusy = false;
            await homeAutoApplyBrowserCoords(pos.coords.latitude, pos.coords.longitude);
        },
        (err) => {
            homeAutoGeoBusy = false;
            if (!hint) return;
            hint.hidden = false;
            if (err?.code === 1) {
                hint.textContent = 'Location permission was denied. Enter latitude and longitude, or allow location for this page.';
            } else {
                hint.textContent = 'Could not read this browser’s GPS. Enter latitude and longitude, or try Use my location.';
            }
        },
        { enableHighAccuracy: false, timeout: 10000, maximumAge: 30 * 60 * 1000 }
    );
}

function homeAutoTimezoneList() {
    let zones = [];
    try {
        if (typeof Intl !== 'undefined' && typeof Intl.supportedValuesOf === 'function') {
            zones = Intl.supportedValuesOf('timeZone');
        }
    } catch {
        zones = [];
    }
    if (!zones.length) {
        zones = ['UTC', 'Europe/London', 'Europe/Dublin', 'Europe/Paris', 'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'Australia/Sydney'];
    }
    const extra = [homeDash.timezone?.name, homeDash.server_timezone, clientTimezone()].filter(Boolean);
    extra.forEach((z) => {
        if (!zones.includes(z)) zones = [z, ...zones];
    });
    return zones;
}

function homeAutoFillTimezoneSelect(selected) {
    const el = document.getElementById('auto-timezone');
    if (!el) return;
    const current = selected || homeDash.timezone?.name || homeDash.server_timezone || clientTimezone() || 'UTC';
    if (el.dataset.filled !== '1') {
        el.innerHTML = homeAutoTimezoneList().map((z) =>
            `<option value="${escapeHtml(z)}">${escapeHtml(z)}</option>`
        ).join('');
        el.dataset.filled = '1';
    }
    if (![...el.options].some((o) => o.value === current)) {
        const opt = document.createElement('option');
        opt.value = current;
        opt.textContent = current;
        el.appendChild(opt);
    }
    if (document.activeElement !== el) el.value = current;
}

async function homeAutoSaveTimezone(zone) {
    const next = String(zone || '').trim();
    if (!next) return;
    try {
        const data = await homeApi({ action: 'automation_save', timezone: next });
        if (!data.ok) throw new Error(data.error || 'Could not save timezone');
        if (data.timezone) homeDash.timezone = data.timezone;
        if (data.server_timezone) homeDash.server_timezone = data.server_timezone;
        if (data.runner) homeDash.runner = data.runner;
        const name = String(homeDash.timezone?.name || next);
        homeAutoTzTried = name !== 'UTC' && name !== 'Etc/UTC';
        renderHomeAutomations();
        showToast(`Times now use ${homeDash.timezone?.name || next}`, 'success');
    } catch (err) {
        showToast(err.message || 'Could not save timezone', 'error');
    }
}

let homeAutoTzTried = false;

async function homeAutoEnsureTimezone() {
    if (homeAutoTzTried) return;
    const client = clientTimezone();
    const info = homeDash.timezone || {};
    if (!client) return;
    const zone = String(info.name || homeDash.server_timezone || '');
    const savedUtc = zone === 'UTC' || zone === 'Etc/UTC';
    if (info.saved && !savedUtc) {
        homeAutoTzTried = true;
        return;
    }
    if (zone && !savedUtc) {
        homeAutoTzTried = true;
        return;
    }
    try {
        const data = await homeApi({ action: 'automation_save', timezone: client });
        if (data.ok) {
            homeAutoTzTried = true;
            if (data.timezone) homeDash.timezone = data.timezone;
            if (data.server_timezone) homeDash.server_timezone = data.server_timezone;
            if (data.runner) homeDash.runner = data.runner;
        }
    } catch {
        // Keep the OS clock; the picker still works.
    }
}

function openHomeAutomations({ updateHash = true } = {}) {
    const page = els.homeAutomationsPage;
    if (!page) return;
    if (settingsModalOpen) closeSettingsModal();
    if (mailPageOpen) closeMailPage({ updateHash: false });
    autoPageOpen = true;
    page.classList.remove('hidden');
    document.body.classList.add('home-automations-open');
    els.homeAutomationsOpen?.setAttribute('aria-expanded', 'true');
    if (updateHash && location.hash !== '#automations') {
        history.replaceState(null, '', `${location.pathname}${location.search}#automations`);
    }
    autoDraft = null;
    autoNameLocked = false;
    loadHomeDashboard({ force: true }).then(async () => {
        await homeAutoEnsureTimezone();
        homeAutoRequestBrowserGps();
        if (autoPageOpen) renderHomeAutomations();
    }).catch(() => {});
    renderHomeAutomations();
}

function closeHomeAutomations({ updateHash = true } = {}) {
    const page = els.homeAutomationsPage;
    if (!page) return;
    page.classList.add('hidden');
    document.body.classList.remove('home-automations-open');
    autoPageOpen = false;
    autoDraft = null;
    els.homeAutomationsOpen?.setAttribute('aria-expanded', 'false');
    if (updateHash && homeAutoHashOpen()) {
        history.replaceState(null, '', `${location.pathname}${location.search}`);
    }
}

function renderHomeAutomations() {
    const tz = document.getElementById('home-automations-tz');
    const info = homeDash.timezone || {};
    const zone = info.name || homeDash.server_timezone || '';
    const clock = info.clock || '';
    if (tz) {
        const utc = zone === 'UTC' || zone === 'Etc/UTC';
        if (utc && clock) {
            tz.textContent = `Times use UTC (now ${clock}). Pick your local timezone — 21:00 means 21:00 UTC, not your wall clock.`;
        } else if (utc) {
            tz.textContent = 'Times use UTC. Pick your local timezone or 21:00 means 21:00 UTC.';
        } else if (zone && clock) tz.textContent = `Times use ${zone} (now ${clock}).`;
        else if (zone) tz.textContent = `Times use ${zone}.`;
        else tz.textContent = 'Times use this panel’s timezone.';
    }
    homeAutoFillTimezoneSelect(zone);
    const runnerEl = document.getElementById('home-automations-runner');
    const runner = homeDash.runner || {};
    const panelVer = document.documentElement.getAttribute('data-panel-version') || '';
    if (runnerEl) {
        const stale = !runner.running;
        const err = String(runner.last_error || '').trim();
        const tick = runner.clock_hm || clock || '';
        runnerEl.classList.remove('hidden');
        if (stale) {
            runnerEl.textContent = `Runner is not active${panelVer ? ` (${panelVer})` : ''}, so times cannot fire and Last ran stays empty. Opening this page starts it — wait a few seconds. If this remains, restart the panel (Settings → Panel updates).`;
        } else if (err) {
            runnerEl.textContent = `Runner is active${panelVer ? ` (${panelVer})` : ''}. Last tick ${tick || '—'}. ${err}`;
        } else {
            runnerEl.textContent = `Runner is active${panelVer ? ` (${panelVer})` : ''}. Last tick ${tick || '—'}.`;
        }
    }
    const list = document.getElementById('home-automations-list');
    const editor = document.getElementById('home-automations-editor');
    if (!list || !editor) return;
    if (autoDraft) {
        list.classList.add('hidden');
        editor.classList.remove('hidden');
        renderHomeAutoEditor();
        return;
    }
    editor.classList.add('hidden');
    list.classList.remove('hidden');
    const rules = homeDash.automations || [];
    const names = homeAutoNames();
    let html = `<div class="auto-row-actions" style="justify-content:flex-start;margin-bottom:0.35rem">
        <button type="button" class="btn" data-auto-new>New automation</button>
    </div>`;
    if (!rules.length) {
        html += `<p class="hint">Drag a When and a Then. Or start from one of these.</p>
        <div class="auto-starters">
            <button type="button" class="auto-starter" data-auto-starter="sunset"><strong>Lights at sunset</strong><span class="hint">Turn a light on when the sun goes down.</span></button>
            <button type="button" class="auto-starter" data-auto-starter="motion"><strong>Motion → scene</strong><span class="hint">A sensor runs a scene, then turns off after 5 minutes.</span></button>
            <button type="button" class="auto-starter" data-auto-starter="timeout"><strong>Off after 30 minutes</strong><span class="hint">If a light stays on, turn it off.</span></button>
            <button type="button" class="auto-starter" data-auto-starter="door"><strong>Door open 5 minutes</strong><span class="hint">If a door stays open, turn a light on.</span></button>
        </div>`;
        list.innerHTML = html;
        return;
    }
    html += rules.map((rule) => {
        const sentence = homeAutoSentence(rule, names);
        const lastHm = runner.last_fire_hm && runner.last_fire_hm[rule.id];
        const thenErr = String((runner.then_error && runner.then_error[rule.id]) || '').trim();
        let lastHint = ' Not run yet.';
        if (lastHm && thenErr) lastHint = ` Tried ${lastHm}. Then failed: ${thenErr}`;
        else if (lastHm) lastHint = ` Last ran ${lastHm}.`;
        else if (thenErr) lastHint = ` Then failed: ${thenErr}`;
        return `<article class="auto-row" data-auto-id="${escapeHtml(rule.id)}">
            <div class="auto-row-main">
                <div class="auto-row-name">${escapeHtml(rule.name || sentence)}</div>
                <p class="hint">${escapeHtml(sentence)}${escapeHtml(lastHint)}</p>
            </div>
            <div class="auto-row-actions">
                <label class="hint" style="display:flex;gap:0.35rem;align-items:center">
                    <input type="checkbox" data-auto-enable ${rule.enabled ? 'checked' : ''}> On
                </label>
                <button type="button" class="btn btn-secondary btn-compact" data-auto-edit>Edit</button>
                <button type="button" class="btn btn-secondary btn-compact" data-auto-del>Delete</button>
            </div>
        </article>`;
    }).join('');
    html += '<p class="hint">New automations run in the background. You can close this page.</p>';
    list.innerHTML = html;
}

function homeAutoDurationParts(sec) {
    const n = Number(sec) || 300;
    if (n % 3600 === 0 && n >= 3600) return { value: n / 3600, unit: 3600 };
    if (n % 60 === 0 && n >= 60) return { value: n / 60, unit: 60 };
    return { value: n, unit: 1 };
}

function homeAutoDayButtons(days) {
    const selected = Array.isArray(days) ? days.map(Number) : [];
    const all = selected.length === 0;
    const labels = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
    return `<span class="auto-days" data-auto-days>${labels.map((label, i) =>
        `<button type="button" data-day="${i}" class="${all || selected.includes(i) ? 'is-on' : ''}">${label}</button>`
    ).join('')}</span>`;
}

function renderHomeAutoEditor() {
    if (!autoDraft) return;
    homeAutoClampDraft();
    const nameEl = document.getElementById('auto-name');
    if (nameEl && document.activeElement !== nameEl) nameEl.value = autoDraft.name || '';
    const sun = homeAutoTriggers().some((trigger) => trigger?.type === 'sun');
    const needs = homeAutoSunNeedsCoords();
    const hint = document.getElementById('auto-coords-hint');
    if (hint) {
        hint.hidden = !sun;
        if (sun && !needs) {
            hint.textContent = 'Sunrise and sunset use this location. Use my location to fill it from this browser’s GPS.';
        } else if (sun) {
            hint.textContent = 'Sunrise and sunset need a location. This page can fill it from this browser’s GPS. The last Yarbo GPS is used when it exists.';
        }
    }
    document.getElementById('auto-coords')?.classList.toggle('hidden', !sun);
    const coords = homeDash.sun_coords || {};
    const lat = document.getElementById('auto-lat');
    const lon = document.getElementById('auto-lon');
    if (lat && document.activeElement !== lat) lat.value = coords.latitude ?? '';
    if (lon && document.activeElement !== lon) lon.value = coords.longitude ?? '';
    if (sun && needs) homeAutoRequestBrowserGps();
    document.getElementById('auto-when-chips').innerHTML = homeAutoWhenChipsHtml();
    document.getElementById('auto-then-chips').innerHTML = (autoDraft.actions || []).map((action, i) => homeAutoThenChipHtml(action, i)).join('')
        || '<p class="hint">Drop a light or scene here.</p>';
    document.getElementById('auto-if-chips').innerHTML = (autoDraft.conditions || []).map((cond, i) => homeAutoIfChipHtml(cond, i)).join('');
    if ((autoDraft.conditions || []).length) document.getElementById('auto-if')?.setAttribute('open', '');
    homeAutoSyncOffAfterFields();
    renderHomeAutoTray();
}

function homeAutoClampDraft() {
    if (!autoDraft) return;
    homeAutoSetTriggers(homeAutoTriggers());
    homeAutoTriggers().forEach((trigger) => {
        if (trigger?.type !== 'device') return;
        const d = homeAutoDeviceById(trigger.id);
        const events = homeAutoWhenEvents(d || { id: trigger.id, kind: 'light' });
        const allowed = events.map(([v]) => v);
        if (allowed.length && !allowed.includes(trigger.event)) {
            trigger.event = allowed[0];
            if (homeAutoDurationEvent(trigger.event)) {
                trigger.for_sec = trigger.for_sec || 300;
            } else {
                delete trigger.for_sec;
            }
        }
    });
    (autoDraft.conditions || []).forEach((cond) => {
        if (!cond || cond.type === 'time_window') return;
        const states = homeAutoIfStateOptions(homeAutoDeviceById(cond.id));
        if (states.length && !states.some(([v]) => v === cond.state)) {
            cond.state = states[0][0];
        }
    });
}

function homeAutoOffAfterParts(sec) {
    const n = Math.max(0, Number(sec) || 0);
    if (n >= 3600 && n % 3600 === 0) return { value: n / 3600, unit: 3600 };
    if (n >= 60 && n % 60 === 0) return { value: n / 60, unit: 60 };
    if (n > 0) return { value: n, unit: 1 };
    return { value: 5, unit: 60 };
}

function homeAutoReadOffAfter() {
    if (document.getElementById('auto-off-after-enabled')?.value !== '1') return 0;
    const value = Number(document.getElementById('auto-off-after-value')?.value || 5);
    const unit = Number(document.getElementById('auto-off-after-unit')?.value || 60);
    return Math.max(1, Math.round(value * unit));
}

function homeAutoSyncOffAfterFields() {
    if (!autoDraft) return;
    const enabled = document.getElementById('auto-off-after-enabled');
    const valueEl = document.getElementById('auto-off-after-value');
    const unitEl = document.getElementById('auto-off-after-unit');
    const fields = document.getElementById('auto-off-after-fields');
    const sec = Number(autoDraft.off_after_sec || 0);
    const on = sec > 0;
    if (enabled && document.activeElement !== enabled) enabled.value = on ? '1' : '0';
    fields?.classList.toggle('hidden', !on);
    if (on) {
        const parts = homeAutoOffAfterParts(sec);
        if (valueEl && document.activeElement !== valueEl) valueEl.value = String(parts.value);
        if (unitEl && document.activeElement !== unitEl) unitEl.value = String(parts.unit);
    }
}

function homeAutoWhenChipsHtml() {
    const triggers = homeAutoTriggers();
    if (!triggers.length) return '<p class="hint">Drop Time, Sunset, a door, or a sensor here. You can add more than one.</p>';
    const match = homeAutoWhenMatch();
    const join = match === 'all' ? 'and' : 'or';
    let html = '';
    if (triggers.length > 1) {
        html += `<div class="auto-when-join">
            <button type="button" data-auto-when-match="any" class="${match === 'any' ? 'is-on' : ''}">Any (or)</button>
            <button type="button" data-auto-when-match="all" class="${match === 'all' ? 'is-on' : ''}">All (and)</button>
        </div>`;
    }
    html += triggers.map((trigger, i) => {
        const chip = homeAutoWhenChipHtml(trigger, i);
        if (i === 0) return chip;
        return `<span class="auto-when-op">${join}</span>${chip}`;
    }).join('');
    return html;
}

function homeAutoWhenChipHtml(trigger, index = 0) {
    if (!trigger) return '';
    const iAttr = `data-auto-when-i="${index}"`;
    if (trigger.type === 'time') {
        return `<div class="auto-chip auto-chip--clock" data-auto-when ${iAttr}>
            At <input type="time" data-auto-at value="${escapeHtml(trigger.at || '21:00')}" step="60">
            ${homeAutoDayButtons(trigger.days)}
            <button type="button" class="auto-chip-x" data-auto-clear-when="${index}" aria-label="Remove">✕</button>
        </div>`;
    }
    if (trigger.type === 'sun') {
        return `<div class="auto-chip auto-chip--clock" data-auto-when ${iAttr}>
            <select data-auto-sun>
                <option value="sunset" ${trigger.event === 'sunset' ? 'selected' : ''}>Sunset</option>
                <option value="sunrise" ${trigger.event === 'sunrise' ? 'selected' : ''}>Sunrise</option>
            </select>
            <input type="number" data-auto-offset value="${Number(trigger.offset_min || 0)}" step="5"> min
            <button type="button" class="auto-chip-x" data-auto-clear-when="${index}" aria-label="Remove">✕</button>
        </div>`;
    }
    if (trigger.type === 'threshold') {
        const d = homeAutoDeviceById(trigger.id);
        const name = d?.name || trigger.id;
        return `<div class="auto-chip" data-auto-when ${iAttr}>
            ${escapeHtml(name)}
            <select data-auto-event>
                <option value="temp_above" ${trigger.metric === 'temperature' && trigger.op === 'above' ? 'selected' : ''}>temperature above</option>
                <option value="temp_below" ${trigger.metric === 'temperature' && trigger.op === 'below' ? 'selected' : ''}>temperature below</option>
                <option value="hum_above" ${trigger.metric === 'humidity' && trigger.op === 'above' ? 'selected' : ''}>humidity above</option>
                <option value="hum_below" ${trigger.metric === 'humidity' && trigger.op === 'below' ? 'selected' : ''}>humidity below</option>
            </select>
            <input type="number" data-auto-thresh value="${escapeHtml(String(trigger.value ?? 22))}" step="0.5">
            <button type="button" class="auto-chip-x" data-auto-clear-when="${index}" aria-label="Remove">✕</button>
        </div>`;
    }
    const d = homeAutoDeviceById(trigger.id);
    const name = d?.name || trigger.id;
    const events = homeAutoWhenEvents(d || { id: trigger.id, kind: 'light' });
    const eventName = trigger.event || 'turns_on';
    const dur = homeAutoDurationEvent(eventName) ? homeAutoDurationParts(trigger.for_sec || 300) : null;
    return `<div class="auto-chip" data-auto-when ${iAttr}>
        ${escapeHtml(name)}
        <select data-auto-event>${events.map(([v, label]) =>
            `<option value="${v}" ${v === eventName ? 'selected' : ''}>${label}</option>`
        ).join('')}</select>
        ${dur ? `<input type="number" min="1" data-auto-for value="${dur.value}">
            <select data-auto-for-unit>
                <option value="1" ${dur.unit === 1 ? 'selected' : ''}>sec</option>
                <option value="60" ${dur.unit === 60 ? 'selected' : ''}>min</option>
                <option value="3600" ${dur.unit === 3600 ? 'selected' : ''}>hr</option>
            </select>` : ''}
        <button type="button" class="auto-chip-x" data-auto-clear-when="${index}" aria-label="Remove">✕</button>
    </div>`;
}

function homeAutoThenChipHtml(action, index) {
    const isScene = action.kind === 'scene';
    const d = isScene
        ? { id: `scene:${action.id}`, kind: 'scene', name: homeAutoSceneById(action.id)?.name || 'Scene' }
        : homeAutoDeviceById(action.id) || { id: action.id, kind: 'light', name: action.id };
    const cmds = homeAutoThenCommands(d);
    const cmd = action.command || (isScene ? 'run' : 'on');
    if (cmd === 'brightness' && !cmds.some(([v]) => v === 'brightness')) cmds.push(['brightness', 'Brightness']);
    const canOff = homeAutoThenCanOffAfter({ ...action, command: cmd });
    const offSec = canOff ? Number(action.off_after_sec || 0) : 0;
    const offHtml = canOff
        ? `<select data-auto-then-off aria-label="Turn off after">${homeAutoOffAfterChoices(offSec).map(([v, label]) =>
            `<option value="${v}" ${Number(v) === offSec ? 'selected' : ''}>${escapeHtml(label)}</option>`
        ).join('')}</select>`
        : '';
    return `<div class="auto-chip${isScene ? ' auto-chip--scene' : ''}" draggable="true" data-auto-then="${index}">
        ${escapeHtml(d.name || action.id)}
        <select data-auto-cmd>${cmds.map(([v, label]) =>
            `<option value="${v}" ${v === cmd ? 'selected' : ''}>${label}</option>`
        ).join('')}</select>
        ${homeAutoThenParamsHtml(d, action, cmd)}
        ${offHtml}
        <button type="button" class="auto-chip-x" data-auto-then-x="${index}" aria-label="Remove">✕</button>
    </div>`;
}

function homeAutoIfChipHtml(cond, index) {
    if (cond.type === 'time_window') {
        return `<div class="auto-chip auto-chip--clock" data-auto-if-i="${index}">
            Between <input type="time" data-auto-if-start value="${escapeHtml(cond.start || '08:00')}">
            and <input type="time" data-auto-if-end value="${escapeHtml(cond.end || '22:00')}">
            <button type="button" class="auto-chip-x" data-auto-if-x="${index}" aria-label="Remove">✕</button>
        </div>`;
    }
    const devices = homeAutoAllDevices().filter((d) => d.id !== autoDraft?.trigger?.id);
    const groups = homeAutoDeviceSelectGroups(devices);
    const opts = groups.map((group) =>
        `<optgroup label="${escapeHtml(group.label)}">${group.items.map((d) =>
            `<option value="${escapeHtml(d.id)}" ${d.id === cond.id ? 'selected' : ''}>${escapeHtml(d.name || d.id)}</option>`
        ).join('')}</optgroup>`
    ).join('');
    const selected = homeAutoDeviceById(cond.id) || devices[0];
    const states = homeAutoIfStateOptions(selected);
    const state = states.some(([v]) => v === cond.state) ? cond.state : (states[0] ? states[0][0] : 'on');
    return `<div class="auto-chip" data-auto-if-i="${index}">
        <select data-auto-if-id>${opts}</select>
        is
        <select data-auto-if-state>
            ${states.map(([v, label]) =>
                `<option value="${v}" ${v === state ? 'selected' : ''}>${escapeHtml(label)}</option>`
            ).join('')}
        </select>
        <button type="button" class="auto-chip-x" data-auto-if-x="${index}" aria-label="Remove">✕</button>
    </div>`;
}

function homeAutoIfStateOptions(d) {
    if (!d) return [['on', 'on'], ['off', 'off']];
    const states = [];
    if (homeAutoCanOnOff(d)) {
        states.push(['on', 'on'], ['off', 'off']);
    }
    if (homeAutoCanOpen(d)) {
        states.push(['open', 'open'], ['closed', 'closed']);
    }
    if (homeAutoCanMotion(d)) {
        states.push(['motion', 'motion'], ['no_motion', 'no motion']);
    }
    return states.length ? states : [['on', 'on'], ['off', 'off']];
}

function homeAutoDeviceSelectGroups(devices) {
    const order = [
        ['lights', 'Lights'],
        ['heaters', 'Heaters'],
        ['plugs', 'Plugs'],
        ['switches', 'Switches'],
        ['relays', 'Relays'],
        ['sensors', 'Sensors'],
        ['cameras', 'Cameras'],
        ['doors', 'Doors'],
        ['controllers', 'Controllers'],
        ['vacuums', 'Vacuums'],
        ['other', 'Other'],
    ];
    const buckets = {};
    order.forEach(([id]) => {
        buckets[id] = [];
    });
    const byName = (a, b) => String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' });
    [...devices].sort(byName).forEach((d) => {
        const gid = homeAutoDeviceGroupId(d);
        (buckets[gid] || buckets.other).push(d);
    });
    return order
        .filter(([id]) => (buckets[id] || []).length)
        .map(([id, label]) => ({ id, label, items: buckets[id] }));
}

function renderHomeAutoTray() {
    const tray = document.getElementById('auto-tray');
    if (!tray) return;
    const groups = homeAutoTrayGroups(homeAutoAllDevices(), homeDash.scenes || []);
    tray.innerHTML = groups.map((group) => (
        `<div class="auto-tray-group" data-auto-group="${escapeHtml(group.id)}">
            <h4 class="auto-tray-label">${escapeHtml(group.label)}</h4>
            <div class="auto-tray-chips">${group.chips.join('')}</div>
        </div>`
    )).join('');
}

function homeAutoDeviceGroupId(d) {
    if (homeIsUnifi(d)) {
        switch (String(d.kind || '')) {
            case 'light':
                return 'lights';
            case 'relay':
                return 'relays';
            case 'sensor':
                return 'sensors';
            case 'camera':
                return 'cameras';
            case 'door':
                return 'doors';
            case 'hub':
                return 'controllers';
            default:
                return 'other';
        }
    }
    switch (String(d.kind || 'light')) {
        case 'heater':
            return 'heaters';
        case 'vacuum':
            return 'vacuums';
        case 'plug':
            return 'plugs';
        case 'switch':
            return 'switches';
        case 'sensor':
            return 'sensors';
        case 'camera':
            return 'cameras';
        default:
            return 'lights';
    }
}

function homeAutoTrayChip(kind, id, name, extraClass) {
    const cls = extraClass ? `auto-chip ${extraClass}` : 'auto-chip';
    const idAttr = id ? ` data-id="${escapeHtml(id)}"` : '';
    return `<button type="button" class="${cls}" draggable="true" data-auto-tray="${escapeHtml(kind)}"${idAttr}>${escapeHtml(name)}</button>`;
}

function homeAutoTrayGroups(devices, scenes) {
    const order = [
        ['time', 'Time'],
        ['lights', 'Lights'],
        ['heaters', 'Heaters'],
        ['plugs', 'Plugs'],
        ['switches', 'Switches'],
        ['relays', 'Relays'],
        ['sensors', 'Sensors'],
        ['cameras', 'Cameras'],
        ['doors', 'Doors'],
        ['controllers', 'Controllers'],
        ['vacuums', 'Vacuums'],
        ['scenes', 'Scenes'],
        ['other', 'Other'],
    ];
    const buckets = {};
    order.forEach(([id]) => {
        buckets[id] = [];
    });
    buckets.time.push(
        homeAutoTrayChip('time', '', 'Time', 'auto-chip--clock'),
        homeAutoTrayChip('sunset', '', 'Sunset', 'auto-chip--clock'),
        homeAutoTrayChip('sunrise', '', 'Sunrise', 'auto-chip--clock')
    );
    const byName = (a, b) => String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' });
    [...devices].sort(byName).forEach((d) => {
        const gid = homeAutoDeviceGroupId(d);
        (buckets[gid] || buckets.other).push(homeAutoTrayChip('device', d.id, d.name || d.id));
    });
    [...scenes].sort(byName).forEach((s) => {
        buckets.scenes.push(homeAutoTrayChip('scene', s.id, s.name || 'Scene'));
    });
    return order
        .filter(([id]) => (buckets[id] || []).length)
        .map(([id, label]) => ({ id, label, chips: buckets[id] }));
}

function homeAutoApplyWhen(trigger) {
    if (!autoDraft || !trigger) return;
    const list = homeAutoTriggers();
    list.push(trigger);
    homeAutoSetTriggers(list);
}

function homeAutoApplyTray(kind, id, zone) {
    if (!autoDraft) return;
    if (kind === 'time' || kind === 'sunset' || kind === 'sunrise') {
        if (zone === 'then') return;
        homeAutoApplyWhen(kind === 'time'
            ? { type: 'time', at: '21:00' }
            : { type: 'sun', event: kind, offset_min: 0 });
        homeAutoSyncName();
        renderHomeAutoEditor();
        return;
    }
    if (kind === 'scene') {
        if (zone === 'when') {
            showToast('Scenes go in Then', 'error');
            return;
        }
        const action = homeAutoDefaultThen({ id: `scene:${id}`, kind: 'scene', name: homeAutoSceneById(id)?.name });
        if (action) {
            if (homeAutoThenCanOffAfter(action) && Number(autoDraft.off_after_sec || 0) > 0) {
                action.off_after_sec = Number(autoDraft.off_after_sec);
            }
            autoDraft.actions.push(action);
        }
        homeAutoSyncName();
        renderHomeAutoEditor();
        return;
    }
    const d = homeAutoDeviceById(id);
    if (!d) return;
    if (zone === 'when' || (zone !== 'then' && !homeAutoTriggers().length && (d.kind === 'sensor' || d.kind === 'door' || d.kind === 'hub'))) {
        homeAutoApplyWhen(homeAutoDefaultWhen(d));
        homeAutoSyncName();
        renderHomeAutoEditor();
        return;
    }
    const action = homeAutoDefaultThen(d);
    if (!action) {
        if (zone === 'then') showToast('Sensors stay in When', 'error');
        else {
            homeAutoApplyWhen(homeAutoDefaultWhen(d));
            homeAutoSyncName();
            renderHomeAutoEditor();
        }
        return;
    }
    if (homeAutoThenCanOffAfter(action) && Number(autoDraft.off_after_sec || 0) > 0) {
        action.off_after_sec = Number(autoDraft.off_after_sec);
    }
    autoDraft.actions.push(action);
    homeAutoSyncName();
    renderHomeAutoEditor();
}

function homeAutoClickTray(kind, id) {
    if (!autoDraft) return;
    const d = kind === 'device' ? homeAutoDeviceById(id) : null;
    if (kind === 'time' || kind === 'sunset' || kind === 'sunrise' || (d && !homeAutoThenOk(d))) {
        homeAutoApplyTray(kind, id, 'when');
        return;
    }
    if (kind === 'scene') {
        homeAutoApplyTray(kind, id, 'then');
        return;
    }
    if (!homeAutoTriggers().length) homeAutoApplyTray(kind, id, 'when');
    else homeAutoApplyTray(kind, id, 'then');
}

function homeAutoStartEditor(partial) {
    autoDraft = { ...homeAutoBlankDraft(), ...partial };
    if (!Array.isArray(autoDraft.actions)) autoDraft.actions = [];
    if (!Array.isArray(autoDraft.conditions)) autoDraft.conditions = [];
    homeAutoSetTriggers(homeAutoTriggers(autoDraft));
    autoDraft.when_match = homeAutoWhenMatch(autoDraft);
    const ruleOff = Number(autoDraft.off_after_sec || 0);
    autoDraft.actions = autoDraft.actions.map((action) => {
        const next = { ...action };
        if (ruleOff > 0 && !(Number(next.off_after_sec || 0) > 0) && homeAutoThenCanOffAfter(next)) {
            next.off_after_sec = ruleOff;
        }
        return next;
    });
    autoNameLocked = Boolean(partial?.name) && partial.name !== homeAutoSentence(partial, homeAutoNames());
    homeAutoSyncName();
    renderHomeAutomations();
    document.getElementById('auto-name')?.focus();
}

function homeAutoStarter(kind) {
    const lights = (homeDash.devices || []).filter((d) => homeAutoThenOk(d) && d.kind !== 'door' && d.kind !== 'hub');
    const light = lights[0];
    const door = (homeDash.devices || []).find((d) => d.kind === 'door' || d.kind === 'hub');
    const sensor = (homeDash.devices || []).find((d) => d.kind === 'sensor')
        || (homeDash.devices || []).find((d) => homeAutoLooksLikeMotion(d));
    const scene = (homeDash.scenes || [])[0];
    if (kind === 'sunset') {
        homeAutoStartEditor({
            trigger: { type: 'sun', event: 'sunset', offset_min: 0 },
            actions: light ? [homeAutoDefaultThen(light)] : [],
        });
        return;
    }
    if (kind === 'timeout') {
        homeAutoStartEditor({
            trigger: light ? { type: 'device', id: light.id, event: 'stays_on', for_sec: 1800 } : null,
            actions: light ? [{ kind: 'device', id: light.id, command: 'off' }] : [],
        });
        return;
    }
    if (kind === 'motion') {
        const then = scene
            ? { kind: 'scene', id: scene.id, command: 'run', off_after_sec: 300 }
            : (light ? { ...homeAutoDefaultThen(light), off_after_sec: 300 } : null);
        homeAutoStartEditor({
            trigger: sensor ? { type: 'device', id: sensor.id, event: 'motion' } : null,
            actions: then ? [then] : [],
            off_after_sec: 300,
        });
        return;
    }
    homeAutoStartEditor({
        trigger: door ? { type: 'device', id: door.id, event: 'stays_open', for_sec: 300 } : null,
        actions: light ? [homeAutoDefaultThen(light)] : [],
    });
}

function homeAutoReadDuration(root = document) {
    const value = Number(root.querySelector('[data-auto-for]')?.value || 5);
    const unit = Number(root.querySelector('[data-auto-for-unit]')?.value || 60);
    return Math.max(1, Math.round(value * unit));
}

function homeAutoTriggerIndex(el) {
    const chip = el?.closest?.('[data-auto-when-i]');
    const i = Number(chip?.getAttribute('data-auto-when-i') ?? 0);
    return Number.isFinite(i) ? i : 0;
}

function homeAutoApplyEvent(raw, index = 0) {
    const list = homeAutoTriggers();
    const trigger = list[index];
    if (!trigger) return;
    if (raw === 'temp_above' || raw === 'temp_below' || raw === 'hum_above' || raw === 'hum_below') {
        list[index] = {
            type: 'threshold',
            id: trigger.id,
            metric: raw.startsWith('hum') ? 'humidity' : 'temperature',
            op: raw.endsWith('below') ? 'below' : 'above',
            value: Number(document.querySelector(`[data-auto-when-i="${index}"] [data-auto-thresh]`)?.value || 22),
        };
        homeAutoSetTriggers(list);
        return;
    }
    const id = trigger.id;
    list[index] = { type: 'device', id, event: raw };
    if (homeAutoDurationEvent(raw)) {
        const chip = document.querySelector(`[data-auto-when-i="${index}"]`);
        list[index].for_sec = homeAutoReadDuration(chip || document);
    }
    homeAutoSetTriggers(list);
}

async function homeAutoSave() {
    if (!autoDraft) return;
    const nameEl = document.getElementById('auto-name');
    if (nameEl) autoDraft.name = nameEl.value.trim();
    if (!autoNameLocked) homeAutoSyncName();
    homeAutoTriggers().forEach((trigger, i) => {
        const chip = document.querySelector(`#home-automations-editor [data-auto-when-i="${i}"]`);
        if (trigger.type === 'device' && homeAutoDurationEvent(trigger.event)) {
            trigger.for_sec = homeAutoReadDuration(chip || document);
        }
        if (trigger.type === 'time') {
            const atEl = chip?.querySelector('[data-auto-at]');
            if (atEl && atEl.value) trigger.at = atEl.value;
        }
    });
    homeAutoSetTriggers(homeAutoTriggers());
    homeAutoReadThenLooks();
    autoDraft.off_after_sec = homeAutoReadOffAfter();
    if (homeAutoTriggers().some((trigger) => trigger.type === 'sun') && homeAutoSunNeedsCoords()) {
        const lat = Number(document.getElementById('auto-lat')?.value);
        const lon = Number(document.getElementById('auto-lon')?.value);
        if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
            throw new Error('Add latitude and longitude for sunrise and sunset');
        }
        await homeApi({ action: 'automation_save', latitude: lat, longitude: lon });
    }
    const data = await homeApi({
        action: 'automation_save',
        id: autoDraft.id,
        name: autoDraft.name,
        enabled: autoDraft.enabled !== false,
        trigger: autoDraft.trigger,
        triggers: autoDraft.triggers,
        when_match: autoDraft.when_match,
        conditions: autoDraft.conditions,
        actions: autoDraft.actions,
        off_after_sec: autoDraft.off_after_sec || 0,
        cooldown_sec: autoDraft.cooldown_sec || 30,
        names: homeAutoNames(),
    });
    if (!data.ok) throw new Error(data.error || 'Could not save');
    homeDash.automations = data.automations || homeDash.automations;
    if (data.sun_coords) homeDash.sun_coords = data.sun_coords;
    autoDraft = null;
    showToast('Automation saved', 'success');
    renderHomeAutomations();
}

function bindHomeAutomations() {
    document.getElementById('home-automations-open')?.addEventListener('click', () => {
        if (autoPageOpen) closeHomeAutomations();
        else openHomeAutomations();
    });
    document.getElementById('home-automations-close')?.addEventListener('click', () => closeHomeAutomations());
    document.getElementById('auto-cancel')?.addEventListener('click', () => {
        autoDraft = null;
        renderHomeAutomations();
    });
    document.getElementById('auto-save')?.addEventListener('click', async () => {
        const btn = document.getElementById('auto-save');
        if (btn) btn.disabled = true;
        try {
            await homeAutoSave();
        } catch (err) {
            showToast(err.message || 'Could not save', 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    });
    document.getElementById('auto-geo')?.addEventListener('click', () => {
        homeAutoRequestBrowserGps({ force: true });
    });
    document.getElementById('auto-name')?.addEventListener('input', () => {
        autoNameLocked = true;
        if (autoDraft) autoDraft.name = document.getElementById('auto-name').value;
    });
    const page = els.homeAutomationsPage;
    if (!page || page.dataset.autoBound) return;
    page.dataset.autoBound = '1';
    page.addEventListener('click', async (event) => {
        const starter = event.target.closest('[data-auto-starter]');
        if (starter) {
            homeAutoStarter(starter.getAttribute('data-auto-starter'));
            return;
        }
        if (event.target.closest('[data-auto-new]')) {
            homeAutoStartEditor({});
            return;
        }
        const enable = event.target.closest('[data-auto-enable]');
        if (enable) {
            const id = enable.closest('[data-auto-id]')?.getAttribute('data-auto-id');
            try {
                const data = await homeApi({ action: 'automation_enable', id, enabled: enable.checked });
                if (!data.ok) throw new Error(data.error || 'Could not update');
                homeDash.automations = data.automations || homeDash.automations;
            } catch (err) {
                enable.checked = !enable.checked;
                showToast(err.message || 'Could not update', 'error');
            }
            return;
        }
        const edit = event.target.closest('[data-auto-edit]');
        if (edit) {
            const id = edit.closest('[data-auto-id]')?.getAttribute('data-auto-id');
            const rule = (homeDash.automations || []).find((row) => row.id === id);
            if (rule) {
                homeAutoStartEditor({
                    ...rule,
                    actions: [...(rule.actions || [])],
                    conditions: [...(rule.conditions || [])],
                    triggers: homeAutoTriggers(rule).map((t) => ({ ...t })),
                    when_match: homeAutoWhenMatch(rule),
                });
            }
            return;
        }
        const del = event.target.closest('[data-auto-del]');
        if (del) {
            const id = del.closest('[data-auto-id]')?.getAttribute('data-auto-id');
            try {
                const data = await homeApi({ action: 'automation_delete', id });
                if (!data.ok) throw new Error(data.error || 'Could not delete');
                homeDash.automations = data.automations || [];
                renderHomeAutomations();
            } catch (err) {
                showToast(err.message || 'Could not delete', 'error');
            }
            return;
        }
        if (event.target.closest('[data-auto-clear-when]') || event.target.hasAttribute?.('data-auto-clear-when')) {
            const x = event.target.closest('[data-auto-clear-when]');
            if (autoDraft && x) {
                const list = homeAutoTriggers();
                const i = Number(x.getAttribute('data-auto-clear-when'));
                if (Number.isFinite(i)) list.splice(i, 1);
                else list.splice(0, 1);
                homeAutoSetTriggers(list);
            }
            homeAutoSyncName();
            renderHomeAutoEditor();
            return;
        }
        const whenMatch = event.target.closest('[data-auto-when-match]');
        if (whenMatch && autoDraft) {
            autoDraft.when_match = whenMatch.getAttribute('data-auto-when-match') === 'all' ? 'all' : 'any';
            homeAutoSyncName();
            renderHomeAutoEditor();
            return;
        }
        const thenX = event.target.closest('[data-auto-then-x]');
        if (thenX && autoDraft) {
            autoDraft.actions.splice(Number(thenX.getAttribute('data-auto-then-x')), 1);
            homeAutoSyncName();
            renderHomeAutoEditor();
            return;
        }
        const ifX = event.target.closest('[data-auto-if-x]');
        if (ifX && autoDraft) {
            autoDraft.conditions.splice(Number(ifX.getAttribute('data-auto-if-x')), 1);
            renderHomeAutoEditor();
            return;
        }
        const ifAdd = event.target.closest('[data-auto-if]');
        if (ifAdd && autoDraft) {
            const which = ifAdd.getAttribute('data-auto-if');
            if (which === 'window') autoDraft.conditions.push({ type: 'time_window', start: '08:00', end: '22:00' });
            else {
                const used = new Set(homeAutoTriggers().map((t) => t.id).filter(Boolean));
                const other = homeAutoAllDevices().find((d) => !used.has(d.id));
                if (!other) {
                    showToast('Add another device first', 'error');
                    return;
                }
                const states = homeAutoIfStateOptions(other);
                autoDraft.conditions.push({ type: 'device', id: other.id, state: states[0] ? states[0][0] : 'on' });
            }
            renderHomeAutoEditor();
            return;
        }
        const dayBtn = event.target.closest('[data-day]');
        if (dayBtn && autoDraft) {
            const i = homeAutoTriggerIndex(dayBtn);
            const list = homeAutoTriggers();
            if (list[i]?.type === 'time') {
                const day = Number(dayBtn.getAttribute('data-day'));
                let current = Array.isArray(list[i].days) ? [...list[i].days] : [0, 1, 2, 3, 4, 5, 6];
                const idx = current.indexOf(day);
                if (idx >= 0) current.splice(idx, 1);
                else current.push(day);
                list[i].days = current.length === 7 ? undefined : current.sort((a, b) => a - b);
                homeAutoSetTriggers(list);
                renderHomeAutoEditor();
            }
            return;
        }
        const tray = event.target.closest('[data-auto-tray]');
        if (tray && autoDraft) {
            homeAutoClickTray(tray.getAttribute('data-auto-tray'), tray.getAttribute('data-id') || '');
        }
    });
    page.addEventListener('input', (event) => {
        if (!autoDraft) return;
        if (event.target.matches('[data-auto-bright], [data-auto-hex], [data-auto-kelvin], [data-auto-celsius]')) {
            homeAutoApplyThenLook(event.target);
        }
    });
    page.addEventListener('change', (event) => {
        if (event.target.matches('#auto-timezone')) {
            homeAutoSaveTimezone(event.target.value);
            return;
        }
        if (!autoDraft) return;
        if (event.target.matches('[data-auto-at]')) {
            const i = homeAutoTriggerIndex(event.target);
            const list = homeAutoTriggers();
            if (list[i]) list[i].at = event.target.value;
            homeAutoSetTriggers(list);
        }
        if (event.target.matches('[data-auto-sun]')) {
            const i = homeAutoTriggerIndex(event.target);
            const list = homeAutoTriggers();
            if (list[i]) list[i].event = event.target.value;
            homeAutoSetTriggers(list);
        }
        if (event.target.matches('[data-auto-offset]')) {
            const i = homeAutoTriggerIndex(event.target);
            const list = homeAutoTriggers();
            if (list[i]) list[i].offset_min = Number(event.target.value || 0);
            homeAutoSetTriggers(list);
        }
        if (event.target.matches('[data-auto-event]')) homeAutoApplyEvent(event.target.value, homeAutoTriggerIndex(event.target));
        if (event.target.matches('[data-auto-for], [data-auto-for-unit]')) {
            const i = homeAutoTriggerIndex(event.target);
            const list = homeAutoTriggers();
            if (list[i]) list[i].for_sec = homeAutoReadDuration(event.target.closest('[data-auto-when]') || document);
            homeAutoSetTriggers(list);
        }
        if (event.target.matches('#auto-off-after-enabled, #auto-off-after-value, #auto-off-after-unit')) {
            autoDraft.off_after_sec = homeAutoReadOffAfter();
            homeAutoApplyOffAfterToActions(autoDraft.off_after_sec);
            homeAutoSyncOffAfterFields();
        }
        if (event.target.matches('[data-auto-thresh]')) {
            const i = homeAutoTriggerIndex(event.target);
            const list = homeAutoTriggers();
            if (list[i]?.type === 'threshold') list[i].value = Number(event.target.value);
            homeAutoSetTriggers(list);
        }
        if (event.target.matches('[data-auto-cmd]')) {
            const i = Number(event.target.closest('[data-auto-then]')?.getAttribute('data-auto-then') || 0);
            if (autoDraft.actions[i]) {
                autoDraft.actions[i].command = event.target.value;
                if (event.target.value === 'brightness' && autoDraft.actions[i].brightness == null) {
                    autoDraft.actions[i].brightness = 100;
                }
                if (!homeAutoThenCanOffAfter(autoDraft.actions[i])) {
                    delete autoDraft.actions[i].off_after_sec;
                }
            }
        }
        if (event.target.matches('[data-auto-then-off]')) {
            const i = Number(event.target.closest('[data-auto-then]')?.getAttribute('data-auto-then') || 0);
            if (autoDraft.actions[i]) {
                const sec = Math.max(0, Number(event.target.value || 0));
                if (sec > 0) autoDraft.actions[i].off_after_sec = sec;
                else delete autoDraft.actions[i].off_after_sec;
                const secs = autoDraft.actions.filter(homeAutoThenCanOffAfter).map((a) => Number(a.off_after_sec || 0));
                if (secs.length && secs.every((s) => s === secs[0])) autoDraft.off_after_sec = secs[0];
            }
        }
        if (event.target.matches('[data-auto-bright], [data-auto-hex], [data-auto-kelvin], [data-auto-celsius]')) {
            homeAutoApplyThenLook(event.target);
        }
        if (event.target.matches('[data-auto-if-start]')) {
            const i = Number(event.target.closest('[data-auto-if-i]')?.getAttribute('data-auto-if-i') || 0);
            if (autoDraft.conditions[i]) autoDraft.conditions[i].start = event.target.value;
        }
        if (event.target.matches('[data-auto-if-end]')) {
            const i = Number(event.target.closest('[data-auto-if-i]')?.getAttribute('data-auto-if-i') || 0);
            if (autoDraft.conditions[i]) autoDraft.conditions[i].end = event.target.value;
        }
        if (event.target.matches('[data-auto-if-id]')) {
            const i = Number(event.target.closest('[data-auto-if-i]')?.getAttribute('data-auto-if-i') || 0);
            if (autoDraft.conditions[i]) {
                autoDraft.conditions[i].id = event.target.value;
                const states = homeAutoIfStateOptions(homeAutoDeviceById(event.target.value));
                if (!states.some(([v]) => v === autoDraft.conditions[i].state)) {
                    autoDraft.conditions[i].state = states[0] ? states[0][0] : 'on';
                }
            }
        }
        if (event.target.matches('[data-auto-if-state]')) {
            const i = Number(event.target.closest('[data-auto-if-i]')?.getAttribute('data-auto-if-i') || 0);
            if (autoDraft.conditions[i]) autoDraft.conditions[i].state = event.target.value;
        }
        homeAutoSyncName();
        if (event.target.matches('[data-auto-event], [data-auto-cmd], [data-auto-then-off], [data-auto-sun], #auto-off-after-enabled, [data-auto-if-id]')) renderHomeAutoEditor();
    });
    page.addEventListener('dragstart', (event) => {
        const tray = event.target.closest('[data-auto-tray]');
        const thenChip = event.target.closest('[data-auto-then]');
        if (tray) {
            autoDrag = { kind: tray.getAttribute('data-auto-tray'), id: tray.getAttribute('data-id') || '' };
            event.dataTransfer?.setData('text/plain', 'auto');
            return;
        }
        if (thenChip) {
            if (event.target.closest('input, select, button, label')) {
                event.preventDefault();
                return;
            }
            autoDrag = { kind: 'reorder', index: Number(thenChip.getAttribute('data-auto-then')) };
            event.dataTransfer?.setData('text/plain', 'auto');
        }
    });
    page.addEventListener('dragend', () => {
        autoDrag = null;
        page.querySelectorAll('.auto-drop.is-over').forEach((el) => el.classList.remove('is-over'));
    });
    page.addEventListener('dragover', (event) => {
        const zone = event.target.closest('[data-auto-zone]');
        if (!zone) return;
        event.preventDefault();
        zone.classList.add('is-over');
    });
    page.addEventListener('dragleave', (event) => {
        const zone = event.target.closest('[data-auto-zone]');
        if (zone && !zone.contains(event.relatedTarget)) zone.classList.remove('is-over');
    });
    page.addEventListener('drop', (event) => {
        const zone = event.target.closest('[data-auto-zone]');
        if (!zone || !autoDrag) return;
        event.preventDefault();
        zone.classList.remove('is-over');
        const which = zone.getAttribute('data-auto-zone');
        if (autoDrag.kind === 'reorder' && which === 'then' && autoDraft) {
            const from = autoDrag.index;
            const target = event.target.closest('[data-auto-then]');
            const to = target ? Number(target.getAttribute('data-auto-then')) : autoDraft.actions.length - 1;
            if (from !== to && autoDraft.actions[from]) {
                const [item] = autoDraft.actions.splice(from, 1);
                autoDraft.actions.splice(Math.max(0, to), 0, item);
                renderHomeAutoEditor();
            }
            autoDrag = null;
            return;
        }
        homeAutoApplyTray(autoDrag.kind, autoDrag.id, which);
        autoDrag = null;
    });
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
    const homeOn = Boolean(els.settingsModuleHome?.checked);
    const unifiOn = Boolean(els.settingsModuleUnifi?.checked);
    document.getElementById('settings-yarbo-section')?.classList.toggle('hidden', !yarboOn);
    document.querySelector('[data-settings-nav="yarbo"]')?.classList.toggle('hidden', !yarboOn);
    // Never set native required on these: they live on the Yarbo pane, which is
    // display:none while Settings → Modules is open. HTML5 then blocks Save with
    // no visible bubble, so ticks look saved until refresh. JS/PHP still check.
    document.getElementById('settings-powerwall-section')?.classList.toggle(
        'hidden',
        !powerwallOn,
    );
    document.getElementById('settings-lymow-section')?.classList.toggle(
        'hidden',
        !lymowOn,
    );
    document.getElementById('settings-home-section')?.classList.toggle(
        'hidden',
        !homeOn,
    );
    document.getElementById('settings-unifi-section')?.classList.toggle(
        'hidden',
        !unifiOn,
    );
    document.querySelector('[data-settings-nav="powerwall"]')?.classList.toggle('hidden', !powerwallOn);
    document.querySelector('[data-settings-nav="lymow"]')?.classList.toggle('hidden', !lymowOn);
    document.querySelector('[data-settings-nav="home"]')?.classList.toggle('hidden', !homeOn);
    document.querySelector('[data-settings-nav="unifi"]')?.classList.toggle('hidden', !unifiOn);
    document.getElementById('papermono-alert-yarbo')?.closest('label')?.classList.toggle('hidden', !yarboOn);
    document.getElementById('papermono-alert-powerwall')?.closest('label')?.classList.toggle('hidden', !powerwallOn);
    document.getElementById('papermono-alert-lymow')?.closest('label')?.classList.toggle('hidden', !lymowOn);
    document.querySelectorAll('#paper-menu-list [data-menu-id]').forEach((row) => {
        const id = row.getAttribute('data-menu-id') || '';
        let show = true;
        if (id === 'yarbo') show = yarboOn;
        else if (id === 'powerwall') show = powerwallOn;
        else if (id === 'lymow') show = lymowOn;
        else if (id === 'house') show = homeOn;
        row.classList.toggle('hidden', !show);
    });
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
    const homeOn = Boolean(els.settingsModuleHome?.checked);
    const unifiOn = Boolean(els.settingsModuleUnifi?.checked);
    if (!yarboOn && !powerwallOn && !lymowOn && !homeOn && !unifiOn) {
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
    const e = extras && typeof extras === 'object' ? extras : vestaboardExtraModules(lastHub);
    const count = (e.yarbo !== false ? 1 : 0) + (e.powerwall ? 1 : 0) + (e.lymow ? 1 : 0);
    document.querySelectorAll('[data-rotate-choice]').forEach((label) => {
        const id = label.getAttribute('data-rotate-choice') || '';
        let show = true;
        if (id === 'yarbo') show = e.yarbo !== false;
        else if (id === 'powerwall') show = Boolean(e.powerwall);
        else if (id === 'lymow') show = Boolean(e.lymow);
        else if (id === 'batteries') show = count >= 2;
        label.classList.toggle('hidden', !show);
    });
}

function fillVestaboardRotateForm() {
    applyRotateViewChoices(vestaboardExtraModules(lastHub));
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
    const h = (hub && hub.modules) ? hub : lastHub;
    return {
        yarbo: h?.modules?.yarbo !== false,
        powerwall: Boolean(h?.modules?.powerwall),
        lymow: Boolean(h?.modules?.lymow),
    };
}

function applyVestaboardLiveChoices(extras, live) {
    const e = extras && typeof extras === 'object' ? extras : vestaboardExtraModules(lastHub);
    const enabled = {
        yarbo: e.yarbo !== false,
        powerwall: Boolean(e.powerwall),
        lymow: Boolean(e.lymow),
    };
    const allowAll = (enabled.yarbo ? 1 : 0) + (enabled.powerwall ? 1 : 0) + (enabled.lymow ? 1 : 0) >= 2;
    const visibleIds = [];
    els.vestaboardLiveSwitch?.querySelectorAll('[data-vestaboard-live]').forEach((btn) => {
        const id = btn.getAttribute('data-vestaboard-live') || '';
        let show = true;
        if (id === 'yarbo') show = enabled.yarbo;
        else if (id === 'powerwall') show = enabled.powerwall;
        else if (id === 'lymow') show = enabled.lymow;
        else if (id === 'batteries') show = allowAll;
        btn.classList.toggle('hidden', !show);
        if (show) visibleIds.push(id);
    });
    let chosen = live || 'yarbo';
    if (!visibleIds.includes(chosen)) {
        chosen = visibleIds[0] || 'yarbo';
    }
    els.vestaboardLiveSwitch?.querySelectorAll('[data-vestaboard-live]').forEach((btn) => {
        const id = btn.getAttribute('data-vestaboard-live') || '';
        btn.classList.toggle('is-active', id === chosen);
    });
    if (els.settingsVestaboardLive) {
        [...els.settingsVestaboardLive.options].forEach((opt) => {
            const id = opt.value;
            let show = true;
            if (id === 'yarbo') show = enabled.yarbo;
            else if (id === 'powerwall') show = enabled.powerwall;
            else if (id === 'lymow') show = enabled.lymow;
            else if (id === 'batteries') show = allowAll;
            opt.hidden = !show;
            opt.disabled = !show;
        });
        const values = [...els.settingsVestaboardLive.options]
            .filter((o) => !o.hidden && !o.disabled)
            .map((o) => o.value);
        els.settingsVestaboardLive.value = values.includes(chosen) ? chosen : (values[0] || 'yarbo');
    }
    applyRotateViewChoices(e);
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
        if (pane === 'cloud' || pane === 'rain') return 'yarbo';
        return SETTINGS_PANES.includes(pane) ? pane : 'connection';
    }
    return null;
}

function showSettingsPane(pane, { updateHash = true } = {}) {
    if (pane === 'cloud' || pane === 'rain') pane = 'yarbo';
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
    if (id === 'home') {
        loadHomeDashboard();
    }
    if (id === 'unifi') {
        loadUnifiDashboard({ silent: true });
    }
}

function openSettingsModal(pane) {
    if (!els.settingsModal) return;
    if (mailPageOpen) closeMailPage({ updateHash: false });
    if (autoPageOpen) closeHomeAutomations({ updateHash: false });
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
        els.settingsOpen.classList.add('is-active');
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
        els.settingsOpen.classList.remove('is-active');
    }
    setSettingsError(null);
    setCloudTestResult(null);
    setConnectionTestResult(null);
    setUpdateResult(null);
    if ((location.hash || '').startsWith('#settings')) {
        history.replaceState(null, '', `${location.pathname}${location.search}`);
    }
    fetchStatus().catch(() => {});
}

function mailHashOpen() {
    return (location.hash || '').replace(/^#/, '') === 'mail';
}

function setMailCompanionVisible(visible) {
    const show = Boolean(visible);
    els.mailOpenWrap?.classList.toggle('hidden', !show);
    if (!show && mailPageOpen) {
        closeMailPage();
    }
}

function setMailUnreadBadge(count) {
    const n = Number(count || 0);
    if (!els.mailUnreadBadge) return;
    const has = n > 0;
    els.mailUnreadBadge.classList.toggle('hidden', !has);
    els.mailUnreadBadge.setAttribute('aria-hidden', has ? 'false' : 'true');
    els.mailUnreadBadge.title = n === 1 ? '1 unread message' : `${Math.max(0, n)} unread messages`;
    if (els.mailOpen) {
        els.mailOpen.setAttribute('aria-label', has
            ? `Messages, ${n === 1 ? '1 unread' : `${n} unread`}`
            : 'Messages');
    }
}

function openMailPage({ updateHash = true } = {}) {
    if (!els.mailPage || els.mailOpenWrap?.classList.contains('hidden')) return;
    if (settingsModalOpen) closeSettingsModal();
    if (autoPageOpen) closeHomeAutomations({ updateHash: false });
    const alreadyOpen = mailPageOpen;
    mailPageOpen = true;
    if (statusAbort) {
        statusAbort.abort();
        statusAbort = null;
        polling = false;
    }
    els.mailPage.classList.remove('hidden');
    document.body.classList.add('mail-page-open');
    if (els.mailOpen) {
        els.mailOpen.textContent = 'Dashboard';
        els.mailOpen.setAttribute('aria-expanded', 'true');
        els.mailOpen.classList.add('is-active');
    }
    if (updateHash && location.hash !== '#mail') {
        history.replaceState(null, '', `${location.pathname}${location.search}#mail`);
    }
    if (!alreadyOpen) {
        fetchPaperMail();
        document.getElementById('paper-mail-text')?.focus();
    }
}

function closeMailPage({ updateHash = true } = {}) {
    if (!els.mailPage) return;
    els.mailPage.classList.add('hidden');
    document.body.classList.remove('mail-page-open');
    mailPageOpen = false;
    if (els.mailOpen) {
        els.mailOpen.textContent = 'Messages';
        els.mailOpen.setAttribute('aria-expanded', 'false');
        els.mailOpen.classList.remove('is-active');
    }
    if (updateHash && mailHashOpen()) {
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
        if (data.hub) {
            applyModuleSwitcher(data.hub);
            if (data.hub.modules?.home) {
                loadHomeDashboard();
            }
        }
        if (data.vestaboard) {
            updateVestaboardDashboard({ vestaboard: data.vestaboard, hub: data.hub });
        }
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
        if (els.settingsModuleHome) {
            els.settingsModuleHome.checked = Boolean(data.hub?.modules?.home);
        }
        if (els.settingsModuleUnifi) {
            els.settingsModuleUnifi.checked = Boolean(data.hub?.modules?.unifi);
        }
        if (els.settingsUnifiHost) els.settingsUnifiHost.value = data.unifi?.host || '';
        if (els.settingsUnifiVerifyTls) els.settingsUnifiVerifyTls.checked = Boolean(data.unifi?.verify_tls);
        if (els.settingsUnifiProtectKey) els.settingsUnifiProtectKey.value = '';
        if (els.settingsUnifiProtectUser) els.settingsUnifiProtectUser.value = data.unifi?.protect_username || '';
        if (els.settingsUnifiProtectPassword) els.settingsUnifiProtectPassword.value = '';
        if (els.settingsUnifiAccessToken) els.settingsUnifiAccessToken.value = '';
        if (els.settingsUnifiAccessStandalone) {
            els.settingsUnifiAccessStandalone.checked = Boolean(data.unifi?.access_standalone);
        }
        if (Array.isArray(data.unifi?.devices) && data.unifi.devices.length) {
            renderUnifiHomePicker(data.unifi, { replace: !unifiPickerDirty });
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
    if (els.papermonoSetupKit) {
        els.papermonoSetupKit.textContent = kind === 'papercolor'
            ? 'Download Paper Colour USB setup kit'
            : 'Download PaperMono USB setup kit';
    }
    const kitHint = document.getElementById('papermono-kit-hint');
    if (kitHint) {
        const hold = kind === 'papercolor' ? 'about 3 seconds' : 'about 2 seconds';
        const docs = kind === 'papercolor'
            ? 'https://github.com/martyndix/yarbo-control-panel/blob/main/docs/papercolor.md#set-up-away-from-the-panel'
            : 'https://github.com/martyndix/yarbo-control-panel/blob/main/docs/papermono.md#set-up-away-from-the-panel';
        kitHint.innerHTML = `To prepare a ${label} away from this host: enter the <strong>site</strong> 2.4 GHz Wi-Fi and the panel URL the tablet will use (not localhost), then download the USB setup kit. Unzip on a Mac or Windows PC, plug the tablet in (hold power ${hold} for download mode), and run <code>python3 flash.py</code> (or <code>py flash.py</code>). The script installs esptool in a local <code>.venv</code> — Homebrew <code>pip install</code> is blocked. The zip contains the Wi-Fi password and pairing token — keep it private. Mac and Windows steps: <a href="${docs}" target="_blank" rel="noopener">docs</a>.`;
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

function paperRemoteProvider() {
    const checked = document.querySelector('input[name="papermono-remote-provider"]:checked');
    return checked?.value === 'custom' ? 'custom' : 'tailscale';
}

function setPaperRemoteResult(message, type) {
    if (!els.papermonoRemoteResult) return;
    if (!message) {
        els.papermonoRemoteResult.textContent = '';
        els.papermonoRemoteResult.className = 'settings-cloud-result hidden';
        return;
    }
    els.papermonoRemoteResult.textContent = message;
    els.papermonoRemoteResult.className = `settings-cloud-result ${type || ''}`.trim();
    els.papermonoRemoteResult.classList.remove('hidden');
}

function paperRemoteHttpUrl(value) {
    const url = String(value || '').trim();
    return /^https?:\/\//i.test(url) ? url : '';
}

function showPaperRemoteLink(wrapEl, linkEl, url) {
    if (!wrapEl || !linkEl) return;
    if (url) {
        linkEl.href = url;
        linkEl.textContent = url;
        wrapEl.classList.remove('hidden');
        return;
    }
    wrapEl.classList.add('hidden');
}

function openPaperRemoteUrl(url) {
    if (!url) return;
    try {
        window.open(url, '_blank', 'noopener');
    } catch (err) {
        // Popup blocked — the visible link stays on the page.
    }
}

function applyPaperRemoteUi(remote, opts = {}) {
    if (!remote || typeof remote !== 'object') return;
    const applyForm = opts.form !== false && !els.papermonoRemoteEnabled?.dataset.dirty;
    if (applyForm) {
        if (els.papermonoRemoteEnabled) {
            els.papermonoRemoteEnabled.checked = Boolean(remote.enabled);
        }
        const provider = remote.provider === 'custom' ? 'custom' : 'tailscale';
        document.querySelectorAll('input[name="papermono-remote-provider"]').forEach((input) => {
            input.checked = input.value === provider;
            input.closest('.papermono-kind-card')?.classList.toggle('is-active', input.checked);
        });
        if (els.papermonoRemoteUrl && remote.origin && !els.papermonoRemoteUrl.dataset.dirty) {
            els.papermonoRemoteUrl.value = remote.origin;
        } else if (els.papermonoRemoteUrl && !els.papermonoRemoteUrl.value && remote.origin) {
            els.papermonoRemoteUrl.value = remote.origin;
        }
    }
    const provider = paperRemoteProvider();
    const enabled = Boolean(els.papermonoRemoteEnabled?.checked);
    const origin = els.papermonoRemoteUrl?.value.trim() || remote.origin || '';
    const ts = remote.tailscale || {};
    const bits = [];
    if (!enabled) {
        bits.push('Remote access is off. Tablets only use the LAN Panel URL.');
    } else if (remote.tablet_url || origin) {
        bits.push(`Tablets will try ${remote.tablet_url || origin} after the LAN URL.`);
    } else {
        bits.push('Remote is on, but there is no HTTPS origin yet.');
    }
    if (provider === 'tailscale') {
        if (!ts.installed) bits.push('Tailscale is not installed on this host.');
        else if (!ts.logged_in) bits.push('Tailscale is installed. Click Log in. A login link must appear above — if nothing opens, the Pi may already be logged in; skip to Funnel.');
        else if (ts.needs_funnel_acl) bits.push('Funnel is not a General access rule. Open Access controls → JSON editor (left sidebar), add nodeAttrs funnel, then Start Funnel.');
        else if (!ts.funnel_on) bits.push('Logged in. Funnel is still off. Open Access controls → JSON editor and add nodeAttrs funnel, then Start Funnel.');
        else bits.push('Funnel is on.');
        if (remote.gate_listening) bits.push('The tablet-only gate is listening.');
        else if (enabled) bits.push('The tablet-only gate is not listening yet — Save remote access, or restart the panel.');
    }
    if (ts.error) bits.push(ts.error);
    if (els.papermonoRemoteStatus) {
        els.papermonoRemoteStatus.textContent = bits.join(' ');
    }
    showPaperRemoteLink(els.papermonoRemoteAuthWrap, els.papermonoRemoteAuth, paperRemoteHttpUrl(ts.auth_url));
    showPaperRemoteLink(els.papermonoRemoteFunnelUrlWrap, els.papermonoRemoteFunnelUrl, paperRemoteHttpUrl(ts.funnel_enable_url));
    if (els.papermonoRemoteFunnelHelp) {
        const needFunnel = provider === 'tailscale' && Boolean(ts.installed)
            && (Boolean(ts.needs_funnel_acl) || (Boolean(ts.logged_in) && !ts.funnel_on));
        els.papermonoRemoteFunnelHelp.classList.toggle('is-needed', needFunnel);
    }
    const tailscaleOn = provider === 'tailscale';
    if (els.papermonoRemoteInstall) els.papermonoRemoteInstall.classList.toggle('hidden', !tailscaleOn);
    if (els.papermonoRemoteLogin) els.papermonoRemoteLogin.classList.toggle('hidden', !tailscaleOn);
    if (els.papermonoRemoteFunnel) els.papermonoRemoteFunnel.classList.toggle('hidden', !tailscaleOn);
}

async function savePaperRemote() {
    const enabled = Boolean(els.papermonoRemoteEnabled?.checked);
    const provider = paperRemoteProvider();
    const origin = els.papermonoRemoteUrl?.value.trim() ?? '';
    setPaperRemoteResult('Saving remote access…');
    try {
        const res = await fetch('/api/device.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'paper_remote', enabled, provider, origin }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Could not save remote access');
        if (els.papermonoRemoteUrl) delete els.papermonoRemoteUrl.dataset.dirty;
        if (els.papermonoRemoteEnabled) delete els.papermonoRemoteEnabled.dataset.dirty;
        applyPaperRemoteUi(data, { form: true });
        setPaperRemoteResult(data.message || (enabled
            ? 'Remote access saved. Update tablets over Wi-Fi so they learn the URL.'
            : 'Remote access is off. Tablets keep using the LAN URL.'), 'success');
        showToast('Remote access saved', 'success');
    } catch (err) {
        setPaperRemoteResult(err.message || 'Could not save remote access', 'error');
    }
}

async function runPaperRemoteStep(step, button) {
    if (button) button.disabled = true;
    const labels = {
        install: 'Installing Tailscale…',
        up: 'Starting Tailscale login…',
        'funnel-on': 'Starting Funnel…',
        status: 'Refreshing…',
    };
    setPaperRemoteResult(labels[step] || 'Working…');
    try {
        const res = await fetch('/api/device.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'paper_remote_step', step }),
        });
        const data = await parseJsonResponse(res);
        const stepOut = data.step && typeof data.step === 'object' ? data.step : {};
        const ts = Object.assign({}, data.tailscale || {}, {
            auth_url: paperRemoteHttpUrl((data.tailscale || {}).auth_url) || paperRemoteHttpUrl(stepOut.auth_url),
            funnel_enable_url: paperRemoteHttpUrl((data.tailscale || {}).funnel_enable_url)
                || paperRemoteHttpUrl(stepOut.funnel_enable_url),
            logged_in: Boolean((data.tailscale || {}).logged_in || stepOut.logged_in),
            funnel_on: Boolean((data.tailscale || {}).funnel_on || stepOut.funnel_on),
            needs_funnel_acl: Boolean((data.tailscale || {}).needs_funnel_acl || stepOut.needs_funnel_acl),
            installed: Boolean((data.tailscale || {}).installed || stepOut.installed),
            error: String((data.tailscale || {}).error || stepOut.error || data.error || ''),
        });
        data.tailscale = ts;
        applyPaperRemoteUi(data);
        const authUrl = paperRemoteHttpUrl(ts.auth_url);
        const funnelUrl = paperRemoteHttpUrl(ts.funnel_enable_url);
        if (step === 'up' && authUrl) openPaperRemoteUrl(authUrl);
        if (step === 'funnel-on' && funnelUrl && !ts.funnel_on) openPaperRemoteUrl(funnelUrl);
        const err = String(data.error || stepOut.error || ts.error || '').trim();
        const funnelHelp = 'Funnel did not start. Login is not enough. The Access controls page is Policies (General access rules) — Funnel is not there. Click JSON editor in the left sidebar and add "nodeAttrs": [{ "target": ["autogroup:member"], "attr": ["funnel"] }], then Save. Turn on DNS MagicDNS and HTTPS Certificates. Then Start Funnel again.';
        if (step === 'install') {
            setPaperRemoteResult(data.message || stepOut.message || 'Tailscale installed. Click Log in next. A login link must appear — if nothing opens, the Pi may already be logged in.', data.ok === false ? 'error' : 'success');
        } else if (step === 'up') {
            if (authUrl) {
                setPaperRemoteResult('A Tailscale login page should have opened. If nothing appeared, tap the login link above on your phone or computer, then click Log in again.', 'success');
            } else if (ts.logged_in) {
                setPaperRemoteResult(data.message || 'This Pi is already logged in. Funnel is a second switch. On Access controls, ignore General access rules — click JSON editor in the left sidebar and add nodeAttrs funnel, then click Start Funnel.', 'success');
            } else {
                setPaperRemoteResult(err || 'No login page appeared. On the Pi run sudo ./scripts/paper_remote.sh up, or skip to Funnel if this machine is already in the Tailscale admin console.', 'error');
            }
        } else if (step === 'funnel-on') {
            if (data.tablet_url || ts.funnel_on) {
                setPaperRemoteResult(`Funnel is on${data.tablet_url ? ` (${data.tablet_url})` : ''}. Save remote access, then update the tablets.`, 'success');
            } else {
                setPaperRemoteResult(err || funnelHelp, 'error');
            }
        } else if (!data.ok) {
            setPaperRemoteResult(err || funnelHelp, 'error');
        } else {
            setPaperRemoteResult(data.message || err || 'Continue the numbered Funnel steps below.', err ? 'error' : 'success');
        }
        if (els.papermonoRemoteUrl && data.origin) {
            els.papermonoRemoteUrl.value = data.origin;
            delete els.papermonoRemoteUrl.dataset.dirty;
        }
    } catch (err) {
        setPaperRemoteResult(err.message || 'Tailscale step failed. See the Funnel steps below — Funnel is not a General access rule. Use JSON editor → nodeAttrs.', 'error');
    } finally {
        if (button) button.disabled = false;
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

function paperBatteryHtml(device) {
    const has = device.battery_level != null || device.battery_updated_at;
    if (!has) {
        return '<span class="papermono-battery papermono-battery--unknown">Battery —</span>';
    }
    const pct = device.battery_level != null ? `${Number(device.battery_level)}%` : '—';
    const charging = Boolean(device.is_charging);
    const cls = charging ? 'papermono-battery is-charging' : 'papermono-battery';
    const charge = charging ? ' · Charging' : '';
    return `<span class="${cls}" title="Tablet battery">${charging ? '⚡ ' : ''}${escapeHtml(pct)}${charge}</span>`;
}

function renderPaperMonoDevices(devices) {
    if (!els.papermonoDevices) return;
    if (!Array.isArray(devices) || devices.length === 0) {
        els.papermonoDevices.innerHTML = '<p class="hint">None yet. Flash a tablet over USB, or download a USB setup kit.</p>';
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
        const revokeLabel = device.name || device.kind_label || 'companion';
        const id = escapeHtml(device.id);
        const name = escapeHtml(device.name || 'PaperMono');
        return `<div class="papermono-device-row" data-papermono-row="${id}">
            <div class="papermono-device-meta">
                <p class="papermono-device-ota-name">${name}</p>
                <p class="hint">${kindLabel}${escapeHtml(online)} · ${paperBatteryHtml(device)} · ${escapeHtml(last)}${fw}</p>
                <div class="papermono-device-tools hidden">
                    <label class="settings-field papermono-device-name-field">
                        <span class="label">Tablet name</span>
                        <input type="text" maxlength="40" value="${name}" data-papermono-name="${id}" data-papermono-kind="${escapeHtml(device.kind || 'papermono')}">
                    </label>
                    <div class="papermono-device-tool-actions">
                        <button type="button" class="btn btn-secondary btn-compact" data-papermono-rename="${id}">Save name</button>
                        <button type="button" class="btn btn-secondary btn-compact" data-papermono-revoke="${id}" data-papermono-revoke-label="${escapeHtml(String(revokeLabel))}">Revoke</button>
                    </div>
                </div>
            </div>
            <div class="papermono-device-actions">
                ${paperOtaUpdateButton(device)}
                <button type="button" class="home-manage-toggle" data-papermono-gear="${id}" aria-pressed="false" aria-label="Rename or revoke ${name}" title="Rename or revoke">⚙️</button>
            </div>
        </div>`;
    }).join('');
    els.papermonoDevices.querySelectorAll('[data-papermono-gear]').forEach((button) => {
        button.addEventListener('click', () => {
            const row = button.closest('[data-papermono-row]');
            const tools = row?.querySelector('.papermono-device-tools');
            if (!tools) return;
            const open = tools.classList.toggle('hidden') === false;
            button.setAttribute('aria-pressed', open ? 'true' : 'false');
            if (open) {
                row?.querySelector('[data-papermono-name]')?.focus();
            }
        });
    });
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

let paperMailState = {
    webId: 'web',
    name: 'Desktop',
    peers: [],
    messages: [],
    unread: 0,
    selectedId: '',
    chars: 180,
};

function applyPaperWebClientName(client) {
    const name = String(client?.name || 'Desktop');
    paperMailState.name = name;
    paperMailState.webId = String(client?.id || 'web');
    const dash = document.getElementById('paper-mail-name');
    const settings = document.getElementById('papermono-web-name');
    if (dash && document.activeElement !== dash) dash.value = name;
    if (settings && document.activeElement !== settings) settings.value = name;
}

async function savePaperWebClientName(raw) {
    const name = String(raw || '').trim();
    if (name === '') {
        throw new Error('Give the desktop client a name');
    }
    const id = paperMailState.webId || 'web';
    const res = await fetch('/api/device.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'rename', id, name }),
    });
    const data = await parseJsonResponse(res);
    if (!data.ok) throw new Error(data.error || 'Could not save the desktop name');
    applyPaperWebClientName(data.device);
    return data.device;
}

function paperMailPeerOptions(peers, selected) {
    const rows = Array.isArray(peers) ? peers : [];
    const opts = ['<option value="*">ALL</option>'];
    rows.forEach((peer) => {
        const id = String(peer.id || '');
        if (!id) return;
        const name = escapeHtml(peer.name || peer.kind_label || 'Tablet');
        const sel = selected === id ? ' selected' : '';
        opts.push(`<option value="${escapeHtml(id)}"${sel}>${name}</option>`);
    });
    return opts.join('');
}

function renderPaperMailInbox(messages) {
    const box = document.getElementById('paper-mail-inbox');
    if (!box) return;
    const rows = Array.isArray(messages) ? messages : [];
    if (rows.length === 0) {
        box.innerHTML = '<p class="hint">No messages yet.</p>';
        return;
    }
    box.innerHTML = rows.map((msg) => {
        const id = String(msg.id || '');
        const mine = Boolean(msg.mine);
        const unread = Boolean(msg.unread);
        const open = paperMailState.selectedId === id;
        const who = mine
            ? (msg.to === '*' ? 'To ALL' : `To ${msg.to_name || 'tablet'}`)
            : (msg.from_name || 'tablet');
        const preview = String(msg.text || '');
        const meta = mine ? (msg.read_label || 'Sent') : String(msg.at_local || '');
        return `<button type="button" class="paper-mail-row${unread ? ' is-unread' : ''}${open ? ' is-open' : ''}${mine ? ' is-mine' : ''}" data-mail-id="${escapeHtml(id)}">
            <span class="paper-mail-row-top">
                <strong>${escapeHtml(who)}</strong>
                <span class="paper-mail-row-meta">${escapeHtml(meta)}</span>
            </span>
            <span class="paper-mail-row-text">${escapeHtml(preview)}</span>
        </button>`;
    }).join('');
}

function renderPaperMail(data) {
    if (!data || typeof data !== 'object') return;
    applyPaperWebClientName(data.web_client);
    paperMailState.peers = Array.isArray(data.peers) ? data.peers : [];
    paperMailState.messages = Array.isArray(data.messages) ? data.messages : [];
    paperMailState.unread = Number(data.unread || 0);
    paperMailState.chars = Number(data.message_chars || 180);
    const hasTablets = data.has_tablets !== undefined
        ? Boolean(data.has_tablets)
        : paperMailState.peers.length > 0;
    setMailCompanionVisible(hasTablets);
    setMailUnreadBadge(paperMailState.unread);
    if (hasTablets && mailHashOpen() && !mailPageOpen) {
        openMailPage({ updateHash: false });
    }
    const toEl = document.getElementById('paper-mail-to');
    if (toEl && document.activeElement !== toEl) {
        const current = toEl.value || '*';
        toEl.innerHTML = paperMailPeerOptions(paperMailState.peers, current);
        if (![...toEl.options].some((opt) => opt.value === current)) {
            toEl.value = '*';
        }
    }
    const textEl = document.getElementById('paper-mail-text');
    if (textEl) {
        textEl.maxLength = paperMailState.chars;
        updatePaperMailCount();
    }
    renderPaperMailInbox(paperMailState.messages);
}

function updatePaperMailCount() {
    const textEl = document.getElementById('paper-mail-text');
    const countEl = document.getElementById('paper-mail-count');
    if (!textEl || !countEl) return;
    countEl.textContent = `${textEl.value.length}/${paperMailState.chars}`;
}

async function fetchPaperMail() {
    try {
        const res = await fetch('/api/device.php?action=mail', { cache: 'no-store' });
        const data = await parseJsonResponse(res);
        if (!data.ok) return;
        renderPaperMail(data);
    } catch {
        // Mail is optional; keep the last inbox on a poll miss.
    }
}

async function sendPaperMail(event) {
    event.preventDefault();
    const textEl = document.getElementById('paper-mail-text');
    const toEl = document.getElementById('paper-mail-to');
    const sendBtn = document.getElementById('paper-mail-send');
    const text = textEl?.value.trim() || '';
    if (text === '') {
        showToast('Write a note first', 'error');
        return;
    }
    if (sendBtn) sendBtn.disabled = true;
    try {
        const res = await fetch('/api/device.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'paper_message',
                to: toEl?.value || '*',
                text,
            }),
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) throw new Error(data.error || 'Could not send');
        if (textEl) textEl.value = '';
        updatePaperMailCount();
        showToast('Sent', 'success');
        await fetchPaperMail();
    } catch (err) {
        showToast(err.message || 'Could not send', 'error');
    } finally {
        if (sendBtn) sendBtn.disabled = false;
    }
}

async function openPaperMailRow(id) {
    const msg = paperMailState.messages.find((row) => row.id === id);
    if (!msg) return;
    paperMailState.selectedId = paperMailState.selectedId === id ? '' : id;
    renderPaperMailInbox(paperMailState.messages);
    if (msg.unread) {
        try {
            await fetch('/api/device.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'paper_read', id }),
            });
            await fetchPaperMail();
        } catch {
            // keep local open state
        }
    }
    if (!msg.mine) {
        const toEl = document.getElementById('paper-mail-to');
        if (toEl && msg.from && [...toEl.options].some((opt) => opt.value === msg.from)) {
            toEl.value = msg.from;
        }
    }
}

function initPaperMail() {
    document.getElementById('paper-mail-compose')?.addEventListener('submit', sendPaperMail);
    document.getElementById('paper-mail-text')?.addEventListener('input', updatePaperMailCount);
    document.getElementById('paper-mail-inbox')?.addEventListener('click', (event) => {
        const row = event.target.closest?.('[data-mail-id]');
        if (!row) return;
        openPaperMailRow(row.getAttribute('data-mail-id') || '');
    });
    document.getElementById('paper-mail-name-save')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        try {
            await savePaperWebClientName(document.getElementById('paper-mail-name')?.value);
            showToast('Desktop name saved', 'success');
            await fetchPaperMail();
        } catch (err) {
            showToast(err.message || 'Could not save the name', 'error');
        } finally {
            button.disabled = false;
        }
    });
    fetchPaperMail();
    setInterval(fetchPaperMail, POLL_INTERVAL_MS);
}

let paperMonoDashboardCache = null;
let paperPowerTimer = 0;

function ensurePaperPowerPoll() {
    if (paperPowerTimer) return;
    paperPowerTimer = window.setInterval(() => {
        if (!settingsModalOpen) return;
        const pane = document.querySelector('[data-settings-pane="papermono"]');
        if (pane?.classList.contains('is-active')) {
            loadPaperMonoDashboard({ applyRemoteForm: false });
        }
    }, 15000);
}

async function loadPaperMonoDashboard(opts = {}) {
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
        applyPaperWebClientName(data.web_client);
        applyPaperRemoteUi(data.paper_remote, { form: opts.applyRemoteForm !== false });
        renderPaperMonoDevices(data.devices);
        setMailCompanionVisible(Array.isArray(data.devices) && data.devices.length > 0);
        ensurePaperPowerPoll();
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
    setPaperMonoUsbBusy(true);
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
        setPaperMonoUsbBusy(false);
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
    setPaperMonoUsbBusy(true);
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
        setPaperMonoUsbBusy(false);
    }
}

function setPaperMonoUsbBusy(busy) {
    [els.papermonoFlash, els.papermonoConfig, els.papermonoSetupKit, els.papermonoBuild].forEach((el) => {
        if (el) el.disabled = busy;
    });
}

function paperPanelUrlLooksLocal(url) {
    try {
        const hostname = new URL(url).hostname.toLowerCase();
        return hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '::1';
    } catch {
        return /^(https?:\/\/)?(localhost|127\.0\.0\.1)\b/i.test(String(url || ''));
    }
}

async function downloadPaperSetupKit() {
    const payload = paperMonoFormPayload();
    const label = paperMonoLabel(payload.kind);
    if (!payload.wifi_ssid) {
        setPaperMonoResult(`Wi-Fi name (SSID) is required. ${label} is 2.4 GHz only.`, 'error');
        return;
    }
    if (!payload.panel_url) {
        setPaperMonoResult(`Panel URL is required so the ${label} can reach this server at the site.`, 'error');
        return;
    }
    if (paperPanelUrlLooksLocal(payload.panel_url)) {
        setPaperMonoResult('Use the URL the tablet will use at the site (not localhost).', 'error');
        return;
    }
    setPaperMonoUsbBusy(true);
    setPaperMonoResult(`Building if needed, then packing a ${label} USB setup kit. Leave this page open…`);
    try {
        const res = await fetch('/api/device.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'setup_kit', ...payload }),
        });
        const type = (res.headers.get('content-type') || '').toLowerCase();
        const isZip = type.includes('application/zip')
            || type.includes('application/x-zip')
            || res.headers.get('x-papermono-kit') === '1';
        if (!isZip) {
            const data = await parseJsonResponse(res);
            const extra = typeof data.log === 'string' && data.log.trim() !== ''
                ? `\n${data.log.trim().slice(-600)}`
                : '';
            throw new Error((data.error || 'Could not download the setup kit') + extra);
        }
        const blob = await res.blob();
        if (!blob || blob.size < 1024) {
            throw new Error('Setup kit download was empty.');
        }
        const disposition = res.headers.get('content-disposition') || '';
        const match = disposition.match(/filename="?([^";]+)"?/i);
        const filename = (match?.[1] || `${payload.kind}-setup.zip`).trim();
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
        setPaperMonoResult(
            `${label} USB setup kit downloaded (${filename}). It is already listed as paired; it comes online after you flash it on a laptop and it joins the site Wi-Fi. If you never flash it, revoke it from the list.`,
            'success',
        );
        showToast(`${label} setup kit downloaded`, 'success');
        loadPaperMonoDashboard();
    } catch (err) {
        setPaperMonoResult(err.message || 'Could not download the setup kit', 'error');
    } finally {
        setPaperMonoUsbBusy(false);
    }
}

function applyPaperMonoPrefs(prefs) {
    if (!prefs || typeof prefs !== 'object') return;
    if (els.papermonoLockScreen) els.papermonoLockScreen.value = prefs.lock_screen || 'both';
    applyPaperMenuLabels(prefs.menu_labels);
    applyPaperMenuVisible(prefs.menu_visible);
    applyPaperMenuOrder(prefs.menu_order);
    applyPaperMonoTimezone(prefs.timezone || '');
    if (els.papermonoLockAfter) els.papermonoLockAfter.value = String(prefs.lock_after_s ?? 60);
    if (els.papermonoLightOff) els.papermonoLightOff.value = String(prefs.light_off_s ?? 15);
    if (els.papermonoBrightness) els.papermonoBrightness.value = String(prefs.brightness ?? 80);
    if (els.papermonoAlertMessage) els.papermonoAlertMessage.checked = prefs.alert_message !== false;
    if (els.papermonoAlertYarbo) els.papermonoAlertYarbo.checked = prefs.alert_yarbo !== false;
    if (els.papermonoAlertLymow) els.papermonoAlertLymow.checked = prefs.alert_lymow !== false;
    if (els.papermonoAlertPowerwall) els.papermonoAlertPowerwall.checked = prefs.alert_powerwall !== false;
    applyPaperPreviewModules();
}

function applyPaperMenuLabels(labels) {
    const map = labels && typeof labels === 'object' ? labels : {};
    document.querySelectorAll('[data-menu-label]').forEach((el) => {
        const id = el.getAttribute('data-menu-label') || '';
        el.value = String(map[id] || el.getAttribute('placeholder') || '');
    });
}

function paperMenuLabelsPayload() {
    const out = {};
    document.querySelectorAll('[data-menu-label]').forEach((el) => {
        const id = el.getAttribute('data-menu-label');
        if (!id) return;
        out[id] = String(el.value || '').trim();
    });
    return out;
}

function applyPaperMenuVisible(vis) {
    const map = vis && typeof vis === 'object' ? vis : {};
    document.querySelectorAll('[data-menu-visible]').forEach((el) => {
        const id = el.getAttribute('data-menu-visible') || '';
        el.checked = map[id] !== false;
    });
}

function paperMenuVisiblePayload() {
    const out = {};
    document.querySelectorAll('[data-menu-visible]').forEach((el) => {
        const id = el.getAttribute('data-menu-visible');
        if (!id) return;
        if (el.closest('[data-menu-id]')?.classList.contains('hidden')) return;
        out[id] = !!el.checked;
    });
    return out;
}

function paperMenuOrderPayload() {
    return [...document.querySelectorAll('#paper-menu-list [data-menu-id]')]
        .map((el) => el.getAttribute('data-menu-id') || '')
        .filter(Boolean);
}

function applyPaperMenuOrder(order) {
    const list = document.getElementById('paper-menu-list');
    if (!list) return;
    const rows = new Map();
    list.querySelectorAll('[data-menu-id]').forEach((el) => {
        rows.set(el.getAttribute('data-menu-id'), el);
    });
    const ids = Array.isArray(order) && order.length
        ? order.map((id) => String(id || ''))
        : paperMenuOrderPayload();
    const seen = new Set();
    ids.forEach((id) => {
        const row = rows.get(id);
        if (!row || seen.has(id)) return;
        seen.add(id);
        list.appendChild(row);
    });
    rows.forEach((row, id) => {
        if (!seen.has(id)) list.appendChild(row);
    });
}

function movePaperMenuRow(row, dir) {
    const list = document.getElementById('paper-menu-list');
    if (!list || !row) return;
    const rows = [...list.querySelectorAll(':scope > [data-menu-id]')];
    const idx = rows.indexOf(row);
    const next = idx + Number(dir);
    if (idx < 0 || next < 0 || next >= rows.length) return;
    if (dir < 0) {
        list.insertBefore(row, rows[next]);
    } else {
        list.insertBefore(row, rows[next].nextSibling);
    }
}

function applyPaperMonoTimezone(zone) {
    const sel = els.papermonoTimezone;
    if (!sel) return;
    const value = String(zone || '');
    if (value && ![...sel.options].some((opt) => opt.value === value)) {
        const opt = document.createElement('option');
        opt.value = value;
        opt.textContent = value.replace(/_/g, ' ');
        sel.appendChild(opt);
    }
    sel.value = value;
}

function paperMonoPrefsPayload() {
    return {
        action: 'prefs',
        lock_screen: els.papermonoLockScreen?.value || 'both',
        menu_labels: paperMenuLabelsPayload(),
        menu_visible: paperMenuVisiblePayload(),
        menu_order: paperMenuOrderPayload(),
        timezone: els.papermonoTimezone?.value || clientTimezone() || '',
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
        await savePaperWebClientName(document.getElementById('papermono-web-name')?.value);
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
    const row = button?.closest('[data-papermono-row]');
    const typedName = row?.querySelector('[data-papermono-name]')?.value.trim()
        || button?.dataset?.papermonoRevokeLabel
        || 'companion';
    if (!window.confirm(`Revoke ${typedName}? It will stop receiving status until you flash or pair it again.`)) {
        return;
    }
    if (!window.confirm(`This cannot be undone from Settings. Continue revoking ${typedName}?`)) {
        return;
    }
    const typed = window.prompt(`Type the tablet name “${typedName}” to revoke it:`);
    if (typed === null) {
        return;
    }
    if (typed.trim() !== typedName) {
        showToast('Name did not match. The tablet was not revoked.', 'error');
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
        showToast(`${typedName} revoked`, 'success');
        loadPaperMonoDashboard();
        fetchPaperMail();
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
        showSettingsPane('yarbo');
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
            module_home: Boolean(els.settingsModuleHome?.checked),
            module_unifi: Boolean(els.settingsModuleUnifi?.checked),
            modules: {
                yarbo: Boolean(els.settingsModuleYarbo?.checked),
                powerwall: Boolean(els.settingsModulePowerwall?.checked),
                lymow: Boolean(els.settingsModuleLymow?.checked),
                home: Boolean(els.settingsModuleHome?.checked),
                unifi: Boolean(els.settingsModuleUnifi?.checked),
            },
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
            unifi_host: els.settingsUnifiHost?.value.trim() || '',
            unifi_verify_tls: Boolean(els.settingsUnifiVerifyTls?.checked),
            unifi_protect_username: els.settingsUnifiProtectUser?.value.trim() || '',
            unifi_access_standalone: Boolean(els.settingsUnifiAccessStandalone?.checked),
        };
        const rainRaw = els.settingsRainSensitivity?.value.trim() ?? '';
        payload.rain_sensitivity = rainRaw === '' ? '' : rainRaw;
        if (document.querySelectorAll('input[data-unifi-home]').length) {
            payload.unifi_show_on_home = unifiShowOnHomeSnapshot();
        }
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
        const unifiKey = els.settingsUnifiProtectKey?.value ?? '';
        if (unifiKey !== '') payload.unifi_protect_api_key = unifiKey;
        const unifiPass = els.settingsUnifiProtectPassword?.value ?? '';
        if (unifiPass !== '') payload.unifi_protect_password = unifiPass;
        const unifiToken = els.settingsUnifiAccessToken?.value ?? '';
        if (unifiToken !== '') payload.unifi_access_token = unifiToken;
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
        unifiPickerDirty = false;
        if (data.hub) {
            applyModuleSwitcher(data.hub);
            applyCompanionSettingsVisibility();
            if (data.vestaboard) {
                applyVestaboardLiveSwitch({ hub: data.hub, vestaboard: data.vestaboard });
            } else {
                applyVestaboardLiveChoices(vestaboardExtraModules(data.hub), data.hub.vestaboard_live || '');
            }
        }
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
    const extra = options.signal;
    const onExtraAbort = () => controller.abort();
    if (extra) {
        if (extra.aborted) controller.abort();
        else extra.addEventListener('abort', onExtraAbort, { once: true });
    }
    try {
        const { signal: _ignored, ...rest } = options;
        return await fetch(url, { ...rest, signal: controller.signal });
    } finally {
        clearTimeout(timer);
        extra?.removeEventListener?.('abort', onExtraAbort);
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
    if (polling || settingsModalOpen || mailPageOpen || driveActive) return;
    if (Date.now() < commandQuietUntil) return;
    polling = true;
    if (statusAbort) {
        statusAbort.abort();
    }
    statusAbort = new AbortController();
    const { signal } = statusAbort;
    let timedOut = false;
    const timer = setTimeout(() => {
        timedOut = true;
        statusAbort.abort();
    }, 15000);
    try {
        const res = await fetch('/api/status.php', { signal, headers: clientTimezoneHeaders() });
        const data = await parseJsonResponse(res);
        if (settingsModalOpen || mailPageOpen || driveActive) return;
        if (data.ok) {
            hasStatusSnapshot = true;
            setError(null);
            updateStatus(data);
            updateCameraStatus(data.camera_state);
        } else {
            applyHubFromStatus(data);
            updateVestaboardDashboard(data);
            if (!(data.transient && hasStatusSnapshot)) {
                setError(data.error || 'Failed to fetch status');
            }
        }
    } catch (err) {
        if (settingsModalOpen || mailPageOpen || driveActive) return;
        if (err?.name === 'AbortError') {
            if (timedOut) {
                setError('Yarbo status timed out. Other modules should still work.');
            }
            return;
        }
        setError(err.message || 'Network error');
    } finally {
        clearTimeout(timer);
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
document.getElementById('map-load-backups')?.addEventListener('click', () => {
    loadMapBackups();
});
if (document.getElementById('map-load-backups')) {
    fetch('/api/map_backup.php', { cache: 'no-store' })
        .then((res) => parseJsonResponse(res))
        .then((data) => {
            if (data.compatible) {
                setMapSaveEnabled(true);
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
els.mailOpen?.addEventListener('click', () => {
    if (mailPageOpen) closeMailPage();
    else openMailPage();
});
document.querySelector('.settings-nav')?.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-settings-nav]');
    if (!btn) return;
    showSettingsPane(btn.getAttribute('data-settings-nav') || 'connection');
});
window.addEventListener('hashchange', () => {
    const pane = settingsHashPane();
    if (pane) openSettingsModal(pane);
    else if (mailHashOpen()) openMailPage({ updateHash: false });
    else if (homeAutoHashOpen()) openHomeAutomations({ updateHash: false });
    else {
        if (settingsModalOpen) closeSettingsModal();
        if (mailPageOpen) closeMailPage({ updateHash: false });
        if (autoPageOpen) closeHomeAutomations({ updateHash: false });
    }
});
if (els.settingsForm) {
    els.settingsForm.noValidate = true;
    els.settingsForm.addEventListener('submit', saveSettings);
}
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
els.settingsModuleHome?.addEventListener('change', onModuleCheckboxChange);
els.settingsModuleUnifi?.addEventListener('change', onModuleCheckboxChange);
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
els.settingsUnifiTest?.addEventListener('click', async (e) => {
    const button = e.currentTarget;
    button.disabled = true;
    if (els.settingsUnifiResult) {
        els.settingsUnifiResult.textContent = 'Talking to the UniFi console…';
        els.settingsUnifiResult.className = 'settings-cloud-result';
        els.settingsUnifiResult.classList.remove('hidden');
    }
    try {
        const payload = {
            action: 'probe',
            unifi_host: els.settingsUnifiHost?.value.trim() || '',
            unifi_verify_tls: Boolean(els.settingsUnifiVerifyTls?.checked),
            unifi_protect_username: els.settingsUnifiProtectUser?.value.trim() || '',
            unifi_access_standalone: Boolean(els.settingsUnifiAccessStandalone?.checked),
        };
        if (document.querySelectorAll('input[data-unifi-home]').length) {
            payload.unifi_show_on_home = unifiShowOnHomeSnapshot();
        }
        const key = els.settingsUnifiProtectKey?.value ?? '';
        if (key !== '') payload.unifi_protect_api_key = key;
        const pass = els.settingsUnifiProtectPassword?.value ?? '';
        if (pass !== '') payload.unifi_protect_password = pass;
        const token = els.settingsUnifiAccessToken?.value ?? '';
        if (token !== '') payload.unifi_access_token = token;
        const data = await unifiApi(payload, 25000);
        if (!data.ok) throw new Error(data.error || data.message || 'UniFi test failed');
        const msg = data.message || 'UniFi connected.';
        if (els.settingsUnifiResult) {
            els.settingsUnifiResult.textContent = msg;
            els.settingsUnifiResult.className = 'settings-cloud-result success';
        }
        if (els.settingsUnifiProtectKey) els.settingsUnifiProtectKey.value = '';
        if (els.settingsUnifiProtectPassword) els.settingsUnifiProtectPassword.value = '';
        if (els.settingsUnifiAccessToken) els.settingsUnifiAccessToken.value = '';
        renderUnifiDashboard(data, { replace: true });
        showToast(msg, 'success');
        if (lastHub?.modules?.home) loadHomeDashboard();
    } catch (err) {
        const message = err.message || 'UniFi test failed';
        if (els.settingsUnifiResult) {
            els.settingsUnifiResult.textContent = message;
            els.settingsUnifiResult.className = 'settings-cloud-result error';
        }
        showToast(message, 'error');
    } finally {
        button.disabled = false;
    }
});
document.getElementById('unifi-card')?.addEventListener('change', (event) => {
    const input = event.target.closest('input[data-unifi-home]');
    if (!input) return;
    saveUnifiShowOnHome(input.getAttribute('data-unifi-home') || '', input.checked);
});
els.settingsUnifiDevices?.addEventListener('change', (event) => {
    const input = event.target.closest('input[data-unifi-home]');
    if (!input) return;
    saveUnifiShowOnHome(input.getAttribute('data-unifi-home') || '', input.checked);
});
document.getElementById('unifi-card')?.addEventListener('click', async (event) => {
    const toggleBtn = event.target.closest('[data-unifi-light], [data-unifi-relay]');
    if (toggleBtn) {
        const id = toggleBtn.getAttribute('data-unifi-light') || toggleBtn.getAttribute('data-unifi-relay') || '';
        const card = toggleBtn.closest('[data-unifi-id]');
        const on = card?.classList.contains('is-on');
        const nextOn = !on;
        card?.classList.toggle('is-on', nextOn);
        if (toggleBtn) toggleBtn.textContent = nextOn ? 'Off' : 'On';
        toggleBtn.disabled = true;
        try {
            const data = await unifiApi({ action: 'command', id, command: nextOn ? 'on' : 'off' });
            if (!data.ok) throw new Error(data.error || 'Failed');
            if (typeof data.on === 'boolean') {
                card?.classList.toggle('is-on', data.on);
                toggleBtn.textContent = data.on ? 'Off' : 'On';
            }
            if (lastHub?.modules?.home) loadHomeDashboard({ patch: true });
        } catch (err) {
            card?.classList.toggle('is-on', Boolean(on));
            toggleBtn.textContent = on ? 'Off' : 'On';
            showToast(err.message || 'UniFi command failed', 'error');
        } finally {
            toggleBtn.disabled = false;
        }
        return;
    }
    const doorBtn = event.target.closest('[data-unifi-door]');
    if (doorBtn) {
        const id = doorBtn.getAttribute('data-unifi-door') || '';
        const cmd = doorBtn.getAttribute('data-unifi-cmd') || 'unlock';
        doorBtn.disabled = true;
        try {
            const data = await unifiApi({ action: 'command', id, command: (cmd === 'lock' ? 'unlock' : cmd), control_cmd: (cmd === 'unlock' || cmd === 'lock') ? '' : cmd });
            if (!data.ok) throw new Error(data.error || 'Failed');
            showToast(unifiDoorCommandToast(cmd), 'success');
            await loadUnifiDashboard({ silent: true });
        } catch (err) {
            showToast(err.message || 'UniFi door failed', 'error');
        } finally {
            doorBtn.disabled = false;
        }
    }
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
els.papermonoRemoteSave?.addEventListener('click', () => savePaperRemote());
els.papermonoRemoteInstall?.addEventListener('click', (e) => runPaperRemoteStep('install', e.currentTarget));
els.papermonoRemoteLogin?.addEventListener('click', (e) => runPaperRemoteStep('up', e.currentTarget));
els.papermonoRemoteFunnel?.addEventListener('click', (e) => runPaperRemoteStep('funnel-on', e.currentTarget));
els.papermonoRemoteUrl?.addEventListener('input', () => {
    if (els.papermonoRemoteUrl) els.papermonoRemoteUrl.dataset.dirty = '1';
});
els.papermonoRemoteEnabled?.addEventListener('change', () => {
    if (els.papermonoRemoteEnabled) els.papermonoRemoteEnabled.dataset.dirty = '1';
});
document.querySelectorAll('input[name="papermono-remote-provider"]').forEach((input) => {
    input.addEventListener('change', () => {
        document.querySelectorAll('input[name="papermono-remote-provider"]').forEach((el) => {
            el.closest('.papermono-kind-card')?.classList.toggle('is-active', el.checked);
        });
        applyPaperRemoteUi({
            enabled: Boolean(els.papermonoRemoteEnabled?.checked),
            provider: paperRemoteProvider(),
            origin: els.papermonoRemoteUrl?.value.trim() || '',
            tablet_url: '',
            gate_listening: false,
            tailscale: {},
        });
    });
});
els.papermonoSetupKit?.addEventListener('click', () => downloadPaperSetupKit());
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
document.getElementById('paper-menu-list')?.addEventListener('click', (event) => {
    const btn = event.target.closest?.('[data-menu-move]');
    if (!btn) return;
    const row = btn.closest('[data-menu-id]');
    movePaperMenuRow(row, btn.getAttribute('data-menu-move') || '0');
});
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
    if (els.mailPage && !els.mailPage.classList.contains('hidden')) {
        closeMailPage();
        return;
    }
    if (els.homeAutomationsPage && !els.homeAutomationsPage.classList.contains('hidden')) {
        closeHomeAutomations();
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
initPaperMail();
initUpdateConfirmModal();
loadSettings()
    .catch(() => {})
    .finally(() => {
        fetchStatus();
        setInterval(fetchStatus, POLL_INTERVAL_MS);
    });
bindHomeDashboard();
document.getElementById('home-setup')?.addEventListener('click', (event) => {
    startHomeSetup(event.currentTarget);
});
document.getElementById('settings-home-setup')?.addEventListener('click', (event) => {
    startHomeSetup(event.currentTarget);
});
document.getElementById('home-pair')?.addEventListener('click', async () => {
    const input = document.getElementById('home-pair-code');
    const code = input?.value.trim() || '';
    const btn = document.getElementById('home-pair');
    if (btn) btn.disabled = true;
    try {
        const data = await homeApi({ action: 'commission', code }, 95000);
        if (!data.ok) throw new Error(data.error || 'Pairing failed');
        if (input) input.value = '';
        showToast(data.message || 'Device added', 'success');
        await loadHomeDashboard();
    } catch (err) {
        showToast(err.message || 'Pairing failed', 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
});
document.getElementById('home-scene-from-on')?.addEventListener('click', () => {
    const nameEl = document.getElementById('home-scene-name');
    homeSceneDraft.name = nameEl?.value.trim() || homeSceneDraft.name || '';
    homeFillSceneDraftFromOn();
    const count = Object.values(homeSceneDraft.included).filter(Boolean).length;
    if (!count) {
        showToast('No lights are on. Turn some on, then try again.', 'error');
        return;
    }
    renderHomeSceneEditor();
    showToast(`Using ${count} light${count === 1 ? '' : 's'} that ${count === 1 ? 'is' : 'are'} on`, 'success');
});
document.getElementById('home-scene-cancel')?.addEventListener('click', () => {
    homeResetSceneDraft();
    const nameEl = document.getElementById('home-scene-name');
    if (nameEl) nameEl.value = '';
    renderHomeSceneEditor();
});
document.getElementById('home-scene-save')?.addEventListener('click', async () => {
    const actions = collectHomeSceneActions();
    const name = (document.getElementById('home-scene-name')?.value.trim() || homeSceneDraft.name || '').trim();
    if (!name) {
        showToast('Name the scene first', 'error');
        return;
    }
    if (!actions.length) {
        showToast('Tick at least one light, or use lights that are on', 'error');
        return;
    }
    try {
        const payload = { action: 'scene_save', name, actions };
        if (homeSceneDraft.id) payload.id = homeSceneDraft.id;
        const data = await homeApi(payload);
        if (!data.ok) throw new Error(data.error || 'Could not save scene');
        homeResetSceneDraft();
        const nameEl = document.getElementById('home-scene-name');
        if (nameEl) nameEl.value = '';
        showToast('Scene saved', 'success');
        await loadHomeDashboard();
    } catch (err) {
        showToast(err.message || 'Could not save scene', 'error');
    }
});
document.getElementById('home-scene-members')?.addEventListener('change', (event) => {
    const row = event.target.closest('[data-scene-member]');
    if (!row) return;
    readHomeSceneDraftFromDom();
    renderHomeSceneEditor();
});
document.getElementById('home-scenes')?.addEventListener('click', async (event) => {
    const edit = event.target.closest('[data-home-scene-edit]');
    const run = event.target.closest('[data-home-scene]');
    const del = event.target.closest('[data-home-scene-del]');
    try {
        if (edit) {
            const scene = (homeDash.scenes || []).find((s) => s.id === edit.getAttribute('data-home-scene-edit'));
            if (!scene) return;
            homeLoadSceneDraft(scene);
            renderHomeSceneEditor();
            document.getElementById('home-scene-editor')?.scrollIntoView({ block: 'nearest' });
        } else if (run) {
            const data = await homeApi({ action: 'scene_run', id: run.getAttribute('data-home-scene') });
            if (!data.ok) throw new Error(data.error || 'Scene failed');
            showToast(data.message || (run.textContent === 'Off' ? 'Scene off' : 'Scene ran'), 'success');
            await loadHomeDashboard();
        } else if (del) {
            const data = await homeApi({ action: 'scene_delete', id: del.getAttribute('data-home-scene-del') });
            if (!data.ok) throw new Error(data.error || 'Could not delete');
            if (homeSceneDraft.id === del.getAttribute('data-home-scene-del')) {
                homeResetSceneDraft();
            }
            await loadHomeDashboard();
        }
    } catch (err) {
        showToast(err.message || 'Scene failed', 'error');
    }
});
document.getElementById('home-paper-tablets')?.addEventListener('click', (event) => {
    const btn = event.target.closest?.('[data-paper-tablet]');
    if (!btn) return;
    const id = btn.getAttribute('data-paper-tablet') || '';
    if (!id || id === homePaperTabletId) return;
    homePaperTabletId = id;
    homePaperFilter = '';
    renderHomePaperAssign(homeDash);
});
document.getElementById('home-paper-assign')?.addEventListener('input', (event) => {
    if (event.target?.id !== 'home-paper-filter') return;
    homePaperFilter = String(event.target.value || '');
    renderHomePaperAssign(homeDash);
});
document.getElementById('home-paper-assign')?.addEventListener('click', async (event) => {
    if (event.target?.id !== 'home-paper-copy') return;
    const fromId = document.getElementById('home-paper-copy-from')?.value || '';
    const from = (homeDash.paper_devices || []).find((p) => p.id === fromId);
    if (!from || !homePaperTabletId) return;
    await homeSavePaperAssignment([...(from.assigned || [])].slice(0, HOME_PAPER_MAX));
});
document.getElementById('home-paper-assign')?.addEventListener('change', async (event) => {
    const input = event.target.closest?.('input[type="checkbox"]');
    const row = event.target.closest?.('[data-paper-item]');
    if (!input || !row) return;
    const id = row.getAttribute('data-paper-item') || '';
    let ids = homePaperAssignedIds();
    if (input.checked) {
        if (!ids.includes(id) && ids.length >= HOME_PAPER_MAX) {
            input.checked = false;
            showToast(`PaperMono HOUSE holds ${HOME_PAPER_MAX} buttons`, 'error');
            return;
        }
        if (!ids.includes(id)) ids.push(id);
    } else {
        ids = ids.filter((item) => item !== id);
    }
    await homeSavePaperAssignment(ids);
});
refreshUpdateBadge();
if (settingsHashPane()) {
    openSettingsModal();
} else if (mailHashOpen()) {
    fetchPaperMail();
} else if (homeAutoHashOpen()) {
    openHomeAutomations({ updateHash: false });
}
