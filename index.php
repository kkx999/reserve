<?php
// 1. 配置检测
if (!file_exists('config.php') || filesize('config.php') < 10) { header("Location: install.php"); exit; }
require_once 'config.php';
if (!isset($conn)) { echo "数据库连接失败"; exit; }

// ==========================================
// 2. API: 获取图表数据 (用于前台显示热度)
// ==========================================
if (isset($_GET['get_chart_data'])) {
    header('Content-Type: application/json');
    $sql = "SELECT DATE_FORMAT(book_time, '%d') as day, COUNT(*) as count 
            FROM appointments 
            WHERE DATE_FORMAT(book_time, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') 
            GROUP BY DATE(book_time)";
    echo json_encode(['status'=>'success', 'data'=>$conn->query($sql)->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$msg = '';
$msg_type = '';

// ==========================================
// 3. 处理预约提交 (增加限额检查)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = strip_tags($_POST['name']);
    $contact = strip_tags($_POST['contact']); 
    $date = $_POST['date']; 
    
    // --- 核心逻辑：检查名额 ---
    // 1. 获取当天限额 (如果没有设置，默认20人)
    $stmt_limit = $conn->prepare("SELECT max_num FROM daily_limits WHERE date = ?");
    $stmt_limit->execute([$date]);
    $limit_row = $stmt_limit->fetch();
    $daily_max = $limit_row ? $limit_row['max_num'] : 20; // 默认值在这里修改

    // 2. 获取当天已预约人数
    $stmt_count = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE DATE(book_time) = ?");
    $stmt_count->execute([$date]);
    $current_count = $stmt_count->fetchColumn();

    if ($current_count >= $daily_max) {
        // 如果满了
        $msg = "⚠️ 抱歉，{$date} 的预约名额已满 ({$current_count}/{$daily_max})，请选择其他日期。";
        $msg_type = "error";
    } else {
        // 没满，允许写入
        try {
            $book_time = $date . " 09:00:00"; 
            $stmt = $conn->prepare("INSERT INTO appointments (name, phone, book_time) VALUES (?, ?, ?)");
            $stmt->execute([$name, $contact, $book_time]);
            $msg = "✅ 提交成功！已为您保留名额。";
            $msg_type = "success";
        } catch (Exception $e) {
            $msg = "❌ 提交失败，请重试。";
            $msg_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>在线预约</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { --primary: #4a90e2; --bg: #f0f2f5; --card: #fff; --text: #333; }
        body { font-family: -apple-system, sans-serif; background: var(--bg); color: var(--text); display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .container { background: var(--card); width: 100%; max-width: 450px; padding: 40px 30px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
        .header { text-align: center; margin-bottom: 25px; }
        input { width: 100%; padding: 12px; margin: 8px 0 20px 0; border: 1px solid #e1e4e8; border-radius: 8px; background: #f9f9f9; box-sizing: border-box; }
        button { width: 100%; padding: 14px; background: linear-gradient(135deg, #4a90e2, #357abd); color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; }
        .alert { padding: 12px; border-radius: 8px; font-size: 14px; text-align: center; margin-bottom: 20px; }
        .alert.success { background: #e6fffa; color: #2c7a7b; border: 1px solid #b2f5ea; }
        .alert.error { background: #fff5f5; color: #c53030; border: 1px solid #fed7d7; }
        .chart-box { margin-top: 30px; padding-top: 20px; border-top: 1px dashed #eee; }
        .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #999; }
        a { text-decoration: none; color: #999; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 style="margin:0 0 10px 0; font-size:24px;">预约登记</h1>
            <p style="color:#666; font-size:14px; margin:0;">请填写信息，名额有限先到先得</p>
        </div>

        <?php if($msg): ?>
            <div class="alert <?= $msg_type ?>"><?= $msg ?></div>
        <?php endif; ?>

        <form method="post">
            <label style="font-size:13px; font-weight:600; color:#666;">您的微信名或电报名</label>
            <input type="text" name="name" required placeholder="请输入昵称">
            
            <label style="font-size:13px; font-weight:600; color:#666;">微信号或电报号</label>
            <input type="text" name="contact" required placeholder="请输入ID">

            <label style="font-size:13px; font-weight:600; color:#666;">预约日期</label>
            <input type="date" name="date" required id="datePicker">
            
            <button type="submit">立即提交</button>
        </form>

        <div class="chart-box">
            <div style="text-align:center; font-size:12px; color:#888; margin-bottom:10px;">📅 本月预约热度</div>
            <canvas id="userChart"></canvas>
        </div>
        
        <div class="footer"><a href="admin/">管理员登录</a></div>
    </div>

    <script>
        document.getElementById('datePicker').valueAsDate = new Date();
        
        fetch('?get_chart_data=1').then(r=>r.json()).then(res=>{
            if(res.status==='success') {
                const labels = res.data.map(i => i.day + '日');
                const counts = res.data.map(i => i.count);
                new Chart(document.getElementById('userChart'), {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{ label: '人数', data: counts, backgroundColor: '#4a90e2', borderRadius: 4 }]
                    },
                    options: { plugins:{legend:{display:false}}, scales:{x:{grid:{display:false}}, y:{ticks:{stepSize:1}}} }
                });
            }
        });
    </script>
</body>
</html>
