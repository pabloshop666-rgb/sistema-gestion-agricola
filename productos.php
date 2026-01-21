<?php
// ==========================================
// 1. CONFIGURACIÓN Y SEGURIDAD
// ==========================================
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (file_exists(__DIR__ . '/config/db.php')) include_once __DIR__ . '/config/db.php';
if (!isset($conexion) || !($conexion instanceof mysqli)) $conexion = new mysqli('localhost', 'root', '', 'agricola');
$conexion->set_charset('utf8mb4');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!(isset($_SESSION['user_name']) || isset($_SESSION['nombre']) || isset($_SESSION['usuario']) || isset($_SESSION['user_id']))) {
    header('Location: ./login.php'); exit();
}
$usuario_actual = [
    'id'     => $_SESSION['user_id']   ?? 0,
    'nombre' => $_SESSION['user_name'] ?? 'Usuario',
    'rol'    => $_SESSION['user_rol']  ?? 'empleado'
];

function cop($v){ return 'COP $'.number_format((int)$v, 0, ',', '.'); }
function catNombre($id){ return [1=>'Kilos',2=>'Bulto',3=>'Unidad'][(int)$id] ?? 'Kilos'; }

// ==========================================
// 2. AUDITORÍA
// ==========================================
function registrarEdicion($db,$pid,$pname,$accion,$user,$chg=[]){
    $ok = $db->query("SHOW TABLES LIKE 'registro_ediciones'");
    if(!$ok || $ok->num_rows===0) return;
    $sql="INSERT INTO registro_ediciones (producto_id,producto_nombre,usuario,rol,accion,precio_old,precio_new,stock_old,stock_new,vence_old,vence_new) VALUES (?,?,?,?,?,?,?,?,?,?,?)";
    $stmt=$db->prepare($sql);
    if($stmt){
        $po=$chg['precio_old']??null; $pn=$chg['precio_new']??null;
        $so=$chg['stock_old']??null;  $sn=$chg['stock_new']??null;
        $vo=$chg['vence_old']??null;  $vn=$chg['vence_new']??null;
        $stmt->bind_param('issssiiiiss',$pid,$pname,$user['nombre'],$user['rol'],$accion,$po,$pn,$so,$sn,$vo,$vn);
        $stmt->execute();
    }
}

function detectarCambios($prev,$nuevo){
    $c=[];
    if((int)$prev['precio']!==(int)$nuevo['precio']) 
        $c[]="precio: ".number_format((int)$prev['precio'])." -> ".number_format((int)$nuevo['precio']);
    $a=(int)$prev['stock']; $b=(int)$nuevo['stock'];
    if($a!==$b){ $d=$b-$a; $c[]=($d>0?"añadió {$d}":"redujo ".abs($d))." (de {$a} a {$b})"; }
    $mA=(int)($prev['stock_minimo']??0); $mB=(int)($nuevo['stock_minimo']??0);
    if($mA!==$mB) $c[]="alerta mínima: {$mA} -> {$mB}";
    $v1=($prev['fecha_vencimiento']??''); if($v1==='0000-00-00')$v1='';
    $v2=($nuevo['fecha_vencimiento']??''); if($v2==='0000-00-00')$v2='';
    if($v1!==$v2) $c[]="vencimiento";
    if(($prev['nombre']??'')!==($nuevo['nombre']??'')) $c[]="nombre cambiado";
    return implode(', ',$c);
}

// ==========================================
// 3. VARIABLES
// ==========================================
$buscar = trim($_GET['q'] ?? '');
$errores=[]; 
$nChoiceRaw = trim($_GET['setn'] ?? $_GET['n'] ?? 'all');
$nChoice = strtolower($nChoiceRaw);
if ($nChoice === 'todos' || $nChoice === 'all' || $nChoice === '*') { $limit = null; $nChoice = 'all'; } 
else { $limit = (int)$nChoice; if ($limit <= 0) $limit = 50; $nChoice = (string)$limit; }

// ==========================================
// 4. PROCESAR POST
// ==========================================
if($_SERVER['REQUEST_METHOD']==='POST'){
    $accion = $_POST['accion'] ?? $_POST['action'] ?? '';
    $q_back = urlencode(trim($_POST['_q'] ?? ''));
    $isAjax = (stripos($_SERVER['HTTP_ACCEPT'] ?? '','application/json')!==false) || (strtolower($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='xmlhttprequest');

    if($accion==='crear' || $accion==='add'){
        $nombre=trim($_POST['nombre']??'');
        $categoria_id=(int)($_POST['categoria_id']??1);
        $precio=(int)($_POST['precio']??0);
        $stock =(int)($_POST['stock']??0);
        $minimo=(int)($_POST['stock_minimo']??5);
        $fv=trim($_POST['fecha_vencimiento']??'');

        if($nombre==='')$errores[]='Nombre obligatorio.';
        if(!$errores){
            $nombre_sql=$conexion->real_escape_string($nombre);
            $ex=$conexion->query("SELECT id,precio,stock,stock_minimo,COALESCE(DATE_FORMAT(fecha_vencimiento,'%Y-%m-%d'),'') fv FROM productos WHERE nombre='$nombre_sql' AND categoria_id=$categoria_id LIMIT 1");
            $fv_sql = ($fv!=='') ? "'".$conexion->real_escape_string($fv)."'" : "NULL";

            if($ex && $ex->num_rows){
                $row=$ex->fetch_assoc(); $id=(int)$row['id'];
                $nuevoStock = (int)$row['stock'] + $stock;
                $conexion->query("UPDATE productos SET precio=$precio, stock=$nuevoStock, stock_minimo=$minimo, fecha_vencimiento=$fv_sql WHERE id=$id");
                registrarEdicion($conexion,$id,$nombre,"Sumó stock (form)",$usuario_actual,['stock_old'=>$row['stock'],'stock_new'=>$nuevoStock]);
                header("Location: ./productos.php?q=$q_back#row-$id"); exit();
            } else {
                $conexion->query("INSERT INTO productos(nombre,categoria_id,precio,stock,stock_minimo,fecha_vencimiento) VALUES('$nombre_sql',$categoria_id,$precio,$stock,$minimo,$fv_sql)");
                $nid=$conexion->insert_id;
                registrarEdicion($conexion,$nid,$nombre,"Creó producto",$usuario_actual,['stock_new'=>$stock]);
                header("Location: ./productos.php?q=$q_back#row-$nid"); exit();
            }
        }
        header("Location: ./productos.php?q=$q_back&err=".urlencode(implode(', ',$errores))); exit();
    }

    if($accion==='actualizar' || $accion==='update'){
        $id=(int)($_POST['id']??$_POST['actualizar_id']??0);
        $nombre = trim($_POST['nombre'] ?? $_POST['e_nombre'] ?? '');
        $cat    = (int)($_POST['categoria_id'] ?? $_POST['e_categoria_id'] ?? 1);
        $precio = (int)($_POST['precio'] ?? $_POST['e_precio'] ?? 0);
        $stock  = (int)($_POST['stock'] ?? $_POST['e_stock'] ?? 0);
        $minimo = (int)($_POST['stock_minimo'] ?? $_POST['e_stock_minimo'] ?? 5); 
        $fv     = trim($_POST['fecha_vencimiento'] ?? $_POST['e_fecha_vencimiento'] ?? '');

        if($id>0 && $nombre!==''){
            $prev = $conexion->query("SELECT *, COALESCE(DATE_FORMAT(fecha_vencimiento,'%Y-%m-%d'),'') fv FROM productos WHERE id=$id")->fetch_assoc();
            $fv_sql = ($fv!=='') ? "'".$conexion->real_escape_string($fv)."'" : "NULL";
            $conexion->query("UPDATE productos SET nombre='".$conexion->real_escape_string($nombre)."', categoria_id=$cat, precio=$precio, stock=$stock, stock_minimo=$minimo, fecha_vencimiento=$fv_sql WHERE id=$id");

            if($prev){
                $nuevo = ['nombre'=>$nombre,'categoria_id'=>$cat,'precio'=>$precio,'stock'=>$stock,'stock_minimo'=>$minimo,'fecha_vencimiento'=>$fv];
                $det = detectarCambios($prev,$nuevo);
                if($det) registrarEdicion($conexion,$id,$nombre,"Editó: $det",$usuario_actual,['stock_old'=>$prev['stock'],'stock_new'=>$stock,'precio_old'=>$prev['precio'],'precio_new'=>$precio]);
            }
            if($isAjax){
                $row = $conexion->query("SELECT id,nombre,categoria_id,precio,stock,stock_minimo,COALESCE(DATE_FORMAT(fecha_vencimiento,'%Y-%m-%d'),'') fv FROM productos WHERE id=$id")->fetch_assoc();
                header('Content-Type: application/json'); echo json_encode(['success'=>true,'row'=>$row]); exit();
            }
            header("Location: ./productos.php?q=$q_back#row-$id"); exit();
        }
        if($isAjax){ echo json_encode(['success'=>false]); exit(); }
    }

    if($accion==='eliminar'){
        $id=(int)$_POST['id'];
        try {
            $conexion->begin_transaction();
            $conexion->query("DELETE FROM productos WHERE id=$id");
            $conexion->commit();
            header("Location: ./productos.php?q=$q_back"); exit();
        } catch (Exception $e) {
            $conexion->rollback();
            if($e->getCode() == 1451){ 
                try {
                    $conexion->query("UPDATE venta_detalles SET producto_id=NULL WHERE producto_id=$id");
                    $conexion->query("DELETE FROM productos WHERE id=$id");
                    header("Location: ./productos.php?q=$q_back&ok=Eliminado_y_desligado"); exit();
                } catch(Exception $ex){
                    header("Location: ./productos.php?q=$q_back&err=Tiene_ventas_activas"); exit();
                }
            }
            header("Location: ./productos.php?q=$q_back&err=Error_desconocido"); exit();
        }
    }
}

// ==========================================
// 5. CONSULTAS VISTA
// ==========================================
$cond="1=1";
if($buscar!==''){
    $q=$conexion->real_escape_string($buscar);
    $cond="(nombre LIKE '%$q%' OR CASE categoria_id WHEN 1 THEN 'Kilos' WHEN 2 THEN 'Bulto' WHEN 3 THEN 'Unidad' END LIKE '%$q%')";
}
$limitSql = $limit ? (" LIMIT ".$limit) : "";

$sql = "SELECT id,nombre,categoria_id,precio,stock,stock_minimo,COALESCE(DATE_FORMAT(fecha_vencimiento,'%Y-%m-%d'),'') AS fv 
        FROM productos WHERE $cond ORDER BY nombre ASC $limitSql";
$rs = $conexion->query($sql);

$bajo=0; $totVal=0;
$kpiQ = $conexion->query("SELECT stock, precio, stock_minimo FROM productos");
while($r=$kpiQ->fetch_assoc()){
    $totVal += (int)$r['stock'] * (int)$r['precio'];
    $min = isset($r['stock_minimo']) ? (int)$r['stock_minimo'] : 5;
    if((int)$r['stock'] <= $min) $bajo++;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no"/>
<title>Inventario</title>
<style>
/* CSS GENERAL */
:root{--bg:#f3f4f6;--card:#ffffff;--txt:#111827;--primary:#2563eb;--danger:#ef4444;--success:#10b981;--warn:#f59e0b;--border:#e5e7eb;}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--txt);padding-bottom:60px;}
a{text-decoration:none;color:var(--primary);}
.wrap{max-width:1100px;margin:0 auto;padding:15px;}

/* Header & KPI */
.top-bar{display:flex;justify-content:space-between;align-items:center;background:var(--card);padding:15px;border-radius:12px;box-shadow:0 1px 2px rgba(0,0,0,0.05);margin-bottom:20px;}
.top-bar h1{margin:0;font-size:1.3rem;font-weight:700;}
.user-tag{font-size:0.8rem;color:#6b7280;background:#f9fafb;padding:4px 8px;border-radius:6px;border:1px solid var(--border);}
.kpi-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px;}
.kpi{background:var(--card);padding:15px;border-radius:12px;text-align:center;box-shadow:0 1px 2px rgba(0,0,0,0.05);}
.kpi span{display:block;font-size:1.4rem;font-weight:800;margin-top:5px;color:#1f2937;}
.kpi small{color:#6b7280;font-size:0.75rem;text-transform:uppercase;font-weight:600;}

/* Formulario */
.form-box{background:var(--card);padding:20px;border-radius:12px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);margin-bottom:25px;}
.form-title{margin-top:0;font-size:1rem;color:var(--primary);margin-bottom:15px;}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:15px;align-items:end;}
.inp-g{display:flex;flex-direction:column;gap:5px;}
.inp-g label{font-size:0.8rem;font-weight:600;color:#374151;}
input,select{padding:10px;border:1px solid #d1d5db;border-radius:8px;width:100%;font-size:0.95rem;background:#fff;}
input:focus,select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,0.1);}
/* Botón Principal */
.btn-pri{background:var(--primary);color:#fff;border:none;padding:12px;border-radius:8px;font-weight:700;cursor:pointer;width:100%;font-size:0.95rem;}
.btn-pri:hover{background:#1d4ed8;}

/* Tabla y Botones Escritos */
.tbl-container{background:var(--card);border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.05);overflow:hidden;}
.tbl-responsive{overflow-x:auto;}
table{width:100%;border-collapse:collapse;white-space:nowrap;}
th{background:#f9fafb;padding:12px 15px;text-align:left;font-size:0.75rem;text-transform:uppercase;color:#6b7280;font-weight:700;}
td{padding:12px 15px;border-bottom:1px solid #f3f4f6;}
tr:last-child td{border-bottom:none;}
.fila-producto:hover{background-color:#f9fafb;}

/* ESTILOS DE LOS BOTONES DE TEXTO (LO NUEVO) */
.btn-txt {
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 700;
    border: none;
    cursor: pointer;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-block;
    margin-right: 4px;
}
.btn-edit-style { background: #e5e7eb; color: #374151; } /* Gris para Editar */
.btn-del-style { background: #fee2e2; color: #dc2626; }  /* Rojo claro para Eliminar */
.btn-save-style { background: #10b981; color: #fff; }     /* Verde para Guardar */

/* Badges */
.badge{padding:5px 10px;border-radius:99px;font-size:0.75rem;font-weight:700;}
.stk-ok{background:#d1fae5;color:#065f46;}
.stk-low{background:#fee2e2;color:#991b1b;box-shadow:0 0 0 1px #fecaca;}

/* Editores Inline */
.inp-edit{padding:5px;border:1px solid var(--primary);border-radius:4px;font-size:0.9rem;}
.editing{background:#eff6ff !important;}

/* Búsqueda */
.controls-row{display:flex;gap:10px;margin-bottom:15px;flex-wrap:wrap;}
.search-box{flex:1;position:relative;}
.search-box input{padding-left:35px;}
.search-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;}

@media(max-width:768px){
    .wrap{padding:10px;}
    .kpi-row{gap:8px;}
    .kpi span{font-size:1.1rem;}
    .ocultar-cel{display:none;}
    .form-grid{grid-template-columns:1fr 1fr;}
    .col-full{grid-column:1/-1;}
    .btn-pri{padding:10px;}
    /* Botones más grandes en móvil para leer mejor */
    .btn-txt{padding:8px 12px; font-size:0.75rem;} 
}
</style>
</head>
<body>

<div class="wrap">
    
    <div class="top-bar">
        <div>
            <h1>📦 Inventario</h1>
            <span class="user-tag">👤 <?=htmlspecialchars($usuario_actual['nombre'])?></span>
        </div>
        <a href="./admin.php" style="font-size:0.9rem;font-weight:600;color:#ef4444;">Atras</a>
    </div>

    <div class="kpi-row">
        <div class="kpi">
            <small>Total Valor</small>
            <span style="color:var(--success)"><?=cop($totVal)?></span>
        </div>
        <div class="kpi">
            <small>Productos</small>
            <span><?=$rs->num_rows?></span>
        </div>
        <div class="kpi">
            <small>Stock Bajo</small>
            <span style="color:<?=($bajo>0?'var(--danger)':'inherit')?>"><?=$bajo?></span>
        </div>
    </div>

    <div class="controls-row">
        <form action="" method="GET" class="search-box">
            <span class="search-icon">🔍</span>
            <input type="text" name="q" value="<?=htmlspecialchars($buscar)?>" placeholder="Buscar producto..." autocomplete="off">
        </form>
        <form action="" method="GET">
            <select name="setn" onchange="this.form.submit()" style="width:auto;cursor:pointer;">
                <option value="50"  <?=$nChoice==='50'?'selected':''?>>Ver 50</option>
                <option value="100" <?=$nChoice==='100'?'selected':''?>>Ver 100</option>
                <option value="all" <?=$nChoice==='all'?'selected':''?>>Ver Todos</option>
            </select>
        </form>
    </div>

    <div class="form-box">
        <h3 class="form-title">✨ Agregar Producto o Stock</h3>
        <form action="" method="POST" class="form-grid">
            <input type="hidden" name="accion" value="crear">
            <input type="hidden" name="_q" value="<?=htmlspecialchars($buscar)?>">
            
            <div class="inp-g col-full">
                <label>Nombre del Producto</label>
                <input name="nombre" required placeholder="  " autocomplete="off">
            </div>
            <div class="inp-g">
                <label>Precio</label>
                <input type="number" name="precio" required placeholder="$ 0">
            </div>
            <div class="inp-g">
                <label>Stock Actual</label>
                <input type="number" name="stock" required placeholder="Cantidad">
            </div>
            <div class="inp-g">
                <label style="color:var(--warn)">⚠️ Alerta Mínima</label>
                <input type="number" name="stock_minimo" value="5" required placeholder="Ej: 5">
            </div>
            <div class="inp-g ocultar-cel">
                <label>Categoría</label>
                <select name="categoria_id">
                    <option value="1">Kilos</option>
                    <option value="2">Bulto</option>
                    <option value="3">Unidad</option>
                </select>
            </div>
            <div class="inp-g ocultar-cel">
                <label>Vencimiento</label>
                <input type="date" name="fecha_vencimiento">
            </div>
            <div class="inp-g col-full">
                <button class="btn-pri">GUARDAR CAMBIOS</button>
            </div>
        </form>
    </div>

    <div class="tbl-container">
        <div class="tbl-responsive">
            <table id="tablaProductos">
                <thead>
                    <tr>
                        <th class="ocultar-cel">Cat</th>
                        <th>Producto</th>
                        <th>Precio</th>
                        <th>Stock</th>
                        <th>Mín.</th>
                        <th class="ocultar-cel">Vence</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                <?php 
                $rs->data_seek(0);
                while($r=$rs->fetch_assoc()): 
                    $id=$r['id'];
                    $stock=(int)$r['stock'];
                    $min=(int)($r['stock_minimo']??5);
                    $esBajo = ($stock <= $min);
                    $badgeClase = $esBajo ? 'stk-low' : 'stk-ok';
                    $badgeTexto = $esBajo ? "$stock ⚠️" : $stock;
                ?>
                    <tr id="row-<?=$id?>" class="fila-producto"
                        data-id="<?=$id?>" 
                        data-nombre="<?=htmlspecialchars($r['nombre'])?>" 
                        data-cat="<?=$r['categoria_id']?>"
                        data-precio="<?=$r['precio']?>"
                        data-stock="<?=$stock?>"
                        data-min="<?=$min?>"
                        data-fv="<?=$r['fv']?>">
                        
                        <td class="ocultar-cel"><?=catNombre($r['categoria_id'])?></td>
                        <td class="cell-nombre" style="font-weight:600;color:var(--txt)">
                            <?=htmlspecialchars($r['nombre'])?>
                        </td>
                        <td class="cell-precio"><?=cop($r['precio'])?></td>
                        <td class="cell-stock">
                            <span class="badge <?=$badgeClase?>"><?=$badgeTexto?></span>
                        </td>
                        <td class="cell-min" style="color:#9ca3af;font-size:0.85rem;text-align:center;">
                            <?=$min?>
                        </td>
                        <td class="ocultar-cel cell-fv" style="font-size:0.8rem;color:#6b7280;">
                            <?=$r['fv']?:'-'?>
                        </td>
                        <td>
                            <button class="btn-txt btn-edit-style btn-edit">EDITAR</button>

                            <form action="" method="POST" style="display:inline" onsubmit="return confirm('¿Seguro deseas ELIMINAR este producto?');">
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id" value="<?=$id?>">
                                <input type="hidden" name="_q" value="<?=htmlspecialchars($buscar)?>">
                                <button class="btn-txt btn-del-style">ELIMINAR</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// 1. Buscador en Vivo
(function(){
    const input = document.querySelector('input[name="q"]');
    if(!input) return;
    input.addEventListener('keyup', function(){
        const term = this.value.toLowerCase();
        const filas = document.querySelectorAll('.fila-producto');
        filas.forEach(fila => {
            const txt = fila.innerText.toLowerCase();
            fila.style.display = txt.includes(term) ? '' : 'none';
        });
    });
})();

// 2. Editor Rápido con Botones de Texto
const QS=(s,p=document)=>p.querySelector(s);

function buildEditorRow(tr){
  if(tr.dataset.editing==='1') return;
  tr.dataset.editing='1'; tr.classList.add('editing');

  const d = tr.dataset;
  const cNom = QS('.cell-nombre',tr);
  const cPre = QS('.cell-precio',tr);
  const cStk = QS('.cell-stock',tr);
  const cMin = QS('.cell-min',tr);
  const btn  = QS('.btn-edit',tr);

  // Guardar HTML original
  tr.dataset.htmlNom = cNom.innerHTML;
  tr.dataset.htmlPre = cPre.innerHTML;
  tr.dataset.htmlStk = cStk.innerHTML;
  tr.dataset.htmlMin = cMin.innerHTML;

  // Crear Inputs
  cNom.innerHTML = `<input class="inp-edit" id="e_nom_${d.id}" value="${d.nombre}" style="width:100%">`;
  cPre.innerHTML = `<input type="number" class="inp-edit" id="e_pre_${d.id}" value="${d.precio}" style="width:80px">`;
  cStk.innerHTML = `<input type="number" class="inp-edit" id="e_stk_${d.id}" value="${d.stock}" style="width:60px">`;
  cMin.innerHTML = `<input type="number" class="inp-edit" id="e_min_${d.id}" value="${d.min}" style="width:50px">`;

  // "GUARDAR" (VERDE)
  btn.innerHTML = 'GUARDAR';
  btn.className = 'btn-txt btn-save-style btn-edit'; // Le aplicamos el estilo verde
  btn.onclick = ()=> saveRow(tr);
}

async function saveRow(tr){
  const id = tr.dataset.id;
  const nom = document.getElementById('e_nom_'+id).value;
  const pre = document.getElementById('e_pre_'+id).value;
  const stock = document.getElementById('e_stk_'+id).value;
  const min = document.getElementById('e_min_'+id).value;
  const fv = tr.dataset.fv || '';
  const cat = tr.dataset.cat || 1;

  let fd = new FormData();
  fd.append('accion','actualizar');
  fd.append('id',id);
  fd.append('e_nombre',nom);
  fd.append('e_categoria_id',cat);
  fd.append('e_precio',pre);
  fd.append('e_stock',stock);
  fd.append('e_stock_minimo',min); 
  fd.append('e_fecha_vencimiento',fv);
  fd.append('_q', document.querySelector('input[name="q"]').value);

  try {
      const r = await fetch('./productos.php',{
          method:'POST',
          body:fd,
          headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
      });
      const j = await r.json();
      
      if(j.success && j.row){
          // Actualizar memoria
          tr.dataset.nombre = j.row.nombre;
          tr.dataset.precio = j.row.precio;
          tr.dataset.stock = j.row.stock;
          tr.dataset.min = j.row.stock_minimo;
          refreshRow(tr, j.row);
          tr.dataset.editing='0'; tr.classList.remove('editing');
      } else {
          alert('Error al guardar');
      }
  } catch(e){
      console.error(e);
      alert('Error de conexión');
  }
}

function refreshRow(tr, row){
    QS('.cell-nombre',tr).textContent = row.nombre;
    QS('.cell-precio',tr).textContent = 'COP $'+parseInt(row.precio).toLocaleString();
    
    // Semáforo
    const s = parseInt(row.stock);
    const m = parseInt(row.stock_minimo);
    const isLow = s <= m;
    const badge = `<span class="badge ${isLow?'stk-low':'stk-ok'}">${s}${isLow?' ⚠️':''}</span>`;
    
    QS('.cell-stock',tr).innerHTML = badge;
    QS('.cell-min',tr).textContent = m;

    // RESTAURAR BOTÓN A "EDITAR" (GRIS)
    const btn = QS('.btn-edit',tr);
    btn.innerHTML = 'EDITAR';
    btn.className = 'btn-txt btn-edit-style btn-edit'; // Estilo gris
    btn.onclick = ()=> buildEditorRow(tr);
}

document.querySelectorAll('.btn-edit').forEach(btn => {
    btn.onclick = function(){ buildEditorRow(this.closest('tr')); }
});
</script>
</body>
</html>