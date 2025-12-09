<?php
session_start();
if (!isset($_SESSION['is_admin'])) { header("Location: login.php"); exit; }
require '../config.php';

// 1. 自动升级数据库（后台也加个保险，防止先访问后台报错）
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS daily_limits (date DATE PRIMARY KEY, max_num INT NOT NULL DEFAULT 20)");
    $conn->query("SELECT message FROM appointments LIMIT 1");
} catch (Exception $e) {
    try { $conn->exec("ALTER TABLE appointments ADD COLUMN message VARCHAR(255) DEFAULT ''"); } catch(Exception $ex){}
}

// 2. 批量与单日设置逻辑
$sys_msg = '';
if (isset($_POST['batch_update'])) {
    $month = $_POST['month']; $limit = (int)$_POST['limit'];
    $days = date('t', strtotime($month . "-01"));
    $stmt = $conn->prepare("INSERT INTO daily_limits (date, max_num) VALUES (?, ?) ON DUPLICATE KEY UPDATE max_num = ?");
    for ($d=1; $d<=$days; $d++) $stmt->execute([$month.'-'.str_pad($d,2,'0',STR_PAD_LEFT), $limit, $limit]);
    $sys_msg = "<div class='alert success'>✅ 设置成功</div>";
}
if (isset($_POST['single_update'])) {
    $stmt = $conn->prepare("INSERT INTO daily_limits (date, max_num) VALUES (?, ?) ON DUPLICATE KEY UPDATE max_num = ?");
    $stmt->execute([$_POST['date'], $_POST['limit'], $_POST['limit']]);
    $sys_msg = "<div class='alert success'>✅ 修改成功</div>";
}
if (isset($_GET['del'])) {
    $conn->prepare("DELETE FROM appointments WHERE id = ?")->execute([(int)$_GET['del']]);
    header("Location: index.php"); exit;
}

// 3. 读取数据
$list = $conn->query("SELECT * FROM appointments ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$chart_data = $conn->query("SELECT DATE_FORMAT(book_time, '%d') as day, COUNT(*) as count FROM appointments WHERE DATE_FORMAT(book_time, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') GROUP BY DATE(book_time)")->fetchAll(PDO::FETCH_ASSOC);

$chart_json = []; foreach($chart_data as $r) $chart_json[intval($r['day'])] = $r['count'];
$final_labels = []; $final_counts = [];
for($i=1; $i<=date('t'); $i++){ $final_labels[]=$i."日"; $final_counts[]=isset($chart_json[$i])?$chart_json[$i]:0; }
$total_month = array_sum($final_counts);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>后台管理</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { --primary: #4a90e2; --bg: #f0f2f5; --white: #fff; --text: #333; }
        body { margin: 0; padding: 20px; font-family: -apple-system, sans-serif; background: var(--bg); color: var(--text); }
        .dashboard { max-width: 1000px; margin: 0 auto; }
        .nav-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card { background: var(--white); border-radius: 12px; padding: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .row { display: flex; gap: 20px; flex-wrap: wrap; } .col { flex: 1; min-width: 280px; }
        input, button { padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: var(--primary); color: white; border: none; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px 10px; border-bottom: 1px solid #eee; text-align: left; }
        .alert { padding: 10px; background: #f6ffed; border: 1px solid #b7eb8f; color: #389e0d; margin-bottom: 20px; border-radius: 6px; }
        .msg-cell { max-width: 200px; color: #666; font-size: 13px; }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="nav-bar"><h2>📅 管理后台</h2><div><a href="../index.php" target="_blank">预览</a> <a href="login.php" style="color:red;margin-left:10px">退出</a></div></div>
    <?= $sys_msg ?>

    <div class="card">
        <h3>⚙️ 限额设置</h3>
        <div class="row">
            <div class="col"><form method="post">整月批量: <input type="month" name="month" value="<?= date('Y-m') ?>" required> <input type="number" name="limit" placeholder="50" style="width:60px" required> <button type="submit" name="batch_update">设置</button></form></div>
            <div class="col"><form method="post">单日修改: <input type="date" name="date" value="<?= date('Y-m-d') ?>" required> <input type="number" name="limit" placeholder="20" style="width:60px" required> <button type="submit" name="single_update">设置</button></form></div>
        </div>
    </div>

    <div class="card"><h3>📈 本月数据 (<?= $total_month ?>人)</h3><div style="height:250px"><canvas id="adminChart"></canvas></div></div>

    <div class="card">
        <h3>📝 预约列表</h3>
        <div style="overflow-x: auto;">
            <table>
                <thead><tr><th>ID</th><th>昵称</th><th>联系方式</th><th>日期</th><th>留言备注</th><th>操作</th></tr></thead>
                <tbody>
                    <?php foreach($list as $item): ?>
                    <tr>
                        <td>#<?= $item['id'] ?></td>
                        <td><?= htmlspecialchars($item['name']) ?></td>
                        <td><?= htmlspecialchars($item['phone']) ?></td>
                        <td><?= date('m-d', strtotime($item['book_time'])) ?></td>
                        <td class="msg-cell"><?= htmlspecialchars($item['message']) ?></td>
                        <td><a href="?del=<?= $item['id'] ?>" style="color:red" onclick="return confirm('删?')">删除</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    new Chart(document.getElementById('adminChart'), {
        type: 'bar',
        data: { labels: <?= json_encode($final_labels) ?>, datasets: [{ label: '人数', data: <?= json_encode($final_counts) ?>, backgroundColor: '#4a90e2' }] },
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{x:{grid:{display:false}}} }
    });
</script>
</body>
</html>
