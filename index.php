<?php
session_start();
if (isset($_SESSION["rol"])) {
    if ($_SESSION["rol"] === "admin") {
        header("Location: admin.php");
    } else {
        header("Location: empleado.php");
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inicio - Sistema Agrícola</title>
    <link rel="stylesheet" href="estilos.css">
</head>
<body>
<div style="max-width: 600px; margin: auto;">
    <h1>🌱 Bienvenido al Sistema Agrícola</h1>
    <p>Por favor, inicia sesión para continuar.</p>
    <a href="login.php">
        <button>Iniciar Sesión</button>
    </a>
</div>
</body>
</html>
