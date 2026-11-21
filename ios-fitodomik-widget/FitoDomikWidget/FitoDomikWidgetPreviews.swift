import SwiftUI
import WidgetKit
struct FitoDomikWidgetPreviews: PreviewProvider {
    static var previews: some View {
        Group {
            FitoDomikWidgetEntryView(entry: .preview(theme: .light))
                .previewContext(WidgetPreviewContext(family: .systemSmall))
                .previewDisplayName("Small light")
            FitoDomikWidgetEntryView(entry: .preview(theme: .dark))
                .previewContext(WidgetPreviewContext(family: .systemSmall))
                .environment(\.colorScheme, .dark)
                .previewDisplayName("Small dark")
            FitoDomikWidgetEntryView(entry: .preview(theme: .green))
                .previewContext(WidgetPreviewContext(family: .systemMedium))
                .previewDisplayName("Medium green")
            FitoDomikWidgetEntryView(
                entry: .preview(
                    state: .offline(message: "Нет связи. Показан кэш"),
                    status: .offlineMock,
                    theme: .dark
                )
            )
            .previewContext(WidgetPreviewContext(family: .systemMedium))
            .environment(\.colorScheme, .dark)
            .previewDisplayName("Offline")
            FitoDomikWidgetEntryView(
                entry: .preview(
                    state: .apiError(message: "Сайт временно недоступен"),
                    status: nil,
                    theme: .green
                )
            )
            .previewContext(WidgetPreviewContext(family: .systemSmall))
            .previewDisplayName("Error")
            FitoDomikWidgetEntryView(
                entry: .preview(
                    state: .noSettings,
                    status: nil,
                    theme: .light
                )
            )
            .previewContext(WidgetPreviewContext(family: .systemSmall))
            .previewDisplayName("No settings")
        }
    }
}