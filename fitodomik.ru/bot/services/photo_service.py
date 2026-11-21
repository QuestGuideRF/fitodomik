from __future__ import annotations
import re
from pathlib import Path, PurePath
from aiogram.types import FSInputFile
from typing import Any
from urllib.parse import ParseResult, parse_qs, quote, urlparse
from bot.config import Settings
from bot.repositories.photos import PhotosRepository
PHOTO_NAME_RE = re.compile(r"^[a-z0-9._-]+\.(?:jpe?g|png|gif|webp)$", re.IGNORECASE)
PHOTO_ROUTE_RE = re.compile(
    r"^farm_photos/([a-z0-9._-]+\.(?:jpe?g|png|gif|webp))$", re.IGNORECASE
)
def _route_filename(parsed: ParseResult) -> str:
    try:
        query = parse_qs(parsed.query, strict_parsing=True)
    except ValueError:
        return ""
    files = query.get("file", [])
    if set(query) != {"file"} or len(files) != 1:
        return ""
    match = PHOTO_ROUTE_RE.fullmatch(files[0])
    return match.group(1) if match else ""
def _photo_filename(parsed: ParseResult, trusted_host: str) -> str:
    if parsed.scheme or parsed.netloc:
        if parsed.scheme != "https" or parsed.netloc.lower() != trusted_host:
            return ""
    if parsed.path == "/security/image.php":
        return _route_filename(parsed)
    if parsed.query or parsed.fragment:
        return ""
    return PurePath(parsed.path.replace("\\", "/")).name
class PhotoService:
    def __init__(self, settings: Settings, photos: PhotosRepository) -> None:
        self.settings = settings
        self.photos = photos
    def trusted_url(self, value: Any) -> str:
        raw = str(value or "").strip()
        if not raw or "\x00" in raw:
            return ""
        parsed = urlparse(raw)
        trusted_host = urlparse(self.settings.site_url).netloc.lower()
        filename = _photo_filename(parsed, trusted_host)
        if not PHOTO_NAME_RE.fullmatch(filename):
            return ""
        return (
            self.settings.site_url
            + "/security/image.php?file=farm_photos/"
            + quote(filename, safe="")
        )
    def local_photo(self, value: Any) -> FSInputFile | None:
        raw = str(value or "").strip()
        filename = PurePath(raw.replace("\\", "/")).name
        if not PHOTO_NAME_RE.fullmatch(filename) or filename != raw:
            return None
        base = (Path(self.settings.project_dir) / "uploads" / "farm_photos").resolve()
        candidate = base / filename
        if candidate.is_symlink() or not candidate.is_file() or candidate.resolve().parent != base:
            return None
        return FSInputFile(candidate)
    def command_result_url(self, command: str, owned_record: dict[str, Any]) -> str:
        field = "photo_analysis" if command == "analyze_plant" else "photo"
        return self.trusted_url(owned_record.get(field))
    def command_result_photo(self, command: str, owned_record: dict[str, Any]) -> FSInputFile | None:
        field = "photo_analysis" if command == "analyze_plant" else "photo"
        return self.local_photo(owned_record.get(field))
    async def latest(self, user_id: int, device_id: str) -> dict[str, Any] | None:
        return await self.photos.latest(user_id, device_id)
    async def latest_analysis(
        self, user_id: int, device_id: str
    ) -> dict[str, Any] | None:
        return await self.photos.latest_analysis(user_id, device_id)
