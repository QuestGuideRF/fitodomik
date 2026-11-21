import Foundation
import CryptoKit
struct CachedFitoDomikSnapshot: Codable, Equatable {
    let status: FitoDomikStatus
    let cachedAt: Date
    let scope: String
}
final class FitoDomikCache {
    static let shared = FitoDomikCache()
    private enum Key {
        static let snapshot = "fitodomik_cached_status_v3"
    }
    private let settings: WidgetSettings
    init(settings: WidgetSettings = .shared) {
        self.settings = settings
    }
    func save(_ status: FitoDomikStatus, cachedAt: Date = Date(), using identity: WidgetSettingsSnapshot) {
        guard scope(identity) == currentScope else { return }
        let snapshot = CachedFitoDomikSnapshot(status: status, cachedAt: cachedAt, scope: scope(identity))
        let encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .iso8601
        guard let data = try? encoder.encode(snapshot) else {
            return
        }
        settings.userDefaults.set(data, forKey: Key.snapshot)
        settings.userDefaults.synchronize()
    }
    func load() -> CachedFitoDomikSnapshot? {
        guard let data = settings.userDefaults.data(forKey: Key.snapshot) else {
            return nil
        }
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
        guard let snapshot = try? decoder.decode(CachedFitoDomikSnapshot.self, from: data),
              snapshot.scope == currentScope else { return nil }
        return snapshot
    }
    private var currentScope: String {
        scope(settings.snapshot())
    }
    private func scope(_ value: WidgetSettingsSnapshot) -> String {
        let identity = "\(value.normalizedUserId ?? "")|\(value.deviceId ?? "")|\(value.apiToken ?? "")"
        return SHA256.hash(data: Data(identity.utf8)).map { String(format: "%02x", $0) }.joined()
    }
    func clear() {
        settings.userDefaults.removeObject(forKey: Key.snapshot)
        settings.userDefaults.synchronize()
    }
}
