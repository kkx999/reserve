<?php
session_start();
if (!isset($_SESSION['admin_logged'])) {
    header("Location: login.php");
    exit;
}
require '../config.php';

// 简单处理：完成/删除
if (isset($_GET['del'])) {
    $conn->prepare("DELETE FROM appointments WHERE id = ?")->execute([$_GET['del']]);
    header("Location: index.php");
}

$stmt = $conn->query("SELECT * FROM appointments ORDER BY book_time DESC");
$list = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>后台管理</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f4f4f4; }
        .btn { color: red; text-decoration: none; }
    </style>
</head>
<body style="padding:20px; font-family:sans-serif;">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h2>预约管理</h2>
        <a href="../index.php">返回首页</a>
    </div>
    <table>
        <tr>
            <th>ID</th>
            <th>姓名</th>
            <th>电话</th>
            <th>预约时间</th>
            <th>操作</th>
        </tr>
        <?php foreach($list as $item): ?>
        <tr>
            <td><?= $item['id'] ?></td>
            <td><?= htmlspecialchars($item['name']) ?></td>
            <td><?= htmlspecialchars($item['phone']) ?></td>
            <td><?= $item['book_time'] ?></td>
            <td><a href="?del=<?= $item['id'] ?>" class="btn" onclick="return confirm('确定删除？')">删除</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
