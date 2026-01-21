<?php
// login.php (autónomo, con CSS incrustado)
session_start();
require __DIR__ . '/config/db.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $clave   = trim($_POST['clave'] ?? '');

    $sql = "SELECT id, nombre, usuario, rol
            FROM usuarios
            WHERE usuario = ? AND clave = ?
            LIMIT 1";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param('ss', $usuario, $clave);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $_SESSION['user_id']   = (int)$row['id'];
        $_SESSION['user_name'] = $row['nombre'];
        $_SESSION['user_user'] = $row['usuario'];
        $_SESSION['user_rol']  = $row['rol'];
        header('Location: admin.php');
        exit;
    } else {
        $error = 'Usuario o contraseña incorrectos.';
    }
}

// Base href dinámico (por si lo necesitas para otros links)
$host    = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = stripos($host, 'localhost') !== false || stripos($host, '127.0.0.1') !== false;
$prodBase  = 'https://agricola.rf.gd/';
$localBase = 'http://localhost/agricola/';
$baseHref  = $isLocal ? $localBase : $prodBase;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<base href="<?= htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8') ?>">
<title>Login - Agrícola</title>

<style>
/* ===== CSS INCRUSTADO ===== */
:root { --bg:#f5f7fb; --card:#fff; --text:#1f2937; --muted:#6b7280; --primary:#2563eb; --danger:#dc2626; --shadow:0 8px 24px rgba(0,0,0,.08) }
*{box-sizing:border-box} html,body{margin:0;padding:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial,sans-serif;color:var(--text);background:var(--bg)}
.login{display:grid;place-items:center;min-height:100vh;padding:20px}
.card{width:100%;max-width:420px;background:var(--card);padding:24px;border-radius:14px;box-shadow:var(--shadow)}
.card h1{margin:0 0 12px}
.card .error{margin:0 0 12px;color:#fff;background:var(--danger);padding:8px 10px;border-radius:8px}
label{display:block;margin:10px 0 6px;font-weight:600}
input{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px}
button{width:100%;margin-top:12px;background:var(--primary);color:#fff;border:none;border-radius:10px;padding:10px 14px;cursor:pointer}
button:hover{opacity:.92}
.small{color:var(--muted);font-size:.9rem;margin-top:10px}
</style>
</head>
<body class="login">
    <div class="card">
        <h1>Iniciar sesión</h1>
        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <form method="post" action="login.php" autocomplete="off">
            <label>Usuario:
                <input type="text" name="usuario" required>
            </label>
            <label>Contraseña:
                <input type="password" name="clave" required>
            </label>
            <button type="submit">Entrar</button>
        </form>
    </div>
</body>
</html>
