import Foundation
enum DateFormatters {
    private static let ruLocale = Locale(identifier: "ru_RU")
    static let widgetTimeFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.locale = ruLocale
        formatter.dateFormat = "HH:mm"
        return formatter
    }()
    static let dayTimeFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.locale = ruLocale
        formatter.dateFormat = "dd.MM HH:mm"
        return formatter
    }()
    private static let serverDateFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        return formatter
    }()
    private static let serverDateWithTimezoneFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.dateFormat = "yyyy-MM-dd'T'HH:mm:ssZ"
        return formatter
    }()
    static func parseServerDate(_ value: String?) -> Date? {
        guard let value = value?.trimmingCharacters(in: .whitespacesAndNewlines), !value.isEmpty else {
            return nil
        }
        if let date = ISO8601DateFormatter().date(from: value) {
            return date
        }
        if let date = serverDateWithTimezoneFormatter.date(from: value) {
            return date
        }
        return serverDateFormatter.date(from: value)
    }
    static func parseTimestamp(_ timestamp: Double?) -> Date? {
        guard let timestamp else { return nil }
        let seconds = timestamp > 10_000_000_000 ? timestamp / 1000 : timestamp
        return Date(timeIntervalSince1970: seconds)
    }
    static func widgetTimeString(from date: Date?) -> String {
        guard let date else { return "Нет времени" }
        return widgetTimeFormatter.string(from: date)
    }
    static func relativeWidgetString(from date: Date?, now: Date = Date()) -> String {
        guard let date else { return "Нет времени" }
        let seconds = max(0, Int(now.timeIntervalSince(date)))
        if seconds < 60 {
            return "только что"
        }
        let minutes = seconds / 60
        if minutes < 60 {
            return "\(minutes) мин назад"
        }
        let hours = minutes / 60
        if hours < 24 {
            return "\(hours) ч назад"
        }
        return dayTimeFormatter.string(from: date)
    }
    static func decimalString(_ value: Double, maximumFractionDigits: Int = 1) -> String {
        let formatter = NumberFormatter()
        formatter.locale = ruLocale
        formatter.minimumFractionDigits = 0
        formatter.maximumFractionDigits = maximumFractionDigits
        return formatter.string(from: NSNumber(value: value)) ?? String(format: "%.1f", value)
    }
}