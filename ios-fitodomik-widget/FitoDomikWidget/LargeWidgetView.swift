import SwiftUI
struct LargeWidgetView: View {
    let status: FitoDomikStatus
    let notice: String?
    let palette: FitoDomikThemePalette
    private let columns = Array(repeating: GridItem(.flexible(), spacing: 8), count: 2)
    var body: some View {
        VStack(alignment: .leading, spacing: 9) {
            FitoDomikHeaderView(status: status, palette: palette)
            LazyVGrid(columns: columns, spacing: 8) {
                ForEach(Array(status.sensorItems(includeUnavailable: false).prefix(6))) { item in
                    SensorCardView(item: item, palette: palette)
                }
            }
            VStack(alignment: .leading, spacing: 6) {
                Text("Устройства")
                    .font(.system(size: 11, weight: .bold))
                    .foregroundColor(palette.secondaryText)
                WidgetDeviceRow(items: status.deviceItems(), palette: palette)
            }
            HStack(spacing: 8) {
                PlantStatusView(status: status, palette: palette)
                PlantPhotoView(photoUrl: status.photoUrl, palette: palette)
                    .frame(width: 112, height: 64)
            }
            if let notice {
                StatusNoticeView(message: notice, palette: palette)
            }
        }
        .padding(13)
    }
}