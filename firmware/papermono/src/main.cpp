#include <Arduino.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <ArduinoJson.h>
#include <M5Unified.h>
#include "version.h"

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

uint32_t lastPoll = 0;
String lastError;
int battery = -1;
String charging = "—";
String state = "—";
String head = "—";
int errorCode = 0;
bool lightsOn = false;
int partialRefreshCount = 0;
String lastDrawnKey;

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
    // epd_quality = full OTP waveform. epd_fastest = partial/fast (ghosts; cap at 10).
    M5.Display.setEpdMode(full ? epd_mode_t::epd_quality : epd_mode_t::epd_fastest);
    partialRefreshCount = full ? 0 : (partialRefreshCount + 1);
}

String screenKey()
{
    return String(battery) + "|" + charging + "|" + state + "|" + head + "|"
        + String(errorCode) + "|" + lastError + "|" + (lightsOn ? "1" : "0") + "|"
        + String((int) WiFi.status());
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

void drawHome(bool forceFull)
{
    String key = screenKey();
    if (!forceFull && key == lastDrawnKey) {
        return;
    }
    beginEpdFrame(forceFull);
    M5.Display.fillScreen(TFT_WHITE);
    int W = M5.Display.width();

    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    M5.Display.setTextDatum(TL_DATUM);
    M5.Display.setTextSize(2);
    M5.Display.drawString("YARBO  ·  BETA", 16, 20);
    M5.Display.setTextSize(1);
    M5.Display.drawString(deviceName + "  " + String(PAPERMONO_FW_VERSION), 16, 52);

    M5.Display.setTextSize(5);
    String bat = battery >= 0 ? (String(battery) + "%") : String("--");
    M5.Display.drawString(bat, 16, 96);

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

    M5.Display.setTextDatum(BL_DATUM);
    M5.Display.setTextSize(1);
    M5.Display.setTextColor(TFT_BLACK, TFT_WHITE);
    String wifi = WiFi.status() == WL_CONNECTED ? WiFi.localIP().toString() : "Wi-Fi: waiting";
    M5.Display.drawString(wifi, 16, M5.Display.height() - 10);
    M5.Display.setTextDatum(BR_DATUM);
    M5.Display.drawString("tap · e-paper", W - 16, M5.Display.height() - 10);
    M5.Display.display();
    lastDrawnKey = key;
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
    M5.Display.drawString("setup  ·  BETA", 16, 78);
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

int buttonAt(int x, int y)
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
    errorCode = doc["error_code"] | 0;
    lastError = "";
    return true;
}

bool httpCommand(const char *cmd)
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
    drawHome(false);
}

void setup()
{
    Serial.begin(115200);
    auto cfg = M5.config();
    cfg.clear_display = true;
    M5.begin(cfg);
    // Native SSD1677 panel is 480x800 portrait (61 x 101 mm device).
    M5.Display.setRotation(0);
    loadConfig();
    if (wifiSsid.length()) {
        WiFi.mode(WIFI_STA);
        WiFi.begin(wifiSsid.c_str(), wifiPass.c_str());
        drawHome(true);
    } else {
        drawSetup();
    }
}

void loop()
{
    M5.update();
    pollSerialConfig();

    if (wifiSsid.isEmpty()) {
        delay(50);
        return;
    }

    if (WiFi.status() != WL_CONNECTED) {
        static uint32_t lastJoinDraw = 0;
        if (millis() - lastJoinDraw > 20000) {
            lastError = "joining " + wifiSsid;
            drawHome(false);
            lastJoinDraw = millis();
        }
        delay(50);
        return;
    }

    // Optional hardware buttons (PaperMono has two user keys) if M5Unified maps them.
    if (M5.BtnA.wasPressed()) {
        runCommand("stop");
    } else if (M5.BtnB.wasPressed()) {
        runCommand("return_to_dock");
    }

    auto t = M5.Touch.getDetail();
    if (t.wasPressed()) {
        int which = buttonAt(t.x, t.y);
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
    }

    if (millis() - lastPoll > PAPERMONO_POLL_MS) {
        lastPoll = millis();
        httpGetStatus();
        drawHome(false);
    }
    delay(30);
}
