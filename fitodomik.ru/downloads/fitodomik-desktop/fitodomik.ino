#include <Wire.h>
#include <Adafruit_BME280.h>
#include <MHZ19_uart.h>
#include <EEPROM.h>
#include "RTClib.h"
#include "GeneratedConfig.h"
#include "ExtraSensors.h"
#if defined(ARDUINO_AVR_UNO)
#define SOIL_PIN A0
#else
#define SOIL_PIN A6
#endif
#define RELAY_PINS {7, 6, 5, 4}
#define SENSOR_READ_TIME 2000
#define AUTO_CONTROL_TIME 10000
#define MAX_PUMP_ON_MS 60000UL
#define MAX_SOIL_AGE_MS 10000UL
#define SERIAL_PROTOCOL_VERSION 2
#define SERIAL_INPUT_CAPACITY 192
#define PUMP_FAULT_EEPROM_ADDRESS 0
#define PUMP_FAULT_EEPROM_MARKER 0xA5
Adafruit_BME280 bme;
MHZ19_uart mhz19;
RTC_DS3231 rtc;
struct SensorData {
  int co2;
  float temp;
  float humidity;
  float pressure;
  int soilMoisture;
} sensors;
struct ThresholdData {
  float tempMin, tempMax;
  float humidityMin, humidityMax;
  float soilMin, soilMax;
  int co2Min, co2Max;
  bool loaded;
} thresholds = {0, 0, 0, 0, 0, 0, 0, 0, false};
struct ScheduleData {
  String lampSchedule;
  String curtainSchedule;
  bool loaded;
} schedule = {"", "", false};
unsigned long lastSensorTime = 0;
unsigned long lastAutoControlTime = 0;
int relayPins[] = RELAY_PINS;
bool relayStates[] = {false, false, false, false};
#if FITODOMIK_EXTRA_RELAY_COUNT > 0
bool extraRelayStates[FITODOMIK_EXTRA_RELAY_COUNT];
#endif
char inputBuffer[SERIAL_INPUT_CAPACITY];
size_t inputLength = 0;
bool discardInputUntilNewline = false;
bool pumpFaultLatched = false;
bool pumpManualRestartRequired = false;
bool soilReadingValid = false;
unsigned long lastValidSoilAt = 0;
unsigned long pumpOnSince = 0;
int currentHour = 0;
void setup() {
  for (int i = 0; i < 4; i++) {
    digitalWrite(relayPins[i], HIGH);
    pinMode(relayPins[i], OUTPUT);
    relayStates[i] = false;
  }
  pumpFaultLatched = EEPROM.read(PUMP_FAULT_EEPROM_ADDRESS) == PUMP_FAULT_EEPROM_MARKER;
  pumpManualRestartRequired = pumpFaultLatched;
  setupExtraRelays();
  Serial.begin(9600);
  setupExtraSensors();
  Wire.begin();
  if (!bme.begin(0x76)) {
    Serial.println(F("BME280 init failed!"));
  }
  mhz19.begin(3, 2);
  mhz19.setAutoCalibration(false);
  if (!rtc.begin()) {
    Serial.println(F("RTC not found!"));
  } else {
    if (rtc.lostPower()) {
      rtc.adjust(DateTime(F(__DATE__), F(__TIME__)));
    }
    currentHour = rtc.now().hour();
  }
  delay(2000);
  Serial.println(F("Multi-Sensor System with Auto Control Ready"));
  Serial.println(F("PROTO:2"));
  if (pumpFaultLatched) Serial.println(F("FAULT:PUMP_TIMEOUT"));
}
void loop() {
  unsigned long currentMillis = millis();
  checkPumpSafety(currentMillis);
  if (currentMillis - lastSensorTime >= SENSOR_READ_TIME) {
    lastSensorTime = currentMillis;
    readSensors();
    sendSerialData();
    sendExtraSensorsJson();
  }
  if (currentMillis - lastAutoControlTime >= AUTO_CONTROL_TIME) {
    lastAutoControlTime = currentMillis;
    currentHour = rtc.now().hour();
    autoControlRelays();
  }
  while (Serial.available()) {
    checkPumpSafety(millis());
    char inChar = (char)Serial.read();
    if (inChar == '\n') {
      if (discardInputUntilNewline) {
        Serial.println(F("ERR:INPUT_TOO_LONG"));
      } else if (inputLength > 0) {
        inputBuffer[inputLength] = '\0';
        String command(inputBuffer);
        command.trim();
        if (command.length() > 0) {
          if (command.indexOf('|') > 0) parseScheduleData(command);
          else processCommand(command);
        }
      }
      inputLength = 0;
      discardInputUntilNewline = false;
    } else if (inChar != '\r' && !discardInputUntilNewline) {
      if (inputLength >= SERIAL_INPUT_CAPACITY - 1) {
        inputLength = 0;
        discardInputUntilNewline = true;
      } else {
        inputBuffer[inputLength++] = inChar;
      }
    }
  }
  checkPumpSafety(millis());
}
void readSensors() {
  sensors.temp = bme.readTemperature();
  sensors.humidity = bme.readHumidity();
  sensors.pressure = bme.readPressure() / 100.0F;
  sensors.co2 = mhz19.getPPM();
  int rawSoil = analogRead(SOIL_PIN);
  soilReadingValid = rawSoil > 1 && rawSoil < 1022;
  if (soilReadingValid) {
    sensors.soilMoisture = map(rawSoil, 1023, 0, 0, 100);
    lastValidSoilAt = millis();
  } else if (relayStates[1]) {
    setRelay(1, false);
    Serial.println(F("ERR:SOIL_SENSOR_INVALID"));
  }
}
void sendSerialData() {
  Serial.print(F("{\"co2\":"));
  Serial.print(sensors.co2);
  Serial.print(F(",\"temp\":"));
  Serial.print(sensors.temp);
  Serial.print(F(",\"humidity\":"));
  Serial.print(sensors.humidity);
  Serial.print(F(",\"pressure\":"));
  Serial.print(sensors.pressure);
  Serial.print(F(",\"soil\":"));
  Serial.print(sensors.soilMoisture);
  Serial.print(F(",\"soil_valid\":"));
  Serial.print(soilReadingValid && millis() - lastValidSoilAt <= MAX_SOIL_AGE_MS ? "true" : "false");
  Serial.print(F(",\"protocol_version\":"));
  Serial.print(SERIAL_PROTOCOL_VERSION);
  Serial.print(F(",\"pump_fault\":"));
  Serial.print(pumpFaultLatched ? "\"PUMP_TIMEOUT\"" : "null");
  Serial.print(F(",\"extra_relays\":{"));
#if FITODOMIK_EXTRA_RELAY_COUNT > 0
  for (int i = 0; i < FITODOMIK_EXTRA_RELAY_COUNT; i++) {
    if (i > 0) Serial.print(F(","));
    Serial.print(F("\""));
    Serial.print(EXTRA_RELAYS[i].uid);
    Serial.print(F("\":"));
    Serial.print(extraRelayStates[i] ? "true" : "false");
  }
#endif
  Serial.print(F("}"));
  for (int i = 0; i < 4; i++) {
    Serial.print(F(",\"relay"));
    Serial.print(i + 1);
    Serial.print(F("\":"));
    Serial.print(relayStates[i] ? "true" : "false");
  }
  Serial.print(F(",\"time\":\""));
  if (currentHour < 10) Serial.print(F("0"));
  Serial.print(currentHour);
  Serial.print(F(":00:00\""));
  DateTime now = rtc.now();
  Serial.print(F(",\"date\":\""));
  if (now.day() < 10) Serial.print(F("0"));
  Serial.print(now.day());
  Serial.print(F("."));
  if (now.month() < 10) Serial.print(F("0"));
  Serial.print(now.month());
  Serial.print(F("."));
  Serial.print(now.year());
  Serial.print(F("\""));
  Serial.println(F("}"));
}
bool validSchedulePart(const String &part) {
  if (part == "0" || part == "") return true;
  const char *cursor = part.c_str();
  while (*cursor) {
    if (*cursor < '0' || *cursor > '9') return false;
    char *end;
    long first = strtol(cursor, &end, 10);
    if (end == cursor || first < 0 || first > 23) return false;
    cursor = end;
    if (*cursor == '-') {
      cursor++;
      if (*cursor < '0' || *cursor > '9') return false;
      long last = strtol(cursor, &end, 10);
      if (end == cursor || last < first || last > 23) return false;
      cursor = end;
    }
    if (*cursor == 0) return true;
    if (*cursor != ',' || cursor[1] == 0) return false;
    cursor++;
  }
  return false;
}
void parseScheduleData(String data) {
  int firstSeparator = data.indexOf('|');
  int secondSeparator = data.indexOf('|', firstSeparator + 1);
  if (firstSeparator <= 0 || secondSeparator <= firstSeparator) {
    Serial.println(F("ERR:INVALID_CONFIG")); return;
  }
  float values[8];
  const char *cursor = data.c_str();
  char *end;
  for (int i = 0; i < 8; i++) {
    values[i] = strtod(cursor, &end);
    if (end == cursor || !isfinite(values[i])) {
      Serial.println(F("ERR:INVALID_CONFIG")); return;
    }
    char expected = i == 7 ? '|' : (i % 2 == 0 ? '-' : ';');
    if (*end != expected) { Serial.println(F("ERR:INVALID_CONFIG")); return; }
    cursor = end + 1;
  }
  for (int i = 0; i < 4; i++) {
    float low = i == 0 ? -50 : 0;
    float high = i == 3 ? 10000 : 100;
    if (values[i*2] < low || values[i*2+1] > high || values[i*2] > values[i*2+1]) {
      Serial.println(F("ERR:INVALID_CONFIG")); return;
    }
  }
  if (values[6] != (int)values[6] || values[7] != (int)values[7]) {
    Serial.println(F("ERR:INVALID_CONFIG")); return;
  }
  String states = data.substring(firstSeparator + 1, secondSeparator);
  if (states != "0,0" && states != "0,1" && states != "1,0" && states != "1,1") {
    Serial.println(F("ERR:INVALID_CONFIG")); return;
  }
  int separator = data.indexOf(';', secondSeparator + 1);
  if (separator < 0) { Serial.println(F("ERR:INVALID_CONFIG")); return; }
  String lamp = data.substring(secondSeparator + 1, separator);
  String curtains = data.substring(separator + 1);
  if (!validSchedulePart(lamp) || !validSchedulePart(curtains)) {
    Serial.println(F("ERR:INVALID_CONFIG")); return;
  }
  // Commit the complete configuration only after every field has passed checks.
  thresholds.tempMin=values[0]; thresholds.tempMax=values[1];
  thresholds.humidityMin=values[2]; thresholds.humidityMax=values[3];
  thresholds.soilMin=values[4]; thresholds.soilMax=values[5];
  thresholds.co2Min=(int)values[6]; thresholds.co2Max=(int)values[7];
  thresholds.loaded=true;
  schedule.lampSchedule=lamp; schedule.curtainSchedule=curtains; schedule.loaded=true;
  Serial.println(F("ACK:CONFIG:2"));
}
void autoControlRelays() {
  if (!thresholds.loaded) return;
  if (sensors.temp > thresholds.tempMax) {
    if (!relayStates[0]) {
      setRelay(0, true);
      Serial.println(F("Auto: Fan ON"));
    }
  } else if (sensors.temp >= thresholds.tempMin && sensors.temp <= thresholds.tempMax) {
    if (relayStates[0]) {
      setRelay(0, false);
      Serial.println(F("Auto: Fan OFF"));
    }
  }
  bool soilFresh = soilReadingValid && millis() - lastValidSoilAt <= MAX_SOIL_AGE_MS;
  if (!soilFresh) {
    if (relayStates[1]) setRelay(1, false);
  } else if (sensors.soilMoisture < thresholds.soilMin && !pumpFaultLatched && !pumpManualRestartRequired) {
    if (!relayStates[1]) {
      setRelay(1, true);
      Serial.println(F("Auto: Pump ON"));
    }
  } else if (sensors.soilMoisture >= thresholds.soilMin && sensors.soilMoisture <= thresholds.soilMax) {
    if (relayStates[1]) {
      setRelay(1, false);
      Serial.println(F("Auto: Pump OFF"));
    }
  }
  if (schedule.loaded) {
    controlBySchedule();
  }
}
void controlBySchedule() {
  bool shouldCurtainsBeOn = isTimeInSchedule(schedule.curtainSchedule, currentHour);
  if (shouldCurtainsBeOn != relayStates[2]) {
    setRelay(2, shouldCurtainsBeOn);
    Serial.print(F("Auto: Curtains "));
    Serial.println(shouldCurtainsBeOn ? "OPEN" : "CLOSED");
  }
  bool shouldLampBeOn = isTimeInSchedule(schedule.lampSchedule, currentHour);
  if (shouldLampBeOn != relayStates[3]) {
    setRelay(3, shouldLampBeOn);
    Serial.print(F("Auto: Lamp "));
    Serial.println(shouldLampBeOn ? "ON" : "OFF");
  }
}
bool isTimeInSchedule(String scheduleStr, int hour) {
  if (scheduleStr == "0" || scheduleStr == "") return false;
  int startPos = 0;
  for (int i = 0; i <= scheduleStr.length(); i++) {
    if (i == scheduleStr.length() || scheduleStr[i] == ',') {
      String interval = scheduleStr.substring(startPos, i);
      int dashPos = interval.indexOf('-');
      if (dashPos > 0) {
        int startHour = interval.substring(0, dashPos).toInt();
        int endHour = interval.substring(dashPos + 1).toInt();
        if (hour >= startHour && hour <= endHour) {
          return true;
        }
      } else {
        if (interval.toInt() == hour) {
          return true;
        }
      }
      startPos = i + 1;
    }
  }
  return false;
}
void processCommand(String cmd) {
  cmd.trim();
  cmd.toUpperCase();
  if (cmd == "S") {
    sendStatus();
  }
  else if (cmd == "PROTO?") {
    Serial.println(F("PROTO:2"));
  }
  else if (cmd == "PUMP_FAULT_RESET") {
    // Reset never energizes the pump. A separate SET command is required.
    setRelay(1, false);
    pumpFaultLatched = false;
    pumpManualRestartRequired = true;
    EEPROM.update(PUMP_FAULT_EEPROM_ADDRESS, 0);
    Serial.println(F("EVENT:pump_fault_reset"));
    Serial.println(F("ACK:PUMP_FAULT_RESET:0"));
  }
  else if (cmd.startsWith("RELAY_SET ") || cmd.startsWith("PUMP_SET ")) {
    String relayUid;
    String stateText;
    if (cmd.startsWith("PUMP_SET ")) {
      relayUid = "PUMP";
      stateText = cmd.substring(9);
    } else {
      int separator = cmd.lastIndexOf(' ');
      if (separator <= 10) {
        Serial.println(F("ERR:INVALID_RELAY_SET"));
        return;
      }
      relayUid = cmd.substring(10, separator);
      stateText = cmd.substring(separator + 1);
    }
    relayUid.trim();
    stateText.trim();
    if (stateText != "0" && stateText != "1") {
      Serial.println(F("ERR:INVALID_STATE"));
      return;
    }
    int relay = relayIndexByUid(relayUid);
    if (relay < 0) {
      bool actual = false;
      if (setExtraRelayByUid(relayUid, stateText == "1", actual)) {
        Serial.print(F("ACK:RELAY:"));
        Serial.print(relayUid);
        Serial.print(F(":"));
        Serial.println(actual ? "1" : "0");
      } else {
        Serial.println(F("ERR:UNKNOWN_RELAY"));
      }
      return;
    }
    if (!setRelay(relay, stateText == "1")) {
      Serial.println(F("ERR:PUMP_FAULT_LATCHED_OR_SENSOR_INVALID"));
      return;
    }
    if (relay == 1 && stateText == "1") pumpManualRestartRequired = false;
    Serial.print(F("ACK:RELAY:"));
    Serial.print(relayUid);
    Serial.print(F(":"));
    Serial.println(relayStates[relay] ? "1" : "0");
  }
  else if (cmd == "A+") {
    Serial.println(F("ERR:GROUP_ON_UNSAFE"));
  }
  else if (cmd == "A-") {
    setAllRelays(false);
  }
  else if (cmd.length() == 1 && cmd[0] >= '1' && cmd[0] <= '4') {
    Serial.println(F("ERR:LEGACY_TOGGLE_UNSAFE"));
  }
  else Serial.println(F("ERR:UNKNOWN_COMMAND"));
}
int relayIndexByUid(const String &uid) {
  if (uid == "FAN" || uid == "RELAY1" || uid == "R1") return 0;
  if (uid == "PUMP" || uid == "RELAY2" || uid == "R2") return 1;
  if (uid == "CURTAINS" || uid == "RELAY3" || uid == "R3") return 2;
  if (uid == "LAMP" || uid == "LED" || uid == "RELAY4" || uid == "R4") return 3;
  return -1;
}
void setupExtraRelays() {
#if FITODOMIK_EXTRA_RELAY_COUNT > 0
  for (int i = 0; i < FITODOMIK_EXTRA_RELAY_COUNT; i++) {
    const ExtraRelayConfig &relay = EXTRA_RELAYS[i];
    digitalWrite(relay.pin, relay.activeLow ? HIGH : LOW);
    pinMode(relay.pin, OUTPUT);
    extraRelayStates[i] = false;
  }
#endif
}
bool setExtraRelayByUid(const String &uid, bool state, bool &actual) {
#if FITODOMIK_EXTRA_RELAY_COUNT > 0
  for (int i = 0; i < FITODOMIK_EXTRA_RELAY_COUNT; i++) {
    if (!uid.equalsIgnoreCase(EXTRA_RELAYS[i].uid)) continue;
    const ExtraRelayConfig &relay = EXTRA_RELAYS[i];
    if (extraRelayStates[i] != state) {
      digitalWrite(relay.pin, state == relay.activeLow ? LOW : HIGH);
      extraRelayStates[i] = state;
    }
    actual = extraRelayStates[i];
    return true;
  }
#endif
  return false;
}
bool setRelay(int index, bool state) {
  if (index < 0 || index >= 4) return false;
  if (index == 1 && state) {
    if (pumpFaultLatched) return false;
    if (!soilReadingValid || millis() - lastValidSoilAt > MAX_SOIL_AGE_MS) return false;
  }
  if (relayStates[index] == state) return true;
  relayStates[index] = state;
  digitalWrite(relayPins[index], state ? LOW : HIGH);
  if (index == 1) {
    if (state) {
      pumpOnSince = millis();
      Serial.println(F("EVENT:pump_started"));
    } else {
      pumpOnSince = 0;
      Serial.println(F("EVENT:pump_stopped"));
    }
  }
  return true;
}
void toggleRelay(int index) {
  // Kept only as a local compatibility symbol; no Serial command invokes it.
  setRelay(index, !relayStates[index]);
}
void setAllRelays(bool state) {
  for (int i = 0; i < 4; i++) {
    setRelay(i, state);
  }
}
void sendStatus() {
  Serial.print(F("Status: "));
  for (int i = 0; i < 4; i++) {
    Serial.print(F("R"));
    Serial.print(i + 1);
    Serial.print(relayStates[i] ? ":ON " : ":OFF ");
  }
  Serial.print(F(" PROTO:2 FAULT:"));
  Serial.println(pumpFaultLatched ? "PUMP_TIMEOUT" : "NONE");
}
void checkPumpSafety(unsigned long now) {
  if (!relayStates[1] || now - pumpOnSince < MAX_PUMP_ON_MS) return;
  // Physical shutdown precedes EEPROM, Serial and any network/camera activity.
  digitalWrite(relayPins[1], HIGH);
  relayStates[1] = false;
  pumpOnSince = 0;
  pumpFaultLatched = true;
  EEPROM.update(PUMP_FAULT_EEPROM_ADDRESS, PUMP_FAULT_EEPROM_MARKER);
  Serial.println(F("EVENT:pump_stopped"));
  Serial.println(F("EVENT:pump_timeout"));
  Serial.println(F("EVENT:pump_fault_latched"));
  Serial.println(F("FAULT:PUMP_TIMEOUT"));
}
