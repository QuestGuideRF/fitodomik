from __future__ import annotations
import importlib.util
import re
import shutil
import subprocess
MAX_VERSION_OUTPUT_LENGTH = 4096
SEMANTIC_VERSION_PATTERN = re.compile(
    r"(?<!\d)(\d{1,18})\.(\d{1,18})\.(\d{1,18})(?!\d)"
)
REQUIRED_MODULES = {
    "serial": "pyserial",
    "PIL": "Pillow",
    "matplotlib": "matplotlib",
    "numpy": "numpy",
    "requests": "requests",
    "cv2": "opencv-python",
    "qrcode": "qrcode",
}
REQUIRED_TOOLS = {
    "arduino-cli": {
        "label": "arduino-cli>=1.4.1",
        "min_version": (1, 4, 1),
    },
}
def check_python_dependencies() -> dict[str, bool]:
    return {
        package: importlib.util.find_spec(module_name) is not None
        for module_name, package in REQUIRED_MODULES.items()
    }
def check_external_tools() -> dict[str, bool]:
    result: dict[str, bool] = {}
    for executable, spec in REQUIRED_TOOLS.items():
        label = str(spec["label"])
        path = shutil.which(executable)
        if not path:
            result[label] = False
            continue
        version = _tool_version(executable)
        min_version = tuple(spec["min_version"])
        result[label] = version is not None and version >= min_version
    return result
def check_dependencies() -> dict[str, bool]:
    result = check_python_dependencies()
    result.update(check_external_tools())
    return result
def missing_dependencies() -> list[str]:
    result = check_dependencies()
    return [package for package, available in result.items() if not available]
def _tool_version(executable: str) -> tuple[int, ...] | None:
    try:
        completed = subprocess.run(
            [executable, "version"],
            capture_output=True,
            text=True,
            timeout=5,
            check=False,
        )
    except Exception:
        return None
    text = f"{completed.stdout}\n{completed.stderr}"[:MAX_VERSION_OUTPUT_LENGTH]
    return _first_semantic_version(text)
def _first_semantic_version(text: str) -> tuple[int, int, int] | None:
    match = SEMANTIC_VERSION_PATTERN.search(text[:MAX_VERSION_OUTPUT_LENGTH])
    if match is None:
        return None
    major, minor, patch = match.groups()
    return int(major), int(minor), int(patch)
