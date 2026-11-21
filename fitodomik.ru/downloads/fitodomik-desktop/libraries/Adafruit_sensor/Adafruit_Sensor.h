#ifndef _ADAFRUIT_SENSOR_H
#define _ADAFRUIT_SENSOR_H
#if ARDUINO >= 100
 #include "Arduino.h"
 #include "Print.h"
#else
 #include "WProgram.h"
#endif
#define SENSORS_GRAVITY_EARTH             (9.80665F)
#define SENSORS_GRAVITY_MOON              (1.6F)
#define SENSORS_GRAVITY_SUN               (275.0F)
#define SENSORS_GRAVITY_STANDARD          (SENSORS_GRAVITY_EARTH)
#define SENSORS_MAGFIELD_EARTH_MAX        (60.0F)
#define SENSORS_MAGFIELD_EARTH_MIN        (30.0F)
#define SENSORS_PRESSURE_SEALEVELHPA      (1013.25F)
#define SENSORS_DPS_TO_RADS               (0.017453293F)
#define SENSORS_GAUSS_TO_MICROTESLA       (100)
typedef enum
{
  SENSOR_TYPE_ACCELEROMETER         = (1),
  SENSOR_TYPE_MAGNETIC_FIELD        = (2),
  SENSOR_TYPE_ORIENTATION           = (3),
  SENSOR_TYPE_GYROSCOPE             = (4),
  SENSOR_TYPE_LIGHT                 = (5),
  SENSOR_TYPE_PRESSURE              = (6),
  SENSOR_TYPE_PROXIMITY             = (8),
  SENSOR_TYPE_GRAVITY               = (9),
  SENSOR_TYPE_LINEAR_ACCELERATION   = (10),
  SENSOR_TYPE_ROTATION_VECTOR       = (11),
  SENSOR_TYPE_RELATIVE_HUMIDITY     = (12),
  SENSOR_TYPE_AMBIENT_TEMPERATURE   = (13),
  SENSOR_TYPE_VOLTAGE               = (15),
  SENSOR_TYPE_CURRENT               = (16),
  SENSOR_TYPE_COLOR                 = (17)
} sensors_type_t;
typedef struct {
    union {
        float v[3];
        struct {
            float x;
            float y;
            float z;
        };
        struct {
            float roll;
            float pitch;
            float heading;
        };
    };
    int8_t status;
    uint8_t reserved[3];
} sensors_vec_t;
typedef struct {
    union {
        float c[3];
        struct {
            float r;
            float g;
            float b;
        };
    };
    uint32_t rgba;
} sensors_color_t;
typedef struct
{
    int32_t version;
    int32_t sensor_id;
    int32_t type;
    int32_t reserved0;
    int32_t timestamp;
    union
    {
        float           data[4];
        sensors_vec_t   acceleration;
        sensors_vec_t   magnetic;
        sensors_vec_t   orientation;
        sensors_vec_t   gyro;
        float           temperature;
        float           distance;
        float           light;
        float           pressure;
        float           relative_humidity;
        float           current;
        float           voltage;
        sensors_color_t color;
    };
} sensors_event_t;
typedef struct
{
    char     name[12];
    int32_t  version;
    int32_t  sensor_id;
    int32_t  type;
    float    max_value;
    float    min_value;
    float    resolution;
    int32_t  min_delay;
} sensor_t;
class Adafruit_Sensor {
 public:
  Adafruit_Sensor() {}
  virtual ~Adafruit_Sensor() {}
  virtual void enableAutoRange(bool enabled) {};
  virtual bool getEvent(sensors_event_t*) = 0;
  virtual void getSensor(sensor_t*) = 0;
 private:
  bool _autoRange;
};
#endif