<?php
session_start();
// 1. 登录检查
if (!isset($_SESSION['is_admin'])) {
    header("Location: login.php");
    exit;
}
require '../config.php';

// 2. 处理删除请求
if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    $conn->prepare("DELETE FROM appointments WHERE id = ?")->execute([$id]);
    header("Location: index.php");
    exit;
}

// 3. 获取列表数据 (只显示最近50条，避免太长)
$stmt = $conn->query("SELECT * FROM appointments ORDER BY created_at DESC LIMIT 50");
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. 获取图表数据 (统计本月每日预约量)
$sql_chart = "SELECT DATE_FORMAT(book_time, '%d') as day, COUNT(*) as count 
              FROM appointments 
              WHERE DATE_FORMAT(book_time, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') 
              GROUP BY DATE(book_time) 
              ORDER BY day ASC";
$chart_data = $conn->query($sql_chart)->fetchAll(PDO::FETCH_ASSOC);
$json_chart_data = json_encode($chart_data); // 转给JS用

// 5. 简单统计本月总数
$total_month = 0;
foreach($chart_data as $d) $total_month += $d['count'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>后台管理中心</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #4a90e2;
            --bg: #f0f2f5;
            --white: #ffffff;
            --text: #333;
            --danger: #ff4d4f;
        }
        body { margin: 0; padding: 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); }
        
        /* 布局容器 */
        .dashboard { max-width: 1000px; margin: 0 auto; }
        
        /* 顶部导航 */
        .nav-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .nav-bar h2 { margin: 0; font-size: 20px; }
        .nav-actions a { text-decoration: none; font-size: 14px; margin-left: 15px; color: #666; transition: 0.3s; }
        .nav-actions a:hover { color: var(--primary); }
        .nav-actions .logout { color: var(--danger); }

        /* 卡片通用样式 */
        .card { background: var(--white); border-radius: 12px; padding: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 20px; }
        
        /* 图表区 */
        .chart-header { display: flex; justify-content: space-between; margin-bottom: 15px; }
        .stat-num { font-size: 24px; font-weight: bold; color: var(--primary); }
        .stat-label { font-size: 12px; color: #888; text-transform: uppercase; }

        /* 表格区 */
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { text-align: left; padding: 12px 10px; color: #888; font-weight: 600; border-bottom: 1px solid #eee; }
        td { padding: 12px 10px; border-bottom: 1px solid #f9f9f9; color: #444; }
        tr:last-child td { border-bottom: none; }
        
        .btn-del { 
            padding: 6px 12px; background: #fff1f0; color: var(--danger); 
            border: 1px solid #ffa39e; border-radius: 4px; 
            text-decoration: none; font-size: 12px; 
        }
        .btn-del:hover { background: var(--danger); color: white; border-color: var(--danger); }
        
        .tag-date { background: #e6f7ff; color: #1890ff; padding: 2px 6px; border-radius: 4px; font-size: 12px; }

        @media (max-width: 600px) {
            body { padding: 10px; }
            .card { padding: 15px; }
            td, th { min-width: 60px; } /* 防止手机上表格太挤 */
        }
    </style>
</head>
<body>

<div class="dashboard">
    <div class="nav-bar">
        <h2>📅 预约管理后台</h2>
        <div class="nav-actions">
            <a href="../index.php" target="_blank">预览前台</a>
            <a href="login.php" class="logout">退出登录</a>
        </div>
    </div>

    <div class="card">
        <div class="chart-header">
            <div>
                <div class="stat-label">本月预约总数</div>
                <div class="stat-num"><?= $total_month ?> <span style="font-size:14px; color:#999; font-weight:normal;">单</span></div>
            </div>
            <div class="stat-label">数据趋势图</div>
        </div>
        <div style="height: 250px;">
            <canvas id="adminChart"></canvas>
        </div>
    </div>

    <div class="card">
        <h3 style="margin-top:0; font-size:16px; margin-bottom:20px;">最新预约列表</h3>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>昵称</th>
                        <th>联系方式</th>
                        <th>预约日期</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($list) > 0): ?>
                        <?php foreach($list as $item): ?>
                        <tr>
                            <td>#<?= $item['id'] ?></td>
                            <td style="font-weight:500;"><?= htmlspecialchars($item['name']) ?></td>
                            <td><?= htmlspecialchars($item['phone']) ?></td>
                            <td>
                                <span class="tag-date"><?= date('m-d', strtotime($item['book_time'])) ?></span>
                            </td>
                            <td>
                                <a href="?del=<?= $item['id'] ?>" class="btn-del" onclick="return confirm('确定要删除这条记录吗？');">删除</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align:center; padding:30px; color:#999;">暂无数据</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    // 1. 获取 PHP 传过来的数据
    const rawData = <?= $json_chart_data ?>;
    
    // 2. 准备图表数据
    const labels = rawData.map(item => item.day + '日');
    const counts = rawData.map(item => item.count);

    // 3. 渲染
    const ctx = document.getElementById('adminChart').getContext('2d');
    new Chart(ctx, {
        type: 'line', // 后台用折线图通常看起来更专业，如果不喜欢改成 'bar' 即可
        data: {
            labels: labels,
            datasets: [{
                label: '每日预约',
                data: counts,
                borderColor: '#4a90e2',
                backgroundColor: 'rgba(74, 144, 226, 0.1)',
                borderWidth: 2,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: '#4a90e2',
                pointRadius: 4,
                tension: 0.3, // 让线条平滑一点
                fill: true    // 填充线条下方区域
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false, // 自适应高度
            plugins: {
                legend: { display: false } // 隐藏图例，更简洁
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 },
                    grid: { borderDash: [5, 5] } // 虚线网格
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
</script>

</body>
</html>
