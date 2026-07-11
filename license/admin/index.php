<?php
session_start();
if (!isset($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}

require_once '../include/db.php';
$conn = get_db_connection();

// -------------------------------------
// 搜索 + 筛选条件 + 分页
// -------------------------------------

$condition = "WHERE 1=1";

if (!empty($_GET['q'])) {
    $q = $conn->real_escape_string($_GET['q']);
    $condition .= " AND (license_key LIKE '%$q%' OR machine_code LIKE '%$q%')";
}

if (isset($_GET['status']) && $_GET['status'] !== '') {
    $status = $_GET['status'];
    if ($status === 'trial') {
        $condition .= " AND (is_trial = 1 OR license_key LIKE 'TRIAL-%')";
    } elseif ($status === 'formal') {
        $condition .= " AND (is_trial = 0 AND license_key NOT LIKE 'TRIAL-%')";
    } elseif ($status === '1' || $status === '0') {
        $condition .= " AND active = " . intval($status);
    }
}

$per_page = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

$count_sql = "SELECT COUNT(*) AS total FROM licenses $condition";
$total = (int) $conn->query($count_sql)->fetch_assoc()['total'];

$total_pages = max(1, ceil($total / $per_page));
$offset = ($page - 1) * $per_page;

$sql = "SELECT * FROM licenses $condition ORDER BY id DESC LIMIT $offset, $per_page";
$res = $conn->query($sql);

$today = date('Y-m-d');

// -------------------------------------
// 统计（以表格每行显示逻辑为准）
// 逻辑说明（与页面 badge 一致）：
// 1) 已过期： expire_date 存在且 < NOW()
// 2) 否则若 machine_code 非空 且 start_date/expire_date 有效：
//      - 若 (expire - start) > 3 天 => 正式
//      - 否则 => 试用
// 3) 缺少 machine 或 日期 => 未授权（不计入 试用/正式）
// -------------------------------------

// 总数
$stat_total = (int)$conn->query("SELECT COUNT(*) AS c FROM licenses")->fetch_assoc()['c'];

// 已过期（expire_date 存在且小于当前时间）
$stat_expired = (int)$conn->query("
    SELECT COUNT(*) AS c
    FROM licenses
    WHERE
        expire_date IS NOT NULL
        AND expire_date <> ''
        AND expire_date <> '0000-00-00 00:00:00'
        AND expire_date < NOW()
")->fetch_assoc()['c'];

// 试用：machine_code 非空，start_date/expire_date 有效，且未过期，且 (expire - start) <= 3 天
$stat_trial = (int)$conn->query("
    SELECT COUNT(*) AS c
    FROM licenses
    WHERE
        TRIM(COALESCE(machine_code, '')) <> ''
        AND start_date IS NOT NULL
        AND start_date <> ''
        AND start_date <> '0000-00-00 00:00:00'
        AND expire_date IS NOT NULL
        AND expire_date <> ''
        AND expire_date <> '0000-00-00 00:00:00'
        AND expire_date >= NOW()
        AND TIMESTAMPDIFF(SECOND, start_date, expire_date) <= (3 * 86400)
")->fetch_assoc()['c'];

// 正式：machine_code 非空，start_date/expire_date 有效，且未过期，且 (expire - start) > 3 天
$stat_formal = (int)$conn->query("
    SELECT COUNT(*) AS c
    FROM licenses
    WHERE
        TRIM(COALESCE(machine_code, '')) <> ''
        AND start_date IS NOT NULL
        AND start_date <> ''
        AND start_date <> '0000-00-00 00:00:00'
        AND expire_date IS NOT NULL
        AND expire_date <> ''
        AND expire_date <> '0000-00-00 00:00:00'
        AND expire_date >= NOW()
        AND TIMESTAMPDIFF(SECOND, start_date, expire_date) > (3 * 86400)
")->fetch_assoc()['c'];

// 启用 / 禁用
$stat_active = (int)$conn->query("SELECT COUNT(*) AS c FROM licenses WHERE active = 1")->fetch_assoc()['c'];
$stat_inactive = (int)$conn->query("SELECT COUNT(*) AS c FROM licenses WHERE active = 0")->fetch_assoc()['c'];

// 已绑定（非空字符串）
$stat_bound = (int)$conn->query("SELECT COUNT(*) AS c FROM licenses WHERE machine_code IS NOT NULL AND TRIM(machine_code) <> ''")->fetch_assoc()['c'];

// 今日新增
$stat_new_today = (int)$conn->query("SELECT COUNT(*) AS c FROM licenses WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['c'];

$params = [];
if (!empty($_GET['q'])) $params['q'] = $_GET['q'];
if (isset($_GET['status']) && $_GET['status'] !== '') $params['status'] = $_GET['status'];

$base_q = http_build_query($params);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>授权管理后台 - 马踏飞燕</title>

<script src="https://cdn.tailwindcss.com"></script>

<style>
/* ---------- 基础样式（保留原视觉） ---------- */
body {
    background: linear-gradient(180deg,#f0f7ff 0%, #e8f3ff 100%);
}
.card-shadow {
    box-shadow: 0 8px 24px rgba(30,64,175,0.08);
}

/* 注意：桌面宽度有意放大以便显示更多列，移动端会覆盖 */
.table-wrapper {
    border:1px solid #d9eaff;
    border-radius:14px;
    overflow:hidden;
    width: 110%;
    margin-left: -5%;
}
.table-head { background: linear-gradient(90deg,#0284c7,#0ea5e9); }
.btn-primary { background: linear-gradient(90deg,#0284c7,#0ea5e9); color:#fff; }

.btn-soft { background-color: rgba(14,165,233,0.12); color:#0369a1; }
.btn-border { border:1px solid #0284c7; color:#0369a1; }

.small-muted { color:#64748b;font-size:.9rem; }

.badge-trial {
    background: linear-gradient(90deg,#0ea5e9,#0284c7);
    color:white;
    padding:2px 6px;
    border-radius:6px;
    font-size:.65rem;
    font-weight:600;
}
.badge-formal {
    background:#10b981;
    color:white;
    padding:2px 6px;
    border-radius:6px;
    font-size:.65rem;
    font-weight:600;
}
.badge-unassigned {
    background:#94a3b8;
    color:white;
    padding:2px 6px;
    border-radius:6px;
    font-size:.65rem;
    font-weight:600;
}
.badge-expired {
    background: #ef4444;
    color: #fff;
    padding: 2px 6px;
    border-radius: 6px;
    font-size: .65rem;
    font-weight: 600;
}

.col-license { min-width: 220px !important; }
.col-machine {
    min-width: 160px !important;
    white-space: normal !important;
    word-break: break-all !important;
    line-height: 1.3;
}
.col-start { min-width: 140px !important; }

.compact-row td {
    padding-top: 9px !important;
    padding-bottom: 9px !important;
}

.table-text { font-size: 13px; color:#475569; font-weight: 500; }
.license-key { font-size: 14px; font-weight:600; color:#0f172a; }

.action-btn {
    border:1px solid #0284c7;
    padding:4px;
    border-radius:6px;
    font-size:12px;
    color:#0369a1;
    writing-mode: vertical-rl;
    text-orientation: upright;
}

.copy-btn { padding:3px; border-radius:5px; }
.copy-btn:hover { background:#dbeffd; }

.perm-btn {
    display:inline-block;
    margin:2px;
    padding:5px 8px;
    border-radius:6px;
    border:1px solid #cbd5e1;
    cursor:pointer;
    font-weight:600;
}
.perm-btn-enable { background:#ecfccb; color:#166534; border-color:#bbf7d0; }
.perm-btn-disable { background:#fee2e2; color:#9f1239; border-color:#fecaca; }

.perm-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap:6px;
}

/* ---------- 移动端（卡片式） ---------- */
@media (max-width: 900px) {
    .table-wrapper {
        width: 100% !important;
        margin-left: 0 !important;
        overflow: visible !important;
        padding: 0 10px !important;
    }
    .overflow-x-auto { overflow-x: visible !important; }
    table thead { display: none !important; }
    table tbody tr {
        display: block !important;
        background: #ffffff;
        margin: 12px 0 !important;
        padding: 12px !important;
        border-radius: 12px !important;
        box-shadow: 0 3px 10px rgba(0,0,0,0.06) !important;
    }
    table tbody tr td {
        display: flex !important;
        width: 100% !important;
        padding: 8px 0 !important;
        border-bottom: 1px solid #e5e7eb !important;
    }
    table tbody tr td:last-child { border-bottom: none !important; }

    table tbody tr td::before {
        content: attr(data-label) !important;
        flex: 0 0 95px !important;
        font-size: 13px !important;
        font-weight: 600 !important;
        color:#475569 !important;
    }

    table tbody tr td > * {
        flex: 1 !important;
        margin-left: 8px !important;
        font-size: 13px !important;
    }

    .col-license > div {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 6px !important;
    }

    .action-btn {
        writing-mode: horizontal-tb !important;
        text-orientation: mixed !important;
        padding: 6px 10px !important;
        margin-right: 6px !important;
        font-size: 13px !important;
        border-radius: 8px !important;
    }

    .perm-row {
        flex-direction: row !important;
        justify-content: flex-start !important;
        margin-top: 6px !important;
    }

    .copy-btn { padding:6px !important; border-radius:6px !important; }
}
</style>
</head>

<body class="antialiased text-slate-800">

<div class="max-w-4xl mx-auto py-8">

<!-- 标题 -->
<div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">马踏飞燕 · MacDL授权神机营</h1>
        <div class="small-muted mt-1">安全管理面板 · 仅限管理员访问</div>
    </div>

    <form method="get" class="mt-4 md:mt-0 flex flex-col sm:flex-row gap-2 items-center">

        <input type="text" name="q" placeholder="搜索授权码或机器码"
            value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
            class="px-3 py-2 border rounded-md w-full sm:w-48 focus:ring-2 focus:ring-sky-300" />

        <select name="status" class="px-3 py-2 border rounded-md w-full sm:w-32">
            <option value="">全部</option>
            <option value="trial" <?= (($_GET['status'] ?? '')=='trial')?'selected':'' ?>>试用</option>
            <option value="formal" <?= (($_GET['status'] ?? '')=='formal')?'selected':'' ?>>正式</option>
            <option value="1" <?= (($_GET['status'] ?? '')=='1')?'selected':'' ?>>启用</option>
            <option value="0" <?= (($_GET['status'] ?? '')=='0')?'selected':'' ?>>禁用</option>
        </select>

        <button type="submit" class="px-4 py-2 rounded-md btn-primary">搜索</button>
    </form>

    <div class="mt-4 md:mt-0 flex items-center gap-3">
        <a href="users.php" class="text-sm text-sky-600 hover:text-sky-700">用户管理</a>
        <a href="logout.php" class="text-sm text-rose-500 hover:text-rose-600">退出登录</a>
    </div>
</div>

<!-- 统计 -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">

    <div class="bg-white p-4 rounded-lg card-shadow">
        <div class="small-muted text-sm">总授权</div>
        <div class="text-2xl font-bold mt-2"><?= $stat_total ?></div>
        <div class="small-muted mt-1">累计授权条目</div>
    </div>

    <div class="bg-white p-4 rounded-lg card-shadow">
        <div class="small-muted text-sm">试用 / 正式</div>
        <div class="flex items-center gap-3 mt-2">
            <span class="badge-trial"><?= $stat_trial ?> 试用</span>
            <span class="text-lg font-medium text-slate-700"><?= $stat_formal ?> 正式</span>
        </div>
        <div class="small-muted mt-1">试用与正式授权统计（以列表右侧徽章为准）</div>
    </div>

    <div class="bg-white p-4 rounded-lg card-shadow">
        <div class="small-muted text-sm">状态</div>
        <div class="flex items-center gap-3 mt-2">
            <span class="text-lg font-semibold text-emerald-600"><?= $stat_active ?> 启用</span>
            <span class="text-lg font-semibold text-rose-500"><?= $stat_inactive ?> 禁用</span>
        </div>
        <div class="small-muted mt-1">
            已绑定 <?= $stat_bound ?> · 今日新增 <?= $stat_new_today ?> · 已过期 <?= $stat_expired ?>
        </div>
    </div>

</div>

<!-- 添加授权 -->
<div class="bg-white rounded-xl card-shadow mb-6 p-4 compact-form">
    <form method="post" action="auth.php" class="flex flex-col md:flex-row gap-2 items-center">

        <input type="text" id="license_key" name="license_key" placeholder="授权码" required
            class="px-3 input-sm border rounded-md w-full md:w-1/2" />

        <button type="button" onclick="genRandomKey()" class="btn-soft btn-sm border rounded-md">
            随机生成
        </button>

        <input type="date" name="expire_date" required class="px-3 input-sm border rounded-md" />

        <button type="submit" class="btn-primary btn-sm ml-auto rounded-md">添加授权</button>
    </form>
</div>

<!-- 列表 -->
<div class="bg-white rounded-xl card-shadow table-wrapper">
<div class="overflow-x-auto">
<table class="min-w-full text-sm">
<thead>
<tr class="table-head text-white text-sm font-medium">
    <th class="px-6 py-3 text-left">ID</th>
    <th class="px-6 py-3 col-start text-left">开始时间</th>
    <th class="px-6 py-3 col-license text-left">授权码</th>
    <th class="px-6 py-3 col-machine text-left">机器码</th>
    <th class="px-6 py-3 text-left">到期日期</th>
    <th class="px-6 py-3 text-center">操作</th>
    <th class="px-6 py-3 text-center">权限</th>
</tr>
</thead>

<tbody class="divide-y">

<?php while($row = $res->fetch_assoc()):
    $machine = trim($row['machine_code'] ?? '');
    $start_date = trim($row['start_date'] ?? '');
    $expire_date = trim($row['expire_date'] ?? '');

    $status_label = 'unassigned';
    $status_text = '未授权';

    if ($machine !== '' && $start_date !== '' && $expire_date !== '' &&
        $start_date != '0000-00-00 00:00:00' && $expire_date != '0000-00-00 00:00:00') {

        $diff = strtotime($expire_date) - strtotime($start_date);
        if ($diff > 86400 * 3) {
            $status_label = 'formal';
            $status_text = '正式';
        } else {
            $status_label = 'trial';
            $status_text = '试用';
        }
    }

    $is_expired = false;
    if ($expire_date && $expire_date != '0000-00-00 00:00:00') {
        if (strtotime($expire_date) < time()) $is_expired = true;
    }

    $id = intval($row['id']);
    $active = intval($row['active']);
?>
<tr class="compact-row hover:bg-slate-50" data-id="<?= $id ?>">

<td class="px-6 table-text" data-label="ID"><?= $id ?></td>

<td class="px-6 table-text col-start" data-label="开始时间"><?= htmlspecialchars($row['start_date']) ?></td>

<td class="px-6 table-text col-license" data-label="授权码">
    <div class="flex items-center gap-3">
        <span class="license-key"><?= htmlspecialchars($row['license_key']) ?></span>

        <button class="copy-btn"
            onclick="copyText('<?= addslashes($row['license_key']) ?>', this)">
            <svg class="w-4 h-4 text-sky-600" fill="none" stroke="currentColor" stroke-width="2"
                viewBox="0 0 24 24">
                <rect width="12" height="12" x="9" y="3" rx="2"/>
                <path d="M8 7H6a2 2 0 00-2 2v9a2 2 0 002 2h9"/>
            </svg>
        </button>

        <?php if ($is_expired): ?>
            <span class="badge-expired">已过期</span>
        <?php elseif ($status_label === 'unassigned'): ?>
            <span class="badge-unassigned">未授权</span>
        <?php elseif ($status_label === 'trial'): ?>
            <span class="badge-trial">试用</span>
        <?php else: ?>
            <span class="badge-formal">正式</span>
        <?php endif; ?>
    </div>
</td>

<td class="px-6 table-text col-machine" data-label="机器码"><?= htmlspecialchars($row['machine_code']) ?></td>

<td class="px-6 table-text" data-label="到期日期"><?= htmlspecialchars($row['expire_date']) ?></td>

<!-- 操作 -->
<td class="px-6 py-3 text-center" data-label="操作">
    <div style="display:flex;justify-content:center;gap:6px;">

        <?php if ($is_expired): ?>
            <a href="javascript:void(0)"
               onclick="openRenewModal(<?= $id ?>,'<?= addslashes($row['license_key']) ?>','<?= $row['expire_date'] ?>')"
               class="action-btn"
               style="background:white;color:#0284c7;border-color:#0284c7;">
               续期
            </a>

            <a href="delete_expired.php?id=<?= $id ?>"
               onclick="return confirm('确定删除此过期授权吗？')"
               class="action-btn"
               style="background:#b91c1c;color:white;border-color:#991b1b;">
               删除
            </a>

        <?php else: ?>
            <a href="javascript:void(0)"
               onclick="openRenewModal(<?= $id ?>,'<?= addslashes($row['license_key']) ?>','<?= $row['expire_date'] ?>')"
               class="action-btn">续期</a>

            <?php if (trim($row['machine_code'])): ?>
            <a href="javascript:void(0)"
               onclick="openResetModal(<?= $id ?>,'<?= addslashes($row['license_key']) ?>')"
               class="action-btn">重置</a>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</td>

<!-- 权限 -->
<td class="px-6 py-3 text-center" data-label="权限" id="perm-cell-<?= $id ?>">
    <div id="perm-text-<?= $id ?>">
        <?php if ($active): ?>
            <span class="text-emerald-600 font-semibold">启用</span>
        <?php else: ?>
            <span class="text-rose-500 font-semibold">禁用</span>
        <?php endif; ?>
    </div>

    <div class="perm-row">
        <button
            id="perm-btn-<?= $id ?>"
            class="perm-btn <?= $active ? 'perm-btn-enable':'perm-btn-disable' ?>"
            onclick="togglePermission(<?= $id ?>, <?= $active ? 0:1 ?>)">
            <?= $active ? '禁用':'启用' ?>
        </button>
    </div>
</td>

</tr>
<?php endwhile; ?>

</tbody>
</table>
</div>
</div>

<!-- 分页 -->
<div class="flex items-center justify-center gap-2 mt-6">
<?php
$prefix = '?page=';
$extra = $base_q ? '&' . $base_q : '';

if ($page > 1)
    echo '<a class="px-3 py-1 btn-border rounded-md" href="'.$prefix.($page-1).$extra.'">上一页</a>';

$start = max(1, $page - 4);
$end = min($total_pages, $page + 4);

for ($i=$start;$i<=$end;$i++){
    if($i==$page){
        echo '<span class="px-3 py-1 bg-sky-100 text-sky-700 rounded-md font-semibold">['.$i.']</span>';
    } else {
        echo '<a class="px-3 py-1 btn-border rounded-md" href="'.$prefix.$i.$extra.'">'.$i.'</a>';
    }
}

if ($page < $total_pages)
    echo '<a class="px-3 py-1 btn-border rounded-md" href="'.$prefix.($page+1).$extra.'">下一页</a>';
?>
</div>

<!-- Modals -->
<div id="renewModal"
     class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
    <div class="bg-white rounded-lg w-96 p-5 card-shadow">
        <div class="flex justify-between mb-2">
            <h3 class="text-lg font-medium">续期授权</h3>
            <button onclick="closeRenewModal()">&times;</button>
        </div>
        <div id="renewInfo" class="small-muted"></div>
        <input type="date" id="newExpireDate" class="mt-3 px-3 py-2 border rounded-md w-full" />
        <div class="flex justify-end mt-4">
            <button onclick="submitRenew()" class="btn-primary px-4 py-2 rounded-md">确认续期</button>
        </div>
    </div>
</div>

<div id="resetModal"
     class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
    <div class="bg-white rounded-lg w-96 p-5 card-shadow">
        <div class="flex justify-between mb-2">
            <h3 class="text-lg font-medium">重置绑定</h3>
            <button onclick="closeResetModal()">&times;</button>
        </div>
        <div id="resetInfo" class="small-muted"></div>
        <div class="flex justify-end mt-4">
            <button onclick="submitReset()" class="btn-primary px-4 py-2 rounded-md">确认重置</button>
        </div>
    </div>
</div>

<script>
function copyText(text, btn){
    navigator.clipboard.writeText(text).then(()=>{
        if (btn) {
            btn.innerHTML = `<svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2"
                        viewBox="0 0 24 24">
                        <path d="M5 13l4 4L19 7"/>
                        </svg>`;
            btn.style.background="#d1fae5";
            setTimeout(()=>{
                btn.style.background="transparent";
                btn.innerHTML=`<svg class="w-4 h-4 text-sky-600" fill="none" stroke="currentColor" stroke-width="2"
                                viewBox="0 0 24 24">
                                <rect width="12" height="12" x="9" y="3" rx="2"/>
                                <path d="M8 7H6a2 2 0 00-2 2v9a2 2 0 002 2h9"/>
                                </svg>`;
            },900)
        }
    }).catch(()=>{ alert('复制失败'); });
}

function genRandomKey(){
    const chars="ABCDEFGHJKMNPQRSTUVWXYZ23456789";
    let key="KEY-";
    for(let i=0;i<12;i++){
        key+=chars[Math.floor(Math.random()*chars.length)];
    }
    document.getElementById('license_key').value=key;
}

function togglePermission(id, to){
    const btn = document.getElementById('perm-btn-' + id);
    const textSpan = document.getElementById('perm-text-' + id);

    if (btn) btn.disabled = true;
    const orig = btn ? btn.innerText : '';

    if (btn) btn.innerText = '...';

    fetch('toggle.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `id=${id}&to=${to}`
    })
    .then(r => r.text())
    .then(txt=>{
        location.reload();
    })
    .catch(err=>{
        console.error(err);
        alert("服务器错误");
        if (btn) { btn.innerText = orig; btn.disabled = false; }
    });
}

let renewId=0;
function openRenewModal(id,license,expire){
    renewId=id;
    document.getElementById("renewInfo").innerText=`授权码：${license}\n当前到期：${expire}`;
    document.getElementById("newExpireDate").value=expire && expire !== '0000-00-00 00:00:00' ? expire.split(' ')[0] : '';
    document.getElementById('renewModal').classList.remove("hidden");
    document.getElementById('renewModal').classList.add("flex");
}
function closeRenewModal(){
    document.getElementById('renewModal').classList.add("hidden");
    document.getElementById('renewModal').classList.remove("flex");
}
function submitRenew(){
    let d=document.getElementById("newExpireDate").value;
    if(!d){ alert("请选择日期"); return; }
    fetch("renew.php",{
        method:"POST",
        headers:{"Content-Type":"application/x-www-form-urlencoded"},
        body:`id=${renewId}&expire_date=${encodeURIComponent(d)}`
    }).then(r=>r.text()).then(t=>{ alert(t); location.reload(); }).catch(e=>alert('请求失败'));
}

let resetId=0;
function openResetModal(id,license){
    resetId=id;
    document.getElementById("resetInfo").innerText=`授权码：${license}\n重置绑定后，可在新设备重新验证。`;
    document.getElementById('resetModal').classList.remove("hidden");
    document.getElementById('resetModal').classList.add("flex");
}
function closeResetModal(){
    document.getElementById('resetModal').classList.add("hidden");
    document.getElementById('resetModal').classList.remove("flex");
}
function submitReset(){
    fetch("reset_machine.php",{
        method:"POST",
        headers:{"Content-Type":"application/x-www-form-urlencoded"},
        body:`id=${resetId}`
    }).then(r=>r.text()).then(t=>{ alert(t); location.reload(); }).catch(e=>alert('请求失败'));
}
</script>

</body>
</html>
