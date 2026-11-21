import SwiftUI
enum ConnectionTestState: Equatable {
    case idle
    case checking
    case success(String)
    case failure(String)
}
struct ConnectionTestView: View {
    let state: ConnectionTestState
    let palette: FitoDomikThemePalette
    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: iconName)
                .font(.system(size: 16, weight: .bold))
                .foregroundColor(iconColor)
                .frame(width: 24)
            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .font(.system(size: 13, weight: .bold))
                    .foregroundColor(palette.primaryText)
                Text(message)
                    .font(.system(size: 12, weight: .medium))
                    .foregroundColor(palette.secondaryText)
                    .lineLimit(2)
                    .minimumScaleFactor(0.82)
            }
            Spacer(minLength: 0)
        }
        .padding(14)
        .background(
            RoundedRectangle(cornerRadius: 8)
                .fill(palette.card)
        )
        .overlay(
            RoundedRectangle(cornerRadius: 8)
                .stroke(palette.border, lineWidth: 1)
        )
    }
    private var title: String {
        switch state {
        case .idle:
            return "Подключение"
        case .checking:
            return "Проверяем сайт"
        case .success:
            return "Связь есть"
        case .failure:
            return "Нужна проверка"
        }
    }
    private var message: String {
        switch state {
        case .idle:
            return "Можно сохранить настройки или проверить API."
        case .checking:
            return "Запрашиваем текущий статус фермы."
        case .success(let message), .failure(let message):
            return message
        }
    }
    private var iconName: String {
        switch state {
        case .idle:
            return "wifi"
        case .checking:
            return "arrow.triangle.2.circlepath"
        case .success:
            return "checkmark.circle.fill"
        case .failure:
            return "exclamationmark.triangle.fill"
        }
    }
    private var iconColor: Color {
        switch state {
        case .idle, .checking:
            return palette.info
        case .success:
            return palette.accent
        case .failure:
            return palette.warning
        }
    }
}