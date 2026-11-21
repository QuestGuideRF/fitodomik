import SwiftUI
import WidgetKit
struct FitoDomikWidgetEntryView: View {
    let entry: FitoDomikWidgetEntry
    @Environment(\.widgetFamily) private var widgetFamily
    @Environment(\.colorScheme) private var colorScheme
    private var palette: FitoDomikThemePalette {
        entry.theme.palette(for: colorScheme)
    }
    var body: some View {
        content
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .fitoDomikWidgetBackground(FitoDomikWidgetBackground(theme: entry.theme, palette: palette))
    }
    @ViewBuilder
    private var content: some View {
        switch entry.state {
        case .noSettings:
            PlaceholderWidgetView(
                title: "Нет настроек",
                message: entry.state.message ?? "Откройте приложение и сохраните User ID",
                palette: palette
            )
        case .loading:
            LoadingWidgetView(palette: palette)
        case .apiError(let message):
            ErrorWidgetView(
                title: "Нет данных",
                message: message,
                systemImage: "exclamationmark.triangle.fill",
                palette: palette
            )
        case .invalidCredentials(let message):
            ErrorWidgetView(
                title: "Проверьте User ID",
                message: message,
                systemImage: "person.crop.circle.badge.exclamationmark",
                palette: palette
            )
        case .offline(let message):
            if let status = entry.status {
                OfflineWidgetView(status: status, message: message, palette: palette)
            } else {
                ErrorWidgetView(
                    title: "Нет связи",
                    message: message,
                    systemImage: "wifi.slash",
                    palette: palette
                )
            }
        case .cached(let message):
            if let status = entry.status {
                familyView(for: status, notice: message)
            } else {
                ErrorWidgetView(
                    title: "Нет кэша",
                    message: "Сайт временно недоступен",
                    systemImage: "tray.fill",
                    palette: palette
                )
            }
        case .data:
            if let status = entry.status {
                familyView(for: status, notice: nil)
            } else {
                ErrorWidgetView(
                    title: "Нет данных",
                    message: "API не вернул данные фермы",
                    systemImage: "exclamationmark.triangle.fill",
                    palette: palette
                )
            }
        }
    }
    @ViewBuilder
    private func familyView(for status: FitoDomikStatus, notice: String?) -> some View {
        switch widgetFamily {
        case .systemSmall:
            SmallWidgetView(status: status, notice: notice, palette: palette)
        case .systemMedium:
            MediumWidgetView(status: status, notice: notice, palette: palette)
        case .systemLarge:
            LargeWidgetView(status: status, notice: notice, palette: palette)
        default:
            SmallWidgetView(status: status, notice: notice, palette: palette)
        }
    }
}
private extension View {
    @ViewBuilder
    func fitoDomikWidgetBackground<Background: View>(_ background: Background) -> some View {
        if #available(iOSApplicationExtension 17.0, *) {
            containerBackground(for: .widget) {
                background
            }
        } else {
            self.background(background)
        }
    }
}