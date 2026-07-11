# -*- coding: utf-8 -*-
"""
共享 UI 组件小工具。
"""

from PySide6.QtCore import Qt
from PySide6.QtWidgets import QLabel, QPushButton, QProgressBar


class UIComponents:
    """统一的轻量 UI 组件工厂。"""

    @staticmethod
    def create_button(text, height=27, min_width=80, tooltip=None):
        button = QPushButton(text)
        button.setFocusPolicy(Qt.NoFocus)
        button.setFixedHeight(height)
        button.setMinimumWidth(min_width)
        if tooltip:
            button.setToolTip(tooltip)
        return button

    @staticmethod
    def create_label(text, style=None):
        label = QLabel(text)
        if style:
            label.setStyleSheet(style)
        return label

    @staticmethod
    def create_progress_bar(height=20, animated=False, animated_progress_class=None):
        if animated and animated_progress_class is not None:
            return animated_progress_class()
        bar = QProgressBar()
        bar.setFixedHeight(height)
        return bar


def create_button(text, height=27, min_width=80, tooltip=None):
    return UIComponents.create_button(text, height, min_width, tooltip)


def create_label(text, style=None):
    return UIComponents.create_label(text, style)
