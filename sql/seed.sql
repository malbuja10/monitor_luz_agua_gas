-- Medidores de prueba conocidos (ver CLAUDE.md). Idempotente.
INSERT INTO medidores (tipo, numero, dispositivo_id, nombre, comunicacion, modelo, tiene_corte) VALUES
  ('energia', '2026090100011', '8606280836067870', 'Energía 4G',   '4G (EG800G) Claro', 'DTSZY06', true),
  ('energia', '2026090100012', '88670403E1E080B0', 'Energía WiFi', 'WiFi 2,4 GHz',      'DTSZY06', true),
  ('agua',    '20260815000001', '040000003409060', 'Agua WiFi DN15', 'WiFi',            'ZYsmart DN15', true),
  ('agua',    '20260815000002', '860628083621547', 'Agua 4G DN20',   '4G',              'ZYsmart DN20', true),
  ('agua',    '20260815000003', '844C003201484BA4', 'Agua LoRaWAN DN25', 'LoRaWAN US915', 'ZYsmart DN25', false)
ON CONFLICT (numero) DO NOTHING;
