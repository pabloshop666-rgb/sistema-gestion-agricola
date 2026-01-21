<?php
// ventas.php — POS con Barra de Meta Diaria y Formularios Colapsables
ini_set('display_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require __DIR__ . '/config/db.php';
date_default_timezone_set('America/Bogota');

/* ---------- utilidades ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function cop($n){ $n = is_numeric($n)?(float)$n:0; return 'COP ' . number_format($n, 0, ',', '.'); }
function uid(){
  foreach(['user_id','id','usuario','user','username'] as $k){
    if (!empty($_SESSION[$k])) {
      if (is_array($_SESSION[$k]) && isset($_SESSION[$k]['id'])) return (int)$_SESSION[$k]['id'];
      return (int)$_SESSION[$k];
    }
  }
  return null;
}
function back(){ header('Location: '. strtok($_SERVER['REQUEST_URI'],'?')); exit; }
function columna_existe(mysqli $cx, string $tabla, string $col): bool {
  $rs = $cx->query("SHOW COLUMNS FROM `$tabla` LIKE '".$cx->real_escape_string($col)."'");
  return $rs && $rs->num_rows > 0;
}
function msg_es(string $m): string {
  $m = (string)$m;
  if (stripos($m,'Unknown column') !== false) return 'Error de base de datos: columna desconocida. Verifica estructura.';
  if (stripos($m,'foreign key') !== false)   return 'Error de base de datos: restricción de clave foránea.';
  if (stripos($m,'Duplicate entry') !== false) return 'Error: registro duplicado.';
  return $m;
}

/* ---------- carrito ---------- */
if (!isset($_SESSION['carrito'])) $_SESSION['carrito'] = [];
$carrito = &$_SESSION['carrito'];

/* ---------- acciones POST ---------- */
try{
  if ($_SERVER['REQUEST_METHOD']==='POST'){
    $accion = $_POST['accion'] ?? '';

    // Agregar al carrito
    if ($accion==='agregar'){
      $pid  = (int)($_POST['producto_id'] ?? 0);
      $cant = max(1, (int)($_POST['cantidad'] ?? 1));

      $st = $conexion->prepare("SELECT id,nombre,precio,stock FROM productos WHERE id=?");
      $st->bind_param('i',$pid); $st->execute();
      $p = $st->get_result()->fetch_assoc();
      if(!$p) throw new Exception('Producto no encontrado.');
      if($cant > (int)$p['stock']) throw new Exception('No hay stock suficiente.');

      $found=false;
      foreach($carrito as &$it){
        if($it['id']==$pid){ $it['cantidad'] += $cant; $found=true; break; }
      }
      if(!$found) $carrito[] = ['id'=>$p['id'],'nombre'=>$p['nombre'],'precio'=>(float)$p['precio'],'cantidad'=>$cant];
      back();
    }

    // Quitar un producto del carrito
    if ($accion === 'quitar') {
      $pid = (int)($_POST['producto_id'] ?? 0);
      if ($pid) {
        foreach ($carrito as $k => $it) {
          if ((int)$it['id'] === $pid) {
            unset($carrito[$k]);
            $carrito = array_values($carrito);
            break;
          }
        }
      }
      back();
    }

    // Vaciar carrito
    if ($accion==='vaciar'){ $carrito = []; back(); }

    // Cerrar venta
    if ($accion==='cerrar_venta'){
      if(!$carrito) throw new Exception('Agrega productos al carrito.');

      $total = 0;
      foreach($carrito as $it) $total += (float)$it['precio'] * (int)$it['cantidad'];

      $recibido = isset($_POST['recibido']) ? (float)$_POST['recibido'] : 0;
      if ($recibido < $total) throw new Exception('El recibido es menor al total.');

      $conexion->begin_transaction();

      $usuarioId = uid();
      $tieneUsuarioId = columna_existe($conexion, 'ventas', 'usuario_id');

      if ($tieneUsuarioId) {
        $st = $conexion->prepare("INSERT INTO ventas (fecha, usuario_id, total) VALUES (NOW(), ?, ?)");
        $st->bind_param('id', $usuarioId, $total);
      } else {
        $st = $conexion->prepare("INSERT INTO ventas (fecha, total) VALUES (NOW(), ?)");
        $st->bind_param('d', $total);
      }
      $st->execute();
      $ventaId = $conexion->insert_id;

      // Descuento de stock + detalle
      foreach($carrito as $it){
        $pid  = (int)$it['id'];
        $cant = (int)$it['cantidad'];
        $pu   = (float)$it['precio'];

        $upd = $conexion->prepare("UPDATE productos SET stock = stock - ? WHERE id = ? AND stock >= ?");
        $upd->bind_param('iii', $cant, $pid, $cant);
        $upd->execute();
        if ($upd->affected_rows !== 1) throw new Exception('Stock insuficiente al cerrar para el producto ID ' . $pid);

        $ins = $conexion->prepare("INSERT INTO venta_detalles (venta_id, producto_id, cantidad, precio) VALUES (?, ?, ?, ?)");
        $ins->bind_param('iiid', $ventaId, $pid, $cant, $pu);
        $ins->execute();
      }

      $chk = $conexion->query("SELECT COALESCE(SUM(precio*cantidad),0) AS sum_items FROM venta_detalles WHERE venta_id=$ventaId")->fetch_assoc();
      $sumItems = (float)($chk['sum_items'] ?? 0);
      $fix = $conexion->prepare("UPDATE ventas SET total=? WHERE id=?");
      $fix->bind_param('di', $sumItems, $ventaId);
      $fix->execute();

      $conexion->commit();
      $carrito = [];
      back();
    }

    // Egreso
    if ($accion==='egreso'){
      $concepto = trim($_POST['concepto'] ?? '');
      $monto = (float)($_POST['monto'] ?? 0);
      $obs   = trim($_POST['obs'] ?? '');
      $mp    = trim($_POST['metodo_pago'] ?? 'Efectivo');
      if($concepto==='' || $monto<=0) throw new Exception('Concepto y monto (>0) requeridos.');
      $st=$conexion->prepare("INSERT INTO egresos (concepto, monto, observaciones, metodo_pago, fecha) VALUES (?,?,?,?,NOW())");
      $st->bind_param('sdss',$concepto,$monto,$obs,$mp);
      $st->execute(); back();
    }

    // Pago de factura
    if ($accion==='pago_factura'){
      $prov  = trim($_POST['proveedor'] ?? '');
      $monto = (float)($_POST['monto'] ?? 0);
      $num   = trim($_POST['num_factura'] ?? '');
      $mp    = trim($_POST['metodo_pago'] ?? 'Efectivo');
      if($prov==='' || $monto<=0) throw new Exception('Proveedor y monto (>0) requeridos.');
      $st=$conexion->prepare("INSERT INTO pagos_facturas (proveedor, monto, metodo_pago, num_factura, fecha) VALUES (?,?,?,?,NOW())");
      $st->bind_param('sdss',$prov,$monto,$mp,$num);
      $st->execute(); back();
    }
  }
}catch(Throwable $e){
  $_SESSION['flash']=['tipo'=>'err','msg'=>msg_es($e->getMessage())];
  back();
}

/* ---------- datos para la vista ---------- */
$prods=[]; $q=$conexion->query("SELECT id, nombre, precio, stock FROM productos ORDER BY nombre ASC");
while($r=$q->fetch_assoc()) $prods[]=$r;

/* KPIs del día (Bogotá) */
$hoy = date('Y-m-d');
$ventasHoy  = (float)($conexion->query("SELECT COALESCE(SUM(total),0) t FROM ventas WHERE DATE(fecha)='$hoy'")->fetch_assoc()['t'] ?? 0);
$egresosHoy = (float)($conexion->query("SELECT COALESCE(SUM(monto),0) t FROM egresos WHERE DATE(fecha)='$hoy'")->fetch_assoc()['t'] ?? 0);
$pagosHoy   = (float)($conexion->query("SELECT COALESCE(SUM(monto),0) t FROM pagos_facturas WHERE DATE(fecha)='$hoy'")->fetch_assoc()['t'] ?? 0);

/* NETO HOY: Ventas − Egresos − Pagos */
$netoHoy    = $ventasHoy - $egresosHoy - $pagosHoy;

/* ====== CÁLCULO DE META DIARIA PARA BARRA ====== */
$metaDiaria = 205000; // La meta "Empresario Pro"
$porcMeta = 0;
$colorMeta = '#dc2626'; // Rojo inicio

if($netoHoy > 0) {
    $porcMeta = ($netoHoy / $metaDiaria) * 100;
    if($porcMeta > 100) $porcMeta = 100;

    if($netoHoy >= 100000 && $netoHoy < $metaDiaria){
        $colorMeta = '#f59e0b'; // Amarillo
    } elseif($netoHoy >= $metaDiaria){
        $colorMeta = '#16a34a'; // Verde
    }
}


/* ====== AJUSTE DE HORA A COLOMBIA EN PHP ====== */
$offRow = $conexion->query("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS off_sec")->fetch_assoc();
$mysqlOffsetSec = (int)($offRow['off_sec'] ?? 0);
$bogotaOffsetSec = -5 * 3600;
$deltaToBogota = $bogotaOffsetSec - $mysqlOffsetSec;

/* Ítems vendidos hoy */
$det = $conexion->query("
  SELECT v.fecha AS fecha,
         p.nombre AS producto,
         d.precio AS precio,
         d.cantidad
  FROM venta_detalles d
  JOIN ventas v    ON v.id = d.venta_id
  JOIN productos p ON p.id = d.producto_id
  WHERE DATE(v.fecha) = '$hoy'
  ORDER BY v.fecha DESC, d.id DESC
");
$rows=[]; while($r=$det->fetch_assoc()){
  $ts = strtotime($r['fecha']);
  if ($ts !== false) $r['fecha'] = date('Y-m-d H:i:s', $ts + $deltaToBogota);
  $rows[]=$r;
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Ventas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    :root{ --bg:#f6f7fb; --card:#fff; --line:#e6e7ee; --muted:#6b7280; --primary:#2563eb; --primary-600:#1d4ed8; --danger:#e53935 }
    *{ box-sizing:border-box }
    body{ margin:0; background:var(--bg); color:#111; font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif }

    .wrap{max-width:1280px;margin:28px auto;padding:0 12px}
    header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
    h1{margin:0;font-size:24px}
    .btn{background:var(--primary);color:#fff;border:none;border-radius:12px;padding:10px 16px;cursor:pointer;min-height:44px}
    .btn:hover{background:var(--primary-600)} .btn.danger{background:var(--danger)} .btn.secondary{background:#4b5563}
    .btn.icon{min-height:auto;padding:6px 10px;border-radius:10px;font-weight:700}

    .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}
    .kpi{background:#fff;border:1px solid var(--line);padding:16px;border-radius:12px}
    .kpi h3{margin:0 0 6px;font-size:13px;color:var(--muted);letter-spacing:.02em;text-transform:uppercase}
    .kpi .v{font-size:20px;font-weight:700}

    .layout{display:block}
    .card{background:#fff;border:1px solid var(--line);padding:16px;border-radius:12px}
    .layout .card + .card, .layout .card + .col-group {margin-top:16px}
    .layout > .card:first-child h3{font-size:20px}

    label{display:block;margin:6px 0 4px;font-weight:600}
    input, select, textarea{width:100%;padding:12px 10px;border:1px solid var(--line);border-radius:12px;background:#fff;font-size:16px}
    .right{text-align:right}

    .table-wrap{margin-top:10px;border-radius:14px;overflow:hidden;background:#fff;border:1px solid var(--line)}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px 12px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}
    th{background:#f5f7fb;font-weight:600}
    td .x-remove{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:6px 10px;cursor:pointer}
    td .x-remove:hover{background:#fee2e2;border-color:#fecaca;color:#b91c1c}

    @media (max-width:1100px){ .grid{grid-template-columns:repeat(3,1fr)} }
    @media (max-width:900px){ .grid{grid-template-columns:repeat(2,1fr)} .layout{grid-template-columns:1fr} }
    @media (max-width:640px){
      .wrap{padding:12px}
      .btn{width:100%}
      .table-wrap{overflow-x:auto}
      table{min-width:720px}
      th,td{white-space:nowrap}
    }

    .paybox{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px}
    .paybox .tot{display:flex;align-items:center;justify-content:flex-end;font-weight:700}
    @media(max-width:600px){ .paybox{grid-template-columns:1fr} .paybox .tot{justify-content:flex-start} }

    /* ===== Estilos del NUEVO buscador ===== */
    .combo{position:relative}
    .combo-input{
      width:100%; padding:14px 12px; border:1px solid var(--line); border-radius:12px;
      font-size:16px; outline:none;
    }
    .combo-input:focus{ border-color:#c7d2fe; box-shadow:0 0 0 4px rgba(59,130,246,.15) }
    .combo-list{
      position:absolute; left:0; right:0; top:100%; margin-top:6px;
      background:#fff; border:1px solid var(--line); border-radius:12px;
      box-shadow:0 10px 30px rgba(0,0,0,.08); overgflow:auto; z-index:50;
      display:none;
    }
    .combo.open .combo-list{ display:block }
    .combo-item{
      padding:12px 14px; cursor:pointer; display:flex; align-items:center; gap:10px;
      font-size:15.5px;
    }
    .combo-item b{ font-weight:700 }
    .combo-item small{ color:var(--muted) }
    .combo-item:hover, .combo-item.active{ background:#f1f5ff }
    .pill{
      margin-left:auto; font-size:12px; padding:3px 8px; border-radius:999px; background:#eef2ff; color:#3730a3;
    }
    .muted{color:var(--muted)}

    /* ===== NUEVOS ESTILOS PARA BARRA Y ACORDEÓN ===== */
    /* Barra de Progreso */
    .progress-card { background:#fff; border:1px solid var(--line); padding:12px 16px; border-radius:12px; margin-bottom:16px; }
    .progress-track{background:#e5e7eb; border-radius:999px; height:12px; width:100%; overflow:hidden; margin-top:6px;}
    .progress-fill{height:100%; transition:width 0.6s ease;}
    
    /* Acordeón (Colapsables) */
    .col-group { display:flex; flex-direction:column; gap:16px; }
    .card-header{
        padding:0 0 10px 0; display:flex; justify-content:space-between; align-items:center; cursor:pointer;
        border-bottom:1px solid transparent; margin-bottom:0;
    }
    .card:not(.collapsed) .card-header { border-bottom-color: var(--line); margin-bottom:16px; }
    .card-header h3{ margin:0; font-size:18px; color:#374151; }
    .toggle-icon{ font-size: 14px; color: var(--muted); transition: transform 0.3s ease; }
    
    .card.collapsed .toggle-icon{ transform: rotate(-90deg); }
    .card.collapsed .card-body{ display:none; }
  </style>
</head>
<body>
<div class="wrap">

  <header>
    <h1>Ventas</h1>
    <a href="admin.php"><button class="btn">Volver</button></a>
  </header>

  <?php if($flash): ?>
    <div style="background:#ffecec;color:#c02626;border:1px solid #f3a4a4;border-radius:10px;padding:10px 12px;margin-bottom:10px">
      <?= h($flash['msg'] ?? '') ?>
    </div>
  <?php endif; ?>

  <div class="grid">
    <div class="kpi"><h3>VENTAS HOY</h3><div class="v"><?= h(cop($ventasHoy)) ?></div></div>
    <div class="kpi"><h3>EGRESOS HOY</h3><div class="v"><?= h(cop($egresosHoy)) ?></div></div>
    <div class="kpi"><h3>PAGOS HOY</h3><div class="v"><?= h(cop($pagosHoy)) ?></div></div>
    <div class="kpi"><h3>NETO HOY</h3><div class="v"><?= h(cop($netoHoy)) ?></div></div>
  </div>

  <div class="progress-card">
    <div style="display:flex; justify-content:space-between; font-size:14px; font-weight:600; color:#374151;">
        <span>Meta Diaria: <?= cop($metaDiaria) ?></span>
        <span style="color:<?= $colorMeta ?>"><?= round($porcMeta) ?>%</span>
    </div>
    <div class="progress-track">
        <div class="progress-fill" style="width:<?= $porcMeta ?>%; background:<?= $colorMeta ?>;"></div>
    </div>
  </div>

  <div class="layout">
    <div class="card">
      <h3 style="margin:0 0 8px 0">Nueva venta</h3>
      <form method="post">
        <input type="hidden" name="accion" value="agregar">

        <label>Producto</label>
        <div class="combo" id="productCombo">
          <input type="text" id="productInput" class="combo-input" placeholder="Escribe para buscar producto…" autocomplete="off">
          <input type="hidden" name="producto_id" id="productoIdHidden" value="">
          <div class="combo-list" id="comboList" role="listbox" aria-label="Resultados de productos"></div>
        </div>

        <label style="margin-top:8px">Cantidad</label>
        <input type="number" name="cantidad" min="1" value="1">

        <div class="right" style="margin-top:8px">
          <button class="btn danger" name="accion" value="vaciar" type="submit" formaction="ventas.php">Vaciar carrito</button>
          <button class="btn" type="submit">Agregar</button>
        </div>
      </form>

      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>#</th><th>Producto</th><th>Precio</th><th>Cant.</th><th>Subtotal</th><th></th></tr>
          </thead>
          <tbody>
            <?php $total=0; if(!$carrito): ?>
              <tr><td colspan="6" class="right muted">— Sin productos —</td></tr>
            <?php else: $i=1; foreach($carrito as $it): $subtotal=((float)$it['precio']*(int)$it['cantidad']); $total+=$subtotal; ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= h($it['nombre']) ?></td>
                <td class="right"><?= h(cop($it['precio'])) ?></td>
                <td class="right"><?= (int)$it['cantidad'] ?></td>
                <td class="right"><?= h(cop($subtotal)) ?></td>
                <td class="right">
                  <form method="post" style="display:inline">
                    <input type="hidden" name="accion" value="quitar">
                    <input type="hidden" name="producto_id" value="<?= (int)$it['id'] ?>">
                    <button type="submit" class="x-remove" title="Quitar">✕</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
          <tfoot>
            <tr><th colspan="5" class="right">Total</th><th class="right" id="totalCell"><?= h(cop($total)) ?></th></tr>
          </tfoot>
        </table>
      </div>

      <div class="paybox">
        <div>
          <label>Recibido (COP)</label>
          <input type="number" id="recibidoInput" min="0" step="1" value="0">
        </div>
        <div class="tot">
          <div>
            <div>Total: <span id="totalTxt"><?= h(cop($total)) ?></span></div>
            <div>Cambio: <span id="cambioTxt">COP 0</span></div>
          </div>
        </div>
      </div>

      <form id="cerrarForm" method="post" style="margin-top:10px;text-align:right">
        <input type="hidden" name="accion" value="cerrar_venta">
        <input type="hidden" name="recibido" id="recibidoHidden" value="0">
        <button class="btn" type="submit">Cerrar venta</button>
      </form>
    </div>

    <div class="col-group">
        
        <div class="card collapsed">
          <div class="card-header" onclick="toggle(this)">
            <h3>Registrar Egreso</h3>
            <span class="toggle-icon">▼</span>
          </div>
          <div class="card-body">
              <form method="post" style="display:grid;gap:8px">
                <input type="hidden" name="accion" value="egreso">
                <div><label>Concepto / detalle</label><input type="text" name="concepto" required></div>
                <div><label>Monto (COP)</label><input type="number" name="monto" step="1" min="0" value="0" required></div>
                <div><label>Observaciones</label><input type="text" name="obs" placeholder="Opcional"></div>
                <div><label>Método de pago</label>
                  <select name="metodo_pago"><option>Efectivo</option><option>Transferencia</option><option>Tarjeta</option><option>Otro</option></select>
                </div>
                <div><button class="btn" type="submit">Guardar Egreso</button></div>
              </form>
          </div>
        </div>

        <div class="card collapsed">
          <div class="card-header" onclick="toggle(this)">
             <h3>Registrar Pago Factura</h3>
             <span class="toggle-icon">▼</span>
          </div>
          <div class="card-body">
              <form method="post" style="display:grid;gap:8px">
                <input type="hidden" name="accion" value="pago_factura">
                <div><label>Proveedor / Detalle</label><input type="text" name="proveedor" required></div>
                <div><label>Monto (COP)</label><input type="number" name="monto" step="1" min="0" value="0" required></div>
                <div><label>N° factura</label><input type="text" name="num_factura" placeholder="Opcional"></div>
                <div>
                  <label>Método de pago</label>
                  <select name="metodo_pago">
                    <option>Efectivo</option><option>Transferencia</option><option>Tarjeta</option><option>Otro</option>
                  </select>
                </div>
                <div><button class="btn" type="submit">Guardar Pago</button></div>
              </form>
          </div>
        </div>

    </div>
  </div>

  <div class="card" style="margin-top:16px">
    <h3 style="margin:0 0 8px 0">Ítems vendidos hoy</h3>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Fecha</th><th>Producto</th><th>Precio</th><th>Cant.</th><th>Subtotal</th></tr></thead>
        <tbody>
          <?php if(!$rows): ?>
            <tr><td colspan="5" class="right muted">— Sin ventas aún —</td></tr>
          <?php else: foreach($rows as $r): ?>
            <tr>
              <td><?= h($r['fecha']) ?></td>
              <td><?= h($r['producto']) ?></td>
              <td class="right"><?= h(cop($r['precio'])) ?></td>
              <td class="right"><?= (int)$r['cantidad'] ?></td>
              <td class="right"><?= h(cop(((float)$r['precio']*(int)$r['cantidad']))) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
(function(){
  /* ===== Toggle Acordeón ===== */
  window.toggle = function(header) {
    header.parentElement.classList.toggle('collapsed');
  };

  /* ===== Productos desde PHP ===== */
  var PRODS = <?php echo json_encode($prods, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

  /* ===== Combobox ===== */
  var combo   = document.getElementById('productCombo');
  var input   = document.getElementById('productInput');
  var list    = document.getElementById('comboList');
  var hidden  = document.getElementById('productoIdHidden');

  var idxActive = -1;
  var itemsDom = [];

  function cop(n){
    n = Math.round(Number(n||0));
    return 'COP ' + n.toString().replace(/\B(?=(\d{3})+(?!\d))/g,'.');
  }

  function render(q){
    q = (q||'').trim().toLowerCase();
    list.innerHTML = '';
    itemsDom = [];
    var filtered = PRODS.filter(function(p){
      var t = (p.nombre + ' ' + p.precio + ' ' + p.stock).toLowerCase();
      return !q || t.includes(q);
    });
    if(filtered.length === 0){
      var no = document.createElement('div');
      no.className = 'combo-item muted';
      no.textContent = 'Sin coincidencias';
      list.appendChild(no);
    } else {
      filtered.forEach(function(p){
        var el = document.createElement('div');
        el.className = 'combo-item';
        el.setAttribute('role','option');
        el.dataset.id = p.id;
        el.innerHTML = '<div><b>'+escapeHtml(p.nombre)+'</b><br><small>'+cop(p.precio)+' · Stock: '+p.stock+'</small></div><span class="pill">ID '+p.id+'</span>';
        el.addEventListener('mousedown', function(e){
          e.preventDefault();
          selectItem(p);
        });
        list.appendChild(el);
        itemsDom.push(el);
      });
    }
    idxActive = -1;
    open();
  }

  function open(){
    combo.classList.add('open');
    ensureDown();
  }
  function close(){ combo.classList.remove('open'); }

  function selectItem(p){
    hidden.value = p.id;
    input.value = p.nombre;
    close();
  }

  function ensureDown(){
    var need = Math.min(320, list.scrollHeight || 320);
    var rect = combo.getBoundingClientRect();
    var spaceBelow = window.innerHeight - rect.bottom;
    if (spaceBelow < need + 12){
      window.scrollBy({ top: (need + 12 - spaceBelow), left:0, behavior:'smooth' });
    }
  }

  function escapeHtml(s){ return (''+s).replace(/[&<>"']/g,function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])}); }

  input.addEventListener('focus', function(){ render(input.value); });
  input.addEventListener('input', function(){ render(input.value); });
  input.addEventListener('keydown', function(e){
    var max = itemsDom.length - 1;
    if(e.key === 'ArrowDown'){
      e.preventDefault();
      if(!combo.classList.contains('open')) open();
      idxActive = Math.min(max, idxActive+1);
      highlight();
    } else if(e.key === 'ArrowUp'){
      e.preventDefault();
      idxActive = Math.max(0, idxActive-1);
      highlight();
    } else if(e.key === 'Enter'){
      if(idxActive >= 0 && itemsDom[idxActive]){
        e.preventDefault();
        var id = itemsDom[idxActive].dataset.id;
        var p  = PRODS.find(function(x){return String(x.id)===String(id);});
        if(p) selectItem(p);
      }
    } else if(e.key === 'Escape'){
      close();
    }
  });

  function highlight(){
    itemsDom.forEach(function(el){ el.classList.remove('active'); });
    if(idxActive >= 0 && itemsDom[idxActive]){
      itemsDom[idxActive].classList.add('active');
      itemsDom[idxActive].scrollIntoView({ block:'nearest' });
    }
  }

  document.addEventListener('click', function(e){
    if(!combo.contains(e.target) && !e.target.closest('.card-header')) close();
  });

  /* ===== Recibido / Cambio ===== */
  var recibidoInput = document.getElementById('recibidoInput');
  var recibidoHidden = document.getElementById('recibidoHidden');
  var cambioTxt = document.getElementById('cambioTxt');
  var totalTxt = document.getElementById('totalTxt');
  var cerrarForm = document.getElementById('cerrarForm');

  function parseCop(t){ return Number((t||'').toString().replace(/[^\d]/g,'')||0); }
  function formatCop(n){ n=Math.max(0,Math.round(n)); return 'COP ' + n.toString().replace(/\B(?=(\d{3})+(?!\d))/g,'.'); }

  function refreshCambio(){
    var total = parseCop(totalTxt.textContent);
    var recibido = Number(recibidoInput.value || 0);
    var cambio = recibido - total;
    if (cambio < 0) cambio = 0;
    cambioTxt.textContent = formatCop(cambio);
    recibidoHidden.value = recibido;
  }

  if (recibidoInput){
    recibidoInput.addEventListener('input', refreshCambio);
    recibidoInput.addEventListener('focus', function(){ recibidoInput.select(); });
    refreshCambio();
  }

  if (cerrarForm){
    cerrarForm.addEventListener('submit', function(e){
      var total = parseCop(totalTxt.textContent);
      var recibido = Number(recibidoInput.value || 0);
      if (recibido < total){
        e.preventDefault();
        alert('El recibido es menor al total. Ingresa un monto válido.');
      } else {
        recibidoHidden.value = recibido;
      }
    });
  }
})();
</script>
</body>
</html>