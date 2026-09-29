#include <Arduino.h>
#include <WiFi.h>
#include <WiFiClient.h>
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
String panelUrl;
String token;
String deviceName = "PaperMono";
String robotName = "";

uint32_t lastPoll = 0;
bool otaBusy = false;
bool otaTriedThisBoot = false;
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
uint32_t lastActivity = 0;
uint32_t lastLight = 0;
bool lightOn = true;
int lockAfterS = 60;
int lightOffS = 15;
int brightnessPct = 80;
String lockScreen = "both";
String unlockPage = "home";
bool alertMessageOn = true;
bool alertYarboOn = true;
bool alertLymowOn = true;
bool alertPowerwallOn = true;
bool yarboError = false;
bool lymowError = false;
bool powerwallError = false;
uint32_t lastErrorAlert = 0;
int tabletBat = -1;
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
String radioDraft = "";
int radioToIndex = 0;
String peerIds[PAPERMONO_PEER_MAX];
String peerNames[PAPERMONO_PEER_MAX];
int peerCount = 0;
String inboxFrom[PAPERMONO_INBOX_MAX];
String inboxText[PAPERMONO_INBOX_MAX];
String inboxIds[PAPERMONO_INBOX_MAX];
int inboxCount = 0;
String lastInboxId = "";
uint8_t radioSync = 0xA5;
volatile int pendingPageSteps = 0;
volatile uint8_t pwrOffEvent = 0;
volatile int touchQX = 0;
volatile int touchQY = 0;
volatile uint8_t touchQ = 0;

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
int homeOffset = 0;

void drawScreen(bool forceFull);
void enterLock();
void exitLock();
void noteActivity();
void nextPage();
void prevPage();
void drawPadlockIcon(int x, int y, int size, bool locked);
void drawWifiIcon(int cx, int cy, int size, bool connected);
void drawBatteryBadge(int right, int cy, int pct, bool compact);
bool takeTouchPress(int &x, int &y);
void applyFrontlight(bool on);
void ensureNtp();
void refreshLocalClock();
bool tapOnPadlock(int x, int y);
bool tapOnUnlock(int x, int y);
bool tapOnLockOff(int x, int y);
void powerOffTablet();
void finishEpdFrame();
void paintRadioDraft(int x, int y);
void updateRadioDraft();
void applyDeviceOffTap(int x);
void applyRadioHit(int hit);
bool httpGetPlans(bool refresh);

void saveConfig()
{
    prefs.begin("yarbo", false);
    prefs.putString("ssid", wifiSsid);
    prefs.putString("pass", wifiPass);
    prefs.putString("url", panelUrl);
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
    panelUrl = prefs.getString("url", "");
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
    token = doc["token"] | token;
    deviceName = doc["name"] | deviceName;
    applyCompanionFields(doc, false);
    panelUrl.replace(" ", "");
    while (panelUrl.endsWith("/")) {
        panelUrl.remove(panelUrl.length() - 1);
    }
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
                    WiFi.disconnect(true, false);
                    WiFi.begin(wifiSsid.c_str(), wifiPass.c_str());
                }
                applyFrontlight(lightOn);
            }
            line = "";
        } else if (c != '\r' && line.length() < 1600) {
            line += c;
        }
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
    while (M5.Display.displayBusy()) {
        pollSerialConfig();
        delay(5);
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
    if (page == PAPERMONO_PAGE_RADIO) return "RADIO";
    if (page == PAPERMONO_PAGE_DEVICE) return "DEVICE";
    if (page == PAPERMONO_PAGE_HOUSE) return "HOUSE";
    return "HOME";
}

String screenKey()
{
    return String(currentPage) + "|" + String(battery) + "|" + charging + "|" + state + "|" + head + "|"
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
        + String(inboxCount) + "|" + String(homeOn ? 1 : 0) + "|" + String(homeCount);
}

bool pageEnabled(int page)
{
    (void) page;
    return true;
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
    if (id == "status") return PAPERMONO_PAGE_STATUS;
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
    int count = PAPERMONO_PAGE_COUNT;
    int p = from;
    for (int i = 0; i < count; i++) {
        p = (p + dir + count) % count;
        if (pageEnabled(p)) return p;
    }
    return firstEnabledPage();
}

int noteChoiceCount()
{
    return 4;
}

const char *noteChoiceId(int i)
{
    if (i == 1) return "powerwall";
    if (i == 2) return "lymow";
    if (i == 3) return "batteries";
    return "yarbo";
}

const char *noteChoiceLabel(int i)
{
    if (i == 1) return "POWER";
    if (i == 2) return "LYMOW";
    if (i == 3) return "ALL";
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

void drawButton(int x, int y, int w, int h, const char *label, bool invert)
{
    uint16_t bg = invert ? TFT_BLACK : TFT_WHITE;
    uint16_t fg = invert ? TFT_WHITE : TFT_BLACK;
    M5.Display.fillRoundRect(x, y, w, h, 12, bg);
    M5.Display.drawRoundRect(x, y, w, h, 12, TFT_BLACK);
    M5.Display.setTextColor(fg, bg);
    M5.Display.setTextDatum(MC_DATUM);
    M5.Display.setTextSize(3);
    M5.Display.drawString(label, x + w / 2, y + h / 2);
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
    if (currentPage == PAPERMONO_PAGE_POWERWALL) return "POWERWALL";
    if (currentPage == PAPERMONO_PAGE_LYMOW) return "LYMOW";
    if (currentPage == PAPERMONO_PAGE_NOTE || currentPage == PAPERMONO_PAGE_BOARD) return "VESTABOARD";
    if (currentPage == PAPERMONO_PAGE_RADIO) return "RADIO";
    if (currentPage == PAPERMONO_PAGE_DEVICE) return "DEVICE";
    if (currentPage == PAPERMONO_PAGE_HOUSE) return "HOUSE";
    return "YARBO";
}

void drawHeader()
{
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(3);
    M5.Display.drawString(headerBrand(), 16, 12);
    String page = pageName(currentPage);
    if (page != headerBrand()) {
        M5.Display.setTextSize(2);
        M5.Display.drawString(page, 16, 52);
    }
    int W = M5.Display.width();
    int lockX = W - 88;
    drawPadlockIcon(lockX + 8, 10, 70, true);
    M5.Display.drawRoundRect(lockX, 4, 82, 82, 14, TFT_BLACK);
    int batRight = lockX - 8;
    int batCy = 22;
    drawBatteryBadge(batRight, batCy, tabletBat, true);
    int batLeft = batRight - 92 - 10;
    int batCx = batLeft + 46;
    drawWifiIcon(batCx, 64, 32, WiFi.status() == WL_CONNECTED);
    M5.Display.setTextDatum(TL_DATUM);
}

void drawPager()
{
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

void drawHousePage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    if (homeCount == 0) {
        M5.Display.setTextSize(2);
        M5.Display.drawString("Assign lights in", 16, 180);
        M5.Display.drawString("the panel Home page.", 16, 214);
    }
    int y0 = plansRowY0();
    int rh = plansRowH();
    int W = M5.Display.width();
    for (int i = 0; i < PAPERMONO_HOME_VISIBLE; i++) {
        int idx = homeOffset + i;
        if (idx >= homeCount) {
            break;
        }
        bool on = homeOnState[idx];
        int y = y0 + i * rh;
        uint16_t bg = on ? TFT_BLACK : TFT_WHITE;
        uint16_t fg = on ? TFT_WHITE : TFT_BLACK;
        M5.Display.fillRoundRect(16, y, W - 32, rh - 8, 10, bg);
        M5.Display.drawRoundRect(16, y, W - 32, rh - 8, 10, TFT_BLACK);
        M5.Display.setTextColor(fg, bg);
        M5.Display.setTextDatum(ML_DATUM);
        M5.Display.setTextSize(2);
        String label = homeNames[idx];
        if (homeKinds[idx] == "scene") {
            label = "*" + label;
        }
        /* Button is 16..(W-16); 16px inset each side so the name uses the full row. */
        label = clipLabelToWidth(label, W - 64);
        M5.Display.drawString(label, 32, y + (rh - 8) / 2);
    }
    if (homeCount > PAPERMONO_HOME_VISIBLE) {
        int bw, bh, gap, ignoreY;
        layoutButtons(bw, bh, gap, ignoreY);
        drawButton(16, plansStartY(), bw, 72, "MORE", false);
    }
    drawPager();
    finishEpdFrame();
}

void drawNotePage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(2);
    M5.Display.drawString("Vestaboard view", 16, 118);
    M5.Display.setTextSize(2);
    M5.Display.drawString("Tap a view.", 16, 160);
    if (!vestaboardOn) {
        M5.Display.drawString("Note off: lock screen still previews.", 16, 190);
    }
    if (lastError.length()) {
        M5.Display.drawString(lastError.substring(0, 28), 16, vestaboardOn ? 196 : 222);
    }
    int bw, bh, gap, y0;
    layoutButtons(bw, bh, gap, y0);
    int n = noteChoiceCount();
    for (int i = 0; i < n; i++) {
        bool left = (i % 2) == 0;
        int row = i / 2;
        int x = left ? 16 : 16 + bw + gap;
        int y = y0 + row * (bh + gap);
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

int brightnessValue()
{
    return map(constrain(brightnessPct, 0, 100), 0, 100, 0, 255);
}

void applyFrontlight(bool on)
{
    lightOn = on;
    paperSetFrontlight(on ? (uint8_t) brightnessValue() : 0);
}

void powerOffTablet()
{
    rgbOff();
    applyFrontlight(false);
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
    M5.Display.fillRect(cx - size - 6, yDot + 1, size * 2 + 12, size + 10, TFT_WHITE);
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
    M5.Display.setTextColor(TFT_BLACK);
    M5.Display.drawString(s, x + w / 2, y + h / 2);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
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

int pngFileWidth(const char *path)
{
    File f = SPIFFS.open(path, FILE_READ);
    if (!f || f.size() < 24) {
        if (f) {
            f.close();
        }
        return PAPERMONO_LOGO_PX;
    }
    uint8_t hdr[24];
    int n = f.read(hdr, 24);
    f.close();
    if (n != 24 || hdr[0] != 0x89 || hdr[1] != 'P' || hdr[2] != 'N' || hdr[3] != 'G') {
        return PAPERMONO_LOGO_PX;
    }
    int w = ((int) hdr[16] << 24) | ((int) hdr[17] << 16) | ((int) hdr[18] << 8) | (int) hdr[19];
    if (w < 8 || w > 1024) {
        return PAPERMONO_LOGO_PX;
    }
    return w;
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
        M5.Display.setTextSize(2);
        M5.Display.drawString(String(unreadCount) + " MSG", W / 2, 188);
    }
    const int batCy = 210;
    const int batH = 72;
    int batRight = W / 2 + 90;
    int batLeft = batRight - 160 - 16;
    int wifiCx = batLeft / 2;
    drawWifiIcon(wifiCx, batCy, 56, WiFi.status() == WL_CONNECTED);
    drawBatteryBadge(batRight, batCy, tabletBat, false);
    int batBottom = batCy + batH / 2;

    bool wantBoard = lockScreen == "vestaboard" || lockScreen == "both";
    bool wantLogo = lockScreen == "logo" || lockScreen == "both";
    bool haveLogo = SPIFFS.exists("/logo.png");
    bool showBoard = wantBoard && (!vestaboardKnown || vestaboardOn);
    bool logoDrawn = false;
    int logoY = batBottom + 20;
    int logoSize = showBoard ? 200 : 232;
    if (wantLogo && haveLogo) {
        int srcW = pngFileWidth("/logo.png");
        float sc = (float) logoSize / (float) srcW;
        int logoX = (W - logoSize) / 2;
        logoDrawn = M5.Display.drawPngFile(SPIFFS, "/logo.png", logoX, logoY, 0, 0, 0, 0, sc, sc);
    } else if (wantLogo && !showBoard) {
        M5.Display.setTextSize(2);
        M5.Display.drawString("Logo after site Wi-Fi", W / 2, logoY + 40);
    }
    if (showBoard) {
        int cell = logoDrawn ? 24 : 30;
        int gap = 2;
        int gridW = 15 * cell + 14 * gap;
        int gridY = logoDrawn ? (logoY + logoSize + 28) : 280;
        drawVestaboardGrid((W - gridW) / 2, gridY, cell, gap);
    }

    int unlockW = 280;
    int offW = 140;
    int btnH = 110;
    int gap = 16;
    int bx = (W - (unlockW + gap + offW)) / 2;
    int by = H - 156;
    drawPadlockIcon(bx + 16, by + 18, 74, false);
    M5.Display.drawRoundRect(bx, by, unlockW, btnH, 18, TFT_BLACK);
    M5.Display.setTextDatum(ML_DATUM);
    M5.Display.setTextSize(3);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.drawString("Unlock", bx + 110, by + btnH / 2);
    M5.Display.fillRoundRect(bx + unlockW + gap, by, offW, btnH, 18, TFT_BLACK);
    M5.Display.setTextDatum(MC_DATUM);
    M5.Display.setTextColor(TFT_WHITE, TFT_BLACK);
    M5.Display.setTextSize(3);
    M5.Display.drawString("OFF", bx + unlockW + gap + offW / 2, by + btnH / 2);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    finishEpdFrame();
}

void enterLock()
{
    screenLocked = true;
    offConfirm = false;
    lastLight = millis();
    applyFrontlight(true);
    drawLockScreen(true);
    lastDrawnKey = screenKey();
}

void exitLock()
{
    screenLocked = false;
    applyUnlockPage();
    noteActivity();
    applyFrontlight(true);
    if (currentPage == PAPERMONO_PAGE_PLANS && !plansLoaded) {
        httpGetPlans(false);
    }
    drawScreen(true);
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
    if (row == 0) return "QWERTYUIOP";
    if (row == 1) return "ASDFGHJKL";
    return "ZXCVBNM";
}

void drawKeyboard(int y0)
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
    drawButton(8, y, 100, ah, kbNumbers ? "ABC" : "123", false);
    drawButton(116, y, 160, ah, "SPACE", false);
    drawButton(284, y, 90, ah, "DEL", false);
    drawButton(382, y, 90, ah, "SEND", true);
}

void drawRadioPage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(2);
    String path = loraReady() ? "LoRa + Wi-Fi" : "Wi-Fi only";
    M5.Display.drawString(path, 16, 100);
    M5.Display.setTextSize(3);
    String toLabel = "ALL";
    if (radioToIndex > 0 && radioToIndex <= peerCount) {
        toLabel = peerNames[radioToIndex - 1];
    }
    M5.Display.drawString("To  " + toLabel, 16, 126);
    paintRadioDraft(16, PAPERMONO_DRAFT_Y);
    int n = min(4, peerCount + 1);
    int pw = (M5.Display.width() - 24) / n;
    for (int i = 0; i < n; i++) {
        const char *lab = i == 0 ? "ALL" : peerNames[i - 1].c_str();
        bool on = radioToIndex == i;
        drawButton(12 + i * pw, PAPERMONO_PEER_Y, pw - 8, 56, lab, on);
    }
    drawKeyboard(PAPERMONO_KB_Y0);
    drawPager();
    finishEpdFrame();
}

void drawDevicePage(bool forceFull)
{
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
    if (screenLocked) {
        String key = screenKey();
        if (!forceFull && key == lastDrawnKey) {
            return;
        }
        drawLockScreen(forceFull);
        lastDrawnKey = screenKey();
        return;
    }
    String key = screenKey();
    if (!forceFull && key == lastDrawnKey) {
        return;
    }
    if (!pageEnabled(currentPage)) {
        currentPage = firstEnabledPage();
    }
    if (currentPage == PAPERMONO_PAGE_STATUS) {
        drawStatusPage(forceFull);
    } else if (currentPage == PAPERMONO_PAGE_HEALTH) {
        drawHealthPage(forceFull);
    } else if (currentPage == PAPERMONO_PAGE_PLANS) {
        drawPlansPage(forceFull);
    } else if (currentPage == PAPERMONO_PAGE_NOTE) {
        if (!pageEnabled(PAPERMONO_PAGE_NOTE)) {
            currentPage = firstEnabledPage();
            drawHome(forceFull);
        } else {
            drawNotePage(forceFull);
        }
    } else if (currentPage == PAPERMONO_PAGE_BOARD) {
        if (!pageEnabled(PAPERMONO_PAGE_BOARD)) {
            currentPage = firstEnabledPage();
            drawHome(forceFull);
        } else {
            drawBoardPage(forceFull);
        }
    } else if (currentPage == PAPERMONO_PAGE_POWERWALL) {
        if (!pageEnabled(PAPERMONO_PAGE_POWERWALL)) {
            currentPage = firstEnabledPage();
            drawScreen(forceFull);
            return;
        }
        drawPowerwallPage(forceFull);
    } else if (currentPage == PAPERMONO_PAGE_LYMOW) {
        if (!pageEnabled(PAPERMONO_PAGE_LYMOW)) {
            currentPage = firstEnabledPage();
            drawHome(forceFull);
        } else {
            drawLymowPage(forceFull);
        }
    } else if (currentPage == PAPERMONO_PAGE_RADIO) {
        drawRadioPage(forceFull);
    } else if (currentPage == PAPERMONO_PAGE_DEVICE) {
        drawDevicePage(forceFull);
    } else if (currentPage == PAPERMONO_PAGE_HOUSE) {
        if (!pageEnabled(PAPERMONO_PAGE_HOUSE)) {
            currentPage = firstEnabledPage();
            drawScreen(forceFull);
            return;
        }
        drawHousePage(forceFull);
    } else {
        drawHome(forceFull);
    }
    lastDrawnKey = screenKey();
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
    return x >= W - 120 && y >= 0 && y <= 120;
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
    http.begin(panelUrl + "/api/device.php?action=logo");
    http.addHeader("X-PaperMono-Token", token);
    http.setTimeout(20000);
    int code = http.GET();
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

void pushInbox(const String &id, const String &from, const String &text, bool alert)
{
    if (id.length() && lastInboxId == id) {
        return;
    }
    for (int i = 0; i < inboxCount; i++) {
        if (id.length() && inboxIds[i] == id) {
            return;
        }
    }
    if (inboxCount >= PAPERMONO_INBOX_MAX) {
        for (int i = 1; i < PAPERMONO_INBOX_MAX; i++) {
            inboxIds[i - 1] = inboxIds[i];
            inboxFrom[i - 1] = inboxFrom[i];
            inboxText[i - 1] = inboxText[i];
        }
        inboxCount = PAPERMONO_INBOX_MAX - 1;
    }
    inboxIds[inboxCount] = id;
    inboxFrom[inboxCount] = from;
    inboxText[inboxCount] = text;
    inboxCount++;
    if (id.length()) {
        lastInboxId = id;
    }
    unreadCount++;
    if (alert && alertMessageOn) {
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
        for (JsonVariant item : msgs) {
            JsonObject m = item.as<JsonObject>();
            if (m.isNull()) continue;
            String id = String((const char *) (m["id"] | ""));
            String from = String((const char *) (m["from_name"] | "tablet"));
            String text = String((const char *) (m["text"] | ""));
            if (text.length()) {
                bool primed = lastInboxId.length() > 0;
                pushInbox(id, from, text, primed);
            }
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
    if (WiFi.status() != WL_CONNECTED || panelUrl.isEmpty() || token.isEmpty()) {
        return false;
    }
    HTTPClient http;
    http.begin(panelUrl + "/api/device.php");
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-PaperMono-Token", token);
    JsonDocument doc;
    doc["action"] = "paper_message";
    doc["token"] = token;
    doc["to"] = to;
    doc["text"] = text;
    String payload;
    serializeJson(doc, payload);
    int code = http.POST(payload);
    String body = http.getString();
    http.end();
    JsonDocument res;
    deserializeJson(res, body);
    return code == 200 && res["ok"];
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
        pushInbox(id, from, text, true);
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
    JsonDocument doc;
    doc["from"] = deviceId;
    doc["from_name"] = deviceName;
    doc["to"] = to;
    doc["to_name"] = toName;
    doc["text"] = radioDraft;
    doc["id"] = String((uint32_t) millis(), HEX);
    String payload;
    serializeJson(doc, payload);
    bool ok = loraReady() && loraSendText(payload);
    if (!ok) {
        ok = postPaperMessage(to, radioDraft);
    }
    if (ok) {
        lastError = "";
        radioDraft = "";
    } else {
        lastError = "send failed";
    }
    drawScreen(false);
    return ok;
}

int keyboardHit(int x, int y)
{
    int y0 = PAPERMONO_KB_Y0;
    int rowH = PAPERMONO_KB_ROW;
    int actionH = PAPERMONO_KB_ACTION;
    if (y < y0 || y > y0 + 3 * rowH + actionH) {
        return -1;
    }
    if (y >= y0 + 3 * rowH) {
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
    int hit = keyboardHit(x, y);
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

void handleDeviceTouch(int x, int y)
{
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
    if (tapOnUnlock(x, y)) {
        unreadCount = 0;
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
    if (otaBusy || WiFi.status() != WL_CONNECTED || panelUrl.isEmpty() || token.isEmpty()) {
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
    String url = panelUrl + "/api/device.php?action=firmware&token=" + token;
    WiFiClient client;
    t_httpUpdate_return ret = updater.update(client, url);
    otaBusy = false;
    rgbOff();
    if (ret != HTTP_UPDATE_OK) {
        lastError = "update failed";
        drawScreen(false);
    }
}

bool httpGetStatus()
{
    if (WiFi.status() != WL_CONNECTED || panelUrl.isEmpty() || token.isEmpty()) {
        return false;
    }
    HTTPClient http;
    String url = panelUrl + "/api/device.php?action=compact&fw=" + String(PAPERMONO_FW_VERSION);
    http.begin(url);
    http.addHeader("X-PaperMono-Token", token);
    http.setTimeout(8000);
    int code = http.GET();
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
    if (homeOffset >= homeCount) {
        homeOffset = 0;
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
    if (WiFi.status() != WL_CONNECTED || panelUrl.isEmpty() || token.isEmpty()) {
        return false;
    }
    HTTPClient http;
    String url = panelUrl + "/api/device.php?action=plans&fw=" + String(PAPERMONO_FW_VERSION);
    if (refresh) {
        url += "&refresh=1";
    }
    http.begin(url);
    http.addHeader("X-PaperMono-Token", token);
    http.setTimeout(20000);
    int code = http.GET();
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
    HTTPClient http;
    http.begin(panelUrl + "/api/device.php");
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-PaperMono-Token", token);
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
    int code = http.POST(payload);
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
    if (!pageEnabled(page)) {
        page = stepEnabledPage(page, 1);
    }
    currentPage = page;
    offConfirm = false;
    noteActivity();
    if (currentPage == PAPERMONO_PAGE_PLANS && loadPlansIfNeeded && !plansLoaded) {
        httpGetPlans(false);
    }
    drawScreen(true);
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
    offConfirm = false;
    if (currentPage == PAPERMONO_PAGE_PLANS && !plansLoaded) {
        httpGetPlans(false);
    }
    return true;
}

void flushPageButtons()
{
    if (applyPendingPages()) {
        drawScreen(true);
        if (applyPendingPages()) {
            drawScreen(true);
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
    int y0 = plansRowY0();
    int rh = plansRowH();
    int startY = plansStartY();
    if (homeCount > PAPERMONO_HOME_VISIBLE && y >= startY && y <= startY + 72) {
        homeOffset += PAPERMONO_HOME_VISIBLE;
        if (homeOffset >= homeCount) {
            homeOffset = 0;
        }
        drawScreen(false);
        return;
    }
    if (y >= y0 && y < startY) {
        int row = (y - y0) / rh;
        int idx = homeOffset + row;
        if (idx >= 0 && idx < homeCount && row < PAPERMONO_HOME_VISIBLE) {
            const char *cmd = homeKinds[idx] == "scene" ? "home_scene" : "home_toggle";
            httpCommand(cmd, nullptr, nullptr, homeIds[idx].c_str());
            httpGetStatus();
            drawScreen(false);
        }
        return;
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
    announceUsbReady();
    for (int i = 0; i < 25; i++) {
        pollSerialConfig();
        delay(20);
    }
    auto cfg = M5.config();
    cfg.clear_display = false;
    M5.begin(cfg);
    M5.Display.setRotation(0);
    M5.Display.setAutoDisplay(false);
    M5.BtnPWR.setHoldThresh(1500);
    M5.Speaker.begin();
    SPIFFS.begin(true);
    loadConfig();
    paperHwBegin();
    applyFrontlight(true);
    lastActivity = millis();
    lastLight = millis();
    if (wifiSsid.length()) {
        WiFi.mode(WIFI_STA);
        WiFi.begin(wifiSsid.c_str(), wifiPass.c_str());
        drawScreen(false);
    } else {
        drawSetup();
    }
    xTaskCreate(inputTask, "btns", 4096, nullptr, 4, nullptr);
}

void loop()
{
    pollSerialConfig();
    if (cfgLeaveSetup && wifiSsid.length()) {
        cfgLeaveSetup = false;
        WiFi.mode(WIFI_STA);
        WiFi.begin(wifiSsid.c_str(), wifiPass.c_str());
        drawScreen(true);
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
    tabletBat = M5.Power.getBatteryLevel();
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
    if (!otaBusy && !screenLocked && WiFi.status() == WL_CONNECTED
        && now - lastActivity > (uint32_t) lockAfterS * 1000) {
        enterLock();
    }
    if (screenLocked && lightOn && now - lastLight > (uint32_t) lightOffS * 1000) {
        applyFrontlight(false);
    }

    int tx = 0;
    int ty = 0;
    if (touchQ) {
        tx = touchQX;
        ty = touchQY;
        touchQ = 0;
        if (screenLocked) {
            handleLockTouch(tx, ty);
        } else {
            noteActivity();
            if (tapOnPadlock(tx, ty)) {
                enterLock();
            } else {
                if (currentPage == PAPERMONO_PAGE_HOME) {
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
        }
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
