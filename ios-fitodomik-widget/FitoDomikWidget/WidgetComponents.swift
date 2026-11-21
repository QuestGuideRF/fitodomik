import SwiftUI
struct FitoDomikWidgetBackground: View {
    let theme: FitoDomikTheme
    let palette: FitoDomikThemePalette
    var body: some View {
        LinearGradient(
            colors: [palette.backgroundTop, palette.backgroundBottom],
            startPoint: .topLeading,
            endPoint: .bottomTrailing
        )
        .overlay(alignment: .topTrailing) {
            Image(systemName: theme == .dark ? "house.fill" : "leaf.fill")
                .font(.system(size: 86, weight: .semibold))
                .foregroundColor(palette.accent.opacity(palette.isDark ? 0.08 : 0.13))
                .rotationEffect(.degrees(-10))
                .offset(x: 18, y: -22)
        }
    }
}
struct FitoDomikHeaderView: View {
    let status: FitoDomikStatus
    let palette: FitoDomikThemePalette
    var compact: Bool = false
    var body: some View {
        HStack(spacing: compact ? 6 : 8) {
            ZStack {
                RoundedRectangle(cornerRadius: 8)
                    .fill(palette.accent.opacity(palette.isDark ? 0.22 : 0.15))
                Image(systemName: "leaf.fill")
                    .font(.system(size: compact ? 12 : 15, weight: .bold))
                    .foregroundColor(palette.accent)
            }
            .frame(width: compact ? 24 : 30, height: compact ? 24 : 30)
            VStack(alignment: .leading, spacing: 1) {
                Text(compact ? "ФитоДомик" : status.title)
                    .font(.system(size: compact ? 12 : 14, weight: .bold))
                    .foregroundColor(palette.primaryText)
                    .lineLimit(1)
                    .minimumScaleFactor(0.75)
                Text("обновлено \(status.lastUpdateText)")
                    .font(.system(size: compact ? 8 : 10, weight: .medium))
                    .foregroundColor(palette.secondaryText)
                    .lineLimit(1)
                    .minimumScaleFactor(0.7)
            }
            Spacer(minLength: 2)
            DeviceStatusChip(
                title: status.overallStatusText,
                systemImage: status.isOnline ? "wifi" : "wifi.slash",
                quality: status.overallQuality,
                palette: palette,
                compact: compact
            )
        }
    }
}
struct SensorCardView: View {
    let item: SensorDisplayItem
    let palette: FitoDomikThemePalette
    var compact: Bool = false
    var body: some View {
        VStack(alignment: .leading, spacing: compact ? 3 : 5) {
            HStack(spacing: 4) {
                Image(systemName: item.systemImage)
                    .font(.system(size: compact ? 10 : 12, weight: .semibold))
                    .foregroundColor(item.quality.color(in: palette))
                    .frame(width: compact ? 12 : 14)
                Text(item.title)
                    .font(.system(size: compact ? 8 : 10, weight: .semibold))
                    .foregroundColor(palette.secondaryText)
                    .lineLimit(1)
                    .minimumScaleFactor(0.75)
            }
            HStack(alignment: .firstTextBaseline, spacing: 2) {
                Text(item.value)
                    .font(.system(size: compact ? 15 : 18, weight: .bold))
                    .foregroundColor(palette.primaryText)
                    .lineLimit(1)
                    .minimumScaleFactor(0.55)
                if !item.unit.isEmpty {
                    Text(item.unit)
                        .font(.system(size: compact ? 8 : 10, weight: .semibold))
                        .foregroundColor(palette.secondaryText)
                        .lineLimit(1)
                }
            }
            if let caption = item.caption, !compact {
                Text(caption)
                    .font(.system(size: 8, weight: .medium))
                    .foregroundColor(palette.mutedText)
                    .lineLimit(1)
                    .minimumScaleFactor(0.7)
            }
        }
        .padding(compact ? 7 : 9)
        .frame(maxWidth: .infinity, minHeight: compact ? 52 : 64, alignment: .leading)
        .background(
            RoundedRectangle(cornerRadius: 8)
                .fill(palette.card)
        )
        .overlay(
            RoundedRectangle(cornerRadius: 8)
                .stroke(item.quality.color(in: palette).opacity(0.22), lineWidth: 1)
        )
    }
}
struct DeviceStatusChip: View {
    let title: String
    let systemImage: String
    let quality: SensorQuality
    let palette: FitoDomikThemePalette
    var compact: Bool = false
    var body: some View {
        HStack(spacing: compact ? 3 : 5) {
            Image(systemName: systemImage)
                .font(.system(size: compact ? 8 : 10, weight: .bold))
            Text(title)
                .font(.system(size: compact ? 8 : 10, weight: .bold))
                .lineLimit(1)
                .minimumScaleFactor(0.7)
        }
        .foregroundColor(quality.color(in: palette))
        .padding(.horizontal, compact ? 6 : 8)
        .padding(.vertical, compact ? 4 : 5)
        .background(
            RoundedRectangle(cornerRadius: 8)
                .fill(quality.color(in: palette).opacity(palette.isDark ? 0.18 : 0.13))
        )
    }
}
struct PlantStatusView: View {
    let status: FitoDomikStatus
    let palette: FitoDomikThemePalette
    var compact: Bool = false
    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: status.overallQuality == .normal ? "checkmark.circle.fill" : "exclamationmark.triangle.fill")
                .font(.system(size: compact ? 14 : 18, weight: .bold))
                .foregroundColor(status.overallQuality.color(in: palette))
            VStack(alignment: .leading, spacing: 2) {
                Text("Растение")
                    .font(.system(size: compact ? 9 : 11, weight: .semibold))
                    .foregroundColor(palette.secondaryText)
                    .lineLimit(1)
                Text(status.plantStatusText)
                    .font(.system(size: compact ? 10 : 12, weight: .bold))
                    .foregroundColor(palette.primaryText)
                    .lineLimit(compact ? 1 : 2)
                    .minimumScaleFactor(0.75)
            }
            Spacer(minLength: 0)
        }
        .padding(compact ? 8 : 10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(
            RoundedRectangle(cornerRadius: 8)
                .fill(palette.card)
        )
        .overlay(
            RoundedRectangle(cornerRadius: 8)
                .stroke(palette.border, lineWidth: 1)
        )
    }
}
struct LoadingWidgetView: View {
    let palette: FitoDomikThemePalette
    var body: some View {
        VStack(spacing: 10) {
            Image(systemName: "leaf.circle.fill")
                .font(.system(size: 34, weight: .semibold))
                .foregroundColor(palette.accent)
            VStack(spacing: 3) {
                Text("ФитоДомик")
                    .font(.system(size: 16, weight: .bold))
                    .foregroundColor(palette.primaryText)
                Text("Обновляем данные")
                    .font(.system(size: 11, weight: .medium))
                    .foregroundColor(palette.secondaryText)
            }
        }
        .padding(14)
    }
}
struct PlaceholderWidgetView: View {
    let title: String
    let message: String
    let palette: FitoDomikThemePalette
    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 8) {
                Image(systemName: "house.fill")
                    .font(.system(size: 17, weight: .bold))
                    .foregroundColor(palette.accent)
                Text("ФитоДомик")
                    .font(.system(size: 15, weight: .bold))
                    .foregroundColor(palette.primaryText)
                Spacer()
            }
            Spacer(minLength: 0)
            VStack(alignment: .leading, spacing: 4) {
                Text(title)
                    .font(.system(size: 14, weight: .bold))
                    .foregroundColor(palette.primaryText)
                Text(message)
                    .font(.system(size: 11, weight: .medium))
                    .foregroundColor(palette.secondaryText)
                    .lineLimit(3)
                    .minimumScaleFactor(0.8)
            }
            Spacer(minLength: 0)
        }
        .padding(14)
    }
}
struct ErrorWidgetView: View {
    let title: String
    let message: String
    let systemImage: String
    let palette: FitoDomikThemePalette
    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 8) {
                Image(systemName: systemImage)
                    .font(.system(size: 18, weight: .bold))
                    .foregroundColor(palette.warning)
                Text("ФитоДомик")
                    .font(.system(size: 15, weight: .bold))
                    .foregroundColor(palette.primaryText)
                Spacer()
            }
            Spacer(minLength: 0)
            VStack(alignment: .leading, spacing: 4) {
                Text(title)
                    .font(.system(size: 14, weight: .bold))
                    .foregroundColor(palette.primaryText)
                    .lineLimit(1)
                    .minimumScaleFactor(0.75)
                Text(message)
                    .font(.system(size: 11, weight: .medium))
                    .foregroundColor(palette.secondaryText)
                    .lineLimit(3)
                    .minimumScaleFactor(0.75)
            }
            Spacer(minLength: 0)
        }
        .padding(14)
    }
}
struct OfflineWidgetView: View {
    let status: FitoDomikStatus
    let message: String
    let palette: FitoDomikThemePalette
    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            FitoDomikHeaderView(status: status, palette: palette, compact: true)
            HStack(spacing: 8) {
                Image(systemName: "wifi.slash")
                    .font(.system(size: 16, weight: .bold))
                    .foregroundColor(palette.danger)
                VStack(alignment: .leading, spacing: 2) {
                    Text("Устройство offline")
                        .font(.system(size: 13, weight: .bold))
                        .foregroundColor(palette.primaryText)
                    Text(message)
                        .font(.system(size: 10, weight: .medium))
                        .foregroundColor(palette.secondaryText)
                        .lineLimit(2)
                }
                Spacer(minLength: 0)
            }
            .padding(9)
            .background(
                RoundedRectangle(cornerRadius: 8)
                    .fill(palette.card)
            )
            HStack(spacing: 7) {
                ForEach(status.smallSensorItems()) { item in
                    SensorCardView(item: item, palette: palette, compact: true)
                }
            }
        }
        .padding(12)
    }
}
struct StatusNoticeView: View {
    let message: String
    let palette: FitoDomikThemePalette
    var body: some View {
        HStack(spacing: 5) {
            Image(systemName: "clock.arrow.circlepath")
                .font(.system(size: 9, weight: .bold))
            Text(message)
                .font(.system(size: 9, weight: .semibold))
                .lineLimit(1)
                .minimumScaleFactor(0.7)
            Spacer(minLength: 0)
        }
        .foregroundColor(palette.secondaryText)
        .padding(.horizontal, 8)
        .padding(.vertical, 5)
        .background(
            RoundedRectangle(cornerRadius: 8)
                .fill(palette.elevatedCard)
        )
    }
}
struct WidgetDeviceRow: View {
    let items: [DeviceStatusItem]
    let palette: FitoDomikThemePalette
    var compact: Bool = false
    var body: some View {
        HStack(spacing: compact ? 5 : 7) {
            if items.isEmpty {
                DeviceStatusChip(
                    title: "Реле недоступны",
                    systemImage: "switch.2",
                    quality: .unavailable,
                    palette: palette,
                    compact: compact
                )
            } else {
                ForEach(items.prefix(compact ? 3 : 4)) { item in
                    DeviceStatusChip(
                        title: item.value,
                        systemImage: item.systemImage,
                        quality: item.isActive == true ? .normal : .info,
                        palette: palette,
                        compact: compact
                    )
                }
            }
        }
    }
}
struct PlantPhotoView: View {
    let photoUrl: String?
    let palette: FitoDomikThemePalette
    var body: some View {
        Group {
            if let photoUrl, let url = URL(string: photoUrl) {
                if #available(iOSApplicationExtension 15.0, *) {
                    AsyncImage(url: url) { phase in
                        switch phase {
                        case .success(let image):
                            image
                                .resizable()
                                .scaledToFill()
                        case .failure:
                            photoPlaceholder(title: "Фото недоступно")
                        case .empty:
                            photoPlaceholder(title: "Фото растения")
                        @unknown default:
                            photoPlaceholder(title: "Фото растения")
                        }
                    }
                } else {
                    photoPlaceholder(title: "Фото растения")
                }
            } else {
                photoPlaceholder(title: "Фото растения")
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(
            RoundedRectangle(cornerRadius: 8)
                .fill(palette.card)
        )
        .clipShape(RoundedRectangle(cornerRadius: 8))
        .overlay(
            RoundedRectangle(cornerRadius: 8)
                .stroke(palette.border, lineWidth: 1)
        )
    }
    private func photoPlaceholder(title: String) -> some View {
        VStack(spacing: 5) {
            Image(systemName: "camera.fill")
                .font(.system(size: 18, weight: .bold))
                .foregroundColor(palette.accent)
            Text(title)
                .font(.system(size: 10, weight: .semibold))
                .foregroundColor(palette.secondaryText)
                .lineLimit(1)
                .minimumScaleFactor(0.8)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}