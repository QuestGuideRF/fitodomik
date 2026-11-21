import Foundation
enum FitoDomikAPIError: LocalizedError, Equatable {
    case noSettings
    case invalidURL
    case invalidCredentials(String)
    case siteUnavailable(String)
    case emptyData
    case apiError(String)
    case decodingFailed
    var errorDescription: String? {
        switch self {
        case .noSettings:
            return "Откройте приложение и сохраните User ID"
        case .invalidURL:
            return "Некорректный адрес API"
        case .invalidCredentials(let message):
            return message
        case .siteUnavailable(let message):
            return message
        case .emptyData:
            return "API не вернул данные фермы"
        case .apiError(let message):
            return message
        case .decodingFailed:
            return "Не удалось прочитать ответ сайта"
        }
    }
}
final class FitoDomikAPI {
    static let shared = FitoDomikAPI()
    private let endpoint = "https://fitodomik.ru/api/widget-data.php"
    private init() {}
    func fetchStatus(using settings: WidgetSettingsSnapshot = WidgetSettings.shared.snapshot()) async throws -> FitoDomikStatus {
        guard let userId = settings.normalizedUserId else {
            throw FitoDomikAPIError.noSettings
        }
        guard var components = URLComponents(string: endpoint) else {
            throw FitoDomikAPIError.invalidURL
        }
        _ = userId
        var queryItems: [URLQueryItem] = []
        if let deviceId = settings.deviceId?.trimmingCharacters(in: .whitespacesAndNewlines).nilIfEmpty {
            queryItems.append(URLQueryItem(name: "device_id", value: deviceId))
        }
        guard let token = settings.apiToken?.trimmingCharacters(in: .whitespacesAndNewlines).nilIfEmpty,
              let deviceId = settings.deviceId?.trimmingCharacters(in: .whitespacesAndNewlines).nilIfEmpty else {
            throw FitoDomikAPIError.invalidCredentials("Укажите Device ID и API token")
        }
        components.queryItems = queryItems
        guard let url = components.url else {
            throw FitoDomikAPIError.invalidURL
        }
        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.setValue(token, forHTTPHeaderField: "X-Auth-Token")
        request.setValue(deviceId, forHTTPHeaderField: "X-Device-ID")
        request.timeoutInterval = 20
        request.cachePolicy = .reloadIgnoringLocalCacheData
        let (data, response) = try await perform(request)
        guard let httpResponse = response as? HTTPURLResponse else {
            throw FitoDomikAPIError.siteUnavailable("Сайт временно недоступен")
        }
        switch httpResponse.statusCode {
        case 200...299:
            break
        case 401, 403, 404:
            throw FitoDomikAPIError.invalidCredentials("Неверный User ID или доступ к устройству закрыт")
        case 500...599:
            throw FitoDomikAPIError.siteUnavailable("Сайт временно недоступен")
        default:
            throw FitoDomikAPIError.apiError("Ошибка API: \(httpResponse.statusCode)")
        }
        do {
            return try decodeStatus(from: data)
        } catch let error as FitoDomikAPIError {
            throw error
        } catch {
            throw FitoDomikAPIError.decodingFailed
        }
    }
    private func perform(_ request: URLRequest) async throws -> (Data, URLResponse) {
        try await withCheckedThrowingContinuation { continuation in
            URLSession.shared.dataTask(with: request) { data, response, error in
                if let error {
                    continuation.resume(throwing: error)
                    return
                }
                guard let data, let response else {
                    continuation.resume(throwing: FitoDomikAPIError.siteUnavailable("Сайт временно недоступен"))
                    return
                }
                continuation.resume(returning: (data, response))
            }.resume()
        }
    }
    private func decodeStatus(from data: Data) throws -> FitoDomikStatus {
        let decoder = JSONDecoder()
        let response = try decoder.decode(FitoDomikAPIResponse.self, from: data)
        if response.ok == false {
            let message = response.message ?? response.error ?? "API вернул ошибку"
            if message.lowercased().contains("user") || message.lowercased().contains("token") || message.lowercased().contains("id") {
                throw FitoDomikAPIError.invalidCredentials("Неверный User ID")
            }
            throw FitoDomikAPIError.apiError(message)
        }
        let dataModel: FitoDomikAPIData
        if let responseData = response.data {
            dataModel = responseData
        } else {
            dataModel = try decoder.decode(FitoDomikAPIData.self, from: data)
        }
        guard dataModel.hasUsefulData else {
            throw FitoDomikAPIError.emptyData
        }
        let serverDate = DateFormatters.parseServerDate(response.updatedAt ?? dataModel.updatedAt)
        let timestampDate = DateFormatters.parseTimestamp(response.timestamp ?? dataModel.timestamp)
        let lastUpdate = serverDate ?? timestampDate ?? Date()
        let isOnline = dataModel.isOnline ?? Date().timeIntervalSince(lastUpdate) < 90 * 60
        return FitoDomikStatus(
            deviceName: dataModel.deviceName ?? "ФитоДомик",
            isOnline: isOnline,
            lastUpdate: lastUpdate,
            temperature: dataModel.temperature?.measurement(defaultUnit: "°C", defaultIcon: "thermometer"),
            airHumidity: dataModel.airHumidity?.measurement(defaultUnit: "%", defaultIcon: "humidity.fill"),
            soilMoisture: dataModel.soilMoisture?.measurement(defaultUnit: "%", defaultIcon: "drop.fill"),
            pressure: dataModel.pressure?.measurement(defaultUnit: "мм", defaultIcon: "gauge.medium"),
            co2: dataModel.co2?.measurement(defaultUnit: "ppm", defaultIcon: "wind"),
            light: dataModel.light?.measurement(defaultUnit: "лк", defaultIcon: "sun.max.fill"),
            pumpStatus: dataModel.pumpStatus?.relayStatus,
            fanStatus: dataModel.fanStatus?.relayStatus,
            lampStatus: dataModel.lampStatus?.relayStatus,
            curtainsStatus: dataModel.curtainsStatus?.relayStatus,
            plantStatus: dataModel.plantStatus,
            recommendation: dataModel.recommendation ?? dataModel.analysis,
            photoUrl: dataModel.photoUrl,
            errorMessage: nil
        )
    }
}
private struct FitoDomikAPIResponse: Decodable {
    let ok: Bool?
    let data: FitoDomikAPIData?
    let message: String?
    let error: String?
    let updatedAt: String?
    let timestamp: Double?
    private enum CodingKeys: String, CodingKey {
        case ok
        case success
        case data
        case message
        case error
        case updatedAt = "updated_at"
        case updatedAtCamel = "updatedAt"
        case timestamp
    }
    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        ok = container.decodeFirstFlexibleBool(for: [.ok, .success])
        data = container.decodeFirst(FitoDomikAPIData.self, for: [.data])
        message = container.decodeFirstString(for: [.message])
        error = container.decodeFirstString(for: [.error])
        updatedAt = container.decodeFirstString(for: [.updatedAt, .updatedAtCamel])
        timestamp = container.decodeFirstFlexibleDouble(for: [.timestamp])
    }
}
private struct FitoDomikAPIData: Decodable {
    let deviceName: String?
    let isOnline: Bool?
    let updatedAt: String?
    let timestamp: Double?
    let temperature: APISensorValue?
    let airHumidity: APISensorValue?
    let soilMoisture: APISensorValue?
    let pressure: APISensorValue?
    let co2: APISensorValue?
    let light: APISensorValue?
    let pumpStatus: APIStateValue?
    let fanStatus: APIStateValue?
    let lampStatus: APIStateValue?
    let curtainsStatus: APIStateValue?
    let plantStatus: String?
    let recommendation: String?
    let analysis: String?
    let photoUrl: String?
    var hasUsefulData: Bool {
        [
            temperature,
            airHumidity,
            soilMoisture,
            pressure,
            co2,
            light
        ].contains { $0 != nil } ||
        [pumpStatus, fanStatus, lampStatus, curtainsStatus].contains { $0 != nil } ||
        plantStatus?.nilIfEmpty != nil ||
        recommendation?.nilIfEmpty != nil ||
        analysis?.nilIfEmpty != nil ||
        photoUrl?.nilIfEmpty != nil
    }
    private enum CodingKeys: String, CodingKey {
        case deviceName = "device_name"
        case deviceNameCamel = "deviceName"
        case name
        case device
        case isOnline = "is_online"
        case isOnlineCamel = "isOnline"
        case online
        case status
        case updatedAt = "updated_at"
        case updatedAtCamel = "updatedAt"
        case timestamp
        case temperature
        case temp
        case humidity
        case airHumidity = "air_humidity"
        case airHumidityCamel = "airHumidity"
        case soilMoisture = "soil_moisture"
        case soilMoistureCamel = "soilMoisture"
        case soil
        case pressure
        case co2
        case light
        case pump
        case pumpStatus = "pump_status"
        case pumpStatusCamel = "pumpStatus"
        case fan
        case fanStatus = "fan_status"
        case fanStatusCamel = "fanStatus"
        case lamp
        case lampStatus = "lamp_status"
        case lampStatusCamel = "lampStatus"
        case curtains
        case curtainsStatus = "curtains_status"
        case curtainsStatusCamel = "curtainsStatus"
        case plantStatus = "plant_status"
        case plantStatusCamel = "plantStatus"
        case recommendation
        case analysis
        case photoUrl = "photo_url"
        case photoUrlCamel = "photoUrl"
    }
    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        deviceName = container.decodeFirstString(for: [.deviceName, .deviceNameCamel, .name, .device])
        isOnline = container.decodeFirstFlexibleBool(for: [.isOnline, .isOnlineCamel, .online, .status])
        updatedAt = container.decodeFirstString(for: [.updatedAt, .updatedAtCamel])
        timestamp = container.decodeFirstFlexibleDouble(for: [.timestamp])
        temperature = container.decodeFirst(APISensorValue.self, for: [.temperature, .temp])
        airHumidity = container.decodeFirst(APISensorValue.self, for: [.airHumidity, .airHumidityCamel, .humidity])
        soilMoisture = container.decodeFirst(APISensorValue.self, for: [.soilMoisture, .soilMoistureCamel, .soil])
        pressure = container.decodeFirst(APISensorValue.self, for: [.pressure])
        co2 = container.decodeFirst(APISensorValue.self, for: [.co2])
        light = container.decodeFirst(APISensorValue.self, for: [.light])
        pumpStatus = container.decodeFirst(APIStateValue.self, for: [.pumpStatus, .pumpStatusCamel, .pump])
        fanStatus = container.decodeFirst(APIStateValue.self, for: [.fanStatus, .fanStatusCamel, .fan])
        lampStatus = container.decodeFirst(APIStateValue.self, for: [.lampStatus, .lampStatusCamel, .lamp])
        curtainsStatus = container.decodeFirst(APIStateValue.self, for: [.curtainsStatus, .curtainsStatusCamel, .curtains])
        plantStatus = container.decodeFirstString(for: [.plantStatus, .plantStatusCamel])
        recommendation = container.decodeFirstString(for: [.recommendation])
        analysis = container.decodeFirstString(for: [.analysis])
        photoUrl = container.decodeFirstString(for: [.photoUrl, .photoUrlCamel])
    }
}
private struct APISensorValue: Decodable {
    let rawValue: String?
    let numericValue: Double?
    let unit: String?
    let icon: String?
    private enum CodingKeys: String, CodingKey {
        case value
        case unit
        case icon
    }
    init(from decoder: Decoder) throws {
        if let container = try? decoder.container(keyedBy: CodingKeys.self) {
            rawValue = container.decodeFlexibleStringIfPresent(forKey: .value)
            numericValue = container.decodeFlexibleDoubleIfPresent(forKey: .value) ?? Self.extractNumber(from: rawValue)
            unit = container.decodeFlexibleStringIfPresent(forKey: .unit)
            icon = container.decodeFlexibleStringIfPresent(forKey: .icon)
            return
        }
        let container = try decoder.singleValueContainer()
        if let value = try? container.decode(Double.self) {
            rawValue = nil
            numericValue = value
        } else if let value = try? container.decode(String.self) {
            rawValue = value
            numericValue = Self.extractNumber(from: value)
        } else {
            rawValue = nil
            numericValue = nil
        }
        unit = nil
        icon = nil
    }
    func measurement(defaultUnit: String, defaultIcon: String) -> SensorMeasurement? {
        guard rawValue?.nilIfEmpty != nil || numericValue != nil else {
            return nil
        }
        return SensorMeasurement(
            value: numericValue,
            rawValue: rawValue,
            unit: unit?.nilIfEmpty ?? defaultUnit,
            icon: icon?.nilIfEmpty ?? defaultIcon
        )
    }
    private static func extractNumber(from value: String?) -> Double? {
        guard let value else { return nil }
        let normalized = value
            .replacingOccurrences(of: ",", with: ".")
            .filter { "0123456789.-".contains($0) }
        return Double(normalized)
    }
}
private struct APIStateValue: Decodable {
    let rawValue: String?
    let state: Bool?
    let icon: String?
    private enum CodingKeys: String, CodingKey {
        case value
        case state
        case status
        case icon
    }
    init(from decoder: Decoder) throws {
        if let container = try? decoder.container(keyedBy: CodingKeys.self) {
            rawValue = container.decodeFlexibleStringIfPresent(forKey: .value) ??
                container.decodeFlexibleStringIfPresent(forKey: .status)
            state = container.decodeFlexibleBoolIfPresent(forKey: .state) ??
                container.decodeFlexibleBoolIfPresent(forKey: .status) ??
                Self.state(from: rawValue)
            icon = container.decodeFlexibleStringIfPresent(forKey: .icon)
            return
        }
        let container = try decoder.singleValueContainer()
        if let bool = try? container.decode(Bool.self) {
            rawValue = nil
            state = bool
        } else if let int = try? container.decode(Int.self) {
            rawValue = nil
            state = int > 0
        } else if let string = try? container.decode(String.self) {
            rawValue = string
            state = Self.state(from: string)
        } else {
            rawValue = nil
            state = nil
        }
        icon = nil
    }
    var relayStatus: DeviceRelayStatus? {
        guard rawValue?.nilIfEmpty != nil || state != nil else {
            return nil
        }
        return DeviceRelayStatus(value: rawValue, isActive: state, icon: icon)
    }
    private static func state(from rawValue: String?) -> Bool? {
        guard let value = rawValue?.lowercased() else {
            return nil
        }
        if ["1", "true", "on", "online", "open", "opened"].contains(value) ||
            value.contains("вкл") ||
            value.contains("откры") ||
            value.contains("работ") {
            return true
        }
        if ["0", "false", "off", "offline", "closed"].contains(value) ||
            value.contains("выкл") ||
            value.contains("закры") ||
            value.contains("стоп") {
            return false
        }
        return nil
    }
}
private extension KeyedDecodingContainer {
    func decodeFirst<T: Decodable>(_ type: T.Type, for keys: [Key]) -> T? {
        for key in keys {
            if let value = try? decodeIfPresent(type, forKey: key) {
                return value
            }
        }
        return nil
    }
    func decodeFirstString(for keys: [Key]) -> String? {
        for key in keys {
            if let value = decodeFlexibleStringIfPresent(forKey: key)?.nilIfEmpty {
                return value
            }
        }
        return nil
    }
    func decodeFirstFlexibleDouble(for keys: [Key]) -> Double? {
        for key in keys {
            if let value = decodeFlexibleDoubleIfPresent(forKey: key) {
                return value
            }
        }
        return nil
    }
    func decodeFirstFlexibleBool(for keys: [Key]) -> Bool? {
        for key in keys {
            if let value = decodeFlexibleBoolIfPresent(forKey: key) {
                return value
            }
        }
        return nil
    }
    func decodeFlexibleStringIfPresent(forKey key: Key) -> String? {
        if let string = try? decodeIfPresent(String.self, forKey: key) {
            return string
        }
        if let int = try? decodeIfPresent(Int.self, forKey: key) {
            return String(int)
        }
        if let double = try? decodeIfPresent(Double.self, forKey: key) {
            return DateFormatters.decimalString(double, maximumFractionDigits: 2)
        }
        if let bool = try? decodeIfPresent(Bool.self, forKey: key) {
            return bool ? "true" : "false"
        }
        return nil
    }
    func decodeFlexibleDoubleIfPresent(forKey key: Key) -> Double? {
        if let double = try? decodeIfPresent(Double.self, forKey: key) {
            return double
        }
        if let int = try? decodeIfPresent(Int.self, forKey: key) {
            return Double(int)
        }
        if let string = try? decodeIfPresent(String.self, forKey: key) {
            let normalized = string
                .replacingOccurrences(of: ",", with: ".")
                .filter { "0123456789.-".contains($0) }
            return Double(normalized)
        }
        return nil
    }
    func decodeFlexibleBoolIfPresent(forKey key: Key) -> Bool? {
        if let bool = try? decodeIfPresent(Bool.self, forKey: key) {
            return bool
        }
        if let int = try? decodeIfPresent(Int.self, forKey: key) {
            return int > 0
        }
        if let string = try? decodeIfPresent(String.self, forKey: key) {
            let value = string.lowercased()
            if ["1", "true", "yes", "on", "online"].contains(value) || value.contains("вкл") {
                return true
            }
            if ["0", "false", "no", "off", "offline"].contains(value) || value.contains("выкл") {
                return false
            }
        }
        return nil
    }
}
