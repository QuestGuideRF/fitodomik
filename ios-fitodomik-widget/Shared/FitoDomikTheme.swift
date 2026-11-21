import SwiftUI
enum FitoDomikTheme: String, CaseIterable, Codable, Identifiable {
    case system
    case light
    case dark
    case green
    var id: String { rawValue }
    var displayName: String {
        switch self {
        case .system: return "Авто"
        case .light: return "Светлая"
        case .dark: return "Темная"
        case .green: return "Зеленая"
        }
    }
    var systemImage: String {
        switch self {
        case .system: return "circle.lefthalf.filled"
        case .light: return "sun.max.fill"
        case .dark: return "moon.fill"
        case .green: return "leaf.fill"
        }
    }
    func palette(for colorScheme: ColorScheme) -> FitoDomikThemePalette {
        switch self {
        case .system:
            return colorScheme == .dark ? .dark : .light
        case .light:
            return .light
        case .dark:
            return .dark
        case .green:
            return .green
        }
    }
}
struct FitoDomikThemePalette {
    let backgroundTop: Color
    let backgroundBottom: Color
    let card: Color
    let elevatedCard: Color
    let primaryText: Color
    let secondaryText: Color
    let mutedText: Color
    let border: Color
    let accent: Color
    let accentSoft: Color
    let warning: Color
    let danger: Color
    let info: Color
    let isDark: Bool
    static let light = FitoDomikThemePalette(
        backgroundTop: Color(hex: "#F8FBF7"),
        backgroundBottom: Color(hex: "#F6FAF4"),
        card: .white,
        elevatedCard: Color(hex: "#EEF7EF"),
        primaryText: Color(hex: "#1D1D1F"),
        secondaryText: Color(hex: "#6B7280"),
        mutedText: Color(hex: "#8A8F98"),
        border: Color(hex: "#DCE8DD"),
        accent: Color(hex: "#2E7D32"),
        accentSoft: Color(hex: "#6FCF97"),
        warning: Color(hex: "#F59E0B"),
        danger: Color(hex: "#EF4444"),
        info: Color(hex: "#3B82F6"),
        isDark: false
    )
    static let dark = FitoDomikThemePalette(
        backgroundTop: Color(hex: "#141414"),
        backgroundBottom: Color(hex: "#1A1B1A"),
        card: Color(hex: "#1E1E1E"),
        elevatedCard: Color(hex: "#252526"),
        primaryText: .white,
        secondaryText: Color(hex: "#BDBDBD"),
        mutedText: Color(hex: "#8E8E93"),
        border: Color(hex: "#333333"),
        accent: Color(hex: "#6FCF97"),
        accentSoft: Color(hex: "#2E7D32"),
        warning: Color(hex: "#FBBF24"),
        danger: Color(hex: "#F87171"),
        info: Color(hex: "#60A5FA"),
        isDark: true
    )
    static let green = FitoDomikThemePalette(
        backgroundTop: Color(hex: "#E8F7E9"),
        backgroundBottom: Color(hex: "#BCE9C5"),
        card: Color.white.opacity(0.86),
        elevatedCard: Color.white.opacity(0.72),
        primaryText: Color(hex: "#0F2F1D"),
        secondaryText: Color(hex: "#315C42"),
        mutedText: Color(hex: "#587A63"),
        border: Color.white.opacity(0.55),
        accent: Color(hex: "#2E7D32"),
        accentSoft: Color(hex: "#6FCF97"),
        warning: Color(hex: "#B7791F"),
        danger: Color(hex: "#C2410C"),
        info: Color(hex: "#2563EB"),
        isDark: false
    )
}
extension Color {
    init(hex: String, opacity: Double = 1) {
        let cleaned = hex.trimmingCharacters(in: CharacterSet.alphanumerics.inverted)
        var int: UInt64 = 0
        Scanner(string: cleaned).scanHexInt64(&int)
        let red: UInt64
        let green: UInt64
        let blue: UInt64
        switch cleaned.count {
        case 3:
            red = (int >> 8) * 17
            green = ((int >> 4) & 0xF) * 17
            blue = (int & 0xF) * 17
        default:
            red = int >> 16
            green = (int >> 8) & 0xFF
            blue = int & 0xFF
        }
        self.init(
            .sRGB,
            red: Double(red) / 255,
            green: Double(green) / 255,
            blue: Double(blue) / 255,
            opacity: opacity
        )
    }
}
extension SensorQuality {
    func color(in palette: FitoDomikThemePalette) -> Color {
        switch self {
        case .normal:
            return palette.accent
        case .warning:
            return palette.warning
        case .critical:
            return palette.danger
        case .unavailable:
            return palette.mutedText
        case .info:
            return palette.info
        }
    }
}