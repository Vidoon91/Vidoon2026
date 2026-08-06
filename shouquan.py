# -*- coding: utf-8 -*-
"""账号登录、机器码与本地缓存模块。"""

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


def _get_user_data_dir():
    """Return a per-user writable directory for login state and caches."""
    local_app_data = os.environ.get("LOCALAPPDATA")
    if not local_app_data:
        local_app_data = os.path.join(os.path.expanduser("~"), "AppData", "Local")
    user_data_dir = os.path.join(local_app_data, "Vidoon")
    try:
        os.makedirs(user_data_dir, exist_ok=True)
        return user_data_dir
    except OSError:
        return BASE_DIR


API_URL = get_api_url()
USER_DATA_DIR = _get_user_data_dir()
ACCOUNT_SESSION_FILE = os.path.join(USER_DATA_DIR, "account_session.json")
LEGACY_LICENSE_FILE = os.path.join(BASE_DIR, "license.json")
LEGACY_ACCOUNT_SESSION_FILE = os.path.join(BASE_DIR, "account_session.json")
AUTH_CACHE_FILE = os.path.join(USER_DATA_DIR, "auth_cache.json")
LEGACY_AUTH_CACHE_FILE = os.path.join(BASE_DIR, "auth_cache.json")

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
    "email_verification_only": "当前仅支持邮箱验证码注册",
    "phone_verification_not_available": "手机短信验证码暂未开放，请使用邮箱注册",
    "invalid_verification_purpose": "验证码用途无效",
    "verification_code_sent": "验证码已发送，请检查邮箱",
    "verification_code_too_frequent": "发送过于频繁，请 60 秒后重试",
    "verification_code_hourly_limit": "该邮箱发送次数过多，请一小时后重试",
    "verification_code_ip_limit": "当前网络发送次数过多，请稍后重试",
    "verification_code_create_failed": "验证码生成失败，请稍后重试",
    "verification_email_send_failed": "验证码邮件发送失败，请稍后重试",
    "smtp_not_configured": "服务器尚未配置验证码邮箱",
    "smtp_openssl_missing": "服务器 PHP 未启用 OpenSSL 扩展",
    "smtp_connect_failed": "验证码邮箱服务器连接失败",
    "smtp_send_failed": "验证码邮件发送失败，请检查服务器邮箱配置",
    "invalid_verification_code": "邮箱验证码错误",
    "verification_code_not_found": "请先获取邮箱验证码",
    "verification_code_expired": "邮箱验证码已过期，请重新获取",
    "verification_code_attempts_exceeded": "验证码错误次数过多，请重新获取",
    "verification_code_already_used": "验证码已使用，请重新获取",
    "password_reset_failed": "密码重置失败，请稍后重试",
    "password_reset_success": "密码重置成功，请使用新密码登录",
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
    "quota_exceeded": "当前下载额度不足",
    "task_limit_exceeded": "本次下载数量超过套餐上限",
    "task_download_limit_exceeded": "本次下载数量超过套餐上限",
    "daily_quota_exceeded": "今日下载额度不足",
    "free_credit_exhausted": "免费额度已用完，请观看广告领取或购买订阅",
    "paid_subscription_not_eligible": "有效付费订阅用户不能参加免费额度领取，订阅到期后可再次参加",
    "heartbeat_disabled": "心跳接口已禁用",
}

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
    candidate_files = (
        ACCOUNT_SESSION_FILE,
        LEGACY_ACCOUNT_SESSION_FILE,
        LEGACY_LICENSE_FILE,
    )
    for file_path in dict.fromkeys(candidate_files):
        try:
            if not os.path.exists(file_path):
                continue
            with open(file_path, "r", encoding="utf-8") as file:
                data = json.load(file)
            if not isinstance(data, dict) or not data.get("token"):
                if file_path == LEGACY_LICENSE_FILE:
                    os.remove(file_path)
                continue
            data["auth_mode"] = "account"
            if file_path != ACCOUNT_SESSION_FILE and save_auth_data(data):
                try:
                    os.remove(file_path)
                except OSError:
                    pass
            return data
        except Exception:
            continue
    return {}


def save_auth_data(data):
    if not isinstance(data, dict):
        return False
    temp_file = f"{ACCOUNT_SESSION_FILE}.tmp"
    try:
        os.makedirs(os.path.dirname(ACCOUNT_SESSION_FILE), exist_ok=True)
        with open(temp_file, "w", encoding="utf-8") as file:
            json.dump(data, file, ensure_ascii=False, indent=2)
            file.flush()
            os.fsync(file.fileno())
        os.replace(temp_file, ACCOUNT_SESSION_FILE)
        return True
    except Exception:
        try:
            if os.path.exists(temp_file):
                os.remove(temp_file)
        except OSError:
            pass
        return False


def clear_auth_data():
    for file_path in dict.fromkeys(
        (ACCOUNT_SESSION_FILE, LEGACY_ACCOUNT_SESSION_FILE, LEGACY_LICENSE_FILE)
    ):
        try:
            if os.path.exists(file_path):
                os.remove(file_path)
        except Exception:
            pass


def get_saved_account_token():
    return load_auth_data().get("token", "")


def save_account_session(session_data):
    session_data = session_data if isinstance(session_data, dict) else {}
    current_data = load_auth_data()
    current_token = current_data.get("token", "")
    payload = {
        "auth_mode": "account",
        "token": session_data.get("token") or current_token,
        "email": session_data.get("email") or current_data.get("email", ""),
        "phone": session_data.get("phone") or current_data.get("phone", ""),
        "display_name": session_data.get("display_name") or current_data.get("display_name", ""),
        "account_level": session_data.get("account_level") or current_data.get("account_level", "free"),
        "account_level_label": session_data.get("account_level_label") or current_data.get("account_level_label", "免费订阅"),
        "max_devices": session_data.get("max_devices", current_data.get("max_devices", 1)),
        "per_task_limit": session_data.get("per_task_limit", current_data.get("per_task_limit", 1)),
        "daily_download_limit": session_data.get("daily_download_limit", current_data.get("daily_download_limit", 3)),
        "effective_daily_download_limit": session_data.get(
            "effective_daily_download_limit",
            current_data.get("effective_daily_download_limit", 3),
        ),
        "free_daily_limit": session_data.get("free_daily_limit", current_data.get("free_daily_limit", 3)),
        "quota_mode": session_data.get("quota_mode", current_data.get("quota_mode", "credit")),
        "free_credit_balance": session_data.get(
            "free_credit_balance",
            current_data.get("free_credit_balance", 3),
        ),
        "today_ad_reward_count": session_data.get(
            "today_ad_reward_count",
            current_data.get("today_ad_reward_count", 0),
        ),
        "today_download_count": session_data.get("today_download_count", current_data.get("today_download_count", 0)),
        "today_download_reserved": session_data.get(
            "today_download_reserved",
            current_data.get("today_download_reserved", 0),
        ),
        "today_download_remaining": session_data.get("today_download_remaining", current_data.get("today_download_remaining", 3)),
        "expire_date": session_data.get("expire_date", current_data.get("expire_date", "")),
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
        raise RuntimeError("连接账号服务器超时，请检查 VPN、代理或网络线路") from exc
    except requests.exceptions.ReadTimeout as exc:
        raise RuntimeError("账号服务器响应超时，请稍后重试或切换网络") from exc
    except requests.exceptions.SSLError as exc:
        raise RuntimeError(f"SSL 证书或代理握手失败：{exc}") from exc
    except requests.exceptions.ProxyError as exc:
        raise RuntimeError(f"代理连接失败，请关闭或更换 VPN/代理：{exc}") from exc
    except requests.exceptions.ConnectionError as exc:
        raise RuntimeError(f"无法连接账号服务器，可能被网络、防火墙或 VPN 拦截：{exc}") from exc

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


def send_email_verification_code(email, purpose, timeout=20):
    try:
        data = _post_api(
            {
                "action": "send_email_code",
                "email": (email or "").strip(),
                "purpose": purpose,
            },
            timeout=timeout,
        )
        return data.get("status") == "ok", _extract_account_api_message(data, "验证码发送失败"), data
    except requests.RequestException as exc:
        return False, f"网络异常，验证码发送失败：{exc}", {}
    except Exception as exc:
        return False, f"验证码发送异常：{exc}", {}


def get_public_site_config(timeout=10):
    try:
        data = _post_api({"action": "get_public_config"}, timeout=timeout)
        return data.get("status") == "ok", data
    except Exception as exc:
        return False, {"status": "error", "msg": str(exc)}


def create_ad_reward_session(timeout=20):
    token = get_saved_account_token()
    if not token:
        return {"valid": False, "status": "no_account", "msg": "请先登录账号"}
    try:
        data = _post_api(
            {
                "action": "create_ad_reward",
                "token": token,
                "machine_code": get_machine_code(),
                "device_name": platform.node() or platform.system(),
            },
            timeout=timeout,
        )
        return {
            "valid": data.get("status") == "ok" and bool(data.get("reward_url")),
            **data,
        }
    except requests.RequestException as exc:
        return {"valid": False, "status": "network_error", "msg": f"网络异常：{exc}"}
    except Exception as exc:
        return {"valid": False, "status": "error", "msg": f"申请免费次数失败：{exc}"}


def get_ad_reward_status(reward_token, timeout=15):
    token = get_saved_account_token()
    if not token or not reward_token:
        return {"valid": False, "status": "missing_params", "msg": "缺少领取凭证"}
    try:
        data = _post_api(
            {
                "action": "ad_reward_status",
                "token": token,
                "machine_code": get_machine_code(),
                "reward_token": reward_token,
            },
            timeout=timeout,
        )
        if data.get("status") == "ok":
            save_account_session(data)
            return {"valid": True, **data}
        return {"valid": False, **data}
    except requests.RequestException as exc:
        return {"valid": False, "status": "network_error", "msg": f"网络异常：{exc}"}
    except Exception as exc:
        return {"valid": False, "status": "error", "msg": f"查询奖励状态失败：{exc}"}


def register_account_with_server(identifier, password, verification_code, timeout=20):
    try:
        identifier = (identifier or "").strip()
        payload = {
            "action": "register",
            "password": password,
            "verification_code": (verification_code or "").strip(),
        }
        if "@" in identifier:
            payload.update({"register_type": "email", "email": identifier})
        else:
            payload.update({"register_type": "phone", "phone": identifier})
        data = _post_api(payload, timeout=timeout)
        return data.get("status") == "ok", _extract_account_api_message(data, "注册失败"), data
    except requests.RequestException as exc:
        return False, f"网络异常，注册失败：{exc}", {}
    except Exception as exc:
        return False, f"注册异常：{exc}", {}


def reset_password_with_email(email, verification_code, new_password, timeout=20):
    try:
        data = _post_api(
            {
                "action": "reset_password",
                "email": (email or "").strip(),
                "verification_code": (verification_code or "").strip(),
                "new_password": new_password,
            },
            timeout=timeout,
        )
        return data.get("status") == "ok", _extract_account_api_message(data, "密码重置失败"), data
    except requests.RequestException as exc:
        return False, f"网络异常，密码重置失败：{exc}", {}
    except Exception as exc:
        return False, f"密码重置异常：{exc}", {}


def login_account_with_server(identifier, password, timeout=20):
    try:
        payload = {
            "action": "login",
            "identifier": (identifier or "").strip(),
            "password": password,
            "machine_code": get_machine_code(),
            "device_name": platform.node() or platform.system(),
        }
        data = _post_api(payload, timeout=timeout)
        if data.get("status") == "ok":
            if not data.get("token"):
                return False, "登录成功但服务端未返回登录令牌，请检查接口 token 字段", data
            if not save_account_session(data):
                return (
                    False,
                    "登录验证成功，但客户端无法保存登录状态。请将软件完整解压后运行，并检查安全软件的文件保护设置。",
                    data,
                )
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
        data = _post_api(payload, timeout=timeout)
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


def reserve_download_permission(url_count=1, timeout=20):
    token = get_saved_account_token()
    if not token:
        return {"valid": False, "msg": "请先登录账号", "status": "no_account"}

    try:
        payload = {
            "action": "reserve_download",
            "token": token,
            "machine_code": get_machine_code(),
            "device_name": platform.node() or platform.system(),
            "url_count": max(1, int(url_count)),
        }
        data = _post_api(payload, timeout=timeout)
        status = data.get("status", "error")
        if status == "ok" and data.get("reservation_token"):
            save_account_session(data)
            return {
                "valid": True,
                "expire_date": data.get("expire_date", ""),
                "msg": data.get("msg", "允许下载"),
                "status": "ok",
                "reservation_token": data.get("reservation_token", ""),
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


def settle_download_permission(reservation_token, success, settled_count=1, timeout=20):
    token = get_saved_account_token()
    if not token or not reservation_token:
        return {"valid": False, "msg": "缺少下载结算凭证", "status": "missing_reservation"}

    normalized_count = max(1, int(settled_count))
    try:
        payload = {
            "action": "settle_download",
            "token": token,
            "machine_code": get_machine_code(),
            "device_name": platform.node() or platform.system(),
            "reservation_token": reservation_token,
            "settled_count": normalized_count,
            "success_count": normalized_count if success else 0,
        }
        data = _post_api(payload, timeout=timeout)
        status = data.get("status", "error")
        if status == "ok":
            save_account_session(data)
            return {
                "valid": True,
                "expire_date": data.get("expire_date", ""),
                "msg": data.get("msg", "下载结果已记录"),
                "status": "ok",
                "auth_mode": "account",
                "account": data,
            }
        return {
            "valid": False,
            "expire_date": data.get("expire_date", ""),
            "msg": data.get("msg", "下载结果记录失败"),
            "status": status,
            "auth_mode": "account",
            "account": data,
        }
    except requests.RequestException as exc:
        return {"valid": False, "msg": f"网络异常，下载结果记录失败：{exc}", "status": "network_error", "auth_mode": "account"}
    except Exception as exc:
        return {"valid": False, "msg": f"下载结果记录异常：{exc}", "status": "error", "auth_mode": "account"}


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
    """账号状态缓存管理。"""

    def __init__(self):
        self.cache_file = AUTH_CACHE_FILE
        self.cache_ttl_hours = 24
        self.cache_ttl_fail_minutes = 30
        self.offline_grace_days = 7
        self.cache = self.load_cache()

    def load_cache(self):
        for cache_file in dict.fromkeys((self.cache_file, LEGACY_AUTH_CACHE_FILE)):
            try:
                if not os.path.exists(cache_file):
                    continue
                with open(cache_file, "r", encoding="utf-8") as file:
                    cache = json.load(file)
                    if isinstance(cache, dict):
                        normalized_cache = {
                            key: value
                            for key, value in cache.items()
                            if isinstance(value, dict) and value.get("auth_mode") == "account"
                        }
                        if cache_file != self.cache_file and self.save_cache(normalized_cache):
                            try:
                                os.remove(cache_file)
                            except OSError:
                                pass
                        return normalized_cache
            except Exception:
                continue
        return {}

    def save_cache(self, cache_data):
        temp_file = f"{self.cache_file}.tmp"
        try:
            os.makedirs(os.path.dirname(self.cache_file), exist_ok=True)
            with open(temp_file, "w", encoding="utf-8") as file:
                json.dump(cache_data, file, indent=2, ensure_ascii=False)
                file.flush()
                os.fsync(file.fileno())
            os.replace(temp_file, self.cache_file)
            return True
        except Exception:
            try:
                if os.path.exists(temp_file):
                    os.remove(temp_file)
            except OSError:
                pass
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
        if auth_data.get("token"):
            identifier = auth_data.get("email") or auth_data.get("phone") or "account"
            return f"{machine_code}_account_{identifier}"
        return f"{machine_code}_account_no_account"

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

    def _is_account_not_expired(self, cache_entry):
        expire_at = self._parse_expire_date(cache_entry.get("expire_date", ""))
        if expire_at is None:
            return cache_entry.get("valid", False)
        return expire_at >= datetime.now()

    def get_offline_fallback_auth(self):
        cache_entry = self.cache.get(self.get_machine_cache_key(), {})
        if not cache_entry or not cache_entry.get("valid", False):
            return {}

        if not self._is_account_not_expired(cache_entry):
            return {}

        try:
            last_verify = datetime.strptime(cache_entry["last_verify_time"], "%Y-%m-%d %H:%M:%S")
        except Exception:
            return {}

        if datetime.now() - last_verify > timedelta(days=self.offline_grace_days):
            return {}

        fallback = dict(cache_entry)
        fallback["msg"] = "当前网络异常，已使用离线账号缓存"
        fallback["status"] = "offline_cache"
        return fallback

    def update_cache(self, auth_result):
        cache_key = self.get_machine_cache_key()
        auth_data = load_auth_data()
        cache_data = {
            "machine_code": get_machine_code(),
            "valid": auth_result.get("valid", False),
            "expire_date": auth_result.get("expire_date", ""),
            "msg": auth_result.get("msg", ""),
            "status": auth_result.get("status", ""),
            "auth_mode": "account",
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

    auth_data = load_auth_data()
    if auth_data.get("token"):
        cached_auth = auth_cache.get_cached_auth()
        if cached_auth and not force_refresh:
            return cached_auth
        result = validate_account_session()
        if result.get("status") == "network_error":
            offline_auth = auth_cache.get_offline_fallback_auth()
            if offline_auth:
                return offline_auth
        auth_cache.update_cache(result)
        return result

    result = {
        "valid": False,
        "expire_date": "",
        "msg": "未登录账号",
        "status": "no_account",
        "auth_mode": "account",
        "account": {},
    }
    auth_cache.update_cache(result)
    return result


if __name__ == "__main__":
    print("机器码：", get_machine_code())
    print("机器码长度：", len(get_machine_code()))
