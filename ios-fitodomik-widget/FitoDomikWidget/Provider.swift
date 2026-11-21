import WidgetKit
import SwiftUI
struct FitoDomikWidgetEntry: TimelineEntry {
    let date: Date
    let state: FitoDomikWidgetState
    let status: FitoDomikStatus?
    let cachedAt: Date?
    let theme: FitoDomikTheme
    static func placeholder(theme: FitoDomikTheme = .green) -> FitoDomikWidgetEntry {
        FitoDomikWidgetEntry(
            date: Date(),
            state: .loading,
            status: .mock,
            cachedAt: nil,
            theme: theme
        )
    }
    static func preview(
        state: FitoDomikWidgetState = .data,
        status: FitoDomikStatus? = .mock,
        theme: FitoDomikTheme = .green
    ) -> FitoDomikWidgetEntry {
        FitoDomikWidgetEntry(
            date: Date(),
            state: state,
            status: status,
            cachedAt: Date(),
            theme: theme
        )
    }
}
enum FitoDomikWidgetState: Equatable {
    case noSettings
    case loading
    case data
    case cached(message: String)
    case offline(message: String)
    case apiError(message: String)
    case invalidCredentials(message: String)
    var message: String? {
        switch self {
        case .noSettings:
            return "Откройте приложение и сохраните User ID"
        case .loading:
            return "Обновляем данные"
        case .data:
            return nil
        case .cached(let message),
             .offline(let message),
             .apiError(let message),
             .invalidCredentials(let message):
            return message
        }
    }
}
struct Provider: TimelineProvider {
    func placeholder(in context: Context) -> FitoDomikWidgetEntry {
        FitoDomikWidgetEntry.placeholder(theme: WidgetSettings.shared.theme)
    }
    func getSnapshot(in context: Context, completion: @escaping (FitoDomikWidgetEntry) -> Void) {
        if context.isPreview {
            completion(.preview())
            return
        }
        completion(snapshotEntry())
    }
    func getTimeline(in context: Context, completion: @escaping (Timeline<FitoDomikWidgetEntry>) -> Void) {
        Task {
            let entry = await timelineEntry()
            let nextUpdate = Calendar.current.date(byAdding: .minute, value: 20, to: Date()) ?? Date().addingTimeInterval(20 * 60)
            completion(Timeline(entries: [entry], policy: .after(nextUpdate)))
        }
    }
    private func snapshotEntry() -> FitoDomikWidgetEntry {
        let settings = WidgetSettings.shared.snapshot()
        guard settings.hasUserId else {
            return FitoDomikWidgetEntry(
                date: Date(),
                state: .noSettings,
                status: FitoDomikCache.shared.load()?.status,
                cachedAt: FitoDomikCache.shared.load()?.cachedAt,
                theme: settings.theme
            )
        }
        if let cached = FitoDomikCache.shared.load() {
            return FitoDomikWidgetEntry(
                date: Date(),
                state: cached.status.isOnline ? .cached(message: "Показаны последние данные") : .offline(message: "Устройство offline"),
                status: cached.status,
                cachedAt: cached.cachedAt,
                theme: settings.theme
            )
        }
        return FitoDomikWidgetEntry(
            date: Date(),
            state: .loading,
            status: .mock,
            cachedAt: nil,
            theme: settings.theme
        )
    }
    private func timelineEntry() async -> FitoDomikWidgetEntry {
        let settings = WidgetSettings.shared.snapshot()
        let now = Date()
        guard settings.hasUserId else {
            return FitoDomikWidgetEntry(
                date: now,
                state: .noSettings,
                status: FitoDomikCache.shared.load()?.status,
                cachedAt: FitoDomikCache.shared.load()?.cachedAt,
                theme: settings.theme
            )
        }
        do {
            let status = try await FitoDomikAPI.shared.fetchStatus(using: settings)
            guard WidgetSettings.shared.snapshot() == settings else {
                return FitoDomikWidgetEntry(date: now, state: .noSettings, status: nil, cachedAt: nil, theme: WidgetSettings.shared.snapshot().theme)
            }
            FitoDomikCache.shared.save(status, cachedAt: now, using: settings)
            return FitoDomikWidgetEntry(
                date: now,
                state: status.isOnline ? .data : .offline(message: "Устройство offline"),
                status: status,
                cachedAt: now,
                theme: settings.theme
            )
        } catch let error as FitoDomikAPIError {
            return entryForError(error, settings: settings, now: now)
        } catch {
            return entryForError(.siteUnavailable("Сайт временно недоступен"), settings: settings, now: now)
        }
    }
    private func entryForError(
        _ error: FitoDomikAPIError,
        settings: WidgetSettingsSnapshot,
        now: Date
    ) -> FitoDomikWidgetEntry {
        let cached = FitoDomikCache.shared.load()
        switch error {
        case .invalidCredentials:
            return FitoDomikWidgetEntry(
                date: now,
                state: .invalidCredentials(message: error.localizedDescription),
                status: cached?.status,
                cachedAt: cached?.cachedAt,
                theme: settings.theme
            )
        case .noSettings:
            return FitoDomikWidgetEntry(
                date: now,
                state: .noSettings,
                status: cached?.status,
                cachedAt: cached?.cachedAt,
                theme: settings.theme
            )
        default:
            if let cached {
                return FitoDomikWidgetEntry(
                    date: now,
                    state: cached.status.isOnline
                        ? .cached(message: "Сайт временно недоступен. Показан кэш")
                        : .offline(message: "Нет связи. Показан кэш"),
                    status: cached.status,
                    cachedAt: cached.cachedAt,
                    theme: settings.theme
                )
            }
            return FitoDomikWidgetEntry(
                date: now,
                state: .apiError(message: error.localizedDescription),
                status: nil,
                cachedAt: nil,
                theme: settings.theme
            )
        }
    }
}
typealias SimpleEntry = FitoDomikWidgetEntry
