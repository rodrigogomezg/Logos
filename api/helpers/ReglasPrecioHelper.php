<?php

class ReglasPrecioHelper {

    /**
     * Resuelve qué regla aplica a cada producto, con prioridad:
     * regla propia del producto > marca > rubro > proveedor (COALESCE, en ese
     * orden). marca/rubro/proveedor se matchean por nombre contra los campos
     * libres de productos — no hay FK real ahí, es texto.
     * precio_venta = costo_actual (neto) × (1 + IVA/100) × (1 + recargo/100).
     * Solo toca productos donde ALGUNA regla resolvió (r.id IS NOT NULL) —
     * un producto sin ninguna regla aplicable no se toca nunca.
     */
    private const BASE_UPDATE = "
        UPDATE productos p
        LEFT JOIN marcas      ma ON ma.nombre COLLATE utf8mb4_unicode_ci = p.marca
        LEFT JOIN rubros      ru ON ru.nombre COLLATE utf8mb4_unicode_ci = p.categoria
        LEFT JOIN proveedores pv ON pv.nombre COLLATE utf8mb4_unicode_ci = p.proveedor
        LEFT JOIN reglas_precio r
               ON r.id = COALESCE(p.regla_precio_id, ma.regla_precio_id, ru.regla_precio_id, pv.regla_precio_id)
        SET p.precio_venta = ROUND(p.costo_actual * (1 + p.iva_porcentaje / 100) * (1 + r.porcentaje_recargo / 100), 2)
        WHERE r.id IS NOT NULL
    ";

    /** Columnas de productos válidas para recalcularPorGrupo() — whitelist, va directo al SQL. */
    private const CAMPOS_GRUPO = ['marca', 'categoria', 'proveedor'];

    /**
     * Recalcula un producto puntual. No-op si ninguna regla le aplica (directa
     * o heredada) — seguro de llamar siempre tras tocar costo_actual,
     * iva_porcentaje, regla_precio_id, marca, categoria o proveedor.
     */
    public static function recalcularPrecio(PDO $db, int $productoId): void {
        $db->prepare(self::BASE_UPDATE . " AND p.id = ?")->execute([$productoId]);
    }

    /** Igual que recalcularPrecio() pero para todos los productos que matcheen un WHERE/params dado. */
    public static function recalcularPorFiltro(PDO $db, string $whereStr, array $whereParams): void {
        $db->prepare(self::BASE_UPDATE . " AND ($whereStr)")->execute($whereParams);
    }

    /**
     * Recalcula todos los productos cuya regla EFECTIVA (ya resuelta con la
     * cascada de prioridad) sea esta — usado cuando cambia el % de una regla,
     * sin importar si el producto la tiene directa o heredada de su marca/
     * rubro/proveedor.
     */
    public static function recalcularPorRegla(PDO $db, int $reglaId): void {
        $db->prepare(self::BASE_UPDATE . " AND r.id = ?")->execute([$reglaId]);
    }

    /**
     * Recalcula todos los productos de una marca/rubro/proveedor puntual —
     * usado al asignar (o cambiar) la regla de ese grupo, para que el efecto
     * se vea al instante en los productos que ya existen.
     */
    public static function recalcularPorGrupo(PDO $db, string $campo, string $nombre): void {
        if (!in_array($campo, self::CAMPOS_GRUPO, true)) {
            throw new InvalidArgumentException("Campo inválido: $campo");
        }
        $db->prepare(self::BASE_UPDATE . " AND p.`$campo` COLLATE utf8mb4_unicode_ci = ?")->execute([$nombre]);
    }
}
