import Foundation
struct SensorMeasurement: Codable, Equatable {
    let value: Double?
    let rawValue: String?
    let unit: String?
    let icon: String?
    var hasValue: Bool {
        value != nil || rawValue?.trimmingCharacters(in: .whitespacesAndNewlines).nilIfEmpty != nil
    }
    func valueText(maximumFractionDigits: Int = 1) -> String {
        if let value {
            return DateFormatters.decimalString(value, maximumFractionDigits: maximumFractionDigits)
        }
        if let rawValue = rawValue?.trimmingCharacters(in: .whitespacesAndNewlines).nilIfEmpty {
            return rawValue
        }
        return "Нет данных"
    }
    var unitText: String {
        unit?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
    }
}
struct DeviceRelayStatus: Codable, Equatable {
    let value: String?
    let isActive: Bool?
    let icon: String?
    var displayValue: String {
        if let value = value?.trimmingCharacters(in: .whitespacesAndNewlines).nilIfEmpty {
            return value
        }
        if let isActive {
            return isActive ? "Включено" : "Выключено"
        }
        return "Нет данных"
    }
    var shortValue: String {
        let value = displayValue.lowercased()
        if value.contains("откры") {
            return "Откр"
        }
        if value.contains("закры") {
            return "Закр"
        }
        if let isActive {
            return isActive ? "Вкл" : "Выкл"
        }
        if displayValue == "Нет данных" {
            return displayValue
        }
        return String(displayValue.prefix(5))
    }
}
enum SensorQuality: String, Codable, Equatable {
    case normal
    case warning
    case critical
    case unavailable
    case info
}
struct SensorDisplayItem: Identifiable, Equatable {
    let id: String
    let title: String
    let value: String
    let unit: String
    let systemImage: String
    let quality: SensorQuality
    let caption: String?
}
struct DeviceStatusItem: Identifiable, Equatable {
    let id: String
    let title: String
    let value: String
    let systemImage: String
    let isActive: Bool?
}
struct FitoDomikStatus: Codable, Equatable {
    var deviceName: String
    var isOnline: Bool
    var lastUpdate: Date?
    var temperature: SensorMeasurement?
    var airHumidity: SensorMeasurement?
    var soilMoisture: SensorMeasurement?
    var pressure: SensorMeasurement?
    var co2: SensorMeasurement?
    var light: SensorMeasurement?
    var pumpStatus: DeviceRelayStatus?
    var fanStatus: DeviceRelayStatus?
    var lampStatus: DeviceRelayStatus?
    var curtainsStatus: DeviceRelayStatus?
    var plantStatus: String?
    var recommendation: String?
    var photoUrl: String?
    var errorMessage: String?
    init(
        deviceName: String = "ФитоДомик",
        isOnline: Bool = true,
        lastUpdate: Date? = nil,
        temperature: SensorMeasurement? = nil,
        airHumidity: SensorMeasurement? = nil,
        soilMoisture: SensorMeasurement? = nil,
        pressure: SensorMeasurement? = nil,
        co2: SensorMeasurement? = nil,
        light: SensorMeasurement? = nil,
        pumpStatus: DeviceRelayStatus? = nil,
        fanStatus: DeviceRelayStatus? = nil,
        lampStatus: DeviceRelayStatus? = nil,
        curtainsStatus: DeviceRelayStatus? = nil,
        plantStatus: String? = nil,
        recommendation: String? = nil,
        photoUrl: String? = nil,
        errorMessage: String? = nil
    ) {
        self.deviceName = deviceName
        self.isOnline = isOnline
        self.lastUpdate = lastUpdate
        self.temperature = temperature
        self.airHumidity = airHumidity
        self.soilMoisture = soilMoisture
        self.pressure = pressure
        self.co2 = co2
        self.light = light
        self.pumpStatus = pumpStatus
        self.fanStatus = fanStatus
        self.lampStatus = lampStatus
        self.curtainsStatus = curtainsStatus
        self.plantStatus = plantStatus
        self.recommendation = recommendation
        self.photoUrl = photoUrl
        self.errorMessage = errorMessage
    }
    var title: String {
        deviceName.trimmingCharacters(in: .whitespacesAndNewlines).nilIfEmpty ?? "ФитоДомик"
    }
    var lastUpdateText: String {
        DateFormatters.relativeWidgetString(from: lastUpdate)
    }
    var updateTimeText: String {
        DateFormatters.widgetTimeString(from: lastUpdate)
    }
    var overallQuality: SensorQuality {
        guard isOnline else { return .critical }
        let qualities = sensorItems(includeUnavailable: false).map(\.quality)
        if qualities.contains(.critical) {
            return .critical
        }
        if qualities.contains(.warning) {
            return .warning
        }
        return qualities.isEmpty ? .unavailable : .normal
    }
    var overallStatusText: String {
        guard isOnline else { return "Нет связи" }
        switch overallQuality {
        case .normal:
            return "Хорошо"
        case .warning:
            return "Проверьте"
        case .critical:
            return "Внимание"
        case .unavailable:
            return "Нет данных"
        case .info:
            return "Работает"
        }
    }
    var plantStatusText: String {
        if let plantStatus = plantStatus?.trimmingCharacters(in: .whitespacesAndNewlines).nilIfEmpty {
            return plantStatus
        }
        if let recommendation = recommendation?.trimmingCharacters(in: .whitespacesAndNewlines).nilIfEmpty {
            return recommendation
        }
        switch overallQuality {
        case .normal:
            return "Растение в порядке"
        case .warning:
            return "Нужна небольшая проверка"
        case .critical:
            return isOnline ? "Проверьте условия роста" : "Ферма сейчас offline"
        case .unavailable:
            return "Данных пока нет"
        case .info:
            return "Система активна"
        }
    }
    func smallSensorItems() -> [SensorDisplayItem] {
        [
            makeSensorItem(
                id: "temperature",
                title: "Температура",
                measurement: temperature,
                unit: "°C",
                systemImage: "thermometer",
                quality: qualityForTemperature()
            ),
            makeSensorItem(
                id: "soil",
                title: "Почва",
                measurement: soilMoisture,
                unit: "%",
                systemImage: "drop.fill",
                quality: qualityForSoilMoisture()
            )
        ]
    }
    func sensorItems(includeUnavailable: Bool) -> [SensorDisplayItem] {
        let items = [
            makeSensorItem(
                id: "temperature",
                title: "Температура",
                measurement: temperature,
                unit: "°C",
                systemImage: "thermometer",
                quality: qualityForTemperature()
            ),
            makeSensorItem(
                id: "airHumidity",
                title: "Воздух",
                measurement: airHumidity,
                unit: "%",
                systemImage: "humidity.fill",
                quality: qualityForAirHumidity()
            ),
            makeSensorItem(
                id: "soil",
                title: "Почва",
                measurement: soilMoisture,
                unit: "%",
                systemImage: "drop.fill",
                quality: qualityForSoilMoisture()
            ),
            makeSensorItem(
                id: "co2",
                title: "CO2",
                measurement: co2,
                unit: "ppm",
                systemImage: "wind",
                quality: qualityForCO2()
            ),
            makeSensorItem(
                id: "pressure",
                title: "Давление",
                measurement: pressure,
                unit: "мм",
                systemImage: "gauge.medium",
                quality: qualityForPressure()
            ),
            makeSensorItem(
                id: "light",
                title: "Свет",
                measurement: light,
                unit: "лк",
                systemImage: "sun.max.fill",
                quality: light?.hasValue == true ? .info : .unavailable
            )
        ]
        return includeUnavailable ? items : items.filter { $0.quality != .unavailable }
    }
    func deviceItems() -> [DeviceStatusItem] {
        [
            makeDeviceItem(id: "lamp", title: "Лампа", relay: lampStatus, systemImage: "lightbulb.fill"),
            makeDeviceItem(id: "pump", title: "Насос", relay: pumpStatus, systemImage: "drop.circle.fill"),
            makeDeviceItem(id: "fan", title: "Вент.", relay: fanStatus, systemImage: "fanblades.fill"),
            makeDeviceItem(id: "curtains", title: "Шторы", relay: curtainsStatus, systemImage: "rectangle.split.2x1.fill")
        ].compactMap { $0 }
    }
    private func makeSensorItem(
        id: String,
        title: String,
        measurement: SensorMeasurement?,
        unit: String,
        systemImage: String,
        quality: SensorQuality
    ) -> SensorDisplayItem {
        let value = measurement?.valueText() ?? "Нет данных"
        let unitText = measurement?.unitText.nilIfEmpty ?? unit
        return SensorDisplayItem(
            id: id,
            title: title,
            value: value,
            unit: quality == .unavailable ? "" : unitText,
            systemImage: systemImage,
            quality: quality,
            caption: quality == .unavailable ? "нет показаний" : nil
        )
    }
    private func makeDeviceItem(
        id: String,
        title: String,
        relay: DeviceRelayStatus?,
        systemImage: String
    ) -> DeviceStatusItem? {
        guard let relay else { return nil }
        return DeviceStatusItem(
            id: id,
            title: title,
            value: relay.shortValue,
            systemImage: systemImage,
            isActive: relay.isActive
        )
    }
    private func qualityForTemperature() -> SensorQuality {
        guard let value = temperature?.value else {
            return temperature?.hasValue == true ? .info : .unavailable
        }
        switch value {
        case 18...30:
            return .normal
        case 15..<18, 30...34:
            return .warning
        default:
            return .critical
        }
    }
    private func qualityForAirHumidity() -> SensorQuality {
        guard let value = airHumidity?.value else {
            return airHumidity?.hasValue == true ? .info : .unavailable
        }
        switch value {
        case 45...75:
            return .normal
        case 35..<45, 75...85:
            return .warning
        default:
            return .critical
        }
    }
    private func qualityForSoilMoisture() -> SensorQuality {
        guard let value = soilMoisture?.value else {
            return soilMoisture?.hasValue == true ? .info : .unavailable
        }
        switch value {
        case 35...70:
            return .normal
        case 20..<35, 70...85:
            return .warning
        default:
            return .critical
        }
    }
    private func qualityForCO2() -> SensorQuality {
        guard let value = co2?.value else {
            return co2?.hasValue == true ? .info : .unavailable
        }
        switch value {
        case ..<900:
            return .normal
        case 900...1500:
            return .warning
        default:
            return .critical
        }
    }
    private func qualityForPressure() -> SensorQuality {
        guard let value = pressure?.value else {
            return pressure?.hasValue == true ? .info : .unavailable
        }
        switch value {
        case 730...780:
            return .normal
        case 700..<730, 780...800:
            return .warning
        default:
            return .info
        }
    }
}
extension FitoDomikStatus {
    static var mock: FitoDomikStatus {
        FitoDomikStatus(
            deviceName: "ФитоДомик Mini",
            isOnline: true,
            lastUpdate: Date(),
            temperature: SensorMeasurement(value: 24.6, rawValue: nil, unit: "°C", icon: nil),
            airHumidity: SensorMeasurement(value: 62, rawValue: nil, unit: "%", icon: nil),
            soilMoisture: SensorMeasurement(value: 48, rawValue: nil, unit: "%", icon: nil),
            pressure: SensorMeasurement(value: 760, rawValue: nil, unit: "мм", icon: nil),
            co2: SensorMeasurement(value: 540, rawValue: nil, unit: "ppm", icon: nil),
            light: SensorMeasurement(value: 830, rawValue: nil, unit: "лк", icon: nil),
            pumpStatus: DeviceRelayStatus(value: "Выключено", isActive: false, icon: nil),
            fanStatus: DeviceRelayStatus(value: "Включено", isActive: true, icon: nil),
            lampStatus: DeviceRelayStatus(value: "Включено", isActive: true, icon: nil),
            curtainsStatus: DeviceRelayStatus(value: "Открыты", isActive: true, icon: nil),
            plantStatus: "Рост стабильный",
            recommendation: "Следующий полив можно проверить вечером",
            photoUrl: nil,
            errorMessage: nil
        )
    }
    static var offlineMock: FitoDomikStatus {
        var status = FitoDomikStatus.mock
        status.isOnline = false
        status.lastUpdate = Calendar.current.date(byAdding: .hour, value: -2, to: Date())
        return status
    }
}