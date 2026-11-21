#pragma once
#include <Arduino.h>
#define FITODOMIK_EXTRA_SENSORS_ENABLED 0
#define FITODOMIK_EXTRA_SENSOR_COUNT 0
#define FITODOMIK_EXTRA_SENSOR_INTERVAL_MS 5000UL
struct ExtraSensorConfig {
  const char* uid;
  const char* type;
  const char* connection;
  const char* pin;
  const char* address;
  const char* unit;
  const char* field;
  const char* profile;
  bool calibrationEnabled;
  int calibrationRawMin;
  int calibrationRawMax;
  bool calibrationInvert;
};
#define FITODOMIK_EXTRA_RELAY_COUNT 0
struct ExtraRelayConfig {
  const char* uid;
  const char* name;
  uint8_t pin;
  bool activeLow;
};