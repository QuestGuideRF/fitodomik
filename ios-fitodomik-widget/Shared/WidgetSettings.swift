import Foundation
import Security
struct WidgetSettingsSnapshot: Equatable {
    let userId: String?
    let theme: FitoDomikTheme
    let deviceId: String?
    let apiToken: String?
    var normalizedUserId: String? {
        userId?.trimmingCharacters(in: .whitespacesAndNewlines).nilIfEmpty
    }
    var hasUserId: Bool {
        normalizedUserId != nil
    }
}
final class WidgetSettings {
    static let shared = WidgetSettings()
    static let appGroupIdentifier = "group.com.fitodomik.widget"
    private enum Key {
        static let userId = "widget_user_id"
        static let theme = "widget_theme"
        static let deviceId = "widget_device_id"
        static let apiToken = "widget_api_token"
    }
    private init() {}
    var isAppGroupAvailable: Bool {
        UserDefaults(suiteName: Self.appGroupIdentifier) != nil
    }
    var userDefaults: UserDefaults {
        UserDefaults(suiteName: Self.appGroupIdentifier) ?? .standard
    }
    var userId: String? {
        get { stringValue(forKey: Key.userId) }
        set { setOptionalString(newValue, forKey: Key.userId) }
    }
    var theme: FitoDomikTheme {
        get {
            guard
                let rawValue = userDefaults.string(forKey: Key.theme),
                let theme = FitoDomikTheme(rawValue: rawValue)
            else {
                return .green
            }
            return theme
        }
        set {
            userDefaults.set(newValue.rawValue, forKey: Key.theme)
            userDefaults.synchronize()
        }
    }
    var deviceId: String? {
        get { stringValue(forKey: Key.deviceId) }
        set { setOptionalString(newValue, forKey: Key.deviceId) }
    }
    var apiToken: String? {
        get {
            guard var query = tokenQuery else { return nil }
            query[kSecReturnData as String] = true
            query[kSecMatchLimit as String] = kSecMatchLimitOne
            var result: CFTypeRef?
            guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess,
                  let data = result as? Data else { return nil }
            return String(data: data, encoding: .utf8)
        }
        set {
            userDefaults.removeObject(forKey: Key.apiToken)
            guard var query = tokenQuery else { return }
            let value = newValue?.trimmingCharacters(in: .whitespacesAndNewlines).nilIfEmpty
            guard let value else { SecItemDelete(query as CFDictionary); return }
            let data = Data(value.utf8)
            let status = SecItemUpdate(query as CFDictionary, [kSecValueData as String: data] as CFDictionary)
            if status == errSecItemNotFound {
                query[kSecValueData as String] = data
                query[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
                SecItemAdd(query as CFDictionary, nil)
            }
        }
    }
    private var tokenQuery: [String: Any]? {
        guard let group = Bundle.main.object(forInfoDictionaryKey: "FitoDomikKeychainAccessGroup") as? String,
              !group.isEmpty, !group.contains("$(") else { return nil }
        return [kSecClass as String: kSecClassGenericPassword,
                kSecAttrService as String: "com.fitodomik.widget.api",
                kSecAttrAccount as String: "account-token",
                kSecAttrAccessGroup as String: group]
    }
    func snapshot() -> WidgetSettingsSnapshot {
        WidgetSettingsSnapshot(
            userId: userId,
            theme: theme,
            deviceId: deviceId,
            apiToken: apiToken
        )
    }
    func save(userId: String, theme: FitoDomikTheme, deviceId: String? = nil, apiToken: String? = nil) {
        self.userId = userId
        self.theme = theme
        self.deviceId = deviceId
        self.apiToken = apiToken
        userDefaults.synchronize()
    }
    private func stringValue(forKey key: String) -> String? {
        if let value = userDefaults.string(forKey: key)?.trimmingCharacters(in: .whitespacesAndNewlines).nilIfEmpty {
            return value
        }
        if let intValue = userDefaults.object(forKey: key) as? Int, intValue > 0 {
            return String(intValue)
        }
        return nil
    }
    private func setOptionalString(_ value: String?, forKey key: String) {
        guard let trimmed = value?.trimmingCharacters(in: .whitespacesAndNewlines).nilIfEmpty else {
            userDefaults.removeObject(forKey: key)
            userDefaults.synchronize()
            return
        }
        userDefaults.set(trimmed, forKey: key)
        userDefaults.synchronize()
    }
}
extension String {
    var nilIfEmpty: String? {
        isEmpty ? nil : self
    }
}
