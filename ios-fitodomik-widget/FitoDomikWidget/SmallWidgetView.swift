import SwiftUI
struct SmallWidgetView: View {
    let status: FitoDomikStatus
    let notice: String?
    let palette: FitoDomikThemePalette
    var body: some View {
        VStack(alignment: .leading, spacing: 7) {
            FitoDomikHeaderView(status: status, palette: palette, compact: true)
            HStack(alignment: .center, spacing: 7) {
                Image(systemName: status.overallQuality == .normal ? "leaf.circle.fill" : "exclamationmark.triangle.fill")
                    .font(.system(size: 22, weight: .bold))
                    .foregroundColor(status.overallQuality.color(in: palette))
                VStack(alignment: .leading, spacing: 1) {
                    Text(status.overallStatusText)
                        .font(.system(size: 15, weight: .bold))
                        .foregroundColor(palette.primaryText)
                        .lineLimit(1)
                    Text(status.isOnline ? "мини-ферма online" : "нет связи")
                        .font(.system(size: 9, weight: .medium))
                        .foregroundColor(palette.secondaryText)
                        .lineLimit(1)
                }
                Spacer(minLength: 0)
            }
            HStack(spacing: 7) {
                ForEach(status.smallSensorItems()) { item in
                    SensorCardView(item: item, palette: palette, compact: true)
                }
            }
            if let notice {
                StatusNoticeView(message: notice, palette: palette)
            }
        }
        .padding(12)
    }
}