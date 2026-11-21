#include "ExtraSensors.h"
#include <math.h>
#include <string.h>
#ifndef FITODOMIK_EXTRA_SENSOR_INTERVAL_MS
#define FITODOMIK_EXTRA_SENSOR_INTERVAL_MS 5000UL
#endif
static unsigned long lastExtraSensorsAt = 0;
int readAnalogPin(const char* pinName) {
  if (pinName == NULL) return -1;
  if (strcmp(pinName, "A0") == 0) return analogRead(A0);
  if (strcmp(pinName, "A1") == 0) return analogRead(A1);
  if (strcmp(pinName, "A2") == 0) return analogRead(A2);
  if (strcmp(pinName, "A3") == 0) return analogRead(A3);
  if (strcmp(pinName, "A4") == 0) return analogRead(A4);
  if (strcmp(pinName, "A5") == 0) return analogRead(A5);
#if defined(A6)
  if (strcmp(pinName, "A6") == 0) return analogRead(A6);
#endif
#if defined(A7)
  if (strcmp(pinName, "A7") == 0) return analogRead(A7);
#endif
  return -1;
}
static int clampPercent(long value) {
  if (value < 0) return 0;
  if (value > 100) return 100;
  return (int)value;
}
static int calibratedAnalogValue(int raw, bool enabled, int rawMin, int rawMax, bool invert) {
  if (!enabled || rawMin == rawMax) {
    return raw;
  }
  long value = ((long)raw - rawMin) * 100L / ((long)rawMax - rawMin);
  int percent = clampPercent(value);
  return invert ? 100 - percent : percent;
}
static void printExtraObjectPrefix(bool& first, const char* uid) {
  if (!first) Serial.print(",");
  first = false;
  Serial.print("\"");
  Serial.print(uid);
  Serial.print("\":{");
}
static void printExtraError(bool& first, const char* uid, const char* error) {
  printExtraObjectPrefix(first, uid);
  Serial.print("\"ok\":false,\"error\":\"");
  Serial.print(error);
  Serial.print("\"}");
}
static void printExtraFloatValue(bool& first, const char* uid, const char* field, float value, bool invalid) {
  printExtraObjectPrefix(first, uid);
  Serial.print("\"");
  Serial.print(field);
  Serial.print("\":");
  if (invalid) {
    Serial.print("null,\"ok\":false,\"error\":\"read_failed\"}");
    return;
  }
  Serial.print(value);
  Serial.print(",\"ok\":true}");
}
static void printExtraFieldPrefix(bool& firstField, const char* field) {
  if (!firstField) Serial.print(",");
  firstField = false;
  Serial.print("\"");
  Serial.print(field);
  Serial.print("\":");
}
static void printExtraFloatField(bool& firstField, const char* field, float value, bool invalid, bool& ok) {
  printExtraFieldPrefix(firstField, field);
  if (invalid) {
    Serial.print("null");
    ok = false;
    return;
  }
  Serial.print(value);
}
static void printExtraObjectEnd(bool ok) {
  Serial.print(",\"ok\":");
  Serial.print(ok ? "true" : "false");
  if (!ok) Serial.print(",\"error\":\"read_failed\"");
  Serial.print("}");
}
static void printExtraLongValue(bool& first, const char* uid, const char* field, long value) {
  printExtraObjectPrefix(first, uid);
  Serial.print("\"");
  Serial.print(field);
  Serial.print("\":");
  Serial.print(value);
  Serial.print(",\"ok\":true}");
}
void setupExtraSensors() {
#if FITODOMIK_EXTRA_SENSORS_ENABLED && FITODOMIK_EXTRA_SENSOR_COUNT > 0
#endif
}
void sendExtraSensorsJson() {
  unsigned long now = millis();
  if (lastExtraSensorsAt != 0 && now - lastExtraSensorsAt < FITODOMIK_EXTRA_SENSOR_INTERVAL_MS) {
    return;
  }
  lastExtraSensorsAt = now;
  Serial.print("EXTRA_SENSORS:{");
  bool first = true;
#if FITODOMIK_EXTRA_SENSORS_ENABLED && FITODOMIK_EXTRA_SENSOR_COUNT > 0
#endif
  Serial.println("}");
}
void sendExtraSensorsData() {
  sendExtraSensorsJson();
}