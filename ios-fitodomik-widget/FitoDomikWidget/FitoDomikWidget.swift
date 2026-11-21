import WidgetKit
import SwiftUI
@main
struct FitoDomikWidget: Widget {
    let kind: String = "FitoDomikWidget"
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: kind, provider: Provider()) { entry in
            FitoDomikWidgetEntryView(entry: entry)
        }
        .configurationDisplayName("ФитоДомик")
        .description("Показывает состояние умной мини-фермы, датчики и устройства.")
        .supportedFamilies([.systemSmall, .systemMedium, .systemLarge])
    }
}