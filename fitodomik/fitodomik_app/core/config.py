from __future__ import annotations
import os
DEFAULT_APP_URL = "https://fitodomik.ru"
DEFAULT_PUBLIC_SITE_URL = "https://fitodomik.ru"
def _normalized_url(value: str, default: str) -> str:
    return (value.strip() or default).rstrip("/")
APP_URL = _normalized_url(os.environ.get("FITODOMIK_APP_URL", ""), DEFAULT_APP_URL)
API_BASE_URL = f"{APP_URL}/api"
PUBLIC_SITE_URL = _normalized_url(
    os.environ.get("FITODOMIK_PUBLIC_SITE_URL", ""),
    DEFAULT_PUBLIC_SITE_URL,
)
UPDATE_METADATA_URL = f"{PUBLIC_SITE_URL}/downloads/latest.json"
