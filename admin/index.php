<?php
session_start();
if (!isset($_SESSION['is_admin'])) { header("Location: login.php"); exit; }
require '../config.php';

// ==================================================
// 1. 【自动升级】检测并创建限额表 (无需手动运行SQL)
// ==================================================
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS daily_limits (
        date DATE PRIMARY KEY,
        max_num INT NOT NULL DEFAULT 20
    )");
} catch (Exception $e) { /* 忽略错误 */ }

// ==================================================
// 2. 处理设置请求 (批量 & 单日)
// ==================================================
$sys_msg = '';
// A. 批量修改整月
if (isset($_POST['batch_update'])) {
    $month = $_POST['month']; // 格式 2023-10
    $limit = (int)$_POST['limit'];
    
    // 计算该月有多少天
    $days_in_month = date('t', strtotime($month . "-01"));
    
    try {
        $sql = "INSERT INTO daily_limits (date, max_num) VALUES (?, ?) ON DUPLICATE KEY UPDATE max_num = ?";
        $stmt = $conn->prepare($sql);
        
        for ($d = 1; $d <= $days_in_month; $d++) {
            $current_date = $month . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
            $stmt->execute([$current_date, $limit, $limit]);
        }
        $sys_msg = "<div class='alert success'>✅ 已将 {$month} 全月每日限额设置为 {$limit} 人</div>";
    } catch (Exception $e) {
        $sys_msg = "<div class='alert error'>❌ 设置失败：" . $e->getMessage() . "</div>";
    }
}

// B. 单日修改
if (isset($_POST['single_update'])) {
    $date = $_POST['date'];
    $limit = (int)$_POST['limit'];
    try {
        $stmt = $conn->prepare("INSERT INTO daily_limits (date, max_num) VALUES (?, ?) ON DUPLICATE KEY UPDATE max_num = ?");
        $stmt->execute([$date, $limit, $limit]);
        $sys_msg = "<div class='alert success'>✅ 已将 {$date} 的限额设置为 {$limit} 人</div>";
    } catch (Exception $e) {
        $sys_msg = "<div class='alert error'>❌ 设置失败</div>";
    }
}

// C. 删除预约
if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    $conn->prepare("DELETE FROM appointments WHERE id = ?")->execute([$id]);
    header("Location: index.php"); exit;
}

// ==================================================
// 3. 数据读取
// ==================================================
// 获取预约列表
$stmt = $conn->query("SELECT * FROM appointments ORDER BY created_at DESC LIMIT 50");
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取图表数据
$sql_chart = "SELECT DATE_FORMAT(book_time, '%d') as day, COUNT(*) as count 
              FROM appointments 
              WHERE DATE_FORMAT(book_time, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') 
              GROUP BY DATE(book_time)";
$chart_data = $conn->query($sql_chart)->fetchAll(PDO::FETCH_ASSOC);

// 整理图表数据供JS使用
$chart_json = [];
foreach($chart_data as $row) {
    $chart_json[intval($row['day'])] = $row['count'];
}
// 补全当月每天的数据（为了显示哪里快满了）
$days_in_current_month = date('t');
$final_chart_labels = [];
$final_chart_counts = [];
for($i=1; $i<=$days_in_current_month; $i++){
    $final_chart_labels[] = $i . "日";
    $final_chart_counts[] = isset($chart_json[$i]) ? $chart_json[$i] : 0;
}

// 统计本月总数
$total_month = array_sum($final_chart_counts);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>预约管理后台</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { --primary: #4a90e2; --bg: #f0f2f5; --white: #fff; --text: #333; --danger: #ff4d4f; --success: #52c41a; }
        body { margin: 0; padding: 20px; font-family: -apple-system, sans-serif; background: var(--bg); color: var(--text); }
        .dashboard { max-width: 1000px; margin: 0 auto; }
        .nav-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .nav-actions a { margin-left: 15px; color: #666; text-decoration: none; font-size: 14px; }
        
        /* 卡片样式 */
        .card { background: var(--white); border-radius: 12px; padding: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .card-title { margin-top: 0; font-size: 16px; margin-bottom: 20px; border-left: 4px solid var(--primary); padding-left: 10px; }
        
        /* 表单样式 */
        .row { display: flex; gap: 20px; flex-wrap: wrap; }
        .col { flex: 1; min-width: 280px; }
        .form-box { background: #f9f9f9; padding: 15px; border-radius: 8px; }
        .form-box h4 { margin: 0 0 15px 0; font-size: 14px; color: #666; }
        input, select { padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-right: 10px; }
        button { padding: 8px 15px; background: var(--primary); color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { opacity: 0.9; }

        /* 表格与提示 */
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px 10px; border-bottom: 1px solid #eee; text-align: left; }
        .tag-date { background: #e6f7ff; color: #1890ff; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
        .alert { padding: 10px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .alert.success { background: #f6ffed; border: 1px solid #b7eb8f; color: #389e0d; }
        .alert.error { background: #fff2f0; border: 1px solid #ffccc7; color: #cf1322; }

        @media (max-width: 600px) { .row { gap: 10px; } input { width: 100%; margin-bottom: 10px; } }
    </style>
</head>
<body>

<div class="dashboard">
    <div class="nav-bar">
        <h2>📅 管理后台</h2>
        <div class="nav-actions">
            <a href="../index.php" target="_blank">预览前台</a>
            <a href="login.php" style="color:var(--danger)">退出</a>
        </div>
    </div>

    <?= $sys_msg ?>

    <div class="card">
        <h3 class="card-title">⚙️ 名额设置 (默认每天20人)</h3>
        <div class="row">
            <div class="col form-box">
                <h4>📅 按月批量设置</h4>
                <form method="post">
                    <input type="month" name="month" value="<?= date('Y-m') ?>" required>
                    <input type="number" name="limit" placeholder="每天名额 (如 50)" required style="width:120px">
                    <button type="submit" name="batch_update">批量应用</button>
                </form>
            </div>
            <div class="col form-box">
                <h4>✏️ 单日单独调整</h4>
                <form method="post">
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
                    <input type="number" name="limit" placeholder="名额" required style="width:80px">
                    <button type="submit" name="single_update">修改</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card">
        <h3 class="card-title">📈 本月热度 (总计: <?= $total_month ?>)</h3>
        <div style="height: 250px;">
            <canvas id="adminChart"></canvas>
        </div>
    </div>

    <div class="card">
        <h3 class="card-title">📝 最新预约</h3>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr><th>ID</th><th>昵称</th><th>联系方式</th><th>预约日期</th><th>操作</th></tr>
                </thead>
                <tbody>
                    <?php foreach($list as $item): ?>
                    <tr>
                        <td>#<?= $item['id'] ?></td>
                        <td><?= htmlspecialchars($item['name']) ?></td>
                        <td><?= htmlspecialchars($item['phone']) ?></td>
                        <td><span class="tag-date"><?= date('Y-m-d', strtotime($item['book_time'])) ?></span></td>
                        <td><a href="?del=<?= $item['id'] ?>" style="color:red;text-decoration:none" onclick="return confirm('确定删除？')">删除</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const ctx = document.getElementById('adminChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($final_chart_labels) ?>,
            datasets: [{
                label: '已预约人数',
                data: <?= json_encode($final_chart_counts) ?>,
                backgroundColor: 'rgba(74, 144, 226, 0.6)',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, grid: { borderDash: [5, 5] } }, x: { grid: { display: false } } }
        }
    });
</script>
</body>
</html>
