<?php
if (!file_exists('config.php') || filesize('config.php') < 10) { header("Location: install.php"); exit; }
require_once 'config.php';
if (!isset($conn)) { echo "Error"; exit; }

// 【新增】读取公告信息
$notice_sql = "SELECT * FROM settings WHERE name IN ('notice_status', 'notice_content')";
$settings = [];
try {
    $stmt = $conn->query($notice_sql);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['name']] = $row['value'];
    }
} catch (Exception $e) {}

// API处理
if (isset($_GET['get_chart_data'])) {
    header('Content-Type: application/json');
    $sql = "SELECT DATE_FORMAT(book_time, '%d') as day, COUNT(*) as count FROM appointments WHERE DATE_FORMAT(book_time, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') GROUP BY DATE(book_time)";
    echo json_encode(['status'=>'success', 'data'=>$conn->query($sql)->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$msg = ''; $msg_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = strip_tags($_POST['name']);
    $contact = strip_tags($_POST['contact']);
    $date = $_POST['date'];
    $message = strip_tags($_POST['message']);
    
    // 检查限额
    $limit = 20;
    $stmt = $conn->prepare("SELECT max_num FROM daily_limits WHERE date = ?");
    $stmt->execute([$date]);
    if ($row = $stmt->fetch()) $limit = $row['max_num'];
    
    $cnt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE DATE(book_time) = ?");
    $cnt->execute([$date]);
    
    if ($cnt->fetchColumn() >= $limit) {
        $msg = "⚠️ 该日期 ({$date}) 名额已满，请更换时间。";
        $msg_type = "error";
    } else {
        try {
            $conn->prepare("INSERT INTO appointments (name, phone, book_time, message) VALUES (?, ?, ?, ?)")
                 ->execute([$name, $contact, $date . " 09:00:00", $message]);
            $msg = "✅ 预约提交成功！";
            $msg_type = "success";
        } catch (Exception $e) { $msg = "提交失败"; $msg_type = "error"; }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>在线预约</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <style>
        :root { --primary:#4f46e5; --bg:#f3f4f6; --card:#fff; }
        body { font-family:-apple-system,BlinkMacSystemFont,sans-serif; background:var(--bg); color:#1f2937; margin:0; padding:20px; min-height:100vh; display:flex; align-items:center; justify-content:center; }
        .container { background:var(--card); width:100%; max-width:450px; padding:30px; border-radius:16px; box-shadow:0 10px 25px rgba(0,0,0,0.05); position:relative; overflow:hidden; }
        
        .header { text-align:center; margin-bottom:25px; }
        .header h1 { margin:0 0 5px 0; font-size:24px; color:#111; }
        .header p { margin:0; color:#666; font-size:14px; }
        
        /* 公告栏样式 */
        .notice-box { background:#fff7ed; border:1px solid #fed7aa; color:#c2410c; padding:12px; border-radius:8px; margin-bottom:20px; font-size:14px; display:flex; gap:10px; line-height:1.5; align-items:start; }
        .notice-icon { font-size:18px; margin-top:1px; flex-shrink:0; }

        label { display:block; font-size:13px; font-weight:600; color:#4b5563; margin-top:15px; margin-bottom:5px; }
        input, textarea { width:100%; padding:12px; border:1px solid #e5e7eb; border-radius:8px; background:#f9fafb; box-sizing:border-box; font-size:14px; font-family:inherit; }
        input:focus, textarea:focus { border-color:var(--primary); outline:none; background:#fff; }
        
        button { width:100%; padding:14px; background:var(--primary); color:white; border:none; border-radius:8px; font-size:16px; font-weight:600; cursor:pointer; margin-top:25px; transition:0.2s; }
        button:hover { background:#4338ca; }
        
        .alert { padding:12px; border-radius:8px; text-align:center; margin-bottom:20px; font-size:14px; }
        .alert.success { background:#d1fae5; color:#065f46; } .alert.error { background:#fee2e2; color:#991b1b; }
        
        .chart-area { margin-top:30px; padding-top:20px; border-top:1px dashed #e5e7eb; }
        .footer { text-align:center; margin-top:20px; font-size:12px; color:#9ca3af; }
        .footer a { color:inherit; text-decoration:none; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>预约服务</h1>
        <p>请填写下方信息完成登记</p>
    </div>

    <?php if (isset($settings['notice_status']) && $settings['notice_status'] == '1'): ?>
    <div class="notice-box">
        <span class="material-symbols-outlined notice-icon">campaign</span>
        <span><?= nl2br(htmlspecialchars($settings['notice_content'])) ?></span>
    </div>
    <?php endif; ?>

    <?php if($msg): ?>
        <div class="alert <?= $msg_type ?>"><?= $msg ?></div>
    <?php endif; ?>

    <form method="post">
        <label>您的微信名 / 电报名</label>
        <input type="text" name="name" required placeholder="请输入您的昵称">
        
        <label>微信号 / 电报号</label>
        <input type="text" name="contact" required placeholder="请输入您的账号ID">

        <label>预约日期</label>
        <input type="date" name="date" required id="datePicker">
        
        <label>留言备注 (选填)</label>
        <textarea name="message" rows="3" maxlength="100" placeholder="如有特殊需求请告知..."></textarea>
        
        <button type="submit">立即提交预约</button>
    </form>

    <div class="chart-area">
        <div style="text-align:center; font-size:12px; color:#9ca3af; margin-bottom:10px; font-weight:600;">📅 本月预约热度</div>
        <canvas id="userChart"></canvas>
    </div>
    
    <div class="footer"><a href="admin/">进入管理员后台</a></div>
</div>

<script>
    document.getElementById('datePicker').valueAsDate = new Date();
    fetch('?get_chart_data=1').then(r=>r.json()).then(res=>{
        if(res.status==='success') {
            const l=res.data.map(i=>i.day+'日'), d=res.data.map(i=>i.count);
            new Chart(document.getElementById('userChart'), {
                type:'bar', data:{labels:l,datasets:[{data:d,backgroundColor:'#4f46e5',borderRadius:4}]},
                options:{plugins:{legend:{display:false}},scales:{x:{grid:{display:false}},y:{ticks:{stepSize:1}}}}
            });
        }
    });
</script>
</body>
</html>
