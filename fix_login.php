<?php
// fix_login.php - 强制重置管理员工具
header("Content-type: text/html; charset=utf-8");

// 1. 检查配置文件是否存在
if (!file_exists('config.php')) {
    die("❌ config.php 不存在！请先访问首页 index.php 完成安装。");
}

// 2. 引入数据库连接
require 'config.php';

if (!isset($conn)) {
    die("❌ 数据库连接失败，请检查 config.php 里的密码是否正确。");
}

try {
    // 3. 暴力清空管理员表 (清除所有旧账号)
    $conn->exec("TRUNCATE TABLE admins");

    // 4. 生成新账号信息
    $username = 'admin';
    $password = '123456'; 
    $hash = password_hash($password, PASSWORD_DEFAULT);

    // 5. 写入数据库
    $stmt = $conn->prepare("INSERT INTO admins (username, password) VALUES (?, ?)");
    $result = $stmt->execute([$username, $hash]);

    if ($result) {
        echo "<div style='text-align:center; padding:50px;'>";
        echo "<h1 style='color:green'>✅ 管理员重置成功！</h1>";
        echo "<p>当前账号：<strong style='font-size:20px'>admin</strong></p>";
        echo "<p>当前密码：<strong style='font-size:20px'>123456</strong></p>";
        echo "<br>";
        echo "<a href='admin/login.php' style='background:#4f46e5; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>立即登录后台</a>";
        echo "<p style='color:red; margin-top:20px;'>⚠️ 登录成功后，请务必在文件管理器中删除 fix_login.php 文件！</p>";
        echo "</div>";
    } else {
        echo "写入失败，请检查数据库权限。";
    }

} catch (PDOException $e) {
    echo "<h1>❌ 发生错误</h1>";
    echo "错误信息：" . $e->getMessage();
    echo "<br>请确认你的数据库里是否有 admins 表。如果没有，请重新运行安装程序。";
}
?>
