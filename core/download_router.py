import re

from core.download_types import DownloadRuntime
from platforms.youtube_download import YouTubeDownloader
from platforms.instagram_download import InstagramDownloader
from platforms.tiktok_download import TikTokDownloader
from platforms.twitter_download import TwitterDownloader


PLATFORM_PATTERNS = {
    "YouTube": re.compile(r"(?:youtube\.com|youtu\.be)", re.IGNORECASE),
    "Instagram": re.compile(r"instagram\.com", re.IGNORECASE),
    "Twitter": re.compile(r"(?:twitter\.com|x\.com)", re.IGNORECASE),
    "TikTok": re.compile(r"tiktok\.com", re.IGNORECASE),
}


def identify_platform(url: str) -> str:
    for platform_name, pattern in PLATFORM_PATTERNS.items():
        if pattern.search(url or ""):
            return platform_name
    return "Unknown"


def create_downloader(platform_name: str, runtime: DownloadRuntime):
    if platform_name == "YouTube":
        return YouTubeDownloader(runtime)
    if platform_name == "Instagram":
        return InstagramDownloader(runtime)
    if platform_name == "Twitter":
        return TwitterDownloader(runtime)
    if platform_name == "TikTok":
        return TikTokDownloader(runtime)
    return None
