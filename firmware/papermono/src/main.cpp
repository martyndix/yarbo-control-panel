#include <Arduino.h>
#include <WiFi.h>
#include <WiFiClient.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <HTTPUpdate.h>
#include <Preferences.h>
#include <SPIFFS.h>
#include <ArduinoJson.h>
#include <M5Unified.h>
#include <cstring>
#include <time.h>
#include "version.h"
#include "paper_hw.h"
#include "paper_net.h"
#include <freertos/FreeRTOS.h>
#include <freertos/task.h>

// M5Stack PaperMono SKU C153 (https://docs.m5stack.com/en/core/PaperMono)
// ESP32-S3R8, SSD1677 480x800 4-level gray, FT6336G touch. Not PaperMono-Lite.
// Manufacturer e-paper rules we follow:
// - After ~10 fast refreshes, run one full-screen refresh to clear ghosting.
// - Do not stream uninterrupted partial refreshes (DC imbalance can damage the panel).
// - Skip redraws when status has not changed.
// - Draw the whole frame in RAM, then one display() (M5GFX auto-display off).
// - Use the panel's OTP waveforms (M5GFX epd_quality / epd_fastest); no custom LUTs.

Preferences prefs;
String wifiSsid;
String wifiPass;
String guestSsid;
String guestPass;
String panelUrl;
String remoteUrl;
String token;
String deviceName = "PaperMono";
String robotName = "";

uint32_t lastPoll = 0;
bool otaBusy = false;
bool otaTriedThisBoot = false;
bool usingRemote = false;
String lastError;
int battery = -1;
String charging = "—";
String state = "—";
String head = "—";
int errorCode = 0;
String errorLabel = "0";
String heading = "—";
String rainLabel = "—";
String connectionType = "—";
String connectionStatus = "—";
String wifiNetwork = "—";
String wifiSignal = "—";
String wifiSecurity = "—";
String batteryTemp = "—";
String wirelessCharge = "—";
String rtkStatus = "—";
String rtcmAge = "—";
String routePriority = "—";
String rainSensor = "—";
String netModule = "—";
String planActivity = "idle";
bool lightsOn = false;
String vestaboardLive = "yarbo";
bool vestaboardOn = true;
bool vestaboardKnown = false;
bool yarboOn = true;
bool powerwallOn = false;
bool lymowOn = false;
int powerwallPct = -1;
String powerwallSolar = "—";
String powerwallLoad = "—";
String lymowName = "";
int lymowBattery = -1;
String lymowState = "—";
String lymowCharging = "—";
int partialRefreshCount = 0;
String lastDrawnKey;
String logoHash = "";
int currentPage = PAPERMONO_PAGE_HOME;
bool screenLocked = false;
bool menuOpen = false;
uint32_t lastActivity = 0;
uint32_t lastLight = 0;
bool lightOn = true;
int lockAfterS = 60;
int lightOffS = 15;
int brightnessPct = 80;
String lockScreen = "both";
String unlockPage = "home";
String menuCustom[PAPERMONO_PAGE_COUNT];
bool menuShow[PAPERMONO_PAGE_COUNT] = {
    true, true, true, true, true, false, true, true, true, true, true
};
#define PAPERMONO_MENU_MAX 12
int menuLaidPage[PAPERMONO_MENU_MAX];
int menuLaidX[PAPERMONO_MENU_MAX];
int menuLaidY[PAPERMONO_MENU_MAX];
int menuLaidN = 0;
int menuLaidW = 208;
int menuLaidH = 100;
int menuOrderN = 7;
int menuOrderPage[PAPERMONO_MENU_MAX] = {
    PAPERMONO_PAGE_STATUS,
    PAPERMONO_PAGE_NOTE,
    PAPERMONO_PAGE_POWERWALL,
    PAPERMONO_PAGE_LYMOW,
    PAPERMONO_PAGE_RADIO,
    PAPERMONO_PAGE_DEVICE,
    PAPERMONO_PAGE_HOUSE
};
int lastYarboPage = PAPERMONO_PAGE_STATUS;
bool alertMessageOn = true;
bool alertYarboOn = true;
bool alertLymowOn = true;
bool alertPowerwallOn = true;
bool yarboError = false;
bool lymowError = false;
bool powerwallError = false;
uint32_t lastErrorAlert = 0;
int tabletBat = -1;
bool tabletCharging = false;
String clockLocal = "--:--";
String clockDate = "";
int clockOffset = 0;
bool clockOffsetSet = false;
uint32_t lastPanelClockMs = 0;
String deviceId = "";
int vestaboardCodes[3][15];
String vestaboardLines[3];
String vestaboardHash = "";
int unreadCount = 0;
bool ntpStarted = false;
uint32_t lastNtpTry = 0;
bool offConfirm = false;
bool kbNumbers = false;
bool kbShift = false;
int wifiUi = PAPERMONO_WIFI_IDLE;
int wifiTryStage = 0;
uint32_t wifiTryAt = 0;
uint32_t lastHomeLook = 0;
String wifiScanSsid[PAPERMONO_WIFI_SCAN_MAX];
bool wifiScanOpen[PAPERMONO_WIFI_SCAN_MAX];
int wifiScanCount = 0;
bool wifiScanBusy = false;
String wifiPickSsid;
String wifiDraft;
String radioDraft = "";
int radioToIndex = 0;
int radioUi = PAPERMONO_RADIO_INBOX;
int radioViewIndex = -1;
String peerIds[PAPERMONO_PEER_MAX];
String peerNames[PAPERMONO_PEER_MAX];
int peerCount = 0;
String inboxFrom[PAPERMONO_INBOX_MAX];
String inboxFromId[PAPERMONO_INBOX_MAX];
String inboxText[PAPERMONO_INBOX_MAX];
String inboxWhen[PAPERMONO_INBOX_MAX];
String inboxToName[PAPERMONO_INBOX_MAX];
String inboxStatus[PAPERMONO_INBOX_MAX];
String inboxIds[PAPERMONO_INBOX_MAX];
bool inboxUnread[PAPERMONO_INBOX_MAX];
bool inboxMine[PAPERMONO_INBOX_MAX];
int inboxCount = 0;
String lastInboxId = "";
String lastPostedMsgId = "";
uint8_t radioSync = 0xA5;
volatile int pendingPageSteps = 0;
volatile uint8_t pwrOffEvent = 0;
volatile int touchQX = 0;
volatile int touchQY = 0;
volatile uint8_t touchQ = 0;
bool inDraw = false;
bool redrawQueued = false;

String planIds[PAPERMONO_PLAN_MAX];
String planNames[PAPERMONO_PLAN_MAX];
int planCount = 0;
int planOffset = 0;
int selectedPlan = -1;
String plansNote = "";
bool plansLoaded = false;
bool homeOn = false;
String homeIds[PAPERMONO_HOME_MAX];
String homeNames[PAPERMONO_HOME_MAX];
String homeKinds[PAPERMONO_HOME_MAX];
bool homeOnState[PAPERMONO_HOME_MAX];
int homeCount = 0;

void drawScreen(bool forceFull);
void serviceTouchQueue();
void waitEpdReady();
bool applyPendingPages();
void drawVestaboardGrid(int x, int y, int cell, int gap);
void enterLock();
void exitLock();
void noteActivity();
void nextPage();
void prevPage();
void showPage(int page, bool loadPlansIfNeeded);
void layoutTileGrid(int n, int &cols, int &rows, int &bw, int &bh, int &gap, int &x0, int &y0);
String menuLabel(int page);
void drawPadlockIcon(int x, int y, int size, bool locked);
void drawWifiIcon(int cx, int cy, int size, bool connected);
void drawChargeBolt(int cx, int cy, int size);
void drawBatteryBadge(int right, int cy, int pct, bool compact);
bool tabletPluggedIn();
void refreshTabletPower(bool force);
bool takeTouchPress(int &x, int &y);
void applyFrontlight(bool on, bool force = false);
bool unreadFrontlightHold();
void ensureNtp();
void refreshLocalClock();
bool tapOnPadlock(int x, int y);
bool tapOnUnlock(int x, int y);
bool tapOnLockOff(int x, int y);
bool tapOnMail(int x, int y);
bool tapOnMenuChip(int x, int y);
void openInboxFromLock();
void openMenu();
void drawMenuPage(bool forceFull);
void handleMenuTouch(int x, int y);
void refreshUnreadLed();
void powerOffTablet();
void finishEpdFrame();
void paintRadioDraft(int x, int y);
void updateRadioDraft();
void applyDeviceOffTap(int x);
void applyRadioHit(int hit);
void wifiStartHome();
void wifiStartGuest();
void wifiService();
void startWifiScan();
void saveGuestWifi();
void clearGuestWifi();
bool httpGetPlans(bool refresh);

void saveConfig()
{
    prefs.begin("yarbo", false);
    prefs.putString("ssid", wifiSsid);
    prefs.putString("pass", wifiPass);
    prefs.putString("gssid", guestSsid);
    prefs.putString("gpass", guestPass);
    prefs.putString("url", panelUrl);
    prefs.putString("remurl", remoteUrl);
    prefs.putString("token", token);
    prefs.putString("name", deviceName);
    prefs.putInt("bright", brightnessPct);
    prefs.putString("lock", lockScreen);
    prefs.putString("unlock", unlockPage);
    prefs.putInt("tzoff", clockOffset);
    prefs.putBool("tzset", clockOffsetSet);
    prefs.putBool("vboard", vestaboardOn);
    prefs.putBool("vknown", vestaboardKnown);
    prefs.end();
}

void loadConfig()
{
    prefs.begin("yarbo", true);
    wifiSsid = prefs.getString("ssid", "");
    wifiPass = prefs.getString("pass", "");
    guestSsid = prefs.getString("gssid", "");
    guestPass = prefs.getString("gpass", "");
    panelUrl = prefs.getString("url", "");
    remoteUrl = prefs.getString("remurl", "");
    token = prefs.getString("token", "");
    deviceName = prefs.getString("name", "PaperMono");
    brightnessPct = constrain(prefs.getInt("bright", brightnessPct), 0, 100);
    String lock = prefs.getString("lock", lockScreen);
    if (lock == "logo" || lock == "vestaboard" || lock == "both") {
        lockScreen = lock;
    }
    String unlock = prefs.getString("unlock", unlockPage);
    if (unlock.length()) {
        unlockPage = unlock;
    }
    clockOffset = prefs.getInt("tzoff", clockOffset);
    clockOffsetSet = prefs.getBool("tzset", clockOffsetSet);
    vestaboardOn = prefs.getBool("vboard", vestaboardOn);
    vestaboardKnown = prefs.getBool("vknown", vestaboardKnown);
    prefs.end();
}

void applyCompanionFields(JsonDocument &doc, bool persist)
{
    if (doc["brightness"].is<int>()) {
        brightnessPct = constrain((int) doc["brightness"], 0, 100);
    }
    String lock = doc["lock_screen"] | "";
    if (lock == "logo" || lock == "vestaboard" || lock == "both") {
        lockScreen = lock;
    }
    String unlock = doc["unlock_page"] | "";
    if (unlock.length()) {
        unlockPage = unlock;
    }
    if (doc["clock_offset"].is<int>()) {
        clockOffset = (int) doc["clock_offset"];
        clockOffsetSet = true;
        ntpStarted = false;
    }
    if (doc["vestaboard_enabled"].is<bool>()) {
        vestaboardOn = doc["vestaboard_enabled"].as<bool>();
        vestaboardKnown = true;
    } else if (doc["vestaboard_enabled"].is<int>()) {
        vestaboardOn = ((int) doc["vestaboard_enabled"]) != 0;
        vestaboardKnown = true;
    }
    if (persist) {
        saveConfig();
    }
}

bool cfgLeaveSetup = false;

void applyConfigJson(const String &json)
{
    JsonDocument doc;
    if (deserializeJson(doc, json)) {
        Serial.println("CFG_ERR");
        return;
    }
    wifiSsid = doc["ssid"] | wifiSsid;
    wifiPass = doc["password"] | wifiPass;
    panelUrl = doc["panel_url"] | panelUrl;
    if (!doc["remote_url"].isNull()) {
        remoteUrl = doc["remote_url"] | remoteUrl;
    }
    token = doc["token"] | token;
    deviceName = doc["name"] | deviceName;
    applyCompanionFields(doc, false);
    paperNetNormalize(panelUrl);
    paperNetNormalize(remoteUrl);
    saveConfig();
    Serial.println("CFG_OK");
    Serial.flush();
    if (wifiSsid.length()) {
        cfgLeaveSetup = true;
    }
}

void pollSerialConfig()
{
    static String line;
    while (Serial.available()) {
        char c = (char) Serial.read();
        if (c == '\n') {
            line.trim();
            if (line.startsWith("CFG:")) {
                applyConfigJson(line.substring(4));
                if (wifiSsid.length()) {
                    wifiStartHome();
                }
                applyFrontlight(lightOn);
            }
            line = "";
        } else if (c != '\r' && line.length() < 1600) {
            line += c;
        }
    }
}

void wifiBegin(const String &ssid, const String &pass)
{
    if (!ssid.length()) {
        return;
    }
    WiFi.mode(WIFI_STA);
    WiFi.disconnect(true, false);
    delay(40);
    WiFi.begin(ssid.c_str(), pass.c_str());
}

void wifiStartGuest()
{
    if (!guestSsid.length()) {
        wifiTryStage = 0;
        return;
    }
    wifiTryStage = 2;
    wifiTryAt = millis();
    lastError = "joining " + guestSsid;
    wifiBegin(guestSsid, guestPass);
}

void wifiStartHome()
{
    if (!wifiSsid.length()) {
        wifiStartGuest();
        return;
    }
    wifiTryStage = 1;
    wifiTryAt = millis();
    lastError = "joining " + wifiSsid;
    wifiBegin(wifiSsid, wifiPass);
}

bool wifiHomeSsidVisible()
{
    if (!wifiSsid.length() || otaBusy || wifiUi != PAPERMONO_WIFI_IDLE) {
        return false;
    }
    int n = WiFi.scanNetworks(false, false);
    bool found = false;
    for (int i = 0; i < n; i++) {
        if (WiFi.SSID(i) == wifiSsid) {
            found = true;
            break;
        }
    }
    WiFi.scanDelete();
    return found;
}

void wifiService()
{
    if (otaBusy || wifiUi != PAPERMONO_WIFI_IDLE) {
        return;
    }
    uint32_t now = millis();
    if (WiFi.status() == WL_CONNECTED) {
        wifiTryStage = 0;
        if (lastError.startsWith("joining ")) {
            lastError = "";
        }
        if (wifiSsid.length() && guestSsid.length() && WiFi.SSID() == guestSsid
            && now - lastHomeLook > PAPERMONO_WIFI_HOME_LOOK_MS) {
            lastHomeLook = now;
            if (wifiHomeSsidVisible()) {
                wifiStartHome();
            }
        }
        return;
    }
    if (!wifiSsid.length() && !guestSsid.length()) {
        return;
    }
    if (wifiTryStage == 0) {
        wifiStartHome();
        return;
    }
    if (now - wifiTryAt < PAPERMONO_WIFI_TRY_MS) {
        return;
    }
    if (wifiTryStage == 1 && guestSsid.length()) {
        wifiStartGuest();
        return;
    }
    wifiStartHome();
}

void wifiCollectScanHits(int n)
{
    if (n <= 0) {
        if (n < 0) {
            WiFi.scanDelete();
        }
        return;
    }
    for (int i = 0; i < n && wifiScanCount < PAPERMONO_WIFI_SCAN_MAX; i++) {
        String ssid = WiFi.SSID(i);
        if (!ssid.length()) {
            continue;
        }
        if (wifiSsid.length() && ssid == wifiSsid) {
            continue;
        }
        bool dup = false;
        for (int j = 0; j < wifiScanCount; j++) {
            if (wifiScanSsid[j] == ssid) {
                dup = true;
                break;
            }
        }
        if (dup) {
            continue;
        }
        wifiScanSsid[wifiScanCount] = ssid;
        wifiScanOpen[wifiScanCount] = (WiFi.encryptionType(i) == WIFI_AUTH_OPEN);
        wifiScanCount++;
    }
    WiFi.scanDelete();
}

int wifiScanRadio()
{
    WiFi.setSleep(false);
    WiFi.mode(WIFI_STA);
    int n = WiFi.scanNetworks(false, true, false, PAPERMONO_WIFI_SCAN_MS);
    uint32_t t0 = millis();
    while (n == WIFI_SCAN_RUNNING && millis() - t0 < 15000) {
        pollSerialConfig();
        delay(100);
        n = WiFi.scanComplete();
    }
    return n;
}

void wifiAbortJoin()
{
    wifiTryStage = 0;
    WiFi.setSleep(false);
    WiFi.mode(WIFI_STA);
    WiFi.scanDelete();
    WiFi.disconnect(false, false);
    uint32_t t0 = millis();
    while (WiFi.status() == WL_CONNECTED && millis() - t0 < 2000) {
        pollSerialConfig();
        delay(20);
    }
    delay(300);
}

void startWifiScan()
{
    bool restore = WiFi.status() == WL_CONNECTED;
    String restoreSsid = restore ? String(WiFi.SSID()) : String("");
    String restorePass;
    if (restore) {
        if (restoreSsid == wifiSsid) {
            restorePass = wifiPass;
        } else if (restoreSsid == guestSsid) {
            restorePass = guestPass;
        } else {
            restore = false;
        }
    }
    wifiUi = PAPERMONO_WIFI_SCAN;
    wifiScanCount = 0;
    wifiScanBusy = true;
    offConfirm = false;
    drawScreen(false);
    wifiAbortJoin();
    wifiScanCount = 0;
    wifiCollectScanHits(wifiScanRadio());
    if (wifiScanCount == 0) {
        delay(400);
        wifiCollectScanHits(wifiScanRadio());
    }
    wifiScanBusy = false;
    if (restore && restoreSsid.length()) {
        wifiBegin(restoreSsid, restorePass);
    }
    drawScreen(false);
}

void saveGuestWifi()
{
    guestSsid = wifiPickSsid;
    guestPass = wifiDraft;
    saveConfig();
    wifiUi = PAPERMONO_WIFI_IDLE;
    wifiDraft = "";
    wifiPickSsid = "";
    kbNumbers = false;
    kbShift = false;
    if (WiFi.status() != WL_CONNECTED) {
        wifiStartGuest();
    }
}

void clearGuestWifi()
{
    bool onGuest = guestSsid.length() && WiFi.SSID() == guestSsid;
    guestSsid = "";
    guestPass = "";
    saveConfig();
    wifiUi = PAPERMONO_WIFI_IDLE;
    if (onGuest) {
        wifiStartHome();
    }
}

void waitEpdReady()
{
    while (M5.Display.displayBusy()) {
        pollSerialConfig();
        if (applyPendingPages()) {
            redrawQueued = true;
        }
        delay(5);
    }
}

void beginEpdFrame(bool forceQuality)
{
    while (M5.Display.displayBusy()) {
        pollSerialConfig();
        delay(5);
    }
    bool full = forceQuality || partialRefreshCount >= 10;
    M5.Display.setEpdMode(full ? epd_mode_t::epd_quality : epd_mode_t::epd_fastest);
    partialRefreshCount = full ? 0 : (partialRefreshCount + 1);
    M5.Display.startWrite();
}

void finishEpdFrame()
{
    M5.Display.endWrite();
    M5.Display.display();
    /* displayBusy() can stay false for a few ms after display(). Starting
     * another refresh in that window overlaps waveforms (mottled/dark panel). */
    uint32_t t0 = millis();
    while (!M5.Display.displayBusy() && (millis() - t0) < 80) {
        delay(1);
    }
}

String pageName(int page)
{
    if (page == PAPERMONO_PAGE_STATUS) return "STATUS";
    if (page == PAPERMONO_PAGE_HEALTH) return "HEALTH";
    if (page == PAPERMONO_PAGE_PLANS) return "PLANS";
    if (page == PAPERMONO_PAGE_NOTE) return "NOTE";
    if (page == PAPERMONO_PAGE_BOARD) return "BOARD";
    if (page == PAPERMONO_PAGE_POWERWALL) return "POWERWALL";
    if (page == PAPERMONO_PAGE_LYMOW) return "LYMOW";
    if (page == PAPERMONO_PAGE_RADIO) return "MAIL";
    if (page == PAPERMONO_PAGE_DEVICE) return "DEVICE";
    if (page == PAPERMONO_PAGE_HOUSE) return "HOUSE";
    return "HOME";
}

String screenKey()
{
    String key = String(currentPage) + "|" + String(battery) + "|" + charging + "|" + state + "|" + head + "|"
        + errorLabel + "|" + heading + "|" + rainLabel + "|" + connectionType + "|" + connectionStatus + "|"
        + wifiNetwork + "|" + wifiSignal + "|" + batteryTemp + "|" + wirelessCharge + "|" + rtkStatus + "|"
        + planActivity + "|" + String(planCount) + "|" + String(selectedPlan) + "|" + String(planOffset) + "|"
        + lastError + "|" + (lightsOn ? "1" : "0") + "|" + robotName + "|" + vestaboardLive + "|"
        + (vestaboardOn ? "1" : "0") + "|" + (yarboOn ? "1" : "0") + "|" + (powerwallOn ? "1" : "0") + "|"
        + (lymowOn ? "1" : "0") + "|" + lymowName + "|" + String(lymowBattery) + "|" + lymowState + "|"
        + String(powerwallPct) + "|" + powerwallSolar + "|" + powerwallLoad + "|"
        + String((int) WiFi.status()) + "|" + logoHash + "|" + String(screenLocked ? 1 : 0) + "|"
        + lockScreen + "|" + clockLocal + "|" + String(unreadCount) + "|" + vestaboardHash + "|"
        + deviceName + "|" + String(tabletBat) + "|" + String(offConfirm ? 1 : 0) + "|"
        + radioDraft + "|" + String(radioToIndex) + "|" + String(kbNumbers ? 1 : 0) + "|"
        + String(kbShift ? 1 : 0) + "|" + String(wifiUi) + "|" + guestSsid + "|" + wifiPickSsid + "|"
        + wifiDraft + "|" + String(wifiScanCount) + "|" + String(wifiScanBusy ? 1 : 0) + "|"
        + WiFi.SSID() + "|"
        + String(inboxCount) + "|" + String(radioUi) + "|" + String(radioViewIndex) + "|"
        + String(homeOn ? 1 : 0) + "|" + String(homeCount)
        + "|" + String(menuOpen ? 1 : 0) + "|" + String(lastYarboPage)
        + "|" + String(tabletCharging ? 1 : 0)
        + "|" + String(usingRemote ? 1 : 0);
    for (int i = 0; i < PAPERMONO_PAGE_COUNT; i++) {
        key += "|" + menuCustom[i] + "|" + String(menuShow[i] ? 1 : 0);
    }
    for (int i = 0; i < menuOrderN; i++) {
        key += "|" + String(menuOrderPage[i]);
    }
    return key;
}

bool isYarboPage(int page)
{
    return page == PAPERMONO_PAGE_STATUS || page == PAPERMONO_PAGE_HEALTH || page == PAPERMONO_PAGE_PLANS;
}

bool pageEnabled(int page)
{
    if (page == PAPERMONO_PAGE_BOARD) {
        return false;
    }
    if (page == PAPERMONO_PAGE_HOME) {
        return true;
    }
    if (page < 0 || page >= PAPERMONO_PAGE_COUNT) {
        return false;
    }
    if (isYarboPage(page) && !yarboOn) {
        return false;
    }
    if (page == PAPERMONO_PAGE_POWERWALL && !powerwallOn) {
        return false;
    }
    if (page == PAPERMONO_PAGE_LYMOW && !lymowOn) {
        return false;
    }
    if (page == PAPERMONO_PAGE_HOUSE && !homeOn) {
        return false;
    }
    return menuShow[page];
}

int firstYarboPage()
{
    if (pageEnabled(PAPERMONO_PAGE_STATUS)) return PAPERMONO_PAGE_STATUS;
    if (pageEnabled(PAPERMONO_PAGE_HEALTH)) return PAPERMONO_PAGE_HEALTH;
    if (pageEnabled(PAPERMONO_PAGE_PLANS)) return PAPERMONO_PAGE_PLANS;
    return PAPERMONO_PAGE_STATUS;
}

int stepYarboPage(int from, int dir)
{
    static const int kYarbo[] = {
        PAPERMONO_PAGE_STATUS, PAPERMONO_PAGE_HEALTH, PAPERMONO_PAGE_PLANS
    };
    int idx = 0;
    for (int i = 0; i < 3; i++) {
        if (kYarbo[i] == from) {
            idx = i;
            break;
        }
    }
    for (int n = 0; n < 3; n++) {
        idx = (idx + dir + 3) % 3;
        if (pageEnabled(kYarbo[idx])) {
            return kYarbo[idx];
        }
    }
    return from;
}

int visiblePageCount()
{
    int n = 0;
    for (int i = 0; i < PAPERMONO_PAGE_COUNT; i++) {
        if (pageEnabled(i)) n++;
    }
    return n > 0 ? n : 1;
}

int firstEnabledPage()
{
    for (int i = 0; i < PAPERMONO_PAGE_COUNT; i++) {
        if (pageEnabled(i)) return i;
    }
    return PAPERMONO_PAGE_HOME;
}

int pageFromUnlockId(const String &id)
{
    if (id == "yarbo" || id == "status") return PAPERMONO_PAGE_STATUS;
    if (id == "health") return PAPERMONO_PAGE_HEALTH;
    if (id == "plans") return PAPERMONO_PAGE_PLANS;
    if (id == "note") return PAPERMONO_PAGE_NOTE;
    if (id == "board") return PAPERMONO_PAGE_BOARD;
    if (id == "powerwall") return PAPERMONO_PAGE_POWERWALL;
    if (id == "lymow") return PAPERMONO_PAGE_LYMOW;
    if (id == "radio") return PAPERMONO_PAGE_RADIO;
    if (id == "device") return PAPERMONO_PAGE_DEVICE;
    if (id == "house") return PAPERMONO_PAGE_HOUSE;
    return PAPERMONO_PAGE_HOME;
}

void applyUnlockPage()
{
    currentPage = pageFromUnlockId(unlockPage);
    if (!pageEnabled(currentPage)) {
        currentPage = firstEnabledPage();
    }
}

int stepEnabledPage(int from, int dir)
{
    if (isYarboPage(from)) {
        return stepYarboPage(from, dir);
    }
    int count = PAPERMONO_PAGE_COUNT;
    int p = from;
    for (int i = 0; i < count; i++) {
        p = (p + dir + count) % count;
        if (p == PAPERMONO_PAGE_HEALTH || p == PAPERMONO_PAGE_PLANS) {
            continue;
        }
        if (pageEnabled(p)) return p;
    }
    return firstEnabledPage();
}

bool noteChoiceEnabled(int i)
{
    if (i == 1) return powerwallOn;
    if (i == 2) return lymowOn;
    if (i == 3) return ((yarboOn ? 1 : 0) + (powerwallOn ? 1 : 0) + (lymowOn ? 1 : 0)) >= 2;
    return yarboOn;
}

int noteChoiceCount()
{
    int n = 0;
    for (int i = 0; i < 4; i++) {
        if (noteChoiceEnabled(i)) n++;
    }
    return n;
}

int noteChoiceRaw(int shown)
{
    int seen = 0;
    for (int i = 0; i < 4; i++) {
        if (!noteChoiceEnabled(i)) continue;
        if (seen == shown) return i;
        seen++;
    }
    return 0;
}

const char *noteChoiceId(int i)
{
    int raw = noteChoiceRaw(i);
    if (raw == 1) return "powerwall";
    if (raw == 2) return "lymow";
    if (raw == 3) return "batteries";
    return "yarbo";
}

const char *noteChoiceLabel(int i)
{
    int raw = noteChoiceRaw(i);
    if (raw == 1) return "POWER";
    if (raw == 2) return "LYMOW";
    if (raw == 3) return "ALL";
    return "YARBO";
}

String clipLabelToWidth(const String &text, int maxPx)
{
    if (maxPx <= 0 || M5.Display.textWidth(text) <= maxPx) {
        return text;
    }
    String s = text;
    while (s.length() > 0 && M5.Display.textWidth(s) > maxPx) {
        s.remove(s.length() - 1);
    }
    return s;
}

void wrapTwoLines(const String &text, int maxPx, String &line1, String &line2)
{
    line1 = text;
    line2 = "";
    if (maxPx <= 0 || M5.Display.textWidth(text) <= maxPx) {
        return;
    }
    int best = -1;
    for (int i = 0; i < (int) text.length(); i++) {
        if (text[i] != ' ') {
            continue;
        }
        String left = text.substring(0, i);
        if (M5.Display.textWidth(left) <= maxPx) {
            best = i;
        }
    }
    if (best > 0) {
        line1 = clipLabelToWidth(text.substring(0, best), maxPx);
        line2 = clipLabelToWidth(text.substring(best + 1), maxPx);
        line2.trim();
        return;
    }
    int lo = 1;
    int hi = (int) text.length();
    while (lo < hi) {
        int mid = (lo + hi + 1) / 2;
        if (M5.Display.textWidth(text.substring(0, mid)) <= maxPx) {
            lo = mid;
        } else {
            hi = mid - 1;
        }
    }
    line1 = clipLabelToWidth(text.substring(0, lo), maxPx);
    line2 = clipLabelToWidth(text.substring(lo), maxPx);
}

void drawFittedLabel(int cx, int cy, int maxW, const String &text, uint16_t fg, uint16_t bg)
{
    M5.Display.setTextColor(fg, bg);
    M5.Display.setTextDatum(MC_DATUM);
    int sizes[2] = {3, 2};
    for (int s = 0; s < 2; s++) {
        M5.Display.setTextSize(sizes[s]);
        if (M5.Display.textWidth(text) <= maxW) {
            M5.Display.drawString(text, cx, cy);
            return;
        }
        String line1;
        String line2;
        wrapTwoLines(text, maxW, line1, line2);
        if (line2.length() == 0) {
            M5.Display.drawString(line1, cx, cy);
            return;
        }
        if (M5.Display.textWidth(line1) <= maxW && M5.Display.textWidth(line2) <= maxW) {
            int gap = sizes[s] == 3 ? 8 : 6;
            int half = sizes[s] == 3 ? 16 : 12;
            M5.Display.drawString(line1, cx, cy - half - gap / 2);
            M5.Display.drawString(line2, cx, cy + half + gap / 2);
            return;
        }
    }
    M5.Display.setTextSize(2);
    M5.Display.drawString(clipLabelToWidth(text, maxW), cx, cy);
}

void drawDoorGlyph(int x, int y, int h, uint16_t fg)
{
    int w = (h * 5) / 8;
    if (w < 12) {
        w = 12;
    }
    M5.Display.drawRect(x, y, w, h, fg);
    M5.Display.drawRect(x + 1, y + 1, w - 2, h - 2, fg);
    int knob = h / 10;
    if (knob < 2) {
        knob = 2;
    }
    M5.Display.fillCircle(x + w - 3 - knob, y + h / 2, knob, fg);
}

void drawButton(int x, int y, int w, int h, const String &label, bool invert)
{
    uint16_t bg = invert ? TFT_BLACK : TFT_WHITE;
    uint16_t fg = invert ? TFT_WHITE : TFT_BLACK;
    M5.Display.fillRoundRect(x, y, w, h, 12, bg);
    M5.Display.drawRoundRect(x, y, w, h, 12, TFT_BLACK);
    drawFittedLabel(x + w / 2, y + h / 2, w - 24, label, fg, bg);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
}

void layoutButtons(int &bw, int &bh, int &gap, int &y0)
{
    int W = M5.Display.width();
    int H = M5.Display.height();
    gap = 12;
    bw = (W - 36) / 2;
    bh = 88;
    y0 = H - (bh * 2) - gap - 36;
}

String headerBrand()
{
    if (currentPage == PAPERMONO_PAGE_HOME) {
        return "YARBO";
    }
    if (isYarboPage(currentPage)) {
        return menuLabel(PAPERMONO_PAGE_STATUS);
    }
    if (currentPage == PAPERMONO_PAGE_BOARD) {
        return menuLabel(PAPERMONO_PAGE_NOTE);
    }
    return menuLabel(currentPage);
}

void drawHeader()
{
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    int W = M5.Display.width();
    int lockX = W - 88;
    M5.Display.setTextSize(3);
    String brand = menuOpen ? String("MENU") : headerBrand();
    M5.Display.drawString(clipLabelToWidth(brand, lockX - 28), 16, 12);
    if (!menuOpen) {
        int chipX = 16;
        int chipY = 50;
        int chipW = 120;
        int chipH = 42;
        M5.Display.drawRoundRect(chipX, chipY, chipW, chipH, 10, TFT_BLACK);
        M5.Display.setTextDatum(MC_DATUM);
        M5.Display.setTextSize(2);
        M5.Display.drawString("MENU", chipX + chipW / 2, chipY + chipH / 2);
        if (unreadCount > 0) {
            int cx = chipX + chipW - 8;
            int cy = chipY + 8;
            M5.Display.fillCircle(cx, cy, 12, TFT_WHITE);
            M5.Display.fillCircle(cx, cy, 9, TFT_BLACK);
        }
        String page = pageName(currentPage);
        bool showPageName = isYarboPage(currentPage) || currentPage == PAPERMONO_PAGE_HOME;
        if (showPageName && page != brand) {
            M5.Display.setTextDatum(ML_DATUM);
            M5.Display.setTextSize(2);
            M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
            M5.Display.drawString(page, chipX + chipW + 12, chipY + chipH / 2);
        }
        M5.Display.setTextDatum(TL_DATUM);
    }
    drawPadlockIcon(lockX + 8, 10, 70, true);
    M5.Display.drawRoundRect(lockX, 4, 82, 82, 14, TFT_BLACK);
    int batRight = lockX - 8;
    int batCy = 22;
    drawBatteryBadge(batRight, batCy, tabletBat, true);
    int batLeft = batRight - 92 - 10;
    int batCx = batLeft + 46;
    drawWifiIcon(batCx, 64, 32, WiFi.status() == WL_CONNECTED);
    if (usingRemote) {
        M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
        M5.Display.setTextDatum(MC_DATUM);
        M5.Display.setTextSize(2);
        M5.Display.drawString("R", batCx - 38, 64);
        M5.Display.setTextDatum(TL_DATUM);
    }
    M5.Display.setTextDatum(TL_DATUM);
}

void drawPager()
{
}

const char *pageKey(int page)
{
    if (page == PAPERMONO_PAGE_STATUS) return "status";
    if (page == PAPERMONO_PAGE_HEALTH) return "health";
    if (page == PAPERMONO_PAGE_PLANS) return "plans";
    if (page == PAPERMONO_PAGE_NOTE) return "note";
    if (page == PAPERMONO_PAGE_BOARD) return "board";
    if (page == PAPERMONO_PAGE_POWERWALL) return "powerwall";
    if (page == PAPERMONO_PAGE_LYMOW) return "lymow";
    if (page == PAPERMONO_PAGE_RADIO) return "radio";
    if (page == PAPERMONO_PAGE_DEVICE) return "device";
    if (page == PAPERMONO_PAGE_HOUSE) return "house";
    return "home";
}

String menuLabelDefault(int page)
{
    if (page == PAPERMONO_PAGE_POWERWALL) return "POWER";
    if (page == PAPERMONO_PAGE_RADIO) return "MAIL";
    if (page == PAPERMONO_PAGE_STATUS) return "YARBO";
    if (page == PAPERMONO_PAGE_HEALTH) return "HEALTH";
    if (page == PAPERMONO_PAGE_PLANS) return "PLANS";
    if (page == PAPERMONO_PAGE_NOTE) return "NOTE";
    if (page == PAPERMONO_PAGE_LYMOW) return "LYMOW";
    if (page == PAPERMONO_PAGE_DEVICE) return "DEVICE";
    if (page == PAPERMONO_PAGE_HOUSE) return "HOUSE";
    return "HOME";
}

String menuLabel(int page)
{
    if (page >= 0 && page < PAPERMONO_PAGE_COUNT && menuCustom[page].length()) {
        return menuCustom[page];
    }
    return menuLabelDefault(page);
}

void applyMenuLabels(JsonVariant labels)
{
    if (!labels.is<JsonObject>()) {
        return;
    }
    for (int i = 0; i < PAPERMONO_PAGE_COUNT; i++) {
        if (i == PAPERMONO_PAGE_BOARD || i == PAPERMONO_PAGE_HEALTH || i == PAPERMONO_PAGE_PLANS) {
            continue;
        }
        const char *raw = "";
        if (i == PAPERMONO_PAGE_STATUS) {
            raw = labels["yarbo"] | "";
            if (raw[0] == 0) {
                raw = labels["status"] | "";
            }
        } else {
            raw = labels[pageKey(i)] | "";
        }
        String s = String(raw);
        s.trim();
        if (s.length() > 20) {
            s = s.substring(0, 20);
        }
        menuCustom[i] = s;
    }
}

void applyMenuVisible(JsonVariant vis)
{
    if (!vis.is<JsonObject>()) {
        return;
    }
    bool yarboSet = false;
    bool yarboOnMenu = true;
    JsonVariant yv = vis["yarbo"];
    if (yv.is<bool>() || yv.is<int>()) {
        yarboSet = true;
        yarboOnMenu = yv.is<bool>() ? yv.as<bool>() : ((int) yv) != 0;
    }
    for (int i = 0; i < PAPERMONO_PAGE_COUNT; i++) {
        if (i == PAPERMONO_PAGE_HOME || i == PAPERMONO_PAGE_BOARD) {
            continue;
        }
        if (isYarboPage(i)) {
            continue;
        }
        JsonVariant v = vis[pageKey(i)];
        if (v.is<bool>()) {
            menuShow[i] = v.as<bool>();
        } else if (v.is<int>()) {
            menuShow[i] = ((int) v) != 0;
        }
    }
    if (!yarboSet) {
        yarboOnMenu = false;
        const char *keys[] = {"status", "health", "plans"};
        bool any = false;
        bool saw = false;
        for (int i = 0; i < 3; i++) {
            JsonVariant v = vis[keys[i]];
            if (v.is<bool>() || v.is<int>()) {
                saw = true;
                bool on = v.is<bool>() ? v.as<bool>() : ((int) v) != 0;
                any = any || on;
            }
        }
        yarboOnMenu = saw ? any : true;
    }
    menuShow[PAPERMONO_PAGE_STATUS] = yarboOnMenu;
    menuShow[PAPERMONO_PAGE_HEALTH] = yarboOnMenu;
    menuShow[PAPERMONO_PAGE_PLANS] = yarboOnMenu;
    menuShow[PAPERMONO_PAGE_BOARD] = false;
    menuShow[PAPERMONO_PAGE_HOME] = true;
}

void applyMenuOrder(JsonVariant order)
{
    static const int kDefault[] = {
        PAPERMONO_PAGE_STATUS, PAPERMONO_PAGE_NOTE, PAPERMONO_PAGE_POWERWALL,
        PAPERMONO_PAGE_LYMOW, PAPERMONO_PAGE_RADIO, PAPERMONO_PAGE_DEVICE, PAPERMONO_PAGE_HOUSE
    };
    bool used[PAPERMONO_PAGE_COUNT] = {};
    int n = 0;
    if (order.is<JsonArray>()) {
        for (JsonVariant item : order.as<JsonArray>()) {
            String id = String((const char *) (item | ""));
            int page = pageFromUnlockId(id);
            if (id == "yarbo") {
                page = PAPERMONO_PAGE_STATUS;
            }
            if (page == PAPERMONO_PAGE_HOME || page == PAPERMONO_PAGE_BOARD
                || page == PAPERMONO_PAGE_HEALTH || page == PAPERMONO_PAGE_PLANS) {
                continue;
            }
            if (used[page] || n >= PAPERMONO_MENU_MAX) {
                continue;
            }
            used[page] = true;
            menuOrderPage[n++] = page;
        }
    }
    for (int i = 0; i < 7; i++) {
        int page = kDefault[i];
        if (used[page] || n >= PAPERMONO_MENU_MAX) {
            continue;
        }
        used[page] = true;
        menuOrderPage[n++] = page;
    }
    menuOrderN = n > 0 ? n : 7;
}

bool yarboMenuEnabled()
{
    return pageEnabled(PAPERMONO_PAGE_STATUS) || pageEnabled(PAPERMONO_PAGE_HEALTH) || pageEnabled(PAPERMONO_PAGE_PLANS);
}

void rebuildMenuLayout()
{
    int pages[PAPERMONO_MENU_MAX];
    int n = 0;
    for (int i = 0; i < menuOrderN && n < PAPERMONO_MENU_MAX; i++) {
        int page = menuOrderPage[i];
        if (page == PAPERMONO_PAGE_STATUS) {
            if (yarboMenuEnabled()) {
                pages[n++] = PAPERMONO_PAGE_STATUS;
            }
            continue;
        }
        if (pageEnabled(page)) {
            pages[n++] = page;
        }
    }
    int cols = 2;
    int rows = 1;
    int bw = 208;
    int bh = 100;
    int gap = 10;
    int x0 = 16;
    int y0 = 108;
    layoutTileGrid(n > 0 ? n : 1, cols, rows, bw, bh, gap, x0, y0);
    menuLaidW = bw;
    menuLaidH = bh;
    menuLaidN = 0;
    for (int i = 0; i < n; i++) {
        int col = i % cols;
        int row = i / cols;
        menuLaidPage[menuLaidN] = pages[i];
        menuLaidX[menuLaidN] = x0 + col * (bw + gap);
        menuLaidY[menuLaidN] = y0 + row * (bh + gap);
        menuLaidN++;
    }
}

void layoutTileGrid(int n, int &cols, int &rows, int &bw, int &bh, int &gap, int &x0, int &y0)
{
    int W = M5.Display.width();
    int H = M5.Display.height();
    cols = 2;
    if (n < 1) {
        n = 1;
    }
    rows = (n + cols - 1) / cols;
    if (rows < 1) {
        rows = 1;
    }
    gap = 10;
    x0 = 16;
    y0 = 108;
    int y1 = H - 20;
    bw = (W - x0 * 2 - gap) / cols;
    bh = (y1 - y0 - gap * (rows - 1)) / rows;
    if (bh > 112) {
        bh = 112;
    }
    if (bh < 70) {
        bh = 70;
    }
}

void drawNotifyBlob(int bx, int by, int bw, bool invert)
{
    int r = 11;
    int cx = bx + bw - 20;
    int cy = by + 20;
    uint16_t fill = invert ? TFT_WHITE : TFT_BLACK;
    uint16_t halo = invert ? TFT_BLACK : TFT_WHITE;
    M5.Display.fillCircle(cx, cy, r + 3, halo);
    M5.Display.fillCircle(cx, cy, r, fill);
}

void drawMenuPage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    rebuildMenuLayout();
    if (menuLaidN == 0) {
        M5.Display.setTextDatum(TL_DATUM);
        M5.Display.setTextSize(2);
        M5.Display.drawString("Turn on pages in", 16, 180);
        M5.Display.drawString("Settings → E-paper.", 16, 214);
    }
    int highlight = currentPage;
    if (highlight == PAPERMONO_PAGE_BOARD) {
        highlight = PAPERMONO_PAGE_NOTE;
    }
    if (isYarboPage(highlight)) {
        highlight = PAPERMONO_PAGE_STATUS;
    }
    for (int i = 0; i < menuLaidN; i++) {
        int page = menuLaidPage[i];
        int x = menuLaidX[i];
        int y = menuLaidY[i];
        int w = menuLaidW;
        int h = menuLaidH;
        bool mailNotify = page == PAPERMONO_PAGE_RADIO && unreadCount > 0;
        bool invert = mailNotify || page == highlight;
        drawButton(x, y, w, h, menuLabel(page), invert);
        if (mailNotify) {
            drawNotifyBlob(x, y, w, invert);
        }
    }
    finishEpdFrame();
}

void openMenu()
{
    menuOpen = true;
    offConfirm = false;
    wifiUi = PAPERMONO_WIFI_IDLE;
    noteActivity();
    drawScreen(false);
}

void handleMenuTouch(int x, int y)
{
    rebuildMenuLayout();
    for (int i = 0; i < menuLaidN; i++) {
        int bx = menuLaidX[i];
        int by = menuLaidY[i];
        int bw = menuLaidW;
        int bh = menuLaidH;
        if (x >= bx && x <= bx + bw && y >= by && y <= by + bh) {
            int page = menuLaidPage[i];
            menuOpen = false;
            if (page == PAPERMONO_PAGE_STATUS) {
                page = (isYarboPage(lastYarboPage) && pageEnabled(lastYarboPage))
                    ? lastYarboPage
                    : firstYarboPage();
            }
            if (page == PAPERMONO_PAGE_RADIO) {
                radioUi = PAPERMONO_RADIO_INBOX;
                radioViewIndex = -1;
            }
            showPage(page, true);
            return;
        }
    }
}

bool tapOnMenuChip(int x, int y)
{
    if (menuOpen || screenLocked) {
        return false;
    }
    return x >= 8 && x <= 150 && y >= 40 && y <= 104;
}

void drawKv(const char *label, const String &value, int y)
{
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(3);
    String left = String(label);
    while (left.length() < 11) {
        left += " ";
    }
    String shown = value.length() ? value : String("—");
    if (shown.length() > 16) {
        shown = shown.substring(0, 16);
    }
    M5.Display.drawString(left + shown, 16, y);
}

void drawHome(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();

    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(5);
    String bat = battery >= 0 ? (String(battery) + "%") : String("--");
    M5.Display.drawString(bat, 16, 110);

    M5.Display.setTextSize(3);
    M5.Display.drawString("Charging  " + charging, 16, 210);
    M5.Display.drawString("State     " + state, 16, 258);
    M5.Display.drawString("Head      " + head, 16, 306);
    M5.Display.drawString("Error     " + String(errorCode), 16, 354);
    if (lastError.length()) {
        M5.Display.setTextSize(2);
        M5.Display.drawString(lastError.substring(0, 28), 16, 400);
    }

    int bw, bh, gap, y0;
    layoutButtons(bw, bh, gap, y0);
    drawButton(16, y0, bw, bh, "STOP", true);
    drawButton(16 + bw + gap, y0, bw, bh, "DOCK", false);
    drawButton(16, y0 + bh + gap, bw, bh, state == "active" ? "PAUSE" : "RESUME", false);
    drawButton(16 + bw + gap, y0 + bh + gap, bw, bh, lightsOn ? "LIGHTS OFF" : "LIGHTS", false);
    drawPager();
    finishEpdFrame();
}

void drawStatusPage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(5);
    String bat = battery >= 0 ? (String(battery) + "%") : String("--");
    M5.Display.drawString(bat, 16, 108);
    drawKv("State", state, 220);
    drawKv("Charging", charging, 260);
    drawKv("Heading", heading, 300);
    drawKv("Head", head, 340);
    drawKv("Error", errorLabel, 380);
    drawKv("Rain", rainLabel, 420);
    if (lastError.length()) {
        M5.Display.setTextSize(2);
        M5.Display.drawString(lastError.substring(0, 28), 16, 468);
    }
    drawPager();
    finishEpdFrame();
}

void drawHealthPage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    int y = 112;
    const int step = 48;
    drawKv("Conn type", connectionType, y); y += step;
    drawKv("Conn stat", connectionStatus, y); y += step;
    drawKv("WiFi", wifiNetwork, y); y += step;
    drawKv("Signal", wifiSignal, y); y += step;
    drawKv("Security", wifiSecurity, y); y += step;
    drawKv("Batt temp", batteryTemp, y); y += step;
    drawKv("Pad", wirelessCharge, y); y += step;
    drawKv("RTK", rtkStatus, y); y += step;
    drawKv("RTCM age", rtcmAge, y); y += step;
    drawKv("Route", routePriority, y); y += step;
    drawKv("Rain sns", rainSensor, y); y += step;
    drawKv("Net mod", netModule, y);
    drawPager();
    finishEpdFrame();
}

int plansRowY0()
{
    return 150;
}

int plansRowH()
{
    return 52;
}

int plansStartY()
{
    return plansRowY0() + PAPERMONO_PLAN_VISIBLE * plansRowH() + 16;
}

int houseItemCount()
{
    int shown = homeCount < PAPERMONO_HOME_VISIBLE ? homeCount : PAPERMONO_HOME_VISIBLE;
    return shown < 0 ? 0 : shown;
}

void layoutHouse(int &cols, int &rows, int &bw, int &bh, int &gap, int &x0, int &y0)
{
    int n = houseItemCount();
    if (n < 1) {
        n = 1;
    }
    layoutTileGrid(n, cols, rows, bw, bh, gap, x0, y0);
}

void houseButtonRect(int idx, int &x, int &y, int &w, int &h)
{
    int cols, rows, bw, bh, gap, x0, y0;
    layoutHouse(cols, rows, bw, bh, gap, x0, y0);
    int col = idx % cols;
    int row = idx / cols;
    x = x0 + col * (bw + gap);
    y = y0 + row * (bh + gap);
    w = bw;
    h = bh;
}

void drawHousePage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    int shown = houseItemCount();
    if (shown == 0) {
        M5.Display.setTextSize(2);
        M5.Display.drawString("Assign lights in", 16, 180);
        M5.Display.drawString("the panel Home page.", 16, 214);
    }
    for (int i = 0; i < shown; i++) {
        int x, y, w, h;
        houseButtonRect(i, x, y, w, h);
        String label = homeNames[i];
        bool door = homeKinds[i] == "door" || homeKinds[i] == "hub";
        if (homeKinds[i] == "scene") {
            label = "*" + label;
        }
        uint16_t bg = homeOnState[i] ? TFT_BLACK : TFT_WHITE;
        uint16_t fg = homeOnState[i] ? TFT_WHITE : TFT_BLACK;
        M5.Display.fillRoundRect(x, y, w, h, 12, bg);
        M5.Display.drawRoundRect(x, y, w, h, 12, TFT_BLACK);
        int padL = door ? 40 : 12;
        drawFittedLabel(x + padL + (w - padL) / 2, y + h / 2, w - padL - 12, label, fg, bg);
        if (door) {
            int glyphH = h > 48 ? 28 : h - 20;
            if (glyphH < 16) {
                glyphH = 16;
            }
            drawDoorGlyph(x + 10, y + (h - glyphH) / 2, glyphH, fg);
        }
        M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
        M5.Display.setTextDatum(TL_DATUM);
    }
    drawPager();
    finishEpdFrame();
}

void drawPlansPage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(2);
    M5.Display.drawString(planActivity.length() ? planActivity : "idle", 16, 108);
    if (plansNote.length() && planCount == 0) {
        M5.Display.setTextSize(2);
        M5.Display.drawString(plansNote.substring(0, 22), 16, 180);
        if (plansNote.length() > 22) {
            M5.Display.drawString(plansNote.substring(22, 44), 16, 214);
        }
    }

    int y0 = plansRowY0();
    int rh = plansRowH();
    int W = M5.Display.width();
    for (int i = 0; i < PAPERMONO_PLAN_VISIBLE; i++) {
        int idx = planOffset + i;
        if (idx >= planCount) {
            break;
        }
        bool sel = idx == selectedPlan;
        int y = y0 + i * rh;
        uint16_t bg = sel ? TFT_BLACK : TFT_WHITE;
        uint16_t fg = sel ? TFT_WHITE : TFT_BLACK;
        M5.Display.fillRoundRect(16, y, W - 32, rh - 8, 10, bg);
        M5.Display.drawRoundRect(16, y, W - 32, rh - 8, 10, TFT_BLACK);
        M5.Display.setTextColor(fg, bg);
        M5.Display.setTextDatum(ML_DATUM);
        M5.Display.setTextSize(2);
        String label = planNames[idx];
        if (label.length() > 18) {
            label = label.substring(0, 18);
        }
        M5.Display.drawString(label, 32, y + (rh - 8) / 2);
    }

    int bw, bh, gap, ignoreY;
    layoutButtons(bw, bh, gap, ignoreY);
    int startY = plansStartY();
    bool canStart = selectedPlan >= 0 && selectedPlan < planCount;
    drawButton(16, startY, bw, 72, "START", canStart);
    if (planCount > PAPERMONO_PLAN_VISIBLE) {
        drawButton(16 + bw + gap, startY, bw, 72, "MORE", false);
    }
    drawPager();
    finishEpdFrame();
}

void drawNotePage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    int W = M5.Display.width();
    int cell = 26;
    int gap = 2;
    int gridW = 15 * cell + 14 * gap;
    int gridH = 3 * cell + 2 * gap;
    int gx = (W - gridW) / 2;
    int gy = 108;
    drawVestaboardGrid(gx, gy, cell, gap);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TC_DATUM);
    M5.Display.setTextSize(2);
    String live = vestaboardLive.length() ? vestaboardLive : String("yarbo");
    live.toUpperCase();
    M5.Display.drawString(live, W / 2, gy + gridH + 16);
    M5.Display.setTextDatum(TL_DATUM);
    if (!vestaboardOn) {
        M5.Display.setTextSize(2);
        M5.Display.drawString("Note off: lock screen still previews.", 16, gy + gridH + 42);
    }
    if (lastError.length()) {
        M5.Display.setTextSize(2);
        M5.Display.drawString(lastError.substring(0, 28), 16, gy + gridH + (vestaboardOn ? 42 : 70));
    }
    int bw, bh, btnGap, y0;
    layoutButtons(bw, bh, btnGap, y0);
    int n = noteChoiceCount();
    for (int i = 0; i < n; i++) {
        bool left = (i % 2) == 0;
        int row = i / 2;
        int x = left ? 16 : 16 + bw + btnGap;
        int y = y0 + row * (bh + btnGap);
        drawButton(x, y, bw, bh, noteChoiceLabel(i), vestaboardLive == noteChoiceId(i));
    }
    drawPager();
    finishEpdFrame();
}

void drawLymowPage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(5);
    String bat = lymowBattery >= 0 ? (String(lymowBattery) + "%") : String("--");
    M5.Display.drawString(bat, 16, 108);
    drawKv("State", lymowState, 220);
    drawKv("Charging", lymowCharging, 260);
    if (lastError.length()) {
        M5.Display.setTextSize(2);
        M5.Display.drawString(lastError.substring(0, 28), 16, 320);
    }
    drawPager();
    finishEpdFrame();
}

void drawPowerwallPage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(5);
    String bat = powerwallPct >= 0 ? (String(powerwallPct) + "%") : String("--");
    M5.Display.drawString(bat, 16, 108);
    drawKv("Solar", powerwallSolar, 220);
    drawKv("Draw", powerwallLoad, 260);
    if (lastError.length()) {
        M5.Display.setTextSize(2);
        M5.Display.drawString(lastError.substring(0, 28), 16, 320);
    }
    drawPager();
    finishEpdFrame();
}

char vestaboardGlyph(int code)
{
    if (code >= 1 && code <= 26) return (char) (64 + code);
    if (code >= 27 && code <= 35) return (char) (code + 22);
    if (code == 36) return '0';
    if (code == 37) return '!';
    if (code == 38) return '@';
    if (code == 39) return '#';
    if (code == 40) return '$';
    if (code == 41) return '(';
    if (code == 42) return ')';
    if (code == 44) return '-';
    if (code == 46) return '+';
    if (code == 47) return '&';
    if (code == 48) return '=';
    if (code == 49) return ';';
    if (code == 50) return ':';
    if (code == 52) return '\'';
    if (code == 53) return '"';
    if (code == 54) return '%';
    if (code == 55) return ',';
    if (code == 56) return '.';
    if (code == 59) return '/';
    if (code == 60) return '?';
    return ' ';
}

uint16_t vestaboardFill(int code)
{
    if (code == 63 || code == 64) return TFT_BLACK;
    if (code == 65) return TFT_LIGHTGREY;
    if (code == 66 || code == 67 || code == 68) return TFT_DARKGREY;
    return TFT_WHITE;
}

void drawVestaboardGrid(int x, int y, int cell, int gap)
{
    M5.Display.setTextDatum(MC_DATUM);
    int ts = cell >= 24 ? 3 : (cell >= 16 ? 2 : 1);
    M5.Display.setTextSize(ts);
    for (int r = 0; r < 3; r++) {
        for (int c = 0; c < 15; c++) {
            int code = vestaboardCodes[r][c];
            int cx = x + c * (cell + gap);
            int cy = y + r * (cell + gap);
            uint16_t fill = vestaboardFill(code);
            M5.Display.fillRect(cx, cy, cell, cell, fill);
            M5.Display.drawRect(cx, cy, cell, cell, TFT_BLACK);
            char glyph = vestaboardGlyph(code);
            if (glyph != ' ' && code < 63) {
                M5.Display.setTextColor(TFT_BLACK, fill);
                String s;
                s += glyph;
                M5.Display.drawString(s, cx + cell / 2, cy + cell / 2);
            }
        }
    }
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
}

bool unreadFrontlightHold()
{
    return unreadCount > 0 && alertMessageOn;
}

int brightnessValue()
{
    int pct = unreadFrontlightHold() ? 100 : brightnessPct;
    return map(constrain(pct, 0, 100), 0, 100, 0, 255);
}

void applyFrontlight(bool on, bool force)
{
    if (unreadFrontlightHold() && !force) {
        on = true;
    }
    lightOn = on;
    paperSetFrontlight(on ? (uint8_t) brightnessValue() : 0);
}

void powerOffTablet()
{
    rgbOff();
    applyFrontlight(false, true);
    M5.Display.waitDisplay();
    M5.Display.setEpdMode(epd_mode_t::epd_quality);
    M5.Display.startWrite();
    M5.Display.fillScreen(TFT_WHITE);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    int W = M5.Display.width();
    int H = M5.Display.height();
    M5.Display.setTextDatum(TC_DATUM);
    M5.Display.setTextSize(3);
    M5.Display.drawString(deviceName.length() ? deviceName : String("PaperMono"), W / 2, 64);
    M5.Display.setTextDatum(MC_DATUM);
    M5.Display.setTextSize(12);
    M5.Display.drawString("OFF", W / 2, H / 2);
    M5.Display.setTextSize(2);
    M5.Display.drawString("TAP RED BUTTON TO BEGIN", W / 2, H - 72);
    M5.Display.endWrite();
    M5.Display.display();
    M5.Display.waitDisplay();
    delay(400);
    M5.Power.powerOff();
}

void paintRadioDraft(int x, int y)
{
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setFont(&fonts::Font2);
    M5.Display.setTextSize(3);
    String draft = radioDraft.length() ? radioDraft : String("Type here");
    if (draft.length() > 14) {
        draft = draft.substring(draft.length() - 14);
    }
    M5.Display.drawString(draft, x, y + 4);
    M5.Display.setTextSize(2);
    M5.Display.drawString(String(radioDraft.length()) + "/" + String(PAPERMONO_MSG_CHARS), x, y + 64);
    M5.Display.setFont(&fonts::Font0);
}

void updateRadioDraft()
{
    const int x = 16;
    const int y = PAPERMONO_DRAFT_Y;
    const int w = M5.Display.width() - 32;
    const int h = PAPERMONO_DRAFT_H;
    M5.Display.waitDisplay();
    if (partialRefreshCount >= 10) {
        drawScreen(false);
        return;
    }
    M5.Display.setEpdMode(epd_mode_t::epd_fastest);
    M5.Display.startWrite();
    M5.Display.fillRect(x, y, w, h, TFT_WHITE);
    paintRadioDraft(x, y);
    M5.Display.endWrite();
    M5.Display.display(x, y, w, h);
    partialRefreshCount++;
}

void ensureNtp()
{
    if (WiFi.status() != WL_CONNECTED) {
        return;
    }
    if (ntpStarted && millis() - lastNtpTry < 3600000UL) {
        return;
    }
    if (clockOffsetSet) {
        configTime(clockOffset, 0, "pool.ntp.org", "time.google.com");
    } else {
        configTime(0, 0, "pool.ntp.org", "time.google.com");
        setenv("TZ", "CET-1CEST,M3.5.0,M10.5.0/3", 1);
        tzset();
    }
    ntpStarted = true;
    lastNtpTry = millis();
}

void refreshLocalClock()
{
    time_t now = time(nullptr);
    if (now < 1700000000) {
        return;
    }
    if (lastPanelClockMs && millis() - lastPanelClockMs < 120000UL) {
        return;
    }
    struct tm t;
    localtime_r(&now, &t);
    char tbuf[8];
    char dbuf[20];
    snprintf(tbuf, sizeof(tbuf), "%02d:%02d", t.tm_hour, t.tm_min);
    strftime(dbuf, sizeof(dbuf), "%a %d %b", &t);
    clockLocal = tbuf;
    clockDate = dbuf;
}

String nowStamp()
{
    if (clockDate.length() && clockLocal.length() && clockLocal != "--:--") {
        return clockDate + "  " + clockLocal;
    }
    if (clockLocal.length() && clockLocal != "--:--") {
        return clockLocal;
    }
    return "";
}

void drawPadlockIcon(int x, int y, int size, bool locked)
{
    int thick = max(5, size / 8);
    int bodyW = (size * 5) / 8;
    int bodyH = (size * 11) / 24;
    int bx = x + (size - bodyW) / 2;
    int by = y + size - bodyH - 1;
    int hoopW = max(bodyW - thick, thick * 3);
    int hoopX = locked ? (bx + (bodyW - hoopW) / 2) : (bx + thick);
    int hoopY = y + 1;
    int hoopH = by - hoopY + thick;
    int rad = hoopW / 2;
    M5.Display.fillRoundRect(hoopX, hoopY, hoopW, hoopH, rad, TFT_BLACK);
    int innerW = hoopW - thick * 2;
    int innerH = hoopH - thick;
    if (innerW > 4 && innerH > 4) {
        int holeH = innerH;
        int maxHole = by - (hoopY + thick);
        if (maxHole < 4) {
            maxHole = 4;
        }
        if (holeH > maxHole) {
            holeH = maxHole;
        }
        M5.Display.fillRoundRect(hoopX + thick, hoopY + thick, innerW, holeH, max(2, rad - thick), TFT_WHITE);
    }
    M5.Display.fillRect(hoopX + thick, by - 1, max(1, hoopW - thick * 2), thick + 1, TFT_WHITE);
    M5.Display.fillRoundRect(bx, by, bodyW, bodyH, max(4, thick / 2), TFT_BLACK);
}

void drawWifiIcon(int cx, int cy, int size, bool connected)
{
    int yDot = cy + size / 4;
    int dot = max(4, size / 8);
    int thick = max(3, size / 10);
    M5.Display.fillCircle(cx, yDot, dot, TFT_BLACK);
    for (int i = 1; i <= 3; i++) {
        int r = (size * i) / 5;
        for (int t = 0; t < thick; t++) {
            if (r - t > dot) {
                M5.Display.drawCircle(cx, yDot, r - t, TFT_BLACK);
            }
        }
    }
    /* Circles are only the Wi-Fi arcs: wipe everything below the hotspot. */
    int maxR = (size * 3) / 5 + thick;
    M5.Display.fillRect(cx - maxR - 4, yDot + 1, maxR * 2 + 8, maxR + 2, TFT_WHITE);
    if (!connected) {
        int x1 = cx - size / 2;
        int y1 = cy - size / 3;
        int x2 = cx + size / 2;
        int y2 = cy + size / 2;
        int bar = max(4, thick + 1);
        for (int t = -bar; t <= bar; t++) {
            M5.Display.drawLine(x1 + t, y1, x2 + t, y2, TFT_BLACK);
            M5.Display.drawLine(x1, y1 + t, x2, y2 + t, TFT_BLACK);
        }
    }
}

void drawChargeBolt(int cx, int cy, int size)
{
    int thick = max(4, size / 8);
    int x0 = cx + size / 5;
    int y0 = cy - size / 2;
    int x1 = cx - size / 4;
    int y1 = cy - size / 18;
    int x2 = cx + size / 3;
    int y2 = y1;
    int x3 = cx - size / 5;
    int y3 = cy + size / 2;
    for (int t = -thick; t <= thick; t++) {
        M5.Display.drawLine(x0 + t, y0, x1 + t, y1, TFT_BLACK);
        M5.Display.drawLine(x0, y0 + t, x1, y1 + t, TFT_BLACK);
        M5.Display.drawLine(x1 + t, y1, x2 + t, y2, TFT_BLACK);
        M5.Display.drawLine(x1, y1 + t, x2, y2 + t, TFT_BLACK);
        M5.Display.drawLine(x2 + t, y2, x3 + t, y3, TFT_BLACK);
        M5.Display.drawLine(x2, y2 + t, x3, y3 + t, TFT_BLACK);
    }
}

void drawBatteryBadge(int right, int cy, int pct, bool compact)
{
    const int w = compact ? 92 : 160;
    const int h = compact ? 36 : 72;
    const int cap = compact ? 10 : 16;
    const int radius = compact ? 8 : 14;
    const int stroke = compact ? 3 : 5;
    const int gap = compact ? 2 : 3;
    int x = right - w - cap;
    int y = cy - h / 2;
    int level = constrain(pct, 0, 100);
    int capH = compact ? 14 : 28;
    int capR = compact ? 2 : 4;

    M5.Display.fillRoundRect(x, y, w, h, radius, TFT_BLACK);
    int ir = max(4, radius - stroke + 2);
    M5.Display.fillRoundRect(
        x + stroke,
        y + stroke,
        w - 2 * stroke,
        h - 2 * stroke,
        ir,
        TFT_WHITE
    );
    M5.Display.fillRect(x + w - stroke, y + (h - capH) / 2, stroke, capH, TFT_BLACK);
    M5.Display.fillRoundRect(x + w - 1, y + (h - capH) / 2, cap + 1, capH, capR, TFT_BLACK);

    int ix = x + stroke + gap;
    int iy = y + stroke + gap;
    int iw = w - 2 * (stroke + gap);
    int ih = h - 2 * (stroke + gap);
    int fillw = pct >= 0 ? (iw * level / 100) : 0;
    if (fillw > 0) {
        M5.Display.fillRect(ix, iy, fillw, ih, TFT_BLACK);
    }

    M5.Display.setTextDatum(MC_DATUM);
    M5.Display.setTextSize(compact ? 2 : 3);
    String s = pct >= 0 ? (String(pct) + "%") : String("--");
    int tw = M5.Display.textWidth(s);
    int th = compact ? 14 : 22;
    int padX = compact ? 3 : 4;
    int padY = compact ? 1 : 2;
    M5.Display.fillRoundRect(
        x + w / 2 - tw / 2 - padX,
        y + h / 2 - th / 2 - padY,
        tw + padX * 2,
        th + padY * 2,
        compact ? 2 : 4,
        TFT_WHITE
    );
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.drawString(s, x + w / 2, y + h / 2);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
}

bool takeTouchPress(int &x, int &y)
{
    static bool held = false;
    m5::touch_point_t pts[2];
    pts[0].x = 0;
    pts[0].y = 0;
    pts[1].x = 0;
    pts[1].y = 0;
    /* PaperMono: getTouchRaw is already in display pixels (official M5 example).
     * getTouch() applies a second affine and throws taps off the drawn buttons. */
    uint8_t n = M5.Display.getTouchRaw(pts, 2);
    bool down = n > 0;
    if (down && !held) {
        x = (int) pts[0].x;
        y = (int) pts[0].y;
        held = true;
        return true;
    }
    if (!down) {
        held = false;
    }
    return false;
}

void noteActivity()
{
    lastActivity = millis();
    lastLight = millis();
    if (!lightOn) {
        applyFrontlight(true);
    }
}

bool pngFileSize(const char *path, int *w, int *h)
{
    File f = SPIFFS.open(path, FILE_READ);
    if (!f || f.size() < 24) {
        if (f) {
            f.close();
        }
        return false;
    }
    uint8_t hdr[24];
    int n = f.read(hdr, 24);
    f.close();
    if (n != 24 || hdr[0] != 0x89 || hdr[1] != 'P' || hdr[2] != 'N' || hdr[3] != 'G') {
        return false;
    }
    int pw = ((int) hdr[16] << 24) | ((int) hdr[17] << 16) | ((int) hdr[18] << 8) | (int) hdr[19];
    int ph = ((int) hdr[20] << 24) | ((int) hdr[21] << 16) | ((int) hdr[22] << 8) | (int) hdr[23];
    if (pw < 8 || pw > 2048 || ph < 8 || ph > 2048) {
        return false;
    }
    if (w) {
        *w = pw;
    }
    if (h) {
        *h = ph;
    }
    return true;
}

int pngFileWidth(const char *path)
{
    int w = PAPERMONO_LOGO_PX;
    int h = 0;
    if (!pngFileSize(path, &w, &h)) {
        return PAPERMONO_LOGO_PX;
    }
    return w;
}

void drawMailBadge(int x, int y, int size, int count)
{
    int stroke = 5;
    M5.Display.fillRoundRect(x, y, size, size, 14, TFT_BLACK);
    M5.Display.fillRoundRect(x + stroke, y + stroke, size - 2 * stroke, size - 2 * stroke, 8, TFT_WHITE);
    int ex = x + stroke + 8;
    int ey = y + stroke + 14;
    int ew = size - 2 * (stroke + 8);
    int eh = size / 2 - 2;
    M5.Display.fillRoundRect(ex, ey, ew, eh, 4, TFT_BLACK);
    int inner = 4;
    M5.Display.fillRoundRect(ex + inner, ey + inner, ew - 2 * inner, eh - 2 * inner, 2, TFT_WHITE);
    int midX = ex + ew / 2;
    int midY = ey + eh / 2;
    int bar = 4;
    for (int t = -bar; t <= bar; t++) {
        M5.Display.drawLine(ex, ey + inner + t, midX, midY + t, TFT_BLACK);
        M5.Display.drawLine(ex + ew - 1, ey + inner + t, midX, midY + t, TFT_BLACK);
    }
    M5.Display.setTextDatum(MC_DATUM);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextSize(2);
    M5.Display.drawString(count > 9 ? String("9+") : String(count), x + size / 2, y + size - stroke - 16);
    M5.Display.setTextDatum(TL_DATUM);
}

void drawLockScreen(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    int W = M5.Display.width();
    int H = M5.Display.height();

    M5.Display.setTextDatum(TC_DATUM);
    M5.Display.setTextSize(4);
    M5.Display.drawString(deviceName.length() ? deviceName : String("PaperMono"), W / 2, 36);
    M5.Display.setTextSize(6);
    M5.Display.drawString(clockLocal.length() ? clockLocal : String("--:--"), W / 2, 110);
    if (unreadCount > 0) {
        drawMailBadge(16, 24, 96, unreadCount);
    }
    const int batCy = 210;
    const int batH = 72;
    int batRight = W / 2 + 90;
    int batLeft = batRight - 160 - 16;
    int wifiCx = batLeft / 2;
    drawWifiIcon(wifiCx, batCy, 56, WiFi.status() == WL_CONNECTED);
    if (usingRemote) {
        M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
        M5.Display.setTextDatum(MC_DATUM);
        M5.Display.setTextSize(3);
        M5.Display.drawString("R", wifiCx, batCy + 44);
        M5.Display.setTextDatum(TL_DATUM);
    }
    drawBatteryBadge(batRight, batCy, tabletBat, false);
    if (tabletCharging) {
        int boltCx = W - wifiCx;
        drawChargeBolt(boltCx, batCy, 56);
    }
    int batBottom = batCy + batH / 2;

    int unlockW = 280;
    int offW = 140;
    int btnH = 110;
    int btnGap = 16;
    int bx = (W - (unlockW + btnGap + offW)) / 2;
    int by = H - 156;
    int contentTop = batBottom + 16;
    int contentBottom = by - 24;
    if (contentBottom < contentTop + 80) {
        contentBottom = contentTop + 80;
    }

    bool wantBoard = lockScreen == "vestaboard" || lockScreen == "both";
    bool wantLogo = lockScreen == "logo" || lockScreen == "both";
    bool haveLogo = SPIFFS.exists("/logo.png");
    bool showBoard = wantBoard && (!vestaboardKnown || vestaboardOn);
    bool showLogo = wantLogo && haveLogo;

    int logoY = contentTop;
    int drawnH = 0;
    if (showLogo) {
        int srcW = PAPERMONO_LOGO_PX;
        int srcH = PAPERMONO_LOGO_PX;
        pngFileSize("/logo.png", &srcW, &srcH);
        if (srcW < 8) {
            srcW = PAPERMONO_LOGO_PX;
        }
        if (srcH < 8) {
            srcH = PAPERMONO_LOGO_PX;
        }
        int minGrid = showBoard ? 56 : 0;
        int gapBoard = showBoard ? 16 : 0;
        int maxW = W - 48;
        int maxH = contentBottom - contentTop - minGrid - gapBoard;
        if (maxH < 90) {
            maxH = 90;
        }
        if (maxH > 240) {
            maxH = 240;
        }
        float sc = (float) maxW / (float) srcW;
        float scH = (float) maxH / (float) srcH;
        if (scH < sc) {
            sc = scH;
        }
        int drawnW = (int) ((float) srcW * sc);
        drawnH = (int) ((float) srcH * sc);
        if (drawnW < 8) {
            drawnW = 8;
        }
        if (drawnH < 8) {
            drawnH = 8;
        }
        int logoX = (W - drawnW) / 2;
        M5.Display.drawPngFile(SPIFFS, "/logo.png", logoX, logoY, drawnW, drawnH, 0, 0, sc, sc);
    } else if (wantLogo && !showBoard) {
        M5.Display.setTextSize(2);
        M5.Display.drawString("Logo after site Wi-Fi", W / 2, logoY + 40);
        drawnH = 72;
    }
    if (showBoard) {
        int gridTop = showLogo ? (logoY + drawnH + 16) : contentTop;
        if (gridTop < contentTop) {
            gridTop = contentTop;
        }
        int gridRoom = contentBottom - gridTop;
        if (gridRoom < 36) {
            gridRoom = 36;
        }
        int gap = 2;
        int cellW = (W - 32 - 14 * gap) / 15;
        int cellH = (gridRoom - 2 * gap) / 3;
        int cell = cellW < cellH ? cellW : cellH;
        if (cell > 30) {
            cell = 30;
        }
        if (cell < 12) {
            cell = 12;
        }
        int gridW = 15 * cell + 14 * gap;
        int gridH = 3 * cell + 2 * gap;
        int gridY = gridTop;
        if (gridY + gridH > contentBottom) {
            gridY = contentBottom - gridH;
        }
        if (gridY < gridTop) {
            gridY = gridTop;
        }
        drawVestaboardGrid((W - gridW) / 2, gridY, cell, gap);
    }

    drawPadlockIcon(bx + 16, by + 18, 74, false);
    M5.Display.drawRoundRect(bx, by, unlockW, btnH, 18, TFT_BLACK);
    M5.Display.setTextDatum(ML_DATUM);
    M5.Display.setTextSize(3);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.drawString("Unlock", bx + 110, by + btnH / 2);
    M5.Display.fillRoundRect(bx + unlockW + btnGap, by, offW, btnH, 18, TFT_BLACK);
    M5.Display.setTextDatum(MC_DATUM);
    M5.Display.setTextColor(TFT_WHITE, TFT_BLACK);
    M5.Display.setTextSize(3);
    M5.Display.drawString("OFF", bx + unlockW + btnGap + offW / 2, by + btnH / 2);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    finishEpdFrame();
}

void enterLock()
{
    screenLocked = true;
    menuOpen = false;
    offConfirm = false;
    wifiUi = PAPERMONO_WIFI_IDLE;
    lastLight = millis();
    applyFrontlight(true);
    drawScreen(false);
}

void exitLock()
{
    screenLocked = false;
    menuOpen = true;
    if (!pageEnabled(currentPage)) {
        currentPage = firstEnabledPage();
    }
    noteActivity();
    applyFrontlight(true);
    if (currentPage == PAPERMONO_PAGE_PLANS && !plansLoaded) {
        httpGetPlans(false);
    }
    drawScreen(false);
}

void drawBoardPage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    int cell = 28;
    int gap = 3;
    int gridW = 15 * cell + 14 * gap;
    drawVestaboardGrid((M5.Display.width() - gridW) / 2, 130, cell, gap);
    M5.Display.setTextDatum(TC_DATUM);
    M5.Display.setTextSize(2);
    M5.Display.drawString("live Vestaboard", M5.Display.width() / 2, 250);
    drawPager();
    finishEpdFrame();
}

const char *kbRow(int row)
{
    if (kbNumbers) {
        if (row == 0) return "1234567890";
        if (row == 1) return "-/:;()$&@\"";
        return ".,?!'#+=";
    }
    if ((wifiUi == PAPERMONO_WIFI_PASS || wifiUi == PAPERMONO_WIFI_SSID) && !kbShift) {
        if (row == 0) return "qwertyuiop";
        if (row == 1) return "asdfghjkl";
        return "zxcvbnm";
    }
    if (row == 0) return "QWERTYUIOP";
    if (row == 1) return "ASDFGHJKL";
    return "ZXCVBNM";
}

void drawKeyboard(int y0, bool wifiKeys)
{
    int W = M5.Display.width();
    M5.Display.setFont(&fonts::Font2);
    for (int r = 0; r < 3; r++) {
        const char *row = kbRow(r);
        int n = strlen(row);
        int keyW = (W - 16) / n;
        int y = y0 + r * PAPERMONO_KB_ROW;
        for (int i = 0; i < n; i++) {
            int x = 8 + i * keyW;
            M5.Display.fillRoundRect(x, y, keyW - 6, PAPERMONO_KB_ROW - 10, 8, TFT_WHITE);
            M5.Display.drawRoundRect(x, y, keyW - 6, PAPERMONO_KB_ROW - 10, 8, TFT_BLACK);
            M5.Display.setTextDatum(MC_DATUM);
            M5.Display.setTextSize(3);
            M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
            String s;
            s += row[i];
            M5.Display.drawString(s, x + (keyW - 6) / 2, y + (PAPERMONO_KB_ROW - 10) / 2);
        }
    }
    M5.Display.setFont(&fonts::Font0);
    int y = y0 + 3 * PAPERMONO_KB_ROW;
    int ah = PAPERMONO_KB_ACTION;
    if (wifiKeys) {
        drawButton(8, y, 72, ah, kbShift ? "AB" : "ab", kbShift);
        drawButton(86, y, 72, ah, kbNumbers ? "ABC" : "123", false);
        drawButton(164, y, 108, ah, "SPACE", false);
        drawButton(278, y, 80, ah, "DEL", false);
        drawButton(364, y, 108, ah, "SAVE", true);
    } else {
        drawButton(8, y, 100, ah, kbNumbers ? "ABC" : "123", false);
        drawButton(116, y, 160, ah, "SPACE", false);
        drawButton(284, y, 90, ah, "DEL", false);
        drawButton(382, y, 90, ah, "SEND", true);
    }
}

void drawRadioCompose(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(2);
    String path = loraReady() ? "LoRa + Wi-Fi" : "Wi-Fi only";
    M5.Display.drawString(path, 16, 100);
    drawButton(320, 92, 144, 44, "INBOX", false);
    M5.Display.setTextSize(3);
    String toLabel = "ALL";
    if (radioToIndex > 0 && radioToIndex <= peerCount) {
        toLabel = peerNames[radioToIndex - 1];
    }
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.drawString("To  " + toLabel, 16, 126);
    paintRadioDraft(16, PAPERMONO_DRAFT_Y);
    int n = min(4, peerCount + 1);
    int pw = (M5.Display.width() - 24) / n;
    for (int i = 0; i < n; i++) {
        const char *lab = i == 0 ? "ALL" : peerNames[i - 1].c_str();
        bool on = radioToIndex == i;
        drawButton(12 + i * pw, PAPERMONO_PEER_Y, pw - 8, 56, lab, on);
    }
    drawKeyboard(PAPERMONO_KB_Y0, false);
    drawPager();
    finishEpdFrame();
}

void drawWrappedText(const String &text, int x, int y, int maxW, int lineH, int maxLines)
{
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(2);
    int line = 0;
    int start = 0;
    int len = text.length();
    while (start < len && line < maxLines) {
        int end = start;
        int lastSpace = -1;
        while (end < len) {
            int w = M5.Display.textWidth(text.substring(start, end + 1));
            if (w > maxW) {
                break;
            }
            if (text[end] == ' ') {
                lastSpace = end;
            }
            end++;
        }
        if (end == start) {
            end = start + 1;
        } else if (end < len && lastSpace > start) {
            end = lastSpace + 1;
        }
        String piece = text.substring(start, end);
        piece.trim();
        if (line == maxLines - 1 && end < len) {
            if (piece.length() > 3) {
                piece = piece.substring(0, piece.length() - 1) + "...";
            }
        }
        M5.Display.drawString(piece, x, y + line * lineH);
        start = end;
        line++;
    }
}

void drawRadioInbox(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    drawButton(320, 92, 144, 44, "WRITE", true);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(2);
    String title = unreadCount > 0 ? (String(unreadCount) + " new") : String("Inbox");
    M5.Display.drawString(title, 16, PAPERMONO_INBOX_Y0 - 8);
    if (inboxCount == 0) {
        M5.Display.setTextSize(3);
        M5.Display.drawString("No messages yet.", 16, 200);
        M5.Display.setTextSize(2);
        M5.Display.drawString("Tap WRITE to send one.", 16, 260);
    } else {
        int shown = min(inboxCount, 6);
        for (int i = 0; i < shown; i++) {
            int idx = inboxCount - 1 - i;
            int y = PAPERMONO_INBOX_Y0 + 28 + i * PAPERMONO_INBOX_ROW;
            M5.Display.drawRoundRect(12, y, M5.Display.width() - 24, PAPERMONO_INBOX_ROW - 8, 10, TFT_BLACK);
            if (inboxUnread[idx] && !inboxMine[idx]) {
                M5.Display.fillCircle(28, y + (PAPERMONO_INBOX_ROW - 8) / 2, 7, TFT_BLACK);
            }
            M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
            M5.Display.setTextDatum(TL_DATUM);
            M5.Display.setTextSize(2);
            String from;
            if (inboxMine[idx]) {
                from = inboxToName[idx].length() ? ("To " + inboxToName[idx]) : String("To ALL");
            } else {
                from = inboxFrom[idx].length() ? inboxFrom[idx] : String("tablet");
            }
            if (from.length() > 18) {
                from = from.substring(0, 18);
            }
            M5.Display.drawString(from, 44, y + 6);
            String when = inboxWhen[idx];
            if (when.length() > 22) {
                when = when.substring(0, 22);
            }
            if (when.length()) {
                M5.Display.drawString(when, 44, y + 28);
            }
            String preview = inboxMine[idx] && inboxStatus[idx].length() ? inboxStatus[idx] : inboxText[idx];
            if (preview.length() > 26) {
                preview = preview.substring(0, 25) + "...";
            }
            M5.Display.drawString(preview, 44, y + (when.length() ? 50 : 32));
        }
    }
    drawPager();
    finishEpdFrame();
}

void drawRadioView(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    drawButton(16, 92, 140, 44, "BACK", false);
    bool mine = radioViewIndex >= 0 && radioViewIndex < inboxCount && inboxMine[radioViewIndex];
    if (!mine) {
        drawButton(324, 92, 140, 44, "REPLY", true);
    }
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    if (radioViewIndex < 0 || radioViewIndex >= inboxCount) {
        M5.Display.setTextSize(2);
        M5.Display.drawString("Message gone.", 16, 180);
        drawPager();
        finishEpdFrame();
        return;
    }
    M5.Display.setTextSize(2);
    M5.Display.drawString(mine ? "To" : "From", 16, 152);
    M5.Display.setTextSize(3);
    String who;
    if (mine) {
        who = inboxToName[radioViewIndex].length() ? inboxToName[radioViewIndex] : String("ALL");
    } else {
        who = inboxFrom[radioViewIndex].length() ? inboxFrom[radioViewIndex] : String("tablet");
    }
    M5.Display.drawString(who.substring(0, 18), 16, 184);
    M5.Display.setTextSize(2);
    int bodyY = 240;
    if (inboxWhen[radioViewIndex].length()) {
        M5.Display.drawString(inboxWhen[radioViewIndex], 16, 228);
        bodyY = 268;
    }
    if (mine && inboxStatus[radioViewIndex].length()) {
        M5.Display.drawString(inboxStatus[radioViewIndex], 16, bodyY);
        bodyY += 36;
    }
    drawWrappedText(inboxText[radioViewIndex], 16, bodyY, M5.Display.width() - 32, 36, 10);
    drawPager();
    finishEpdFrame();
}

void drawRadioPage(bool forceFull)
{
    if (radioUi == PAPERMONO_RADIO_VIEW) {
        drawRadioView(forceFull);
        return;
    }
    if (radioUi == PAPERMONO_RADIO_COMPOSE) {
        drawRadioCompose(forceFull);
        return;
    }
    drawRadioInbox(forceFull);
}

void drawWifiScanPage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(2);
    M5.Display.drawString("Travel Wi-Fi (2.4 GHz)", 16, 100);
    drawButton(16, 136, 144, 48, "SCAN", true);
    drawButton(168, 136, 144, 48, "TYPE", false);
    drawButton(320, 136, 144, 48, "CANCEL", false);
    if (wifiScanBusy) {
        M5.Display.setTextSize(2);
        M5.Display.drawString("Scanning 2.4 GHz.", 16, 220);
        M5.Display.drawString("Leave it — several seconds.", 16, 256);
    } else if (wifiScanCount == 0) {
        M5.Display.setTextSize(2);
        M5.Display.drawString("No other networks found.", 16, 220);
        M5.Display.drawString("SCAN again, or TYPE the name.", 16, 256);
    } else {
        for (int i = 0; i < wifiScanCount; i++) {
            int y = 200 + i * 84;
            String lab = wifiScanSsid[i];
            if (wifiScanOpen[i]) {
                lab += "  open";
            }
            drawButton(16, y, 448, 72, lab, false);
        }
    }
    drawPager();
    finishEpdFrame();
}

void drawWifiPassPage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(2);
    String title = wifiPickSsid.length() ? wifiPickSsid : String("Password");
    M5.Display.drawString(clipLabelToWidth(title, 300), 16, 100);
    drawButton(320, 92, 144, 44, "CANCEL", false);
    M5.Display.setTextSize(2);
    M5.Display.drawString("Password", 16, 148);
    String shown;
    int n = wifiDraft.length();
    if (n == 0) {
        shown = "(empty = open)";
    } else {
        for (int i = 0; i < n - 1; i++) {
            shown += '*';
        }
        shown += wifiDraft[n - 1];
    }
    M5.Display.setTextSize(3);
    M5.Display.setFont(&fonts::Font2);
    M5.Display.drawString(clipLabelToWidth(shown, M5.Display.width() - 32), 16, 184);
    M5.Display.setFont(&fonts::Font0);
    drawKeyboard(PAPERMONO_KB_Y0, true);
    drawPager();
    finishEpdFrame();
}

void drawWifiSsidPage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(2);
    M5.Display.drawString("Network name", 16, 100);
    drawButton(320, 92, 144, 44, "CANCEL", false);
    String shown = wifiDraft.length() ? wifiDraft : String("(type SSID)");
    M5.Display.setTextSize(3);
    M5.Display.setFont(&fonts::Font2);
    M5.Display.drawString(clipLabelToWidth(shown, M5.Display.width() - 32), 16, 160);
    M5.Display.setFont(&fonts::Font0);
    drawKeyboard(PAPERMONO_KB_Y0, true);
    drawPager();
    finishEpdFrame();
}

void drawDevicePage(bool forceFull)
{
    if (wifiUi == PAPERMONO_WIFI_SCAN) {
        drawWifiScanPage(forceFull);
        return;
    }
    if (wifiUi == PAPERMONO_WIFI_SSID) {
        drawWifiSsidPage(forceFull);
        return;
    }
    if (wifiUi == PAPERMONO_WIFI_PASS) {
        drawWifiPassPage(forceFull);
        return;
    }
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(5);
    String bat = tabletBat >= 0 ? (String(tabletBat) + "%") : String("--");
    M5.Display.drawString(bat, 16, 110);
    M5.Display.setTextSize(5);
    M5.Display.drawString(clockLocal.length() ? clockLocal : String("--:--"), 16, 200);
    M5.Display.setTextSize(2);
    M5.Display.drawString(clockDate, 16, 268);
    String nowSsid = WiFi.status() == WL_CONNECTED ? WiFi.SSID() : String("Not connected");
    M5.Display.drawString("Wi-Fi  " + clipLabelToWidth(nowSsid, 360), 16, 312);
    if (guestSsid.length()) {
        M5.Display.drawString("Travel  " + clipLabelToWidth(guestSsid, 240), 16, 348);
        drawButton(320, 336, 144, 44, "CLEAR", false);
    } else {
        M5.Display.drawString("No travel Wi-Fi saved.", 16, 348);
    }
    drawButton(16, 396, 448, 72, "REMOTE WIFI", true);
    int H = M5.Display.height();
    int offY = H - 200;
    int offH = 120;
    if (offConfirm) {
        drawButton(16, offY, 208, offH, "CANCEL", false);
        drawButton(248, offY, 208, offH, "OFF NOW", true);
        M5.Display.setTextDatum(TL_DATUM);
        M5.Display.setTextSize(2);
        M5.Display.drawString("Power off this tablet?", 16, offY - 36);
    } else {
        drawButton(16, offY, 448, offH, "OFF", true);
        M5.Display.setTextDatum(TL_DATUM);
        M5.Display.setTextSize(2);
        M5.Display.drawString("Side button also turns the tablet off.", 16, offY - 36);
    }
    drawPager();
    finishEpdFrame();
}

void drawScreen(bool forceFull)
{
    if (inDraw) {
        redrawQueued = true;
        return;
    }
    inDraw = true;
    do {
        redrawQueued = false;
        waitEpdReady();
        serviceTouchQueue();
    } while (redrawQueued);
    if (screenLocked) {
        String key = screenKey();
        if (forceFull || key != lastDrawnKey) {
            drawLockScreen(forceFull);
            lastDrawnKey = screenKey();
        }
    } else if (menuOpen) {
        String key = screenKey();
        if (forceFull || key != lastDrawnKey) {
            drawMenuPage(forceFull);
            lastDrawnKey = screenKey();
        }
    } else {
        if (currentPage == PAPERMONO_PAGE_BOARD) {
            currentPage = PAPERMONO_PAGE_NOTE;
        }
        if (!pageEnabled(currentPage)) {
            currentPage = firstEnabledPage();
        }
        String key = screenKey();
        if (forceFull || key != lastDrawnKey) {
            if (currentPage == PAPERMONO_PAGE_STATUS) {
                drawStatusPage(forceFull);
            } else if (currentPage == PAPERMONO_PAGE_HEALTH) {
                drawHealthPage(forceFull);
            } else if (currentPage == PAPERMONO_PAGE_PLANS) {
                drawPlansPage(forceFull);
            } else if (currentPage == PAPERMONO_PAGE_NOTE) {
                drawNotePage(forceFull);
            } else if (currentPage == PAPERMONO_PAGE_POWERWALL) {
                drawPowerwallPage(forceFull);
            } else if (currentPage == PAPERMONO_PAGE_LYMOW) {
                drawLymowPage(forceFull);
            } else if (currentPage == PAPERMONO_PAGE_RADIO) {
                drawRadioPage(forceFull);
            } else if (currentPage == PAPERMONO_PAGE_DEVICE) {
                drawDevicePage(forceFull);
            } else if (currentPage == PAPERMONO_PAGE_HOUSE) {
                drawHousePage(forceFull);
            } else {
                drawHome(forceFull);
            }
            lastDrawnKey = screenKey();
        }
    }
    inDraw = false;
    if (redrawQueued) {
        drawScreen(false);
    }
}

void drawSetup()
{
    beginEpdFrame(true);
    M5.Display.fillScreen(TFT_WHITE);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(3);
    M5.Display.drawString("PaperMono", 16, 28);
    M5.Display.setTextSize(2);
    M5.Display.drawString("setup", 16, 78);
    M5.Display.setTextSize(2);
    M5.Display.drawString("1. Plug USB into the computer", 16, 140);
    M5.Display.drawString("   running this Yarbo panel.", 16, 172);
    M5.Display.drawString("2. Open Settings, then", 16, 216);
    M5.Display.drawString("   PaperMono companion.", 16, 248);
    M5.Display.drawString("3. Flash firmware and send", 16, 292);
    M5.Display.drawString("   2.4 GHz Wi-Fi from that page.", 16, 324);
    M5.Display.drawString("Keep this cable connected", 16, 380);
    M5.Display.drawString("until CFG_OK.", 16, 412);
    finishEpdFrame();
    lastDrawnKey = "setup";
}

int homeButtonAt(int x, int y)
{
    int bw, bh, gap, y0;
    layoutButtons(bw, bh, gap, y0);
    if (y < y0) {
        return -1;
    }
    bool left = x < 16 + bw + gap / 2;
    bool top = y < y0 + bh + gap / 2;
    if (top && left) return 1;
    if (top && !left) return 2;
    if (!top && left) return 3;
    return 4;
}

bool tapOnPager(int y)
{
    (void) y;
    return false;
}

bool tapOnPadlock(int x, int y)
{
    int W = M5.Display.width();
    int lockX = W - 88;
    return x >= lockX - 8 && y >= 0 && y <= 92;
}

bool tapOnUnlock(int x, int y)
{
    int W = M5.Display.width();
    int H = M5.Display.height();
    int unlockW = 280;
    int offW = 140;
    int btnH = 110;
    int gap = 16;
    int bx = (W - (unlockW + gap + offW)) / 2;
    int by = H - 156;
    bool btn = x >= bx && x < bx + unlockW && y >= by && y <= by + btnH;
    return btn;
}

bool tapOnLockOff(int x, int y)
{
    int W = M5.Display.width();
    int H = M5.Display.height();
    int unlockW = 280;
    int offW = 140;
    int btnH = 110;
    int gap = 16;
    int bx = (W - (unlockW + gap + offW)) / 2;
    int by = H - 156;
    int ox0 = bx + unlockW + gap;
    return x >= ox0 && x <= ox0 + offW && y >= by && y <= by + btnH;
}

bool tapOnMail(int x, int y)
{
    if (unreadCount <= 0) {
        return false;
    }
    return x >= 8 && x <= 128 && y >= 8 && y <= 136;
}

void refreshUnreadLed()
{
    int n = 0;
    for (int i = 0; i < inboxCount; i++) {
        if (inboxUnread[i] && !inboxMine[i]) {
            n++;
        }
    }
    unreadCount = n;
    if (unreadFrontlightHold()) {
        rgbHoldMessage(true);
        applyFrontlight(true);
    } else {
        rgbHoldMessage(false);
        if (lightOn) {
            applyFrontlight(true);
        }
    }
}

void openInboxFromLock()
{
    radioUi = PAPERMONO_RADIO_INBOX;
    radioViewIndex = -1;
    currentPage = PAPERMONO_PAGE_RADIO;
    screenLocked = false;
    menuOpen = false;
    noteActivity();
    applyFrontlight(true);
    drawScreen(false);
    lastDrawnKey = screenKey();
}

bool syncPaperLogo(const String &hash)
{
    if (hash.length() == 0) {
        if (SPIFFS.exists("/logo.png")) {
            SPIFFS.remove("/logo.png");
        }
        SPIFFS.remove("/logo.tmp");
        logoHash = "";
        return true;
    }
    if (hash == logoHash && SPIFFS.exists("/logo.png")) {
        return true;
    }
    HTTPClient http;
    int code = paperNetGet(http, "/api/device.php?action=logo", 20000);
    if (code != 200) {
        http.end();
        return false;
    }
    int len = http.getSize();
    File f = SPIFFS.open("/logo.tmp", FILE_WRITE);
    if (!f) {
        http.end();
        return false;
    }
    WiFiClient *stream = http.getStreamPtr();
    uint8_t buf[1024];
    int written = 0;
    unsigned long start = millis();
    while (http.connected() && (len < 0 || written < len) && millis() - start < 20000) {
        int avail = stream->available();
        if (avail <= 0) {
            delay(10);
            continue;
        }
        size_t want = avail > (int) sizeof(buf) ? sizeof(buf) : (size_t) avail;
        int n = stream->readBytes(buf, want);
        if (n <= 0) {
            break;
        }
        f.write(buf, n);
        written += n;
        if (len >= 0 && written >= len) {
            break;
        }
    }
    f.close();
    http.end();
    if (written < 24) {
        SPIFFS.remove("/logo.tmp");
        return false;
    }
    File chk = SPIFFS.open("/logo.tmp", FILE_READ);
    uint8_t mag[8] = {0};
    if (chk) {
        chk.read(mag, 8);
        chk.close();
    }
    if (mag[0] != 0x89 || mag[1] != 'P' || mag[2] != 'N' || mag[3] != 'G') {
        SPIFFS.remove("/logo.tmp");
        return false;
    }
    SPIFFS.remove("/logo.png");
    SPIFFS.rename("/logo.tmp", "/logo.png");
    logoHash = hash;
    return true;
}

void pushInbox(const String &id, const String &from, const String &fromId, const String &text, bool alert, const String &when, bool mine, const String &toName, const String &status)
{
    for (int i = 0; i < inboxCount; i++) {
        if (id.length() && inboxIds[i] == id) {
            if (when.length() && inboxWhen[i] != when) {
                inboxWhen[i] = when;
            }
            inboxMine[i] = mine;
            if (toName.length()) {
                inboxToName[i] = toName;
            }
            inboxStatus[i] = status;
            if (mine) {
                inboxUnread[i] = false;
            }
            refreshUnreadLed();
            return;
        }
    }
    if (inboxCount >= PAPERMONO_INBOX_MAX) {
        for (int i = 1; i < PAPERMONO_INBOX_MAX; i++) {
            inboxIds[i - 1] = inboxIds[i];
            inboxFrom[i - 1] = inboxFrom[i];
            inboxFromId[i - 1] = inboxFromId[i];
            inboxText[i - 1] = inboxText[i];
            inboxWhen[i - 1] = inboxWhen[i];
            inboxToName[i - 1] = inboxToName[i];
            inboxStatus[i - 1] = inboxStatus[i];
            inboxUnread[i - 1] = inboxUnread[i];
            inboxMine[i - 1] = inboxMine[i];
        }
        inboxCount = PAPERMONO_INBOX_MAX - 1;
    }
    inboxIds[inboxCount] = id;
    inboxFrom[inboxCount] = from;
    inboxFromId[inboxCount] = fromId;
    inboxText[inboxCount] = text;
    inboxWhen[inboxCount] = when.length() ? when : nowStamp();
    inboxToName[inboxCount] = toName;
    inboxStatus[inboxCount] = status;
    inboxMine[inboxCount] = mine;
    inboxUnread[inboxCount] = mine ? false : alert;
    inboxCount++;
    if (id.length()) {
        lastInboxId = id;
    }
    refreshUnreadLed();
    if (alert && !mine && alertMessageOn) {
        alertMessage();
    }
}

void applyCompactExtras(JsonDocument &doc)
{
    String newName = doc["device_name"] | deviceName;
    bool nameChanged = newName.length() && newName != deviceName;
    if (newName.length()) {
        deviceName = newName;
    }
    deviceId = doc["device_id"] | deviceId;
    lockAfterS = doc["lock_after_s"] | lockAfterS;
    lightOffS = doc["light_off_s"] | lightOffS;
    int prevBright = brightnessPct;
    String prevLock = lockScreen;
    String prevUnlock = unlockPage;
    int prevOff = clockOffset;
    bool prevSet = clockOffsetSet;
    bool prevBoard = vestaboardOn;
    bool prevKnown = vestaboardKnown;
    applyCompanionFields(doc, false);
    if (!doc["remote_url"].isNull()) {
        String ru = doc["remote_url"] | "";
        paperNetNormalize(ru);
        if (ru != remoteUrl) {
            remoteUrl = ru;
            saveConfig();
        }
    }
    if (doc["menu_labels"].is<JsonObject>()) {
        applyMenuLabels(doc["menu_labels"]);
    }
    if (doc["menu_visible"].is<JsonObject>()) {
        applyMenuVisible(doc["menu_visible"]);
    }
    if (doc["menu_order"].is<JsonArray>()) {
        applyMenuOrder(doc["menu_order"]);
    }
    if (brightnessPct != prevBright && lightOn) {
        applyFrontlight(true);
    }
    if (nameChanged || brightnessPct != prevBright || prevLock != lockScreen || prevUnlock != unlockPage || prevOff != clockOffset
        || prevSet != clockOffsetSet || prevBoard != vestaboardOn || prevKnown != vestaboardKnown) {
        saveConfig();
    }
    if (clockOffsetSet && (prevOff != clockOffset || !prevSet) && WiFi.status() == WL_CONNECTED) {
        configTime(clockOffset, 0, "pool.ntp.org", "time.google.com");
        ntpStarted = true;
        lastNtpTry = millis();
    }
    alertMessageOn = doc["alert_message"] | alertMessageOn;
    alertYarboOn = doc["alert_yarbo"] | alertYarboOn;
    alertLymowOn = doc["alert_lymow"] | alertLymowOn;
    alertPowerwallOn = doc["alert_powerwall"] | alertPowerwallOn;
    yarboError = doc["yarbo_error"] | false;
    lymowError = doc["lymow_error"] | false;
    powerwallError = doc["powerwall_error"] | false;
    String panelClock = doc["clock_local"] | "";
    if (panelClock.length() >= 4) {
        clockLocal = panelClock;
        String panelDate = doc["clock_date"] | "";
        if (panelDate.length()) {
            clockDate = panelDate;
        }
        lastPanelClockMs = millis();
    } else if (time(nullptr) < 1700000000) {
        clockLocal = doc["clock_local"] | clockLocal;
        clockDate = doc["clock_date"] | clockDate;
        refreshLocalClock();
    } else {
        refreshLocalClock();
    }
    uint8_t sync = (uint8_t) ((int) (doc["radio_sync"] | (int) radioSync));
    if (sync != radioSync) {
        radioSync = sync;
        loraSetSyncWord(radioSync);
    }
    vestaboardHash = doc["vestaboard_hash"] | vestaboardHash;
    JsonArray lines = doc["vestaboard_lines"].as<JsonArray>();
    if (!lines.isNull()) {
        for (int r = 0; r < 3; r++) {
            vestaboardLines[r] = String((const char *) (lines[r] | ""));
        }
    }
    JsonArray codes = doc["vestaboard_codes"].as<JsonArray>();
    if (!codes.isNull()) {
        for (int r = 0; r < 3; r++) {
            JsonArray row = codes[r].as<JsonArray>();
            for (int c = 0; c < 15; c++) {
                vestaboardCodes[r][c] = row.isNull() ? 0 : (int) (row[c] | 0);
            }
        }
    }
    peerCount = 0;
    JsonArray peers = doc["paper_peers"].as<JsonArray>();
    if (!peers.isNull()) {
        for (JsonVariant item : peers) {
            if (peerCount >= PAPERMONO_PEER_MAX) break;
            JsonObject p = item.as<JsonObject>();
            if (p.isNull()) continue;
            peerIds[peerCount] = String((const char *) (p["id"] | ""));
            peerNames[peerCount] = String((const char *) (p["name"] | ""));
            if (peerIds[peerCount].length()) {
                peerCount++;
            }
        }
    }
    JsonArray msgs = doc["paper_messages"].as<JsonArray>();
    if (!msgs.isNull()) {
        String seenIds[PAPERMONO_INBOX_MAX];
        int seenCount = inboxCount < PAPERMONO_INBOX_MAX ? inboxCount : PAPERMONO_INBOX_MAX;
        for (int i = 0; i < seenCount; i++) {
            seenIds[i] = inboxIds[i];
        }
        String viewingId = "";
        if (radioUi == PAPERMONO_RADIO_VIEW && radioViewIndex >= 0 && radioViewIndex < inboxCount) {
            viewingId = inboxIds[radioViewIndex];
        }
        inboxCount = 0;
        bool anyNewUnread = false;
        String newestId = "";
        for (JsonVariant item : msgs) {
            JsonObject m = item.as<JsonObject>();
            if (m.isNull()) continue;
            String id = String((const char *) (m["id"] | ""));
            String from = String((const char *) (m["from_name"] | "tablet"));
            String fromId = String((const char *) (m["from"] | ""));
            String text = String((const char *) (m["text"] | ""));
            String when = String((const char *) (m["at_local"] | ""));
            bool mine = m["mine"] | false;
            bool unread = m["unread"] | false;
            String toName = String((const char *) (m["to_name"] | "ALL"));
            String status = String((const char *) (m["read_label"] | ""));
            if (!text.length()) continue;
            bool known = false;
            if (id.length()) {
                for (int i = 0; i < seenCount; i++) {
                    if (seenIds[i] == id) {
                        known = true;
                        break;
                    }
                }
            }
            pushInbox(id, from, fromId, text, false, when, mine, toName, status);
            if (!mine && unread && inboxCount > 0) {
                inboxUnread[inboxCount - 1] = true;
                if (!known) {
                    anyNewUnread = true;
                }
            }
            if (id.length()) {
                newestId = id;
            }
        }
        if (newestId.length()) {
            lastInboxId = newestId;
        }
        radioViewIndex = -1;
        if (viewingId.length()) {
            for (int i = 0; i < inboxCount; i++) {
                if (inboxIds[i] == viewingId) {
                    radioViewIndex = i;
                    break;
                }
            }
        }
        if (radioUi == PAPERMONO_RADIO_VIEW && radioViewIndex < 0) {
            radioUi = PAPERMONO_RADIO_INBOX;
        }
        refreshUnreadLed();
        if (anyNewUnread && alertMessageOn) {
            alertMessage();
        }
    }
    bool anyError = (yarboOn && yarboError && alertYarboOn)
        || (lymowOn && lymowError && alertLymowOn)
        || (powerwallOn && powerwallError && alertPowerwallOn);
    alertsSetErrorActive(anyError);
    if (anyError && millis() - lastErrorAlert > 60000) {
        lastErrorAlert = millis();
        alertError();
    }
}

bool postPaperMessage(const String &to, const String &text)
{
    if (WiFi.status() != WL_CONNECTED || (panelUrl.isEmpty() && remoteUrl.isEmpty()) || token.isEmpty()) {
        return false;
    }
    JsonDocument doc;
    doc["action"] = "paper_message";
    doc["token"] = token;
    doc["to"] = to;
    doc["text"] = text;
    String payload;
    serializeJson(doc, payload);
    HTTPClient http;
    int code = paperNetPost(http, "/api/device.php", payload);
    String body = http.getString();
    http.end();
    JsonDocument res;
    deserializeJson(res, body);
    lastPostedMsgId = "";
    bool ok = code == 200 && res["ok"];
    if (ok) {
        lastPostedMsgId = String((const char *) (res["message"]["id"] | ""));
    }
    return ok;
}

void postPaperRead(const String &id)
{
    if (!id.length() || WiFi.status() != WL_CONNECTED || (panelUrl.isEmpty() && remoteUrl.isEmpty()) || token.isEmpty()) {
        return;
    }
    JsonDocument doc;
    doc["action"] = "paper_read";
    doc["token"] = token;
    doc["id"] = id;
    String payload;
    serializeJson(doc, payload);
    HTTPClient http;
    paperNetPost(http, "/api/device.php", payload);
    http.end();
}

void handleIncomingRadio(const String &raw)
{
    JsonDocument doc;
    if (deserializeJson(doc, raw)) {
        return;
    }
    String to = doc["to"] | "*";
    String fromId = doc["from"] | "";
    if (fromId == deviceId && deviceId.length()) {
        return;
    }
    if (to != "*" && to.length() && to != deviceId) {
        return;
    }
    String from = doc["from_name"] | "tablet";
    String text = doc["text"] | "";
    String id = doc["id"] | String(millis());
    if (text.length()) {
        pushInbox(id, from, fromId, text, true, nowStamp(), false, "", "");
        drawScreen(false);
    }
}

bool sendRadioMessage()
{
    radioDraft.trim();
    if (radioDraft.length() == 0) {
        return false;
    }
    if (radioDraft.length() > PAPERMONO_MSG_CHARS) {
        radioDraft = radioDraft.substring(0, PAPERMONO_MSG_CHARS);
    }
    String to = "*";
    String toName = "ALL";
    if (radioToIndex > 0 && radioToIndex <= peerCount) {
        to = peerIds[radioToIndex - 1];
        toName = peerNames[radioToIndex - 1];
    }
    lastPostedMsgId = "";
    bool panelOk = postPaperMessage(to, radioDraft);
    String id = lastPostedMsgId.length() ? lastPostedMsgId : String((uint32_t) millis(), HEX);
    JsonDocument doc;
    doc["from"] = deviceId;
    doc["from_name"] = deviceName;
    doc["to"] = to;
    doc["to_name"] = toName;
    doc["text"] = radioDraft;
    doc["id"] = id;
    String payload;
    serializeJson(doc, payload);
    bool loraOk = loraReady() && loraSendText(payload);
    bool ok = panelOk || loraOk;
    if (ok) {
        lastError = "";
        pushInbox(id, deviceName, deviceId, radioDraft, false, nowStamp(), true, toName, "Sent");
        radioDraft = "";
        radioUi = PAPERMONO_RADIO_INBOX;
    } else {
        lastError = "send failed";
    }
    drawScreen(false);
    return ok;
}

int keyboardHit(int x, int y, bool wifiKeys)
{
    int y0 = PAPERMONO_KB_Y0;
    int rowH = PAPERMONO_KB_ROW;
    int actionH = PAPERMONO_KB_ACTION;
    if (y < y0 || y > y0 + 3 * rowH + actionH) {
        return -1;
    }
    if (y >= y0 + 3 * rowH) {
        if (wifiKeys) {
            if (x < 84) return 104;
            if (x < 160) return 100;
            if (x < 274) return 101;
            if (x < 360) return 102;
            return 103;
        }
        if (x < 114) return 100;
        if (x < 280) return 101;
        if (x < 378) return 102;
        return 103;
    }
    int row = (y - y0) / rowH;
    const char *keys = kbRow(row);
    int n = strlen(keys);
    int keyW = (M5.Display.width() - 16) / n;
    int i = (x - 8) / keyW;
    if (i < 0 || i >= n) return -1;
    return (row * 32) + i;
}

void applyRadioHit(int hit)
{
    if (hit == 100) {
        kbNumbers = !kbNumbers;
        drawScreen(false);
        return;
    }
    if (hit == 101) {
        if (radioDraft.length() < PAPERMONO_MSG_CHARS) radioDraft += ' ';
        updateRadioDraft();
        return;
    }
    if (hit == 102) {
        if (radioDraft.length()) radioDraft.remove(radioDraft.length() - 1);
        updateRadioDraft();
        return;
    }
    if (hit == 103) {
        sendRadioMessage();
        return;
    }
    int row = hit / 32;
    int col = hit % 32;
    const char *keys = kbRow(row);
    if (col < (int) strlen(keys) && radioDraft.length() < PAPERMONO_MSG_CHARS) {
        radioDraft += keys[col];
        updateRadioDraft();
    }
}

void handleRadioTouch(int x, int y)
{
    if (radioUi == PAPERMONO_RADIO_INBOX) {
        if (y >= 88 && y <= 140 && x >= 300) {
            radioUi = PAPERMONO_RADIO_COMPOSE;
            drawScreen(false);
            return;
        }
        if (inboxCount == 0) {
            return;
        }
        int shown = min(inboxCount, 6);
        int y0 = PAPERMONO_INBOX_Y0 + 28;
        if (y >= y0 && y < y0 + shown * PAPERMONO_INBOX_ROW) {
            int row = (y - y0) / PAPERMONO_INBOX_ROW;
            if (row >= 0 && row < shown) {
                int idx = inboxCount - 1 - row;
                radioViewIndex = idx;
                if (idx >= 0 && idx < inboxCount) {
                    inboxUnread[idx] = false;
                    if (!inboxMine[idx]) {
                        postPaperRead(inboxIds[idx]);
                    }
                }
                refreshUnreadLed();
                radioUi = PAPERMONO_RADIO_VIEW;
                drawScreen(false);
            }
        }
        return;
    }
    if (radioUi == PAPERMONO_RADIO_VIEW) {
        if (y >= 88 && y <= 140 && x < 180) {
            radioUi = PAPERMONO_RADIO_INBOX;
            radioViewIndex = -1;
            drawScreen(false);
            return;
        }
        if (y >= 88 && y <= 140 && x >= 300) {
            if (radioViewIndex >= 0 && radioViewIndex < inboxCount && inboxMine[radioViewIndex]) {
                return;
            }
            radioToIndex = 0;
            if (radioViewIndex >= 0 && radioViewIndex < inboxCount) {
                String fromId = inboxFromId[radioViewIndex];
                for (int i = 0; i < peerCount; i++) {
                    if (peerIds[i] == fromId) {
                        radioToIndex = i + 1;
                        break;
                    }
                }
            }
            radioUi = PAPERMONO_RADIO_COMPOSE;
            drawScreen(false);
            return;
        }
        return;
    }
    if (y >= 88 && y <= 140 && x >= 300) {
        radioUi = PAPERMONO_RADIO_INBOX;
        drawScreen(false);
        return;
    }
    int n = min(4, peerCount + 1);
    int pw = (M5.Display.width() - 24) / n;
    if (y >= PAPERMONO_PEER_Y && y <= PAPERMONO_PEER_Y + 56) {
        int i = (x - 12) / pw;
        if (i >= 0 && i < n) {
            radioToIndex = i;
            drawScreen(false);
        }
        return;
    }
    int hit = keyboardHit(x, y, false);
    if (hit >= 0) {
        applyRadioHit(hit);
    }
}

void applyDeviceOffTap(int x)
{
    if (offConfirm) {
        if (x < 240) {
            offConfirm = false;
            drawScreen(false);
        } else {
            powerOffTablet();
        }
    } else {
        offConfirm = true;
        drawScreen(false);
    }
}

void applyWifiHit(int hit)
{
    int cap = wifiUi == PAPERMONO_WIFI_SSID ? PAPERMONO_WIFI_SSID_MAX : PAPERMONO_WIFI_PASS_MAX;
    if (hit == 104) {
        kbShift = !kbShift;
        if (kbShift) {
            kbNumbers = false;
        }
        drawScreen(false);
        return;
    }
    if (hit == 100) {
        kbNumbers = !kbNumbers;
        if (kbNumbers) {
            kbShift = false;
        }
        drawScreen(false);
        return;
    }
    if (hit == 101) {
        if (wifiDraft.length() < cap) {
            wifiDraft += ' ';
        }
        drawScreen(false);
        return;
    }
    if (hit == 102) {
        if (wifiDraft.length()) {
            wifiDraft.remove(wifiDraft.length() - 1);
        }
        drawScreen(false);
        return;
    }
    if (hit == 103) {
        if (wifiUi == PAPERMONO_WIFI_SSID) {
            wifiPickSsid = wifiDraft;
            wifiPickSsid.trim();
            if (!wifiPickSsid.length()) {
                return;
            }
            wifiDraft = "";
            kbNumbers = false;
            kbShift = false;
            wifiUi = PAPERMONO_WIFI_PASS;
            drawScreen(false);
            return;
        }
        if (!wifiPickSsid.length()) {
            return;
        }
        saveGuestWifi();
        drawScreen(false);
        return;
    }
    int row = hit / 32;
    int col = hit % 32;
    const char *keys = kbRow(row);
    if (col < (int) strlen(keys) && wifiDraft.length() < cap) {
        wifiDraft += keys[col];
        if (kbShift) {
            kbShift = false;
        }
        drawScreen(false);
    }
}

void handleWifiScanTouch(int x, int y)
{
    if (y >= 128 && y <= 192) {
        if (x < 164) {
            startWifiScan();
        } else if (x < 312) {
            wifiUi = PAPERMONO_WIFI_SSID;
            wifiDraft = "";
            wifiPickSsid = "";
            kbNumbers = false;
            kbShift = false;
            drawScreen(false);
        } else {
            wifiUi = PAPERMONO_WIFI_IDLE;
            drawScreen(false);
        }
        return;
    }
    if (wifiScanBusy || wifiScanCount == 0) {
        return;
    }
    for (int i = 0; i < wifiScanCount; i++) {
        int by = 200 + i * 84;
        if (y >= by && y <= by + 72) {
            wifiPickSsid = wifiScanSsid[i];
            wifiDraft = "";
            kbNumbers = false;
            kbShift = false;
            if (wifiScanOpen[i]) {
                saveGuestWifi();
                drawScreen(false);
                return;
            }
            wifiUi = PAPERMONO_WIFI_PASS;
            drawScreen(false);
            return;
        }
    }
}

void handleDeviceTouch(int x, int y)
{
    if (wifiUi == PAPERMONO_WIFI_SCAN) {
        handleWifiScanTouch(x, y);
        return;
    }
    if (wifiUi == PAPERMONO_WIFI_SSID || wifiUi == PAPERMONO_WIFI_PASS) {
        if (y >= 88 && y <= 140 && x >= 300) {
            wifiUi = PAPERMONO_WIFI_SCAN;
            wifiDraft = "";
            drawScreen(false);
            return;
        }
        int hit = keyboardHit(x, y, true);
        if (hit >= 0) {
            applyWifiHit(hit);
        }
        return;
    }
    if (guestSsid.length() && y >= 330 && y <= 388 && x >= 310) {
        clearGuestWifi();
        drawScreen(false);
        return;
    }
    if (y >= 390 && y <= 476) {
        startWifiScan();
        return;
    }
    int H = M5.Display.height();
    int offY = H - 200;
    if (y >= offY - 10 && y <= H - 40) {
        applyDeviceOffTap(x);
    }
}

void handleLockTouch(int x, int y)
{
    lastLight = millis();
    if (!lightOn) {
        applyFrontlight(true);
    }
    if (tapOnLockOff(x, y)) {
        powerOffTablet();
        return;
    }
    if (tapOnMail(x, y)) {
        openInboxFromLock();
        return;
    }
    if (tapOnUnlock(x, y)) {
        exitLock();
    }
}

void drawOtaScreen()
{
    beginEpdFrame(true);
    M5.Display.fillScreen(TFT_WHITE);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(MC_DATUM);
    M5.Display.setTextSize(3);
    M5.Display.drawString("UPDATING", M5.Display.width() / 2, M5.Display.height() / 2 - 40);
    M5.Display.setTextSize(2);
    M5.Display.drawString("Stay on Wi-Fi. Do not power off.", M5.Display.width() / 2, M5.Display.height() / 2 + 16);
    finishEpdFrame();
}

void runOtaUpdate()
{
    if (otaBusy || WiFi.status() != WL_CONNECTED || (panelUrl.isEmpty() && remoteUrl.isEmpty()) || token.isEmpty()) {
        return;
    }
    otaBusy = true;
    WiFi.setSleep(false);
    applyFrontlight(true);
    drawOtaScreen();
    alertOta();
    delay(1200);
    HTTPUpdate updater(300000);
    updater.rebootOnUpdate(true);
    updater.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
    String path = "/api/device.php?action=firmware&token=" + token;
    bool ok = paperNetOta(path);
    otaBusy = false;
    rgbOff();
    if (!ok) {
        lastError = "update failed";
        drawScreen(false);
    }
}

bool tabletPluggedIn()
{
    /* PaperMono: do not poll Power isCharging here. That attaches the IP2316 to the
     * shared I2C bus (FT6336G touch) and leaves taps dead while USB is in. PM1
     * VBUS is enough to know the tablet is on power, including a full battery. */
    int16_t vbus = M5.Power.getVBUSVoltage();
    return vbus >= 4000;
}

void refreshTabletPower(bool force)
{
    static uint32_t lastPowerMs = 0;
    uint32_t now = millis();
    if (!force && lastPowerMs != 0 && now - lastPowerMs < 2000) {
        return;
    }
    lastPowerMs = now;
    tabletBat = M5.Power.getBatteryLevel();
    tabletCharging = tabletPluggedIn();
}

static String panelApiPath(const char *action)
{
    refreshTabletPower(true);
    String url = "/api/device.php?action=";
    url += action;
    url += "&fw=";
    url += PAPERMONO_FW_VERSION;
    if (tabletBat >= 0) {
        int pct = tabletBat > 100 ? 100 : tabletBat;
        url += "&batt=";
        url += String(pct);
    }
    url += tabletCharging ? "&chg=1" : "&chg=0";
    return url;
}

bool httpGetStatus()
{
    if (WiFi.status() != WL_CONNECTED || (panelUrl.isEmpty() && remoteUrl.isEmpty()) || token.isEmpty()) {
        return false;
    }
    HTTPClient http;
    String url = panelApiPath("compact");
    int code = paperNetGet(http, url);
    String body = http.getString();
    http.end();
    if (code != 200) {
        lastError = "HTTP " + String(code);
        return false;
    }
    JsonDocument doc;
    if (deserializeJson(doc, body)) {
        lastError = "bad status";
        return false;
    }
    applyCompactExtras(doc);
    vestaboardLive = doc["vestaboard_live"] | vestaboardLive;
    yarboOn = doc["yarbo_enabled"] | true;
    powerwallOn = doc["powerwall_enabled"] | false;
    lymowOn = doc["lymow_enabled"] | false;
    homeOn = doc["home_enabled"] | false;
    homeCount = 0;
    {
        JsonArray items = doc["home_items"].as<JsonArray>();
        if (!items.isNull()) {
            for (JsonVariant item : items) {
                if (homeCount >= PAPERMONO_HOME_MAX) {
                    break;
                }
                JsonObject o = item.as<JsonObject>();
                if (o.isNull()) {
                    continue;
                }
                homeIds[homeCount] = String((const char *) (o["id"] | ""));
                homeNames[homeCount] = String((const char *) (o["name"] | ""));
                homeKinds[homeCount] = String((const char *) (o["kind"] | "light"));
                homeOnState[homeCount] = o["on"] | false;
                if (homeIds[homeCount].length()) {
                    homeCount++;
                }
            }
        }
    }
    powerwallPct = doc["powerwall_pct"] | powerwallPct;
    powerwallSolar = doc["powerwall_solar"] | powerwallSolar;
    powerwallLoad = doc["powerwall_load"] | powerwallLoad;
    lymowName = doc["lymow_name"] | lymowName;
    lymowBattery = doc["lymow_battery"] | lymowBattery;
    lymowState = doc["lymow_state"] | lymowState;
    lymowCharging = doc["lymow_charging"] | lymowCharging;
    syncPaperLogo(String((const char *) (doc["logo_hash"] | "")));
    lastError = "";
    if (!doc["ok"]) {
        String err = doc["error"] | "bad status";
        if (err.length()) {
            connectionStatus = err;
        }
        return false;
    }
    battery = doc["battery"] | battery;
    charging = doc["charging_label"] | charging;
    state = doc["state"] | state;
    head = doc["head_type_name"] | head;
    robotName = doc["robot_name"] | "";
    errorCode = doc["error_code"] | 0;
    errorLabel = doc["error_label"] | String(errorCode);
    if (doc["heading"].is<float>() || doc["heading"].is<int>() || doc["heading"].is<double>()) {
        heading = String((float) doc["heading"], 1) + " deg";
    } else if (!doc["heading"].isNull()) {
        heading = String((const char *) (doc["heading"] | "—"));
    }
    rainLabel = doc["rain_label"] | rainLabel;
    connectionType = doc["connection_type"] | connectionType;
    connectionStatus = doc["connection_status"] | connectionStatus;
    wifiNetwork = doc["wifi_network"] | wifiNetwork;
    wifiSignal = doc["wifi_signal"] | wifiSignal;
    wifiSecurity = doc["wifi_security"] | wifiSecurity;
    batteryTemp = doc["battery_temp"] | batteryTemp;
    wirelessCharge = doc["wireless_charge"] | wirelessCharge;
    rtkStatus = doc["rtk_status"] | rtkStatus;
    rtcmAge = doc["rtcm_age"] | rtcmAge;
    routePriority = doc["route_priority"] | routePriority;
    rainSensor = doc["rain_sensor"] | rainSensor;
    netModule = doc["net_module"] | netModule;
    planActivity = doc["plan_activity"] | planActivity;
    lastError = "";
    bool otaPending = doc["ota_pending"] | false;
    String latest = doc["firmware_latest"] | "";
    if (otaPending && latest.length() && latest != PAPERMONO_FW_VERSION && !otaTriedThisBoot) {
        otaTriedThisBoot = true;
        runOtaUpdate();
    }
    return true;
}

bool httpGetPlans(bool refresh)
{
    if (WiFi.status() != WL_CONNECTED || (panelUrl.isEmpty() && remoteUrl.isEmpty()) || token.isEmpty()) {
        return false;
    }
    HTTPClient http;
    String url = panelApiPath("plans");
    if (refresh) {
        url += "&refresh=1";
    }
    int code = paperNetGet(http, url, 20000);
    String body = http.getString();
    http.end();
    if (code != 200) {
        plansNote = "HTTP " + String(code);
        return false;
    }
    JsonDocument doc;
    if (deserializeJson(doc, body) || !doc["ok"]) {
        plansNote = doc["error"] | "plans failed";
        return false;
    }
    planCount = 0;
    JsonArray arr = doc["plans"].as<JsonArray>();
    if (!arr.isNull()) {
        for (JsonVariant item : arr) {
            if (planCount >= PAPERMONO_PLAN_MAX) {
                break;
            }
            JsonObject p = item.as<JsonObject>();
            if (p.isNull()) {
                continue;
            }
            if (p["id"].is<int>() || p["id"].is<long>()) {
                planIds[planCount] = String((int) p["id"]);
            } else {
                planIds[planCount] = String((const char *) (p["id"] | ""));
            }
            planNames[planCount] = String((const char *) (p["name"] | ""));
            if (planIds[planCount].length() && planNames[planCount].length()) {
                planCount++;
            }
        }
    }
    plansNote = doc["note"] | "";
    if (selectedPlan >= planCount) {
        selectedPlan = planCount ? 0 : -1;
    }
    if (planOffset >= planCount) {
        planOffset = 0;
    }
    plansLoaded = true;
    return true;
}

bool httpCommand(const char *cmd, const char *planId = nullptr, const char *live = nullptr, const char *homeId = nullptr)
{
    if (WiFi.status() != WL_CONNECTED) {
        return false;
    }
    JsonDocument doc;
    doc["action"] = "command";
    doc["command"] = cmd;
    doc["token"] = token;
    if (planId && planId[0]) {
        doc["plan_id"] = planId;
    }
    if (live && live[0]) {
        doc["vestaboard_live"] = live;
    }
    if (homeId && homeId[0]) {
        doc["home_id"] = homeId;
    }
    String payload;
    serializeJson(doc, payload);
    HTTPClient http;
    int code = paperNetPost(http, "/api/device.php", payload);
    String body = http.getString();
    http.end();
    JsonDocument res;
    deserializeJson(res, body);
    bool ok = code == 200 && res["ok"];
    if (ok) {
        lastError = "";
    } else if (res["error"].is<const char*>()) {
        lastError = res["error"].as<const char*>();
    } else {
        lastError = "cmd HTTP " + String(code);
    }
    return ok;
}

void runCommand(const char *cmd)
{
    httpCommand(cmd);
    httpGetStatus();
    drawScreen(false);
}

void setVestaboardLive(const char *live)
{
    vestaboardLive = live;
    httpCommand("vestaboard_live", nullptr, live);
    httpGetStatus();
    drawScreen(false);
}

void showPage(int page, bool loadPlansIfNeeded)
{
    menuOpen = false;
    if (page == PAPERMONO_PAGE_BOARD) {
        page = PAPERMONO_PAGE_NOTE;
    }
    if (!pageEnabled(page)) {
        page = stepEnabledPage(page, 1);
    }
    currentPage = page;
    if (isYarboPage(currentPage)) {
        lastYarboPage = currentPage;
    }
    offConfirm = false;
    if (currentPage != PAPERMONO_PAGE_DEVICE) {
        wifiUi = PAPERMONO_WIFI_IDLE;
    }
    noteActivity();
    if (currentPage == PAPERMONO_PAGE_PLANS && loadPlansIfNeeded && !plansLoaded) {
        httpGetPlans(false);
    }
    drawScreen(false);
}

void nextPage()
{
    showPage(stepEnabledPage(currentPage, 1), true);
}

void prevPage()
{
    showPage(stepEnabledPage(currentPage, -1), true);
}

bool applyPendingPages()
{
    int steps = pendingPageSteps;
    if (steps == 0) {
        return false;
    }
    pendingPageSteps = 0;
    if (menuOpen) {
        menuOpen = false;
    }
    int dir = steps > 0 ? 1 : -1;
    int n = steps > 0 ? steps : -steps;
    if (n > PAPERMONO_PAGE_COUNT) {
        n = n % PAPERMONO_PAGE_COUNT;
        if (n == 0) {
            n = 1;
        }
    }
    for (int i = 0; i < n; i++) {
        currentPage = stepEnabledPage(currentPage, dir);
    }
    if (isYarboPage(currentPage)) {
        lastYarboPage = currentPage;
    }
    offConfirm = false;
    if (currentPage == PAPERMONO_PAGE_PLANS && !plansLoaded) {
        httpGetPlans(false);
    }
    return true;
}

void flushPageButtons()
{
    if (applyPendingPages()) {
        drawScreen(false);
        if (applyPendingPages()) {
            drawScreen(false);
        }
    }
}

void inputTask(void *arg)
{
    (void) arg;
    for (;;) {
        M5.update();
        if (!screenLocked) {
            if (M5.BtnA.wasPressed()) {
                pendingPageSteps++;
                lastActivity = millis();
                lastLight = millis();
                if (!lightOn) {
                    applyFrontlight(true);
                }
            }
            if (M5.BtnB.wasPressed()) {
                pendingPageSteps--;
                lastActivity = millis();
                lastLight = millis();
                if (!lightOn) {
                    applyFrontlight(true);
                }
            }
        }
        if (M5.BtnPWR.wasReleased() && !M5.BtnPWR.wasHold()) {
            pwrOffEvent = 1;
        }
        int tx = 0;
        int ty = 0;
        if (takeTouchPress(tx, ty)) {
            lastActivity = millis();
            lastLight = millis();
            if (!lightOn) {
                applyFrontlight(true);
            } else if (unreadFrontlightHold()) {
                applyFrontlight(true);
            }
            touchQX = tx;
            touchQY = ty;
            touchQ = 1;
        }
        vTaskDelay(pdMS_TO_TICKS(8));
    }
}

void handleNoteTouch(int x, int y)
{
    int bw, bh, gap, y0;
    layoutButtons(bw, bh, gap, y0);
    if (y >= y0) {
        bool left = x < 16 + bw + gap / 2;
        bool top = y < y0 + bh + gap / 2;
        int which = 0;
        if (top && left) which = 0;
        else if (top && !left) which = 1;
        else if (!top && left) which = 2;
        else which = 3;
        if (which < noteChoiceCount()) {
            setVestaboardLive(noteChoiceId(which));
        }
        return;
    }
}

void handlePlansTouch(int x, int y)
{
    int y0 = plansRowY0();
    int rh = plansRowH();
    int startY = plansStartY();
    int bw, bh, gap, ignoreY;
    layoutButtons(bw, bh, gap, ignoreY);
    if (y >= startY && y <= startY + 72) {
        bool left = x < 16 + bw + gap / 2;
        if (left) {
            if (selectedPlan >= 0 && selectedPlan < planCount) {
                httpCommand("start_plan", planIds[selectedPlan].c_str());
                httpGetStatus();
                drawScreen(false);
            }
            return;
        }
        if (planCount > PAPERMONO_PLAN_VISIBLE) {
            planOffset += PAPERMONO_PLAN_VISIBLE;
            if (planOffset >= planCount) {
                planOffset = 0;
            }
            drawScreen(false);
        }
        return;
    }
    if (y >= y0 && y < startY) {
        int row = (y - y0) / rh;
        int idx = planOffset + row;
        if (idx >= 0 && idx < planCount && row < PAPERMONO_PLAN_VISIBLE) {
            selectedPlan = idx;
            drawScreen(false);
        }
        return;
    }
}

void handleHouseTouch(int x, int y)
{
    int shown = houseItemCount();
    for (int i = 0; i < shown; i++) {
        int bx, by, bw, bh;
        houseButtonRect(i, bx, by, bw, bh);
        if (x >= bx && x <= bx + bw && y >= by && y <= by + bh) {
            const char *cmd = homeKinds[i] == "scene" ? "home_scene" : "home_toggle";
            httpCommand(cmd, nullptr, nullptr, homeIds[i].c_str());
            httpGetStatus();
            drawScreen(false);
            return;
        }
    }
}

void announceUsbReady()
{
    Serial.println("PAPER_READY");
    Serial.flush();
}

void setup()
{
    Serial.setRxBufferSize(4096);
    Serial.begin(115200);
#if defined(ARDUINO_USB_CDC_ON_BOOT)
    Serial.setTxTimeoutMs(0);
#endif
    announceUsbReady();
    for (int i = 0; i < 25; i++) {
        pollSerialConfig();
        delay(20);
    }
    auto cfg = M5.config();
    cfg.clear_display = false;
    M5.begin(cfg);
    announceUsbReady();
    for (int i = 0; i < 50; i++) {
        pollSerialConfig();
        delay(20);
    }
    M5.Display.setRotation(0);
    M5.Display.setAutoDisplay(false);
    M5.BtnPWR.setHoldThresh(1500);
    M5.Speaker.setVolume(255);
    SPIFFS.begin(true);
    loadConfig();
    paperHwBegin();
    applyFrontlight(true);
    lastActivity = millis();
    lastLight = millis();
    screenLocked = false;
    menuOpen = true;
    if (currentPage == PAPERMONO_PAGE_HOME || !pageEnabled(currentPage)) {
        currentPage = firstEnabledPage();
    }
    if (wifiSsid.length()) {
        wifiStartHome();
        drawScreen(true);
    } else {
        drawSetup();
    }
    xTaskCreate(inputTask, "btns", 4096, nullptr, 4, nullptr);
}

void serviceTouchQueue()
{
    if (!touchQ) {
        return;
    }
    int tx = touchQX;
    int ty = touchQY;
    touchQ = 0;
    uint32_t now = millis();
    if (screenLocked) {
        lastLight = now;
        if (!lightOn) {
            applyFrontlight(true);
        }
        handleLockTouch(tx, ty);
        return;
    }
    noteActivity();
    if (tapOnPadlock(tx, ty)) {
        enterLock();
    } else if (menuOpen) {
        handleMenuTouch(tx, ty);
    } else if (tapOnMenuChip(tx, ty)) {
        openMenu();
    } else if (currentPage == PAPERMONO_PAGE_HOME) {
        int which = homeButtonAt(tx, ty);
        if (which == 1) {
            runCommand("stop");
        } else if (which == 2) {
            runCommand("return_to_dock");
        } else if (which == 3) {
            runCommand(state == "active" ? "pause" : "resume");
        } else if (which == 4) {
            lightsOn = !lightsOn;
            runCommand(lightsOn ? "lights_on" : "lights_off");
        }
    } else if (currentPage == PAPERMONO_PAGE_PLANS) {
        handlePlansTouch(tx, ty);
    } else if (currentPage == PAPERMONO_PAGE_HOUSE) {
        handleHouseTouch(tx, ty);
    } else if (currentPage == PAPERMONO_PAGE_NOTE) {
        handleNoteTouch(tx, ty);
    } else if (currentPage == PAPERMONO_PAGE_RADIO) {
        handleRadioTouch(tx, ty);
    } else if (currentPage == PAPERMONO_PAGE_DEVICE) {
        handleDeviceTouch(tx, ty);
    }
}

void loop()
{
    pollSerialConfig();
    if (cfgLeaveSetup && wifiSsid.length()) {
        cfgLeaveSetup = false;
        wifiStartHome();
        drawScreen(false);
    }
    if (wifiSsid.isEmpty()) {
        static uint32_t lastReady = 0;
        uint32_t readyNow = millis();
        if (readyNow - lastReady > 2000) {
            announceUsbReady();
            lastReady = readyNow;
        }
        delay(50);
        return;
    }
    loraService();
    rgbTick();
    refreshTabletPower(false);
    wifiService();
    if (WiFi.status() == WL_CONNECTED) {
        ensureNtp();
    }
    String prevClock = clockLocal;
    refreshLocalClock();

    String loraIn;
    if (loraTakeRx(loraIn)) {
        handleIncomingRadio(loraIn);
    }

    if (pwrOffEvent) {
        pwrOffEvent = 0;
        powerOffTablet();
    }

    uint32_t now = millis();
    serviceTouchQueue();

    now = millis();
    if (!otaBusy && !screenLocked && wifiUi == PAPERMONO_WIFI_IDLE && WiFi.status() == WL_CONNECTED
        && now - lastActivity > (uint32_t) lockAfterS * 1000) {
        enterLock();
    }
    if (screenLocked && lightOn && !unreadFrontlightHold()
        && now - lastLight > (uint32_t) lightOffS * 1000) {
        applyFrontlight(false);
    }

    flushPageButtons();

    if (prevClock != clockLocal) {
        drawScreen(false);
        flushPageButtons();
    }

    if (WiFi.status() != WL_CONNECTED) {
        delay(30);
        return;
    }

    if (now - lastPoll > PAPERMONO_POLL_MS) {
        lastPoll = now;
        httpGetStatus();
        if (currentPage == PAPERMONO_PAGE_PLANS) {
            httpGetPlans(false);
        }
        drawScreen(false);
        flushPageButtons();
    }
    delay(20);
}
