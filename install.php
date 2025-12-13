<?php
error_reporting(E_ALL);
// 1. 如果已安装，拦截
if (file_exists('config.php') && filesize('config.php') > 10) {
    die("<!DOCTYPE html><html><body style='background:#f3f4f6;display:flex;justify-content:center;align-items:center;height:100vh;font-family:system-ui'><div style='background:white;padding:40px;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.1);text-align:center'><h2>⚠️ 系统已安装</h2><p style='color:#666'>如需重装，请手动删除根目录下的 <b>config.php</b> 文件。</p><a href='index.php' style='display:inline-block;margin-top:20px;text-decoration:none;color:#4f46e5;font-weight:bold'>返回首页 &rarr;</a></div></body></html>");
}

$msg = '';
$step = 1; // 1: 表单, 2: 成功

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $host = trim($_POST['host']);
    $db_name = trim($_POST['db_name']);
    $db_user = trim($_POST['db_user']);
    $db_pass = trim($_POST['db_pass']);
    $admin_user = trim($_POST['admin_user']);
    $admin_pass = trim($_POST['admin_pass']);

    try {
        // 连接数据库
        $dsn = "mysql:host=$host;dbname=$db_name;charset=utf8mb4";
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        $pdo = new PDO($dsn, $db_user, $db_pass, $options);
        
        // 建表
        $pdo->exec("CREATE TABLE IF NOT EXISTS appointments (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50), phone VARCHAR(50), book_time DATETIME, message VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS admins (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50), password VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS daily_limits (date DATE PRIMARY KEY, max_num INT DEFAULT 20) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(50) PRIMARY KEY, value TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 初始化管理员
        $pdo->exec("TRUNCATE TABLE admins");
        $stmt = $pdo->prepare("INSERT INTO admins (username, password) VALUES (?, ?)");
        $stmt->execute([$admin_user, password_hash($admin_pass, PASSWORD_DEFAULT)]);
        
        // 初始化设置
        $pdo->exec("INSERT IGNORE INTO settings (name, value) VALUES ('notice_status', '0')");
        $pdo->exec("INSERT IGNORE INTO settings (name, value) VALUES ('notice_content', '欢迎预约！')");

        // 生成配置文件
        $config_content = "<?php\n\$host='$host';\n\$db_name='$db_name';\n\$db_user='$db_user';\n\$db_pass='$db_pass';\n\ntry{\n    \$conn=new PDO(\"mysql:host=\$host;dbname=\$db_name;charset=utf8mb4\",\$db_user,\$db_pass);\n    \$conn->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);\n}catch(PDOException \$e){\n    die('数据库连接失败');\n}\n?>";
        
        if (file_put_contents('config.php', $config_content)) {
            $step = 2;
            // 尝试自毁
            @unlink(__FILE__); 
        } else {
            $msg = "<div class='banner error'><span class='material-symbols-outlined'>error</span> 数据库连接成功，但无法写入 config.php，请检查目录权限 (需 777)。</div>";
        }
    } catch (PDOException $e) {
        $err = $e->getMessage();
        $tip = "请检查数据库账号密码是否正确";
        if (strpos($err, 'Unknown database') !== false) $tip = "数据库 '$db_name' 不存在，请先在面板创建";
        $msg = "<div class='banner error'>
                    <span class='material-symbols-outlined'>error</span>
                    <div><strong>安装失败：$tip</strong><br><small style='opacity:0.8'>$err</small></div>
                </div>";
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>系统安装向导</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <style>
        :root { --primary: #4f46e5; --primary-hover: #4338ca; --bg: #f3f4f6; --text: #1f2937; --border: #e5e7eb; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        
        .container { background: white; width: 100%; max-width: 500px; padding: 40px; border-radius: 20px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); animation: fadeUp 0.5s ease-out; }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        .header { text-align: center; margin-bottom: 30px; }
        .logo { width: 56px; height: 56px; background: #e0e7ff; color: var(--primary); border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px; }
        .logo span { font-size: 32px; }
        h1 { margin: 0; font-size: 24px; font-weight: 800; color: #111827; }
        p.subtitle { color: #6b7280; font-size: 14px; margin-top: 8px; }

        .section-title { font-size: 12px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.05em; margin: 24px 0 12px 0; display: flex; align-items: center; gap: 8px; }
        .section-title::after { content: ''; flex: 1; height: 1px; background: #f3f4f6; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { margin-bottom: 0; }
        .input-wrapper { position: relative; }
        .input-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 20px; pointer-events: none; transition: 0.3s; }
        
        input { width: 100%; padding: 12px 12px 12px 42px; border: 2px solid var(--border); border-radius: 10px; font-size: 14px; box-sizing: border-box; transition: 0.2s; background: #f9fafb; color: var(--text); }
        input:focus { border-color: var(--primary); background: white; outline: none; box-shadow: 0 0 0 4px #e0e7ff; }
        input:focus + .input-icon { color: var(--primary); }
        
        /* 通用按钮样式 */
        button { width: 100%; padding: 14px; background: var(--primary); color: white; border: none; border-radius: 10px; font-size: 16px; font-weight: 600; cursor: pointer; margin-top: 30px; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; }
        button:hover { background: var(--primary-hover); transform: translateY(-1px); }
        button:active { transform: translateY(0); }

        .banner { padding: 16px; border-radius: 10px; margin-bottom: 24px; display: flex; gap: 12px; align-items: start; font-size: 14px; line-height: 1.5; }
        .banner.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        
        /* 成功页样式修正 */
        .success-view { text-align: center; }
        .success-icon { width: 80px; height: 80px; background: #dcfce7; color: #166534; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; animation: popIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        @keyframes popIn { from { transform: scale(0); } to { transform: scale(1); } }
        
        /* 修复按钮不对称的核心代码 */
        .success-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 30px; }
        .success-actions a { display: block; text-decoration: none; }
        /* 强制覆盖 margin-top 为 0，确保两个按钮高度一致 */
        .success-actions button { margin-top: 0; height: 100%; } 
        
        .btn-outline { background: white; border: 2px solid var(--border); color: var(--text); }
        .btn-outline:hover { border-color: #d1d5db; background: #f9fafb; }
        
        @media (max-width: 480px) { .form-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<div class="container">
    <?php if ($step == 1): ?>
        <div class="header">
            <div class="logo"><span class="material-symbols-outlined">rocket_launch</span></div>
            <h1>安装向导</h1>
            <p class="subtitle">几步完成系统部署，即刻开启预约服务</p>
        </div>

        <?= $msg ?>

        <form method="post">
            <div class="section-title"><span class="material-symbols-outlined" style="font-size:16px">database</span> 数据库配置</div>
            
            <div class="form-group" style="margin-bottom:16px">
                <div class="input-wrapper">
                    <input type="text" name="host" value="localhost" placeholder="数据库地址 (通常为 localhost)" required>
                    <span class="material-symbols-outlined input-icon">dns</span>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:16px">
                <div class="input-wrapper">
                    <input type="text" name="db_name" placeholder="数据库名 (请先在面板创建空库)" required>
                    <span class="material-symbols-outlined input-icon">folder_open</span>
                </div>
            </div>

            <div class="form-grid">
                <div class="input-wrapper">
                    <input type="text" name="db_user" placeholder="数据库用户" required>
                    <span class="material-symbols-outlined input-icon">person</span>
                </div>
                <div class="input-wrapper">
                    <input type="text" name="db_pass" placeholder="数据库密码" required>
                    <span class="material-symbols-outlined input-icon">key</span>
                </div>
            </div>

            <div class="section-title"><span class="material-symbols-outlined" style="font-size:16px">admin_panel_settings</span> 后台管理员设置</div>

            <div class="form-grid">
                <div class="input-wrapper">
                    <input type="text" name="admin_user" value="admin" placeholder="用户名" required>
                    <span class="material-symbols-outlined input-icon">account_circle</span>
                </div>
                <div class="input-wrapper">
                    <input type="text" name="admin_pass" value="123456" placeholder="登录密码" required>
                    <span class="material-symbols-outlined input-icon">lock</span>
                </div>
            </div>

            <button type="submit">立即安装系统 <span class="material-symbols-outlined">arrow_forward</span></button>
        </form>

    <?php else: ?>
        <div class="success-view">
            <div class="success-icon"><span class="material-symbols-outlined" style="font-size:40px">check</span></div>
            <h1>安装成功！</h1>
            <p class="subtitle">系统已准备就绪，配置文件已生成。</p>
            
            <div style="background:#fff7ed; border:1px solid #ffedd5; color:#c2410c; padding:12px; border-radius:8px; font-size:13px; margin-top:20px; text-align:left; display:flex; gap:10px;">
                <span class="material-symbols-outlined" style="font-size:20px">shield</span>
                <span>安全提示：本安装程序文件 (install.php) 已尝试自动删除。如果删除失败，请您务必手动删除。</span>
            </div>

            <div class="success-actions">
                <a href="index.php"><button type="button" class="btn-outline">进入前台</button></a>
                <a href="admin/"><button type="button">登录后台</button></a>
            </div>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
