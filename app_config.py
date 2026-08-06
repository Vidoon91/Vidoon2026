import json
import os
import sys


if getattr(sys, "frozen", False):
    BASE_DIR = os.path.dirname(sys.executable)
else:
    BASE_DIR = os.path.abspath(os.path.dirname(__file__))

APP_SETTINGS_FILE = os.path.join(BASE_DIR, "app_settings.json")
VERSION_FILE = os.path.join(BASE_DIR, "version.json")


def _load_app_version():
    try:
        with open(VERSION_FILE, "r", encoding="utf-8-sig") as file:
            version_data = json.load(file)
        version = str(version_data.get("version", "")).strip()
        if version:
            return version
    except (OSError, ValueError, TypeError):
        pass
    return "0.0.0"


APP_VERSION = _load_app_version()

DEFAULT_APP_SETTINGS = {
    "client": {
        "api_url": "https://license.muyanshidai.com/api.php",
        "app_name": "Vidoon",
        "app_tag": "Vidoon@2026",
        "app_display_name": "Vidoon 视频素材管理工具 2026",
        "version": APP_VERSION,
        "release_date": "2026-04-11",
        "support_email": "842635534@qq.com",
        "author_name": "马踏飞燕",
        "copyright_text": "© 2026 马踏飞燕 版权所有",
        "storage_dir_name": "Vidoon",
        "window_title": "Vidoon 视频素材工具",
        "video_window_title": "Vidoon 视频素材工具",
        "website": {
            "home": "https://www.muyanshidai.com",
            "disclaimer": "https://www.muyanshidai.com/disclaimer",
            "privacy": "https://www.muyanshidai.com/privacy",
            "terms": "https://www.muyanshidai.com/terms",
        },
        "update": {
            "enabled": True,
            "check_on_start": True,
            "version_url": "https://license.muyanshidai.com/version.php",
            "download_page_url": "https://license.muyanshidai.com/index.php",
        },
        "defaults": {
            "retry_count": 3,
            "max_threads": 3,
            "deno_timeout": 12,
        },
    }
}

_APP_SETTINGS_CACHE = None


def _deep_merge(base, override):
    result = dict(base)
    for key, value in override.items():
        if isinstance(value, dict) and isinstance(result.get(key), dict):
            result[key] = _deep_merge(result[key], value)
        else:
            result[key] = value
    return result


def load_app_settings(force_reload=False):
    global _APP_SETTINGS_CACHE
    if _APP_SETTINGS_CACHE is not None and not force_reload:
        return _APP_SETTINGS_CACHE

    settings = DEFAULT_APP_SETTINGS
    try:
        if os.path.exists(APP_SETTINGS_FILE):
            with open(APP_SETTINGS_FILE, "r", encoding="utf-8") as file:
                user_settings = json.load(file)
                if isinstance(user_settings, dict):
                    settings = _deep_merge(DEFAULT_APP_SETTINGS, user_settings)
    except Exception:
        settings = DEFAULT_APP_SETTINGS

    _APP_SETTINGS_CACHE = settings
    return settings


def get_app_value(path, default=None):
    settings = load_app_settings()
    current = settings
    for part in path.split("."):
        if not isinstance(current, dict) or part not in current:
            return default
        current = current[part]
    return current


def get_api_url():
    return get_app_value("client.api_url", DEFAULT_APP_SETTINGS["client"]["api_url"])


def get_app_version():
    return APP_VERSION
