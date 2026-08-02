<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

class LicenciaController {

    private const HUB_URL    = 'https://hub.drilogs.com.ar/api/v1/heartbeat.php';
    private const LIMITE_DIAS = 15;

    // ── Endpoints ────────────────────────────────────────────────────────────────

    /**
     * POST /api/licencia/verificar
     * Llamada del timer de Electron (sin sesión). Recibe {token, version_app}
     * en el body, llama al Hub y actualiza licencia_estado si tiene éxito.
     * Siempre devuelve el estado_efectivo calculado.
     */
    public function verificar(): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
            http_response_code(403);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $body        = json_decode(file_get_contents('php://input'), true) ?? [];
        $token       = trim((string)($body['token']       ?? ''));
        $version     = trim((string)($body['version_app'] ?? ''));
        $ipLocal     = trim((string)($body['ip_local']     ?? '')) ?: null;
        $puertoLocal = isset($body['puerto_local']) && is_numeric($body['puerto_local'])
                       ? (int)$body['puerto_local'] : null;

        $pdo = DB::get();

        $verificacionOk = false;
        $motivoFallo    = null;
        $clienteNombre  = null;
        $sucursalNombre = null;

        if ($token !== '') {
            $hub      = self::callHub($token, $version, $ipLocal, $puertoLocal);
            $httpCode = $hub['http_code'];
            $hubData  = $hub['data'];

            if ($hubData !== null) {
                // 200 OK con respuesta válida del Hub
                $verificacionOk = true;
                $clienteNombre  = $hubData['cliente_nombre'];
                $sucursalNombre = $hubData['sucursal_nombre'];

                $tz    = new DateTimeZone('America/Argentina/Buenos_Aires');
                $ahora = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');

                $pdo->prepare("
                    INSERT INTO licencia_estado
                        (id, estado_hub, dias_restantes_gracia, proximo_vencimiento, mensaje_hub,
                         fecha_ultima_verificacion_exitosa)
                    VALUES (1, :estado, :dias_gracia, :prox_vto, :mensaje, :fecha)
                    ON DUPLICATE KEY UPDATE
                        estado_hub                        = VALUES(estado_hub),
                        dias_restantes_gracia             = VALUES(dias_restantes_gracia),
                        proximo_vencimiento               = VALUES(proximo_vencimiento),
                        mensaje_hub                       = VALUES(mensaje_hub),
                        fecha_ultima_verificacion_exitosa = VALUES(fecha_ultima_verificacion_exitosa)
                ")->execute([
                    ':estado'      => $hubData['estado'],
                    ':dias_gracia' => $hubData['dias_restantes_gracia'],
                    ':prox_vto'    => $hubData['proximo_vencimiento'],
                    ':mensaje'     => $hubData['mensaje'],
                    ':fecha'       => $ahora,
                ]);
            } elseif ($httpCode === 401) {
                $motivoFallo = 'token_invalido';
            } else {
                // timeout, error de red, o cualquier otro código no-401
                $motivoFallo = 'sin_conexion';
            }
        }

        $row = self::getLicenciaRow($pdo);
        echo json_encode(
            array_merge(self::calcularEstadoEfectivo($row), [
                'verificacion_ok' => $verificacionOk,
                'motivo_fallo'    => $motivoFallo,
                'cliente_nombre'  => $clienteNombre,
                'sucursal_nombre' => $sucursalNombre,
            ]),
            JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * GET /api/licencia/estado
     * Solo lectura: devuelve el estado_efectivo actual sin llamar al Hub.
     * Usado por la UI de todas las instancias (Servidor y Cliente).
     */
    public function estado(): void {
        $row = self::getLicenciaRow(DB::get());
        echo json_encode(self::calcularEstadoEfectivo($row), JSON_UNESCAPED_UNICODE);
    }

    // ── API interna (para otros controllers) ─────────────────────────────────────

    /** Devuelve el estado efectivo actual sin llamada al Hub. */
    public static function getEstadoEfectivo(): array {
        return self::calcularEstadoEfectivo(self::getLicenciaRow(DB::get()));
    }

    // ── Helpers privados ─────────────────────────────────────────────────────────

    private static function getLicenciaRow(PDO $pdo): array {
        $stmt = $pdo->query("SELECT * FROM licencia_estado WHERE id = 1");
        return ($stmt && ($row = $stmt->fetch(PDO::FETCH_ASSOC))) ? $row : self::defaultRow();
    }

    private static function defaultRow(): array {
        return [
            'estado_hub'                        => 'al_dia',
            'dias_restantes_gracia'             => null,
            'proximo_vencimiento'               => null,
            'mensaje_hub'                       => null,
            'fecha_ultima_verificacion_exitosa' => null,
        ];
    }

    /**
     * Regla de los 15 días: si no hubo heartbeat exitoso en los últimos 15 días
     * (o nunca), el estado_efectivo es 'bloqueado' sin importar lo que diga el Hub.
     */
    private static function calcularEstadoEfectivo(array $row): array {
        $tz    = new DateTimeZone('America/Argentina/Buenos_Aires');
        $ahora = new DateTimeImmutable('now', $tz);

        $vencido = $row['fecha_ultima_verificacion_exitosa'] === null;
        if (!$vencido) {
            $ultima  = new DateTimeImmutable($row['fecha_ultima_verificacion_exitosa'], $tz);
            $vencido = ((int) $ahora->diff($ultima)->days) >= self::LIMITE_DIAS;
        }

        if ($vencido) {
            return [
                'estado_efectivo'                   => 'bloqueado',
                'modo_restringido'                  => true,
                'dias_restantes_gracia'             => null,
                'proximo_vencimiento'               => null,
                'mensaje'                           => 'Sistema limitado. No se pudo verificar la licencia hace más de 15 días. Contactate con Logos.',
                'fecha_ultima_verificacion_exitosa' => $row['fecha_ultima_verificacion_exitosa'],
            ];
        }

        return [
            'estado_efectivo'                   => $row['estado_hub'],
            'modo_restringido'                  => $row['estado_hub'] === 'bloqueado',
            'dias_restantes_gracia'             => $row['dias_restantes_gracia'],
            'proximo_vencimiento'               => $row['proximo_vencimiento'],
            'mensaje'                           => $row['mensaje_hub'],
            'fecha_ultima_verificacion_exitosa' => $row['fecha_ultima_verificacion_exitosa'],
        ];
    }

    /**
     * Llama al Hub con timeout de 8 segundos.
     * Devuelve siempre un array:
     *   ['http_code' => int|null, 'data' => array|null]
     *   http_code null  → error de conexión / timeout (sin respuesta HTTP)
     *   http_code int   → código HTTP recibido (p.ej. 401, 200)
     *   data non-null   → solo cuando http_code === 200 y JSON válido con 'estado'
     * Nunca lanza excepción.
     */
    private static function callHub(string $token, string $version, ?string $ipLocal = null, ?int $puertoLocal = null): array {
        $body = json_encode([
            'version_app'  => $version,
            'ip_local'     => $ipLocal,
            'puerto_local' => $puertoLocal,
        ]);
        $ctx  = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token,
                    'Content-Length: ' . strlen($body),
                ]),
                'content'       => $body,
                'timeout'       => 8,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents(self::HUB_URL, false, $ctx);
        if ($response === false) {
            return ['http_code' => null, 'data' => null];
        }

        preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0] ?? '', $m);
        $httpCode = (int)($m[1] ?? 0) ?: null; // 0 → null (cabecera no parseable)

        if ($httpCode !== 200) {
            return ['http_code' => $httpCode, 'data' => null];
        }

        $raw = json_decode($response, true);
        if (!is_array($raw) || !isset($raw['estado'])) {
            return ['http_code' => 200, 'data' => null];
        }

        $estadosValidos = ['al_dia', 'en_gracia', 'bloqueado'];
        return [
            'http_code' => 200,
            'data'      => [
                'estado'                => in_array($raw['estado'], $estadosValidos, true)
                                           ? $raw['estado'] : 'bloqueado',
                'dias_restantes_gracia' => isset($raw['dias_restantes_gracia'])
                                           ? (int)$raw['dias_restantes_gracia'] : null,
                'proximo_vencimiento'   => $raw['proximo_vencimiento'] ?? null,
                'mensaje'               => $raw['mensaje'] ?? null,
                'cliente_nombre'        => isset($raw['cliente_nombre'])
                                           ? (string)$raw['cliente_nombre'] : null,
                'sucursal_nombre'       => isset($raw['sucursal_nombre'])
                                           ? (string)$raw['sucursal_nombre'] : null,
            ],
        ];
    }
}
