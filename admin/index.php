<?php
session_start();
if (!isset($_SESSION['is_admin'])) { header("Location: login.php"); exit; }
require '../config.php';

// ==================================================
// 1. 数据库自动升级 (建表/加字段)
// ==================================================
try {
    // 确保原有表存在
    $conn->exec("CREATE TABLE IF NOT EXISTS daily_limits (date DATE PRIMARY KEY, max_num INT NOT NULL DEFAULT 20)");
    $conn->exec("ALTER TABLE appointments ADD COLUMN message VARCHAR(255) DEFAULT ''");
} catch (Exception $e) {}

// 【新增】创建设置表 (用于存储公告)
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS settings (
        name VARCHAR(50) PRIMARY KEY, 
        value TEXT
    )");
    // 初始化公告默认值
    $stmt = $conn->query("SELECT * FROM settings WHERE name='notice_status'");
    if (!$stmt->fetch()) {
        $conn->exec("INSERT INTO settings (name, value) VALUES ('notice_status', '0')");
        $conn->exec("INSERT INTO settings (name, value) VALUES ('notice_content', '欢迎使用在线预约系统！')");
    }
} catch (Exception $e) {}

$sys_msg = '';

// ==================================================
// 2. 核心逻辑处理
// ==================================================

// A. 【新增】保存公告设置
if (isset($_POST['save_notice'])) {
    $status = isset($_POST['notice_status']) ? '1' : '0';
    $content = $_POST['notice_content'];
    
    try {
        $stmt = $conn->prepare("INSERT INTO settings (name, value) VALUES ('notice_status', ?) ON DUPLICATE KEY UPDATE value = ?");
        $stmt->execute([$status, $status]);
        
        $stmt = $conn->prepare("INSERT INTO settings (name, value) VALUES ('notice_content', ?) ON DUPLICATE KEY UPDATE value = ?");
        $stmt->execute([$content, $content]);
        
        $sys_msg = "<div class='alert success'>✅ 公告设置已更新</div>";
    } catch (Exception $e) {
        $sys_msg = "<div class='alert error'>❌ 保存失败</div>";
    }
}

// B. 编辑/删除/限额 (保持原有逻辑)
if (isset($_POST['update_appointment'])) {
    $book_time = $_POST['edit_date'] . " 09:00:00";
    $conn->prepare("UPDATE appointments SET name=?, phone=?, book_time=?, message=? WHERE id=?")
         ->execute([$_POST['edit_name'], $_POST['edit_phone'], $book_time, $_POST['edit_message'], $_POST['edit_id']]);
    $sys_msg = "<div class='alert success'>✅ 更新成功</div>";
}
if (isset($_GET['del'])) {
    $conn->prepare("DELETE FROM appointments WHERE id = ?")->execute([(int)$_GET['del']]);
    header("Location: index.php"); exit;
}
if (isset($_POST['batch_update'])) {
    $days = date('t', strtotime($_POST['month'] . "-01"));
    $stmt = $conn->prepare("INSERT INTO daily_limits (date, max_num) VALUES (?, ?) ON DUPLICATE KEY UPDATE max_num = ?");
    for ($d=1; $d<=$days; $d++) $stmt->execute([$_POST['month'].'-'.str_pad($d,2,'0',STR_PAD_LEFT), $_POST['limit'], $_POST['limit']]);
    $sys_msg = "<div class='alert success'>✅ 批量设置成功</div>";
}
if (isset($_POST['single_update'])) {
    $conn->prepare("INSERT INTO daily_limits (date, max_num) VALUES (?, ?) ON DUPLICATE KEY UPDATE max_num = ?")
         ->execute([$_POST['date'], $_POST['limit'], $_POST['limit']]);
    $sys_msg = "<div class='alert success'>✅ 设置成功</div>";
}

// ==================================================
// 3. 数据读取
// ==================================================
// 读取公告配置
$notice_status = $conn->query("SELECT value FROM settings WHERE name='notice_status'")->fetchColumn();
$notice_content = $conn->query("SELECT value FROM settings WHERE name='notice_content'")->fetchColumn();

// 读取列表和图表
$list = $conn->query("SELECT * FROM appointments ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$chart_data = $conn->query("SELECT DATE_FORMAT(book_time, '%d') as day, COUNT(*) as count FROM appointments WHERE DATE_FORMAT(book_time, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') GROUP BY DATE(book_time)")->fetchAll(PDO::FETCH_ASSOC);

$chart_json = []; foreach($chart_data as $r) $chart_json[intval($r['day'])] = $r['count'];
$final_labels = []; $final_counts = [];
for($i=1; $i<=date('t'); $i++){ $final_labels[]=$i."日"; $final_counts[]=isset($chart_json[$i])?$chart_json[$i]:0; }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>预约管理控制台</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <style>
        :root { --primary:#4f46e5; --primary-hover:#4338ca; --bg:#f3f4f6; --card:#fff; --text:#1f2937; --border:#e5e7eb; }
        body { margin:0; padding:0; font-family:-apple-system,BlinkMacSystemFont,Roboto,sans-serif; background:var(--bg); color:var(--text); }
        .container { max-width:1200px; margin:0 auto; padding:20px; }
        .navbar { background:var(--card); padding:15px 20px; display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; border-radius:8px; box-shadow:0 1px 2px rgba(0,0,0,0.05); }
        .brand { font-size:20px; font-weight:700; color:var(--primary); display:flex; align-items:center; gap:10px; }
        .nav-links a { text-decoration:none; color:#6b7280; margin-left:20px; display:inline-flex; align-items:center; gap:5px; transition:0.2s; }
        .nav-links a:hover { color:var(--primary); }
        
        .card { background:var(--card); border-radius:12px; padding:24px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom:24px; border:1px solid var(--border); }
        .card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
        .card-title { font-size:18px; font-weight:600; margin:0; display:flex; align-items:center; gap:8px; }
        
        .form-row { display:flex; gap:20px; flex-wrap:wrap; }
        .form-col { flex:1; min-width:300px; }
        
        input, select, textarea { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:6px; box-sizing:border-box; margin-bottom:10px; font-family:inherit; }
        input:focus, textarea:focus { border-color:var(--primary); outline:none; }
        .btn { padding:10px 16px; border-radius:6px; border:none; color:white; background:var(--primary); cursor:pointer; font-weight:500; display:inline-flex; align-items:center; gap:5px; }
        .btn:hover { background:var(--primary-hover); }
        .btn-sm { padding:5px 10px; font-size:12px; }
        .btn-danger { background:#ef4444; } .btn-danger:hover { background:#dc2626; }
        
        table { width:100%; border-collapse:collapse; font-size:14px; }
        th, td { padding:12px 15px; text-align:left; border-bottom:1px solid var(--border); }
        th { background:#f9fafb; font-weight:600; color:#6b7280; }
        .user-info { display:flex; align-items:center; gap:10px; }
        .avatar { width:32px; height:32px; background:#e0e7ff; color:var(--primary); border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; }
        
        .alert { padding:10px; border-radius:6px; margin-bottom:15px; display:flex; align-items:center; gap:8px; }
        .alert.success { background:#d1fae5; color:#065f46; } .alert.error { background:#fee2e2; color:#991b1b; }
        
        /* 开关样式 */
        .switch-label { display:flex; align-items:center; cursor:pointer; gap:10px; margin-bottom:10px; font-weight:500; }
        .switch-input { display:none; }
        .switch-slider { position:relative; width:40px; height:20px; background:#ccc; border-radius:20px; transition:0.3s; }
        .switch-slider:before { content:""; position:absolute; height:16px; width:16px; left:2px; bottom:2px; background:white; border-radius:50%; transition:0.3s; }
        .switch-input:checked + .switch-slider { background:var(--primary); }
        .switch-input:checked + .switch-slider:before { transform:translateX(20px); }
        
        /* 弹窗 */
        .modal { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); display:none; align-items:center; justify-content:center; z-index:999; }
        .modal-content { background:white; padding:25px; border-radius:12px; width:400px; animation:pop 0.3s; }
        @keyframes pop { from{transform:scale(0.9)} to{transform:scale(1)} }
    </style>
</head>
<body>

<div class="container">
    <div class="navbar">
        <div class="brand"><span class="material-symbols-outlined">calendar_month</span> 管理控制台</div>
        <div class="nav-links">
            <a href="../index.php" target="_blank"><span class="material-symbols-outlined">visibility</span> 前台</a>
            <a href="login.php" style="color:#ef4444"><span class="material-symbols-outlined">logout</span> 退出</a>
        </div>
    </div>
    
    <?= $sys_msg ?>

    <div class="form-row">
        <div class="form-col" style="flex:2">
            <div class="card" style="height:100%">
                <div class="card-header"><h3 class="card-title"><span class="material-symbols-outlined">bar_chart</span> 预约趋势</h3></div>
                <div style="height:250px"><canvas id="chart"></canvas></div>
            </div>
        </div>
        
        <div class="form-col" style="flex:1">
            <div class="card">
                <div class="card-header"><h3 class="card-title"><span class="material-symbols-outlined">campaign</span> 公告设置</h3></div>
                <form method="post">
                    <label class="switch-label">
                        <input type="checkbox" name="notice_status" class="switch-input" <?= $notice_status == '1' ? 'checked' : '' ?>>
                        <span class="switch-slider"></span>
                        <span>启用前端公告</span>
                    </label>
                    <textarea name="notice_content" rows="3" placeholder="请输入公告内容..."><?= htmlspecialchars($notice_content) ?></textarea>
                    <button type="submit" name="save_notice" class="btn" style="width:100%">保存公告</button>
                </form>
            </div>
            
            <div class="card">
                <div class="card-header"><h3 class="card-title"><span class="material-symbols-outlined">tune</span> 限额控制</h3></div>
                <form method="post" style="display:flex; gap:5px; margin-bottom:10px;">
                    <input type="month" name="month" value="<?= date('Y-m') ?>" required style="margin:0">
                    <input type="number" name="limit" placeholder="50" style="width:60px; margin:0" required>
                    <button name="batch_update" class="btn btn-sm">批量</button>
                </form>
                <form method="post" style="display:flex; gap:5px;">
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" required style="margin:0">
                    <input type="number" name="limit" placeholder="20" style="width:60px; margin:0" required>
                    <button name="single_update" class="btn btn-sm" style="background:#4b5563">单日</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title"><span class="material-symbols-outlined">list_alt</span> 最新预约</h3></div>
        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>用户</th><th>日期</th><th>留言</th><th style="text-align:right">操作</th></tr></thead>
                <tbody>
                    <?php foreach($list as $r): ?>
                    <tr>
                        <td>
                            <div class="user-info">
                                <div class="avatar"><?= mb_substr($r['name'],0,1) ?></div>
                                <div><div><?= htmlspecialchars($r['name']) ?></div><div style="font-size:12px;color:#999"><?= htmlspecialchars($r['phone']) ?></div></div>
                            </div>
                        </td>
                        <td><?= date('Y-m-d', strtotime($r['book_time'])) ?></td>
                        <td style="color:#666; font-size:13px; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap"><?= htmlspecialchars($r['message']) ?></td>
                        <td style="text-align:right">
                            <button onclick='edit(<?= json_encode($r) ?>)' class="btn btn-sm" style="background:#e0e7ff; color:#4338ca">编辑</button>
                            <a href="?del=<?= $r['id'] ?>" onclick="return confirm('删?')" class="btn btn-sm btn-danger" style="text-decoration:none">删除</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal" id="editModal" onclick="if(event.target==this)this.style.display='none'">
    <div class="modal-content">
        <h3 style="margin-top:0">✏️ 编辑预约</h3>
        <form method="post">
            <input type="hidden" name="edit_id" id="eid">
            <label>姓名</label><input name="edit_name" id="ename" required>
            <label>联系方式</label><input name="edit_phone" id="ephone" required>
            <label>日期</label><input type="date" name="edit_date" id="edate" required>
            <label>留言</label><textarea name="edit_message" id="emsg"></textarea>
            <button type="submit" name="update_appointment" class="btn" style="width:100%">保存</button>
        </form>
    </div>
</div>

<script>
    new Chart(document.getElementById('chart'), { type:'line', data:{labels:<?=json_encode($final_labels)?>,datasets:[{data:<?=json_encode($final_counts)?>,borderColor:'#4f46e5',backgroundColor:'rgba(79,70,229,0.1)',fill:true,tension:0.4}]}, options:{plugins:{legend:{display:false}},scales:{x:{grid:{display:false}},y:{beginAtZero:true}},maintainAspectRatio:false} });
    function edit(d) {
        document.getElementById('editModal').style.display='flex';
        document.getElementById('eid').value = d.id;
        document.getElementById('ename').value = d.name;
        document.getElementById('ephone').value = d.phone;
        document.getElementById('edate').value = d.book_time.split(' ')[0];
        document.getElementById('emsg').value = d.message;
    }
</script>
</body>
</html>
