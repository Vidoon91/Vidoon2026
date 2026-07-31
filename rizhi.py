# -*- coding: utf-8 -*-
"""
rizhi.py - 日志功能模块
统一处理文件日志和 GUI 日志显示。
"""

import os
import logging
import tempfile
from datetime import datetime

from PySide6.QtCore import QObject, Signal
from PySide6.QtWidgets import QMessageBox, QFileDialog, QApplication

from core.download_utils import get_error_message, get_strategy_label, get_success_message


class LogHandler(QObject):
    """统一日志处理器。"""

    log_signal = Signal(str)

    def __init__(self, base_dir, log_signal_callback=None):
        super().__init__()
        self.base_dir = os.path.abspath(base_dir)
        self.log_dir = os.path.join(self.base_dir, "logs")
        self.log_file = None
        self.file_logging_error = None

        if log_signal_callback:
            self.log_signal.connect(log_signal_callback)

        self.setup_file_logging()

    def _log_dir_candidates(self):
        candidates = [
            os.path.join(self.base_dir, "logs"),
            os.path.join(os.getcwd(), "logs"),
        ]

        appdata_dir = os.environ.get("LOCALAPPDATA") or os.environ.get("APPDATA")
        if appdata_dir:
            candidates.append(os.path.join(appdata_dir, "Vidoon2026", "logs"))

        candidates.append(os.path.join(tempfile.gettempdir(), "Vidoon2026", "logs"))

        unique_candidates = []
        seen = set()
        for path in candidates:
            normalized = os.path.abspath(path)
            key = os.path.normcase(normalized)
            if key not in seen:
                seen.add(key)
                unique_candidates.append(normalized)
        return unique_candidates

    def _open_file_handler(self, log_dir, log_format):
        os.makedirs(log_dir, exist_ok=True)

        date_text = datetime.now().strftime("%Y%m%d")
        log_file = os.path.join(log_dir, f"app_{date_text}.log")
        try:
            handler = logging.FileHandler(log_file, encoding="utf-8")
        except PermissionError:
            timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
            log_file = os.path.join(log_dir, f"app_{timestamp}_{os.getpid()}.log")
            handler = logging.FileHandler(log_file, encoding="utf-8")

        handler.setLevel(logging.INFO)
        handler.setFormatter(logging.Formatter(log_format))
        return handler, log_file

    def setup_file_logging(self):
        """配置文件和控制台日志。"""
        log_format = "%(asctime)s - %(levelname)s - [%(platform)s] - [MODE:%(mode)s] - [STRATEGY:%(strategy)s] - [COOKIE:%(cookie)s] - %(message)s"

        self.logger = logging.getLogger("VidoonLogger")
        self.logger.setLevel(logging.INFO)

        if self.logger.handlers:
            for handler in self.logger.handlers:
                handler.close()
            self.logger.handlers.clear()

        console_handler = logging.StreamHandler()
        console_handler.setLevel(logging.INFO)
        console_handler.setFormatter(logging.Formatter("%(asctime)s - %(message)s"))

        file_handler = None
        errors = []
        for log_dir in self._log_dir_candidates():
            try:
                file_handler, log_file = self._open_file_handler(log_dir, log_format)
                self.log_dir = log_dir
                self.log_file = log_file
                break
            except OSError as exc:
                errors.append(f"{log_dir}: {exc}")

        if file_handler:
            self.logger.addHandler(file_handler)
        else:
            self.file_logging_error = " | ".join(errors)

        self.logger.addHandler(console_handler)
        self.logger.propagate = False

        if self.file_logging_error:
            self.logger.warning(
                f"File logging disabled: {self.file_logging_error}",
                extra={"platform": "SYSTEM", "mode": "MATERIAL", "strategy": "NONE", "cookie": "NO"},
            )
            self.log_signal.emit(f"日志文件无法写入，已仅启用控制台日志: {self.file_logging_error}")
        elif os.path.normcase(os.path.abspath(self.log_dir)) != os.path.normcase(os.path.join(self.base_dir, "logs")):
            self.logger.warning(
                f"Root log directory is not writable, using fallback log file: {self.log_file}",
                extra={"platform": "SYSTEM", "mode": "MATERIAL", "strategy": "NONE", "cookie": "NO"},
            )
            self.log_signal.emit(f"根目录日志不可写，已改用: {self.log_file}")

    def log_download_start(self, url, platform, download_type, url_modified, modification_reason, strategy, cookie_used):
        """记录下载开始日志。"""
        mode_text = {"video": "MATERIAL", "audio": "AUDIO"}.get(download_type, "MATERIAL")
        extra = {
            "platform": platform,
            "mode": mode_text,
            "strategy": strategy,
            "cookie": "YES" if cookie_used else "NO",
        }

        message = f"DOWNLOAD_START | MODE:{mode_text} | URL:{url} | MODIFIED:{url_modified} | REASON:{modification_reason}"
        self.logger.info(message, extra=extra)

        mode_icon = {"video": "🎥", "audio": "🎵"}.get(download_type, "🎥")
        strategy_text = get_strategy_label(strategy)
        display_msg = f"开始下载 [{platform}] {mode_icon} | {strategy_text}"

        if url_modified:
            display_msg += f" | 链接已规范化：{modification_reason}"
        if cookie_used:
            display_msg += " | 已带 Cookie"

        self.log_signal.emit(display_msg)

    def log_download_result(
        self,
        platform,
        download_type,
        success,
        strategy,
        cookie_used,
        error_source=None,
        deno_used=False,
        selected_format=None,
    ):
        """记录下载结果日志。"""
        mode_text = {"video": "MATERIAL", "audio": "AUDIO"}.get(download_type, "MATERIAL")
        extra = {
            "platform": platform,
            "mode": mode_text,
            "strategy": strategy,
            "cookie": "YES" if cookie_used else "NO",
        }

        mode_icon = {"video": "🎥", "audio": "🎵"}.get(download_type, "🎥")
        strategy_text = get_strategy_label(strategy)

        if success:
            message = f"DOWNLOAD_SUCCESS | MODE:{mode_text} | STRATEGY:{strategy}"
            self.logger.info(message, extra=extra)
            success_text = get_success_message(
                platform,
                cookie_used=cookie_used,
                deno_used=deno_used,
                selected_format=selected_format,
            )
            self.log_signal.emit(f"{success_text} | {strategy_text}")
            return

        message = f"DOWNLOAD_FAILED | MODE:{mode_text} | STRATEGY:{strategy} | ERROR:{error_source}"
        self.logger.warning(message, extra=extra)
        error_text = get_error_message(error_source, platform)
        deno_text = "，这一步已经是 Deno 兜底下载" if deno_used else ""
        self.log_signal.emit(f"下载失败 [{platform}] {mode_icon} | {strategy_text} | {error_text}{deno_text}")

    def log(self, message, level=logging.INFO, platform="SYSTEM", mode="MATERIAL", strategy="NONE", cookie_used=False):
        """通用日志记录。"""
        extra = {
            "platform": platform,
            "mode": mode,
            "strategy": strategy,
            "cookie": "YES" if cookie_used else "NO",
        }

        if level == logging.INFO:
            self.logger.info(message, extra=extra)
        elif level == logging.WARNING:
            self.logger.warning(message, extra=extra)
        elif level == logging.ERROR:
            self.logger.error(message, extra=extra)
        elif level == logging.DEBUG:
            self.logger.debug(message, extra=extra)

        self.log_signal.emit(message)

    def get_log_stats(self, log_box):
        """获取日志行数。"""
        try:
            if log_box:
                return len(log_box.toPlainText().split("\n"))
        except Exception:
            pass
        return 0

    def clear_run_log(self, log_box, log_info_label, log_time_label):
        """清空运行日志。"""
        if log_box:
            log_box.clear()
            self.log_signal.emit("运行日志已清空")
            self._update_log_info(log_info_label, log_time_label)

    def copy_run_log(self, log_box):
        """复制运行日志到剪贴板。"""
        if not log_box:
            return

        log_text = log_box.toPlainText()
        if log_text.strip():
            QApplication.clipboard().setText(log_text)
            self.log_signal.emit("运行日志已复制到剪贴板")
            QMessageBox.information(None, "复制成功", "运行日志已复制到剪贴板")
        else:
            self.log_signal.emit("运行日志为空，无法复制")

    def save_run_log(self, log_box, parent_window):
        """保存运行日志到文件。"""
        if not log_box:
            return

        log_text = log_box.toPlainText()
        if not log_text.strip():
            self.log_signal.emit("运行日志为空，无法保存")
            QMessageBox.warning(parent_window, "保存失败", "运行日志为空，无法保存")
            return

        file_path, _ = QFileDialog.getSaveFileName(
            parent_window,
            "保存运行日志",
            os.path.expanduser(f"~/Desktop/运行日志_{datetime.now().strftime('%Y%m%d_%H%M%S')}.txt"),
            "Text Files (*.txt);;All Files (*.*)",
        )

        if not file_path:
            return

        try:
            with open(file_path, "w", encoding="utf-8") as f:
                f.write("=== Vidoon 运行日志 ===\n")
                f.write(f"导出时间: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
                f.write("软件版本: 视频素材工具\n")
                f.write("=" * 50 + "\n\n")
                f.write(log_text)

            self.log_signal.emit(f"运行日志已保存到: {file_path}")
            QMessageBox.information(parent_window, "保存成功", f"运行日志已保存到:\n{file_path}")
        except Exception as e:
            self.log_signal.emit(f"保存运行日志失败: {e}")
            QMessageBox.warning(parent_window, "保存失败", f"保存运行日志失败: {e}")

    def _update_log_info(self, log_info_label, log_time_label):
        """更新运行日志信息。"""
        try:
            if log_info_label and log_time_label:
                log_info_label.setText("📳 日志信息: 0 条记录")
                log_time_label.setText(f"📮 当前时间: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
        except Exception:
            pass
