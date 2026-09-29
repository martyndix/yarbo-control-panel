#pragma once

#include <Arduino.h>

bool paperHwBegin();
void paperSetFrontlight(uint8_t brightness);
bool loraReady();
bool loraSetSyncWord(uint8_t word);
bool loraSendText(const String &payload);
bool loraTakeRx(String &out);
void loraService();
void rgbOff();
void rgbTick();
void rgbHoldMessage(bool on);
void alertMessage();
void alertError();
void alertOta();
void alertsSetErrorActive(bool on);
