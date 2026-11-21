import SwiftUI
struct MediumWidgetView: View {
    let status: FitoDomikStatus
    let notice: String?
    let palette: FitoDomikThemePalette
    private let columns = Array(repeating: GridItem(.flexible(), spacing: 7), count: 4)
    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            FitoDomikHeaderView(status: status, palette: palette)
            LazyVGrid(columns: columns, spacing: 7) {
                ForEach(Array(status.sensorItems(includeUnavailable: false).prefix(4))) { item in
                    SensorCardView(item: item, palette: palette, compact: true)
                }
            }
            HStack(alignment: .center) {
                WidgetDeviceRow(items: status.deviceItems(), palette: palette, compact: true)
                Spacer(minLength: 0)
            }
            if let notice {
                StatusNoticeView(message: notice, palette: palette)
            }
        }
        .padding(12)
    }
}