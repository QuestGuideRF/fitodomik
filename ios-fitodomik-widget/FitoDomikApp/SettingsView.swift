import SwiftUI
import WidgetKit
struct SettingsView: View {
    @Environment(\.colorScheme) private var colorScheme
    @State private var userId = ""
    @State private var deviceId = ""
    @State private var apiToken = ""
    @State private var selectedTheme: FitoDomikTheme = .green
    @State private var connectionState: ConnectionTestState = .idle
    @State private var lastStatus: FitoDomikStatus?
    @State private var alert: SettingsAlert?
    private var palette: FitoDomikThemePalette {
        selectedTheme.palette(for: colorScheme)
    }
    var body: some View {
        NavigationView {
            ScrollView {
                VStack(spacing: 14) {
                    headerView
                    credentialsCard
                    themeCard
                    actionCard
                    ConnectionTestView(state: connectionState, palette: palette)
                    lastDataCard
                    helpCard
                }
                .padding(16)
            }
            .background(palette.backgroundTop.ignoresSafeArea())
            .navigationTitle("ФитоДомик")
            .navigationBarTitleDisplayMode(.inline)
            .onAppear(perform: loadCurrentSettings)
            .alert(item: $alert) { alert in
                Alert(
                    title: Text(alert.title),
                    message: Text(alert.message),
                    dismissButton: .default(Text("OK"))
                )
            }
        }
        .navigationViewStyle(StackNavigationViewStyle())
        .preferredColorScheme(preferredColorScheme)
    }
    private var preferredColorScheme: ColorScheme? {
        switch selectedTheme {
        case .system:
            return nil
        case .light, .green:
            return .light
        case .dark:
            return .dark
        }
    }
    private var headerView: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 12) {
                ZStack {
                    RoundedRectangle(cornerRadius: 8)
                        .fill(palette.accent.opacity(palette.isDark ? 0.22 : 0.16))
                    Image(systemName: "leaf.fill")
                        .font(.system(size: 25, weight: .bold))
                        .foregroundColor(palette.accent)
                }
                .frame(width: 52, height: 52)
                VStack(alignment: .leading, spacing: 4) {
                    Text("ФитоДомик")
                        .font(.system(size: 28, weight: .bold))
                        .foregroundColor(palette.primaryText)
                    Text("Виджет умной мини-фермы")
                        .font(.system(size: 14, weight: .medium))
                        .foregroundColor(palette.secondaryText)
                }
                Spacer(minLength: 0)
            }
            appGroupStatus
        }
        .padding(16)
        .background(cardBackground)
    }
    private var credentialsCard: some View {
        settingsCard(title: "Доступ", systemImage: "person.crop.circle.badge.checkmark") {
            VStack(alignment: .leading, spacing: 12) {
                VStack(alignment: .leading, spacing: 6) {
                    Text("User ID")
                        .font(.system(size: 13, weight: .bold))
                        .foregroundColor(palette.primaryText)
                    TextField("Введите User ID", text: $userId)
                        .keyboardType(.numberPad)
                        .textFieldStyle(RoundedBorderTextFieldStyle())
                }
                VStack(alignment: .leading, spacing: 6) {
                    Text("Device ID")
                        .font(.system(size: 13, weight: .bold))
                        .foregroundColor(palette.primaryText)
                    TextField("Device ID устройства", text: $deviceId)
                        .textFieldStyle(RoundedBorderTextFieldStyle())
                }
                SecureField("API token аккаунта", text: $apiToken)
                    .textFieldStyle(RoundedBorderTextFieldStyle())
                Link(destination: URL(string: "https://fitodomik.ru/get_token.php")!) {
                    Label("Получить User ID на fitodomik.ru/get_token.php", systemImage: "link")
                        .font(.system(size: 12, weight: .semibold))
                        .foregroundColor(palette.accent)
                        .lineLimit(2)
                }
            }
        }
    }
    private var themeCard: some View {
        settingsCard(title: "Тема", systemImage: "paintpalette.fill") {
            VStack(spacing: 12) {
                LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 8) {
                    ForEach(FitoDomikTheme.allCases) { theme in
                        ThemeOptionButton(
                            theme: theme,
                            isSelected: selectedTheme == theme,
                            palette: palette
                        ) {
                            selectedTheme = theme
                        }
                    }
                }
                ThemePreviewView(theme: selectedTheme)
                    .frame(height: 132)
            }
        }
    }
    private var actionCard: some View {
        VStack(spacing: 10) {
            Button(action: saveSettings) {
                Label("Сохранить", systemImage: "checkmark.circle.fill")
                    .frame(maxWidth: .infinity)
            }
            .buttonStyle(FitoDomikButtonStyle(palette: palette, style: .primary))
            Button(action: checkConnection) {
                Label(
                    connectionState == .checking ? "Проверяем..." : "Проверить подключение",
                    systemImage: connectionState == .checking ? "arrow.triangle.2.circlepath" : "wifi"
                )
                .frame(maxWidth: .infinity)
            }
            .buttonStyle(FitoDomikButtonStyle(palette: palette, style: .secondary))
            .disabled(connectionState == .checking)
        }
        .padding(14)
        .background(cardBackground)
    }
    private var lastDataCard: some View {
        settingsCard(title: "Последние данные", systemImage: "chart.bar.fill") {
            if let status = lastStatus {
                VStack(alignment: .leading, spacing: 10) {
                    HStack {
                        Label(status.overallStatusText, systemImage: status.isOnline ? "wifi" : "wifi.slash")
                            .font(.system(size: 13, weight: .bold))
                            .foregroundColor(status.overallQuality.color(in: palette))
                        Spacer()
                        Text(status.updateTimeText)
                            .font(.system(size: 12, weight: .medium))
                            .foregroundColor(palette.secondaryText)
                    }
                    VStack(spacing: 8) {
                        ForEach(Array(status.sensorItems(includeUnavailable: false).prefix(4))) { item in
                            LastDataRow(item: item, palette: palette)
                        }
                    }
                }
            } else {
                Label("Нет сохраненных данных", systemImage: "tray")
                    .font(.system(size: 13, weight: .semibold))
                    .foregroundColor(palette.secondaryText)
            }
        }
    }
    private var helpCard: some View {
        settingsCard(title: "Памятка", systemImage: "questionmark.circle.fill") {
            VStack(alignment: .leading, spacing: 9) {
                HelpLine(text: "App Groups должен быть включен в app target и widget target.", palette: palette)
                HelpLine(text: "После сохранения виджет получает настройки через group.com.fitodomik.widget.", palette: palette)
                HelpLine(text: "Если тема не поменялась, удалите виджет с экрана и добавьте заново.", palette: palette)
                HelpLine(text: "Если сайт недоступен, виджет покажет последние сохраненные данные.", palette: palette)
            }
        }
    }
    private var appGroupStatus: some View {
        let hasSettings = WidgetSettings.shared.snapshot().hasUserId
        let isAvailable = WidgetSettings.shared.isAppGroupAvailable
        return HStack(spacing: 8) {
            Image(systemName: isAvailable ? "checkmark.circle.fill" : "exclamationmark.triangle.fill")
                .foregroundColor(isAvailable ? palette.accent : palette.warning)
            Text(hasSettings ? "Настройки сохранены для виджета" : "Настройки еще не сохранены")
                .font(.system(size: 12, weight: .semibold))
                .foregroundColor(palette.secondaryText)
            Spacer(minLength: 0)
        }
        .padding(10)
        .background(
            RoundedRectangle(cornerRadius: 8)
                .fill(palette.elevatedCard)
        )
    }
    private var cardBackground: some View {
        RoundedRectangle(cornerRadius: 8)
            .fill(palette.card)
            .overlay(
                RoundedRectangle(cornerRadius: 8)
                    .stroke(palette.border, lineWidth: 1)
            )
    }
    private func settingsCard<Content: View>(
        title: String,
        systemImage: String,
        @ViewBuilder content: () -> Content
    ) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            Label(title, systemImage: systemImage)
                .font(.system(size: 15, weight: .bold))
                .foregroundColor(palette.primaryText)
            content()
        }
        .padding(14)
        .background(cardBackground)
    }
    private func loadCurrentSettings() {
        let snapshot = WidgetSettings.shared.snapshot()
        userId = snapshot.userId ?? ""
        deviceId = snapshot.deviceId ?? ""
        apiToken = snapshot.apiToken ?? ""
        selectedTheme = snapshot.theme
        lastStatus = FitoDomikCache.shared.load()?.status
    }
    private func saveSettings() {
        guard let snapshot = makeSettingsSnapshot() else {
            alert = SettingsAlert(
                title: "User ID не сохранен",
                message: "Введите числовой User ID с fitodomik.ru/get_token.php."
            )
            return
        }
        WidgetSettings.shared.save(
            userId: snapshot.normalizedUserId ?? "",
            theme: snapshot.theme,
            deviceId: snapshot.deviceId,
            apiToken: snapshot.apiToken
        )
        guard WidgetSettings.shared.apiToken == snapshot.apiToken else {
            alert = SettingsAlert(title: "Токен не сохранён", message: "Проверьте общую Keychain access group приложения и Widget Extension.")
            return
        }
        WidgetCenter.shared.reloadAllTimelines()
        lastStatus = FitoDomikCache.shared.load()?.status
        connectionState = .success("Настройки сохранены для виджета")
        alert = SettingsAlert(
            title: "Готово",
            message: "Настройки сохранены в App Group, виджет запросит свежие данные."
        )
    }
    private func checkConnection() {
        guard let snapshot = makeSettingsSnapshot() else {
            connectionState = .failure("Введите корректный User ID")
            return
        }
        connectionState = .checking
        Task {
            do {
                let status = try await FitoDomikAPI.shared.fetchStatus(using: snapshot)
                FitoDomikCache.shared.save(status, using: snapshot)
                WidgetCenter.shared.reloadAllTimelines()
                await MainActor.run {
                    lastStatus = status
                    connectionState = .success("Подключение работает, данные обновлены")
                }
            } catch {
                await MainActor.run {
                    connectionState = .failure(error.localizedDescription)
                }
            }
        }
    }
    private func makeSettingsSnapshot() -> WidgetSettingsSnapshot? {
        let trimmedUserId = userId.trimmingCharacters(in: .whitespacesAndNewlines)
        guard let numericId = Int(trimmedUserId), numericId > 0,
              !deviceId.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty,
              !apiToken.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
            return nil
        }
        return WidgetSettingsSnapshot(
            userId: trimmedUserId,
            theme: selectedTheme,
            deviceId: deviceId.trimmingCharacters(in: .whitespacesAndNewlines).nilIfEmpty,
            apiToken: apiToken.trimmingCharacters(in: .whitespacesAndNewlines)
        )
    }
}
struct SettingsAlert: Identifiable {
    let id = UUID()
    let title: String
    let message: String
}
struct LastDataRow: View {
    let item: SensorDisplayItem
    let palette: FitoDomikThemePalette
    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: item.systemImage)
                .font(.system(size: 14, weight: .bold))
                .foregroundColor(item.quality.color(in: palette))
                .frame(width: 22)
            Text(item.title)
                .font(.system(size: 13, weight: .semibold))
                .foregroundColor(palette.primaryText)
            Spacer()
            Text([item.value, item.unit].filter { !$0.isEmpty }.joined(separator: " "))
                .font(.system(size: 13, weight: .bold))
                .foregroundColor(palette.secondaryText)
        }
        .padding(9)
        .background(
            RoundedRectangle(cornerRadius: 8)
                .fill(palette.elevatedCard)
        )
    }
}
struct HelpLine: View {
    let text: String
    let palette: FitoDomikThemePalette
    var body: some View {
        HStack(alignment: .top, spacing: 8) {
            Image(systemName: "checkmark.circle.fill")
                .font(.system(size: 12, weight: .bold))
                .foregroundColor(palette.accent)
            Text(text)
                .font(.system(size: 12, weight: .medium))
                .foregroundColor(palette.secondaryText)
                .fixedSize(horizontal: false, vertical: true)
        }
    }
}
struct ThemeOptionButton: View {
    let theme: FitoDomikTheme
    let isSelected: Bool
    let palette: FitoDomikThemePalette
    let action: () -> Void
    var body: some View {
        Button(action: action) {
            HStack(spacing: 7) {
                Image(systemName: theme.systemImage)
                    .font(.system(size: 13, weight: .bold))
                Text(theme.displayName)
                    .font(.system(size: 13, weight: .bold))
                    .lineLimit(1)
                    .minimumScaleFactor(0.8)
            }
            .foregroundColor(isSelected ? palette.accent : palette.secondaryText)
            .frame(maxWidth: .infinity)
            .padding(.vertical, 10)
            .background(
                RoundedRectangle(cornerRadius: 8)
                    .fill(isSelected ? palette.accent.opacity(0.14) : palette.elevatedCard)
            )
            .overlay(
                RoundedRectangle(cornerRadius: 8)
                    .stroke(isSelected ? palette.accent : palette.border, lineWidth: 1)
            )
        }
        .buttonStyle(PlainButtonStyle())
    }
}
enum FitoDomikButtonStyleKind {
    case primary
    case secondary
}
struct FitoDomikButtonStyle: ButtonStyle {
    let palette: FitoDomikThemePalette
    let style: FitoDomikButtonStyleKind
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.system(size: 15, weight: .bold))
            .foregroundColor(style == .primary ? .white : palette.accent)
            .padding(.vertical, 13)
            .background(
                RoundedRectangle(cornerRadius: 8)
                    .fill(style == .primary ? palette.accent : palette.accent.opacity(0.13))
            )
            .opacity(configuration.isPressed ? 0.78 : 1)
    }
}
struct SettingsView_Previews: PreviewProvider {
    static var previews: some View {
        SettingsView()
    }
}
