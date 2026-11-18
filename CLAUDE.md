# Plataforma de medidores Zhongyi — Minkafab

Contexto para Claude Code. Viene de los chats del proyecto "Configuraciones medidores" en claude.ai.
Responde en español.

## Qué es este repo
Sitio web de monitoreo (energía, agua, gas) + receptores Node de los medidores inteligentes Zhongyi
(energía DTSZY06 y agua ultrasónicos ZYsmart) que reportan a nuestro servidor propio. Ver README.md.

- Web: Ubuntu + Apache2 + PHP 8 (sin frameworks) + PostgreSQL. Publicado en https://dev.minkafab.com/monitor/
  (Alias de Apache a `public/`, ver `deploy/`). Enlaces y redirecciones siempre relativos; `ruta_base` en config.
- Instalar/actualizar: `sudo bash deploy/instalar.sh --receptores` (idempotente).
- `src/ingesta.php` es la única puerta de entrada de datos: los receptores Node hacen POST a
  `public/api/ingest.php` (localhost + token) y reciben los comandos en cola en la respuesta.
- Acciones de comando genéricas: `conectar` (abrir válvula / reconectar relé), `cortar`, `consultar`, `ciclo`.
  Cada receptor traduce la acción a la trama de su protocolo.

## Servidor
- `dev.minkafab.com` (135.181.100.95), procesos con `pm2`.
- `zy-receiver.js` — medidores de energía, **TCP 10091**. Réplica opcional a Zhongyi por UDP:
  `pm2 start zy-receiver.js --name zy-receiver -- 10091 udp:58.215.245.18:20131`
  (`FORWARD_DOWNLINK=0` bloquea comandos de Zhongyi hacia el medidor). Está en `receivers/zy-receiver.js`
  e integrado con la web (reportes 0x12 → BD; comandos en cola → `0A ..`; respuestas `0A fn código 01` → confirmación
  por acción, porque no llevan FID).
- `receivers/zy-water-udp.js` — medidores de agua, **UDP 10091**, log en `zy-water.jsonl`. Convive con el TCP.

## Medidores de energía DTSZY06 (big-endian)
| Medidor | Comunicación | ID en tramas |
|---|---|---|
| 2026090100011 | 4G (EG800G), Claro | 8606280836067870 |
| 2026090100012 | WiFi 2,4 GHz | 88670403E1E080B0 |

- Login: `ZY-IOT` 01 01 + largo(2) + ID ASCII + CRC16/MODBUS (BE).
- Latido (solo WiFi): `ZY-IOT` 01 03 0000 CRC + ID 8 B.
- Reporte: ID(8) 12 tipo(02) paquete + energía 4 | congelado 4 | saldo 4 | FP 2 | alarma 4 | estado 1 |
  batería 2 | V 3×2 | I 3×2 | P 3×2 + checksum add8 + 01.
- Unidades: energía 0,01 kWh; V 0,01 V; I 0,001 A; P ~0,001 W (2 B, revisar con cargas > 65 W).
- Bajada: 0A + función + datos + 01. Relé `0A 01 01/00 01`; ciclo `0A 02 <seg 4 B> 01`; lectura `0A 04 00 01`.
- El 4G no envía latidos y cae a ~60 s: los comandos van en cola y se envían al reconectar.

## Medidores de agua ZYsmart (little-endian)
| Medidor | Comunicación | ID |
|---|---|---|
| 20260815000001 | WiFi, DN15, válvula | IMEI 040000003409060 |
| 20260815000002 | 4G, DN20, válvula | IMEI 860628083621547 |
| 20260815000003 | LoRaWAN US915, DN25 | DevEUI 844C003201484BA4 |

- Trama de lectura (31 B): ID 8 B (IMEI+'0') | CMD 02 | nº medidor 7 B | acumulado 4 | recarga 4 |
  estado 1 | alarma 1 | batería 2 | RSSI | SNR | FID. Argumento real = 21 B (el manual dice 13).
- Ejemplo: `0400000034090600 02 01000015082620 00000000 50c30000 00 20 5e01 00 00 00`
  → 0,000 m³, recarga 50,000 m³, válvula abierta, alarma 0x20 (sensor de flujo), batería 3,50 V.
- Bajada (manual): 02 FID consulta; 04 55/99 FID válvula abrir/cerrar; 05 flujo; 06 ciclo; 0E hora.
  Implementado en `tramaBajada()`; `WATER_DOWNLINK_ID=1` antepone el ID de 8 B.

## Pendientes
- Confirmar con Zhongyi: si la bajada de agua lleva prefijo IMEI+0 y si el medidor espera ACK
  (reporta cada ~10 s, posible reenvío por falta de respuesta).
- Confirmar formato UDP esperado por Zhongyi y alta de medidores en en.energy.zhongyismart.com.
- Energía: en banco solo llega L3 (probable Err-002); bits de alarma/estado; escala de potencia.
  Mientras `estado_hex`/`alarma_hex` no estén descifrados, la web no los interpreta: el estado del relé mostrado
  es el del último comando de corte confirmado, y no se generan alarmas de energía.
- Gas: sin medidores ni protocolo todavía (el tipo ya existe en la BD y en la web).
- LoRaWAN (agua DN25): falta integración con el network server.

## Convenciones
- PHP sin dependencias; consultas siempre con parámetros (`q()`), salida con `h()`, formularios con `csrf_campo()`.
- Node.js, sin dependencias salvo que sea necesario.
- Probar cada decodificador con las tramas reales de arriba antes de desplegar.
