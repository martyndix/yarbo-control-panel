#pragma once

#include <Arduino.h>

bool paperHwBegin();
bool loraReady();
bool loraSetSyncWord(uint8_t word);
bool loraSendText(const String &payload);
bool loraTakeRx(String &out);
void loraService();
void rgbOff();
void rgbTick();
void alertMessage();
void alertError();
void alertsSetErrorActive(bool on);
