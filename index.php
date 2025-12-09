<?php
// 检测是否安装
if (!file_exists('config.php') || filesize('config.php') < 100) {
    header("Location: install.php");
    exit;
}
require 'config.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'];
    $phone = $_POST['phone'];
    $date = $_POST['date'];
    $time = $_POST['time'];
    $full_time = "$date $time";

    $stmt = $conn->prepare("INSERT INTO appointments (name, phone, book_time) VALUES (?, ?, ?)");
    if ($stmt->execute([$name, $phone, $full_time])) {
        $msg = "<div style='color:green; padding:10px; border:1px solid green;'>预约提交成功！</div>";
    } else {
        $msg = "<div style='color:red;'>预约失败，请重试。</div>";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>在线预约</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: sans-serif; padding: 20px; max-width: 500px; margin: 0 auto; }
        input, button { width: 100%; padding: 10px; margin: 5px 0; box-sizing: border-box; }
        button { background: #28a745; color: white; border: none; cursor: pointer; }
    </style>
</head>
<body>
    <h1>预约服务</h1>
    <?= $msg ?>
    <form method="post">
        <label>姓名</label>
        <input type="text" name="name" required>
        
        <label>电话</label>
        <input type="text" name="phone" required>
        
        <label>日期</label>
        <input type="date" name="date" required>
        
        <label>时间</label>
        <input type="time" name="time" required>
        
        <button type="submit">立即提交</button>
    </form>
    <p style="text-align:center; margin-top:20px;"><a href="admin/" style="color:#999; text-decoration:none;">管理员入口</a></p>
</body>
</html>
