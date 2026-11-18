<?php
declare(strict_types=1);

/**
 * Procesa un reporte normalizado enviado por un receptor Node.
 *
 * Formato de entrada (todos opcionales salvo tipo y dispositivo_id):
 * {
 *   "tipo": "agua"|"energia"|"gas",
 *   "dispositivo_id": "040000003409060",   // IMEI / ID de trama
 *   "numero": "20260815000001",            // nº de medidor si viene en la trama
 *   "desde": "1.2.3.4:5678",
 *   "evento": "lectura"|"ack"|"otro",
 *   "valor": 12.345,                       // acumulado (kWh o m³)
 *   "corte": "abierta"|"cerrada"|"con_servicio"|"cortado"|"anormal",
 *   "bateria_v": 3.5, "rssi": -70,
 *   "alarmas": ["fuga", ...],              // lista completa de alarmas activas (null = no informa)
 *   "ack": {"ok": true, "cmd": "04", "fid": 3},
 *   "datos": {...},                        // todo lo decodificado
 *   "raw": "0400..."
 * }
 *
 * Devuelve ['medidor_id' => int, 'comandos' => [...pendientes entregados al receptor...]]
 */
function procesar_reporte(array $r): array
{
    $tipo = $r['tipo'] ?? '';
    $dev  = strtoupper(trim((string) ($r['dispositivo_id'] ?? '')));
    if (!isset(TIPOS[$tipo]) || $dev === '') {
        throw new InvalidArgumentException('tipo o dispositivo_id inválido');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $m = obtener_o_crear_medidor($tipo, $dev, isset($r['numero']) ? (string) $r['numero'] : null);
        $evento = $r['evento'] ?? 'lectura';
        $datos  = is_array($r['datos'] ?? null) ? $r['datos'] : [];

        // Snapshot: se fusiona con el último dato para no perder campos en tramas parciales.
        $snap = array_merge(json_decode($m['ultimo_dato'] ?? '{}', true) ?: [], array_filter([
            'valor'     => $r['valor'] ?? null,
            'corte'     => $r['corte'] ?? null,
            'bateria_v' => $r['bateria_v'] ?? null,
            'rssi'      => $r['rssi'] ?? null,
            'alarmas'   => $r['alarmas'] ?? null,
        ], fn($v) => $v !== null), $evento === 'lectura' ? ['datos' => $datos, 'ts' => date('c')] : []);

        q('UPDATE medidores SET ultima_conexion = now(), ultima_ip = ?, ultimo_dato = ?::jsonb WHERE id = ?',
          [$r['desde'] ?? null, json_encode($snap, JSON_UNESCAPED_UNICODE), $m['id']]);

        if ($evento === 'lectura' && $m['activo']) {
            guardar_lectura($m['id'], $r, $datos);
        }
        if (is_array($r['alarmas'] ?? null)) {
            sincronizar_alarmas($m['id'], $r['alarmas']);
        }
        if ($evento === 'ack' && is_array($r['ack'] ?? null)) {
            registrar_ack($m['id'], $r['ack']);
        }
        if (isset($r['corte'])) {
            confirmar_por_estado($m['id'], (string) $r['corte']);
        }

        // Entregar comandos pendientes (se marcan como enviados; el receptor informa la trama luego).
        $cmds = q("UPDATE comandos SET estado = 'enviado', enviado = now()
                   WHERE id IN (SELECT id FROM comandos WHERE medidor_id = ? AND estado = 'pendiente'
                                ORDER BY id FOR UPDATE SKIP LOCKED)
                   RETURNING id, accion, parametro", [$m['id']])->fetchAll();

        $pdo->commit();
        return ['medidor_id' => (int) $m['id'], 'comandos' => $cmds];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function obtener_o_crear_medidor(string $tipo, string $dev, ?string $numero): array
{
    $m = q('SELECT id, activo, ultimo_dato FROM medidores WHERE dispositivo_id = ?', [$dev])->fetch();
    if ($m) return $m;
    if ($numero) {
        $m = q('SELECT id, activo, ultimo_dato, dispositivo_id FROM medidores WHERE numero = ?', [$numero])->fetch();
        if ($m && $m['dispositivo_id'] === null) {
            q('UPDATE medidores SET dispositivo_id = ? WHERE id = ?', [$dev, $m['id']]);
            return $m;
        }
        if ($m) $numero = null; // nº ya usado por otro dispositivo: usar el ID como nº
    }
    return q("INSERT INTO medidores (tipo, numero, dispositivo_id, nombre)
              VALUES (?, ?, ?, 'Nuevo (alta automática)') RETURNING id, activo, ultimo_dato",
             [$tipo, $numero ?: $dev, $dev])->fetch();
}

function guardar_lectura(int $mid, array $r, array $datos): void
{
    $ult = q('SELECT ts, valor, corte, datos->\'alarmas\' AS alarmas FROM lecturas
              WHERE medidor_id = ? ORDER BY ts DESC LIMIT 1', [$mid])->fetch();
    $valor = isset($r['valor']) ? round((float) $r['valor'], 3) : null;
    $corte = $r['corte'] ?? null;
    $alarmas = isset($r['alarmas']) ? json_encode(array_values($r['alarmas'])) : null;
    $guardar = !$ult
        || time() - strtotime($ult['ts']) >= (int) config('intervalo_historico_seg', 300)
        || ($valor !== null && (float) $ult['valor'] !== $valor)
        || ($corte !== null && $ult['corte'] !== $corte)
        || ($alarmas !== null && json_encode(json_decode((string) $ult['alarmas'])) !== $alarmas);
    if (!$guardar) return;

    if (isset($r['alarmas'])) $datos['alarmas'] = array_values($r['alarmas']);
    q('INSERT INTO lecturas (medidor_id, valor, corte, bateria_v, rssi, datos, raw)
       VALUES (?, ?, ?, ?, ?, ?::jsonb, ?)',
      [$mid, $valor, $corte, $r['bateria_v'] ?? null, isset($r['rssi']) ? (int) $r['rssi'] : null,
       json_encode($datos, JSON_UNESCAPED_UNICODE), $r['raw'] ?? null]);
}

function sincronizar_alarmas(int $mid, array $activas): void
{
    $activas = array_values(array_unique(array_map('strval', $activas)));
    $abiertas = q('SELECT codigo FROM alarmas WHERE medidor_id = ? AND fin IS NULL', [$mid])
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_diff($activas, $abiertas) as $c) {
        q('INSERT INTO alarmas (medidor_id, codigo) VALUES (?, ?)', [$mid, $c]);
    }
    foreach (array_diff($abiertas, $activas) as $c) {
        q('UPDATE alarmas SET fin = now() WHERE medidor_id = ? AND codigo = ? AND fin IS NULL', [$mid, $c]);
    }
}

function registrar_ack(int $mid, array $ack): void
{
    $fid = isset($ack['fid']) ? (int) $ack['fid'] : null;
    if ($fid === null) return;
    $ok = !empty($ack['ok']);
    q("UPDATE comandos SET estado = ?, confirmado = now(), detalle = ?
       WHERE id = (SELECT id FROM comandos WHERE medidor_id = ? AND fid = ? AND estado = 'enviado'
                   ORDER BY id DESC LIMIT 1)",
      [$ok ? 'confirmado' : 'error', $ok ? 'ACK OK' : 'ACK ERROR', $mid, $fid]);
}

/** Si el medidor ya reporta el estado pedido, el comando de corte se da por confirmado. */
function confirmar_por_estado(int $mid, string $corte): void
{
    $accion = match ($corte) {
        'abierta', 'con_servicio' => 'conectar',
        'cerrada', 'cortado'      => 'cortar',
        default                   => null,
    };
    if (!$accion) return;
    q("UPDATE comandos SET estado = 'confirmado', confirmado = now(),
              detalle = coalesce(detalle, 'estado reportado por el medidor')
       WHERE medidor_id = ? AND accion = ? AND estado = 'enviado'", [$mid, $accion]);
}

/** El receptor informa la trama efectivamente transmitida (o un error) para un comando entregado. */
function registrar_envio(array $e): void
{
    $id = (int) ($e['id'] ?? 0);
    if (!empty($e['error'])) {
        q("UPDATE comandos SET estado = 'error', detalle = ? WHERE id = ?", [(string) $e['error'], $id]);
        return;
    }
    q('UPDATE comandos SET fid = ?, trama_hex = ? WHERE id = ?',
      [isset($e['fid']) ? (int) $e['fid'] : null, $e['trama_hex'] ?? null, $id]);
}
