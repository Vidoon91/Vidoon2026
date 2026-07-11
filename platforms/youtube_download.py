import os
import random
import re
from urllib.parse import parse_qs, urlencode, urlparse, urlunparse

from setting import DenoResolver
from core.download_types import DownloadRuntime, build_result
from core.download_utils import (
    DOWNLOAD_PROGRESS_TEMPLATE,
    build_selected_format_print_template,
    maybe_log_download_progress,
    parse_download_progress,
    run_logged_download_strategy,
)


YOUTUBE_USER_AGENTS = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:136.0) Gecko/20100101 Firefox/136.0",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36",
]

YOUTUBE_QUALITY_SELECTOR = (
    "bestvideo[height>=2160][ext=mp4]+bestaudio[ext=m4a]/"
    "bestvideo[height>=1440][ext=mp4]+bestaudio[ext=m4a]/"
    "bestvideo[height>=1080][ext=mp4]+bestaudio[ext=m4a]/"
    "bestvideo[height>=720][ext=mp4]+bestaudio[ext=m4a]/"
    "best"
)

YOUTUBE_SHORTS_QUALITY_SELECTOR = YOUTUBE_QUALITY_SELECTOR

YOUTUBE_FALLBACK_QUALITY_SELECTOR = (
    "bestvideo[height<=1080][ext=mp4]+bestaudio[ext=m4a]/"
    "bestvideo[height<=720][ext=mp4]+bestaudio[ext=m4a]/"
    "best[height<=1080][ext=mp4]/"
    "best[height<=720]/"
    "best"
)

YOUTUBE_SORT_SELECTOR = "res,fps,br"


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


def get_browser_user_agent() -> str:
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


class YouTubeDownloader:
    platform_name = "YouTube"

    def __init__(self, runtime: DownloadRuntime):
        self.runtime = runtime
        self.deno_resolver = None
        if runtime.enable_deno and runtime.deno_path and os.path.exists(runtime.deno_path):
            self.deno_resolver = DenoResolver(runtime.deno_path, runtime.deno_timeout)

    def normalize_url(self, url: str) -> dict:
        original_url = url.strip()
        parsed = urlparse(original_url)
        scheme = parsed.scheme or "https"
        netloc = parsed.netloc or "www.youtube.com"
        query = parse_qs(parsed.query)
        path = parsed.path
        is_shorts = "/shorts/" in path

        if "youtu.be" in netloc:
            video_id = parsed.path.strip("/")
            path = "/watch"
            query = {"v": [video_id]}
            netloc = "www.youtube.com"

        kept = {}
        for key in ("v", "list", "t", "time_continue"):
            if key in query:
                kept[key] = query[key][0]

        normalized_url = urlunparse((scheme, netloc, path, "", urlencode(kept), parsed.fragment or ""))
        return {
            "success": True,
            "original_url": original_url,
            "normalized_url": normalized_url,
            "platform": self.platform_name,
            "url_modified": normalized_url != original_url,
            "modification_reason": "normalized YouTube URL" if normalized_url != original_url else "no changes",
            "is_shorts": is_shorts,
            "message": "YouTube URL normalized (Shorts)" if is_shorts else "YouTube URL normalized",
        }

    def classify_error(self, stderr_text: str) -> str:
        error_text = (stderr_text or "").lower()
        patterns = [
            (["http error 429", "too many requests", "rate limit"], "NETWORK_RATE_LIMIT"),
            ([
                "sign in to confirm you're not a bot",
                "confirm youre not a bot",
                "not a bot",
                "bot check",
                "missing a valid po_token",
                "po_token",
            ], "AUTH_BOT_CHECK"),
            (["cookies are no longer valid", "provided youtube account cookies are no longer valid"], "AUTH_COOKIE_INVALID"),
            (["http error 416", "requested range not satisfiable"], "DOWNLOAD_RANGE_INVALID"),
            (["confirm your age", "age-restricted", "age restricted"], "CONTENT_AGE_RESTRICTED"),
            (["private video", "private"], "PRIVATE_VIDEO"),
            (["login required", "sign in to confirm your age", "login", "sign in"], "AUTH_NEED_LOGIN"),
            (["unable to extract", "failed to parse", "signature", "n-sig", "no video formats", "unsupported url"], "EXTRACTOR_FAILED"),
            (["timeout", "connection", "network"], "NETWORK_ERROR"),
        ]
        for keys, value in patterns:
            if any(key in error_text for key in keys):
                return value
        return "UNKNOWN"

    def should_use_cookie(self, error_source: str) -> bool:
        return error_source in {"AUTH_NEED_LOGIN", "CONTENT_AGE_RESTRICTED", "PRIVATE_VIDEO", "AUTH_BOT_CHECK"}

    def should_use_deno(self, error_source: str) -> bool:
        return error_source in {"EXTRACTOR_FAILED", "AUTH_BOT_CHECK", "UNKNOWN"}

    def should_use_advanced_auth(self, error_source: str) -> bool:
        return error_source in {"AUTH_BOT_CHECK", "AUTH_NEED_LOGIN", "EXTRACTOR_FAILED"}

    def should_use_format_fallback(self, error_source: str) -> bool:
        return error_source in {"DOWNLOAD_RANGE_INVALID", "EXTRACTOR_FAILED", "UNKNOWN", "AUTH_BOT_CHECK"}

    def is_ip_risk_error(self, error_source: str) -> bool:
        return error_source in {
            "NETWORK_RATE_LIMIT",
            "AUTH_BOT_CHECK",
        }

    def _friendly_message(self, error_source: str) -> str:
        messages = {
            "NETWORK_RATE_LIMIT": "当前 IP 请求过多，已被 YouTube 限流，下载已停止。请更换 VPN 节点或稍后再试。",
            "AUTH_COOKIE_INVALID": "这份 YouTube Cookie 已失效，请重新导出并导入。",
            "AUTH_BOT_CHECK": "当前 IP/VPN 节点已触发 YouTube 风控或机器人验证，下载已停止。请更换 VPN 节点、切换出口 IP，或降低下载频率后重试。",
            "NETWORK_ERROR": "网络连接异常，请检查网络或 VPN 后重试。",
            "CONTENT_AGE_RESTRICTED": "该视频有年龄限制，请使用有效的 YouTube Cookie。",
            "PRIVATE_VIDEO": "该视频是私密视频，当前账号无权访问。",
            "AUTH_NEED_LOGIN": "这个 YouTube 视频需要登录后才能下载，请导入可用的 YouTube Cookie。",
            "EXTRACTOR_FAILED": "YouTube 解析失败，可尝试更新 yt-dlp、配置 visitor_data/poToken，或启用 Deno。",
        }
        return messages.get(error_source, "下载失败，请检查链接、网络或更新 yt-dlp。")

    def execute_download(self, url_info: dict, log_callback) -> dict:
        normalized_url = url_info["normalized_url"]
        is_shorts = bool(url_info.get("is_shorts", False))

        log_callback("📋 平台: YouTube")
        if is_shorts:
            log_callback("📱 检测到 Shorts 视频")

        result = self._download_platform_without_cookie(normalized_url, log_callback, is_shorts)
        if result["success"]:
            return result

        error_source = result["error_source"]
        if self.is_ip_risk_error(error_source):
            log_callback("⛔ YouTube 下载已停止：当前 IP/VPN 节点触发平台风控")
            log_callback("原因：该 IP 可能请求过多、被 YouTube 标记为异常环境，继续重试无意义")
            log_callback("处理建议：请更换 VPN 节点 / 更换出口 IP / 降低下载频率后重新下载")
            result["message"] = self._friendly_message(error_source)
            return result

        if error_source in {"AUTH_COOKIE_INVALID", "NETWORK_ERROR"}:
            result["message"] = self._friendly_message(error_source)
            return result

        if self.should_use_cookie(error_source):
            if self._has_cookie_file():
                result = self._download_platform_with_cookie(normalized_url, log_callback, is_shorts)
                if result["success"]:
                    return result
                if self.is_ip_risk_error(result["error_source"]):
                    result["message"] = self._friendly_message(result["error_source"])
                    return result
                if result["error_source"] in {
                    "AUTH_COOKIE_INVALID",
                    "NETWORK_ERROR",
                }:
                    result["message"] = self._friendly_message(result["error_source"])
                    return result
            else:
                log_callback("⚠️ 当前错误需要 YouTube Cookie，但未找到 cookies.txt")

        if self.should_use_advanced_auth(result["error_source"]):
            if self._has_cookie_file() and self._has_advanced_auth_params():
                result = self._download_platform_with_advanced_auth(normalized_url, log_callback, is_shorts)
                if result["success"]:
                    return result
                if self.is_ip_risk_error(result["error_source"]):
                    result["message"] = self._friendly_message(result["error_source"])
                    return result
                if result["error_source"] in {"AUTH_COOKIE_INVALID", "NETWORK_ERROR"}:
                    result["message"] = self._friendly_message(result["error_source"])
                    return result
            elif self._has_cookie_file():
                log_callback("策略3：自动增强机器人验证处理已关闭，跳过高级验证参数模式")

        if self.runtime.youtube_format_fallback and self.should_use_format_fallback(result["error_source"]):
            result = self._download_format_fallback(normalized_url, log_callback, is_shorts)
            if result["success"]:
                return result
            if self.is_ip_risk_error(result["error_source"]):
                result["message"] = self._friendly_message(result["error_source"])
                return result
            if result["error_source"] in {"AUTH_COOKIE_INVALID", "NETWORK_ERROR"}:
                result["message"] = self._friendly_message(result["error_source"])
                return result

        if self.should_use_deno(result["error_source"]) and self.deno_resolver:
            result = self._download_with_deno(normalized_url, log_callback, is_shorts, url_info)
            if result["success"]:
                return result
            result["message"] = self._friendly_message(result.get("error_source", "UNKNOWN"))
            return result

        result["message"] = self._friendly_message(result.get("error_source", "UNKNOWN"))
        return result

    def _has_cookie_file(self) -> bool:
        return bool(self.runtime.cookie_file and os.path.exists(self.runtime.cookie_file))

    def _has_advanced_auth_params(self) -> bool:
        return bool(self.runtime.youtube_advanced_auth_enabled)

    def _download_platform_without_cookie(self, url: str, log_callback, is_shorts: bool) -> dict:
        log_callback("策略1：YouTube 无 Cookie 下载...")
        cmd = self._build_youtube_command(url, is_shorts, use_cookie=False)
        return self._run_strategy(cmd, url, log_callback, "PlatformNoCookie", cookie_used=False)

    def _download_platform_with_cookie(self, url: str, log_callback, is_shorts: bool) -> dict:
        log_callback("策略2：YouTube Cookie 下载...")
        cmd = self._build_youtube_command(url, is_shorts, use_cookie=True)
        log_callback("🍪 使用 YouTube cookies.txt")
        return self._run_strategy(cmd, url, log_callback, "PlatformCookie", cookie_used=True)

    def _download_platform_with_advanced_auth(self, url: str, log_callback, is_shorts: bool) -> dict:
        log_callback("策略3：YouTube Cookie + 高级验证参数...")
        cmd = self._build_youtube_command(url, is_shorts, use_cookie=True, use_advanced_auth=True)
        return self._run_strategy(cmd, url, log_callback, "YouTubeAdvancedAuth", cookie_used=True)

    def _download_format_fallback(self, url: str, log_callback, is_shorts: bool) -> dict:
        log_callback("策略4：降级格式重试...")
        cmd = self._build_youtube_command(
            url,
            is_shorts,
            use_cookie=self._has_cookie_file(),
            use_advanced_auth=self._has_cookie_file() and self._has_advanced_auth_params(),
            fallback_format=True,
        )
        return self._run_strategy(
            cmd,
            url,
            log_callback,
            "YouTubeFormatFallback",
            cookie_used=self._has_cookie_file(),
        )

    def _download_with_deno(self, url: str, log_callback, is_shorts: bool, url_info: dict) -> dict:
        log_callback("策略5：Deno 兜底解析...")
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
        cmd = self._build_youtube_command(
            resolved_url,
            is_shorts,
            use_cookie=self._has_cookie_file(),
            use_deno_url=True,
        )
        return self._run_strategy(
            cmd,
            resolved_url,
            log_callback,
            "DenoFallback",
            cookie_used=self._has_cookie_file(),
            deno_used=True,
        )

    def _build_youtube_command(
        self,
        url: str,
        is_shorts: bool,
        *,
        use_cookie: bool,
        use_deno_url: bool = False,
        use_advanced_auth: bool = False,
        fallback_format: bool = False,
    ) -> list[str]:
        cmd = self._build_base_command()
        cmd.extend(["--merge-output-format", "mp4", "--remux-video", "mp4"])
        quality_selector = (
            YOUTUBE_FALLBACK_QUALITY_SELECTOR
            if fallback_format
            else (YOUTUBE_SHORTS_QUALITY_SELECTOR if is_shorts else YOUTUBE_QUALITY_SELECTOR)
        )

        cmd.extend(
            [
                "--add-header",
                "Referer:https://www.youtube.com/",
                "--postprocessor-args",
                "ffmpeg:-c:v copy -c:a aac -b:a 192k -movflags +faststart",
                "--concurrent-fragments",
                "3",
                "-S",
                YOUTUBE_SORT_SELECTOR,
                "--print",
                build_selected_format_print_template(),
                "-f",
                quality_selector,
                "-o",
                os.path.join(self.runtime.save_path, "%(title)s-%(id)s.%(ext)s"),
            ]
        )

        extractor_args = self._build_extractor_args(is_shorts, use_deno_url, use_advanced_auth)
        if extractor_args:
            cmd.extend(["--extractor-args", extractor_args])

        if use_cookie and self._has_cookie_file():
            cmd.extend(["--cookies", self.runtime.cookie_file])

        cmd.append(url)
        return cmd

    def _build_extractor_args(self, is_shorts: bool, use_deno_url: bool, use_advanced_auth: bool) -> str:
        if use_advanced_auth and (self.runtime.youtube_advanced_extractor_args or "").strip():
            return self.runtime.youtube_advanced_extractor_args.strip()

        segments = []
        if use_deno_url or use_advanced_auth:
            player_client = "web,android" if is_shorts else "web,android,ios"
            segments.append(f"player_client={player_client}")

        if use_advanced_auth:
            visitor_data = (self.runtime.youtube_visitor_data or "").strip()
            po_token = (self.runtime.youtube_po_token or "").strip()
            po_context = (self.runtime.youtube_po_token_context or "web.gvs").strip() or "web.gvs"
            if visitor_data:
                segments.append(f"visitor_data={visitor_data}")
            if po_token:
                token_value = po_token if "+" in po_token else f"{po_context}+{po_token}"
                segments.append(f"po_token={token_value}")

        return "youtube:" + ";".join(segments) if segments else ""

    def _build_base_command(self) -> list[str]:
        user_agent = self._get_user_agent()
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
            "--ffmpeg-location",
            self.runtime.ffmpeg_path,
            "--user-agent",
            user_agent,
            "--socket-timeout",
            "60",
            "--retries",
            "5",
            "--retry-sleep",
            "http:linear=5:15:2",
            "--retry-sleep",
            "fragment:linear=5:15:2",
            "--sleep-requests",
            "1",
        ]
        if self.runtime.enable_deno and self.runtime.deno_path and os.path.exists(self.runtime.deno_path):
            cmd.extend(["--js-runtimes", f"deno:{self.runtime.deno_path}"])
        return cmd

    def _get_user_agent(self) -> str:
        configured = (self.runtime.youtube_user_agent or "").strip()
        if configured and configured.startswith("Mozilla/"):
            return configured

        if self.runtime.youtube_use_browser_user_agent:
            browser_user_agent = get_browser_user_agent()
            if browser_user_agent:
                return browser_user_agent

        return random.choice(YOUTUBE_USER_AGENTS)

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
