import SwiftUI
struct ThemePreviewView: View {
    let theme: FitoDomikTheme
    @Environment(\.colorScheme) private var colorScheme
    private var palette: FitoDomikThemePalette {
        theme.palette(for: colorScheme)
    }
    var body: some View {
        VStack(alignment: .leading, spacing: 9) {
            HStack {
                Label("ФитоДомик", systemImage: "leaf.fill")
                    .font(.system(size: 13, weight: .bold))
                    .foregroundColor(palette.primaryText)
                Spacer()
                Label("Хорошо", systemImage: "wifi")
                    .font(.system(size: 10, weight: .bold))
                    .foregroundColor(palette.accent)
                    .padding(.horizontal, 7)
                    .padding(.vertical, 4)
                    .background(
                        RoundedRectangle(cornerRadius: 8)
                            .fill(palette.accent.opacity(0.13))
                    )
            }
            HStack(spacing: 8) {
                previewSensor(title: "Темп.", value: "24,6", unit: "°C", icon: "thermometer")
                previewSensor(title: "Почва", value: "48", unit: "%", icon: "drop.fill")
                previewSensor(title: "CO2", value: "540", unit: "ppm", icon: "wind")
            }
        }
        .padding(12)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(
            LinearGradient(
                colors: [palette.backgroundTop, palette.backgroundBottom],
                startPoint: .topLeading,
                endPoint: .bottomTrailing
            )
        )
        .clipShape(RoundedRectangle(cornerRadius: 8))
        .overlay(
            RoundedRectangle(cornerRadius: 8)
                .stroke(palette.border, lineWidth: 1)
        )
    }
    private func previewSensor(title: String, value: String, unit: String, icon: String) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Image(systemName: icon)
                .font(.system(size: 12, weight: .bold))
                .foregroundColor(palette.accent)
            Text(title)
                .font(.system(size: 9, weight: .semibold))
                .foregroundColor(palette.secondaryText)
                .lineLimit(1)
            HStack(alignment: .firstTextBaseline, spacing: 2) {
                Text(value)
                    .font(.system(size: 15, weight: .bold))
                    .foregroundColor(palette.primaryText)
                    .lineLimit(1)
                Text(unit)
                    .font(.system(size: 8, weight: .bold))
                    .foregroundColor(palette.secondaryText)
                    .lineLimit(1)
            }
        }
        .padding(8)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(
            RoundedRectangle(cornerRadius: 8)
                .fill(palette.card)
        )
    }
}