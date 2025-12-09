<?php
// 1. 基础配置与检测
if (!file_exists('config.php') || filesize('config.php') < 10) { header("Location: install.php"); exit; }
require_once 'config.php';
if (!isset($conn)) { echo "Error: Database not connected."; exit; }

// 2. 读取公告配置
$settings = [];
try {
    $stmt = $conn->query("SELECT * FROM settings WHERE name IN ('notice_status', 'notice_content')");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['name']] = $row['value'];
    }
} catch (Exception $e) {}

$msg = ''; 
$msg_type = '';

// 3. 处理表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = strip_tags($_POST['name']);
    $contact = strip_tags($_POST['contact']);
    $date = $_POST['date'];
    $message = strip_tags($_POST['message']);
    
    // 获取当天的限额
    $limit = 20; 
    try {
        $stmt = $conn->prepare("SELECT max_num FROM daily_limits WHERE date = ?");
        $stmt->execute([$date]);
        if ($row = $stmt->fetch()) $limit = $row['max_num'];
        
        // 获取当天已预约数量
        $cnt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE DATE(book_time) = ?");
        $cnt->execute([$date]);
        
        if ($cnt->fetchColumn() >= $limit) {
            $msg = "⚠️ 该日期 ({$date}) 名额已满，请更换其他日期。";
            $msg_type = "error";
        } else {
            $conn->prepare("INSERT INTO appointments (name, phone, book_time, message) VALUES (?, ?, ?, ?)")
                 ->execute([$name, $contact, $date . " 09:00:00", $message]);
            $msg = "✅ 预约提交成功！请等待管理员联系。";
            $msg_type = "success";
        }
    } catch (Exception $e) {
        $msg = "提交失败，请检查输入或稍后再试。";
        $msg_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>在线预约</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --bg: #f3f4f6;
            --card: #ffffff;
            --text-dark: #111827; /* 更黑的文字 */
            --text-gray: #4b5563;
            --border: #d1d5db;
        }
        
        body {
            /* 使用系统字体栈，优先粗体显示清晰 */
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg);
            color: var(--text-dark);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            background: var(--card);
            width: 100%;
            max-width: 440px;
            padding: 35px;
            border-radius: 20px; /* 更圆润 */
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }
        
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 {
            margin: 0 0 10px 0;
            font-size: 26px;
            color: #000;
            font-weight: 800; /* 特粗标题 */
            letter-spacing: -0.5px;
        }
        .header p {
            margin: 0;
            color: var(--text-gray);
            font-size: 15px;
            font-weight: 500; /* 副标题也稍微加粗 */
        }
        
        /* 公告栏 */
        .notice-box {
            background: #fff7ed;
            border: 2px solid #ffedd5; /* 边框加粗 */
            color: #c2410c;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-size: 14px;
            font-weight: 600; /* 公告文字加粗 */
            display: flex;
            gap: 10px;
            line-height: 1.5;
            align-items: start;
        }
        .notice-icon { font-weight: normal; font-size: 20px; margin-top: 1px; flex-shrink: 0; }

        /* 表单元素 */
        label {
            display: block;
            font-size: 14px;
            font-weight: 700; /* 标签加粗 */
            color: #111; /* 纯黑 */
            margin-top: 20px;
            margin-bottom: 8px;
        }
        
        input, textarea, select {
            width: 100%;
            padding: 14px;
            border: 2px solid #e5e7eb; /* 边框稍微加粗 */
            border-radius: 10px;
            background: #f9fafb;
            box-sizing: border-box;
            font-size: 16px;
            font-family: inherit;
            color: #000;
            font-weight: 500; /* 输入的内容文字加粗 (Medium) */
            transition: all 0.2s;
        }
        
        input:focus, textarea:focus {
            border-color: var(--primary);
            background: #fff;
            outline: none;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }
        
        input::placeholder, textarea::placeholder {
            color: #9ca3af;
            font-weight: 400; /* 占位符保持正常粗细 */
        }
        
        button {
            width: 100%;
            padding: 16px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 17px;
            font-weight: 700; /* 按钮文字加粗 */
            cursor: pointer;
            margin-top: 30px;
            transition: background 0.2s, transform 0.1s;
            letter-spacing: 0.5px;
        }
        
        button:hover { background: var(--primary-hover); }
        button:active { transform: scale(0.98); }
        
        /* 提示框 */
        .alert {
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 25px;
            font-size: 15px;
            font-weight: 600; /* 提示文字加粗 */
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .alert.success { background: #dcfce7; color: #166534; } 
        .alert.error { background: #fee2e2; color: #991b1b; }
        
        .word-count { text-align: right; font-size: 13px; font-weight: 500; color: #6b7280; margin-top: 6px; }
        
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 13px;
            font-weight: 500;
            color: #9ca3af;
            border-top: 2px dashed #f3f4f6;
            padding-top: 20px;
        }
        .footer a { color: inherit; text-decoration: none; font-weight: 600; }
        .footer a:hover { color: var(--primary); }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>预约登记服务</h1>
        <p>请填写下方信息，名额有限，先到先得</p>
    </div>

    <?php if (isset($settings['notice_status']) && $settings['notice_status'] == '1'): ?>
    <div class="notice-box">
        <span class="material-symbols-outlined notice-icon">campaign</span>
        <span><?= nl2br(htmlspecialchars($settings['notice_content'])) ?></span>
    </div>
    <?php endif; ?>

    <?php if($msg): ?>
        <div class="alert <?= $msg_type ?>">
            <span class="material-symbols-outlined" style="font-size:20px"><?= $msg_type=='success'?'check_circle':'error' ?></span>
            <?= $msg ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <label>您的微信名 / 电报名</label>
        <input type="text" name="name" required placeholder="请输入您的昵称" autocomplete="off">
        
        <label>微信号 / 电报号</label>
        <input type="text" name="contact" required placeholder="请输入您的账号ID" autocomplete="off">

        <label>预约日期</label>
        <input type="date" name="date" required id="datePicker">
        
        <label>留言备注 (选填)</label>
        <textarea name="message" id="msgInput" rows="3" maxlength="100" placeholder="如有特殊需求请告知..."></textarea>
        <div class="word-count"><span id="charCount">0</span>/100</div>
        
        <button type="submit">立即提交预约</button>
    </form>
    
    <div class="footer">
        &copy; <?= date('Y') ?> 在线预约系统 | <a href="admin/">管理员登录</a>
    </div>
</div>

<script>
    // 1. 设置默认日期为今天
    document.getElementById('datePicker').valueAsDate = new Date();
    
    // 2. 留言字数统计
    const msgInput = document.getElementById('msgInput');
    const charCount = document.getElementById('charCount');
    msgInput.addEventListener('input', function() {
        charCount.textContent = this.value.length;
    });
</script>

</body>
</html>
