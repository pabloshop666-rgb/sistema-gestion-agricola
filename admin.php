<?php
// admin.php – Panel con acceso a reportes y gestión de proveedores
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Verificar si es admin (según tu estructura)
$es_admin = strtolower($_SESSION['user_rol'] ?? '') === 'admin';

// Base href por si más adelante enlazas otros recursos
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
<title>Panel - Agrícola</title>

<style>
/* ====== CSS EMBEBIDO ====== */
:root{
  --bg:#f5f7fb; --card:#ffffff; --text:#1f2937; --muted:#6b7280;
  --primary:#2563eb; --primary-2:#1d4ed8; --shadow:0 10px 30px rgba(0,0,0,.08);
  --ring:#dbeafe; --surface:#f8fafc; --orange:#f97316;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);
  font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial,sans-serif}
.wrap{max-width:1100px;margin:32px auto;padding:0 16px}
.header{
  background:var(--card); border-radius:14px; box-shadow:var(--shadow);
  padding:18px 20px; display:flex; align-items:center; gap:12px
}
.header .avatar{
  width:44px;height:44px;border-radius:50%;display:grid;place-items:center;
  background:var(--ring); color:var(--primary); font-weight:700
}
.header .who{line-height:1.25}
.header .who b{font-weight:700}
.header small{color:var(--muted)}

/* Admin badge */
.admin-badge{
  background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color:#fff; padding:6px 12px; border-radius:20px; font-size:12px;
  font-weight:600; margin-left:auto;
}

.panel{
  margin-top:22px; background:var(--card); border-radius:14px; box-shadow:var(--shadow);
  padding:24px;
}
.panel h2{margin:0 0 12px}
.grid{
  margin-top:6px; display:grid; gap:18px;
  grid-template-columns: repeat(auto-fit,minmax(210px,1fr));
}
.card{
  background:var(--surface); border:1px solid #e5e7eb; border-radius:12px;
  padding:18px; display:flex; flex-direction:column; gap:10px;
  transition:.18s transform,.18s box-shadow;
}
.card:hover{transform:translateY(-2px); box-shadow:0 14px 36px rgba(0,0,0,.08)}
.card h3{margin:0 0 4px}
.card p{margin:0; color:var(--muted); font-size:.95rem}
.btn{
  display:inline-block; margin-top:8px; text-align:center;
  background:var(--primary); color:#fff; padding:10px 12px; border-radius:10px;
  text-decoration:none; font-weight:600;
}
.btn:hover{background:var(--primary-2)}
.btn.orange{background:var(--orange)}
.btn.orange:hover{background:#ea580c}

/* Admin-only section */
.admin-section{
  background:linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
  border:1px solid #e0e7ff; border-radius:12px; padding:16px; margin-top:16px;
}
.admin-section h3{margin:0 0 8px; color:#4338ca; font-size:14px; text-transform:uppercase; letter-spacing:0.05em;}

.footer-actions{
  margin-top:22px; display:flex; justify-content:center;
}
.btn-out{
  background:#ef4444; color:#fff; padding:12px 16px; border-radius:12px;
  text-decoration:none; font-weight:700; min-width:200px; text-align:center;
}
.btn-out:hover{background:#dc2626}
</style>
</head>
<body>
  <div class="wrap">

    <section class="header">
      <div class="avatar">AG</div>
      <div class="who">
        <div>Hola, <b><?= htmlspecialchars($_SESSION['user_name'], ENT_QUOTES, 'UTF-8') ?></b>
          <small>(<?= htmlspecialchars($_SESSION['user_rol'], ENT_QUOTES, 'UTF-8') ?>)</small>
        </div>
        <small>Selecciona una opción para continuar</small>
      </div>
      <?php if ($es_admin): ?>
      <div class="admin-badge">ADMIN</div>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Panel</h2>
      <div class="grid">
        <article class="card">
          <h3>Gestionar productos</h3>
          <p>Agrega, edita o elimina productos y categorías.</p>
          <a class="btn" href="productos.php">Ir a productos</a>
        </article>

        <article class="card">
          <h3>Ventas</h3>
          <p>Registra ventas nuevas y consulta el historial.</p>
          <a class="btn" href="ventas.php">Ir a ventas</a>
        </article>

        <article class="card">
          <h3>Reporte</h3>
          <p>Descarga o consulta reportes de ventas.</p>
          <a class="btn" href="reporte.php">Ver reporte</a>
        </article>

        <?php if ($es_admin): ?>
        
        <article class="card">
          <h3>Costos y Proveedores</h3>
          <p>Ajusta costos, simula precios y márgenes.</p>
          <a class="btn orange" href="proveedores.php">Gestionar Costos</a>
        </article>

        <article class="card">
          <h3>Registro de Ediciones</h3>
          <p>Ve quién ha editado productos y cuándo.</p>
          <a class="btn orange" href="reportes_ediciones.php">Ver registro</a>
        </article>
        
        <?php endif; ?>
      </div>

      <div class="footer-actions">
        <a class="btn-out" href="logout.php">Cerrar sesión</a>
      </div>
    </section>

  </div>
</body>
</html>