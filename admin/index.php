<?php
session_start();
if (!isset($_SESSION['is_admin'])) { header("Location: login.php"); exit; }
require '../config.php';

// ==================================================
// 1. 数据库自动维护
// ==================================================
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS daily_limits (date DATE PRIMARY KEY, max_num INT NOT NULL DEFAULT 20)");
    $conn->exec("ALTER TABLE appointments ADD COLUMN message VARCHAR(255) DEFAULT ''");
    $conn->exec("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(50) PRIMARY KEY, value TEXT)");
    // 初始化公告
    if(!$conn->query("SELECT * FROM settings WHERE name='notice_status'")->fetch()) {
        $conn->exec("INSERT INTO settings (name, value) VALUES ('notice_status', '0'), ('notice_content', '欢迎预约！')");
    }
} catch (Exception $e) {}

$sys_msg = '';
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// ==================================================
// 2. 核心逻辑处理
// ==================================================

// A. 【新增】修改管理员账号密码
if (isset($_POST['update_account'])) {
    $cur_pass = $_POST['cur_pass'];
    $new_user = strip_tags($_POST['new_user']);
    $new_pass = $_POST['new_pass'];

    // 获取当前管理员信息 (默认取ID为1的管理员，或者表里的第一条)
    $stmt = $conn->query("SELECT * FROM admins LIMIT 1");
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin && password_verify($cur_pass, $admin['password'])) {
        // 旧密码验证通过，更新信息
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE admins SET username = ?, password = ? WHERE id = ?");
        if ($update->execute([$new_user, $new_hash, $admin['id']])) {
            $sys_msg = "<div class='alert success'>✅ 账号修改成功！下次登录请使用新密码。</div>";
        } else {
            $sys_msg = "<div class='alert error'>❌ 数据库更新失败。</div>";
        }
    } else {
        $sys_msg = "<div class='alert error'>❌ 当前旧密码错误，操作被拒绝。</div>";
    }
}

// B. 保存公告
if (isset($_POST['save_notice'])) {
    $status = isset($_POST['notice_status']) ? '1' : '0';
    $conn->prepare("INSERT INTO settings (name, value) VALUES ('notice_status', ?) ON DUPLICATE KEY UPDATE value = ?")->execute([$status, $status]);
    $conn->prepare("INSERT INTO settings (name, value) VALUES ('notice_content', ?) ON DUPLICATE KEY UPDATE value = ?")->execute([$_POST['notice_content'], $_POST['notice_content']]);
    $sys_msg = "<div class='alert success'>✅ 公告已更新</div>";
}

// C. 编辑/删除预约
if (isset($_POST['update_appointment'])) {
    $book_time = $_POST['edit_date'] . " 09:00:00";
    $conn->prepare("UPDATE appointments SET name=?, phone=?, book_time=?, message=? WHERE id=?")
         ->execute([$_POST['edit_name'], $_POST['edit_phone'], $book_time, $_POST['edit_message'], $_POST['edit_id']]);
    $sys_msg = "<div class='alert success'>✅ 预约信息已更新</div>";
}
if (isset($_GET['del'])) {
    $conn->prepare("DELETE FROM appointments WHERE id = ?")->execute([(int)$_GET['del']]);
    header("Location: index.php"); exit;
}

// D. 限额设置
if (isset($_POST['batch_update'])) {
    $days = date('t', strtotime($_POST['month'] . "-01"));
    $stmt = $conn->prepare("INSERT INTO daily_limits (date, max_num) VALUES (?, ?) ON DUPLICATE KEY UPDATE max_num = ?");
    for ($d=1; $d<=$days; $d++) $stmt->execute([$_POST['month'].'-'.str_pad($d,2,'0',STR_PAD_LEFT), $_POST['limit'], $_POST['limit']]);
    $sys_msg = "<div class='alert success'>✅ 批量设置成功</div>";
}
if (isset($_POST['single_update_modal'])) {
    $conn->prepare("INSERT INTO daily_limits (date, max_num) VALUES (?, ?) ON DUPLICATE KEY UPDATE max_num = ?")
         ->execute([$_POST['modal_limit_date'], $_POST['modal_limit_num'], $_POST['modal_limit_num']]);
    $sys_msg = "<div class='alert success'>✅ 限额已修改</div>";
}

// ==================================================
// 3. 数据读取
// ==================================================
// 获取当前管理员用户名
$admin_info = $conn->query("SELECT username FROM admins LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$current_username = $admin_info ? $admin_info['username'] : 'admin';

// 公告
$notice_status = $conn->query("SELECT value FROM settings WHERE name='notice_status'")->fetchColumn();
$notice_content = $conn->query("SELECT value FROM settings WHERE name='notice_content'")->fetchColumn();

// 列表 & 图表
$list = $conn->query("SELECT * FROM appointments ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$chart_data = $conn->query("SELECT DATE_FORMAT(book_time, '%d') as day, COUNT(*) as count FROM appointments WHERE DATE_FORMAT(book_time, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') GROUP BY DATE(book_time)")->fetchAll(PDO::FETCH_ASSOC);
$chart_json = []; foreach($chart_data as $r) $chart_json[intval($r['day'])] = $r['count'];
$final_labels = []; $final_counts = [];
for($i=1; $i<=date('t'); $i++){ $final_labels[]=$i."日"; $final_counts[]=isset($chart_json[$i])?$chart_json[$i]:0; }

// 日历数据
$limits_map = $conn->query("SELECT date, max_num FROM daily_limits WHERE date LIKE '$current_month%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$counts_data = $conn->query("SELECT DATE(book_time) as d, COUNT(*) as c FROM appointments WHERE book_time LIKE '$current_month%' GROUP BY d")->fetchAll(PDO::FETCH_KEY_PAIR);
$days_in_month = date('t', strtotime($current_month . "-01"));
$calendar_data = [];
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
    <title>预约管理后台</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <style>
        :root { --primary:#4f46e5; --bg:#f3f4f6; --card:#fff; --text:#1f2937; --border:#e5e7eb; --danger:#ef4444; --success:#10b981; --warn:#f59e0b; }
        body { margin:0; padding:0; font-family:-apple-system,BlinkMacSystemFont,sans-serif; background:var(--bg); color:var(--text); }
        .container { max-width:1200px; margin:0 auto; padding:20px; }
        
        .navbar { background:var(--card); padding:15px 20px; display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
        .brand { font-size:20px; font-weight:700; color:var(--primary); display:flex; gap:10px; align-items:center; }
        .nav-links a { text-decoration:none; color:#6b7280; margin-left:20px; display:inline-flex; align-items:center; gap:5px; transition:0.2s; }
        .nav-links a:hover { color:var(--primary); }
        
        .card { background:var(--card); border-radius:12px; padding:24px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom:24px; border:1px solid var(--border); }
        .card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
        .card-title { font-size:18px; font-weight:600; display:flex; align-items:center; gap:8px; margin:0; }
        
        .btn { padding:10px 16px; border-radius:6px; border:none; color:white; background:var(--primary); cursor:pointer; font-weight:500; display:inline-flex; align-items:center; gap:5px; text-decoration:none; transition:0.2s; }
        .btn:hover { opacity:0.9; }
        .btn-sm { padding:5px 10px; font-size:12px; }
        .btn-danger { background:var(--danger); }
        .btn-white { background:white; color:#333; border:1px solid #ddd; }
        
        input, select, textarea { width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; box-sizing:border-box; margin-bottom:10px; font-family:inherit; }
        
        /* 开关 */
        .switch-label { display:flex; align-items:center; cursor:pointer; gap:10px; margin-bottom:15px; font-weight:500; }
        .switch-input { display:none; }
        .switch-slider { position:relative; width:40px; height:20px; background:#ccc; border-radius:20px; transition:0.3s; }
        .switch-slider:before { content:""; position:absolute; height:16px; width:16px; left:2px; bottom:2px; background:white; border-radius:50%; transition:0.3s; }
        .switch-input:checked + .switch-slider { background:var(--primary); }
        .switch-input:checked + .switch-slider:before { transform:translateX(20px); }

        /* 日历网格 */
        .limit-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 10px; }
        .limit-cell { background: #f9fafb; border: 1px solid var(--border); border-radius: 8px; padding: 10px; cursor: pointer; transition: 0.2s; position: relative; overflow: hidden; }
        .limit-cell:hover { border-color: var(--primary); transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .limit-date { font-size: 14px; font-weight: bold; color: #374151; margin-bottom: 5px; }
        .limit-info { font-size: 12px; color: #6b7280; display: flex; justify-content: space-between; }
        .progress-bar { height: 4px; background: #e5e7eb; border-radius: 2px; margin-top: 8px; overflow: hidden; }
        .progress-fill { height: 100%; background: var(--success); width: 0%; transition: 0.3s; }
        .status-full .progress-fill { background: var(--danger); }
        .status-warn .progress-fill { background: var(--warn); }
        .limit-cell.status-full { background: #fef2f2; border-color: #fecaca; }

        /* 表格 */
        table { width:100%; border-collapse:collapse; font-size:14px; }
        th, td { padding:12px 15px; text-align:left; border-bottom:1px solid var(--border); }
        th { background:#f9fafb; font-weight:600; color:#6b7280; }
        .avatar { width:32px; height:32px; background:#e0e7ff; color:var(--primary); border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; margin-right:10px; }
        
        .alert { padding:10px; border-radius:6px; margin-bottom:15px; background:#d1fae5; color:#065f46; animation:fade 0.5s; }
        @keyframes fade { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }
        
        /* 弹窗 */
        .modal { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); display:none; align-items:center; justify-content:center; z-index:999; backdrop-filter:blur(2px); }
        .modal-content { background:white; padding:25px; border-radius:12px; width:400px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); animation:pop 0.2s; }
        @keyframes pop { from{transform:scale(0.95);opacity:0} to{transform:scale(1);opacity:1} }
    </style>
</head>
<body>

<div class="container">
    <div class="navbar">
        <div class="brand"><span class="material-symbols-outlined">dashboard</span> 管理控制台</div>
        <div class="nav-links">
            <a href="../index.php" target="_blank"><span class="material-symbols-outlined">visibility</span> 前台</a>
            <a href="login.php" style="color:#ef4444"><span class="material-symbols-outlined">logout</span> 退出</a>
        </div>
    </div>
    
    <?= $sys_msg ?>

    <div style="display:flex; gap:20px; flex-wrap:wrap;">
        <div style="flex:2; min-width:300px;">
            <div class="card" style="height:350px;">
                <div class="card-header"><h3 class="card-title"><span class="material-symbols-outlined">show_chart</span> 预约走势</h3></div>
                <div style="height:280px"><canvas id="chart"></canvas></div>
            </div>
        </div>
        
        <div style="flex:1; min-width:300px;">
            <div class="card" style="margin-bottom:20px;">
                <div class="card-header" style="margin-bottom:15px;"><h3 class="card-title"><span class="material-symbols-outlined">campaign</span> 公告设置</h3></div>
                <form method="post">
                    <label class="switch-label">
                        <input type="checkbox" name="notice_status" class="switch-input" <?= $notice_status=='1'?'checked':'' ?>>
                        <span class="switch-slider"></span> <span>启用前台公告</span>
                    </label>
                    <textarea name="notice_content" rows="3" placeholder="公告内容..."><?= htmlspecialchars($notice_content) ?></textarea>
                    <button type="submit" name="save_notice" class="btn btn-sm" style="width:100%">保存公告</button>
                </form>
            </div>

            <div class="card">
                <div class="card-header" style="margin-bottom:15px;"><h3 class="card-title"><span class="material-symbols-outlined">security</span> 账号修改</h3></div>
                <form method="post">
                    <input type="password" name="cur_pass" placeholder="当前旧密码 (必填)" required style="border-color:#d1d5db; background:#f9fafb;">
                    <div style="display:flex; gap:5px;">
                        <input type="text" name="new_user" placeholder="新用户名" value="<?= htmlspecialchars($current_username) ?>" required>
                        <input type="password" name="new_pass" placeholder="新密码" required>
                    </div>
                    <button type="submit" name="update_account" class="btn btn-sm btn-danger" style="width:100%">确认修改</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><span class="material-symbols-outlined">calendar_month</span> 每日限额监控</h3>
            <form method="get" style="display:flex; gap:10px; align-items:center;">
                <input type="month" name="month" value="<?= $current_month ?>" onchange="this.form.submit()" style="margin:0; width:auto;">
                <button type="button" class="btn btn-white" onclick="document.getElementById('batchModal').style.display='flex'">批量设置</button>
            </form>
        </div>
        
        <div class="limit-grid">
            <?php foreach($calendar_data as $day): ?>
                <?php $status_class = ($day['percent']>=100) ? 'status-full' : (($day['percent']>=80) ? 'status-warn' : ''); ?>
                <div class="limit-cell <?= $status_class ?>" onclick="openLimitModal('<?= $day['date'] ?>', <?= $day['limit'] ?>)">
                    <div class="limit-date"><?= $day['day'] ?>日</div>
                    <div class="limit-info">
                        <span>已约: <b><?= $day['used'] ?></b></span>
                        <span style="color:#999">/ <?= $day['limit'] ?></span>
                    </div>
                    <div class="progress-bar"><div class="progress-fill" style="width: <?= $day['percent'] ?>%"></div></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title"><span class="material-symbols-outlined">list_alt</span> 最新预约列表</h3></div>
        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>用户</th><th>预约时间</th><th>留言</th><th style="text-align:right">操作</th></tr></thead>
                <tbody>
                    <?php foreach($list as $r): ?>
                    <tr>
                        <td style="display:flex; align-items:center;">
                            <div class="avatar"><?= mb_substr($r['name'],0,1) ?></div>
                            <div><div><?= htmlspecialchars($r['name']) ?></div><div style="font-size:12px;color:#999"><?= htmlspecialchars($r['phone']) ?></div></div>
                        </td>
                        <td><?= date('Y-m-d', strtotime($r['book_time'])) ?></td>
                        <td style="color:#666; font-size:13px; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap"><?= htmlspecialchars($r['message']) ?></td>
                        <td style="text-align:right">
                            <button onclick='editAppt(<?= json_encode($r) ?>)' class="btn btn-sm" style="background:#e0e7ff; color:#4338ca">编辑</button>
                            <a href="?del=<?= $r['id'] ?>" onclick="return confirm('删除?')" class="btn btn-sm btn-danger">删除</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal" id="editModal" onclick="if(event.target==this)this.style.display='none'"><div class="modal-content"><h3>✏️ 编辑预约</h3><form method="post"><input type="hidden" name="edit_id" id="eid"><label>姓名</label><input name="edit_name" id="ename" required><label>联系方式</label><input name="edit_phone" id="ephone" required><label>日期</label><input type="date" name="edit_date" id="edate" required><label>留言</label><textarea name="edit_message" id="emsg"></textarea><button type="submit" name="update_appointment" class="btn" style="width:100%">保存修改</button></form></div></div>
<div class="modal" id="limitModal" onclick="if(event.target==this)this.style.display='none'"><div class="modal-content"><h3>🔢 修改限额</h3><form method="post"><label>日期</label><input type="date" name="modal_limit_date" id="limit_date_input" readonly style="background:#f3f4f6"><label>设置最大人数</label><input type="number" name="modal_limit_num" id="limit_num_input" required><button type="submit" name="single_update_modal" class="btn" style="width:100%">确认修改</button></form></div></div>
<div class="modal" id="batchModal" onclick="if(event.target==this)this.style.display='none'"><div class="modal-content"><h3>📅 批量设置全月</h3><form method="post"><label>选择月份</label><input type="month" name="month" value="<?= $current_month ?>" required><label>每天限额</label><input type="number" name="limit" placeholder="例如: 50" required><button type="submit" name="batch_update" class="btn" style="width:100%">应用到全月</button></form></div></div>

<script>
    new Chart(document.getElementById('chart'), { type:'line', data:{labels:<?=json_encode($final_labels)?>,datasets:[{data:<?=json_encode($final_counts)?>,borderColor:'#4f46e5',backgroundColor:'rgba(79,70,229,0.1)',fill:true,tension:0.4}]}, options:{plugins:{legend:{display:false}},scales:{x:{grid:{display:false}},y:{beginAtZero:true}},maintainAspectRatio:false} });
    function editAppt(d) { document.getElementById('editModal').style.display='flex'; document.getElementById('eid').value = d.id; document.getElementById('ename').value = d.name; document.getElementById('ephone').value = d.phone; document.getElementById('edate').value = d.book_time.split(' ')[0]; document.getElementById('emsg').value = d.message; }
    function openLimitModal(d, l) { document.getElementById('limitModal').style.display='flex'; document.getElementById('limit_date_input').value = d; document.getElementById('limit_num_input').value = l; }
</script>
</body>
</html>
