<?php
declare(strict_types=1);

function cabecera(string $titulo, string $activo = ''): void
{
    $u = usuario();
    $nAlarmas = $u ? (int) q('SELECT count(*) FROM alarmas a JOIN medidores m ON m.id = a.medidor_id
                              WHERE a.fin IS NULL AND m.activo')->fetchColumn() : 0;
    $sitio = config('nombre_sitio', 'Monitor');
    $nav = [
        'index.php'     => ['Panel', 'lector'],
        'alarmas.php'   => ['Alarmas', 'lector'],
        'comandos.php'  => ['Comandos', 'operador'],
        'medidores.php' => ['Medidores', 'admin'],
        'usuarios.php'  => ['Usuarios', 'admin'],
    ];
    ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titulo) ?> · <?= h($sitio) ?></title>
<link rel="stylesheet" href="assets/app.css?v=1">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
</head>
<body>
<header class="barra">
  <a class="marca" href="index.php"><span class="logo">⚡💧🔥</span> <?= h($sitio) ?></a>
  <?php if ($u): ?>
  <nav>
    <?php foreach ($nav as $url => [$txt, $rol]): if (!puede($rol)) continue; ?>
      <a href="<?= $url ?>" class="<?= $activo === $url ? 'activo' : '' ?>"><?= h($txt) ?>
        <?php if ($url === 'alarmas.php' && $nAlarmas): ?><span class="insignia"><?= $nAlarmas ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <form method="post" action="logout.php" class="salir">
    <?= csrf_campo() ?>
    <span class="quien"><?= h($u['nombre'] ?: $u['usuario']) ?> · <?= h($u['rol']) ?></span>
    <button type="submit" class="btn-link">Salir</button>
  </form>
  <?php endif; ?>
</header>
<main>
<?php if ($f = flash()): ?>
  <div class="aviso <?= h($f[1]) ?>"><?= h($f[0]) ?></div>
<?php endif;
}

function pie(array $scripts = []): void
{
    ?>
</main>
<?php foreach ($scripts as $s): ?>
<script src="<?= h($s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

function chip_estado(?string $ts): string
{
    return en_linea($ts)
        ? '<span class="chip ok">● En línea</span>'
        : '<span class="chip apagado">● Sin conexión</span>';
}

function chip_corte(?string $c): string
{
    if ($c === null) return '';
    $cls = in_array($c, ['abierta', 'con_servicio'], true) ? 'ok' : ($c === 'anormal' ? 'mal' : 'aviso');
    return '<span class="chip ' . $cls . '">' . h(nombre_corte($c)) . '</span>';
}

/** Etiqueta de las acciones de corte según el tipo de medidor. */
function nombre_accion(string $accion, string $tipo): string
{
    return match ($accion) {
        'conectar'  => $tipo === 'energia' ? 'Reconectar servicio' : 'Abrir válvula',
        'cortar'    => $tipo === 'energia' ? 'Cortar servicio' : 'Cerrar válvula',
        'consultar' => 'Solicitar lectura',
        'ciclo'     => 'Cambiar ciclo de reporte',
        default     => $accion,
    };
}
