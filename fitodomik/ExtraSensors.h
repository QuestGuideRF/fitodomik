#pragma once
#include <Arduino.h>
#include "GeneratedConfig.h"
int readAnalogPin(const char* pinName);
void setupExtraSensors();
void sendExtraSensorsJson();
void sendExtraSensorsData();