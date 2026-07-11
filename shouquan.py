# -*- coding: utf-8 -*-
"""授权、账号登录、机器码与本地缓存模块。"""

import hashlib
import json
import os
import platform
import sys
import uuid
from datetime import datetime, timedelta

import requests

from app_config import get_api_url

try:
    import wmi
    import winreg
except ImportError:
    wmi = None
    winreg = None


if getattr(sys, "frozen", False):
    BASE_DIR = os.path.dirname(sys.executable)
else:
    BASE_DIR = os.path.abspath(os.path.dirname(__file__))

API_URL = get_api_url()
LICENSE_FILE = os.path.join(BASE_DIR, "license.json")
AUTH_CACHE_FILE = os.path.join(BASE_DIR, "auth_cache.json")

ACCOUNT_API_MESSAGE_MAP = {
    "email_or_phone_required": "请输入邮箱或手机号",
    "register_type_required": "请选择邮箱注册或手机注册",
    "email_required": "请输入邮箱地址",
    "phone_required": "请输入手机号",
    "invalid_register_type": "注册方式错误，请选择邮箱注册或手机注册",
    "password_too_short": "密码至少需要 6 位",
    "invalid_email": "邮箱格式不正确",
    "invalid_phone": "手机号格式不正确",
    "account_exists": "该账号已注册，请直接登录",
    "register_failed": "注册失败，请检查服务端数据库配置",
    "missing_login_params": "登录参数不完整",
    "account_not_found": "账号不存在",
    "invalid_password": "密码错误",
    "account_disabled": "账号已被禁用",
    "subscription_expired": "订阅已过期",
    "device_limit_reached": "已达到设备数量上限",
    "invalid_token": "登录状态已失效，请重新登录",
    "device_mismatch": "当前设备已切换到其他账号，请重新登录",
    "session_expired": "登录状态已过期，请重新登录",
    "missing_token_or_machine": "登录状态缺失，请重新登录",
    "quota_exceeded": "今日下载次数已用完",
    "heartbeat_disabled": "心跳接口已禁用",
}

ACCOUNT_API_OK_STATUSES = {
    "ok",
    "success",
    "login_success",
    "register_success",
    "validated",
    "active",
    "1",
    "true",
}

ACCOUNT_TOKEN_FIELDS = ("token", "session_token", "auth_token", "access_token")


def _first_present(data, keys, default=""):
    if not isinstance(data, dict):
        return default
    for key in keys:
        value = data.get(key)
        if value not in (None, ""):
            return value
    return default


def init_wmi():
    if platform.system() != "Windows" or wmi is None:
        return None
    try:
        return wmi.WMI()
    except Exception:
        return None


def get_motherboard_bios_sn(wmi_obj):
    if not wmi_obj:
        return ""

    serial_number = ""
    for _ in range(3):
        try:
            for board in wmi_obj.Win32_BaseBoard():
                sn = board.SerialNumber.strip() if board.SerialNumber else ""
                if sn and sn not in ["To be filled by O.E.M.", "N/A", ""]:
                    serial_number = sn
                    break
            if serial_number:
                break
        except Exception:
            continue

    if serial_number:
        return serial_number

    for _ in range(3):
        try:
            for bios in wmi_obj.Win32_BIOS():
                sn = bios.SerialNumber.strip() if bios.SerialNumber else ""
                if sn and sn not in ["To be filled by O.E.M.", "N/A", ""]:
                    serial_number = sn
                    break
            if serial_number:
                break
        except Exception:
            continue

    return serial_number


def get_cpu_disk_sn(wmi_obj):
    if not wmi_obj:
        return "", ""

    cpu_id = ""
    for _ in range(3):
        try:
            for cpu in wmi_obj.Win32_Processor():
                pid = cpu.ProcessorId.strip() if cpu.ProcessorId else ""
                if pid:
                    cpu_id = pid
                    break
            if cpu_id:
                break
        except Exception:
            continue

    disk_sn = ""
    for _ in range(3):
        try:
            for disk in wmi_obj.Win32_DiskDrive():
                if disk.MediaType == "Fixed hard disk media":
                    sn = disk.SerialNumber.strip() if disk.SerialNumber else ""
                    if sn and sn not in ["N/A", ""]:
                        disk_sn = sn
                        break
            if disk_sn:
                break
        except Exception:
            continue

    return cpu_id, disk_sn


def get_system_guid():
    if platform.system() != "Windows" or winreg is None:
        return ""

    try:
        key = winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r"SOFTWARE\Microsoft\Cryptography")
        guid, _ = winreg.QueryValueEx(key, "MachineGuid")
        winreg.CloseKey(key)
        return guid.strip() if guid else ""
    except Exception:
        return ""


def get_physical_mac(wmi_obj):
    if not wmi_obj:
        return ""

    mac_list = []
    virtual_keywords = ["VMware", "VirtualBox", "Hyper-V", "Bluetooth", "Microsoft", "USB"]
    try:
        for nic in wmi_obj.Win32_NetworkAdapter():
            if not nic.MACAddress:
                continue

            mac = nic.MACAddress.strip().replace(":", "").replace("-", "")
            if not mac:
                continue

            manufacturer = nic.Manufacturer.strip() if nic.Manufacturer else ""
            if any(keyword in manufacturer for keyword in virtual_keywords):
                continue

            mac_list.append(mac)
    except Exception:
        pass

    return mac_list[0] if mac_list else ""


def clean_feature_str(raw_str):
    clean_str = "".join(char for char in raw_str if char.isalnum()).upper()
    return clean_str or "WINDOWS_STABLE_HW_ID"


def _hash_machine_feature(raw_str):
    clean_str = clean_feature_str(raw_str)
    return hashlib.sha256(clean_str.encode("utf-8")).hexdigest().upper()


def get_machine_code_candidates():
    candidates = []

    if platform.system() != "Windows":
        base = platform.node() + platform.system() + platform.processor() + str(uuid.getnode())
        return [_hash_machine_feature(base)]

    wmi_obj = init_wmi()
    fallback_mac = f"{uuid.getnode():012X}"

    guid = get_system_guid()
    physical_mac = get_physical_mac(wmi_obj)
    if guid:
        candidates.append(_hash_machine_feature(f"{guid}_"))
        if physical_mac:
            candidates.append(_hash_machine_feature(f"{guid}_{physical_mac}"))
        candidates.append(_hash_machine_feature(f"{guid}_{fallback_mac}"))

    level1 = get_motherboard_bios_sn(wmi_obj)
    if level1:
        candidates.append(_hash_machine_feature(level1))

    cpu_id, disk_sn = get_cpu_disk_sn(wmi_obj)
    if cpu_id or disk_sn:
        candidates.append(_hash_machine_feature(f"{cpu_id}_{disk_sn}"))

    candidates.append(_hash_machine_feature(f"{platform.node()}_{platform.processor()}_{fallback_mac}"))

    unique_candidates = []
    for candidate in candidates:
        if candidate and candidate not in unique_candidates:
            unique_candidates.append(candidate)
    return unique_candidates


def get_machine_code():
    return get_machine_code_candidates()[0]


def load_auth_data():
    try:
        if os.path.exists(LICENSE_FILE):
            with open(LICENSE_FILE, "r", encoding="utf-8") as file:
                data = json.load(file)
                if isinstance(data, dict):
                    return data
    except Exception:
        return {}
    return {}


def save_auth_data(data):
    if not isinstance(data, dict):
        return False
    try:
        with open(LICENSE_FILE, "w", encoding="utf-8") as file:
            json.dump(data, file, ensure_ascii=False, indent=2)
        return True
    except Exception:
        return False


def clear_auth_data():
    try:
        if os.path.exists(LICENSE_FILE):
            os.remove(LICENSE_FILE)
    except Exception:
        pass


def load_license_key():
    data = load_auth_data()
    if data.get("license_key"):
        return data.get("license_key")
    if data.get("auth_mode") == "account":
        return data.get("email") or data.get("phone") or ""
    return None


def save_license_key(key):
    payload = {"auth_mode": "license", "license_key": key}
    return save_auth_data(payload)


def get_saved_account_token():
    data = load_auth_data()
    if data.get("auth_mode") == "account":
        return data.get("token", "")
    return ""


def save_account_session(session_data):
    session_data = _normalize_account_api_data(session_data)
    current_data = load_auth_data()
    current_token = current_data.get("token", "") if current_data.get("auth_mode") == "account" else ""
    payload = {
        "auth_mode": "account",
        "token": _first_present(session_data, ACCOUNT_TOKEN_FIELDS, current_token),
        "email": session_data.get("email") or current_data.get("email", ""),
        "phone": session_data.get("phone") or current_data.get("phone", ""),
        "display_name": session_data.get("display_name") or current_data.get("display_name", ""),
        "account_level": session_data.get("account_level") or current_data.get("account_level", "free"),
        "account_level_label": session_data.get("account_level_label") or current_data.get("account_level_label", "免费订阅"),
        "max_devices": session_data.get("max_devices", current_data.get("max_devices", 1)),
        "free_daily_limit": session_data.get("free_daily_limit", current_data.get("free_daily_limit", 3)),
        "today_download_count": session_data.get("today_download_count", current_data.get("today_download_count", 0)),
        "today_download_remaining": session_data.get("today_download_remaining", current_data.get("today_download_remaining", 3)),
        "expire_date": session_data.get("expire_date") or current_data.get("expire_date", ""),
    }
    return save_auth_data(payload)


def _create_requests_session():
    session = requests.Session()
    # 允许读取系统代理 / VPN 环境，避免浏览器能访问但软件请求不走代理。
    session.trust_env = True
    session.headers.update({
        "User-Agent": "Vidoon/2026 Windows Client",
        "Accept": "application/json",
        "Content-Type": "application/json; charset=utf-8",
    })
    return session


def _post_api(payload, timeout=20):
    try:
        with _create_requests_session() as session:
            response = session.post(API_URL, json=payload, timeout=timeout)

        response.raise_for_status()

        raw_text = response.text or ""
        if not raw_text.strip():
            raise RuntimeError("服务器返回空内容，可能被防火墙、CDN、WAF 或网关拦截")

        try:
            data = response.json()
        except ValueError as exc:
            preview = raw_text.strip().replace("\r", " ").replace("\n", " ")[:300]
            raise RuntimeError(f"服务器返回的不是 JSON，可能被 WAF/CDN/防火墙拦截：{preview or '<empty>'}") from exc

        if not isinstance(data, dict):
            raise RuntimeError("服务器返回格式异常")

        return data

    except requests.exceptions.ConnectTimeout as exc:
        raise RuntimeError("连接授权服务器超时，请检查 VPN、代理或网络线路") from exc
    except requests.exceptions.ReadTimeout as exc:
        raise RuntimeError("授权服务器响应超时，请稍后重试或切换网络") from exc
    except requests.exceptions.SSLError as exc:
        raise RuntimeError(f"SSL 证书或代理握手失败：{exc}") from exc
    except requests.exceptions.ProxyError as exc:
        raise RuntimeError(f"代理连接失败，请关闭或更换 VPN/代理：{exc}") from exc
    except requests.exceptions.ConnectionError as exc:
        raise RuntimeError(f"无法连接授权服务器，可能被网络、防火墙或 VPN 拦截：{exc}") from exc

def _normalize_account_api_data(data):
    if not isinstance(data, dict):
        return {}

    normalized = dict(data)
    nested_sources = []
    for nested_key in ("data", "account", "user"):
        nested_value = normalized.get(nested_key)
        if isinstance(nested_value, dict):
            nested_sources.append(nested_value)

    for nested_value in nested_sources:
        for key, value in nested_value.items():
            normalized.setdefault(key, value)

    if not normalized.get("token"):
        token_value = _first_present(normalized, ACCOUNT_TOKEN_FIELDS)
        if token_value:
            normalized["token"] = token_value

    status = normalized.get("status")
    valid = normalized.get("valid")
    status_text = str(status or "").strip().lower()
    message_text = str(normalized.get("msg", "") or "").strip().lower()

    if valid is True and status in (1, "1", True, "", None):
        normalized["status"] = "ok"
    elif status in (1, True):
        normalized["status"] = "ok"
    elif status_text in ACCOUNT_API_OK_STATUSES:
        normalized["status"] = "ok"
    elif valid is False and status in (1, "1", True):
        normalized["status"] = "error"

    normalized_status_text = str(normalized.get("status", "") or "").strip().lower()
    if "valid" not in normalized and normalized_status_text == "ok":
        normalized["valid"] = True
    elif "valid" not in normalized and message_text in ACCOUNT_API_OK_STATUSES:
        normalized["valid"] = True

    if not normalized.get("account_level_label"):
        level = normalized.get("account_level", "free")
        level_map = {
            "free": "免费订阅",
            "monthly": "月订阅",
            "semiannual": "半年订阅",
            "annual": "年订阅",
        }
        normalized["account_level_label"] = level_map.get(level, "免费订阅")

    status_message_map = {
        "device_mismatch": "该设备已切换到其他账号登录",
    }
    normalized_status = normalized.get("status")
    if normalized_status in status_message_map:
        raw_msg = str(normalized.get("msg", "") or "").strip()
        if raw_msg in ("", "device_mismatch"):
            normalized["msg"] = status_message_map[normalized_status]

    return normalized


def _build_account_identifier_payload(identifier, include_register_type=False):
    normalized_identifier = (identifier or "").strip()
    payload = {"identifier": normalized_identifier}

    if "@" in normalized_identifier:
        payload["email"] = normalized_identifier
        if include_register_type:
            payload["register_type"] = "email"
    else:
        payload["phone"] = normalized_identifier
        if include_register_type:
            payload["register_type"] = "phone"

    return payload


def _extract_account_api_message(data, fallback="请求失败"):
    if not isinstance(data, dict):
        return fallback

    raw_msg = str(data.get("msg", "") or "").strip()
    status = str(data.get("status", "") or "").strip()
    db_error = str(data.get("db_error", "") or "").strip()

    mapped_msg = ACCOUNT_API_MESSAGE_MAP.get(raw_msg) or ACCOUNT_API_MESSAGE_MAP.get(status)
    final_msg = mapped_msg or raw_msg or fallback

    if db_error:
        final_msg = f"{final_msg}\n数据库详情：{db_error}"

    return final_msg


def verify_license_with_server(key, timeout=20):
    try:
        last_result = (False, "", "授权校验失败", "error")

        for machine_code in get_machine_code_candidates():
            payload = {"action": "validate", "license_key": key, "machine_code": machine_code}
            data = _post_api(payload, timeout=timeout)
            status = data.get("status", "")

            if status == "ok":
                return True, data.get("expire_date", ""), data.get("msg", "授权通过"), status

            if status == "expired":
                last_result = (False, data.get("expire_date", ""), data.get("msg", "授权已过期"), status)
                continue

            last_result = (False, data.get("expire_date", ""), data.get("msg", "授权校验失败"), status or "error")

        return last_result
    except requests.RequestException as exc:
        return False, "", f"网络异常，授权校验失败：{exc}", "network_error"
    except Exception as exc:
        return False, "", f"授权校验异常：{exc}", "error"


def register_account_with_server(identifier, password, timeout=20):
    try:
        identifier = (identifier or "").strip()
        payload = {
            "action": "register",
            "password": password,
        }

        # 同时携带 identifier 与细分字段，兼容新旧服务端参数约定。
        payload.update(_build_account_identifier_payload(identifier, include_register_type=True))

        data = _normalize_account_api_data(_post_api(payload, timeout=timeout))
        return data.get("status") == "ok", _extract_account_api_message(data, "注册失败"), data
    except requests.RequestException as exc:
        return False, f"网络异常，注册失败：{exc}", {}
    except Exception as exc:
        return False, f"注册异常：{exc}", {}

def login_account_with_server(identifier, password, timeout=20):
    try:
        payload = {
            "action": "login",
            "password": password,
            "machine_code": get_machine_code(),
            "device_name": platform.node() or platform.system(),
        }
        payload.update(_build_account_identifier_payload(identifier))
        data = _normalize_account_api_data(_post_api(payload, timeout=timeout))
        if data.get("status") == "ok":
            if not _first_present(data, ACCOUNT_TOKEN_FIELDS):
                return False, "登录成功但服务端未返回登录令牌，请检查接口 token 字段", data
            save_account_session(data)
            return True, _extract_account_api_message(data, "登录成功"), data
        return False, _extract_account_api_message(data, "登录失败"), data
    except requests.RequestException as exc:
        return False, f"网络异常，登录失败：{exc}", {}
    except Exception as exc:
        return False, f"登录异常：{exc}", {}


def validate_account_session(timeout=20):
    token = get_saved_account_token()
    if not token:
        return {"valid": False, "msg": "未登录账号", "status": "no_account"}

    try:
        payload = {
            "action": "validate_account",
            "token": token,
            "machine_code": get_machine_code(),
            "device_name": platform.node() or platform.system(),
        }
        data = _normalize_account_api_data(_post_api(payload, timeout=timeout))
        status = data.get("status", "error")

        if status == "ok" and data.get("valid", True):
            save_account_session(data)
            return {
                "valid": True,
                "expire_date": data.get("expire_date", ""),
                "msg": data.get("msg", "账号验证通过"),
                "status": "ok",
                "auth_mode": "account",
                "account": data,
            }

        if status in ("expired", "quota_exceeded"):
            save_account_session(data)
            return {
                "valid": False,
                "expire_date": data.get("expire_date", ""),
                "msg": data.get("msg", "账号不可用"),
                "status": status,
                "auth_mode": "account",
                "account": data,
            }

        if status in ("invalid_token", "disabled", "device_mismatch", "session_expired"):
            clear_auth_data()

        return {
            "valid": False,
            "expire_date": data.get("expire_date", ""),
            "msg": data.get("msg", "账号验证失败"),
            "status": status,
            "auth_mode": "account",
            "account": data,
        }
    except requests.RequestException as exc:
        return {"valid": False, "expire_date": "", "msg": f"网络异常，账号验证失败：{exc}", "status": "network_error", "auth_mode": "account"}
    except Exception as exc:
        return {"valid": False, "expire_date": "", "msg": f"账号验证异常：{exc}", "status": "error", "auth_mode": "account"}


def consume_download_permission(url_count=1, timeout=20):
    token = get_saved_account_token()
    if not token:
        return {"valid": False, "msg": "请先登录账号", "status": "no_account"}

    try:
        payload = {
            "action": "consume_download",
            "token": token,
            "machine_code": get_machine_code(),
            "device_name": platform.node() or platform.system(),
            "url_count": max(1, int(url_count)),
        }
        data = _post_api(payload, timeout=timeout)
        status = data.get("status", "error")
        if status == "ok":
            save_account_session(data)
            return {
                "valid": True,
                "expire_date": data.get("expire_date", ""),
                "msg": data.get("msg", "允许下载"),
                "status": "ok",
                "auth_mode": "account",
                "account": data,
            }

        if status in ("invalid_token", "disabled", "device_mismatch", "session_expired"):
            clear_auth_data()

        return {
            "valid": False,
            "expire_date": data.get("expire_date", ""),
            "msg": data.get("msg", "下载权限校验失败"),
            "status": status,
            "auth_mode": "account",
            "account": data,
        }
    except requests.RequestException as exc:
        return {"valid": False, "expire_date": "", "msg": f"网络异常，下载权限校验失败：{exc}", "status": "network_error", "auth_mode": "account"}
    except Exception as exc:
        return {"valid": False, "expire_date": "", "msg": f"下载权限校验异常：{exc}", "status": "error", "auth_mode": "account"}


def logout_account_with_server(timeout=20):
    token = get_saved_account_token()
    if not token:
        clear_auth_data()
        return True, "已退出登录"

    try:
        payload = {
            "action": "logout_account",
            "token": token,
        }
        data = _post_api(payload, timeout=timeout)
        clear_auth_data()
        return data.get("status") == "ok", data.get("msg", "已退出登录")
    except Exception:
        clear_auth_data()
        return True, "已退出登录"


class AuthCacheManager:
    """授权缓存管理。"""

    def __init__(self):
        self.cache_file = AUTH_CACHE_FILE
        self.cache_ttl_hours = 24
        self.cache_ttl_fail_minutes = 30
        self.offline_grace_days = 7
        self.cache = self.load_cache()

    def load_cache(self):
        try:
            if os.path.exists(self.cache_file):
                with open(self.cache_file, "r", encoding="utf-8") as file:
                    cache = json.load(file)
                    if isinstance(cache, dict):
                        return {key: value for key, value in cache.items() if isinstance(value, dict)}
        except Exception:
            pass
        return {}

    def save_cache(self, cache_data):
        try:
            with open(self.cache_file, "w", encoding="utf-8") as file:
                json.dump(cache_data, file, indent=2, ensure_ascii=False)
            return True
        except Exception:
            return False

    def is_cache_valid(self, cache_entry):
        if not cache_entry or "last_verify_time" not in cache_entry:
            return False

        try:
            last_verify = datetime.strptime(cache_entry["last_verify_time"], "%Y-%m-%d %H:%M:%S")
            now = datetime.now()

            if cache_entry.get("valid", False):
                cache_hours = cache_entry.get("cache_hours", self.cache_ttl_hours)
                return (now - last_verify) < timedelta(hours=cache_hours)

            cache_minutes = cache_entry.get("cache_minutes", self.cache_ttl_fail_minutes)
            return (now - last_verify) < timedelta(minutes=cache_minutes)
        except Exception:
            return False

    def get_machine_cache_key(self):
        machine_code = get_machine_code()
        auth_data = load_auth_data()
        if auth_data.get("auth_mode") == "account" and auth_data.get("token"):
            identifier = auth_data.get("email") or auth_data.get("phone") or "account"
            return f"{machine_code}_account_{identifier}"

        license_key = auth_data.get("license_key") or "no_license"
        return f"{machine_code}_license_{license_key}"

    def get_cached_auth(self):
        cache_entry = self.cache.get(self.get_machine_cache_key(), {})
        if self.is_cache_valid(cache_entry):
            return cache_entry
        return {}

    def _parse_expire_date(self, expire_date):
        if not expire_date:
            return None

        for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%d"):
            try:
                return datetime.strptime(expire_date, fmt)
            except Exception:
                continue
        return None

    def _is_license_not_expired(self, cache_entry):
        expire_at = self._parse_expire_date(cache_entry.get("expire_date", ""))
        if expire_at is None:
            return cache_entry.get("valid", False)
        return expire_at >= datetime.now()

    def get_offline_fallback_auth(self):
        cache_entry = self.cache.get(self.get_machine_cache_key(), {})
        if not cache_entry or not cache_entry.get("valid", False):
            return {}

        if not self._is_license_not_expired(cache_entry):
            return {}

        try:
            last_verify = datetime.strptime(cache_entry["last_verify_time"], "%Y-%m-%d %H:%M:%S")
        except Exception:
            return {}

        if datetime.now() - last_verify > timedelta(days=self.offline_grace_days):
            return {}

        fallback = dict(cache_entry)
        fallback["msg"] = "当前网络异常，已使用离线授权缓存"
        fallback["status"] = "offline_cache"
        return fallback

    def update_cache(self, auth_result):
        cache_key = self.get_machine_cache_key()
        auth_data = load_auth_data()
        cache_data = {
            "machine_code": get_machine_code(),
            "license_key": auth_data.get("license_key") or auth_data.get("email") or auth_data.get("phone") or "no_license",
            "valid": auth_result.get("valid", False),
            "expire_date": auth_result.get("expire_date", ""),
            "msg": auth_result.get("msg", ""),
            "status": auth_result.get("status", ""),
            "auth_mode": auth_result.get("auth_mode", auth_data.get("auth_mode", "license")),
            "last_verify_time": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "cache_hours": self.cache_ttl_hours,
            "cache_minutes": self.cache_ttl_fail_minutes,
            "account": auth_result.get("account", {}),
        }

        self.cache[cache_key] = cache_data
        if len(self.cache) > 10:
            items = list(self.cache.items())
            sorted_items = sorted(items, key=lambda item: item[1].get("last_verify_time", ""), reverse=True)
            self.cache = dict(sorted_items[:10])

        self.save_cache(self.cache)
        return cache_data

    def clear_cache(self):
        self.cache = {}
        try:
            if os.path.exists(self.cache_file):
                os.remove(self.cache_file)
        except Exception:
            pass

    def force_refresh(self):
        self.clear_cache()


def ensure_authorized(force_refresh=False):
    auth_cache = AuthCacheManager()

    if force_refresh:
        auth_cache.force_refresh()

    cached_auth = auth_cache.get_cached_auth()
    if cached_auth and not force_refresh:
        return cached_auth

    auth_data = load_auth_data()
    auth_mode = auth_data.get("auth_mode", "")

    if auth_mode == "account" and auth_data.get("token"):
        result = validate_account_session()
        if result.get("status") == "network_error":
            offline_auth = auth_cache.get_offline_fallback_auth()
            if offline_auth:
                return offline_auth
        auth_cache.update_cache(result)
        return result

    license_key = auth_data.get("license_key", "")
    if not license_key:
        result = {"valid": False, "expire_date": "", "msg": "未授权", "status": "no_local"}
        auth_cache.update_cache(result)
        return result

    success, expire_date, msg, status = verify_license_with_server(license_key)
    if status == "network_error":
        offline_auth = auth_cache.get_offline_fallback_auth()
        if offline_auth:
            return offline_auth

    if status == "expired":
        result = {"valid": False, "expire_date": expire_date or "", "msg": msg or "授权已过期", "status": "expired"}
    elif success:
        result = {"valid": True, "expire_date": expire_date or "", "msg": msg or "授权通过", "status": "ok"}
    else:
        result = {"valid": False, "expire_date": expire_date or "", "msg": msg or "授权校验失败", "status": status or "error"}

    auth_cache.update_cache(result)
    return result


if __name__ == "__main__":
    print("机器码：", get_machine_code())
    print("机器码长度：", len(get_machine_code()))
