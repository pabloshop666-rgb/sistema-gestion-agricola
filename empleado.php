<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header('Location: login.php');
    exit;
}
$nombre = htmlspecialchars($_SESSION['usuario']['nombre']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Panel de Empleado</title>
  <!-- Asegúrate de que esta ruta apunte a tu CSS -->
  <link rel="stylesheet" href="estilos.css">
</head>
<body>
  <div class="container">
    <div class="card">
      <h1>Bienvenido, <?= $nombre ?></h1>
      <nav class="menu-panel">
        <a class="btn-azul"    href="productos.php">Gestionar Productos</a>
        <a class="btn-verde"   href="registrar_venta.php">Registrar Venta</a>
        <a class="btn-amarillo" href="ventas.php">Ver Ventas</a>
        <a class="btn-rojo"    href="logout.php">Cerrar Sesión</a>
      </nav>
    </div>
  </div>
</body>
</html>
