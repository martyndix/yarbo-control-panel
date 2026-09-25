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

// M5Stack PaperMono SKU C153 (https://docs.m5stack.com/en/core/PaperMono)
// ESP32-S3R8, SSD1677 480x800 4-level gray, FT6336G touch. Not PaperMono-Lite.
// Manufacturer e-paper rules we follow:
// - After ~10 partial (fast) refreshes, run one full-screen refresh to clear ghosting.
// - Do not stream uninterrupted partial refreshes (DC imbalance can damage the panel).
// - Skip redraws when status has not changed.
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
bool vestaboardOn = false;
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
String lockScreen = "logo";
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
String deviceId = "";
int vestaboardCodes[3][15];
String vestaboardLines[3];
String vestaboardHash = "";
int unreadCount = 0;
int unlockStep = 0;
uint32_t unlockStepAt = 0;
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

String planIds[PAPERMONO_PLAN_MAX];
String planNames[PAPERMONO_PLAN_MAX];
int planCount = 0;
int planOffset = 0;
int selectedPlan = -1;
String plansNote = "";
bool plansLoaded = false;

void drawScreen(bool forceFull);
void enterLock();
void exitLock();
void noteActivity();
void nextPage();
void prevPage();

void saveConfig()
{
    prefs.begin("yarbo", false);
    prefs.putString("ssid", wifiSsid);
    prefs.putString("pass", wifiPass);
    prefs.putString("url", panelUrl);
    prefs.putString("token", token);
    prefs.putString("name", deviceName);
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
    prefs.end();
}

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
    panelUrl.replace(" ", "");
    while (panelUrl.endsWith("/")) {
        panelUrl.remove(panelUrl.length() - 1);
    }
    saveConfig();
    Serial.println("CFG_OK");
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
            }
            line = "";
        } else if (c != '\r' && line.length() < 800) {
            line += c;
        }
    }
}

void beginEpdFrame(bool forceFull)
{
    bool full = forceFull || partialRefreshCount >= 10;
    M5.Display.setEpdMode(full ? epd_mode_t::epd_quality : epd_mode_t::epd_fastest);
    partialRefreshCount = full ? 0 : (partialRefreshCount + 1);
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
        + String(inboxCount);
}

bool pageEnabled(int page)
{
    if (page == PAPERMONO_PAGE_HOME || page == PAPERMONO_PAGE_STATUS
        || page == PAPERMONO_PAGE_HEALTH || page == PAPERMONO_PAGE_PLANS) {
        return yarboOn;
    }
    if (page == PAPERMONO_PAGE_NOTE) return vestaboardOn;
    if (page == PAPERMONO_PAGE_BOARD) return vestaboardOn;
    if (page == PAPERMONO_PAGE_POWERWALL) return powerwallOn;
    if (page == PAPERMONO_PAGE_LYMOW) return lymowOn;
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

int stepEnabledPage(int from, int dir)
{
    int p = from;
    for (int i = 0; i < PAPERMONO_PAGE_COUNT; i++) {
        p += dir;
        if (p < 0) p = PAPERMONO_PAGE_COUNT - 1;
        p = p % PAPERMONO_PAGE_COUNT;
        if (pageEnabled(p)) return p;
    }
    return firstEnabledPage();
}

int noteChoiceCount()
{
    int n = 0;
    if (yarboOn) n++;
    if (powerwallOn) n++;
    if (lymowOn) n++;
    if ((int) yarboOn + (int) powerwallOn + (int) lymowOn >= 2) n++;
    return n > 0 ? n : 1;
}

const char *noteChoiceId(int i)
{
    const char *ids[4];
    int n = 0;
    if (yarboOn) ids[n++] = "yarbo";
    if (powerwallOn) ids[n++] = "powerwall";
    if (lymowOn) ids[n++] = "lymow";
    if ((int) yarboOn + (int) powerwallOn + (int) lymowOn >= 2) ids[n++] = "batteries";
    if (n == 0) return "yarbo";
    if (i < 0 || i >= n) return ids[0];
    return ids[i];
}

const char *noteChoiceLabel(int i)
{
    const char *labels[4];
    int n = 0;
    if (yarboOn) labels[n++] = "YARBO";
    if (powerwallOn) labels[n++] = "WALL";
    if (lymowOn) labels[n++] = "LYMOW";
    if ((int) yarboOn + (int) powerwallOn + (int) lymowOn >= 2) labels[n++] = "ALL";
    if (n == 0) return "YARBO";
    if (i < 0 || i >= n) return labels[0];
    return labels[i];
}

void drawButton(int x, int y, int w, int h, const char *label, bool invert)
{
    uint16_t bg = invert ? TFT_BLACK : TFT_WHITE;
    uint16_t fg = invert ? TFT_WHITE : TFT_BLACK;
    M5.Display.fillRoundRect(x, y, w, h, 12, bg);
    M5.Display.drawRoundRect(x, y, w, h, 12, TFT_BLACK);
    M5.Display.setTextColor(fg, bg);
    M5.Display.setTextDatum(MC_DATUM);
    M5.Display.setTextSize(2);
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

String headerDeviceName()
{
    if (currentPage == PAPERMONO_PAGE_LYMOW) {
        return lymowName.length() ? lymowName : String("Lymow");
    }
    if (currentPage == PAPERMONO_PAGE_NOTE) {
        return deviceName;
    }
    if (currentPage == PAPERMONO_PAGE_BOARD || currentPage == PAPERMONO_PAGE_POWERWALL
        || currentPage == PAPERMONO_PAGE_RADIO || currentPage == PAPERMONO_PAGE_DEVICE) {
        return deviceName;
    }
    return robotName.length() ? robotName : deviceName;
}

String headerBrand()
{
    if (currentPage == PAPERMONO_PAGE_POWERWALL) return "POWERWALL";
    if (currentPage == PAPERMONO_PAGE_LYMOW) return "LYMOW";
    if (currentPage == PAPERMONO_PAGE_NOTE || currentPage == PAPERMONO_PAGE_BOARD) return "VESTABOARD";
    if (currentPage == PAPERMONO_PAGE_RADIO) return "RADIO";
    if (currentPage == PAPERMONO_PAGE_DEVICE) return "DEVICE";
    return "YARBO";
}

void drawHeader()
{
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(2);
    M5.Display.drawString(headerBrand(), 16, 16);
    M5.Display.setTextSize(1);
    M5.Display.drawString(headerDeviceName() + "  " + String(PAPERMONO_FW_VERSION), 16, 48);
    String page = pageName(currentPage);
    if (page != headerBrand()) {
        M5.Display.setTextSize(2);
        M5.Display.drawString(page, 16, 72);
    }
    M5.Display.setTextSize(1);
    M5.Display.setTextDatum(TR_DATUM);
    String bat = tabletBat >= 0 ? (String(tabletBat) + "%") : String("--");
    M5.Display.drawString("TAB " + bat, M5.Display.width() - 16, 48);
    M5.Display.setTextDatum(TL_DATUM);
    int logoSize = 180;
    if (SPIFFS.exists("/logo.png")) {
        M5.Display.drawPngFile(SPIFFS, "/logo.png", M5.Display.width() - logoSize - 16, 16, logoSize, logoSize);
    }
}

void drawPager()
{
    int H = M5.Display.height();
    int W = M5.Display.width();
    const char *labels[PAPERMONO_PAGE_COUNT] = {
        "HOME", "STATUS", "HEALTH", "PLANS", "NOTE", "BOARD", "WALL", "LYMOW", "RADIO", "DEVICE"
    };
    M5.Display.setTextDatum(TC_DATUM);
    M5.Display.setTextSize(1);
    int n = visiblePageCount();
    int slot = W / n;
    int drawn = 0;
    for (int i = 0; i < PAPERMONO_PAGE_COUNT; i++) {
        if (!pageEnabled(i)) continue;
        int x = slot * drawn + slot / 2;
        if (i == currentPage) {
            M5.Display.setTextColor(TFT_WHITE, TFT_BLACK);
            M5.Display.fillRect(slot * drawn + 8, H - 36, slot - 16, 18, TFT_BLACK);
        } else {
            M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
        }
        M5.Display.drawString(labels[i], x, H - 33);
        drawn++;
    }
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(BL_DATUM);
    String wifi = WiFi.status() == WL_CONNECTED ? WiFi.localIP().toString() : "Wi-Fi: waiting";
    M5.Display.drawString(wifi, 16, H - 8);
    M5.Display.setTextDatum(BR_DATUM);
    M5.Display.drawString("keys · pages", W - 16, H - 8);
}

void drawKv(const char *label, const String &value, int y)
{
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(2);
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

    M5.Display.setTextSize(2);
    M5.Display.drawString("Charging  " + charging, 16, 210);
    M5.Display.drawString("State     " + state, 16, 250);
    M5.Display.drawString("Head      " + head, 16, 290);
    M5.Display.drawString("Error     " + String(errorCode), 16, 330);
    if (lastError.length()) {
        M5.Display.setTextSize(1);
        M5.Display.drawString(lastError.substring(0, 40), 16, 372);
    }

    int bw, bh, gap, y0;
    layoutButtons(bw, bh, gap, y0);
    drawButton(16, y0, bw, bh, "STOP", true);
    drawButton(16 + bw + gap, y0, bw, bh, "DOCK", false);
    drawButton(16, y0 + bh + gap, bw, bh, state == "active" ? "PAUSE" : "RESUME", false);
    drawButton(16 + bw + gap, y0 + bh + gap, bw, bh, lightsOn ? "LIGHTS OFF" : "LIGHTS", false);
    drawPager();
    M5.Display.display();
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
        M5.Display.setTextSize(1);
        M5.Display.drawString(lastError.substring(0, 40), 16, 468);
    }
    drawPager();
    M5.Display.display();
}

void drawHealthPage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    int y = 112;
    const int step = 42;
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
    M5.Display.display();
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
    M5.Display.setTextSize(1);
    M5.Display.drawString(planActivity.length() ? planActivity : "idle", 16, 108);
    if (plansNote.length() && planCount == 0) {
        M5.Display.setTextSize(2);
        M5.Display.drawString(plansNote.substring(0, 22), 16, 180);
        M5.Display.setTextSize(1);
        if (plansNote.length() > 22) {
            M5.Display.drawString(plansNote.substring(22, 48), 16, 214);
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
    M5.Display.display();
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
    M5.Display.setTextSize(1);
    if (!vestaboardOn) {
        M5.Display.drawString("Enable the Note in panel Settings.", 16, 156);
    } else {
        M5.Display.drawString("Tap a view.", 16, 156);
    }
    if (lastError.length()) {
        M5.Display.drawString(lastError.substring(0, 40), 16, 188);
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
    M5.Display.display();
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
        M5.Display.setTextSize(1);
        M5.Display.drawString(lastError.substring(0, 40), 16, 320);
    }
    drawPager();
    M5.Display.display();
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
        M5.Display.setTextSize(1);
        M5.Display.drawString(lastError.substring(0, 40), 16, 320);
    }
    drawPager();
    M5.Display.display();
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
    M5.Display.setTextSize(1);
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
    M5.Display.setBrightness(on ? brightnessValue() : 0);
}

void noteActivity()
{
    lastActivity = millis();
    lastLight = millis();
    if (!lightOn) {
        applyFrontlight(true);
    }
}

void drawLockScreen(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TC_DATUM);
    M5.Display.setTextSize(2);
    M5.Display.drawString(deviceName.length() ? deviceName : String("PaperMono"), M5.Display.width() / 2, 18);
    M5.Display.setTextSize(3);
    M5.Display.drawString(clockLocal.length() ? clockLocal : String("--:--"), M5.Display.width() / 2, 52);
    M5.Display.setTextSize(1);
    String bat = tabletBat >= 0 ? (String("TAB ") + tabletBat + "%") : String("TAB --");
    if (unreadCount > 0) {
        bat += "  ·  " + String(unreadCount) + " msg";
    }
    M5.Display.drawString(bat, M5.Display.width() / 2, 92);

    bool showLogo = lockScreen != "vestaboard" || !vestaboardOn;
    bool showBoard = vestaboardOn && (lockScreen == "vestaboard" || lockScreen == "both");
    if (lockScreen == "logo" || !vestaboardOn) {
        showLogo = true;
        showBoard = false;
    }
    int W = M5.Display.width();
    int H = M5.Display.height();
    if (showLogo && SPIFFS.exists("/logo.png")) {
        int logoSize = showBoard ? 160 : 280;
        M5.Display.drawPngFile(SPIFFS, "/logo.png", (W - logoSize) / 2, showBoard ? 118 : 150, logoSize, logoSize);
    }
    if (showBoard) {
        int cell = showLogo ? 22 : 28;
        int gap = 3;
        int gridW = 15 * cell + 14 * gap;
        int gridY = showLogo ? 300 : 180;
        drawVestaboardGrid((W - gridW) / 2, gridY, cell, gap);
    }

    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(2);
    M5.Display.drawString("1", 18, 18);
    M5.Display.setTextDatum(BR_DATUM);
    M5.Display.drawString("2", W - 18, H - 18);
    M5.Display.setTextDatum(BC_DATUM);
    M5.Display.setTextSize(1);
    M5.Display.drawString("opposite corners to unlock", W / 2, H - 8);
    M5.Display.display();
}

void enterLock()
{
    screenLocked = true;
    unlockStep = 0;
    offConfirm = false;
    applyFrontlight(false);
    drawLockScreen(true);
    lastDrawnKey = screenKey();
}

void exitLock()
{
    screenLocked = false;
    unlockStep = 0;
    noteActivity();
    applyFrontlight(true);
    drawScreen(true);
}

void drawBoardPage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    int cell = 26;
    int gap = 4;
    int gridW = 15 * cell + 14 * gap;
    drawVestaboardGrid((M5.Display.width() - gridW) / 2, 130, cell, gap);
    M5.Display.setTextDatum(TC_DATUM);
    M5.Display.setTextSize(1);
    M5.Display.drawString("live Vestaboard", M5.Display.width() / 2, 250);
    drawPager();
    M5.Display.display();
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
    for (int r = 0; r < 3; r++) {
        const char *row = kbRow(r);
        int n = strlen(row);
        int keyW = (W - 20) / n;
        int y = y0 + r * 42;
        for (int i = 0; i < n; i++) {
            int x = 10 + i * keyW;
            M5.Display.drawRect(x, y, keyW - 4, 38, TFT_BLACK);
            M5.Display.setTextDatum(MC_DATUM);
            M5.Display.setTextSize(1);
            String s;
            s += row[i];
            M5.Display.drawString(s, x + (keyW - 4) / 2, y + 19);
        }
    }
    int y = y0 + 3 * 42;
    drawButton(16, y, 90, 44, kbNumbers ? "ABC" : "123", false);
    drawButton(114, y, 180, 44, "SPACE", false);
    drawButton(302, y, 70, 44, "DEL", false);
    drawButton(380, y, 84, 44, "SEND", true);
}

void drawRadioPage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(1);
    String path = loraReady() ? "LoRa + Wi-Fi fallback" : "Wi-Fi only";
    M5.Display.drawString(path, 16, 108);
    M5.Display.setTextSize(2);
    String toLabel = "ALL";
    if (radioToIndex > 0 && radioToIndex <= peerCount) {
        toLabel = peerNames[radioToIndex - 1];
    }
    M5.Display.drawString("To  " + toLabel, 16, 128);
    M5.Display.setTextSize(1);
    int y = 158;
    int shown = min(3, inboxCount);
    for (int i = inboxCount - shown; i < inboxCount; i++) {
        if (i < 0) continue;
        String line = inboxFrom[i] + ": " + inboxText[i];
        if (line.length() > 42) line = line.substring(0, 42);
        M5.Display.drawString(line, 16, y);
        y += 18;
    }
    M5.Display.setTextSize(1);
    String draft = radioDraft.length() ? radioDraft : String("(type a message)");
    if (draft.length() > 42) draft = draft.substring(draft.length() - 42);
    M5.Display.drawString(draft, 16, 220);
    M5.Display.drawString(String(radioDraft.length()) + "/" + String(PAPERMONO_MSG_CHARS), 16, 238);
    int n = min(4, peerCount + 1);
    int pw = (M5.Display.width() - 24) / n;
    for (int i = 0; i < n; i++) {
        const char *lab = i == 0 ? "ALL" : peerNames[i - 1].c_str();
        bool on = radioToIndex == i;
        drawButton(12 + i * pw, 258, pw - 8, 40, lab, on);
    }
    drawKeyboard(310);
    drawPager();
    M5.Display.display();
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
    M5.Display.setTextSize(2);
    M5.Display.drawString(clockLocal.length() ? clockLocal : String("--:--"), 16, 210);
    M5.Display.setTextSize(1);
    M5.Display.drawString(clockDate, 16, 250);
    M5.Display.drawString("Tablet battery  ·  " + deviceName, 16, 280);
    if (offConfirm) {
        drawButton(16, 360, 208, 88, "CANCEL", false);
        drawButton(248, 360, 208, 88, "OFF NOW", true);
        M5.Display.setTextDatum(TL_DATUM);
        M5.Display.setTextSize(1);
        M5.Display.drawString("Power off this tablet?", 16, 330);
    } else {
        drawButton(16, 360, 440, 88, "OFF", true);
        M5.Display.setTextDatum(TL_DATUM);
        M5.Display.setTextSize(1);
        M5.Display.drawString("Full power off. Side button turns it on.", 16, 330);
    }
    drawPager();
    M5.Display.display();
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
    M5.Display.setTextSize(1);
    M5.Display.drawString("1. Plug USB into the computer", 16, 140);
    M5.Display.drawString("   running this Yarbo panel.", 16, 162);
    M5.Display.drawString("2. Open Settings, then", 16, 198);
    M5.Display.drawString("   PaperMono companion.", 16, 220);
    M5.Display.drawString("3. Flash firmware and send", 16, 256);
    M5.Display.drawString("   2.4 GHz Wi-Fi from that page.", 16, 278);
    M5.Display.drawString("Keep this cable connected", 16, 330);
    M5.Display.drawString("until CFG_OK.", 16, 352);
    M5.Display.display();
    lastDrawnKey = "setup";
}

int homeButtonAt(int x, int y)
{
    int bw, bh, gap, y0;
    layoutButtons(bw, bh, gap, y0);
    if (y < y0) {
        return 0;
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
    return y >= M5.Display.height() - 48;
}

bool syncPaperLogo(const String &hash)
{
    if (hash.length() == 0) {
        if (SPIFFS.exists("/logo.png")) {
            SPIFFS.remove("/logo.png");
        }
        logoHash = "";
        return true;
    }
    if (hash == logoHash && SPIFFS.exists("/logo.png")) {
        return true;
    }
    HTTPClient http;
    http.begin(panelUrl + "/api/device.php?action=logo");
    http.addHeader("X-PaperMono-Token", token);
    http.setTimeout(12000);
    int code = http.GET();
    if (code != 200) {
        http.end();
        return false;
    }
    int len = http.getSize();
    File f = SPIFFS.open("/logo.png", FILE_WRITE);
    if (!f) {
        http.end();
        return false;
    }
    WiFiClient *stream = http.getStreamPtr();
    uint8_t buf[1024];
    int written = 0;
    unsigned long start = millis();
    while (http.connected() && (len < 0 || written < len) && millis() - start < 12000) {
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
    if (written < 8) {
        SPIFFS.remove("/logo.png");
        return false;
    }
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
    if (newName.length()) {
        deviceName = newName;
    }
    deviceId = doc["device_id"] | deviceId;
    lockAfterS = doc["lock_after_s"] | lockAfterS;
    lightOffS = doc["light_off_s"] | lightOffS;
    int b = doc["brightness"] | brightnessPct;
    if (b != brightnessPct) {
        brightnessPct = b;
        if (lightOn && !screenLocked) {
            applyFrontlight(true);
        }
    }
    lockScreen = doc["lock_screen"] | lockScreen;
    alertMessageOn = doc["alert_message"] | alertMessageOn;
    alertYarboOn = doc["alert_yarbo"] | alertYarboOn;
    alertLymowOn = doc["alert_lymow"] | alertLymowOn;
    alertPowerwallOn = doc["alert_powerwall"] | alertPowerwallOn;
    yarboError = doc["yarbo_error"] | false;
    lymowError = doc["lymow_error"] | false;
    powerwallError = doc["powerwall_error"] | false;
    clockLocal = doc["clock_local"] | clockLocal;
    clockDate = doc["clock_date"] | clockDate;
    clockOffset = doc["clock_offset"] | clockOffset;
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
    drawScreen(true);
    return ok;
}

int keyboardHit(int x, int y)
{
    int y0 = 310;
    if (y < y0 || y > y0 + 3 * 42 + 44) {
        return -1;
    }
    if (y >= y0 + 3 * 42) {
        if (x < 110) return 100;
        if (x < 300) return 101;
        if (x < 372) return 102;
        return 103;
    }
    int row = (y - y0) / 42;
    const char *keys = kbRow(row);
    int n = strlen(keys);
    int keyW = (M5.Display.width() - 20) / n;
    int i = (x - 10) / keyW;
    if (i < 0 || i >= n) return -1;
    return (row * 32) + i;
}

void handleRadioTouch(int x, int y)
{
    if (tapOnPager(y)) {
        nextPage();
        return;
    }
    int n = min(4, peerCount + 1);
    int pw = (M5.Display.width() - 24) / n;
    if (y >= 258 && y <= 298) {
        int i = (x - 12) / pw;
        if (i >= 0 && i < n) {
            radioToIndex = i;
            drawScreen(true);
        }
        return;
    }
    int hit = keyboardHit(x, y);
    if (hit < 0) {
        if (y < 110) nextPage();
        return;
    }
    if (hit == 100) {
        kbNumbers = !kbNumbers;
        drawScreen(true);
        return;
    }
    if (hit == 101) {
        if (radioDraft.length() < PAPERMONO_MSG_CHARS) radioDraft += ' ';
        drawScreen(false);
        return;
    }
    if (hit == 102) {
        if (radioDraft.length()) radioDraft.remove(radioDraft.length() - 1);
        drawScreen(false);
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
        drawScreen(false);
    }
}

void handleDeviceTouch(int x, int y)
{
    if (tapOnPager(y)) {
        nextPage();
        return;
    }
    if (y >= 360 && y <= 448) {
        if (offConfirm) {
            if (x < 240) {
                offConfirm = false;
                drawScreen(true);
            } else {
                M5.Power.powerOff();
            }
        } else {
            offConfirm = true;
            drawScreen(true);
        }
        return;
    }
    if (y < 110) nextPage();
}

void handleLockTouch(int x, int y)
{
    lastLight = millis();
    if (!lightOn) {
        applyFrontlight(true);
    }
    int W = M5.Display.width();
    int H = M5.Display.height();
    bool c1 = x <= 80 && y <= 80;
    bool c2 = x >= W - 80 && y >= H - 80;
    uint32_t now = millis();
    if (unlockStep == 1 && now - unlockStepAt > 4000) {
        unlockStep = 0;
    }
    if (unlockStep == 0 && c1) {
        unlockStep = 1;
        unlockStepAt = now;
        return;
    }
    if (unlockStep == 1 && c2) {
        unreadCount = 0;
        exitLock();
        return;
    }
    unlockStep = 0;
}

void drawOtaScreen()
{
    beginEpdFrame(true);
    M5.Display.fillScreen(TFT_WHITE);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(MC_DATUM);
    M5.Display.setTextSize(3);
    M5.Display.drawString("UPDATING", M5.Display.width() / 2, M5.Display.height() / 2 - 40);
    M5.Display.setTextSize(1);
    M5.Display.drawString("Stay on Wi-Fi. Do not power off.", M5.Display.width() / 2, M5.Display.height() / 2 + 16);
    M5.Display.display();
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
    HTTPUpdate updater(180000);
    updater.rebootOnUpdate(true);
    updater.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
    String url = panelUrl + "/api/device.php?action=firmware&token=" + token;
    WiFiClient client;
    t_httpUpdate_return ret = updater.update(client, url);
    otaBusy = false;
    rgbOff();
    if (ret != HTTP_UPDATE_OK) {
        lastError = "update failed";
        drawScreen(true);
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
    if (deserializeJson(doc, body) || !doc["ok"]) {
        lastError = doc["error"] | "bad status";
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
    vestaboardLive = doc["vestaboard_live"] | vestaboardLive;
    vestaboardOn = doc["vestaboard_enabled"] | false;
    yarboOn = doc["yarbo_enabled"] | true;
    powerwallOn = doc["powerwall_enabled"] | false;
    lymowOn = doc["lymow_enabled"] | false;
    powerwallPct = doc["powerwall_pct"] | powerwallPct;
    powerwallSolar = doc["powerwall_solar"] | powerwallSolar;
    powerwallLoad = doc["powerwall_load"] | powerwallLoad;
    lymowName = doc["lymow_name"] | lymowName;
    lymowBattery = doc["lymow_battery"] | lymowBattery;
    lymowState = doc["lymow_state"] | lymowState;
    lymowCharging = doc["lymow_charging"] | lymowCharging;
    lastError = "";
    applyCompactExtras(doc);
    syncPaperLogo(String((const char *) (doc["logo_hash"] | "")));
    bool otaPending = doc["ota_pending"] | false;
    String latest = doc["firmware_latest"] | "";
    if (otaPending && latest.length() && latest != PAPERMONO_FW_VERSION) {
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

bool httpCommand(const char *cmd, const char *planId = nullptr, const char *live = nullptr)
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
    if (tapOnPager(y) || y < 110) {
        nextPage();
    }
}

void handlePlansTouch(int x, int y)
{
    if (tapOnPager(y)) {
        nextPage();
        return;
    }
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
                drawScreen(true);
            }
            return;
        }
        if (planCount > PAPERMONO_PLAN_VISIBLE) {
            planOffset += PAPERMONO_PLAN_VISIBLE;
            if (planOffset >= planCount) {
                planOffset = 0;
            }
            drawScreen(true);
        }
        return;
    }
    if (y >= y0 && y < startY) {
        int row = (y - y0) / rh;
        int idx = planOffset + row;
        if (idx >= 0 && idx < planCount && row < PAPERMONO_PLAN_VISIBLE) {
            selectedPlan = idx;
            drawScreen(true);
        }
        return;
    }
    if (y < 110) {
        nextPage();
    }
}

void setup()
{
    Serial.begin(115200);
    auto cfg = M5.config();
    cfg.clear_display = true;
    M5.begin(cfg);
    M5.Display.setRotation(0);
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
        drawScreen(true);
    } else {
        drawSetup();
    }
}

void loop()
{
    M5.update();
    pollSerialConfig();
    loraService();
    rgbTick();
    tabletBat = M5.Power.getBatteryLevel();

    String loraIn;
    if (loraTakeRx(loraIn)) {
        handleIncomingRadio(loraIn);
    }

    if (wifiSsid.isEmpty()) {
        delay(50);
        return;
    }

    if (M5.BtnPWR.wasClicked() || M5.BtnPWR.wasHold()) {
        if (!screenLocked) {
            enterLock();
        } else {
            lastLight = millis();
            applyFrontlight(true);
        }
    }

    uint32_t now = millis();
    if (!otaBusy && !screenLocked && wifiSsid.length() && now - lastActivity > (uint32_t) lockAfterS * 1000) {
        enterLock();
    }
    if (screenLocked && lightOn && now - lastLight > (uint32_t) lightOffS * 1000) {
        applyFrontlight(false);
    }

    if (WiFi.status() != WL_CONNECTED) {
        static uint32_t lastJoinDraw = 0;
        if (now - lastJoinDraw > 20000) {
            lastError = "joining " + wifiSsid;
            drawScreen(false);
            lastJoinDraw = now;
        }
        delay(30);
        return;
    }

    if (!screenLocked) {
        if (M5.BtnA.wasPressed()) {
            noteActivity();
            nextPage();
        } else if (M5.BtnB.wasPressed()) {
            noteActivity();
            prevPage();
        }
    }

    auto t = M5.Touch.getDetail();
    if (t.wasPressed()) {
        if (screenLocked) {
            handleLockTouch(t.x, t.y);
        } else {
            noteActivity();
            if (currentPage == PAPERMONO_PAGE_HOME) {
                int which = homeButtonAt(t.x, t.y);
                if (which == 0) {
                    nextPage();
                } else if (which == 1) {
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
                handlePlansTouch(t.x, t.y);
            } else if (currentPage == PAPERMONO_PAGE_NOTE) {
                handleNoteTouch(t.x, t.y);
            } else if (currentPage == PAPERMONO_PAGE_RADIO) {
                handleRadioTouch(t.x, t.y);
            } else if (currentPage == PAPERMONO_PAGE_DEVICE) {
                handleDeviceTouch(t.x, t.y);
            } else if (tapOnPager(t.y) || t.y < 110) {
                nextPage();
            }
        }
    }

    if (now - lastPoll > PAPERMONO_POLL_MS) {
        lastPoll = now;
        httpGetStatus();
        if (!pageEnabled(currentPage)) {
            currentPage = stepEnabledPage(currentPage, 1);
            drawScreen(true);
        } else if (currentPage == PAPERMONO_PAGE_PLANS) {
            httpGetPlans(false);
            drawScreen(false);
        } else {
            drawScreen(false);
        }
    }
    delay(20);
}
