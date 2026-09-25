#include "paper_hw.h"

#include <M5IOE1.h>
#include <M5PM1.h>
#include <M5Unified.h>
#include <RadioLib.h>
#include <SPI.h>

namespace {

constexpr int kMosiPin = 38;
constexpr int kMisoPin = 40;
constexpr int kSckPin = 39;
constexpr int kNssPin = 41;
constexpr int kIrqPin = 5;
constexpr int kBusyPin = 21;
constexpr uint32_t kSpiFrequencyHz = 8000000;
constexpr auto kResetPin = M5IOE1_PIN_10;
constexpr auto kAntennaSwitchPin = M5IOE1_PIN_2;
constexpr auto kLedG = M5IOE1_PIN_8;
constexpr auto kLedB = M5IOE1_PIN_9;

SPIClass loraSpi(HSPI);
SX1262 radio = new Module(
    kNssPin, kIrqPin, RADIOLIB_NC, kBusyPin, loraSpi,
    SPISettings(kSpiFrequencyHz, MSBFIRST, SPI_MODE0));
M5PM1 pm1;
M5IOE1 ioe1;

bool hwReady = false;
bool radioOk = false;
uint8_t syncWord = 0xA5;
volatile bool rxFlag = false;
String rxHold;
uint32_t rgbUntil = 0;
bool rgbError = false;
uint32_t rgbLastToggle = 0;
bool rgbOn = false;
bool rgbIsError = false;

void IRAM_ATTR onDio1()
{
    rxFlag = true;
}

bool enableLoRaHardware()
{
    const m5pm1_err_t pm1Error =
        pm1.begin(&M5.In_I2C, M5PM1_DEFAULT_ADDR, M5PM1_I2C_FREQ_100K);
    if (pm1Error != M5PM1_OK) {
        Serial.printf("M5PM1 init failed, code: %d\n", (int) pm1Error);
        return false;
    }
    if (pm1.gpioSetFunc(M5PM1_GPIO_NUM_2, M5PM1_GPIO_FUNC_GPIO) != M5PM1_OK ||
        pm1.gpioSet(M5PM1_GPIO_NUM_2, M5PM1_GPIO_MODE_OUTPUT, HIGH,
                    M5PM1_GPIO_PULL_NONE, M5PM1_GPIO_DRIVE_PUSHPULL) != M5PM1_OK) {
        Serial.println("LoRa power enable failed");
        return false;
    }
    const m5ioe1_err_t ioeError =
        ioe1.begin(&M5.In_I2C, M5IOE1_DEFAULT_ADDR_2, M5IOE1_I2C_FREQ_100K);
    if (ioeError != M5IOE1_OK) {
        Serial.printf("M5IOE1 init failed, code: %d\n", (int) ioeError);
        return false;
    }
    ioe1.pinMode(kResetPin, OUTPUT);
    ioe1.pinMode(kAntennaSwitchPin, OUTPUT);
    ioe1.pinMode(kLedG, OUTPUT);
    ioe1.pinMode(kLedB, OUTPUT);
    ioe1.setDriveMode(kResetPin, M5IOE1_DRIVE_PUSHPULL);
    ioe1.setDriveMode(kAntennaSwitchPin, M5IOE1_DRIVE_PUSHPULL);
    ioe1.setDriveMode(kLedG, M5IOE1_DRIVE_PUSHPULL);
    ioe1.setDriveMode(kLedB, M5IOE1_DRIVE_PUSHPULL);
    ioe1.digitalWrite(kLedG, LOW);
    ioe1.digitalWrite(kLedB, LOW);
    delay(200);
    m5ioe1_err_t ioeWriteError = M5IOE1_OK;
    ioe1.digitalWriteWithRes(kResetPin, LOW, &ioeWriteError);
    delay(100);
    ioe1.digitalWriteWithRes(kResetPin, HIGH, &ioeWriteError);
    delay(200);
    if (ioeWriteError != M5IOE1_OK) {
        return false;
    }
    pinMode(kBusyPin, INPUT);
    hwReady = true;
    return true;
}

void startRx()
{
    if (!radioOk) {
        return;
    }
    ioe1.digitalWrite(kAntennaSwitchPin, LOW);
    radio.startReceive();
}

void writeRgb(bool g, bool b)
{
    if (!hwReady) {
        return;
    }
    ioe1.digitalWrite(kLedG, g ? HIGH : LOW);
    ioe1.digitalWrite(kLedB, b ? HIGH : LOW);
}

void beep(int freq, int ms)
{
    M5.Speaker.tone((uint32_t) freq, (uint32_t) ms);
}

} // namespace

bool paperHwBegin()
{
    if (!enableLoRaHardware()) {
        return false;
    }
    loraSpi.begin(kSckPin, kMisoPin, kMosiPin, kNssPin);
    int state = radio.begin(868.0f, 125.0f, 9, 7, syncWord, 14, 8, 1.6f, false);
    if (state != RADIOLIB_ERR_NONE) {
        Serial.printf("SX1262 init failed, code: %d\n", state);
        radioOk = false;
        return true;
    }
    radio.setCurrentLimit(140);
    radio.setDio1Action(onDio1);
    radioOk = true;
    startRx();
    return true;
}

bool loraReady()
{
    return radioOk;
}

bool loraSetSyncWord(uint8_t word)
{
    if (word == 0) {
        word = 0xA5;
    }
    if (word == syncWord) {
        return radioOk;
    }
    syncWord = word;
    if (!radioOk) {
        return false;
    }
    int state = radio.setSyncWord(syncWord);
    startRx();
    return state == RADIOLIB_ERR_NONE;
}

bool loraSendText(const String &payload)
{
    if (!radioOk || payload.length() == 0) {
        return false;
    }
    ioe1.digitalWrite(kAntennaSwitchPin, HIGH);
    int state = radio.transmit(payload);
    startRx();
    return state == RADIOLIB_ERR_NONE;
}

bool loraTakeRx(String &out)
{
    if (rxHold.length() == 0) {
        return false;
    }
    out = rxHold;
    rxHold = "";
    return true;
}

void loraService()
{
    if (!radioOk || !rxFlag) {
        return;
    }
    rxFlag = false;
    String got;
    int state = radio.readData(got);
    startRx();
    if (state == RADIOLIB_ERR_NONE && got.length()) {
        rxHold = got;
    }
}

void rgbOff()
{
    rgbUntil = 0;
    rgbError = false;
    rgbOn = false;
    writeRgb(false, false);
}

void rgbTick()
{
    uint32_t now = millis();
    if (rgbError) {
        if (now - rgbLastToggle >= 400) {
            rgbLastToggle = now;
            rgbOn = !rgbOn;
            writeRgb(false, rgbOn);
        }
        return;
    }
    if (rgbUntil == 0) {
        return;
    }
    if (now >= rgbUntil) {
        rgbUntil = 0;
        writeRgb(false, false);
        return;
    }
    if (now - rgbLastToggle >= 180) {
        rgbLastToggle = now;
        rgbOn = !rgbOn;
        writeRgb(rgbOn && !rgbIsError, rgbOn);
    }
}

void alertMessage()
{
    rgbError = false;
    rgbIsError = false;
    rgbUntil = millis() + 1400;
    rgbLastToggle = 0;
    rgbOn = true;
    writeRgb(true, true);
    beep(1800, 140);
    delay(80);
    beep(2200, 140);
}

void alertError()
{
    rgbError = true;
    rgbIsError = true;
    rgbLastToggle = 0;
    rgbOn = true;
    writeRgb(false, false);
    beep(900, 220);
    delay(90);
    beep(700, 280);
}

void alertsSetErrorActive(bool on)
{
    if (on) {
        rgbError = true;
        rgbIsError = true;
        rgbLastToggle = 0;
    } else if (rgbError) {
        rgbError = false;
        if (rgbUntil == 0) {
            writeRgb(false, false);
        }
    }
}
