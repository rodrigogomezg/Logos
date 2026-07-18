<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Auth.php';

class ReservasController {

    private const DURACION_MINUTOS = 20;

    /**
     * POST /reservas
     * Sincroniza las reservas de un carrito: recibe el estado completo del carrito
     * y reemplaza atómicamente todas las reservas de ese carrito.
     *
     * Body: { carrito_id: "uuid", items: [{producto_id, cantidad, deposito_id?}] }
     * Devuelve: { ok: true, bloqueados: [{producto_id, disponible, pedido}] }
     *   (bloqueados = ítems donde disponible < pedido, para avisar en la UI)
     */
    public function sincronizar(): void {
        $body       = json_decode(file_get_contents('php://input'), true) ?? [];
        $carrito_id = trim($body['carrito_id'] ?? '');
        $items      = $body['items'] ?? [];

        if ($carrito_id === '' || strlen($carrito_id) > 36) {
            json(400, ['error' => 'carrito_id requerido (max 36 chars)']);
        }

        $db = DB::get();
        // Limpiar reservas vencidas de paso (lazy cleanup)
        $db->exec("DELETE FROM stock_reservas WHERE vence_en < NOW()");

        $deposito_id = (int)($body['deposito_id'] ?? 1);
        $vence_en    = date('Y-m-d H:i:s', strtotime('+' . self::DURACION_MINUTOS . ' minutes'));

        $bloqueados = [];

        $db->beginTransaction();
        try {
            // Borrar reservas actuales de este carrito (reemplazar limpiamente)
            $db->prepare("DELETE FROM stock_reservas WHERE carrito_id = ?")->execute([$carrito_id]);

            foreach ($items as $item) {
                $prod_id = (int)($item['producto_id'] ?? 0);
                $cant    = (float)($item['cantidad']    ?? 0);
                if ($prod_id <= 0 || $cant <= 0) continue;

                // Stock disponible para este carrito = stock_actual − reservas de OTROS carritos
                $stmtDisp = $db->prepare("
                    SELECT p.stock_actual - COALESCE((
                        SELECT SUM(sr.cantidad)
                        FROM stock_reservas sr
                        WHERE sr.producto_id = p.id
                          AND sr.carrito_id  != ?
                          AND sr.vence_en    > NOW()
                    ), 0) AS disponible
                    FROM productos p WHERE p.id = ?
                ");
                $stmtDisp->execute([$carrito_id, $prod_id]);
                $disponible = (float)($stmtDisp->fetchColumn() ?? 0);

                // Reservar lo que haya disponible (no bloqueamos la reserva, pero avisamos)
                $cant_reservar = min($cant, max(0, $disponible));
                if ($cant_reservar > 0) {
                    $db->prepare("
                        INSERT INTO stock_reservas (producto_id, deposito_id, cantidad, carrito_id, vence_en)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE cantidad = VALUES(cantidad), vence_en = VALUES(vence_en)
                    ")->execute([$prod_id, $deposito_id, $cant_reservar, $carrito_id, $vence_en]);
                }

                if ($cant > $disponible + 0.001) {
                    $bloqueados[] = [
                        'producto_id' => $prod_id,
                        'disponible'  => max(0, $disponible),
                        'pedido'      => $cant,
                    ];
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            json(500, ['error' => $e->getMessage()]);
        }

        json(200, ['ok' => true, 'bloqueados' => $bloqueados]);
    }

    /**
     * DELETE /reservas/:carrito_id
     * Libera todas las reservas de un carrito (al confirmar venta o vaciar carrito).
     */
    public function liberar(string $carrito_id): void {
        if ($carrito_id === '') json(400, ['error' => 'carrito_id requerido']);
        DB::get()->prepare("DELETE FROM stock_reservas WHERE carrito_id = ?")->execute([$carrito_id]);
        json(200, ['ok' => true]);
    }

    /**
     * Uso interno: liberar reservas de un carrito dentro de una transacción ya abierta.
     */
    public static function liberarEnTransaccion(PDO $db, string $carrito_id): void {
        if ($carrito_id === '') return;
        $db->prepare("DELETE FROM stock_reservas WHERE carrito_id = ?")->execute([$carrito_id]);
    }
}
