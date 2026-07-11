# -*- coding: utf-8 -*-
"""
批量提取功能模块 - 从主程序拆分
包含批量提取页面UI、批量提取设置对话框和相关逻辑
优化版：移除音频下载功能，专注视频下载
优化版：删除【时长】字段，增加【下载】、【状态】、【结果】字段，支持点击下载功能
修复版本：解决日志重复输出问题和信号调用问题
迭代优化版本：
1. 保存结果时只保存URL链接，不保存标题
2. 实现持久化存储：当不点击清空的时候，下次打开软件，还显示上次提取的结果
3. 增加【状态】和【结果】字段，显示下载进度和结果
迭代优化版本2：
1. 批量提取界面，提取的视频链接列表增：点击下载后，下载过的记录也要保存，下次启动软件打开后，要知道哪些已经下载过了
2. 清空结果后面增加打开下载文件按钮，直接打开设置配置的视频保存路径文件夹
迭代优化版本3：
1. 保存结果：去掉Excel格式，只保存TXT格式，并在保存时自动过滤已下载过的链接
2. 提取的链接列表链接和标题字段不需要显示全部，和下载、状态、结果这些字段平均宽度，根据需要手动拖动调整宽度
3. 提取的链接和标题能够用鼠标复制
优化版：去掉画质选择功能，默认下载最佳画质
迭代升级：优化表格显示效果
1. 下载字段下的列表要居中，不要左对齐
2. 链接字段、标题字段自动折叠一部分减少列的宽度
3. 优化整体UI布局和显示效果
迭代优化：增加进度字段
1. 在状态和结果字段中间增加进度字段，显示一个进度条
迭代优化：模拟下载进度
1. 【进度】字段实现模拟下载进度，通过独立的线程模拟进度更新
2. 模拟进度逻辑：快速上升到30%（准备阶段）、缓慢上升到80%（下载阶段）、保持80%直到下载完成、下载完成后跳到100%
迭代优化：完整显示链接
1. 链接字段显示完整URL，但通过设置单元格文本换行来适应列宽
2. 用户可以通过双击单元格或右键菜单复制完整链接
3. 优化复制功能，确保可以复制到完整的链接
优化版：统一使用主窗口的log_handler
迭代优化：移除Cookie状态检查功能
更新Cookie状态显示方法，从主窗口获取状态
简化日志输出
Cookie和Deno只在启动时检查一次，不重复检查
迭代优化：持久化文件保存日志只在第一次保存时输出一次
"""

import os
import re
import json
import platform
import subprocess
import threading
import time
from datetime import datetime
from urllib.parse import urlparse, urlunparse

from PySide6.QtWidgets import (
    QWidget, QVBoxLayout, QHBoxLayout, QLabel, QPushButton, 
    QTextEdit, QTableWidget, QTableWidgetItem, QHeaderView,
    QDialog, QDialogButtonBox, QGroupBox, QFormLayout, QMessageBox,
    QFileDialog, QComboBox, QProgressBar, QSpacerItem, QSizePolicy,
    QLineEdit, QFrame, QSpinBox, QCheckBox, QStyleOption, QStyle,
    QApplication, QMenu
)
from PySide6.QtCore import Qt, QTimer, Signal, QObject, QThread, QSize
from PySide6.QtGui import QGuiApplication, QClipboard, QFont, QBrush, QColor, QPainter, QAction


# ------------------- 批量提取设置对话框 -------------------
class BatchExtractSettingsDialog(QDialog):
    """批量提取设置对话框"""
    
    def __init__(self, parent=None):
        super().__init__(parent)
        self.setWindowTitle("批量提取设置")
        self.resize(500, 200)
        self.init_ui()
        
    def init_ui(self):
        """初始化UI"""
        layout = QVBoxLayout(self)
        
        format_group = QGroupBox("提取设置")
        format_layout = QFormLayout()
        
        # 字段选择
        fields_layout = QHBoxLayout()
        self.checkbox_url = QCheckBox("链接 (url)")
        self.checkbox_url.setChecked(True)
        self.checkbox_url.setEnabled(False)  # 链接必须选中
        
        self.checkbox_title = QCheckBox("标题 (title)")
        self.checkbox_title.setChecked(True)
        
        fields_layout.addWidget(self.checkbox_url)
        fields_layout.addWidget(self.checkbox_title)
        
        format_layout.addRow("提取字段:", fields_layout)
        
        # 分隔符
        self.separator_input = QLineEdit("|")
        self.separator_input.setFixedWidth(50)
        format_layout.addRow("字段分隔符:", self.separator_input)
        
        # 提取模式选项
        self.extract_mode_combo = QComboBox()
        self.extract_mode_combo.addItems(["快速模式（仅基本信息）", "完整模式（获取详细信息）"])
        self.extract_mode_combo.setToolTip("完整模式会获取更多信息但速度较慢")
        format_layout.addRow("提取模式:", self.extract_mode_combo)
        
        self.info_label = QLabel("⚠️ 注意：将提取播放列表/频道中的所有链接，并获取标题信息")
        self.info_label.setStyleSheet("color: orange; font-size: 11px;")
        self.info_label.setWordWrap(True)
        format_layout.addRow("说明:", self.info_label)
        
        format_group.setLayout(format_layout)
        layout.addWidget(format_group)
        
        button_box = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
        button_box.accepted.connect(self.accept)
        button_box.rejected.connect(self.reject)
        layout.addWidget(button_box)
        
    def get_settings(self):
        """获取设置"""
        fields = []
        if self.checkbox_url.isChecked():
            fields.append("url")
        if self.checkbox_title.isChecked():
            fields.append("title")
        # 下载、状态、进度、结果字段是固定的，不需要用户选择
        
        return {
            "fields": fields,
            "separator": self.separator_input.text().strip() or "|",
            "extract_mode": self.extract_mode_combo.currentText()
        }


# ------------------- 批量提取信号处理器 -------------------
class BatchExtractSignals(QObject):
    """批量提取信号处理器"""
    progress_signal = Signal(int)
    extract_complete_signal = Signal(list)
    status_signal = Signal(str)
    update_stats_signal = Signal(str, str)
    update_page_info_signal = Signal(str)
    update_download_status_signal = Signal(str, str, str, int)  # 修改：增加进度参数 (url, status, result, progress)


# ------------------- 居中按钮小部件 -------------------
class CenteredButtonWidget(QWidget):
    """居中对齐的按钮小部件"""
    
    def __init__(self, text="", parent=None):
        super().__init__(parent)
        self.button = QPushButton(text)
        self.button.setFixedWidth(60)
        self.button.setFixedHeight(24)
        
        # 确保按钮文本水平居中
        self.button.setStyleSheet("""
            QPushButton {
                font-size: 10px;
                padding: 3px;
                border-radius: 3px;
                text-align: center;
                min-width: 60px;
            }
        """)
        
        layout = QHBoxLayout(self)
        layout.setContentsMargins(0, 0, 0, 0)
        layout.addStretch()
        layout.addWidget(self.button)
        layout.addStretch()
    
    def set_button_style(self, style_sheet):
        """设置按钮样式"""
        base_style = """
            QPushButton {
                font-size: 10px;
                padding: 3px;
                border-radius: 3px;
                border: none;
            }
        """
        self.button.setStyleSheet(base_style + style_sheet)
    
    def set_text(self, text):
        """设置按钮文本"""
        self.button.setText(text)
    
    def set_enabled(self, enabled):
        """设置按钮是否可用"""
        self.button.setEnabled(enabled)
    
    def connect_clicked(self, callback):
        """连接点击事件"""
        self.button.clicked.connect(callback)


# ------------------- 自定义进度条小部件 -------------------
class TableProgressBar(QProgressBar):
    """用于表格的自定义进度条"""
    
    def __init__(self, parent=None):
        super().__init__(parent)
        self.setFixedHeight(18)
        self.setTextVisible(True)
        self.setAlignment(Qt.AlignCenter)
        
        # 设置进度条样式
        self.setStyleSheet("""
            QProgressBar {
                border: 1px solid #CFDDE5;
                border-radius: 3px;
                background-color: #E2E8F0;
                text-align: center;
                font-size: 9px;
                color: #0F172A;
            }
            QProgressBar::chunk {
                background-color: #2E7892;
                border-radius: 2px;
            }
        """)
    
    def set_progress_color(self, color):
        """设置进度条颜色"""
        self.setStyleSheet(f"""
            QProgressBar {{
                border: 1px solid #CFDDE5;
                border-radius: 3px;
                background-color: #E2E8F0;
                text-align: center;
                font-size: 9px;
                color: #0F172A;
            }}
            QProgressBar::chunk {{
                background-color: {color};
                border-radius: 2px;
            }}
        """)


# ------------------- 可复制表格小部件 -------------------
class CopyableTableWidget(QTableWidget):
    """支持复制完整链接和标题的表格小部件"""
    
    def __init__(self, parent=None):
        super().__init__(parent)
        self.setContextMenuPolicy(Qt.CustomContextMenu)
        self.customContextMenuRequested.connect(self.show_context_menu)
        
    def show_context_menu(self, position):
        """显示右键菜单"""
        item = self.itemAt(position)
        if not item:
            return
            
        menu = QMenu(self)
        
        # 添加复制菜单项
        copy_action = QAction("复制", self)
        copy_action.triggered.connect(self.copy_selected_cell)
        menu.addAction(copy_action)
        
        # 添加复制完整链接菜单项（如果是链接列）
        column = self.currentColumn()
        if column == 0:  # 链接列
            copy_url_action = QAction("复制完整链接", self)
            copy_url_action.triggered.connect(self.copy_full_url)
            menu.addAction(copy_url_action)
        elif column == 1:  # 标题列
            copy_title_action = QAction("复制完整标题", self)
            copy_title_action.triggered.connect(self.copy_full_title)
            menu.addAction(copy_title_action)
        
        menu.exec(self.mapToGlobal(position))
    
    def copy_selected_cell(self):
        """复制选中的单元格内容"""
        current_item = self.currentItem()
        if current_item:
            clipboard = QApplication.clipboard()
            clipboard.setText(current_item.text())
    
    def copy_full_url(self):
        """复制完整的URL链接"""
        current_item = self.currentItem()
        if current_item:
            # 尝试从UserRole获取完整URL
            full_url = current_item.data(Qt.UserRole)
            if full_url:
                clipboard = QApplication.clipboard()
                clipboard.setText(full_url)
    
    def copy_full_title(self):
        """复制完整的标题"""
        current_item = self.currentItem()
        if current_item:
            # 尝试从UserRole获取完整标题
            full_title = current_item.data(Qt.UserRole)
            if full_title:
                clipboard = QApplication.clipboard()
                clipboard.setText(full_title)
    
    def keyPressEvent(self, event):
        """处理键盘事件，支持Ctrl+C复制"""
        if event.key() == Qt.Key_C and event.modifiers() == Qt.ControlModifier:
            self.copy_selected_cell()
        else:
            super().keyPressEvent(event)


# ------------------- 进度模拟器 -------------------
class ProgressSimulator(QThread):
    """进度模拟器线程 - 模拟下载进度"""
    
    progress_update_signal = Signal(str, int)  # 信号：更新进度 (url, progress)
    
    def __init__(self, url, parent=None):
        super().__init__(parent)
        self.url = url
        self.is_running = True
        self.current_progress = 0
        self.simulation_stage = 0  # 0:准备阶段, 1:下载阶段, 2:保持阶段, 3:完成阶段
    
    def stop_simulation(self):
        """停止模拟"""
        self.is_running = False
    
    def set_completed(self):
        """设置为完成状态"""
        self.current_progress = 100
        self.progress_update_signal.emit(self.url, 100)
    
    def set_failed(self):
        """设置为失败状态"""
        self.current_progress = 0
        self.progress_update_signal.emit(self.url, 0)
    
    def run(self):
        """运行进度模拟"""
        try:
            # 阶段1: 快速上升到30%（准备阶段）- 大约3秒
            stage1_start = time.time()
            stage1_duration = 3.0  # 3秒
            
            while self.is_running and self.current_progress < 30:
                elapsed = time.time() - stage1_start
                progress = min(30, int(elapsed / stage1_duration * 30))
                
                if progress > self.current_progress:
                    self.current_progress = progress
                    self.progress_update_signal.emit(self.url, progress)
                
                time.sleep(0.1)  # 每100毫秒检查一次
            
            if not self.is_running:
                return
            
            # 阶段2: 缓慢上升到80%（下载阶段）- 大约15秒
            stage2_start = time.time()
            stage2_duration = 15.0  # 15秒
            
            while self.is_running and self.current_progress < 80:
                elapsed = time.time() - stage2_start
                progress = min(80, 30 + int(elapsed / stage2_duration * 50))
                
                if progress > self.current_progress:
                    self.current_progress = progress
                    self.progress_update_signal.emit(self.url, progress)
                
                time.sleep(0.3)  # 每300毫秒检查一次
            
            if not self.is_running:
                return
            
            # 阶段3: 保持80%直到外部通知完成
            while self.is_running and self.current_progress == 80:
                time.sleep(0.5)  # 每500毫秒检查一次
            
        except Exception as e:
            print(f"进度模拟异常: {e}")


# ------------------- 单个下载线程 -------------------
class SingleDownloadThread(QThread):
    """单个下载线程 - 优化版：固定为视频下载，带模拟进度"""
    
    download_complete_signal = Signal(str, bool)
    update_download_status_signal = Signal(str, str, str, int)  # 修改：增加进度参数 (url, status, result, progress)
    
    def __init__(self, parent=None, log_handler=None):
        super().__init__(parent)
        self.url = ""
        self.save_path = ""
        self.download_type = "video"  # 固定为视频下载
        self.downloader_core = None
        self.current_status = "等待下载"
        self.current_result = ""
        self.progress = 0
        self.progress_simulator = None
        self.log_handler = log_handler
        
    def set_params(self, url, save_path, downloader_core):
        """设置下载参数"""
        self.url = url
        self.save_path = save_path
        self.downloader_core = downloader_core
        
    def run(self):
        """运行下载线程 - 固定为视频下载，带模拟进度"""
        try:
            timestamp = datetime.now().strftime("%H:%M:%S")
            if self.log_handler:
                self.log_handler.log(f"[{timestamp}] 📥 开始下载: {self.url}")
            
            # 启动进度模拟器
            self.progress_simulator = ProgressSimulator(self.url)
            self.progress_simulator.progress_update_signal.connect(self.on_progress_update)
            self.progress_simulator.start()
            
            # 更新状态为下载中，进度为0
            self.update_download_status_signal.emit(self.url, "下载中", "开始下载", 0)
            
            # 检查下载器核心是否有效
            if self.downloader_core is None:
                if self.log_handler:
                    self.log_handler.log(f"[{timestamp}] ❌ 下载器核心未初始化")
                self.update_download_status_signal.emit(self.url, "失败", "下载器核心未初始化", 0)
                if self.progress_simulator:
                    self.progress_simulator.set_failed()
                    self.progress_simulator.stop_simulation()
                self.download_complete_signal.emit(self.url, False)
                return
            
            # 创建日志回调函数
            log_callback = lambda msg: self.log_handler.log(msg) if self.log_handler else print(msg)
            
            # 使用下载器核心下载单个链接 - 固定为视频下载，默认最佳画质
            success = self.downloader_core.download_single(
                self.url, 
                self.save_path, 
                log_callback
            )
            
            # 停止进度模拟器
            if self.progress_simulator:
                if success:
                    self.progress_simulator.set_completed()
                else:
                    self.progress_simulator.set_failed()
                self.progress_simulator.stop_simulation()
                self.progress_simulator.wait(1000)  # 等待1秒让线程结束
            
            if success:
                self.update_download_status_signal.emit(self.url, "完成", "下载成功", 100)
            else:
                self.update_download_status_signal.emit(self.url, "失败", "下载失败", 0)
            
            self.download_complete_signal.emit(self.url, success)
            
        except Exception as e:
            timestamp = datetime.now().strftime("%H:%M:%S")
            error_msg = str(e)
            if self.log_handler:
                self.log_handler.log(f"[{timestamp}] ❌ 下载异常: {error_msg}")
            
            # 停止进度模拟器
            if self.progress_simulator:
                self.progress_simulator.set_failed()
                self.progress_simulator.stop_simulation()
            
            self.update_download_status_signal.emit(self.url, "失败", f"异常: {error_msg[:30]}", 0)
            self.download_complete_signal.emit(self.url, False)
    
    def on_progress_update(self, url, progress):
        """处理进度更新"""
        if url == self.url:
            self.progress = progress
            status_text = ""
            if progress <= 30:
                status_text = "准备中..."
            elif progress <= 80:
                status_text = "下载中..."
            else:
                status_text = "完成中..."
            
            self.update_download_status_signal.emit(url, "下载中", f"{status_text} {progress}%", progress)


# ------------------- 批量提取页面 -------------------
class BatchExtractPage(QWidget):
    """批量提取页面 - 从主程序拆分，优化版：移除音频下载功能，统一日志处理"""
    
    def __init__(self, parent=None, config=None, yt_dlp_path="", 
                 ffmpeg_path="", deno_path="", main_window=None, log_handler=None):
        super().__init__(parent)
        self.parent = parent
        self.config = config
        self.yt_dlp_path = yt_dlp_path
        self.ffmpeg_path = ffmpeg_path
        self.deno_path = deno_path
        self.main_window = main_window
        self.log_handler = log_handler  # 统一使用主窗口的log_handler
        
        # 批量提取状态
        self.is_extracting = False
        self.extracted_data = []  # 存储字典列表
        self.extract_thread = None
        self.selected_platform = "YouTube"
        
        # 分页相关变量
        self.current_page = 1
        self.items_per_page = 10
        self.total_pages = 1
        
        # 提取设置
        self.extract_settings = {
            "fields": ["url", "title"],  # 只包含用户选择的字段，下载、状态、进度、结果是固定的
            "separator": "|",
            "extract_mode": "快速模式（仅基本信息）"
        }
        
        # 信号
        self.signals = BatchExtractSignals()
        self.signals.progress_signal.connect(self._set_progress)
        self.signals.extract_complete_signal.connect(self._on_extract_complete)
        self.signals.status_signal.connect(self._set_status)
        self.signals.update_stats_signal.connect(self._update_stats)
        self.signals.update_page_info_signal.connect(self._update_page_info)
        self.signals.update_download_status_signal.connect(self._update_download_status)  # 新增
        
        # Cookie文件路径
        self.cookie_file = ""
        self.instagram_cookie_file = ""
        self.tiktok_cookie_file = ""
        self.twitter_cookie_file = ""
        
        # 下载相关 - 固定为视频下载
        self.download_type = "video"
        self.download_threads = []  # 存储活跃的下载线程
        self.latest_preview_file = ""
        
        # 持久化存储文件路径
        self.persistence_file = os.path.join(os.path.dirname(__file__), "batch_extract_data.json")
        
        # 持久化文件保存标志 - 用于控制日志输出
        self.persistent_save_logged = False
        
        # 初始化UI
        self.init_ui()
        
        # 加载上次提取的结果（如果存在）
        self.load_last_extract_results()
        
    def init_ui(self):
        """初始化UI - 优化版：移除下载类型选择"""
        layout = QVBoxLayout(self)
        layout.setContentsMargins(12, 12, 12, 12)
        layout.setSpacing(8)

        # 工具栏
        toolbar = QHBoxLayout()
        toolbar.setSpacing(6)

        toolbar.addWidget(QLabel("📺 平台:"))
        
        self.platform_combo_batch = QComboBox()
        self.platform_combo_batch.addItems(["YouTube", "Instagram", "TikTok"])
        self.platform_combo_batch.setCurrentText("YouTube")
        self.platform_combo_batch.setFixedWidth(100)
        self.platform_combo_batch.currentTextChanged.connect(self.on_platform_changed_batch)
        toolbar.addWidget(self.platform_combo_batch)
        
        toolbar.addSpacing(10)
        
        toolbar.addSpacing(10)

        # 提取设置按钮已移除
        # self.btn_settings_batch = QPushButton("⚙️ 提取设置")
        # self.btn_settings_batch.setFixedHeight(36)
        # self.btn_settings_batch.setMinimumWidth(80)
        # self.btn_settings_batch.clicked.connect(self.open_batch_settings)
        # toolbar.addWidget(self.btn_settings_batch)

        self.btn_paste_batch = QPushButton("📋 粘贴链接")
        self.btn_paste_batch.setFixedHeight(36)
        self.btn_paste_batch.setMinimumWidth(80)
        self.btn_paste_batch.clicked.connect(self._on_paste_batch)
        toolbar.addWidget(self.btn_paste_batch)

        self.btn_start_extract = QPushButton("🚀 开始提取")
        self.btn_start_extract.setFixedHeight(36)
        self.btn_start_extract.setMinimumWidth(80)
        self.btn_start_extract.clicked.connect(self.start_batch_extract)
        toolbar.addWidget(self.btn_start_extract)

        self.btn_save_extract = QPushButton("💾 保存结果")
        self.btn_save_extract.setFixedHeight(36)
        self.btn_save_extract.setMinimumWidth(80)
        self.btn_save_extract.clicked.connect(self.save_extract_results)
        toolbar.addWidget(self.btn_save_extract)

        self.btn_clear_extract = QPushButton("🗑️ 清空结果")
        self.btn_clear_extract.setFixedHeight(36)
        self.btn_clear_extract.setMinimumWidth(80)
        self.btn_clear_extract.clicked.connect(self.clear_extract_results)
        toolbar.addWidget(self.btn_clear_extract)

        self.btn_preview_extract = QPushButton("预览播放")
        self.btn_preview_extract.setFixedHeight(36)
        self.btn_preview_extract.setMinimumWidth(80)
        self.btn_preview_extract.setEnabled(False)
        self.btn_preview_extract.setToolTip("预览最近下载完成的视频")
        self.btn_preview_extract.clicked.connect(self._on_preview)
        toolbar.addWidget(self.btn_preview_extract)



        toolbar.addSpacerItem(QSpacerItem(20, 20, QSizePolicy.Expanding, QSizePolicy.Minimum))
        layout.addLayout(toolbar)

        # Cookie状态标签 - 从主窗口获取状态
        self.cookie_status_label_batch = QLabel("")
        self.cookie_status_label_batch.setStyleSheet("font-size: 11px; margin-top: 2px;")
        layout.addWidget(self.cookie_status_label_batch)

        # 输入框区域
        input_layout = QHBoxLayout()
        input_layout.setSpacing(8)
        
        # 添加标签
        input_label = QLabel("主页频道链接:")
        input_label.setFixedWidth(80)
        input_layout.addWidget(input_label)
        
        # 单行输入框
        self.input_box_batch = QLineEdit()
        self.input_box_batch.setPlaceholderText("请输入频道主页链接或视频列表链接")
        self.input_box_batch.setFixedHeight(32)
        self.input_box_batch.setClearButtonEnabled(True)
        
        # 设置输入框样式，与主程序保持一致
        self.input_box_batch.setStyleSheet("""
            QLineEdit {
                padding: 5px 7px;
                border: 1px solid #C9D8E1;
                border-radius: 6px;
                background-color: #FBFDFE;
                color: #0F172A;
                selection-background-color: #24576D;
            }
            QLineEdit:focus {
                border: 1px solid #6A9CAF;
                background-color: #FFFFFF;
            }
            QLineEdit:disabled {
                background-color: #E2E8F0;
                color: #94A3B8;
                border: 1px solid #CFDDE5;
            }
        """)
        self.on_platform_changed_batch(self.platform_combo_batch.currentText())
        
        input_layout.addWidget(self.input_box_batch, stretch=1)
        layout.addLayout(input_layout)

        # 进度条
        batch_prog_row = QHBoxLayout()
        self.batch_progress_bar = QProgressBar()
        self.batch_progress_bar.setFixedHeight(16)
        batch_prog_row.addWidget(self.batch_progress_bar)
        layout.addLayout(batch_prog_row)

        # 结果表格 - 使用自定义的可复制表格
        self.table_results = CopyableTableWidget()
        self.update_table_columns()
        
        # 设置表格属性 - 允许手动调整列宽
        self.table_results.horizontalHeader().setSectionResizeMode(QHeaderView.Interactive)  # 允许手动调整
        self.table_results.horizontalHeader().setStretchLastSection(False)  # 最后一列不拉伸
        
        # 调整行号列（垂直表头）的宽度和对齐方式
        vertical_header = self.table_results.verticalHeader()
        vertical_header.setDefaultSectionSize(45)  # 行高
        vertical_header.setFixedWidth(30)  # 减少行号列宽度
        vertical_header.setSectionResizeMode(QHeaderView.Fixed)  # 固定宽度
        
        # 设置行号居中显示
        vertical_header.setDefaultAlignment(Qt.AlignCenter)
        
        self.table_results.setAlternatingRowColors(True)
        self.table_results.setEditTriggers(QTableWidget.NoEditTriggers)
        
        # 设置选择模式为选择单元格，以便复制内容
        self.table_results.setSelectionBehavior(QTableWidget.SelectItems)
        self.table_results.setSelectionMode(QTableWidget.SingleSelection)
        
        # 启用鼠标选择和复制
        self.table_results.setFocusPolicy(Qt.StrongFocus)
        
        # 设置表格样式 - 深色主题，与主程序保持一致
        self.table_results.setStyleSheet("""
            QTableWidget {
                background-color: #FBFDFE;
                color: #0F172A;
                gridline-color: #E2E8F0;
                font-size: 11px;
                selection-background-color: #1E293B;
                selection-color: #FFFFFF;
                border: 1px solid #C9D8E1;
                border-radius: 8px;
                alternate-background-color: #F8FBFC;
            }
            QTableWidget QTableCornerButton::section {
                background-color: #EAF2F6;
                border: none;
                border-right: 1px solid #CFDDE5;
                border-bottom: 1px solid #CFDDE5;
            }
            QTableWidget::item {
                padding: 4px;
                border-bottom: 1px solid #E2E8F0;
                background-color: transparent;
            }
            QTableWidget::item:selected {
                background-color: #1E293B;
                color: #FFFFFF;
            }
            QHeaderView {
                background-color: #EAF2F6;
                border: none;
            }
            QHeaderView::section {
                background-color: #EAF2F6;
                color: #0F172A;
                padding: 8px;
                border: 1px solid #CFDDE5;
                font-weight: bold;
                font-size: 11px;
                min-width: 50px;
            }
            QHeaderView::section:last {
                border-right: none;
            }
            QTableWidget QScrollBar:vertical {
                background-color: #EEF2F7;
                width: 10px;
                border-radius: 5px;
            }
            QTableWidget QScrollBar::handle:vertical {
                background-color: #CFDDE5;
                border-radius: 5px;
                min-height: 26px;
            }
            QTableWidget QScrollBar::handle:vertical:hover {
                background-color: #94A3B8;
            }
            QTableWidget QScrollBar::add-line:vertical,
            QTableWidget QScrollBar::sub-line:vertical {
                border: none;
                background: none;
            }
        """)
        
        layout.addWidget(self.table_results, stretch=1)
        
        # 分页控件区域
        self.init_pagination_controls(layout)
        
        # 统计信息
        stats_layout = QHBoxLayout()
        self.lbl_extract_stats = QLabel("📊 等待提取...")
        self.lbl_extract_stats.setStyleSheet("color: #475569; font-size: 11px;")
        
        self.lbl_extract_progress = QLabel("进度: 0/0")
        self.lbl_extract_progress.setStyleSheet("color: #64748B; font-size: 11px;")
        
        stats_layout.addWidget(self.lbl_extract_stats)
        stats_layout.addStretch()
        stats_layout.addWidget(self.lbl_extract_progress)
        layout.addLayout(stats_layout)

        layout.addStretch()
        
    def init_pagination_controls(self, layout):
        """初始化分页控件"""
        # 创建分页控件容器
        pagination_container = QFrame()
        pagination_container.setFixedHeight(40)
        pagination_container.setStyleSheet("""
            QFrame {
                background-color: #F8FBFC;
                border: 1px solid #CFDDE5;
                border-radius: 8px;
                padding: 4px;
            }
        """)
        
        pagination_layout = QHBoxLayout(pagination_container)
        pagination_layout.setContentsMargins(10, 0, 10, 0)
        pagination_layout.setSpacing(10)
        
        # 每页显示数量标签
        items_per_page_label = QLabel("每页显示:")
        items_per_page_label.setStyleSheet("color: #0F172A;")
        pagination_layout.addWidget(items_per_page_label)
        
        # 每页显示数量选择框
        self.spin_items_per_page = QSpinBox()
        self.spin_items_per_page.setMinimum(5)
        self.spin_items_per_page.setMaximum(50)
        self.spin_items_per_page.setValue(10)
        self.spin_items_per_page.setFixedWidth(60)
        self.spin_items_per_page.setStyleSheet("""
            QSpinBox {
                background-color: #FBFDFE;
                color: #0F172A;
                border: 1px solid #C9D8E1;
                border-radius: 6px;
                padding: 3px;
            }
        """)
        self.spin_items_per_page.valueChanged.connect(self.on_items_per_page_changed)
        pagination_layout.addWidget(self.spin_items_per_page)
        
        # 分隔符
        pagination_layout.addSpacerItem(QSpacerItem(20, 20, QSizePolicy.Expanding, QSizePolicy.Minimum))
        
        # 上一页按钮
        self.btn_prev_page = QPushButton("◀ 上一页")
        self.btn_prev_page.setFixedWidth(80)
        self.btn_prev_page.setEnabled(False)
        self.btn_prev_page.clicked.connect(self.go_to_previous_page)
        self.btn_prev_page.setStyleSheet("""
            QPushButton {
                background: qlineargradient(x1:0,y1:0,x2:0,y2:1, stop:0 #FBFDFE, stop:1 #EEF4F7);
                color: #17445C;
                border: 1px solid #CFDDE5;
                border-radius: 6px;
                padding: 5px;
                font-weight: bold;
            }
            QPushButton:disabled {
                background-color: #E2E8F0;
                color: #94A3B8;
                border: 1px solid #CFDDE5;
            }
            QPushButton:hover:enabled {
                background: qlineargradient(x1:0,y1:0,x2:0,y2:1, stop:0 #2A6A82, stop:1 #17445C);
                color: #FFFFFF;
                border: 1px solid #6AB6CC;
            }
        """)
        pagination_layout.addWidget(self.btn_prev_page)
        
        # 页码信息标签
        self.lbl_page_info = QLabel("第 1 页 / 共 1 页")
        self.lbl_page_info.setStyleSheet("color: #0F172A; font-weight: bold;")
        self.lbl_page_info.setFixedWidth(120)
        self.lbl_page_info.setAlignment(Qt.AlignCenter)
        pagination_layout.addWidget(self.lbl_page_info)
        
        # 下一页按钮
        self.btn_next_page = QPushButton("下一页 ▶")
        self.btn_next_page.setFixedWidth(80)
        self.btn_next_page.setEnabled(False)
        self.btn_next_page.clicked.connect(self.go_to_next_page)
        self.btn_next_page.setStyleSheet("""
            QPushButton {
                background: qlineargradient(x1:0,y1:0,x2:0,y2:1, stop:0 #FBFDFE, stop:1 #EEF4F7);
                color: #17445C;
                border: 1px solid #CFDDE5;
                border-radius: 6px;
                padding: 5px;
                font-weight: bold;
            }
            QPushButton:disabled {
                background-color: #E2E8F0;
                color: #94A3B8;
                border: 1px solid #CFDDE5;
            }
            QPushButton:hover:enabled {
                background: qlineargradient(x1:0,y1:0,x2:0,y2:1, stop:0 #2A6A82, stop:1 #17445C);
                color: #FFFFFF;
                border: 1px solid #6AB6CC;
            }
        """)
        pagination_layout.addWidget(self.btn_next_page)
        
        # 跳转页码输入框
        go_to_label = QLabel("跳转到:")
        go_to_label.setStyleSheet("color: #0F172A;")
        pagination_layout.addWidget(go_to_label)
        
        self.spin_go_to_page = QSpinBox()
        self.spin_go_to_page.setMinimum(1)
        self.spin_go_to_page.setMaximum(1)
        self.spin_go_to_page.setValue(1)
        self.spin_go_to_page.setFixedWidth(60)
        self.spin_go_to_page.setStyleSheet("""
            QSpinBox {
                background-color: #FBFDFE;
                color: #0F172A;
                border: 1px solid #C9D8E1;
                border-radius: 6px;
                padding: 3px;
            }
        """)
        pagination_layout.addWidget(self.spin_go_to_page)
        
        # 跳转按钮
        self.btn_go_to_page = QPushButton("跳转")
        self.btn_go_to_page.setFixedWidth(60)
        self.btn_go_to_page.setEnabled(False)
        self.btn_go_to_page.clicked.connect(self.go_to_specific_page)
        self.btn_go_to_page.setStyleSheet("""
            QPushButton {
                background: qlineargradient(x1:0,y1:0,x2:0,y2:1, stop:0 #FBFDFE, stop:1 #EEF4F7);
                color: #17445C;
                border: 1px solid #CFDDE5;
                border-radius: 6px;
                padding: 5px;
            }
            QPushButton:disabled {
                background-color: #E2E8F0;
                color: #94A3B8;
                border: 1px solid #CFDDE5;
            }
            QPushButton:hover:enabled {
                background: qlineargradient(x1:0,y1:0,x2:0,y2:1, stop:0 #2A6A82, stop:1 #17445C);
                color: #FFFFFF;
                border: 1px solid #6AB6CC;
            }
        """)
        pagination_layout.addWidget(self.btn_go_to_page)
        
        # 将分页控件添加到主布局
        layout.addWidget(pagination_container)
    
    def update_table_columns(self):
        """根据设置更新表格列 - 固定列：链接、标题、下载、状态、进度、结果"""
        # 固定列数：6列
        self.table_results.setColumnCount(6)
        
        # 设置列标题
        column_titles = ["链接", "标题", "下载", "状态", "进度", "结果"]
        self.table_results.setHorizontalHeaderLabels(column_titles)
        
        # 设置初始列宽比例 - 第一列宽度减少四分之一
        total_width = self.table_results.width() if self.table_results.width() > 0 else 800
        avg_width = int(total_width / 6)  # 平均分配6列
        # 第一列宽度减少四分之一
        first_col_width = int(avg_width * 0.75)
        # 其他列平均分配剩余宽度
        remaining_width = total_width - first_col_width
        other_col_width = int(remaining_width / 5)
        
        col_widths = [
            first_col_width,  # 链接列（宽度减少四分之一）
            other_col_width,  # 标题列
            other_col_width,  # 下载列
            other_col_width,  # 状态列
            other_col_width,  # 进度列
            other_col_width   # 结果列
        ]
        
        for i, width in enumerate(col_widths):
            self.table_results.setColumnWidth(i, width)
    
    def set_config(self, config):
        """设置配置"""
        self.config = config
        if config:
            # 移除Cookie文件路径获取，改为从主窗口获取状态
            self.cookie_file = config.get("cookie_file", "")
            self.instagram_cookie_file = config.get("cookie_instagram", "")
            self.tiktok_cookie_file = config.get("cookie_tiktok", "")
            # 固定为视频下载
            self.download_type = "video"
    
    def set_yt_dlp_path(self, yt_dlp_path):
        """设置yt-dlp路径"""
        self.yt_dlp_path = yt_dlp_path
        
    def update_cookie_status_display(self):
        """更新Cookie状态显示 - 从主窗口获取状态"""
        if not self.main_window:
            return
            
        try:
            # 从主窗口获取Cookie状态
            if hasattr(self.main_window, 'cookie_status'):
                cookie_status = self.main_window.cookie_status
                status_items = []
                all_exist = True
                
                if cookie_status.get('youtube', {}).get('exists', False):
                    status_items.append("YouTube: ✅")
                else:
                    status_items.append("YouTube: ❌")
                    all_exist = False
                
                if cookie_status.get('instagram', {}).get('exists', False):
                    status_items.append("Instagram: ✅")
                else:
                    status_items.append("Instagram: ❌")
                    all_exist = False
                
                if cookie_status.get('tiktok', {}).get('exists', False):
                    status_items.append("TikTok: ✅")
                else:
                    status_items.append("TikTok: ❌")
                    all_exist = False
                
                status_text = " | ".join(status_items)
                
                if all_exist:
                    self.cookie_status_label_batch.setText(f"✅ 所有Cookie就绪 | {status_text}")
                    self.cookie_status_label_batch.setStyleSheet("font-size: 11px; margin-top: 2px; color: #10b981;")
                else:
                    self.cookie_status_label_batch.setText(f"⚠️ 部分Cookie缺失 | {status_text}")
                    self.cookie_status_label_batch.setStyleSheet("font-size: 11px; margin-top: 2px; color: #f59e0b;")
        except Exception:
            # 如果获取失败，显示简单状态
            self.cookie_status_label_batch.setText("🍪 Cookie状态: 未知")
            self.cookie_status_label_batch.setStyleSheet("font-size: 11px; margin-top: 2px; color: #9ca3af;")
        
    def _set_progress(self, value):
        """设置进度条"""
        self.batch_progress_bar.setValue(value)
        
    def _set_status(self, text):
        """设置状态"""
        pass
        
    def _update_stats(self, stats_text, progress_text):
        """更新统计信息"""
        self.lbl_extract_stats.setText(stats_text)
        self.lbl_extract_progress.setText(progress_text)
        
    def _update_page_info(self, text):
        """更新分页信息"""
        self.lbl_page_info.setText(text)
    
    def _update_download_status(self, url, status, result, progress):
        """更新下载状态"""
        # 找到对应的数据项和行索引
        item_index = -1
        for i, item in enumerate(self.extracted_data):
            if item.get("url") == url:
                item["status"] = status
                item["result"] = result
                item["progress"] = progress
                item_index = i
                break
        
        # 如果找到了对应项，直接更新表格中对应行
        if item_index >= 0:
            # 计算该数据项在当前页的行索引
            start_idx = (self.current_page - 1) * self.items_per_page
            end_idx = min(start_idx + self.items_per_page, len(self.extracted_data))
            
            # 检查该项是否在当前页
            if start_idx <= item_index < end_idx:
                # 计算在表格中的行号
                table_row = item_index - start_idx
                
                # 列索引定义
                DOWNLOAD_COL = 2
                STATUS_COL = 3
                PROGRESS_COL = 4
                RESULT_COL = 5
                
                # 更新状态列
                status_item = QTableWidgetItem(status)
                status_item.setFlags(status_item.flags() | Qt.ItemIsSelectable | Qt.ItemIsEnabled)
                status_item.setTextAlignment(Qt.AlignCenter)
                
                # 根据状态设置颜色
                if status == "下载中":
                    status_item.setForeground(QBrush(QColor("#ffc107")))
                elif status == "完成":
                    status_item.setForeground(QBrush(QColor("#28a745")))
                elif status == "失败":
                    status_item.setForeground(QBrush(QColor("#dc3545")))
                else:
                    status_item.setForeground(QBrush(QColor("#0F172A")))
                
                self.table_results.setItem(table_row, STATUS_COL, status_item)
                
                # 更新进度列
                progress_bar = TableProgressBar()
                if status == "下载中":
                    if progress == 0:
                        progress_bar.setRange(0, 0)
                        progress_bar.setFormat("等待中...")
                    else:
                        progress_bar.setRange(0, 100)
                        progress_bar.setValue(progress)
                        progress_bar.setFormat(f"{progress}%")
                        progress_bar.set_progress_color("#ffc107")
                elif status == "完成":
                    progress_bar.setRange(0, 100)
                    progress_bar.setValue(100)
                    progress_bar.setFormat("100%")
                    progress_bar.set_progress_color("#28a745")
                elif status == "失败":
                    progress_bar.setRange(0, 100)
                    progress_bar.setValue(0)
                    progress_bar.setFormat("0%")
                    progress_bar.set_progress_color("#dc3545")
                else:
                    progress_bar.setRange(0, 100)
                    progress_bar.setValue(0)
                    progress_bar.setFormat("0%")
                
                self.table_results.setCellWidget(table_row, PROGRESS_COL, progress_bar)
                
                # 更新下载按钮
                centered_button = CenteredButtonWidget()
                if status == "下载中":
                    centered_button.set_button_style("""
                        background-color: #ffc107;  /* 黄色背景 */
                        color: black;  /* 黑色文字 */
                    """)
                    centered_button.set_text("下载中")
                    centered_button.set_enabled(False)
                elif status == "完成":
                    centered_button.set_button_style("""
                        background-color: #28a745;  /* 绿色背景 */
                        color: white;  /* 白色文字 */
                    """)
                    centered_button.set_text("已下载")
                    centered_button.set_enabled(False)
                elif status == "失败":
                    centered_button.set_button_style("""
                        background-color: #dc3545;  /* 红色背景 */
                        color: white;  /* 白色文字 */
                    """)
                    centered_button.set_text("重试")
                    centered_button.set_enabled(True)
                    centered_button.connect_clicked(lambda checked, url=url: self.download_single_video(url))
                else:  # 未下载
                    centered_button.set_button_style("""
                        background-color: #2E7892;
                        color: white;  /* 白色文字 */
                    """)
                    centered_button.set_text("下载")
                    centered_button.set_enabled(True)
                    centered_button.connect_clicked(lambda checked, url=url: self.download_single_video(url))
                
                self.table_results.setCellWidget(table_row, DOWNLOAD_COL, centered_button)

                # 更新结果列，确保批量下载后表格里能立即看到“下载成功/下载失败”
                result_item = QTableWidgetItem(result)
                result_item.setData(Qt.UserRole, result)
                result_item.setFlags(result_item.flags() | Qt.ItemIsSelectable | Qt.ItemIsEnabled)
                result_item.setTextAlignment(Qt.AlignCenter)
                result_item.setFont(QFont("Arial", 9))

                if "成功" in result or "完成" in result:
                    result_item.setForeground(QBrush(QColor("#28a745")))
                elif "失败" in result or "异常" in result:
                    result_item.setForeground(QBrush(QColor("#dc3545")))
                elif "下载中" in result or "等待" in result:
                    result_item.setForeground(QBrush(QColor("#ffc107")))
                else:
                    result_item.setForeground(QBrush(QColor("#0F172A")))

                result_item.setToolTip(f"完整结果: {result}")
                self.table_results.setItem(table_row, RESULT_COL, result_item)
        
        # 保存到持久化文件（保留下载状态）- 不输出日志
        self.save_to_persistent_file(silent=True)
        
    def on_platform_changed_batch(self, platform_name):
        """平台更改"""
        self.selected_platform = platform_name
        placeholder_map = {
            "YouTube": "请输入 YouTube 频道主页链接或播放列表链接",
            "Instagram": "请输入 Instagram 主页链接、Reels 列表链接或帖子列表链接",
            "TikTok": "请输入 TikTok 主页链接或视频列表链接",
        }
        self.input_box_batch.setPlaceholderText(
            placeholder_map.get(platform_name, "请输入主页链接或视频列表链接")
        )
        # 平台更改时更新Cookie状态显示
        self.update_cookie_status_display()
        
    def _on_paste_batch(self):
        """粘贴链接"""
        try:
            clipboard = QGuiApplication.clipboard()
            text = clipboard.text()
            if text:
                # 只取第一行（如果有多行）
                lines = text.strip().split('\n')
                if lines:
                    first_line = lines[0].strip()
                    self.input_box_batch.setText(first_line)
                    if len(lines) > 1:
                        if self.log_handler:
                            self.log_handler.log("已粘贴第一条链接（共{}条）".format(len(lines)))
                    else:
                        if self.log_handler:
                            self.log_handler.log("已粘贴链接")
            else:
                if self.log_handler:
                    self.log_handler.log("剪贴板为空")
        except Exception as e:
            if self.log_handler:
                self.log_handler.log(f"粘贴失败: {str(e)}")
            
    # 提取设置功能已移除，默认提取URL链接和标题
    def start_batch_extract(self):
        """开始批量提取"""
        # 检查授权 - 需要从父窗口获取
        if self.main_window and not getattr(self.main_window, 'authorized', False):
            reply = QMessageBox.question(
                self, "授权提示",
                "当前未授权或试用已过期。\n是否跳转到官网获取正式授权？",
                QMessageBox.Yes | QMessageBox.No,
                QMessageBox.Yes
            )
            if reply == QMessageBox.Yes:
                if self.main_window:
                    self.main_window.open_website()
            return

        if not os.path.exists(self.yt_dlp_path):
            if self.log_handler:
                self.log_handler.log("找不到 yt-dlp.exe，请先更新插件")
            QMessageBox.warning(self, "工具缺失", "找不到 yt-dlp.exe，请先点击'更新插件'按钮下载")
            return

        text = self.input_box_batch.text().strip()
        if not text:
            if self.log_handler:
                self.log_handler.log("输入框为空，请输入链接。")
            QMessageBox.warning(self, "输入为空", "请输入频道主页链接")
            return

        if self.is_extracting:
            reply = QMessageBox.question(
                self, "提取进行中",
                "当前已有提取任务在进行中，是否停止并开始新的提取？",
                QMessageBox.Yes | QMessageBox.No,
                QMessageBox.No
            )
            if reply != QDialogButtonBox.Yes:
                return
            self.stop_extract()

        # 构建print_format
        fields = self.extract_settings["fields"]
        separator = self.extract_settings["separator"]
        
        # 将字段名映射为yt-dlp格式
        field_mapping = {
            "url": "%(url)s",
            "title": "%(title)s"
        }
        
        print_format_parts = [field_mapping.get(field, field) for field in fields]
        print_format = separator.join(print_format_parts)

        # 验证链接格式
        if not self._validate_url(text):
            if self.log_handler:
                self.log_handler.log("链接格式不正确，请输入有效的URL")
            QMessageBox.warning(self, "链接无效", "请输入有效的URL链接")
            return

        # 发送开始提取日志
        if self.log_handler:
            self.log_handler.log(f"开始批量提取链接...")
            self.log_handler.log(f"输出格式: {print_format}")
            self.log_handler.log(f"链接: {text}")
            self.log_handler.log(f"提取字段: {', '.join(fields)}")
            self.log_handler.log(f"提取模式: {self.extract_settings['extract_mode']}")
        
        # 清空表格和分页状态
        detected_platform = self._detect_batch_platform_from_url(text)
        if detected_platform == "Instagram":
            entry_type = self._classify_instagram_extract_url(text)
            if entry_type is None:
                QMessageBox.warning(
                    self,
                    "Instagram 链接不支持",
                    "请输入支持的 Instagram 入口：主页、/reels/、/tagged/、/stories/、/stories/highlights/ 或 /explore/tags/ 链接",
                )
                return

            normalized_url = self._normalize_instagram_extract_url(text, entry_type)
            if normalized_url != text:
                self.input_box_batch.setText(normalized_url)
                text = normalized_url

            if self.log_handler:
                self.log_handler.log(f"Instagram 入口类型: {self._get_instagram_entry_label(entry_type)}")

            if entry_type in {"stories", "story_highlights"}:
                if not getattr(self, "instagram_cookie_file", "") or not os.path.exists(self.instagram_cookie_file):
                    QMessageBox.warning(
                        self,
                        "Instagram Cookie 缺失",
                        "提取 Instagram Stories 或 Highlights 需要有效的 Instagram Cookie，请先在设置中导入。",
                    )
                    return

        self.table_results.setRowCount(0)
        self.extracted_data = []
        self.current_page = 1
        self.total_pages = 1
        self.update_pagination_controls()
        
        # 重置持久化文件保存标志
        self.persistent_save_logged = False
        
        self.is_extracting = True
        self.signals.update_stats_signal.emit("正在提取中...", "进度: 0/1")
        
        self.btn_start_extract.setEnabled(False)
        self.btn_start_extract.setText("⏳ 提取中...")
        
        self.batch_progress_bar.setValue(0)
        
        self.extract_thread = threading.Thread(
            target=self._batch_extract_worker,
            args=(text, print_format, separator, fields),
            daemon=True
        )
        self.extract_thread.start()
        
    def _validate_url(self, url):
        """验证URL格式"""
        # 简单的URL验证
        url_pattern = re.compile(
            r'^https?://'  # http:// or https://
            r'(?:(?:[A-Z0-9](?:[A-Z0-9-]{0,61}[A-Z0-9])?\.)+(?:[A-Z]{2,6}\.?|[A-Z0-9-]{2,}\.?)|'  # domain...
            r'localhost|'  # localhost...
            r'\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})'  # ...or ip
            r'(?::\d+)?'  # optional port
            r'(?:/?|[/?]\S+)$', re.IGNORECASE)
        
        if re.match(url_pattern, url) is None:
            return False

        if self.selected_platform == "Instagram":
            return self._classify_instagram_extract_url(url) is not None

        return True

    def _detect_batch_platform_from_url(self, url):
        lowered_url = (url or "").lower()
        if "youtube.com" in lowered_url or "youtu.be" in lowered_url:
            return "YouTube"
        if "instagram.com" in lowered_url:
            return "Instagram"
        if "twitter.com" in lowered_url or "x.com" in lowered_url:
            return "Twitter"
        return "TikTok"

    def _classify_instagram_extract_url(self, url):
        try:
            parsed = urlparse((url or "").strip())
        except Exception:
            return None

        netloc = (parsed.netloc or "").lower()
        if "instagram.com" not in netloc:
            return None

        segments = [segment for segment in parsed.path.split("/") if segment]
        if not segments:
            return None

        reserved = {
            "accounts", "about", "developer", "direct", "explore", "legal",
            "p", "reel", "reels", "stories", "tv",
        }

        if segments[0] == "stories":
            if len(segments) >= 3 and segments[1] == "highlights":
                return "story_highlights"
            if len(segments) >= 2:
                return "stories"
            return None

        if segments[0] == "explore":
            if len(segments) >= 3 and segments[1] == "tags":
                return "explore_tags"
            return None

        if segments[0] in {"p", "reel", "tv"} and len(segments) >= 2:
            return "single_post"

        if len(segments) == 1 and segments[0] not in reserved:
            return "profile"

        if len(segments) >= 2 and segments[1] == "reels":
            return "profile_reels"

        if len(segments) >= 2 and segments[1] == "tagged":
            return "profile_tagged"

        return None

    def _normalize_instagram_extract_url(self, url, entry_type):
        parsed = urlparse((url or "").strip())
        segments = [segment for segment in parsed.path.split("/") if segment]
        normalized_path = "/" + "/".join(segments)
        if normalized_path and not normalized_path.endswith("/"):
            normalized_path += "/"

        keep_query_types = {"stories", "story_highlights", "single_post"}
        normalized_query = parsed.query if entry_type in keep_query_types else ""

        return urlunparse((
            parsed.scheme or "https",
            "www.instagram.com",
            normalized_path,
            "",
            normalized_query,
            "",
        ))

    def _get_instagram_entry_label(self, entry_type):
        labels = {
            "profile": "主页帖子",
            "profile_reels": "主页 Reels",
            "profile_tagged": "被标记内容",
            "stories": "Stories",
            "story_highlights": "Stories Highlights",
            "explore_tags": "话题标签页",
            "single_post": "单条帖子",
        }
        return labels.get(entry_type, "Instagram 页面")
        
    def stop_extract(self):
        """停止提取"""
        self.is_extracting = False
        if self.extract_thread and self.extract_thread.is_alive():
            if self.log_handler:
                self.log_handler.log("正在停止提取...")
        self.btn_start_extract.setEnabled(True)
        self.btn_start_extract.setText("🚀 开始提取")
        
    def clear_extract_results(self):
        """清空提取结果"""
        reply = QMessageBox.question(
            self, "确认清空",
            "确定要清空所有提取结果吗？",
            QMessageBox.Yes | QMessageBox.No,
            QMessageBox.No
        )
        if reply == QMessageBox.Yes:
            self.table_results.setRowCount(0)
            self.extracted_data = []
            self.current_page = 1
            self.total_pages = 1
            self.update_pagination_controls()
            self.signals.update_stats_signal.emit("等待提取...", "进度: 0/1")
            if self.log_handler:
                self.log_handler.log("已清空提取结果")
            
            # 删除持久化存储文件
            self.delete_persistent_data()
            
    def save_extract_results(self):
        """保存提取结果 - 只保存未下载过的链接"""
        if not self.extracted_data:
            if self.log_handler:
                self.log_handler.log("没有可保存的提取结果")
            QMessageBox.warning(self, "保存失败", "没有可保存的提取结果")
            return

        # 过滤未下载过的链接
        undownloaded_items = [item for item in self.extracted_data 
                              if item.get("status", "未下载") != "完成"]
        
        if not undownloaded_items:
            if self.log_handler:
                self.log_handler.log("所有链接都已经下载过了，没有需要保存的未下载链接")
            QMessageBox.information(self, "保存提示", 
                "所有链接都已经下载过了，没有需要保存的未下载链接。\n\n"
                "如果您想重新保存所有链接，请先清空结果并重新提取。")
            return

        file_path, _ = QFileDialog.getSaveFileName(
            self, "保存提取结果",
            os.path.expanduser("~/Desktop/未下载链接.txt"),
            "Text Files (*.txt);;All Files (*.*)"
        )
        
        if not file_path:
            return

        try:
            # 确保文件扩展名正确
            if not file_path.lower().endswith('.txt'):
                file_path += '.txt'
            
            # 只保存未下载的URL链接，每行一个
            with open(file_path, 'w', encoding='utf-8') as f:
                # 写入文件头信息
                f.write(f"# 提取时间: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
                f.write(f"# 平台: {self.selected_platform if hasattr(self, 'selected_platform') else '自动识别'}\n")
                f.write(f"# 原始链接: {self.input_box_batch.text().strip()}\n")
                f.write(f"# 总链接数: {len(self.extracted_data)}\n")
                f.write(f"# 未下载链接数: {len(undownloaded_items)}\n")
                f.write(f"# 已下载链接数: {len(self.extracted_data) - len(undownloaded_items)}\n")
                f.write(f"# 画质: 最佳画质\n")
                f.write(f"# 只保存未下载的URL链接\n")
                f.write("#" * 80 + "\n\n")
                
                # 写入未下载的URL链接，每行一个
                for item in undownloaded_items:
                    url = item.get("url", "")
                    if url:  # 确保URL不为空
                        f.write(f"{url}\n")
            
            if self.log_handler:
                self.log_handler.log(f"提取结果已保存到: {file_path}")
                self.log_handler.log(f"过滤后保存了 {len(undownloaded_items)} 个未下载的URL链接")
                self.log_handler.log(f"已过滤 {len(self.extracted_data) - len(undownloaded_items)} 个已下载链接")
            
            QMessageBox.information(
                self, 
                "保存成功", 
                f"已保存 {len(undownloaded_items)} 个未下载的URL链接到:\n{file_path}\n\n"
                f"统计信息:\n"
                f"• 总链接数: {len(self.extracted_data)} 个\n"
                f"• 已下载链接: {len(self.extracted_data) - len(undownloaded_items)} 个\n"
                f"• 未下载链接: {len(undownloaded_items)} 个\n\n"
                f"已自动过滤掉已下载过的链接。"
            )
            
        except Exception as e:
            if self.log_handler:
                self.log_handler.log(f"保存失败: {e}")
            QMessageBox.warning(self, "保存失败", f"保存文件时出错: {e}")
    
    def open_download_folder(self):
        """打开下载文件夹"""
        if not self.config:
            if self.log_handler:
                self.log_handler.log("配置未初始化")
            QMessageBox.warning(self, "错误", "配置未初始化")
            return
        
        # 获取下载路径
        download_path = self.config.get("download_path", "")
        
        # 如果配置中没有下载路径，则使用默认下载路径
        if not download_path:
            # 尝试获取主窗口的下载路径
            if self.main_window and hasattr(self.main_window, 'download_path'):
                download_path = self.main_window.download_path
            else:
                # 使用当前目录下的downloads文件夹
                download_path = os.path.join(os.path.dirname(__file__), "downloads")
        
        # 如果路径不存在，创建它
        if not os.path.exists(download_path):
            try:
                os.makedirs(download_path, exist_ok=True)
                if self.log_handler:
                    self.log_handler.log(f"创建下载文件夹: {download_path}")
            except Exception as e:
                if self.log_handler:
                    self.log_handler.log(f"创建下载文件夹失败: {e}")
                QMessageBox.warning(self, "错误", f"创建下载文件夹失败: {e}")
                return
        
        # 打开文件夹
        try:
            if platform.system() == "Windows":
                os.startfile(download_path)
            elif platform.system() == "Darwin":  # macOS
                subprocess.Popen(["open", download_path])
            else:  # Linux
                subprocess.Popen(["xdg-open", download_path])
            
            if self.log_handler:
                self.log_handler.log(f"已打开下载文件夹: {download_path}")
            
        except Exception as e:
            if self.log_handler:
                self.log_handler.log(f"打开下载文件夹失败: {e}")
            QMessageBox.warning(self, "错误", f"打开下载文件夹失败: {e}")
            
    def _batch_extract_worker(self, url, print_format, separator, fields):
        """批量提取工作线程"""
        try:
            self.signals.progress_signal.emit(10)
            self.signals.update_stats_signal.emit(
                f"正在提取中...",
                f"进度: 开始提取"
            )
            
            if self.log_handler:
                self.log_handler.log(f"正在处理链接: {url}")
            
            try:
                cmd = [
                    self.yt_dlp_path,
                    "--flat-playlist",
                    "--print", print_format,
                    "--no-download",
                    "--quiet",
                    "--ignore-errors",
                    "--no-warnings"
                ]
                
                # 根据提取模式调整参数
                if self.extract_settings["extract_mode"] == "完整模式（获取详细信息）":
                    # 移除--flat-playlist以获取详细信息，但速度会变慢
                    cmd.remove("--flat-playlist")
                    cmd.append("--get-title")
                
                # 根据平台自动判断是否需要Cookie
                platform_name = self._detect_batch_platform_from_url(url)
                instagram_entry_type = None
                if platform_name == "Instagram":
                    instagram_entry_type = self._classify_instagram_extract_url(url)
                    url = self._normalize_instagram_extract_url(url, instagram_entry_type or "profile")
                
                if platform_name == "YouTube":
                    cmd.extend(["--yes-playlist"])
                    # 只在启动时检查一次，下载时不重复检查
                    if hasattr(self, 'cookie_file') and os.path.exists(self.cookie_file):
                        cmd.extend(["--cookies", self.cookie_file])
                elif platform_name == "Instagram":
                    cmd.extend(["--yes-playlist"])
                    if hasattr(self, 'instagram_cookie_file') and os.path.exists(self.instagram_cookie_file):
                        cmd.extend(["--cookies", self.instagram_cookie_file])
                    if instagram_entry_type in {"stories", "story_highlights", "single_post"} and "--flat-playlist" in cmd:
                        cmd.remove("--flat-playlist")
                    if instagram_entry_type == "profile_reels":
                        cmd.extend(["--match-filter", "url =~ (?i)reel"])
                elif platform_name == "TikTok":
                    # 只在启动时检查一次，下载时不重复检查
                    if hasattr(self, 'tiktok_cookie_file') and os.path.exists(self.tiktok_cookie_file):
                        cmd.extend(["--cookies", self.tiktok_cookie_file])
                elif platform_name == "Twitter":
                    cmd.extend(["--yes-playlist"])
                    if hasattr(self, 'twitter_cookie_file') and os.path.exists(self.twitter_cookie_file):
                        cmd.extend(["--cookies", self.twitter_cookie_file])

                cmd.append(url)
                
                creationflags = subprocess.CREATE_NO_WINDOW if platform.system().lower().startswith("win") else 0
                
                if self.log_handler:
                    self.log_handler.log(f"正在执行命令: {' '.join(cmd)}")
                
                result = subprocess.run(
                    cmd,
                    capture_output=True,
                    text=True,
                    encoding='utf-8',
                    errors='ignore',
                    creationflags=creationflags,
                    timeout=600
                )
                
                self.signals.progress_signal.emit(50)
                
                if result.returncode == 0 and result.stdout:
                    lines = [line.strip() for line in result.stdout.split('\n') if line.strip()]
                    total_lines = len(lines)
                    
                    if total_lines > 0:
                        valid_count = 0
                        invalid_count = 0
                        
                        for idx, line in enumerate(lines):
                            if not line or not self.is_extracting:
                                continue
                            
                            # 更新进度
                            if idx % 100 == 0 or idx == total_lines - 1:
                                progress = 50 + int((idx / total_lines) * 50)
                                self.signals.progress_signal.emit(progress)
                                self.signals.update_stats_signal.emit(
                                    f"正在解析数据...",
                                    f"进度: {idx}/{total_lines}"
                                )
                            
                            # 解析每一行数据
                            parts = line.split(separator, len(fields)-1)
                            
                            # 如果分割后的数量少于字段数量，用空字符串填充
                            while len(parts) < len(fields):
                                parts.append("")
                            
                            item = {}
                            for i, field in enumerate(fields):
                                value = parts[i] if i < len(parts) else ""
                                value = value.strip()
                                item[field] = value
                            
                            # 检查是否有效数据（至少包含URL）
                            if item.get("url") and "http" in item.get("url", "").lower():
                                # 检查这个URL是否已经有下载状态（从持久化数据中恢复）
                                existing_status = "未下载"
                                existing_result = ""
                                existing_progress = 0
                                
                                # 在现有数据中查找是否已经有这个URL的状态
                                for existing_item in self.extracted_data:
                                    if existing_item.get("url") == item.get("url"):
                                        existing_status = existing_item.get("status", "未下载")
                                        existing_result = existing_item.get("result", "")
                                        existing_progress = existing_item.get("progress", 0)
                                        break
                                
                                # 添加下载、状态、进度、结果字段
                                item["download"] = ""  # 下载按钮
                                item["status"] = existing_status  # 使用现有状态或默认"未下载"
                                item["progress"] = existing_progress  # 使用现有进度或默认0
                                item["result"] = existing_result  # 使用现有结果或默认""
                                self.extracted_data.append(item)
                                valid_count += 1
                            else:
                                invalid_count += 1
                        
                        if self.log_handler:
                            self.log_handler.log(f"提取完成: 共 {total_lines} 行，有效 {valid_count} 条，无效 {invalid_count} 条")
                    else:
                        if self.log_handler:
                            self.log_handler.log(f"未提取到数据: {url}")
                else:
                    error_msg = result.stderr if result.stderr else "未知错误"
                    if self.log_handler:
                        self.log_handler.log(f"提取失败: {error_msg[:200]}")
                    
            except subprocess.TimeoutExpired:
                if self.log_handler:
                    self.log_handler.log(f"提取超时: {url}")
            except Exception as e:
                if self.log_handler:
                    self.log_handler.log(f"提取异常: {str(e)[:200]}")
            
            # 发送完成信号
            self.signals.extract_complete_signal.emit(self.extracted_data)
            
        except Exception as e:
            if self.log_handler:
                self.log_handler.log(f"提取工作线程异常: {e}")
        finally:
            self.is_extracting = False
            self.btn_start_extract.setEnabled(True)
            self.btn_start_extract.setText("🚀 开始提取")
            
            self.batch_progress_bar.setValue(100)
            
    def _on_extract_complete(self, extracted_data):
        """提取完成处理"""
        self.is_extracting = False
        self.btn_start_extract.setEnabled(True)
        self.btn_start_extract.setText("🚀 开始提取")
        
        if extracted_data:
            if self.log_handler:
                self.log_handler.log(f"批量提取完成！共提取 {len(extracted_data)} 条记录")
            self.signals.update_stats_signal.emit(f"提取完成: {len(extracted_data)} 条记录", "进度: 完成")
            
            # 保存到持久化存储（包含下载状态）- 只在第一次保存时输出日志
            self.save_to_persistent_file(silent=False)
            
            # 计算总页数并更新分页控件
            self.calculate_pagination()
            self.update_pagination_controls()
            self.display_current_page()
            
            # 显示提取统计信息
            platform_name = self._detect_batch_platform_from_url(self.input_box_batch.text())
            
            QMessageBox.information(
                self, 
                "提取完成", 
                f"批量提取完成！\n"
                f"平台: {platform_name}\n"
                f"原始链接: {self.input_box_batch.text().strip()}\n"
                f"画质: 最佳画质\n"
                f"共提取 {len(extracted_data)} 条记录。\n\n"
                f"结果已显示在表格中，可以点击'保存结果'按钮保存未下载的链接到TXT文件。"
            )
        else:
            if self.log_handler:
                self.log_handler.log("批量提取完成，但未提取到任何记录")
            self.signals.update_stats_signal.emit("提取完成: 0 条记录", "进度: 完成")
            
    # ---------- 持久化存储相关方法 ----------
    
    def save_to_persistent_file(self, silent=True):
        """保存提取数据到持久化文件 - 包含下载状态
        
        Args:
            silent: 是否静默保存（不输出日志），默认True
        """
        try:
            if not self.extracted_data:
                return
                
            # 保存所有数据（包括下载状态和进度）
            data_to_save = []
            for item in self.extracted_data:
                data_to_save.append({
                    "url": item.get("url", ""),
                    "title": item.get("title", ""),
                    "status": item.get("status", "未下载"),
                    "progress": item.get("progress", 0),
                    "result": item.get("result", "")
                })
            
            # 保存提取设置和输入链接
            persistent_data = {
                "extracted_data": data_to_save,
                "input_url": self.input_box_batch.text().strip(),
                "platform": self.selected_platform,
                "extract_time": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                "download_stats": self.get_download_stats()  # 保存下载统计
            }
            
            with open(self.persistence_file, 'w', encoding='utf-8') as f:
                json.dump(persistent_data, f, ensure_ascii=False, indent=2)
            
            # 只在第一次保存时输出日志，或者当silent=False时输出
            if not silent and not self.persistent_save_logged:
                if self.log_handler:
                    self.log_handler.log(f"已保存提取数据到持久化文件")
                self.persistent_save_logged = True
            elif silent:
                # 静默保存，不输出日志
                pass
            else:
                # 已经输出过日志，不再输出
                pass
            
        except Exception as e:
            # 错误日志始终输出
            if self.log_handler:
                self.log_handler.log(f"保存持久化数据失败: {e}")
    
    def get_download_stats(self):
        """获取下载统计信息"""
        if not self.extracted_data:
            return {"total": 0, "completed": 0, "failed": 0, "pending": 0}
        
        total = len(self.extracted_data)
        completed = sum(1 for item in self.extracted_data if item.get("status") == "完成")
        failed = sum(1 for item in self.extracted_data if item.get("status") == "失败")
        pending = total - completed - failed
        
        return {
            "total": total,
            "completed": completed,
            "failed": failed,
            "pending": pending
        }
    
    def load_last_extract_results(self):
        """加载上次提取的结果 - 包含下载状态和进度"""
        try:
            if os.path.exists(self.persistence_file):
                with open(self.persistence_file, 'r', encoding='utf-8') as f:
                    persistent_data = json.load(f)
                
                if "extracted_data" in persistent_data:
                    # 恢复数据，包含下载状态、进度和结果
                    self.extracted_data = []
                    for item in persistent_data["extracted_data"]:
                        self.extracted_data.append({
                            "url": item.get("url", ""),
                            "title": item.get("title", ""),
                            "download": "",  # 下载按钮
                            "status": item.get("status", "未下载"),  # 恢复保存的状态
                            "progress": item.get("progress", 0),  # 恢复保存的进度
                            "result": item.get("result", "")  # 恢复保存的结果
                        })
                    
                    # 恢复输入链接和平台
                    if "input_url" in persistent_data:
                        self.input_box_batch.setText(persistent_data["input_url"])
                    
                    if "platform" in persistent_data:
                        self.selected_platform = persistent_data["platform"]
                        self.platform_combo_batch.setCurrentText(self.selected_platform)
                    
                    # 更新UI
                    if self.extracted_data:
                        # 获取下载统计
                        stats = self.get_download_stats()
                        download_stats_text = f"（已下载: {stats['completed']}, 失败: {stats['failed']}, 待下载: {stats['pending']}）"
                        
                        if self.log_handler:
                            self.log_handler.log(f"已加载上次提取的 {len(self.extracted_data)} 条记录 {download_stats_text}")
                        
                        # 计算总页数并更新分页控件
                        self.calculate_pagination()
                        self.update_pagination_controls()
                        self.display_current_page()
                        
                        # 更新统计信息
                        extract_time = persistent_data.get("extract_time", "未知时间")
                        self.signals.update_stats_signal.emit(
                            f"已加载上次提取结果 ({extract_time}) {download_stats_text}",
                            f"总记录: {len(self.extracted_data)} 条 | 画质: 最佳画质"
                        )
                        
                        # 重置持久化文件保存标志，因为已经加载了数据
                        self.persistent_save_logged = True
                        
        except Exception as e:
            print(f"加载持久化数据失败: {e}")
    
    def delete_persistent_data(self):
        """删除持久化存储文件"""
        try:
            if os.path.exists(self.persistence_file):
                os.remove(self.persistence_file)
                if self.log_handler:
                    self.log_handler.log("已删除持久化存储文件")
        except Exception as e:
            print(f"删除持久化文件失败: {e}")
            
    # ---------- 分页功能相关方法 ----------
    
    def calculate_pagination(self):
        """计算分页信息"""
        self.items_per_page = self.spin_items_per_page.value()
        total_items = len(self.extracted_data)
        
        if total_items == 0:
            self.total_pages = 1
        else:
            self.total_pages = (total_items + self.items_per_page - 1) // self.items_per_page
        
        # 确保当前页码在有效范围内
        if self.current_page > self.total_pages:
            self.current_page = max(1, self.total_pages)
    
    def update_pagination_controls(self):
        """更新分页控件状态"""
        # 更新页码信息
        page_info = f"第 {self.current_page} 页 / 共 {self.total_pages} 页"
        self.signals.update_page_info_signal.emit(page_info)
        
        # 更新跳转页码输入框范围
        self.spin_go_to_page.setMaximum(self.total_pages)
        self.spin_go_to_page.setValue(self.current_page)
        
        # 更新按钮状态
        self.btn_prev_page.setEnabled(self.current_page > 1)
        self.btn_next_page.setEnabled(self.current_page < self.total_pages)
        self.btn_go_to_page.setEnabled(self.total_pages > 1)
        
        # 获取下载统计
        stats = self.get_download_stats()
        
        # 更新分页统计信息
        if self.extracted_data:
            start_idx = (self.current_page - 1) * self.items_per_page
            end_idx = min(start_idx + self.items_per_page, len(self.extracted_data))
            
            # 计算当前页的下载统计
            page_items = self.extracted_data[start_idx:end_idx]
            page_completed = sum(1 for item in page_items if item.get("status") == "完成")
            page_failed = sum(1 for item in page_items if item.get("status") == "失败")
            page_pending = len(page_items) - page_completed - page_failed
            
            self.signals.update_stats_signal.emit(
                f"共提取: {len(self.extracted_data)} 条记录 (显示 {start_idx+1}-{end_idx} 条) | 本页: 完成 {page_completed}, 失败 {page_failed}, 待下载 {page_pending}",
                f"页码: {self.current_page}/{self.total_pages} | 总完成: {stats['completed']}/{stats['total']} | 画质: 最佳画质"
            )
    
    def display_current_page(self):
        """显示当前页的数据 - 深色主题，文字用白色，完整显示链接"""
        try:
            self.table_results.setRowCount(0)
            
            if not self.extracted_data:
                return
            
            # 计算当前页的起始和结束索引
            start_idx = (self.current_page - 1) * self.items_per_page
            end_idx = min(start_idx + self.items_per_page, len(self.extracted_data))
            
            # 显示当前页的数据
            for i in range(start_idx, end_idx):
                row = self.table_results.rowCount()
                self.table_results.insertRow(row)
                # 设置行高以适应完整链接
                self.table_results.setRowHeight(row, 45)  # 增加行高以显示完整链接
                
                # 获取当前数据项
                item_data = self.extracted_data[i]
                
                # 列索引定义
                URL_COL = 0
                TITLE_COL = 1
                DOWNLOAD_COL = 2
                STATUS_COL = 3
                PROGRESS_COL = 4  # 新增：进度列
                RESULT_COL = 5
                
                # 1. 链接列 - 显示完整URL，居中对齐，蓝色文字
                url = item_data.get("url", "")
                url_item = QTableWidgetItem(url)  # 显示完整URL
                url_item.setData(Qt.UserRole, url)  # 保存完整URL到用户数据（与显示相同）
                url_item.setForeground(QBrush(QColor("#4dabf7")))  # 蓝色文字
                url_item.setToolTip(f"点击右键菜单复制完整链接\n或双击单元格复制")
                url_item.setFlags(url_item.flags() | Qt.ItemIsSelectable | Qt.ItemIsEnabled)  # 允许选择和复制
                url_item.setTextAlignment(Qt.AlignCenter)  # 居中对齐
                url_item.setFont(QFont("Arial", 9))  # 使用较小字体以适应完整URL
                self.table_results.setItem(row, URL_COL, url_item)
                
                # 2. 标题列 - 显示完整标题，居中对齐，白色文字
                title = item_data.get("title", "")
                title_item = QTableWidgetItem(title)  # 显示完整标题
                title_item.setData(Qt.UserRole, title)  # 保存完整标题到用户数据（与显示相同）
                title_item.setForeground(QBrush(QColor("#0F172A")))
                title_item.setToolTip(f"点击右键菜单复制完整标题\n或双击单元格复制")
                title_item.setFlags(title_item.flags() | Qt.ItemIsSelectable | Qt.ItemIsEnabled)  # 允许选择和复制
                title_item.setTextAlignment(Qt.AlignCenter)  # 居中对齐
                title_item.setFont(QFont("Arial", 9))  # 使用较小字体以适应完整标题
                self.table_results.setItem(row, TITLE_COL, title_item)
                
                # 3. 下载列（按钮）- 居中对齐
                status = item_data.get("status", "未下载")
                
                # 创建居中对齐的按钮小部件
                centered_button = CenteredButtonWidget()
                
                # 根据当前状态设置按钮样式和文本
                if status == "下载中":
                    centered_button.set_button_style("""
                        background-color: #ffc107;  /* 黄色背景 */
                        color: black;  /* 黑色文字 */
                    """)
                    centered_button.set_text("下载中")
                    centered_button.set_enabled(False)
                elif status == "完成":
                    centered_button.set_button_style("""
                        background-color: #28a745;  /* 绿色背景 */
                        color: white;  /* 白色文字 */
                    """)
                    centered_button.set_text("已下载")
                    centered_button.set_enabled(False)
                elif status == "失败":
                    centered_button.set_button_style("""
                        background-color: #dc3545;  /* 红色背景 */
                        color: white;  /* 白色文字 */
                    """)
                    centered_button.set_text("重试")
                    centered_button.set_enabled(True)
                else:  # 未下载
                    centered_button.set_button_style("""
                        background-color: #2E7892;
                        color: white;  /* 白色文字 */
                    """)
                    centered_button.set_text("下载")
                    centered_button.set_enabled(True)
                
                # 设置按钮点击事件
                if status == "未下载" or status == "失败":
                    centered_button.connect_clicked(lambda checked, url=url: self.download_single_video(url))
                
                self.table_results.setCellWidget(row, DOWNLOAD_COL, centered_button)
                
                # 4. 状态列 - 居中对齐，白色文字
                status = item_data.get("status", "未下载")
                status_item = QTableWidgetItem(status)
                status_item.setFlags(status_item.flags() | Qt.ItemIsSelectable | Qt.ItemIsEnabled)  # 允许选择和复制
                status_item.setTextAlignment(Qt.AlignCenter)  # 居中对齐
                
                # 根据状态设置颜色
                if status == "下载中":
                    status_item.setForeground(QBrush(QColor("#ffc107")))  # 黄色文字
                elif status == "完成":
                    status_item.setForeground(QBrush(QColor("#28a745")))  # 绿色文字
                elif status == "失败":
                    status_item.setForeground(QBrush(QColor("#dc3545")))  # 红色文字
                else:
                    status_item.setForeground(QBrush(QColor("#0F172A")))
                
                self.table_results.setItem(row, STATUS_COL, status_item)
                
                # 5. 进度列 - 新增：显示进度条
                progress = item_data.get("progress", 0)
                progress_bar = TableProgressBar()
                progress_bar.setValue(progress)
                
                # 根据状态和进度设置进度条样式
                if status == "下载中":
                    if progress == 0:
                        # 设置忙碌状态的进度条
                        progress_bar.setRange(0, 0)  # 这会显示一个忙碌的进度条
                        progress_bar.setFormat("等待中...")
                    else:
                        progress_bar.setRange(0, 100)
                        progress_bar.setValue(progress)
                        progress_bar.setFormat(f"{progress}%")
                        progress_bar.set_progress_color("#ffc107")  # 黄色
                elif status == "完成":
                    progress_bar.setRange(0, 100)
                    progress_bar.setValue(100)
                    progress_bar.setFormat("100%")
                    progress_bar.set_progress_color("#28a745")  # 绿色
                elif status == "失败":
                    progress_bar.setRange(0, 100)
                    progress_bar.setValue(0)
                    progress_bar.setFormat("0%")
                    progress_bar.set_progress_color("#dc3545")  # 红色
                else:  # 未下载
                    progress_bar.setRange(0, 100)
                    progress_bar.setValue(0)
                    progress_bar.setFormat("0%")
                    progress_bar.set_progress_color("#6c757d")  # 灰色
                
                self.table_results.setCellWidget(row, PROGRESS_COL, progress_bar)
                
                # 6. 结果列 - 居中对齐，白色文字
                result = item_data.get("result", "")
                result_item = QTableWidgetItem(result)  # 显示完整结果
                result_item.setData(Qt.UserRole, result)  # 保存完整结果到用户数据
                result_item.setFlags(result_item.flags() | Qt.ItemIsSelectable | Qt.ItemIsEnabled)  # 允许选择和复制
                result_item.setTextAlignment(Qt.AlignCenter)  # 居中对齐
                result_item.setFont(QFont("Arial", 9))  # 使用较小字体
                
                # 根据结果设置颜色
                if "成功" in result or "完成" in result:
                    result_item.setForeground(QBrush(QColor("#28a745")))  # 绿色文字
                elif "失败" in result or "异常" in result:
                    result_item.setForeground(QBrush(QColor("#dc3545")))  # 红色文字
                elif "下载中" in result or "等待" in result:
                    result_item.setForeground(QBrush(QColor("#ffc107")))  # 黄色文字
                else:
                    result_item.setForeground(QBrush(QColor("#0F172A")))
                
                result_item.setToolTip(f"完整结果: {result}")
                self.table_results.setItem(row, RESULT_COL, result_item)
            

            
        except Exception as e:
            print(f"显示分页数据失败: {e}")
    
    def download_single_video(self, url):
        """下载单个视频 - 固定为视频下载"""
        if not url:
            if self.log_handler:
                self.log_handler.log("无效的链接")
            return
        
        # 检查授权
        if self.main_window and not getattr(self.main_window, 'authorized', False):
            reply = QMessageBox.question(
                self, "授权提示",
                "当前未授权或试用已过期。\n是否跳转到官网获取正式授权？",
                QMessageBox.Yes | QMessageBox.No,
                QMessageBox.Yes
            )
            if reply == QMessageBox.Yes:
                if self.main_window:
                    self.main_window.open_website()
            return
        
        # 检查工具
        if not os.path.exists(self.yt_dlp_path):
            if self.log_handler:
                self.log_handler.log("找不到 yt-dlp.exe，请先更新插件")
            QMessageBox.warning(self, "工具缺失", "找不到 yt-dlp.exe，请先点击'更新插件'按钮下载")
            return
            
        if not os.path.exists(self.ffmpeg_path):
            if self.log_handler:
                self.log_handler.log("ffmpeg.exe 缺失，无法处理视频合成")
            QMessageBox.warning(self, "工具缺失", "ffmpeg.exe 缺失，请确保ffmpeg工具包在程序目录中")
            return

        if (
            self.main_window
            and getattr(self.main_window, 'auth_mode', '') == "account"
            and hasattr(self.main_window, '_consume_account_download_quota')
        ):
            if not self.main_window._consume_account_download_quota(1, "批量提取页单条下载"):
                return
        
        # 获取保存路径
        save_path = self.config.get("download_path", "")
        if not save_path or not os.path.exists(save_path):
            save_path = QFileDialog.getExistingDirectory(self, "选择保存目录")
            if not save_path:
                if self.log_handler:
                    self.log_handler.log("未选择保存目录，取消下载")
                return
        
        # 创建下载器核心
        try:
            from videodown import VideoDownloaderCore
            
            # 创建下载器核心 - 固定为视频下载
            downloader_core = VideoDownloaderCore(
                yt_dlp_path=self.yt_dlp_path,
                ffmpeg_path=self.ffmpeg_path,
                deno_path=self.deno_path,
                config=self.config.config,
                signals=None,  # 不传递信号，直接使用回调
                log_handler=self.log_handler,  # 使用统一的日志处理器
                update_progress_callback=None,
                check_completion_callback=None,
                update_task_stats_callback=None,
                cookie_status=getattr(self.main_window, 'cookie_status', {}),
                enable_deno=getattr(self.main_window, 'enable_deno', bool(self.deno_path and os.path.exists(self.deno_path)))
            )
            downloader_core.cookie_file = self.cookie_file
            downloader_core.instagram_cookie_file = self.instagram_cookie_file
            downloader_core.tiktok_cookie_file = self.tiktok_cookie_file
            downloader_core.twitter_cookie_file = self.twitter_cookie_file
            downloader_core.deno_path = self.deno_path
            downloader_core.enable_deno = bool(
                getattr(self.main_window, 'enable_deno', bool(self.deno_path and os.path.exists(self.deno_path)))
                and self.deno_path
                and os.path.exists(self.deno_path)
            )
            
            # 创建下载线程 - 固定为视频下载
            if self.main_window and hasattr(self.main_window, "_create_video_downloader_core"):
                downloader_core = self.main_window._create_video_downloader_core(
                    signals=None,
                    log_handler=self.log_handler,
                )

            download_thread = SingleDownloadThread(self, self.log_handler)
            download_thread.set_params(url, save_path, downloader_core)
            download_thread.download_complete_signal.connect(self.on_single_download_complete)
            download_thread.update_download_status_signal.connect(self.signals.update_download_status_signal.emit)
            
            # 存储线程引用
            self.download_threads.append(download_thread)
            
            # 启动下载线程
            download_thread.start()
            
        except Exception as e:
            timestamp = datetime.now().strftime("%H:%M:%S")
            if self.log_handler:
                self.log_handler.log(f"[{timestamp}] 创建下载器失败: {str(e)}")
        
    def on_single_download_complete(self, url, success):
        """单个下载完成处理"""
        timestamp = datetime.now().strftime("%H:%M:%S")
        if success:
            if self.log_handler:
                self.log_handler.log(f"[{timestamp}] 下载完成: {url}")
            if self.main_window and hasattr(self.main_window, "_find_latest_media_file"):
                latest_file = self.main_window._find_latest_media_file(self.config.get("download_path", ""))
                if latest_file and hasattr(self.main_window, "_set_latest_preview_file"):
                    self.main_window._set_latest_preview_file(latest_file)
        else:
            if self.log_handler:
                self.log_handler.log(f"[{timestamp}] 下载失败: {url}")
        
        # 从线程列表中移除已完成的线程
        for thread in self.download_threads[:]:
            if not thread.isRunning():
                self.download_threads.remove(thread)

        if self.main_window and hasattr(self.main_window, "update_cookie_status_display"):
            self.main_window.update_cookie_status_display()
        if not success and self.main_window and hasattr(self.main_window, "_notify_invalid_youtube_cookie_if_needed"):
            self.main_window._notify_invalid_youtube_cookie_if_needed()

    def set_preview_file(self, file_path):
        self.latest_preview_file = file_path or ""
        if hasattr(self, "btn_preview_extract"):
            self.btn_preview_extract.setEnabled(bool(self.latest_preview_file and os.path.exists(self.latest_preview_file)))
            if self.latest_preview_file:
                self.btn_preview_extract.setToolTip(f"预览：{os.path.basename(self.latest_preview_file)}")
            else:
                self.btn_preview_extract.setToolTip("预览最近下载完成的视频")

    def clear_preview_file(self):
        self.set_preview_file("")

    def _on_preview(self):
        if not self.latest_preview_file:
            QMessageBox.information(self, "预览播放", "还没有可预览的视频，请先完成一次视频下载。")
            return
        if self.main_window and hasattr(self.main_window, "open_video_preview"):
            self.main_window.open_video_preview(self.latest_preview_file)
            return
        QMessageBox.warning(self, "预览播放", "当前窗口未连接播放服务。")
    
    def go_to_previous_page(self):
        """跳转到上一页"""
        if self.current_page > 1:
            self.current_page -= 1
            self.display_current_page()
            self.update_pagination_controls()
            if self.log_handler:
                self.log_handler.log(f"跳转到上一页: {self.current_page}/{self.total_pages}")
    
    def go_to_next_page(self):
        """跳转到下一页"""
        if self.current_page < self.total_pages:
            self.current_page += 1
            self.display_current_page()
            self.update_pagination_controls()
            if self.log_handler:
                self.log_handler.log(f"跳转到下一页: {self.current_page}/{self.total_pages}")
    
    def go_to_specific_page(self):
        """跳转到指定页码"""
        target_page = self.spin_go_to_page.value()
        if 1 <= target_page <= self.total_pages and target_page != self.current_page:
            self.current_page = target_page
            self.display_current_page()
            self.update_pagination_controls()
            if self.log_handler:
                self.log_handler.log(f"跳转到第 {target_page} 页")
    
    def on_items_per_page_changed(self, value):
        """每页显示数量更改"""
        self.items_per_page = value
        if self.extracted_data:
            self.calculate_pagination()
            self.display_current_page()
            self.update_pagination_controls()
            if self.log_handler:
                self.log_handler.log(f"每页显示数量更改为: {value}")
    
    def set_cookie_files(self, cookie_file, instagram_cookie_file, tiktok_cookie_file, twitter_cookie_file=""):
        """设置Cookie文件路径"""
        self.cookie_file = cookie_file
        self.instagram_cookie_file = instagram_cookie_file
        self.tiktok_cookie_file = tiktok_cookie_file
        self.twitter_cookie_file = twitter_cookie_file
        # 更新配置对象中的Cookie文件路径，确保下载时使用正确的路径
        if self.config:
            if hasattr(self.config, "set"):
                self.config.set("cookie_file", cookie_file)
                self.config.set("cookie_instagram", instagram_cookie_file)
                self.config.set("cookie_tiktok", tiktok_cookie_file)
                self.config.set("cookie_twitter", twitter_cookie_file)
            elif hasattr(self.config, "config"):
                self.config.config["cookie_file"] = cookie_file
                self.config.config["cookie_instagram"] = instagram_cookie_file
                self.config.config["cookie_tiktok"] = tiktok_cookie_file
                self.config.config["cookie_twitter"] = twitter_cookie_file

    def update_cookie_status_display(self):
        """Delegate batch-page cookie rendering to the main window."""
        if not self.main_window:
            self.cookie_status_label_batch.setText("Cookie状态: 未连接主窗口")
            self.cookie_status_label_batch.setStyleSheet("font-size: 11px; margin-top: 2px; color: #9ca3af;")
            return

        try:
            if hasattr(self.main_window, "update_cookie_status_display"):
                self.main_window.update_cookie_status_display()
            else:
                self.cookie_status_label_batch.setText("Cookie状态: 未知")
                self.cookie_status_label_batch.setStyleSheet("font-size: 11px; margin-top: 2px; color: #9ca3af;")
        except Exception:
            self.cookie_status_label_batch.setText("Cookie状态: 未知")
            self.cookie_status_label_batch.setStyleSheet("font-size: 11px; margin-top: 2px; color: #9ca3af;")
        # 不在这里更新Cookie状态显示，改为从主窗口获取状态
