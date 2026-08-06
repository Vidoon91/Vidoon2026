# -*- coding: utf-8 -*-
"""Remote update checking and ZIP-based self update."""

import hashlib
import json
import os
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import urllib.request
import urllib.error
import webbrowser
from datetime import datetime
from urllib.parse import urlparse

from PySide6.QtCore import QObject, Signal
from PySide6.QtWidgets import QApplication, QMessageBox, QProgressDialog

from app_config import get_app_value, get_app_version


if getattr(sys, "frozen", False):
    BASE_DIR = os.path.dirname(sys.executable)
else:
    BASE_DIR = os.path.abspath(os.path.dirname(__file__))


APP_NAME = "Vidoon2026"
EXE_NAME = "Vidoon2026.exe"
UPDATER_DIR = os.path.join(BASE_DIR, "_update")
PRESERVE_NAMES = {
    "app_settings.json",
    "auth_cache.json",
    "batch_extract_data.json",
    "config.json",
    "cookies.txt",
    "instagram_cookies.txt",
    "account_session.json",
    "proxy.json",
    "proxy_config.json",
    "tiktok_cookies.txt",
    "Vidoon2026.lock",
}
PRESERVE_DIRS = {"logs", "cache"}


def _parse_version(version):
    parts = []
    for chunk in str(version or "").replace("-", ".").replace("_", ".").split("."):
        number = ""
        for char in chunk:
            if char.isdigit():
                number += char
            else:
                break
        parts.append(int(number or 0))
    while len(parts) < 4:
        parts.append(0)
    return tuple(parts[:4])


def is_newer_version(remote_version, local_version=None):
    local_version = local_version or get_app_version()
    return _parse_version(remote_version) > _parse_version(local_version)


def _download_file(url, target_path, progress_callback=None):
    request = urllib.request.Request(url, headers={"User-Agent": f"{APP_NAME}/{get_app_version()}"})
    with urllib.request.urlopen(request, timeout=20) as response:
        total = int(response.headers.get("Content-Length") or 0)
        downloaded = 0
        with open(target_path, "wb") as file:
            while True:
                chunk = response.read(1024 * 256)
                if not chunk:
                    break
                file.write(chunk)
                downloaded += len(chunk)
                if progress_callback and total > 0:
                    progress_callback(downloaded, total)


def _sha256_file(path):
    digest = hashlib.sha256()
    with open(path, "rb") as file:
        for chunk in iter(lambda: file.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest().lower()


def _browser_download_url(url):
    """Return a browser-openable direct download URL when GitHub page URLs are supplied."""
    url = str(url or "").strip()
    if not url:
        return ""

    parsed = urlparse(url)
    if parsed.netloc.lower() != "github.com":
        return url

    parts = [part for part in parsed.path.split("/") if part]
    if len(parts) >= 5 and parts[2] == "blob":
        owner, repo, branch = parts[0], parts[1], parts[3]
        file_path = "/".join(parts[4:])
        return f"https://raw.githubusercontent.com/{owner}/{repo}/{branch}/{file_path}"

    return url


def _write_update_script(zip_path):
    script_path = os.path.join(UPDATER_DIR, "apply_update.ps1")
    app_dir = BASE_DIR
    exe_path = os.path.join(BASE_DIR, EXE_NAME)
    preserve_names = ",".join(f"'{name}'" for name in sorted(PRESERVE_NAMES))
    preserve_dirs = ",".join(f"'{name}'" for name in sorted(PRESERVE_DIRS))

    script = f"""
$ErrorActionPreference = 'Stop'
$pidToWait = {os.getpid()}
$zipPath = {json.dumps(zip_path)}
$appDir = {json.dumps(app_dir)}
$exePath = {json.dumps(exe_path)}
$extractDir = Join-Path $env:TEMP ('{APP_NAME}_update_' + [Guid]::NewGuid().ToString('N'))
$preserveNames = @({preserve_names})
$preserveDirs = @({preserve_dirs})

function Invoke-WithRetry {{
    param(
        [scriptblock]$Action,
        [string]$Label
    )
    $lastError = $null
    for ($i = 1; $i -le 12; $i++) {{
        try {{
            & $Action
            return
        }} catch {{
            $lastError = $_
            Start-Sleep -Milliseconds 500
        }}
    }}
    throw "$Label failed: $($lastError.Exception.Message)"
}}

try {{
    for ($i = 1; $i -le 120; $i++) {{
        $process = Get-Process -Id $pidToWait -ErrorAction SilentlyContinue
        if ($null -eq $process) {{ break }}
        Start-Sleep -Milliseconds 500
    }}

    $process = Get-Process -Id $pidToWait -ErrorAction SilentlyContinue
    if ($null -ne $process) {{
        Stop-Process -Id $pidToWait -Force -ErrorAction SilentlyContinue
        Start-Sleep -Milliseconds 1200
    }}

    New-Item -ItemType Directory -Force -Path $extractDir | Out-Null
    Expand-Archive -LiteralPath $zipPath -DestinationPath $extractDir -Force

    $candidate = Join-Path $extractDir '{APP_NAME}'
    if (Test-Path -LiteralPath $candidate) {{
        $sourceDir = $candidate
    }} else {{
        $dirs = Get-ChildItem -LiteralPath $extractDir -Directory
        if ($dirs.Count -eq 1 -and (Test-Path -LiteralPath (Join-Path $dirs[0].FullName '{EXE_NAME}'))) {{
            $sourceDir = $dirs[0].FullName
        }} else {{
            $sourceDir = $extractDir
        }}
    }}

    Get-ChildItem -LiteralPath $sourceDir -Force | ForEach-Object {{
        if ($preserveNames -contains $_.Name) {{ return }}
        if ($_.PSIsContainer -and ($preserveDirs -contains $_.Name)) {{ return }}
        $target = Join-Path $appDir $_.Name
        if (Test-Path -LiteralPath $target) {{
            Invoke-WithRetry -Label "Remove $target" -Action {{
                Remove-Item -LiteralPath $target -Recurse -Force
            }}
        }}
        Invoke-WithRetry -Label "Copy $target" -Action {{
            Copy-Item -LiteralPath $_.FullName -Destination $target -Recurse -Force
        }}
    }}

    Start-Process -FilePath $exePath -WorkingDirectory $appDir
}} catch {{
    Add-Type -AssemblyName PresentationFramework
    [System.Windows.MessageBox]::Show("自动更新失败：$($_.Exception.Message)", "更新失败") | Out-Null
}} finally {{
    Remove-Item -LiteralPath $extractDir -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $zipPath -Force -ErrorAction SilentlyContinue
}}
"""

    os.makedirs(UPDATER_DIR, exist_ok=True)
    with open(script_path, "w", encoding="utf-8-sig") as file:
        file.write(script)
    return script_path


class AutoUpdateManager(QObject):
    update_available = Signal(dict, bool)
    check_failed = Signal(str, bool)
    download_progress = Signal(int)
    download_finished = Signal(str, dict)
    download_failed = Signal(str)

    def __init__(self, parent=None, log_handler=None):
        super().__init__(parent)
        self.parent = parent
        self.log_handler = log_handler
        self.progress_dialog = None
        self._checking = False
        self._current_check_silent = True
        self._downloading = False

        self.update_available.connect(self._handle_update_available)
        self.check_failed.connect(self._handle_check_failed)
        self.download_progress.connect(self._handle_download_progress)
        self.download_finished.connect(self._handle_download_finished)
        self.download_failed.connect(self._handle_download_failed)

    def _log(self, message):
        if self.log_handler:
            try:
                self.log_handler.log(message)
            except Exception:
                pass

    def is_enabled(self):
        return bool(get_app_value("client.update.enabled", True))

    def version_url(self):
        return get_app_value("client.update.version_url", "")

    def download_page_url(self):
        return get_app_value(
            "client.update.download_page_url",
            "https://license.muyanshidai.com/index.php",
        )

    def check_for_updates(self, silent=False):
        if not self.is_enabled():
            return
        if self._checking:
            # A manual click should promote the startup background check so its
            # result is shown instead of silently discarding the user's action.
            if not silent:
                self._current_check_silent = False
            return

        url = self.version_url()
        if not url:
            if not silent:
                QMessageBox.information(self.parent, "检查更新", "未配置远程更新地址。")
            return

        self._checking = True
        self._current_check_silent = silent

        def worker():
            try:
                separator = "&" if "?" in url else "?"
                request_url = f"{url}{separator}_={time.time_ns()}"
                request = urllib.request.Request(
                    request_url,
                    headers={
                        "User-Agent": f"{APP_NAME}/{get_app_version()}",
                        "Cache-Control": "no-cache",
                        "Pragma": "no-cache",
                    },
                )
                with urllib.request.urlopen(request, timeout=10) as response:
                    data = json.loads(response.read().decode("utf-8-sig"))

                remote_version = data.get("version", "")
                effective_silent = self._current_check_silent
                if remote_version and is_newer_version(remote_version):
                    self.update_available.emit(data, effective_silent)
                elif not effective_silent:
                    self.check_failed.emit("当前已是最新版本。", False)
            except Exception as exc:
                if isinstance(exc, urllib.error.HTTPError):
                    if exc.code == 404:
                        message = "官网版本接口不存在，请检查服务器 version.php 是否已上传。"
                    else:
                        message = f"官网版本服务返回错误（HTTP {exc.code}），请稍后重试。"
                elif isinstance(exc, urllib.error.URLError):
                    message = "无法连接官网版本服务，请检查网络后重试。"
                elif isinstance(exc, (json.JSONDecodeError, UnicodeDecodeError)):
                    message = "官网版本信息格式不正确，请检查后台版本设置。"
                else:
                    message = f"检查更新时发生异常：{exc}"
                self.check_failed.emit(message, self._current_check_silent)
            finally:
                self._checking = False

        threading.Thread(target=worker, daemon=True).start()

    def _handle_update_available(self, data, silent):
        remote_version = data.get("version", "")
        notes = data.get("notes", "")
        message = f"发现新版本 {remote_version}，当前版本 {get_app_version()}。"
        if notes:
            message += f"\n\n更新内容：\n{notes}"
        message += "\n\n软件不会自动更新覆盖。请点击“打开官网下载”，在官网选择下载方式。下载完成解压后，直接使用新版并删除旧版。"

        box = QMessageBox(self.parent)
        box.setWindowTitle("发现新版本")
        box.setIcon(QMessageBox.Information)
        box.setText(message)
        open_button = box.addButton("打开官网下载", QMessageBox.AcceptRole)
        box.addButton("取消", QMessageBox.RejectRole)
        box.exec()

        if box.clickedButton() == open_button:
            download_page_url = (
                str(data.get("download_page_url", "") or "").strip()
                or self.download_page_url()
            )
            webbrowser.open(download_page_url)
            self._log(f"已打开官网软件下载页：{download_page_url}")

    def _handle_check_failed(self, message, silent):
        if silent:
            self._log(f"检查更新跳过：{message}")
            return
        if "当前已是最新版本" in message:
            QMessageBox.information(self.parent, "检查更新", message)
        else:
            QMessageBox.warning(self.parent, "检查更新失败", message)

    def download_and_apply(self, data):
        if self._downloading:
            return

        package_url = data.get("url", "")
        if not package_url:
            QMessageBox.warning(self.parent, "更新失败", "更新包地址为空。")
            return

        os.makedirs(UPDATER_DIR, exist_ok=True)
        zip_path = os.path.join(
            UPDATER_DIR,
            f"{APP_NAME}_{data.get('version', datetime.now().strftime('%Y%m%d%H%M%S'))}.zip",
        )

        self.progress_dialog = QProgressDialog("正在下载更新...", "取消", 0, 100, self.parent)
        self.progress_dialog.setWindowTitle("软件更新")
        self.progress_dialog.setMinimumDuration(0)
        self.progress_dialog.setValue(0)
        self.progress_dialog.setAutoClose(False)
        self.progress_dialog.setAutoReset(False)
        self._downloading = True

        def progress(downloaded, total):
            self.download_progress.emit(int(downloaded * 100 / total))

        def worker():
            try:
                _download_file(package_url, zip_path, progress)
                expected_sha256 = str(data.get("sha256", "")).strip().lower()
                if expected_sha256 and _sha256_file(zip_path) != expected_sha256:
                    raise RuntimeError("更新包校验失败，请重新上传或检查 sha256。")
                self.download_finished.emit(zip_path, data)
            except Exception as exc:
                try:
                    if os.path.exists(zip_path):
                        os.remove(zip_path)
                except Exception:
                    pass
                self.download_failed.emit(str(exc))
            finally:
                self._downloading = False

        threading.Thread(target=worker, daemon=True).start()

    def _handle_download_progress(self, value):
        if self.progress_dialog:
            self.progress_dialog.setValue(max(0, min(100, value)))
            QApplication.processEvents()

    def _handle_download_finished(self, zip_path, data):
        if self.progress_dialog:
            self.progress_dialog.setValue(100)
            self.progress_dialog.close()
            self.progress_dialog = None

        script_path = _write_update_script(zip_path)
        QMessageBox.information(self.parent, "准备更新", "更新包已下载完成，软件将关闭并自动安装新版。")
        subprocess.Popen(
            [
                "powershell",
                "-NoProfile",
                "-ExecutionPolicy",
                "Bypass",
                "-File",
                script_path,
            ],
            cwd=BASE_DIR,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
        )
        QApplication.quit()

    def _handle_download_failed(self, message):
        if self.progress_dialog:
            self.progress_dialog.close()
            self.progress_dialog = None
        QMessageBox.warning(self.parent, "更新失败", message)
