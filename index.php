<?php
// 1. 核心配置与连接检测
if (!file_exists('config.php') || filesize('config.php') < 10) {
    header("Location: install.php");
    exit;
}
require_once 'config.php';
if (!isset($conn)) { echo "数据库连接失败"; exit; }

// ==========================================
// 2.【新增】内部API：获取当月图表数据
// ==========================================
if (isset($_GET['get_chart_data'])) {
    header('Content-Type: application/json');
    try {
        // SQL逻辑：查询当月每一天的预约数
        // DATE(book_time) 提取日期，COUNT(*) 统计数量
        $sql = "SELECT DATE_FORMAT(book_time, '%d') as day, COUNT(*) as count 
                FROM appointments 
                WHERE DATE_FORMAT(book_time, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') 
                GROUP BY DATE(book_time) 
                ORDER BY day ASC";
        $stmt = $conn->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $data]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error']);
    }
    exit; // API请求处理完直接结束，不渲染HTML
}

// ==========================================
// 3. 处理表单提交
// ==========================================
$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = strip_tags($_POST['name']);
    $contact = strip_tags($_POST['contact']); 
    $date = $_POST['date']; 
    // 因为去掉了具体时间点，我们默认存为当天的 09:00:00，或者直接存日期
    $book_time = $date . " 09:00:00"; 

    try {
        $stmt = $conn->prepare("INSERT INTO appointments (name, phone, book_time) VALUES (?, ?, ?)");
        $stmt->execute([$name, $contact, $book_time]);
        $msg = "✅ 提交成功！已记录您的预约。";
        $msg_type = "success";
    } catch (Exception $e) {
        $msg = "❌ 提交失败，请重试。";
        $msg_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>在线预约服务</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #4a90e2; 
            --primary-hover: #357abd;
            --bg-color: #f0f2f5;
            --card-bg: #ffffff;
            --text-main: #333333;
            --text-sub: #666666;
            --border-color: #e1e4e8;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; outline: none; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg-color);
            color: var(--text-main);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            background: var(--card-bg);
            width: 100%;
            max-width: 450px; /* 稍微加宽一点给图表 */
            padding: 40px 30px;
            border-radius: 16px;
            box-shadow: var(--shadow);
        }

        .header { text-align: center; margin-bottom: 25px; }
        .header h1 { font-size: 24px; font-weight: 700; color: #1a1a1a; margin-bottom: 8px; }
        .header p { color: var(--text-sub); font-size: 14px; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: var(--text-sub); margin-bottom: 8px; }
        
        input {
            width: 100%;
            padding: 12px 15px;
            font-size: 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: #f9f9f9;
            transition: all 0.3s;
            color: #333;
        }
        input:focus {
            border-color: var(--primary-color);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
        }

        button.submit-btn {
            width: 100%;
            padding: 14px;
            font-size: 16px;
            font-weight: 600;
            color: white;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            margin-top: 10px;
        }
        button.submit-btn:active { transform: scale(0.98); }
        button.submit-btn:hover { box-shadow: 0 5px 15px rgba(74, 144, 226, 0.3); }

        .alert {
            padding: 12px;
            border-radius: 8px;
            font-size: 14px;
            text-align: center;
            margin-bottom: 20px;
        }
        .alert.success { background: #e6fffa; color: #2c7a7b; border: 1px solid #b2f5ea; }
        .alert.error { background: #fff5f5; color: #c53030; border: 1px solid #fed7d7; }

        .footer { text-align: center; margin-top: 25px; font-size: 12px; color: #aaa; }
        .footer a { color: #aaa; text-decoration: none; }

        /* 图表区域样式 */
        .chart-container {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px dashed #eee;
        }
        .chart-title {
            text-align: center;
            font-size: 12px;
            font-weight: 600;
            color: #888;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="header">
            <h1>预约登记</h1>
            <p>填写信息以完成预约</p>
        </div>

        <?php if($msg): ?>
            <div class="alert <?= $msg_type ?>"><?= $msg ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label>您的微信名或电报名</label>
                <input type="text" name="name" required placeholder="请输入昵称" autocomplete="off">
            </div>
            
            <div class="form-group">
                <label>微信号或电报号</label>
                <input type="text" name="contact" required placeholder="请输入ID" autocomplete="off">
            </div>

            <div class="form-group">
                <label>预约日期</label>
                <input type="date" name="date" required id="datePicker">
            </div>
            
            <button type="submit" class="submit-btn">立即提交</button>
        </form>

        <div class="chart-container">
            <div class="chart-title">📅 本月每日预约热度</div>
            <canvas id="bookingChart"></canvas>
        </div>

        <div class="footer">
            <p>© 2024 系统 | <a href="admin/">管理后台</a></p>
        </div>
    </div>

    <script>
        // 1. 设置日期选择器默认为今天
        document.getElementById('datePicker').valueAsDate = new Date();

        // 2. 加载图表数据
        fetch('?get_chart_data=1')
            .then(response => response.json())
            .then(res => {
                if(res.status === 'success') {
                    renderChart(res.data);
                }
            });

        function renderChart(data) {
            // 准备数据：生成当月所有天数（这里简化处理，直接用有数据的天数）
            // 如果想更严谨，可以生成1-31号填补0，但为了MVP，我们只显示有预约的日期
            
            const labels = data.map(item => item.day + '日');
            const counts = data.map(item => item.count);

            const ctx = document.getElementById('bookingChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar', // 柱状图
                data: {
                    labels: labels,
                    datasets: [{
                        label: '预约人数',
                        data: counts,
                        backgroundColor: 'rgba(74, 144, 226, 0.6)', // 蓝色半透明
                        borderColor: 'rgba(74, 144, 226, 1)',
                        borderWidth: 1,
                        borderRadius: 4,
                        barThickness: 'flex',
                        maxBarThickness: 20
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false } // 隐藏图例
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1 } // Y轴只显示整数
                        },
                        x: {
                            grid: { display: false } // 隐藏X轴网格线，更清爽
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>
