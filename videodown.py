# -*- coding: utf-8 -*-
"""
VideoDown 模块 - 视频下载 UI 与队列调度外壳
包含：VideoDownloadPage, VideoDownloaderCore
负责视频下载页面、下载任务队列、日志和进度桥接
平台下载策略已经迁移到 core/ 与 platforms/ 目录
"""

import os
import sys
import threading
import re
import random
import time
from datetime import datetime

from PySide6.QtWidgets import (
    QWidget, QVBoxLayout, QHBoxLayout, QLabel, QPushButton,
    QTextEdit, QFrame, QSizePolicy, QSpacerItem, QCheckBox,
    QFileDialog, QMessageBox
)
from PySide6.QtCore import Qt

from core.download_router import identify_platform, create_downloader
from core.download_types import DownloadRuntime
from core.download_utils import get_error_message, get_strategy_label, get_success_message
from ui_components import UIComponents

if getattr(sys, "frozen", False):
    BASE_DIR = os.path.dirname(sys.executable)
else:
    BASE_DIR = os.path.abspath(os.path.dirname(__file__))


TASK_SWITCH_COOLDOWNS = {
    "YouTube": {
        "normal": (0.8, 1.8),
        "cautious": (1.3, 2.1),
        "elevated": (1.8, 2.5),
        "protected": (3.0, 6.0),
        "protected_seconds": 120,
    },
    "TikTok": {
        "normal": (1.2, 2.2),
        "cautious": (1.8, 2.4),
        "elevated": (2.2, 2.8),
        "protected": (3.5, 6.0),
        "protected_seconds": 180,
    },
    "Instagram": {
        "normal": (1.5, 2.5),
        "cautious": (2.0, 2.6),
        "elevated": (2.4, 3.0),
        "protected": (3.5, 6.0),
        "protected_seconds": 180,
    },
    "default": {
        "normal": (1.0, 2.0),
        "cautious": (1.5, 2.3),
        "elevated": (2.0, 2.8),
        "protected": (3.0, 5.0),
        "protected_seconds": 120,
    },
}

RISK_ERROR_SOURCES = {
    "NETWORK_RATE_LIMIT",
    "NETWORK_FORBIDDEN",
    "AUTH_CAPTCHA",
    "AUTH_NEED_LOGIN",
    "AUTH_COOKIE_REQUIRED",
    "CONTENT_AGE_RESTRICTED",
}

WARNING_ERROR_SOURCES = {
    "NETWORK_CONNECTION",
    "NETWORK_TIMEOUT",
    "EXTRACTOR_FAILED",
    "EXTRACTOR_UNSUPPORTED",
}


class AdaptiveTaskPacer:
    """Adjust inter-task cooldowns based on recent platform outcomes."""

    def __init__(self):
        self._lock = threading.Lock()
        self._states = {}

    def _profile_for(self, platform_name):
        return TASK_SWITCH_COOLDOWNS.get(platform_name, TASK_SWITCH_COOLDOWNS["default"])

    def _state_for(self, platform_name):
        if platform_name not in self._states:
            self._states[platform_name] = {
                "pressure": 0,
                "success_streak": 0,
                "protected_until": 0.0,
                "last_error": "",
            }
        return self._states[platform_name]

    def record_result(self, platform_name, success, error_source=None):
        platform_name = platform_name or "default"
        now = time.monotonic()
        profile = self._profile_for(platform_name)

        with self._lock:
            state = self._state_for(platform_name)
            if success:
                state["success_streak"] += 1
                state["last_error"] = ""
                if state["success_streak"] >= 2:
                    state["pressure"] = max(0, state["pressure"] - 1)
                if state["success_streak"] >= 4:
                    state["protected_until"] = 0.0
                return

            state["success_streak"] = 0
            state["last_error"] = error_source or ""
            if error_source in RISK_ERROR_SOURCES:
                state["pressure"] = max(2, state["pressure"] + 1)
                state["protected_until"] = max(
                    state["protected_until"],
                    now + profile["protected_seconds"],
                )
            elif error_source in WARNING_ERROR_SOURCES:
                state["pressure"] = max(1, min(2, state["pressure"] + 1))
            else:
                state["pressure"] = min(1, state["pressure"] + 1)

    def get_cooldown(self, platform_name):
        platform_name = platform_name or "default"
        now = time.monotonic()
        profile = self._profile_for(platform_name)

        with self._lock:
            state = self._state_for(platform_name)
            protected_remaining = max(0.0, state["protected_until"] - now)
            if protected_remaining > 0:
                return profile["protected"], "protected", protected_remaining

            pressure = state["pressure"]
            if pressure >= 2:
                return profile["elevated"], "elevated", 0.0
            if pressure == 1:
                return profile["cautious"], "cautious", 0.0
            return profile["normal"], "normal", 0.0

# ==================== Download UI ====================

class VideoDownloadPage(QWidget):
    """视频下载页面。"""
    
    def __init__(self, parent, log_handler, config, platform=None):
        super().__init__(parent)
        self.parent = parent
        self.log_handler = log_handler
        self.config = config
        self.platform = platform  # 
        self.signal_handler = None
        self.download_callback = None
        
        self._init_state()
        self._build_ui()
    
    def _init_state(self):
        """"""
        self.current_video_title = "等待下载..."
        self.current_video_progress = 0
        self.current_downloaded_size = "0 B"
        self.current_total_size = "0 B"
        self.current_eta = "00:00"
        self.current_speed = "0 KB/s"
        self.latest_preview_file = ""
    
    def _build_ui(self):
        """构建下载 UI。"""
        layout = QVBoxLayout(self)
        layout.setContentsMargins(12, 12, 12, 12)
        layout.setSpacing(8)

        # ?
        toolbar = QHBoxLayout()
        
        toolbar.addSpacing(10)
        
        # ?
        self.btn_paste = UIComponents.create_button("粘贴链接", 27, 80)
        self.btn_paste.clicked.connect(self._on_paste)
        toolbar.addWidget(self.btn_paste)
        
        # txt
        self.btn_import = UIComponents.create_button("导入 TXT", 27, 100, "从 TXT 文件中批量导入视频链接")
        self.btn_import.clicked.connect(self._on_import_txt)
        toolbar.addWidget(self.btn_import)
        
        # 
        self.btn_start = UIComponents.create_button("开始下载", 27, 80)
        self.btn_start.clicked.connect(self.start_video_download)
        toolbar.addWidget(self.btn_start)

        self.btn_preview = UIComponents.create_button("预览播放", 27, 80, "预览最近下载完成的视频")
        self.btn_preview.setEnabled(False)
        self.btn_preview.clicked.connect(self._on_preview)
        toolbar.addWidget(self.btn_preview)

        toolbar.addSpacerItem(QSpacerItem(20, 20, QSizePolicy.Expanding, QSizePolicy.Minimum))



        layout.addLayout(toolbar)

        # Cookie?- ?
        self.cookie_status_label = UIComponents.create_label("正在检查 Cookie 状态...", "font-size: 11px; margin-top: 2px; color: #9ca3af;")
        layout.addWidget(self.cookie_status_label)

        if self.platform == 'YouTube':
            self.chk_youtube_advanced_auth = QCheckBox("自动增强机器人验证处理")
            self.chk_youtube_advanced_auth.setChecked(bool(self.config.get("youtube_advanced_auth_enabled", True)))
            self.chk_youtube_advanced_auth.setToolTip("触发 YouTube 机器人验证时，自动尝试 Cookie + 高级客户端参数、poToken（如可用）和降级格式重试。")
            self.chk_youtube_advanced_auth.stateChanged.connect(self._save_youtube_advanced_auth_enabled)
            layout.addWidget(self.chk_youtube_advanced_auth)

        # 
        self.input_box = QTextEdit()
        # 
        if self.platform == 'YouTube':
            placeholder = "请输入 YouTube 视频链接，每行一个，最多支持 50 个链接"
        elif self.platform == 'TikTok':
            placeholder = "请输入 TikTok 视频链接，每行一个，最多支持 50 个链接"
        elif self.platform == 'Instagram':
            placeholder = "请输入 Instagram 视频链接，每行一个，最多支持 50 个链接"
        else:
            placeholder = "请输入视频链接，每行一个，最多支持 50 个链接"
        self.input_box.setPlaceholderText(placeholder)
        self.input_box.setFixedHeight(108)
        self.input_box.setAcceptDrops(True)
        self.input_box.textChanged.connect(self._check_input_limit)
        layout.addWidget(self.input_box)

        # 下载摘要
        summary_frame = QFrame()
        summary_frame.setObjectName("downloadSummary")
        summary_layout = QHBoxLayout(summary_frame)
        summary_layout.setContentsMargins(8, 6, 8, 6)
        summary_layout.setSpacing(14)

        self.lbl_download_percent = UIComponents.create_label("下载百分比：0.0%", "font-weight: bold; color: #0F172A;")
        stats_layout = QHBoxLayout()
        self.lbl_total_tasks = UIComponents.create_label("总任务：0")
        self.lbl_completed_tasks = UIComponents.create_label("完成：0")
        self.lbl_failed_tasks = UIComponents.create_label("失败：0")
        self.lbl_speed = UIComponents.create_label("速度：0 KB/s", "color: #0F172A; font-weight: bold;")

        summary_layout.addWidget(self.lbl_download_percent)
        summary_layout.addWidget(self.lbl_speed)
        summary_layout.addWidget(self.lbl_completed_tasks)
        summary_layout.addWidget(self.lbl_failed_tasks)
        summary_layout.addStretch()
        summary_layout.addWidget(self.lbl_total_tasks)
        layout.addWidget(summary_frame)

        # 下载日志
        self.download_log_box = QTextEdit()
        self.download_log_box.setReadOnly(True)
        self.download_log_box.setLineWrapMode(QTextEdit.WidgetWidth)
        self.download_log_box.setPlaceholderText("下载日志将在此显示...")
        layout.addWidget(self.download_log_box, stretch=1)
    
    def update_task_stats(self, total, completed, failed):
        """更新任务统计。"""
        try:
            self.lbl_total_tasks.setText(f"总任务：{total}")
            self.lbl_completed_tasks.setText(f"完成：{completed}")
            self.lbl_failed_tasks.setText(f"失败：{failed}")
            percent = (completed / total * 100) if total > 0 else 0
            self.lbl_download_percent.setText(f"下载百分比：{percent:.1f}%")
        except Exception as e:
            print(f"更新任务统计失败: {e}")
    
    def _check_input_limit(self):
        """检查输入数量限制。"""
        text = self.input_box.toPlainText()
        lines = [line.strip() for line in text.split('\n') if line.strip()]
        if len(lines) > 50:
            # 0
            self.input_box.setPlainText('\n'.join(lines[:50]))
            self.log_handler.log("最多只能输入 50 个链接，超出的内容已自动裁剪。")
    
    def _on_paste(self):
        """从剪贴板粘贴链接。"""
        from PySide6.QtWidgets import QApplication
        text = QApplication.clipboard().text()
        if text:
            self.input_box.append(text)
            self.log_handler.log("已从剪贴板粘贴内容。")
        else:
            self.log_handler.log("剪贴板为空。")
    
    def _on_import_txt(self):
        """导入 TXT 链接文件。"""
        # txt?
        file_path, _ = QFileDialog.getOpenFileName(
            self,
            "选择 TXT 文件",
            "",
            "文本文件 (*.txt);;所有文件 (*.*)"
        )
        
        if file_path:
            try:
                # 
                with open(file_path, 'r', encoding='utf-8') as f:
                    content = f.read()
                
                # 
                lines = [line.strip() for line in content.split('\n') if line.strip()]
                
                # ?
                url_pattern = re.compile(r'https?://[^\s]+')
                valid_urls = []
                
                for line in lines:
                    urls = url_pattern.findall(line)
                    valid_urls.extend(urls)
                
                if valid_urls:
                    # ?
                    unique_urls = list(set(valid_urls))
                    
                    # ?0
                    if len(unique_urls) > 50:
                        unique_urls = unique_urls[:50]
                        self.log_handler.log(f"导入了 {len(valid_urls)} 个链接，已自动截取前 50 个。")
                    
                    # ?
                    self.input_box.clear()
                    for url in unique_urls:
                        self.input_box.append(url)
                    
                    self.log_handler.log(f"成功导入 {len(unique_urls)} 个有效链接。")
                    
                    # 
                    QMessageBox.information(
                        self,
                        "导入完成",
                        f"成功导入 {len(unique_urls)} 个链接\n"
                        f"原始行数：{len(lines)}\n"
                        f"有效链接：{len(valid_urls)}\n"
                        f"去重后保留：{len(unique_urls)}"
                    )
                else:
                    QMessageBox.warning(self, "导入失败", "文件中没有找到有效的视频链接。")
                    self.log_handler.log("导入的文件中没有找到有效的视频链接。")
                    
            except Exception as e:
                QMessageBox.critical(self, "导入失败", f"读取 TXT 文件时发生错误：\n{str(e)}")
                self.log_handler.log(f"导入 TXT 文件失败：{str(e)}")
    

    
    def set_signal_handler(self, signal_handler):
        """Refresh cookie status labels from current config."""
        self.signal_handler = signal_handler
    
    def set_download_callback(self, callback):
        """更新速度显示。"""
        self.download_callback = callback

    def set_preview_file(self, file_path):
        self.latest_preview_file = file_path or ""
        if hasattr(self, "btn_preview"):
            self.btn_preview.setEnabled(bool(self.latest_preview_file and os.path.exists(self.latest_preview_file)))
            if self.latest_preview_file:
                self.btn_preview.setToolTip(f"预览：{os.path.basename(self.latest_preview_file)}")
            else:
                self.btn_preview.setToolTip("预览最近下载完成的视频")

    def clear_preview_file(self):
        self.set_preview_file("")

    def _on_preview(self):
        if not self.latest_preview_file:
            QMessageBox.information(self, "预览播放", "还没有可预览的视频，请先完成一次视频下载。")
            return
        if self.parent and hasattr(self.parent, "open_video_preview"):
            self.parent.open_video_preview(self.latest_preview_file)
            return
        QMessageBox.warning(self, "预览播放", "当前窗口未连接播放服务。")
    
    def set_config(self, config):
        """更新当前下载进度。"""
        self.config = config

    def _save_youtube_advanced_auth_enabled(self):
        """Persist the YouTube advanced auth retry toggle."""
        if not hasattr(self, "chk_youtube_advanced_auth"):
            return
        enabled = self.chk_youtube_advanced_auth.isChecked()
        if hasattr(self.config, "set"):
            self.config.set("youtube_advanced_auth_enabled", enabled)
        elif isinstance(self.config, dict):
            self.config["youtube_advanced_auth_enabled"] = enabled
    
    def update_speed_display(self, speed_text):
        """更新视频标题显示。"""
        self.current_speed = speed_text
        self.lbl_speed.setText(f"速度：{speed_text}")

    def update_current_progress(self, progress_info):
        """重置下载页面状态。"""
        progress_type = progress_info.get('type', 'progress')
        
        if progress_type == 'title':
            title = progress_info.get('data', '未知视频')
            self.current_video_title = title
        
        elif progress_type == 'progress':
            data = progress_info.get('data', {})
            self.current_video_progress = data.get('percent', 0)
            self.current_downloaded_size = data.get('downloaded', '0 B')
            self.current_total_size = data.get('total', '0 B')
            self.current_speed = data.get('speed', '0 KB/s')
            self.current_eta = data.get('eta', '00:00')
            self.lbl_download_percent.setText(f"下载百分比：{self.current_video_progress:.1f}%")
            self.lbl_speed.setText(f"速度：{self.current_speed}")
    
    def update_video_title(self, title):
        """更新当前视频标题。"""
        self.current_video_title = title
    
    def reset_current_progress(self):
        """重置当前下载进度。"""
        self.current_video_title = "等待下载..."
        self.current_video_progress = 0
        self.current_downloaded_size = "0 B"
        self.current_total_size = "0 B"
        self.current_eta = "00:00"
        self.lbl_download_percent.setText("下载百分比：0.0%")
        self.lbl_speed.setText("速度：0 KB/s")
    
    def start_video_download(self):
        """Start queued video downloads from the text box."""
        if not self.download_callback:
            return
        
        text = self.input_box.toPlainText().strip()
        if not text:
            self.log_handler.log("没有可粘贴的链接")
            return
            
        urls = self._extract_and_validate_urls(text)
        if not urls:
            self.log_handler.log("请输入下载链接")
            return
        
        save_path = self.config.get("download_path", "")
        
        # 
        self.download_callback(urls, save_path, "video")
    
    def _extract_and_validate_urls(self, text):
        """"""
        url_pattern = re.compile(r'https?://[^\s]+')
        found_urls = url_pattern.findall(text)
        
        valid_urls = []
        supported_domains = ['youtube.com', 'youtu.be', 'bilibili.com', 'douyin.com', 
                            'tiktok.com', 'twitter.com', 'x.com', 'instagram.com',
                            'youtube.com/shorts', 'youtu.be/shorts', 'facebook.com',
                            'fb.watch', 'vimeo.com']
        
        for url in found_urls:
            if any(domain in url for domain in supported_domains):
                valid_urls.append(url)
            else:
                self.log_handler.log(f"无效链接: {url}")
        
        return valid_urls

# ====================  ====================
class VideoDownloaderCore:
    """Core threaded downloader with queue management."""
    
    def __init__(self, yt_dlp_path, ffmpeg_path, deno_path, config, signals, log_handler, 
                 update_progress_callback=None, check_completion_callback=None, update_task_stats_callback=None,
                 cookie_status=None, enable_deno=True, cookie_file=None, instagram_cookie_file=None, tiktok_cookie_file=None, twitter_cookie_file=None):
        self.yt_dlp_path = yt_dlp_path
        self.ffmpeg_path = ffmpeg_path
        self.config = config
        self.signals = signals
        self.log_handler = log_handler
        self.update_progress_callback = update_progress_callback
        self.check_completion_callback = check_completion_callback
        self.update_task_stats_callback = update_task_stats_callback
        self.cookie_status = cookie_status or {}
        
        self.download_queue = []
        self.active_downloads = {}
        self.queue_lock = threading.Lock()
        self.task_progress = {}
        self.task_status = {}
        self.max_threads = config.get("max_threads", 3)
        self.total_tasks = 0
        self.completed_tasks = 0
        self.failed_tasks = 0
        self.is_downloading = False
        
        self.current_display_url = None
        self.current_video_title = "等待下载..."
        self.last_save_path = ""
        self.base_dir = BASE_DIR
        self.cookie_file = self._resolve_optional_path(
            cookie_file if cookie_file is not None else config.get("cookie_file", ""),
            os.path.join(BASE_DIR, "cookies.txt"),
        )
        self.instagram_cookie_file = self._resolve_optional_path(
            instagram_cookie_file if instagram_cookie_file is not None else config.get("cookie_instagram", ""),
            os.path.join(BASE_DIR, "instagram_cookies.txt"),
        )
        self.tiktok_cookie_file = self._resolve_optional_path(
            tiktok_cookie_file if tiktok_cookie_file is not None else config.get("cookie_tiktok", ""),
            os.path.join(BASE_DIR, "tiktok_cookies.txt"),
        )
        self.twitter_cookie_file = self._resolve_optional_path(
            twitter_cookie_file if twitter_cookie_file is not None else config.get("cookie_twitter", ""),
            os.path.join(BASE_DIR, "twitter_cookies.txt"),
        )
        self.deno_path = self._resolve_optional_path(deno_path or config.get("deno_path", ""), os.path.join(BASE_DIR, "deno.exe"))
        self.enable_deno = bool(enable_deno and self.deno_path and os.path.exists(self.deno_path))
        
        self.task_pacer = AdaptiveTaskPacer()

    def _resolve_optional_path(self, configured_path, fallback_path=""):
        configured_path = (configured_path or "").strip()
        candidates = []

        if configured_path:
            if os.path.isabs(configured_path):
                candidates.append(configured_path)
            else:
                candidates.append(os.path.abspath(os.path.join(self.base_dir, configured_path)))

        if fallback_path:
            candidates.append(fallback_path)

        for candidate in candidates:
            if candidate and os.path.exists(candidate):
                return candidate

        return candidates[0] if candidates else ""

    def _get_task_switch_cooldown(self, url):
        """Return an adaptive per-platform cooldown between queued tasks."""
        platform_name = identify_platform(url) or "default"
        return platform_name, self.task_pacer.get_cooldown(platform_name)

    def _get_retry_count(self):
        """Read retry count from persisted settings."""
        try:
            retry_count = int(self.config.get("retry_count", 3))
        except Exception:
            retry_count = 3
        return max(1, retry_count)

    def _should_retry_download(self, error_source):
        """Only retry transient or likely-recoverable download failures."""
        retryable_errors = {
            "UNKNOWN",
            "NETWORK_ERROR",
            "NETWORK_TIMEOUT",
            "NETWORK_FORBIDDEN",
            "CONTENT_UNAVAILABLE",
            "EXTRACTOR_FAILED",
            "CONTENT_NO_FORMATS",
        }
        return error_source in retryable_errors

    def _should_retry_result(self, result):
        error_source = result.get("error_source") or "UNKNOWN"
        if result.get("strategy_used") == "DenoFallback" and error_source == "EXTRACTOR_FAILED":
            return False
        return self._should_retry_download(error_source)

    def _retry_sleep_seconds(self, attempt_index):
        """Backoff slightly between retry rounds."""
        base_delay = min(5, 2 + max(0, attempt_index - 1))
        return random.uniform(base_delay, base_delay + 2.0)

    def _execute_platform_download_with_retries(self, downloader, url_info, timestamp, log_callback):
        """Run platform downloader attempts with outer retry only for transient failures."""
        attempts = self._get_retry_count()
        last_result = None

        for attempt in range(1, attempts + 1):
            result = downloader.execute_download(
                url_info,
                log_callback=lambda msg: log_callback(
                    f"[{datetime.now().strftime('%H:%M:%S')}]"
                    f"{'' if str(msg).startswith('[策略') else ' '}{msg}"
                ),
            )
            last_result = result

            if result.get("success"):
                if attempt > 1:
                    log_callback(f"[{datetime.now().strftime('%H:%M:%S')}] 第 {attempt} 次尝试下载成功")
                return result

            error_source = result.get("error_source") or "UNKNOWN"
            if attempt >= attempts or not self._should_retry_result(result):
                return result

            wait_seconds = self._retry_sleep_seconds(attempt)
            log_callback(
                f"[{datetime.now().strftime('%H:%M:%S')}] 第 {attempt} 次下载失败："
                f"{get_error_message(error_source, url_info.get('platform', ''))}，"
                f"{wait_seconds:.1f} 秒后开始第 {attempt + 1}/{attempts} 次重试"
            )
            time.sleep(wait_seconds)

        return last_result or {"success": False, "error_source": "UNKNOWN", "message": "UNKNOWN"}

    def start_download(self, urls, save_path, download_type):
        """Worker loop that consumes download queue items."""
        self.last_save_path = save_path
        self.total_tasks = len(urls)
        self.completed_tasks = 0
        self.failed_tasks = 0
        self.task_progress = {u: 0.0 for u in urls}
        self.task_status = {u: "pending" for u in urls}
        self.is_downloading = True
        
        if self.update_task_stats_callback:
            self.update_task_stats_callback()
        
        with self.queue_lock:
            self.download_queue = [(u, save_path, "video") for u in urls]
            self.active_downloads = {}
        
        for _ in range(min(self.max_threads, len(urls))):
            t = threading.Thread(target=self._worker, daemon=True)
            t.start()
    
    def _worker(self):
        """下载队列工作线程。"""
        import random
        import time
        
        while self.is_downloading:
            with self.queue_lock:
                if not self.download_queue:
                    break
                url, save_path, download_type = self.download_queue.pop(0)
                self.active_downloads[url] = "starting"
                self.task_status[url] = "downloading"
            
            result_info = self._download_one(url, save_path, download_type)
            success = result_info.get('success', False)
            
            self.signals.task_complete_signal.emit(url, success, 100.0 if success else 0.0)
            
            if self.check_completion_callback:
                self.check_completion_callback()
            
            # ?
            with self.queue_lock:
                if self.download_queue:
                    platform_name, cooldown_state = self._get_task_switch_cooldown(url)
                    (cooldown_min, cooldown_max), pace_mode, protected_remaining = cooldown_state
                    delay = random.uniform(cooldown_min, cooldown_max)
                    if pace_mode == "protected":
                        self.log_handler.log(
                            f"{platform_name} pacing is in protected mode for another "
                            f"{protected_remaining:.0f}s; cooling down {delay:.2f}s before the next task"
                        )
                    else:
                        self.log_handler.log(
                            f"{platform_name} pacing mode: {pace_mode}; cooling down {delay:.2f}s before the next task"
                        )
                    time.sleep(delay)
    
    def _download_one(self, url, save_path, download_type):
        """Download a single URL with strategy fallback support."""
        if not self.is_downloading:
            return {"success": False, "platform": "Unknown", "error_source": "STOPPED"}
        
        timestamp = datetime.now().strftime("%H:%M:%S")

        platform_name = identify_platform(url)
        if platform_name == "Unknown":
            self.log_handler.log(f"[{timestamp}] 不支持的URL: {url}")
            return {"success": False, "platform": "Unknown", "error_source": "INVALID_URL"}

        runtime = DownloadRuntime(
            yt_dlp_path=self.yt_dlp_path,
            ffmpeg_path=self.ffmpeg_path,
            save_path=save_path,
            deno_path=self.deno_path,
            deno_timeout=int(self.config.get("deno_timeout", 12)),
            enable_deno=self.enable_deno,
            cookie_file=self.cookie_file,
            instagram_cookie_file=self.instagram_cookie_file,
            tiktok_cookie_file=self.tiktok_cookie_file,
            twitter_cookie_file=self.twitter_cookie_file,
            cookie_status=self.cookie_status,
            youtube_visitor_data=self.config.get("youtube_visitor_data", ""),
            youtube_po_token=self.config.get("youtube_po_token", ""),
            youtube_po_token_context=self.config.get("youtube_po_token_context", "web.gvs"),
            youtube_advanced_extractor_args=self.config.get("youtube_advanced_extractor_args", ""),
            youtube_advanced_auth_enabled=bool(self.config.get("youtube_advanced_auth_enabled", True)),
            youtube_format_fallback=bool(self.config.get("youtube_format_fallback", self.config.get("format_fallback", True))),
            youtube_user_agent=self.config.get("youtube_user_agent", self.config.get("user_agent", "")),
            youtube_use_browser_user_agent=bool(self.config.get("youtube_use_browser_user_agent", True)),
            speed_callback=lambda speed: self.signals.speed_signal.emit(speed),
            progress_callback=lambda type_, data: self.signals.current_progress_signal.emit({
                'type': type_,
                'url': url,
                'data': data,
            }),
        )
        downloader = create_downloader(platform_name, runtime)
        if downloader is None:
            self.log_handler.log(f"[{timestamp}] 平台暂不支持: {platform_name}")
            return {"success": False, "platform": platform_name, "error_source": "INVALID_PLATFORM"}

        url_result = downloader.normalize_url(url)
        if not url_result["success"]:
            self.log_handler.log(f"[{timestamp}] URL 解析失败: {url_result['message']}")
            return {"success": False, "platform": platform_name, "error_source": "INVALID_URL"}
        
        self.current_display_url = url

        self.log_handler.log_download_start(
            url=url_result['original_url'],
            platform=url_result['platform'],
            download_type="video",
            url_modified=url_result['url_modified'],
            modification_reason=url_result.get('modification_reason', ''),
            strategy='START',
            cookie_used=False
        )

        result = self._execute_platform_download_with_retries(
            downloader=downloader,
            url_info=url_result,
            timestamp=timestamp,
            log_callback=self.log_handler.log,
        )
        
        if result['success']:
            # 
            has_audio = result.get('has_audio', True)
            
            # 
            self.log_handler.log_download_result(
                platform=url_result['platform'],
                download_type="video",
                success=True,
                strategy=result.get('strategy_used', 'UNKNOWN'),
                cookie_used=result.get('cookie_used', False),
                deno_used=result.get('deno_used', False)
            )
            
            with self.queue_lock:
                self.completed_tasks += 1
                self.task_progress[url] = 100.0
                
                timestamp = datetime.now().strftime("%H:%M:%S")
                title = self.current_video_title if self.current_video_title != "等待下载..." else ""
                
                # ?
                if title and os.path.sep in title:
                    # 
                    filename = os.path.basename(title)
                    if '-' in filename:
                        parts = filename.rsplit('-', 1)
                        video_title = parts[0]
                        # ?
                        video_title = re.sub(r'\.f\d+$', '', video_title)
                    else:
                        video_title = os.path.splitext(filename)[0]
                else:
                    video_title = title
                
                # 
                try:
                    # 下载成功后，记录最新生成的文件信息
                    if os.path.exists(save_path):
                        files = []
                        for f in os.listdir(save_path):
                            if f.endswith(('.mp4', '.webm', '.mkv')):
                                file_path = os.path.join(save_path, f)
                                mtime = os.path.getmtime(file_path)
                                files.append((mtime, file_path))
                        
                        if files:
                            # 取最后生成的文件
                            files.sort(key=lambda x: x[0], reverse=True)
                            latest_file = files[0][1]
                            file_size = os.path.getsize(latest_file)
                            file_size_mb = file_size / (1024 * 1024)
                            
                            self.log_handler.log(f"[{timestamp}] 文件名: {os.path.basename(latest_file)}")
                            self.log_handler.log(f"[{timestamp}] 保存路径: {latest_file}")
                            self.log_handler.log(f"[{timestamp}] 文件大小: {file_size_mb:.2f} MB")
                        else:
                            fallback_title = video_title or "下载完成"
                            self.log_handler.log(f"[{timestamp}] 下载完成: {fallback_title}")
                except Exception as e:
                    fallback_title = video_title or "下载完成"
                    self.log_handler.log(f"[{timestamp}] 下载完成: {fallback_title}")
                
                if url == self.current_display_url:
                    self.signals.current_progress_signal.emit({'type': 'reset', 'data': {}})
            
            if self.update_task_stats_callback:
                self.update_task_stats_callback()
            self.task_pacer.record_result(url_result['platform'], True)
            return {"success": True, "platform": url_result['platform'], "error_source": None}
        else:
            # 
            self.log_handler.log_download_result(
                platform=url_result['platform'],
                download_type="video",
                success=False,
                strategy=result.get('strategy_used', 'UNKNOWN'),
                cookie_used=result.get('cookie_used', False),
                error_source=result.get('error_source', 'UNKNOWN'),
                deno_used=result.get('deno_used', False)
            )
            
            self.log_handler.log(f"[{timestamp}] 下载失败")
            if result.get("error_source"):
                strategy_text = get_strategy_label(result.get("strategy_used", "UNKNOWN"))
                error_text = get_error_message(result.get("error_source", "UNKNOWN"), url_result['platform'])
                if result.get("deno_used"):
                    self.log_handler.log(f"[{timestamp}] 本次失败发生在：{strategy_text}（Deno 兜底）")
                else:
                    self.log_handler.log(f"[{timestamp}] 本次失败发生在：{strategy_text}")
                self.log_handler.log(f"[{timestamp}] 失败原因：{error_text}")
            elif result.get("message"):
                self.log_handler.log(f"[{timestamp}] {result['message']}")
            if result.get("output_text"):
                self.log_handler.log(result["output_text"][:400])
            
            with self.queue_lock:
                self.failed_tasks += 1
                self.task_progress[url] = 0.0
            
            if self.update_task_stats_callback:
                self.update_task_stats_callback()
            error_source = result.get('error_source', 'UNKNOWN')
            self.task_pacer.record_result(url_result['platform'], False, error_source)
            return {"success": False, "platform": url_result['platform'], "error_source": error_source}
    
    def download_single(self, url, save_path, log_callback):
        """Download a single URL using the normalized strategy pipeline."""
        try:
            timestamp = datetime.now().strftime("%H:%M:%S")

            platform_name = identify_platform(url)
            if platform_name == "Unknown":
                log_callback(f"[{timestamp}] 不支持的URL: {url}")
                return False

            runtime = DownloadRuntime(
                yt_dlp_path=self.yt_dlp_path,
                ffmpeg_path=self.ffmpeg_path,
            save_path=save_path,
            deno_path=self.deno_path,
            deno_timeout=int(self.config.get("deno_timeout", 12)),
            enable_deno=self.enable_deno,
                cookie_file=self.cookie_file,
                instagram_cookie_file=self.instagram_cookie_file,
                tiktok_cookie_file=self.tiktok_cookie_file,
                twitter_cookie_file=self.twitter_cookie_file,
                cookie_status=self.cookie_status,
                youtube_visitor_data=self.config.get("youtube_visitor_data", ""),
                youtube_po_token=self.config.get("youtube_po_token", ""),
                youtube_po_token_context=self.config.get("youtube_po_token_context", "web.gvs"),
                youtube_advanced_extractor_args=self.config.get("youtube_advanced_extractor_args", ""),
                youtube_advanced_auth_enabled=bool(self.config.get("youtube_advanced_auth_enabled", True)),
                youtube_format_fallback=bool(self.config.get("youtube_format_fallback", self.config.get("format_fallback", True))),
                youtube_user_agent=self.config.get("youtube_user_agent", self.config.get("user_agent", "")),
                youtube_use_browser_user_agent=bool(self.config.get("youtube_use_browser_user_agent", True)),
                speed_callback=None,
                progress_callback=None,
            )
            downloader = create_downloader(platform_name, runtime)
            if downloader is None:
                log_callback(f"[{timestamp}] 平台暂不支持: {platform_name}")
                return False

            url_result = downloader.normalize_url(url)
            if not url_result['success']:
                log_callback(f"[{timestamp}] URL 解析失败: {url_result['message']}")
                return False

            log_callback(f"[{timestamp}] {url_result['message']}")

            result = self._execute_platform_download_with_retries(
                downloader=downloader,
                url_info=url_result,
                timestamp=timestamp,
                log_callback=log_callback,
            )
            
            if result['success']:
                has_audio = result.get('has_audio', True)
                success_text = get_success_message(
                    url_result['platform'],
                    cookie_used=result.get('cookie_used', False),
                    deno_used=result.get('deno_used', False),
                )
                if not has_audio:
                    log_callback(f"[{timestamp}] {success_text}")
                    log_callback(f"[{timestamp}] 注意：视频已下载，但音频可能不完整")
                else:
                    log_callback(f"[{timestamp}] {success_text}")
                
                # 
                output_text = result.get('output_text', '')
                title_match = re.search(r'Destination:\s+(.+?)\.', output_text)
                if title_match:
                    title = title_match.group(1)
                    log_callback(f"[{timestamp}] : {title}")
                
                return True
            else:
                log_callback(f"[{timestamp}] 下载失败")
                if result.get("error_source"):
                    strategy_text = get_strategy_label(result.get("strategy_used", "UNKNOWN"))
                    error_text = get_error_message(result.get("error_source", "UNKNOWN"), url_result['platform'])
                    if result.get("deno_used"):
                        log_callback(f"[{timestamp}] 本次失败发生在：{strategy_text}（Deno 兜底）")
                    else:
                        log_callback(f"[{timestamp}] 本次失败发生在：{strategy_text}")
                    log_callback(f"[{timestamp}] 失败原因：{error_text}")
                elif result.get("message"):
                    log_callback(f"[{timestamp}] {result['message']}")
                if result.get("output_text"):
                    log_callback(result["output_text"][:400])
                return False
                
        except Exception as e:
            log_callback(f"[{datetime.now().strftime('%H:%M:%S')}] 下载器异常: {str(e)}")
            return False

