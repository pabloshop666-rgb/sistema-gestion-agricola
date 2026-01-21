<?php
// reporte.php - VERSIÓN "ACORDEÓN MÓVIL" CON TOP 20 📱
// Todo se puede minimizar para que no ocupe espacio
ini_set('display_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// Ajusta la ruta si es necesario
if (file_exists(__DIR__ . '/config/db.php')) require __DIR__ . '/config/db.php';
else $conexion = new mysqli('localhost', 'root', '', 'agricola'); 

date_default_timezone_set('America/Bogota');

/* ------------ Helpers ------------ */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function cop($n){ $n = is_numeric($n)?(float)$n:0; return 'COP ' . number_format($n, 0, ',', '.'); }
function catNombre($id){ return [1=>'Kilos',2=>'Bulto',3=>'Unidad'][(int)$id] ?? 'Kilos'; }

// NOTA: Esta función es la antigua (por categoría). 
// Si quieres usar el stock mínimo individual que hicimos en productos, avísame para cambiar esto.
function umbralPorCat($c){ return [1=>7,2=>5,3=>4][(int)$c] ?? 7; } 

/* ------------ LISTA DE FESTIVOS (Manual como pediste) ------------ */
$festivos = [
    '2025-01-01', '2025-01-06', '2025-03-24', '2025-04-17', '2025-04-18', 
    '2025-05-01', '2025-06-02', '2025-06-23', '2025-06-30', '2025-07-20', 
    '2025-08-07', '2025-08-18', '2025-10-13', '2025-11-03', '2025-11-17', 
    '2025-12-08', '2025-12-25',
    '2026-01-01', '2026-01-12', '2026-03-23', '2026-03-29', '2026-03-30',
    '2026-05-01', '2026-05-18', '2026-06-08', '2026-06-15', '2026-06-29',
    '2026-07-20', '2026-08-07', '2026-08-17', '2026-10-12', '2026-11-02',
    '2026-11-16', '2026-12-08', '2026-12-25'
];

function esDiaHabil($fechaStr, $listaFestivos) {
    $ts = strtotime($fechaStr);
    $diaSemana = date('w', $ts);
    if ($diaSemana == 0) return false; // Domingo
    if (in_array($fechaStr, $listaFestivos)) return false; // Festivo
    return true;
}

/* ------------ Configuración Inicial ------------ */
$conexion->query("SET time_zone='-05:00'"); 
$desde = $_GET['desde'] ?? date('Y-m-d');
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$rIni  = $conexion->real_escape_string($desde . ' 00:00:00');
$rFin  = $conexion->real_escape_string($hasta . ' 23:59:59');

/* ------------ 1. META INTELIGENTE ------------ */
$metaDiariaBase = 205000;
$diasHabiles = 0;
$periodo = new DatePeriod(new DateTime($desde), new DateInterval('P1D'), (new DateTime($hasta))->modify('+1 day'));
foreach ($periodo as $dt) {
    if (esDiaHabil($dt->format('Y-m-d'), $festivos)) $diasHabiles++;
}
$metaDelPeriodo = ($diasHabiles > 0) ? ($metaDiariaBase * $diasHabiles) : 0;

/* ------------ 2. KPIs ------------ */
$ventasRango  = (float)($conexion->query("SELECT COALESCE(SUM(total),0) t FROM ventas WHERE fecha >= '$rIni' AND fecha <= '$rFin'")->fetch_assoc()['t'] ?? 0);
$egresosRango = (float)($conexion->query("SELECT COALESCE(SUM(monto),0) t FROM egresos WHERE fecha >= '$rIni' AND fecha <= '$rFin'")->fetch_assoc()['t'] ?? 0);
$pagosRango   = (float)($conexion->query("SELECT COALESCE(SUM(monto),0) t FROM pagos_facturas WHERE fecha >= '$rIni' AND fecha <= '$rFin'")->fetch_assoc()['t'] ?? 0);
$netoRango    = $ventasRango - $egresosRango - $pagosRango;

// Semáforo
$porcentajeMeta = 0;
$mensajeMeta = "Día de descanso 🏖️";
$colorMeta = '#9ca3af';

if ($metaDelPeriodo > 0) {
    $porcentajeMeta = ($netoRango > 0) ? ($netoRango / $metaDelPeriodo) * 100 : 0;
    if($porcentajeMeta > 100) $porcentajeMeta = 100;
    
    $mitadMeta = $metaDelPeriodo / 2;
    if($netoRango < $mitadMeta){
        $colorMeta = '#dc2626'; $mensajeMeta = "Falta empujar... (Meta: ".cop($metaDelPeriodo).")";
    } elseif($netoRango >= $mitadMeta && $netoRango < $metaDelPeriodo){
        $colorMeta = '#f59e0b'; $mensajeMeta = "¡Ya casi! Faltan ".cop($metaDelPeriodo - $netoRango);
    } else {
        $colorMeta = '#16a34a'; $mensajeMeta = "¡Meta cumplida! Extra: ".cop($netoRango - $metaDelPeriodo);
    }
}

/* ------------ 3. CONSULTAS (AQUÍ ESTÁ EL TOP 20) ------------ */
$topProds=[]; 
try{
    // CAMBIO: LIMIT 20 en lugar de LIMIT 5
    $q=$conexion->query("SELECT p.nombre,SUM(d.cantidad)c,SUM(d.precio*d.cantidad)t FROM venta_detalles d JOIN ventas v ON v.id=d.venta_id JOIN productos p ON p.id=d.producto_id WHERE v.fecha>='$rIni' AND v.fecha<='$rFin' GROUP BY p.id ORDER BY t DESC LIMIT 20");
    while($r=$q->fetch_assoc())$topProds[]=$r;
}catch(Exception $e){}

$listaVentas=[]; $q=$conexion->query("SELECT v.fecha,p.nombre,d.precio,d.cantidad,(d.precio*d.cantidad)s FROM venta_detalles d JOIN ventas v ON v.id=d.venta_id JOIN productos p ON p.id=d.producto_id WHERE v.fecha>='$rIni' AND v.fecha<='$rFin' ORDER BY v.fecha DESC");while($r=$q->fetch_assoc())$listaVentas[]=$r;
$listaEgresos=[]; $q=$conexion->query("SELECT * FROM egresos WHERE fecha>='$rIni' AND fecha<='$rFin' ORDER BY fecha DESC");while($r=$q->fetch_assoc())$listaEgresos[]=$r;
$listaPagos=[]; $q=$conexion->query("SELECT * FROM pagos_facturas WHERE fecha>='$rIni' AND fecha<='$rFin' ORDER BY fecha DESC");while($r=$q->fetch_assoc())$listaPagos[]=$r;

// Alerta Stock (Nota: Usa lógica vieja por categoría)
$bajoStock=[]; $q=$conexion->query("SELECT * FROM productos ORDER BY nombre ASC");while($r=$q->fetch_assoc()){$r['umbral']=umbralPorCat($r['categoria_id']??1);if($r['stock']<=$r['umbral'])$bajoStock[]=$r;}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Reporte Pro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    :root{ --bg:#f6f7fb; --card:#fff; --line:#e6e7ee; --muted:#6b7280; --primary:#2563eb; --danger:#dc2626; --success:#16a34a; --warn:#f59e0b; }
    *{ box-sizing:border-box }
    body{ margin:0; background:var(--bg); color:#111; font-family:system-ui,-apple-system,sans-serif }
    .wrap{max-width:1100px;margin:28px auto;padding:0 12px}
    header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
    h1{margin:0;font-size:24px}
    .btn{background:var(--primary);color:#fff;border:none;border-radius:12px;padding:10px 16px;cursor:pointer;min-height:44px}
    
    /* Grid de KPIs */
    .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}
    .kpi{background:#fff;border:1px solid var(--line);padding:16px;border-radius:12px}
    .kpi h3{margin:0 0 6px;font-size:13px;color:var(--muted);text-transform:uppercase}
    .kpi .v{font-size:20px;font-weight:700}
    
    /* TARJETAS COLAPSABLES */
    .card{background:#fff;border:1px solid var(--line);border-radius:12px;margin-bottom:16px; overflow:hidden;}
    
    /* Cabecera Clickable */
    .card-header{
        padding:16px; 
        display:flex; 
        justify-content:space-between; 
        align-items:center; 
        cursor:pointer;
        background: #fff;
        transition: background 0.2s;
    }
    .card-header:hover{ background: #f9fafb; }
    .card-header h3{ margin:0; font-size:18px; color:#374151; }
    
    /* Flecha Giratoria */
    .toggle-icon{ 
        font-size: 14px; 
        color: var(--muted); 
        transition: transform 0.3s ease; 
    }
    /* Estado Cerrado (Collapsed) */
    .card.collapsed .toggle-icon{ transform: rotate(-90deg); }
    .card.collapsed .card-body{ display:none; }
    
    .card-body{ padding: 0 16px 16px 16px; border-top:1px solid transparent; }
    .card:not(.collapsed) .card-body{ border-top-color: var(--line); padding-top:16px; }

    /* Inputs y Tablas */
    label{display:block;margin:6px 0 4px;font-weight:600}
    input{width:100%;padding:12px 10px;border:1px solid var(--line);border-radius:12px;font-size:16px}
    .row{display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end}
    .table-wrap{border-radius:10px;overflow:hidden;background:#fff;border:1px solid var(--line); overflow-x: auto;}
    
    /* CLASE NUEVA: Scroll para el Top 20 */
    .table-scroll { max-height: 400px; overflow-y: auto; }
    
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px 12px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}
    th{background:#f5f7fb;font-weight:600;font-size:14px;color:#4b5563;}
    .right{text-align:right}

    /* Barra Progreso */
    .progress-track{background:#e5e7eb; border-radius:999px; height:16px; width:100%; overflow:hidden; margin-top:8px;}
    .progress-fill{height:100%; transition:width 0.6s ease;}
    .meta-info{display:flex; justify-content:space-between; font-size:13px; margin-top:6px; color:var(--muted);}

    @media(max-width:900px){ .grid{grid-template-columns:repeat(2,1fr)} .row{grid-template-columns:1fr 1fr} }
    @media(max-width:600px){ .grid{grid-template-columns:1fr} .row{grid-template-columns:1fr} }
  </style>
</head>
<body>
<div class="wrap">

  <header>
    <h1>Reporte Inteligente</h1>
    <a href="admin.php"><button class="btn">Volver</button></a>
  </header>

  <div class="card">
    <div class="card-body" style="padding-top:16px"> <form class="row" method="get">
          <div><label>Desde</label><input type="date" name="desde" value="<?= h($desde) ?>"></div>
          <div><label>Hasta</label><input type="date" name="hasta" value="<?= h($hasta) ?>"></div>
          <div><button class="btn" type="submit" style="width:100%">Analizar</button></div>
        </form>
    </div>
  </div>

  <div class="card" style="border-left:5px solid <?= $colorMeta ?>">
    <div class="card-body" style="padding-top:16px">
        <h3 style="margin:0 0 8px 0; font-size:18px">🎯 Meta (<?= $diasHabiles ?> días hábiles)</h3>
        <?php if($metaDelPeriodo > 0): ?>
            <p style="margin:0 0 8px; color:<?= $colorMeta ?>"><b><?= $mensajeMeta ?></b></p>
            <div class="progress-track"><div class="progress-fill" style="width:<?= $porcentajeMeta ?>%; background:<?= $colorMeta ?>"></div></div>
            <div class="meta-info"><span>0</span><span><?= cop($metaDelPeriodo) ?></span></div>
        <?php else: ?>
            <p style="margin:0">🏖️ Sin meta financiera (Descanso).</p>
        <?php endif; ?>
    </div>
  </div>

  <div class="grid">
    <div class="kpi"><h3>VENTAS</h3><div class="v"><?= h(cop($ventasRango)) ?></div></div>
    <div class="kpi"><h3>EGRESOS</h3><div class="v" style="color:var(--danger)"><?= h(cop($egresosRango)) ?></div></div>
    <div class="kpi"><h3>PAGOS</h3><div class="v"><?= h(cop($pagosRango)) ?></div></div>
    <div class="kpi" style="background:<?= $metaDelPeriodo>0 && $netoRango>=$metaDelPeriodo?'#dcfce7':'#fff' ?>">
        <h3 style="color:<?= $colorMeta ?>">NETO REAL</h3>
        <div class="v" style="color:<?= $colorMeta ?>"><?= h(cop($netoRango)) ?></div>
    </div>
  </div>

  <div class="card collapsed"> <div class="card-header" onclick="toggle(this)">
        <h3>🏆 Top 20 Productos</h3>
        <span class="toggle-icon">▼</span>
    </div>
    <div class="card-body">
      <div class="table-wrap table-scroll">
        <table>
          <thead><tr><th>#</th><th>Producto</th><th class="right">Total $</th><th class="right">Cant.</th></tr></thead>
          <tbody>
            <?php if(!$topProds): ?><tr><td colspan="4" class="right">Sin datos.</td></tr>
            <?php else: $i=1; foreach($topProds as $p): ?>
              <tr>
                  <td style="color:var(--muted); font-size:12px"><?= $i++ ?></td>
                  <td><?= h($p['nombre']) ?></td>
                  <td class="right"><b><?= h(cop($p['t'])) ?></b></td>
                  <td class="right"><?= $p['c'] ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card collapsed">
    <div class="card-header" onclick="toggle(this)">
        <h3 style="color:#166534">📦 Detalle de Ventas</h3>
        <span class="toggle-icon">▼</span>
    </div>
    <div class="card-body">
      <div class="table-wrap table-scroll" style="max-height:500px"> <table>
          <thead><tr><th>Fecha</th><th>Producto</th><th class="right">Cant.</th><th class="right">Total</th></tr></thead>
          <tbody>
          <?php if(!$listaVentas): ?><tr><td colspan="4" class="right">Sin ventas.</td></tr>
          <?php else: foreach($listaVentas as $r): ?>
            <tr>
              <td><?= h($r['fecha']) ?></td>
              <td><?= h($r['nombre']) ?></td>
              <td class="right"><?= (int)$r['cantidad'] ?></td>
              <td class="right"><b><?= h(cop($r['s'])) ?></b></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card collapsed">
    <div class="card-header" onclick="toggle(this)">
        <h3 style="color:#991b1b">💸 Gastos Diarios</h3>
        <span class="toggle-icon">▼</span>
    </div>
    <div class="card-body">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Fecha</th><th>Concepto</th><th class="right">Monto</th></tr></thead>
          <tbody>
          <?php if(!$listaEgresos): ?><tr><td colspan="3" class="right">Sin gastos.</td></tr>
          <?php else: foreach($listaEgresos as $r): ?>
            <tr>
              <td><?= h($r['fecha']) ?></td>
              <td><?= h($r['concepto']) ?></td>
              <td class="right" style="color:var(--danger)"><b>- <?= h(cop($r['monto'])) ?></b></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card collapsed">
    <div class="card-header" onclick="toggle(this)">
        <h3 style="color:#9a3412">🧾 Pagos Proveedores</h3>
        <span class="toggle-icon">▼</span>
    </div>
    <div class="card-body">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Fecha</th><th>Proveedor</th><th class="right">Monto</th></tr></thead>
          <tbody>
          <?php if(!$listaPagos): ?><tr><td colspan="3" class="right">Sin pagos.</td></tr>
          <?php else: foreach($listaPagos as $r): ?>
            <tr>
              <td><?= h($r['fecha']) ?></td>
              <td><?= h($r['proveedor']) ?></td>
              <td class="right" style="color:#d97706"><b>- <?= h(cop($r['monto'])) ?></b></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card collapsed">
    <div class="card-header" onclick="toggle(this)">
        <h3>⚠️ Alerta Stock</h3>
        <span class="toggle-icon">▼</span>
    </div>
    <div class="card-body">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Prod.</th><th>Cat.</th><th class="right">Stock</th></tr></thead>
          <tbody>
          <?php if(!$bajoStock): ?><tr><td colspan="3" class="right">Todo OK.</td></tr>
          <?php else: foreach($bajoStock as $p): ?>
            <tr>
              <td><?= h($p['nombre']) ?></td>
              <td><small><?= catNombre($p['categoria_id']??1) ?></small></td>
              <td class="right" style="color:var(--danger)"><b><?= (int)$p['stock'] ?></b></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<script>
function toggle(header) {
    header.parentElement.classList.toggle('collapsed');
}
</script>
</body>
</html>