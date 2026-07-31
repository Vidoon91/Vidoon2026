import os
import random
import re
from urllib.parse import parse_qs, urlencode, urlparse, urlunparse

from setting import DenoResolver
from core.download_types import DownloadRuntime, build_result
from core.download_utils import (
    DOWNLOAD_PROGRESS_TEMPLATE,
    build_output_file_print_template,
    build_output_id_print_template,
    build_selected_format_print_template,
    maybe_log_download_progress,
    parse_download_progress,
    run_logged_download_strategy,
)


# ============================================================
# 独立的浏览器 UA 检测（不依赖 youtube_download.py）
# ============================================================
def _version_key(version: str) -> tuple:
    return tuple(int(part) for part in version.split(".") if part.isdigit())


def _detect_chromium_version(exe_path: str) -> str:
    if not exe_path or not os.path.exists(exe_path):
        return ""
    app_dir = os.path.dirname(exe_path)
    try:
        versions = [
            name for name in os.listdir(app_dir)
            if re.match(r"^\d+\.\d+\.\d+\.\d+$", name)
        ]
    except Exception:
        versions = []
    if versions:
        return sorted(versions, key=_version_key, reverse=True)[0]
    return ""


def get_instagram_browser_user_agent() -> str:
    """从本机 Chrome/Edge 读取真实版本号，构造 UA；失败返回空字符串。"""
    if os.name != "nt":
        return ""

    candidates = [
        (
            "chrome",
            os.path.join(os.environ.get("PROGRAMFILES", ""), "Google", "Chrome", "Application", "chrome.exe"),
        ),
        (
            "chrome",
            os.path.join(os.environ.get("PROGRAMFILES(X86)", ""), "Google", "Chrome", "Application", "chrome.exe"),
        ),
        (
            "chrome",
            os.path.join(os.environ.get("LOCALAPPDATA", ""), "Google", "Chrome", "Application", "chrome.exe"),
        ),
        (
            "edge",
            os.path.join(os.environ.get("PROGRAMFILES(X86)", ""), "Microsoft", "Edge", "Application", "msedge.exe"),
        ),
        (
            "edge",
            os.path.join(os.environ.get("PROGRAMFILES", ""), "Microsoft", "Edge", "Application", "msedge.exe"),
        ),
    ]

    for browser_name, exe_path in candidates:
        version = _detect_chromium_version(exe_path)
        if not version:
            continue
        base = (
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
            f"(KHTML, like Gecko) Chrome/{version} Safari/537.36"
        )
        if browser_name == "edge":
            return f"{base} Edg/{version}"
        return base

    return ""


# ============================================================
# 内置 UA 池（兜底）
# ============================================================
INSTAGRAM_USER_AGENTS = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36",
    "Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Mobile/15E148 Safari/604.1",
]

INSTAGRAM_QUALITY_SELECTOR = (
    "bestvideo[height>=2160]+bestaudio/"
    "bestvideo[height>=1440]+bestaudio/"
    "bestvideo[height>=1080]+bestaudio/"
    "bestvideo[height>=720]+bestaudio/"
    "best"
)

INSTAGRAM_SORT_SELECTOR = "res,fps,br"


class InstagramDownloader:
    platform_name = "Instagram"

    def __init__(self, runtime: DownloadRuntime):
        self.runtime = runtime
        self.deno_resolver = None
        if runtime.enable_deno and runtime.deno_path and os.path.exists(runtime.deno_path):
            self.deno_resolver = DenoResolver(runtime.deno_path, 12)

    def normalize_url(self, url: str) -> dict:
        parsed = urlparse(url.strip())
        query = parse_qs(parsed.query)
        clean_query = {}

        if "id" in query:
            clean_query["id"] = query["id"][0]

        normalized = urlunparse((
            parsed.scheme or "https",
            parsed.netloc,
            parsed.path,
            "",
            urlencode(clean_query),
            parsed.fragment or "",
        ))
        modified = normalized != url.strip()
        reason = "removed tracking params" if modified else "no changes"
        return {
            "success": True,
            "original_url": url,
            "normalized_url": normalized,
            "platform": self.platform_name,
            "url_modified": modified,
            "modification_reason": reason,
            "is_shorts": any(marker in normalized for marker in ("/reel/", "/reels/")),
            "message": "Instagram URL normalized",
        }

    def classify_error(self, stderr_text: str) -> str:
        error_text = (stderr_text or "").lower()
        patterns = [
            (["http error 429", "too many requests", "rate limit", "429"], "NETWORK_RATE_LIMIT"),
            (["http error 416", "requested range not satisfiable"], "DOWNLOAD_RANGE_INVALID"),
            (["login required", "login to access", "sign in to view", "authentication required"], "AUTH_NEED_LOGIN"),
            (["cookies are no longer valid"], "AUTH_COOKIE_INVALID"),
            (["checkpoint_required", "challenge_required"], "AUTH_CHECKPOINT"),
            (["unable to extract", "failed to parse", "no video formats", "unsupported url"], "EXTRACTOR_FAILED"),
            ([
                "timed out",
                "timeout",
                "connection reset",
                "connection refused",
                "failed to establish a new connection",
                "network is unreachable",
                "tls connect error",
                "sslerror",
            ], "NETWORK_ERROR"),
        ]
        for keys, value in patterns:
            if any(key in error_text for key in keys):
                return value
        return "UNKNOWN"

    def should_use_cookie(self, error_source: str) -> bool:
        return error_source in {"AUTH_NEED_LOGIN", "AUTH_CHECKPOINT"}

    def should_use_deno(self, error_source: str) -> bool:
        return error_source == "EXTRACTOR_FAILED"

    def execute_download(self, url_info: dict, log_callback) -> dict:
        normalized_url = url_info["normalized_url"]
        log_callback("📋 平台: Instagram")

        result = self._download_platform_without_cookie(normalized_url, log_callback)
        if result["success"]:
            return result

        if result["error_source"] == "NETWORK_RATE_LIMIT":
            result["message"] = "Instagram 请求过于频繁，请稍后再试。"
            return result
        if result["error_source"] == "AUTH_COOKIE_INVALID":
            result["message"] = "这份 Instagram Cookie 已失效，请重新导出并导入。"
            return result
        if result["error_source"] == "AUTH_CHECKPOINT":
            result["message"] = "Instagram 账号触发安全检查，请在浏览器中完成验证后重新导出 Cookie。"
            return result
        if result["error_source"] == "NETWORK_ERROR":
            result["message"] = "网络连接异常，请检查网络或 VPN 后重试。"
            return result

        if self.should_use_cookie(result["error_source"]):
            if self._has_cookie_file():
                result = self._download_platform_with_cookie(normalized_url, log_callback)
                if result["success"]:
                    return result
                if result["error_source"] == "AUTH_COOKIE_INVALID":
                    result["message"] = "这份 Instagram Cookie 已失效，请重新导出并导入。"
                    return result
                if result["error_source"] == "NETWORK_RATE_LIMIT":
                    result["message"] = "Instagram 请求过于频繁，请稍后再试。"
                    return result
                if result["error_source"] == "NETWORK_ERROR":
                    result["message"] = "网络连接异常，请检查网络或 VPN 后重试。"
                    return result
            else:
                log_callback("⚠️ 当前错误需要 Instagram Cookie，但未找到 instagram_cookies.txt")

        if self.should_use_deno(result["error_source"]) and self.deno_resolver:
            return self._download_with_deno(normalized_url, log_callback, url_info)

        return result

    def _has_cookie_file(self) -> bool:
        return bool(self.runtime.instagram_cookie_file and os.path.exists(self.runtime.instagram_cookie_file))

    def _download_platform_without_cookie(self, url: str, log_callback) -> dict:
        log_callback("策略1：Instagram 无 Cookie 下载...")
        cmd = self._build_instagram_command(url, use_cookie=False)
        return self._run_strategy(cmd, url, log_callback, "PlatformNoCookie", cookie_used=False)

    def _download_platform_with_cookie(self, url: str, log_callback) -> dict:
        log_callback("策略2：Instagram Cookie 下载...")
        cmd = self._build_instagram_command(url, use_cookie=True)
        log_callback("🍪 使用Instagram Cookie文件")
        return self._run_strategy(cmd, url, log_callback, "PlatformCookie", cookie_used=True)

    def _download_with_deno(self, url: str, log_callback, url_info: dict) -> dict:
        log_callback("策略3：Deno 解析兜底...")
        resolved = self.deno_resolver.resolve_url(url, log_callback)
        if not resolved or not resolved.get("video_url"):
            return build_result(
                success=False,
                platform=self.platform_name,
                strategy_used="DenoFallback",
                error_source="EXTRACTOR_FAILED",
                message="Deno 解析失败",
                deno_used=True,
                normalized_url=url,
                url_modified=url_info.get("url_modified", False),
                modification_reason=url_info.get("modification_reason", ""),
            )

        resolved_url = resolved["video_url"]
        log_callback("Deno 解析成功，开始下载解析后的直链")
        cmd = self._build_instagram_command(resolved_url, use_cookie=self._has_cookie_file())
        return self._run_strategy(
            cmd,
            resolved_url,
            log_callback,
            "DenoFallback",
            cookie_used=self._has_cookie_file(),
            deno_used=True,
        )

    def _build_instagram_command(self, url: str, *, use_cookie: bool) -> list[str]:
        cmd = self._build_base_command()
        cmd.extend([
            "--merge-output-format", "mp4",
            "--add-header", "Referer:https://www.instagram.com/",
            "--sleep-requests", "3",
            "--min-sleep-interval", "2",
            "--max-sleep-interval", "10",
            "--postprocessor-args", "ffmpeg:-c:v copy -c:a aac -b:a 192k -movflags +faststart",
            "--concurrent-fragments", "1",
            "-S", INSTAGRAM_SORT_SELECTOR,
            "--print", build_selected_format_print_template(),
            "--print", build_output_file_print_template(),
            "--print", build_output_id_print_template(),
            "-f", INSTAGRAM_QUALITY_SELECTOR,
            "-o", os.path.join(self.runtime.save_path, "%(title)s-%(id)s.%(ext)s"),
        ])

        if use_cookie and self._has_cookie_file():
            cmd.extend(["--cookies", self.runtime.instagram_cookie_file])

        cmd.append(url)
        return cmd

    def _build_base_command(self) -> list[str]:
        cmd = [
            self.runtime.yt_dlp_path,
            "--no-playlist",
            "--ignore-errors",
            "--no-continue",
            "--force-overwrites",
            "--newline",
            "--progress",
            "--progress-template",
            DOWNLOAD_PROGRESS_TEMPLATE,
            "--ffmpeg-location", self.runtime.ffmpeg_path,
            "--user-agent", self._get_user_agent(),
            "--socket-timeout", "60",
            "--retries", "5",
            "--retry-sleep", "http:linear=5:15:2",
            "--retry-sleep", "fragment:linear=5:15:2",
        ]
        if self.runtime.write_thumbnail:
            cmd.append("--write-thumbnail")
        return cmd

    def _get_user_agent(self) -> str:
        browser_ua = get_instagram_browser_user_agent()
        if browser_ua:
            return browser_ua
        return random.choice(INSTAGRAM_USER_AGENTS)

    def _run_strategy(
        self,
        cmd: list[str],
        url: str,
        log_callback,
        strategy_name: str,
        *,
        cookie_used: bool,
        deno_used: bool = False,
    ) -> dict:
        return run_logged_download_strategy(
            self,
            cmd,
            url,
            log_callback,
            strategy_name,
            cookie_used=cookie_used,
            deno_used=deno_used,
        )

    def _parse_progress(self, line: str):
        title_match = re.search(r"\[download\] Destination:\s+(.+)", line)
        if title_match and self.runtime.progress_callback:
            self.runtime.progress_callback("title", title_match.group(1))

        progress = parse_download_progress(line)
        if not progress:
            return

        if self.runtime.progress_callback:
            self.runtime.progress_callback("progress", progress)
        if self.runtime.speed_callback and progress.get("speed"):
            self.runtime.speed_callback(progress["speed"])
        maybe_log_download_progress(
            self.runtime,
            self.platform_name,
            getattr(self.runtime, "_active_strategy_name", ""),
            progress,
        )
