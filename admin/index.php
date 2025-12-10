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
    if(!$conn->query("SELECT * FROM settings WHERE name='notice_status'")->fetch()) {
        $conn->exec("INSERT INTO settings (name, value) VALUES ('notice_status', '0'), ('notice_content', '欢迎预约！')");
    }
} catch (Exception $e) {}

$sys_msg = '';
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// ==================================================
// 2. 核心逻辑处理
// ==================================================

// A. 修改管理员账号密码
if (isset($_POST['update_account'])) {
    $cur_pass = $_POST['cur_pass'];
    $new_user = strip_tags($_POST['new_user']);
    $new_pass = $_POST['new_pass'];

    $stmt = $conn->query("SELECT * FROM admins LIMIT 1");
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin && password_verify($cur_pass, $admin['password'])) {
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE admins SET username = ?, password = ? WHERE id = ?");
        if ($update->execute([$new_user, $new_hash, $admin['id']])) {
            $sys_msg = "<div class='toast success'><span class='material-symbols-outlined'>check_circle</span> 账号修改成功！下次登录请使用新密码。</div>";
        } else {
            $sys_msg = "<div class='toast error'><span class='material-symbols-outlined'>error</span> 数据库更新失败。</div>";
        }
    } else {
        $sys_msg = "<div class='toast error'><span class='material-symbols-outlined'>block</span> 旧密码错误，拒绝操作。</div>";
    }
}

// B. 保存公告 & TG 配置
if (isset($_POST['save_notice'])) {
    $status = isset($_POST['notice_status']) ? '1' : '0';
    $conn->prepare("INSERT INTO settings (name, value) VALUES ('notice_status', ?) ON DUPLICATE KEY UPDATE value = ?")->execute([$status, $status]);
    $conn->prepare("INSERT INTO settings (name, value) VALUES ('notice_content', ?) ON DUPLICATE KEY UPDATE value = ?")->execute([$_POST['notice_content'], $_POST['notice_content']]);
    
    $tg_token = trim($_POST['tg_bot_token']);
    $tg_id = trim($_POST['tg_chat_id']);
    $conn->prepare("INSERT INTO settings (name, value) VALUES ('tg_bot_token', ?) ON DUPLICATE KEY UPDATE value = ?")->execute([$tg_token, $tg_token]);
    $conn->prepare("INSERT INTO settings (name, value) VALUES ('tg_chat_id', ?) ON DUPLICATE KEY UPDATE value = ?")->execute([$tg_id, $tg_id]);

    $sys_msg = "<div class='toast success'><span class='material-symbols-outlined'>check_circle</span> 系统设置已更新</div>";
}

// C. 编辑/删除预约
if (isset($_POST['update_appointment'])) {
    $book_time = $_POST['edit_date'] . " 09:00:00";
    // 注意：这里的 edit_name 对应 "微信名"，edit_phone 对应 "微信号"
    $conn->prepare("UPDATE appointments SET name=?, phone=?, book_time=?, message=? WHERE id=?")
          ->execute([$_POST['edit_name'], $_POST['edit_phone'], $book_time, $_POST['edit_message'], $_POST['edit_id']]);
    $sys_msg = "<div class='toast success'><span class='material-symbols-outlined'>check_circle</span> 预约信息已更新</div>";
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
    $sys_msg = "<div class='toast success'><span class='material-symbols-outlined'>check_circle</span> 批量设置成功</div>";
}
if (isset($_POST['single_update_modal'])) {
    $conn->prepare("INSERT INTO daily_limits (date, max_num) VALUES (?, ?) ON DUPLICATE KEY UPDATE max_num = ?")
          ->execute([$_POST['modal_limit_date'], $_POST['modal_limit_num'], $_POST['modal_limit_num']]);
    $sys_msg = "<div class='toast success'><span class='material-symbols-outlined'>check_circle</span> 限额已修改</div>";
}

// ==================================================
// 3. 数据读取
// ==================================================
$admin_info = $conn->query("SELECT username FROM admins LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$current_username = $admin_info ? $admin_info['username'] : 'admin';

$notice_status = $conn->query("SELECT value FROM settings WHERE name='notice_status'")->fetchColumn();
$notice_content = $conn->query("SELECT value FROM settings WHERE name='notice_content'")->fetchColumn();
$tg_bot_token = $conn->query("SELECT value FROM settings WHERE name='tg_bot_token'")->fetchColumn();
$tg_chat_id = $conn->query("SELECT value FROM settings WHERE name='tg_chat_id'")->fetchColumn();

$list = $conn->query("SELECT * FROM appointments ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$chart_data = $conn->query("SELECT DATE_FORMAT(book_time, '%d') as day, COUNT(*) as count FROM appointments WHERE DATE_FORMAT(book_time, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') GROUP BY DATE(book_time)")->fetchAll(PDO::FETCH_ASSOC);
$chart_json = []; foreach($chart_data as $r) $chart_json[intval($r['day'])] = $r['count'];
$final_labels = []; $final_counts = [];
for($i=1; $i<=date('t'); $i++){ $final_labels[]=$i."日"; $final_counts[]=isset($chart_json[$i])?$chart_json[$i]:0; }

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
    <title>预约管理控制台</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <style>
        :root { 
            --primary: #4338ca; --primary-light: #e0e7ff; --primary-hover: #3730a3;
            --bg: #f3f4f6; --card-bg: #ffffff; 
            --text-main: #111827; --text-muted: #6b7280; --border: #e5e7eb;
            --danger: #ef4444; --danger-bg: #fef2f2; --success: #10b981; --warning: #f59e0b; 
        }
        
        body { margin: 0; padding: 0; font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text-main); -webkit-font-smoothing: antialiased; }
        
        /* 布局 */
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .dashboard-grid { display: grid; grid-template-columns: 1fr 350px; gap: 24px; }
        @media (max-width: 1024px) { .dashboard-grid { grid-template-columns: 1fr; } }

        /* 顶部导航 */
        .navbar { 
            background: var(--card-bg); padding: 16px 24px; border-radius: 12px; 
            display: flex; justify-content: space-between; align-items: center; 
            box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); margin-bottom: 24px;
        }
        .brand { font-size: 18px; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 8px; }
        .nav-actions a { 
            text-decoration: none; color: var(--text-muted); font-size: 14px; font-weight: 500; 
            margin-left: 20px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; 
        }
        .nav-actions a:hover { color: var(--primary); }
        .nav-actions a.logout:hover { color: var(--danger); }

        /* 卡片通用样式 */
        .card { 
            background: var(--card-bg); border-radius: 16px; padding: 24px; 
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.02);
            border: 1px solid rgba(229, 231, 235, 0.5);
            margin-bottom: 24px; transition: transform 0.2s;
        }
        .card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card-title { font-size: 16px; font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 8px; margin: 0; }
        
        /* 表单元素 */
        input, select, textarea { 
            width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; 
            background: #f9fafb; font-size: 14px; color: var(--text-main); transition: 0.2s; box-sizing: border-box; margin-bottom: 12px;
        }
        input:focus, select:focus, textarea:focus { 
            outline: none; border-color: var(--primary); background: #fff; 
            box-shadow: 0 0 0 3px var(--primary-light); 
        }
        
        .btn { 
            padding: 10px 16px; border-radius: 8px; border: none; font-size: 14px; font-weight: 500; 
            cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; 
            transition: 0.2s; text-decoration: none;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-hover); box-shadow: 0 4px 12px rgba(67, 56, 202, 0.2); }
        .btn-danger { background: var(--danger-bg); color: var(--danger); }
        .btn-danger:hover { background: #fee2e2; }
        .btn-ghost { background: transparent; color: var(--text-muted); border: 1px solid var(--border); }
        .btn-ghost:hover { border-color: var(--text-muted); color: var(--text-main); }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        /* 开关 Switch */
        .switch-label { display: flex; align-items: center; cursor: pointer; gap: 12px; margin-bottom: 16px; user-select: none; }
        .switch-input { display: none; }
        .switch-track { 
            position: relative; width: 44px; height: 24px; background: #e5e7eb; 
            border-radius: 24px; transition: 0.3s; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
        }
        .switch-track:after { 
            content: ""; position: absolute; height: 20px; width: 20px; left: 2px; bottom: 2px; 
            background: white; border-radius: 50%; transition: 0.3s; box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .switch-input:checked + .switch-track { background: var(--primary); }
        .switch-input:checked + .switch-track:after { transform: translateX(20px); }

        /* 日历 Grid */
        .calendar-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 12px; }
        .day-cell { 
            background: #f9fafb; border: 1px solid var(--border); border-radius: 10px; padding: 12px 10px; 
            cursor: pointer; position: relative; overflow: hidden; transition: 0.2s; text-align: center;
        }
        .day-cell:hover { border-color: var(--primary); transform: translateY(-2px); }
        .day-num { font-size: 15px; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: block; }
        .day-stats { font-size: 12px; color: var(--text-muted); margin-bottom: 8px; }
        .progress-track { height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden; width: 100%; }
        .progress-bar { height: 100%; transition: width 0.4s ease; border-radius: 3px; }
        .status-normal .progress-bar { background: var(--success); }
        .status-warn .progress-bar { background: var(--warning); }
        .status-full .progress-bar { background: var(--danger); }
        .status-full { background: var(--danger-bg); border-color: #fecaca; }

        /* 表格 */
        .table-responsive { overflow-x: auto; border-radius: 8px; border: 1px solid var(--border); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 600px; }
        th { background: #f9fafb; padding: 14px 16px; text-align: left; font-weight: 600; color: var(--text-muted); border-bottom: 1px solid var(--border); white-space: nowrap; }
        td { padding: 14px 16px; border-bottom: 1px solid var(--border); color: var(--text-main); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f9fafb; }
        
        .user-info { display: flex; align-items: center; gap: 12px; }
        .avatar { 
            width: 36px; height: 36px; background: var(--primary-light); color: var(--primary); 
            border-radius: 50%; display: flex; align-items: center; justify-content: center; 
            font-weight: 600; font-size: 14px; flex-shrink: 0;
        }
        .id-badge {
            font-family: monospace; font-size: 13px; color: var(--primary);
            background: #e0e7ff; padding: 4px 8px; border-radius: 6px; display: inline-block;
            letter-spacing: 0.5px;
        }
        .msg-cell { max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-muted); }

        /* Toast 提示 */
        .toast { 
            position: fixed; top: 20px; right: 20px; z-index: 1000; padding: 16px 20px; 
            border-radius: 8px; display: flex; align-items: center; gap: 10px; 
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); animation: slideIn 0.3s;
        }
        .toast.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .toast.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

        /* Modal */
        .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 999; }
        .modal-content { background: white; width: 90%; max-width: 420px; padding: 30px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); animation: modalPop 0.2s cubic-bezier(0.34, 1.56, 0.64, 1); }
        @keyframes modalPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .modal h3 { margin-top: 0; margin-bottom: 20px; font-size: 18px; display: flex; align-items: center; gap: 8px; }
    </style>
</head>
<body>

<div class="container">
    <?= $sys_msg ?>

    <div class="navbar">
        <div class="brand">
            <span class="material-symbols-outlined" style="font-size: 28px;">admin_panel_settings</span>
            <span>管理控制台</span>
        </div>
        <div class="nav-actions">
            <a href="../index.php" target="_blank"><span class="material-symbols-outlined">visibility</span> 查看前台</a>
            <a href="login.php" class="logout"><span class="material-symbols-outlined">logout</span> 退出登录</a>
        </div>
    </div>

    <div class="dashboard-grid">
        
        <div class="main-column">
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><span class="material-symbols-outlined" style="color:var(--primary)">monitoring</span> 近期预约走势</h3>
                    <span style="font-size:12px; color:var(--text-muted)">本月数据概览</span>
                </div>
                <div style="height: 300px; width: 100%;">
                    <canvas id="chart"></canvas>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><span class="material-symbols-outlined" style="color:var(--primary)">calendar_month</span> 每日限额监控</h3>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <input type="month" name="month" value="<?= $current_month ?>" onchange="window.location.href='?month='+this.value" style="width: auto; margin:0; padding: 6px 10px;">
                        <button class="btn btn-ghost btn-sm" onclick="document.getElementById('batchModal').style.display='flex'">批量设置</button>
                    </div>
                </div>
                <div class="calendar-grid">
                    <?php foreach($calendar_data as $day): ?>
                        <?php 
                            $percent = $day['percent'];
                            $status_class = 'status-normal';
                            if ($percent >= 100) $status_class = 'status-full';
                            elseif ($percent >= 80) $status_class = 'status-warn';
                        ?>
                        <div class="day-cell <?= $status_class ?>" onclick="openLimitModal('<?= $day['date'] ?>', <?= $day['limit'] ?>)">
                            <span class="day-num"><?= $day['day'] ?>日</span>
                            <div class="day-stats"><?= $day['used'] ?> <span style="color:#9ca3af">/ <?= $day['limit'] ?></span></div>
                            <div class="progress-track">
                                <div class="progress-bar" style="width: <?= $percent ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><span class="material-symbols-outlined" style="color:var(--primary)">list_alt</span> 最新预约记录</h3>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>您的微信名 / 电报名</th>
                                <th>微信号 / 电报号</th>
                                <th>预约日期</th>
                                <th>留言备注</th>
                                <th style="text-align:right">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($list as $r): ?>
                            <tr>
                                <td>
                                    <div class="user-info">
                                        <div class="avatar"><?= mb_substr($r['name'],0,1) ?></div>
                                        <div style="font-weight:500"><?= htmlspecialchars($r['name']) ?></div>
                                    </div>
                                </td>
                                <td>
                                    <span class="id-badge"><?= htmlspecialchars($r['phone']) ?></span>
                                </td>
                                <td>
                                    <div style="font-weight:500"><?= date('m-d', strtotime($r['book_time'])) ?></div>
                                    <div style="font-size:12px; color:#999"><?= date('Y', strtotime($r['book_time'])) ?></div>
                                </td>
                                <td class="msg-cell" title="<?= htmlspecialchars($r['message']) ?>">
                                    <?= $r['message'] ? htmlspecialchars($r['message']) : '<span style="color:#ccc">无留言</span>' ?>
                                </td>
                                <td style="text-align:right">
                                    <button onclick='editAppt(<?= json_encode($r) ?>)' class="btn btn-ghost btn-sm" title="编辑">
                                        <span class="material-symbols-outlined" style="font-size:16px;">edit</span>
                                    </button>
                                    <a href="?del=<?= $r['id'] ?>" onclick="return confirm('确认删除该预约？此操作不可恢复。')" class="btn btn-ghost btn-sm" style="color:var(--danger)" title="删除">
                                        <span class="material-symbols-outlined" style="font-size:16px;">delete</span>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <div class="side-column">
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><span class="material-symbols-outlined">settings_applications</span> 系统配置</h3>
                </div>
                <form method="post">
                    <label class="switch-label">
                        <input type="checkbox" name="notice_status" class="switch-input" <?= $notice_status=='1'?'checked':'' ?>>
                        <span class="switch-track"></span>
                        <span>启用前台公告</span>
                    </label>
                    <textarea name="notice_content" rows="4" placeholder="在此输入展示给用户的公告内容..."><?= htmlspecialchars($notice_content) ?></textarea>
                    
                    <div style="margin-top:20px; padding-top:20px; border-top:1px dashed var(--border);">
                        <h4 style="margin:0 0 10px 0; font-size:14px; color:var(--text-muted); display:flex; align-items:center; gap:5px;">
                            <span class="material-symbols-outlined" style="font-size:18px">send</span> TG 推送配置
                        </h4>
                        <input type="text" name="tg_bot_token" value="<?= htmlspecialchars($tg_bot_token ?? '') ?>" placeholder="Bot Token" style="font-size:13px;">
                        <input type="text" name="tg_chat_id" value="<?= htmlspecialchars($tg_chat_id ?? '') ?>" placeholder="Chat ID" style="font-size:13px;">
                    </div>

                    <button type="submit" name="save_notice" class="btn btn-primary" style="width:100%; margin-top:10px;">保存设置</button>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><span class="material-symbols-outlined">shield_person</span> 管理员账号</h3>
                </div>
                <form method="post">
                    <div style="background:#fef2f2; padding:10px; border-radius:8px; margin-bottom:15px; border:1px solid #fecaca;">
                        <input type="password" name="cur_pass" placeholder="验证当前密码" required style="border:1px solid #fca5a5; background:white; margin-bottom:0;">
                    </div>
                    <label style="font-size:12px; color:var(--text-muted); margin-bottom:5px; display:block">设置新信息</label>
                    <input type="text" name="new_user" placeholder="用户名" value="<?= htmlspecialchars($current_username) ?>" required>
                    <input type="password" name="new_pass" placeholder="新密码" required>
                    <button type="submit" name="update_account" class="btn btn-danger" style="width:100%">确认修改</button>
                </form>
            </div>

        </div>
    </div>
</div>

<div class="modal" id="editModal" onclick="if(event.target==this)this.style.display='none'">
    <div class="modal-content">
        <h3><span class="material-symbols-outlined">edit_calendar</span> 编辑预约</h3>
        <form method="post">
            <input type="hidden" name="edit_id" id="eid">
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <div><label style="font-size:12px;color:#666">您的微信名 / 电报名</label><input name="edit_name" id="ename" required></div>
                <div><label style="font-size:12px;color:#666">微信号 / 电报号</label><input name="edit_phone" id="ephone" required></div>
            </div>
            
            <label style="font-size:12px;color:#666">预约日期</label>
            <input type="date" name="edit_date" id="edate" required>
            
            <label style="font-size:12px;color:#666">用户留言</label>
            <textarea name="edit_message" id="emsg" rows="3"></textarea>
            
            <button type="submit" name="update_appointment" class="btn btn-primary" style="width:100%">保存修改</button>
        </form>
    </div>
</div>

<div class="modal" id="limitModal" onclick="if(event.target==this)this.style.display='none'">
    <div class="modal-content">
        <h3><span class="material-symbols-outlined">tune</span> 修改单日限额</h3>
        <form method="post">
            <label style="font-size:12px;color:#666">选定日期</label>
            <input type="date" name="modal_limit_date" id="limit_date_input" readonly style="background:#e5e7eb; cursor:not-allowed">
            
            <label style="font-size:12px;color:#666">最大接待人数</label>
            <input type="number" name="modal_limit_num" id="limit_num_input" required style="font-size:18px; font-weight:bold;">
            
            <button type="submit" name="single_update_modal" class="btn btn-primary" style="width:100%">更新限额</button>
        </form>
    </div>
</div>

<div class="modal" id="batchModal" onclick="if(event.target==this)this.style.display='none'">
    <div class="modal-content">
        <h3><span class="material-symbols-outlined">date_range</span> 批量设置月度限额</h3>
        <p style="font-size:13px; color:#666; margin-bottom:15px;">此操作将覆盖该月所有日期的最大人数限制。</p>
        <form method="post">
            <label style="font-size:12px;color:#666">目标月份</label>
            <input type="month" name="month" value="<?= $current_month ?>" required>
            
            <label style="font-size:12px;color:#666">每日统一限额</label>
            <input type="number" name="limit" placeholder="例如: 20" required>
            
            <button type="submit" name="batch_update" class="btn btn-primary" style="width:100%">应用到全月</button>
        </form>
    </div>
</div>

<script>
    // 图表配置
    const ctx = document.getElementById('chart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($final_labels) ?>,
            datasets: [{
                label: '预约人数',
                data: <?= json_encode($final_counts) ?>,
                borderColor: '#4338ca',
                backgroundColor: 'rgba(67, 56, 202, 0.05)',
                borderWidth: 2,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#4338ca',
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: { beginAtZero: true, grid: { borderDash: [2, 4], color: '#f3f4f6' }, ticks: { stepSize: 1 } }
            }
        }
    });

    // 弹窗逻辑
    function editAppt(d) { 
        document.getElementById('editModal').style.display='flex'; 
        document.getElementById('eid').value = d.id; 
        document.getElementById('ename').value = d.name; 
        document.getElementById('ephone').value = d.phone; 
        document.getElementById('edate').value = d.book_time.split(' ')[0]; 
        document.getElementById('emsg').value = d.message; 
    }
    function openLimitModal(d, l) { 
        document.getElementById('limitModal').style.display='flex'; 
        document.getElementById('limit_date_input').value = d; 
        document.getElementById('limit_num_input').value = l; 
    }
    
    // 自动消失提示
    setTimeout(() => {
        const toasts = document.querySelectorAll('.toast');
        toasts.forEach(t => t.style.display = 'none');
    }, 3000);
</script>
</body>
</html>
