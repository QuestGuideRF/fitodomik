import SwiftUI
struct FarmWidgetView: View {
    let entry: FitoDomikWidgetEntry
    var body: some View {
        FitoDomikWidgetEntryView(entry: entry)
    }
}