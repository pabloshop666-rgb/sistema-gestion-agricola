<?php
// ---------------------------------------------------------
// 1. CONFIGURACIÓN
// ---------------------------------------------------------
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'config/db.php'; 

// ---------------------------------------------------------
// 2. LÓGICA PHP: ACTUALIZAR + HISTORIAL (Tu seguridad)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_producto = $_POST['id_producto'];
    $nuevo_costo = $_POST['nuevo_costo'];
    $nuevo_precio_venta = $_POST['nuevo_precio_venta'];
    
    // Consultamos valores viejos para el historial
    $sql_old = "SELECT nombre, precio_costo, precio FROM productos WHERE id = $id_producto";
    $res_old = $conexion->query($sql_old);
    $datos_viejos = $res_old->fetch_assoc();

    // Actualizamos
    $sql_update = "UPDATE productos SET precio_costo = ?, precio = ? WHERE id = ?";
    $stmt = $conexion->prepare($sql_update);
    $stmt->bind_param("ddi", $nuevo_costo, $nuevo_precio_venta, $id_producto);
    
    if($stmt->execute()){
        // Guardamos en historial
        $usuario = !empty($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Admin';
        $sql_h = "INSERT INTO historial_precios (usuario, producto, costo_ant, costo_nue, precio_ant, precio_nue) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_h = $conexion->prepare($sql_h);
        $stmt_h->bind_param("ssdddd", $usuario, $datos_viejos['nombre'], $datos_viejos['precio_costo'], $nuevo_costo, $datos_viejos['precio'], $nuevo_precio_venta);
        $stmt_h->execute();

        $mensaje = "✅ Precio actualizado y auditado.";
        $color_mensaje = "green";
    } else {
        $mensaje = "❌ Error: " . $stmt->error;
        $color_mensaje = "red";
    }
}

// ---------------------------------------------------------
// 3. CONSULTA DE PRODUCTOS
// ---------------------------------------------------------
$sql = "SELECT id, nombre, precio_costo, precio FROM productos ORDER BY nombre ASC";
$resultado = $conexion->query($sql);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Precios - Agrícola</title>
    <style>
        /* ESTILOS GENERALES */
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f0f2f5; padding: 20px; }
        .container { max-width: 1250px; margin: 0 auto; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        
        /* CABECERA */
        .header-actions { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .btn-volver { text-decoration: none; background-color: #7f8c8d; color: white; padding: 10px 20px; border-radius: 5px; font-weight: bold; }
        h1 { color: #2e7d32; margin: 0; text-align: center; flex-grow: 1; }

        /* BUSCADOR */
        .search-container { margin-bottom: 20px; text-align: center; }
        #buscador { width: 100%; max-width: 500px; padding: 12px; border: 2px solid #ddd; border-radius: 25px; padding-left: 20px; }

        /* TABLA */
        .alerta { text-align: center; padding: 10px; margin-bottom: 15px; border-radius: 5px; color: white; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.95em; }
        th { background-color: #2e7d32; color: white; padding: 12px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #ddd; vertical-align: middle; }
        
        /* INPUTS */
        input[type="number"] { padding: 6px; border: 1px solid #ccc; border-radius: 4px; width: 80px; text-align: center; }
        .input-costo { border: 2px solid #2980b9; background-color: #eaf2f8; font-weight: bold; color: #000; }
        
        .btn-guardar { background-color: #27ae60; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer; font-weight: bold; }
        .btn-guardar:hover { background-color: #1e8449; }

        /* --- ESTILOS DE LA ALERTA INTELIGENTE --- */
        .alerta-ganancia {
            display: inline-block; padding: 4px 8px; border-radius: 12px; 
            font-size: 0.8em; font-weight: bold; margin-top: 5px; width: 100%; text-align: center;
        }
        
        /* Colores del Semáforo */
        .danger { background-color: #fadbd8; color: #c0392b; border: 1px solid #e6b0aa; } /* Rojo */
        .warning { background-color: #fdebd0; color: #d35400; border: 1px solid #fad7a0; } /* Amarillo */
        .success { background-color: #d4efdf; color: #1e8449; border: 1px solid #a9dfbf; } /* Verde */

    </style>
</head>
<body>

<div class="container">
    <div class="header-actions">
        <a href="admin.php" class="btn-volver">⬅ Volver</a>
        <h1>🚜 Radar de Ganancias</h1>
        <div style="width: 100px;"></div>
    </div>

    <?php if(isset($mensaje)): ?>
        <div class="alerta" style="background-color: <?php echo $color_mensaje; ?>;">
            <?php echo $mensaje; ?>
        </div>
    <?php endif; ?>

    <div class="search-container">
        <input type="text" id="buscador" onkeyup="filtrarTabla()" placeholder="🔍 Buscar producto...">
    </div>

    <table id="tablaProductos">
        <thead>
            <tr>
                <th style="width: 25%;">Producto</th>
                <th>Costo Proveedor</th>
                <th>Nuevo Costo</th>
                <th>% Ganancia Real</th>
                <th>Precio Venta</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($resultado && $resultado->num_rows > 0): ?>
                <?php while($row = $resultado->fetch_assoc()): ?>
                    
                    <?php 
                        // --- CÁLCULO INTELIGENTE DEL % REAL ---
                        $costo = $row['precio_costo'];
                        $venta = $row['precio'];
                        $porc_real = 0;

                        if ($costo > 0) {
                            $ganancia = $venta - $costo;
                            $porc_real = round(($ganancia / $costo) * 100);
                        }

                        // --- LÓGICA DEL SEMÁFORO ---
                        $clase_badge = "success"; // Verde por defecto
                        $texto_badge = "✅ Bien: " . $porc_real . "%";

                        if ($porc_real < 20) {
                            // Rojo si ganas menos del 20%
                            $clase_badge = "danger"; 
                            $texto_badge = "⚠️ BAJO: " . $porc_real . "%";
                        } elseif ($porc_real < 30) {
                            // Amarillo si estás entre 20% y 29%
                            $clase_badge = "warning";
                            $texto_badge = "⚖️ Justo: " . $porc_real . "%";
                        }
                    ?>

                    <tr class="fila-producto">
                        <form method="POST" action="proveedores.php">
                            <input type="hidden" name="id_producto" value="<?php echo $row['id']; ?>">
                            
                            <td class="nombre-producto" style="font-weight: 500;"><?php echo $row['nombre']; ?></td>

                            <td style="color: #777;">$ <?php echo number_format($row['precio_costo'], 0, ',', '.'); ?></td>

                            <td>
                                <input type="number" step="0.01" 
                                       name="nuevo_costo" 
                                       class="input-costo" 
                                       value="<?php echo $row['precio_costo']; ?>" 
                                       oninput="calcular(this)">
                            </td>

                            <td>
                                <div style="display:flex; align-items:center; gap:5px;">
                                    <input type="number" step="1" 
                                           class="input-porcentaje" 
                                           value="<?php echo $porc_real; ?>" 
                                           oninput="calcular(this)"> %
                                </div>
                                
                                <div class="alerta-badge <?php echo $clase_badge; ?>">
                                    <span class="alerta-ganancia <?php echo $clase_badge; ?>">
                                        <?php echo $texto_badge; ?>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <span class="precio-final-visual" style="font-size:1.1em; font-weight:bold; color:#d35400;">
                                    $ <?php echo number_format($row['precio'], 0, ',', '.'); ?>
                                </span>
                                <input type="hidden" name="nuevo_precio_venta" class="input-venta-final" value="<?php echo $row['precio']; ?>">
                            </td>

                            <td>
                                <button type="submit" class="btn-guardar">💾</button>
                            </td>
                        </form>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6" style="text-align:center;">No hay productos.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
    function filtrarTabla() {
        let input = document.getElementById("buscador");
        let filtro = input.value.toUpperCase();
        let filas = document.getElementById("tablaProductos").getElementsByClassName("fila-producto");
        for (let i = 0; i < filas.length; i++) {
            let nombre = filas[i].getElementsByClassName("nombre-producto")[0].textContent || filas[i].getElementsByClassName("nombre-producto")[0].innerText;
            filas[i].style.display = nombre.toUpperCase().indexOf(filtro) > -1 ? "" : "none";
        }
    }

    function calcular(elemento) {
        let fila = elemento.closest('tr');
        let costo = parseFloat(fila.querySelector('.input-costo').value) || 0;
        let porcentaje = parseFloat(fila.querySelector('.input-porcentaje').value) || 0;

        let precioVenta = costo + (costo * (porcentaje / 100));
        precioVenta = Math.ceil(precioVenta / 50) * 50;

        fila.querySelector('.precio-final-visual').innerText = "$ " + precioVenta.toLocaleString('es-CO');
        fila.querySelector('.input-venta-final').value = precioVenta;

        // Actualizar la etiqueta visual en tiempo real
        let badge = fila.querySelector('.alerta-ganancia');
        badge.innerText = "Calculando: " + porcentaje + "%";
        
        // Cambiar color en vivo
        badge.className = "alerta-ganancia"; // Reset
        if(porcentaje < 20) badge.classList.add("danger");
        else if(porcentaje < 30) badge.classList.add("warning");
        else badge.classList.add("success");
    }
</script>

</body>
</html>
<?php $conexion->close(); ?>