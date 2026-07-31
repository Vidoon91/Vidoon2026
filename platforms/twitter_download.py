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
from platforms.youtube_download import get_browser_user_agent


TWITTER_USER_AGENTS = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:145.0) Gecko/20100101 Firefox/145.0",
    "Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Mobile/15E148 Safari/604.1",
]

TWITTER_QUALITY_SELECTOR = (
    "bestvideo[height>=2160]+bestaudio/"
    "bestvideo[height>=1440]+bestaudio/"
    "bestvideo[height>=1080]+bestaudio/"
    "bestvideo[height>=720]+bestaudio/"
    "best[ext=mp4]/"
    "best"
)

TWITTER_SORT_SELECTOR = "res,fps,br"


class TwitterDownloader:
    platform_name = "Twitter"

    def __init__(self, runtime: DownloadRuntime):
        self.runtime = runtime
        self.deno_resolver = None
        if runtime.enable_deno and runtime.deno_path and os.path.exists(runtime.deno_path):
            self.deno_resolver = DenoResolver(runtime.deno_path, 12)

    def normalize_url(self, url: str) -> dict:
        original_url = url.strip()
        parsed = urlparse(original_url)
        query = parse_qs(parsed.query)
        clean_query = {}

        for key in ("s", "t"):
            if key in query:
                clean_query[key] = query[key][0]

        netloc = parsed.netloc or "x.com"
        if netloc.lower() == "mobile.twitter.com":
            netloc = "twitter.com"

        normalized = urlunparse((
            parsed.scheme or "https",
            netloc,
            parsed.path,
            "",
            urlencode(clean_query),
            parsed.fragment or "",
        ))
        modified = normalized != original_url
        return {
            "success": True,
            "original_url": original_url,
            "normalized_url": normalized,
            "platform": self.platform_name,
            "url_modified": modified,
            "modification_reason": "removed tracking params" if modified else "no changes",
            "is_shorts": False,
            "message": "Twitter URL normalized",
        }

    def classify_error(self, stderr_text: str) -> str:
        error_text = (stderr_text or "").lower()
        patterns = [
            (["http error 429", "too many requests", "rate limit", "429"], "NETWORK_RATE_LIMIT"),
            (["http error 403", "forbidden"], "NETWORK_FORBIDDEN"),
            (["http error 416", "requested range not satisfiable"], "DOWNLOAD_RANGE_INVALID"),
            ([
                "login required",
                "sign in to view",
                "authentication required",
                "this account is private",
                "protected account",
            ], "AUTH_NEED_LOGIN"),
            (["cookies are no longer valid"], "AUTH_COOKIE_INVALID"),
            (["tweet is unavailable", "post is unavailable", "tweet has been deleted", "post has been deleted"], "CONTENT_UNAVAILABLE"),
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
        return error_source in {"AUTH_NEED_LOGIN", "NETWORK_FORBIDDEN"}

    def should_use_deno(self, error_source: str) -> bool:
        return error_source == "EXTRACTOR_FAILED"

    def execute_download(self, url_info: dict, log_callback) -> dict:
        normalized_url = url_info["normalized_url"]
        log_callback("平台: Twitter/X")

        result = self._download_platform_without_cookie(normalized_url, log_callback)
        if result["success"]:
            return result

        if result["error_source"] == "AUTH_COOKIE_INVALID":
            result["message"] = "这份 Twitter Cookie 已失效，请重新导出并导入。"
            return result
        if result["error_source"] == "NETWORK_ERROR":
            result["message"] = "网络连接异常，请检查网络或 VPN 后重试。"
            return result
        if result["error_source"] == "CONTENT_UNAVAILABLE":
            result["message"] = "该 Twitter/X 视频不可用、已删除或当前账号无权访问。"
            return result

        if self.should_use_cookie(result["error_source"]):
            if self._has_cookie_file():
                result = self._download_platform_with_cookie(normalized_url, log_callback)
                if result["success"]:
                    return result
                if result["error_source"] == "AUTH_COOKIE_INVALID":
                    result["message"] = "这份 Twitter Cookie 已失效，请重新导出并导入。"
                    return result
                if result["error_source"] == "NETWORK_ERROR":
                    result["message"] = "网络连接异常，请检查网络或 VPN 后重试。"
                    return result
            else:
                log_callback("当前错误需要 Twitter Cookie，但未找到 twitter_cookies.txt")

        if self.should_use_deno(result["error_source"]) and self.deno_resolver:
            return self._download_with_deno(normalized_url, log_callback, url_info)

        return result

    def _has_cookie_file(self) -> bool:
        return bool(self.runtime.twitter_cookie_file and os.path.exists(self.runtime.twitter_cookie_file))

    def _download_platform_without_cookie(self, url: str, log_callback) -> dict:
        log_callback("策略1：Twitter/X 无 Cookie 下载...")
        cmd = self._build_twitter_command(url, use_cookie=False)
        return self._run_strategy(cmd, url, log_callback, "PlatformNoCookie", cookie_used=False)

    def _download_platform_with_cookie(self, url: str, log_callback) -> dict:
        log_callback("策略2：Twitter/X Cookie 下载...")
        cmd = self._build_twitter_command(url, use_cookie=True)
        log_callback("使用 Twitter Cookie 文件")
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
        cmd = self._build_twitter_command(resolved_url, use_cookie=self._has_cookie_file())
        return self._run_strategy(
            cmd,
            resolved_url,
            log_callback,
            "DenoFallback",
            cookie_used=self._has_cookie_file(),
            deno_used=True,
        )

    def _build_twitter_command(self, url: str, *, use_cookie: bool) -> list[str]:
        cmd = self._build_base_command()
        cmd.extend([
            "--merge-output-format", "mp4",
            "--add-header", "Referer:https://x.com/",
            "--sleep-requests", "2",
            "--min-sleep-interval", "1",
            "--max-sleep-interval", "5",
            "--postprocessor-args", "ffmpeg:-c:v copy -c:a aac -b:a 192k -movflags +faststart",
            "-S", TWITTER_SORT_SELECTOR,
            "--print", build_selected_format_print_template(),
            "--print", build_output_file_print_template(),
            "--print", build_output_id_print_template(),
            "-f", TWITTER_QUALITY_SELECTOR,
            "-o", os.path.join(self.runtime.save_path, "%(title)s-%(id)s.%(ext)s"),
        ])

        if use_cookie and self._has_cookie_file():
            cmd.extend(["--cookies", self.runtime.twitter_cookie_file])

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
        browser_user_agent = get_browser_user_agent()
        if browser_user_agent:
            return browser_user_agent
        return random.choice(TWITTER_USER_AGENTS)

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
