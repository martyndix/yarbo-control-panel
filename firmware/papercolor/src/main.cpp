#include <Arduino.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <SPIFFS.h>
#include <ArduinoJson.h>
#include <M5Unified.h>
#include "version.h"

// M5Stack PaperColor (https://docs.m5stack.com/en/core/PaperColor)
// Spectra 6 400x600, no touch. Buttons A/B change page, C sleep/wake.
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
String deviceName = "Paper Colour";
String robotName = "";

uint32_t lastPoll = 0;
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
String lymowName = "";
int lymowBattery = -1;
String lymowState = "—";
String lymowCharging = "—";
bool yarboOn = true;
bool powerwallOn = false;
bool lymowOn = false;
int powerwallPct = -1;
String powerwallSolar = "—";
String powerwallLoad = "—";
int partialRefreshCount = 0;
String lastDrawnKey;
String logoHash = "";
int currentPage = PAPERMONO_PAGE_HOME;
bool screenLocked = false;
uint32_t lastActivity = 0;
int lockAfterS = 60;
String lockScreen = "logo";
bool vestaboardOn = false;
int vestaboardCodes[3][15];
String vestaboardHash = "";
String clockLocal = "--:--";
String clockDate = "";
int tabletBat = -1;

String planIds[PAPERMONO_PLAN_MAX];
String planNames[PAPERMONO_PLAN_MAX];
int planCount = 0;
int planOffset = 0;
int selectedPlan = -1;
String plansNote = "";
bool plansLoaded = false;

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
    deviceName = prefs.getString("name", "Paper Colour");
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
    if (page == PAPERMONO_PAGE_POWERWALL) return "POWERWALL";
    if (page == PAPERMONO_PAGE_LYMOW) return "LYMOW";
    if (page == PAPERMONO_PAGE_BOARD) return "BOARD";
    return "HOME";
}

String screenKey()
{
    return String(currentPage) + "|" + String(battery) + "|" + charging + "|" + state + "|" + head + "|"
        + errorLabel + "|" + heading + "|" + rainLabel + "|" + connectionType + "|" + connectionStatus + "|"
        + wifiNetwork + "|" + wifiSignal + "|" + batteryTemp + "|" + wirelessCharge + "|" + rtkStatus + "|"
        + planActivity + "|" + String(planCount) + "|" + String(selectedPlan) + "|" + String(planOffset) + "|"
        + lastError + "|" + (lightsOn ? "1" : "0") + "|" + robotName + "|" + lymowName + "|"
        + String(lymowBattery) + "|" + lymowState + "|" + (yarboOn ? "1" : "0") + "|"
        + (powerwallOn ? "1" : "0") + "|"
        + (lymowOn ? "1" : "0") + "|" + String(powerwallPct) + "|" + powerwallSolar + "|"
        + powerwallLoad + "|" + String((int) WiFi.status()) + "|" + logoHash + "|"
        + String(screenLocked ? 1 : 0) + "|" + lockScreen + "|" + vestaboardHash + "|"
        + deviceName + "|" + clockLocal;
}

bool pageEnabled(int page)
{
    if (page == PAPERMONO_PAGE_HOME || page == PAPERMONO_PAGE_STATUS
        || page == PAPERMONO_PAGE_HEALTH || page == PAPERMONO_PAGE_PLANS) {
        return yarboOn;
    }
    if (page == PAPERMONO_PAGE_POWERWALL) return powerwallOn;
    if (page == PAPERMONO_PAGE_LYMOW) return lymowOn;
    if (page == PAPERMONO_PAGE_BOARD) return vestaboardOn;
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
    if (currentPage == PAPERMONO_PAGE_POWERWALL || currentPage == PAPERMONO_PAGE_BOARD) {
        return deviceName;
    }
    return robotName.length() ? robotName : deviceName;
}

String headerBrand()
{
    if (currentPage == PAPERMONO_PAGE_POWERWALL) return "POWERWALL";
    if (currentPage == PAPERMONO_PAGE_LYMOW) return "LYMOW";
    if (currentPage == PAPERMONO_PAGE_BOARD) return "VESTABOARD";
    return "YARBO  ·  COLOUR";
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
    int logoSize = 140;
    if (SPIFFS.exists("/logo.png")) {
        M5.Display.drawPngFile(SPIFFS, "/logo.png", M5.Display.width() - logoSize - 16, 16, logoSize, logoSize);
    }
}

void drawPager()
{
    int H = M5.Display.height();
    int W = M5.Display.width();
    const char *labels[PAPERMONO_PAGE_COUNT] = {"HOME", "STATUS", "HEALTH", "PLANS", "WALL", "LYMOW", "BOARD"};
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

uint16_t vestaboardFillColor(int code)
{
    if (code == 63) return TFT_RED;
    if (code == 64) return TFT_RED;
    if (code == 65) return TFT_YELLOW;
    if (code == 66) return TFT_GREEN;
    if (code == 67) return TFT_BLUE;
    if (code == 68) return TFT_BLUE;
    if (code == 69) return TFT_WHITE;
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
            uint16_t fill = vestaboardFillColor(code);
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

void drawLockScreen(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TC_DATUM);
    M5.Display.setTextSize(2);
    M5.Display.drawString(deviceName.length() ? deviceName : String("Paper Colour"), M5.Display.width() / 2, 16);
    M5.Display.setTextSize(2);
    M5.Display.drawString(clockLocal.length() ? clockLocal : String("--:--"), M5.Display.width() / 2, 48);
    M5.Display.setTextSize(1);
    M5.Display.drawString(clockDate, M5.Display.width() / 2, 78);
    bool showLogo = lockScreen != "vestaboard" || !vestaboardOn;
    bool showBoard = vestaboardOn && (lockScreen == "vestaboard" || lockScreen == "both");
    if (lockScreen == "logo" || !vestaboardOn) {
        showLogo = true;
        showBoard = false;
    }
    int W = M5.Display.width();
    if (showLogo && SPIFFS.exists("/logo.png")) {
        int logoSize = showBoard ? 110 : 180;
        M5.Display.drawPngFile(SPIFFS, "/logo.png", (W - logoSize) / 2, showBoard ? 100 : 120, logoSize, logoSize);
    }
    if (showBoard) {
        int cell = showLogo ? 16 : 22;
        int gap = 2;
        int gridW = 15 * cell + 14 * gap;
        int gridY = showLogo ? 230 : 140;
        drawVestaboardGrid((W - gridW) / 2, gridY, cell, gap);
    }
    M5.Display.setTextDatum(BC_DATUM);
    M5.Display.drawString("C unlocks", W / 2, M5.Display.height() - 8);
    M5.Display.display();
}

void drawBoardPage(bool forceFull)
{
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    drawHeader();
    int cell = 20;
    int gap = 3;
    int gridW = 15 * cell + 14 * gap;
    drawVestaboardGrid((M5.Display.width() - gridW) / 2, 118, cell, gap);
    M5.Display.setTextDatum(TC_DATUM);
    M5.Display.setTextSize(1);
    M5.Display.drawString("live Vestaboard", M5.Display.width() / 2, 200);
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
    } else if (currentPage == PAPERMONO_PAGE_POWERWALL) {
        if (!pageEnabled(PAPERMONO_PAGE_POWERWALL)) {
            currentPage = firstEnabledPage();
            drawHome(forceFull);
        } else {
            drawPowerwallPage(forceFull);
        }
    } else if (currentPage == PAPERMONO_PAGE_LYMOW) {
        if (!pageEnabled(PAPERMONO_PAGE_LYMOW)) {
            currentPage = firstEnabledPage();
            drawHome(forceFull);
        } else {
            drawLymowPage(forceFull);
        }
    } else if (currentPage == PAPERMONO_PAGE_BOARD) {
        if (!pageEnabled(PAPERMONO_PAGE_BOARD)) {
            currentPage = firstEnabledPage();
            drawHome(forceFull);
        } else {
            drawBoardPage(forceFull);
        }
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
    M5.Display.drawString("Paper Colour", 16, 28);
    M5.Display.setTextSize(2);
    M5.Display.drawString("setup  ·  BETA", 16, 78);
    M5.Display.setTextSize(1);
    M5.Display.drawString("1. Plug USB into the computer", 16, 140);
    M5.Display.drawString("   running this Yarbo panel.", 16, 162);
    M5.Display.drawString("2. Settings, E-paper companions", 16, 198);
    M5.Display.drawString("   Choose Paper Colour.", 16, 220);
    M5.Display.drawString("3. Flash Paper Colour firmware", 16, 256);
    M5.Display.drawString("   and send 2.4 GHz Wi-Fi.", 16, 278);
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
    vestaboardOn = doc["vestaboard_enabled"] | false;
    String newName = doc["device_name"] | deviceName;
    if (newName.length()) {
        deviceName = newName;
    }
    lockAfterS = doc["lock_after_s"] | lockAfterS;
    lockScreen = doc["lock_screen"] | lockScreen;
    clockLocal = doc["clock_local"] | clockLocal;
    clockDate = doc["clock_date"] | clockDate;
    vestaboardHash = doc["vestaboard_hash"] | vestaboardHash;
    JsonArray codes = doc["vestaboard_codes"].as<JsonArray>();
    if (!codes.isNull()) {
        for (int r = 0; r < 3; r++) {
            JsonArray row = codes[r].as<JsonArray>();
            for (int c = 0; c < 15; c++) {
                vestaboardCodes[r][c] = row.isNull() ? 0 : (int) (row[c] | 0);
            }
        }
    }
    lastError = "";
    syncPaperLogo(String((const char *) (doc["logo_hash"] | "")));
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

bool httpCommand(const char *cmd, const char *planId = nullptr)
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

void showPage(int page, bool loadPlansIfNeeded)
{
    if (!pageEnabled(page)) {
        page = stepEnabledPage(page, 1);
    }
    currentPage = page;
    lastActivity = millis();
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
    SPIFFS.begin(true);
    loadConfig();
    lastActivity = millis();
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
    tabletBat = M5.Power.getBatteryLevel();

    if (wifiSsid.isEmpty()) {
        delay(50);
        return;
    }

    uint32_t now = millis();
    if (!screenLocked && wifiSsid.length() && now - lastActivity > (uint32_t) lockAfterS * 1000) {
        screenLocked = true;
        drawScreen(true);
    }

    if (WiFi.status() != WL_CONNECTED) {
        static uint32_t lastJoinDraw = 0;
        if (now - lastJoinDraw > 20000) {
            lastError = "joining " + wifiSsid;
            drawScreen(false);
            lastJoinDraw = now;
        }
        delay(50);
        return;
    }

    if (M5.BtnC.wasPressed()) {
        screenLocked = !screenLocked;
        lastActivity = millis();
        if (!screenLocked) {
            M5.Display.wakeup();
        }
        drawScreen(true);
        return;
    }

    if (screenLocked) {
        if (now - lastPoll > PAPERMONO_POLL_MS) {
            lastPoll = now;
            httpGetStatus();
            drawScreen(false);
        }
        delay(40);
        return;
    }

    if (M5.BtnA.wasPressed()) {
        lastActivity = millis();
        nextPage();
    } else if (M5.BtnB.wasPressed()) {
        lastActivity = millis();
        prevPage();
    }

    auto t = M5.Touch.getDetail();
    if (t.wasPressed()) {
        lastActivity = millis();
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
        } else if (tapOnPager(t.y) || t.y < 110) {
            nextPage();
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
    delay(40);
}
