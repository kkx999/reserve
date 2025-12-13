<?php
session_start();
if (!isset($_SESSION['is_admin'])) { header("Location: login.php"); exit; }
require '../config.php';

// 生成 Token
if (empty($_SESSION['token'])) { $_SESSION['token'] = bin2hex(random_bytes(32)); }
$token = $_SESSION['token'];

// ==================================================
// 1. 自动维护表结构
// ==================================================
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS daily_limits (date DATE PRIMARY KEY, max_num INT NOT NULL DEFAULT 20)");
    $conn->exec("ALTER TABLE appointments ADD COLUMN message VARCHAR(255) DEFAULT ''");
    $conn->exec("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(50) PRIMARY KEY, value TEXT)");
    if(!$conn->query("SELECT * FROM settings WHERE name='notice_status'")->fetch()) {
        $conn->exec("INSERT INTO settings (name, value) VALUES ('notice_status', '0'), ('notice_content', '欢迎预约！')");
    }
} catch (Exception $e) {}

// ==================================================
// 2. 核心逻辑 (带 CSRF 校验)
// ==================================================
$current_page_url = $_SERVER['PHP_SELF'];

function check_token() {
    if (!isset($_POST['token']) || !hash_equals($_SESSION['token'], $_POST['token'])) {
        die("❌ 令牌验证失败，请返回刷新重试。");
    }
}

// A. 修改管理员账号
if (isset($_POST['update_account'])) {
    check_token();
    $cur_pass = $_POST['cur_pass'];
    $new_user = strip_tags($_POST['new_user']);
    $new_pass = $_POST['new_pass'];

    $stmt = $conn->query("SELECT * FROM admins LIMIT 1");
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin && password_verify($cur_pass, $admin['password'])) {
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $conn->prepare("UPDATE admins SET username = ?, password = ? WHERE id = ?")->execute([$new_user, $new_hash, $admin['id']]);
        $_SESSION['sys_msg'] = "<div class='toast success'><span class='material-symbols-outlined'>check_circle</span> 账号修改成功</div>";
    } else {
        $_SESSION['sys_msg'] = "<div class='toast error'><span class='material-symbols-outlined'>block</span> 旧密码错误</div>";
    }
    header("Location: " . $current_page_url); exit;
}

// B. 保存公告/TG设置
if (isset($_POST['save_notice'])) {
    check_token();
    $status = isset($_POST['notice_status']) ? '1' : '0';
    $conn->prepare("INSERT INTO settings (name, value) VALUES ('notice_status', ?) ON DUPLICATE KEY UPDATE value = ?")->execute([$status, $status]);
    $conn->prepare("INSERT INTO settings (name, value) VALUES ('notice_content', ?) ON DUPLICATE KEY UPDATE value = ?")->execute([$_POST['notice_content'], $_POST['notice_content']]);
    
    $tg_token = trim($_POST['tg_bot_token']);
    $tg_id = trim($_POST['tg_chat_id']);
    $conn->prepare("INSERT INTO settings (name, value) VALUES ('tg_bot_token', ?) ON DUPLICATE KEY UPDATE value = ?")->execute([$tg_token, $tg_token]);
    $conn->prepare("INSERT INTO settings (name, value) VALUES ('tg_chat_id', ?) ON DUPLICATE KEY UPDATE value = ?")->execute([$tg_id, $tg_id]);

    $_SESSION['sys_msg'] = "<div class='toast success'><span class='material-symbols-outlined'>check_circle</span> 设置已保存</div>";
    header("Location: " . $current_page_url); exit;
}

// C. 编辑预约
if (isset($_POST['update_appointment'])) {
    check_token();
    $book_time = $_POST['edit_date'] . " 09:00:00";
    $conn->prepare("UPDATE appointments SET name=?, phone=?, book_time=?, message=? WHERE id=?")
          ->execute([$_POST['edit_name'], $_POST['edit_phone'], $book_time, $_POST['edit_message'], $_POST['edit_id']]);
    $_SESSION['sys_msg'] = "<div class='toast success'><span class='material-symbols-outlined'>check_circle</span> 预约更新成功</div>";
    header("Location: " . $current_page_url); exit;
}

// D. 删除预约 (GET 请求也验证 Token)
if (isset($_GET['del'])) {
    if (!isset($_GET['token']) || !hash_equals($_SESSION['token'], $_GET['token'])) {
        die("❌ 非法操作。");
    }
    $conn->prepare("DELETE FROM appointments WHERE id = ?")->execute([(int)$_GET['del']]);
    header("Location: " . $current_page_url); exit;
}

// E. 限额设置
if (isset($_POST['batch_update'])) {
    check_token();
    $days = date('t', strtotime($_POST['month'] . "-01"));
    $stmt = $conn->prepare("INSERT INTO daily_limits (date, max_num) VALUES (?, ?) ON DUPLICATE KEY UPDATE max_num = ?");
    for ($d=1; $d<=$days; $d++) $stmt->execute([$_POST['month'].'-'.str_pad($d,2,'0',STR_PAD_LEFT), $_POST['limit'], $_POST['limit']]);
    $_SESSION['sys_msg'] = "<div class='toast success'><span class='material-symbols-outlined'>check_circle</span> 批量设置成功</div>";
    header("Location: " . $current_page_url); exit;
}
if (isset($_POST['single_update_modal'])) {
    check_token();
    $conn->prepare("INSERT INTO daily_limits (date, max_num) VALUES (?, ?) ON DUPLICATE KEY UPDATE max_num = ?")
          ->execute([$_POST['modal_limit_date'], $_POST['modal_limit_num'], $_POST['modal_limit_num']]);
    $_SESSION['sys_msg'] = "<div class='toast success'><span class='material-symbols-outlined'>check_circle</span> 限额已修改</div>";
    header("Location: " . $current_page_url); exit;
}

// 提取消息
$sys_msg = isset($_SESSION['sys_msg']) ? $_SESSION['sys_msg'] : '';
unset($_SESSION['sys_msg']);

// ==================================================
// 3. 数据读取 (分页 + 搜索)
// ==================================================
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$admin_info = $conn->query("SELECT username FROM admins LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// 分页与搜索逻辑
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;
$search = isset($_GET['s']) ? trim($_GET['s']) : '';

// 构建查询
$sql_where = "WHERE 1=1";
$params = [];
if (!empty($search)) {
    $sql_where .= " AND (name LIKE ? OR phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// 获取总数
$stmt_count = $conn->prepare("SELECT COUNT(*) FROM appointments $sql_where");
$stmt_count->execute($params);
$total_rows = $stmt_count->fetchColumn();
$total_pages = ceil($total_rows / $per_page);

// 获取当前页数据
$sql_list = "SELECT * FROM appointments $sql_where ORDER BY created_at DESC LIMIT $offset, $per_page";
$stmt_list = $conn->prepare($sql_list);
$stmt_list->execute($params);
$list = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

// 读取设置
$notice_status = $conn->query("SELECT value FROM settings WHERE name='notice_status'")->fetchColumn();
$notice_content = $conn->query("SELECT value FROM settings WHERE name='notice_content'")->fetchColumn();
$tg_bot_token = $conn->query("SELECT value FROM settings WHERE name='tg_bot_token'")->fetchColumn();
$tg_chat_id = $conn->query("SELECT value FROM settings WHERE name='tg_chat_id'")->fetchColumn();

// 图表与日历数据
$chart_data = $conn->query("SELECT DATE_FORMAT(book_time, '%d') as day, COUNT(*) as count FROM appointments WHERE DATE_FORMAT(book_time, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') GROUP BY DATE(book_time)")->fetchAll(PDO::FETCH_ASSOC);
$chart_json = []; foreach($chart_data as $r) $chart_json[intval($r['day'])] = $r['count'];
$final_labels = []; $final_counts = [];
for($i=1; $i<=date('t'); $i++){ $final_labels[]=$i."日"; $final_counts[]=isset($chart_json[$i])?$chart_json[$i]:0; }

$limits_map = $conn->query("SELECT date, max_num FROM daily_limits WHERE date LIKE '$current_month%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$counts_data = $conn->query("SELECT DATE(book_time) as d, COUNT(*) as c FROM appointments WHERE book_time LIKE '$current_month%' GROUP BY d")->fetchAll(PDO::FETCH_KEY_PAIR);
$calendar_data = [];
$days_in_month = date('t', strtotime($current_month . "-01"));
for ($d = 1; $d <= $days_in_month; $d++) {
    $date_str = $current_month . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
    $limit = isset($limits_map[$date_str]) ? $limits_map[$date_str] : 20; 
    $used = isset($counts_data[$date_str]) ? $counts_data[$date_str] : 0;
    $calendar_data[] = ['date'=>$date_str, 'day'=>$d, 'limit'=>$limit, 'used'=>$used, 'percent'=>min(100, round(($used/$limit)*100))];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>预约管理控制台</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <style>
        /* 保持 UI 不变，省略部分 CSS */
        :root { --primary: #4338ca; --primary-light: #e0e7ff; --primary-hover: #3730a3; --bg: #f3f4f6; --card-bg: #ffffff; --text-main: #111827; --text-muted: #6b7280; --border: #e5e7eb; --danger: #ef4444; --danger-bg: #fef2f2; --success: #10b981; --warning: #f59e0b; }
        body { margin: 0; padding: 0; font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text-main); }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .dashboard-grid { display: grid; grid-template-columns: 1fr 350px; gap: 24px; }
        @media (max-width: 1024px) { .dashboard-grid { grid-template-columns: 1fr; } }
        .navbar { background: var(--card-bg); padding: 16px 24px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 2px rgba(0,0,0,0.05); margin-bottom: 24px; }
        .card { background: var(--card-bg); border-radius: 16px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid rgba(229,231,235,0.5); margin-bottom: 24px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card-title { font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px; margin: 0; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; background: #f9fafb; font-size: 14px; box-sizing: border-box; margin-bottom: 12px; }
        input:focus { outline: none; border-color: var(--primary); background: #fff; }
        .btn { padding: 10px 16px; border-radius: 8px; border: none; font-size: 14px; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-danger { background: var(--danger-bg); color: var(--danger); }
        .btn-ghost { background: transparent; color: var(--text-muted); border: 1px solid var(--border); }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .switch-label { display: flex; align-items: center; cursor: pointer; gap: 12px; margin-bottom: 16px; }
        .switch-input { display: none; }
        .switch-track { position: relative; width: 44px; height: 24px; background: #e5e7eb; border-radius: 24px; transition: 0.3s; }
        .switch-track:after { content: ""; position: absolute; height: 20px; width: 20px; left: 2px; bottom: 2px; background: white; border-radius: 50%; transition: 0.3s; }
        .switch-input:checked + .switch-track { background: var(--primary); }
        .switch-input:checked + .switch-track:after { transform: translateX(20px); }
        .calendar-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 12px; }
        .day-cell { background: #f9fafb; border: 1px solid var(--border); border-radius: 10px; padding: 12px 10px; cursor: pointer; text-align: center; }
        .day-cell:hover { border-color: var(--primary); }
        .status-full { background: var(--danger-bg); border-color: #fecaca; }
        .table-responsive { overflow-x: auto; border-radius: 8px; border: 1px solid var(--border); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 600px; }
        th, td { padding: 14px 16px; border-bottom: 1px solid var(--border); text-align: left; }
        th { background: #f9fafb; color: var(--text-muted); font-weight: 600; }
        .toast { position: fixed; top: 20px; right: 20px; z-index: 1000; padding: 16px; border-radius: 8px; display: flex; align-items: center; gap: 10px; animation: slideIn 0.3s; }
        .toast.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .toast.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); display: none; align-items: center; justify-content: center; z-index: 999; }
        .modal-content { background: white; width: 90%; max-width: 420px; padding: 30px; border-radius: 16px; }
        @keyframes slideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }
        .pagination { display: flex; gap: 5px; margin-top: 20px; justify-content: center; }
        .pagination a { padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; text-decoration: none; color: var(--text-main); font-size: 13px; }
        .pagination a.active { background: var(--primary); color: white; border-color: var(--primary); }
    </style>
</head>
<body>
<div class="container">
    <?= $sys_msg ?>
    <div class="navbar">
        <div style="font-size:18px; font-weight:700; color:var(--primary); display:flex; align-items:center; gap:8px;">
            <span class="material-symbols-outlined">admin_panel_settings</span> 管理后台
        </div>
        <div>
            <a href="../index.php" target="_blank" class="btn btn-ghost btn-sm">前台</a>
            <a href="login.php" class="btn btn-ghost btn-sm" style="color:var(--danger)">退出</a>
        </div>
    </div>

    <div class="dashboard-grid">
        <div class="main-column">
            <div class="card">
                <div class="card-header"><h3 class="card-title">近期走势</h3></div>
                <div style="height:250px"><canvas id="chart"></canvas></div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">每日限额</h3>
                    <div style="display:flex;gap:10px">
                        <input type="month" value="<?= $current_month ?>" onchange="window.location.href='?month='+this.value" style="width:auto;margin:0">
                        <button class="btn btn-ghost btn-sm" onclick="document.getElementById('batchModal').style.display='flex'">批量</button>
                    </div>
                </div>
                <div class="calendar-grid">
                    <?php foreach($calendar_data as $day): ?>
                    <div class="day-cell <?= $day['percent']>=100?'status-full':'' ?>" onclick="openLimitModal('<?= $day['date'] ?>', <?= $day['limit'] ?>)">
                        <span style="font-weight:bold"><?= $day['day'] ?></span><br>
                        <span style="font-size:12px;color:#888"><?= $day['used'] ?>/<?= $day['limit'] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">预约记录 (<?= $total_rows ?>)</h3>
                    <form style="display:flex; gap:10px; margin:0;" method="get">
                        <input type="text" name="s" placeholder="搜昵称/账号..." value="<?= htmlspecialchars($search) ?>" style="margin:0; width:150px; padding:6px 10px;">
                        <button type="submit" class="btn btn-primary btn-sm">搜索</button>
                    </form>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>用户</th><th>账号</th><th>日期</th><th>备注</th><th>操作</th></tr></thead>
                        <tbody>
                            <?php foreach($list as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['name']) ?></td>
                                <td><?= htmlspecialchars($r['phone']) ?></td>
                                <td><?= date('m-d', strtotime($r['book_time'])) ?></td>
                                <td title="<?= htmlspecialchars($r['message']) ?>"><?= mb_substr($r['message'],0,10) ?></td>
                                <td>
                                    <button onclick='editAppt(<?= json_encode($r) ?>)' class="btn btn-ghost btn-sm"><span class="material-symbols-outlined" style="font-size:16px">edit</span></button>
                                    <a href="?del=<?= $r['id'] ?>&token=<?= $token ?>" onclick="return confirm('确定删除？')" class="btn btn-ghost btn-sm" style="color:var(--danger)"><span class="material-symbols-outlined" style="font-size:16px">delete</span></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if($total_pages > 1): ?>
                <div class="pagination">
                    <?php for($i=1; $i<=$total_pages; $i++): ?>
                        <a href="?page=<?= $i ?>&s=<?= urlencode($search) ?>" class="<?= $i==$page?'active':'' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="side-column">
            <div class="card">
                <div class="card-header"><h3 class="card-title">系统配置</h3></div>
                <form method="post">
                    <input type="hidden" name="token" value="<?= $token ?>">
                    <label class="switch-label">
                        <input type="checkbox" name="notice_status" class="switch-input" <?= $notice_status=='1'?'checked':'' ?>>
                        <span class="switch-track"></span><span>开启公告</span>
                    </label>
                    <textarea name="notice_content" rows="3"><?= htmlspecialchars($notice_content) ?></textarea>
                    <div style="border-top:1px dashed #ddd; margin-top:10px; padding-top:10px;">
                        <input type="text" name="tg_bot_token" value="<?= htmlspecialchars($tg_bot_token ?? '') ?>" placeholder="TG Bot Token">
                        <input type="text" name="tg_chat_id" value="<?= htmlspecialchars($tg_chat_id ?? '') ?>" placeholder="TG Chat ID">
                    </div>
                    <button type="submit" name="save_notice" class="btn btn-primary" style="width:100%">保存设置</button>
                </form>
            </div>
            
            <div class="card">
                <div class="card-header"><h3 class="card-title">修改密码</h3></div>
                <form method="post">
                    <input type="hidden" name="token" value="<?= $token ?>">
                    <input type="password" name="cur_pass" placeholder="当前密码" required>
                    <input type="text" name="new_user" placeholder="新用户名" value="<?= htmlspecialchars($admin_info['username']??'admin') ?>" required>
                    <input type="password" name="new_pass" placeholder="新密码" required>
                    <button type="submit" name="update_account" class="btn btn-danger" style="width:100%">确认修改</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal" id="editModal" onclick="if(event.target==this)this.style.display='none'">
    <div class="modal-content">
        <h3>编辑预约</h3>
        <form method="post">
            <input type="hidden" name="token" value="<?= $token ?>">
            <input type="hidden" name="edit_id" id="eid">
            <input name="edit_name" id="ename" required placeholder="姓名">
            <input name="edit_phone" id="ephone" required placeholder="联系方式">
            <input type="date" name="edit_date" id="edate" required>
            <textarea name="edit_message" id="emsg" rows="3" placeholder="留言"></textarea>
            <button type="submit" name="update_appointment" class="btn btn-primary" style="width:100%">保存</button>
        </form>
    </div>
</div>

<div class="modal" id="limitModal" onclick="if(event.target==this)this.style.display='none'">
    <div class="modal-content">
        <h3>修改单日限额</h3>
        <form method="post">
            <input type="hidden" name="token" value="<?= $token ?>">
            <input type="date" name="modal_limit_date" id="limit_date_input" readonly style="background:#eee">
            <input type="number" name="modal_limit_num" id="limit_num_input" required style="font-size:20px; font-weight:bold">
            <button type="submit" name="single_update_modal" class="btn btn-primary" style="width:100%">更新</button>
        </form>
    </div>
</div>

<div class="modal" id="batchModal" onclick="if(event.target==this)this.style.display='none'">
    <div class="modal-content">
        <h3>批量设置</h3>
        <form method="post">
            <input type="hidden" name="token" value="<?= $token ?>">
            <input type="month" name="month" value="<?= $current_month ?>" required>
            <input type="number" name="limit" placeholder="每日数量 (如: 20)" required>
            <button type="submit" name="batch_update" class="btn btn-primary" style="width:100%">应用</button>
        </form>
    </div>
</div>

<script>
    const ctx = document.getElementById('chart').getContext('2d');
    new Chart(ctx, { type: 'line', data: { labels: <?= json_encode($final_labels) ?>, datasets: [{ label: '人数', data: <?= json_encode($final_counts) ?>, borderColor: '#4338ca', borderWidth: 2, fill: true, tension: 0.3 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: {display:false} }, scales: { x:{grid:{display:false}}, y:{beginAtZero:true} } } });
    
    function editAppt(d) { document.getElementById('editModal').style.display='flex'; document.getElementById('eid').value=d.id; document.getElementById('ename').value=d.name; document.getElementById('ephone').value=d.phone; document.getElementById('edate').value=d.book_time.split(' ')[0]; document.getElementById('emsg').value=d.message; }
    function openLimitModal(d, l) { document.getElementById('limitModal').style.display='flex'; document.getElementById('limit_date_input').value=d; document.getElementById('limit_num_input').value=l; }
    setTimeout(() => { document.querySelectorAll('.toast').forEach(t => t.style.display='none'); }, 3000);
</script>
</body>
</html>
