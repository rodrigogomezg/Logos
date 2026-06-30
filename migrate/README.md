# Migración Professional Plus → BRON

## Orden de ejecución

### Paso 1 — Importar backup a XAMPP

El backup `.bak.g` es un mysqldump comprimido con gzip. Ya fue descomprimido en:
```
C:\Users\roloo\AppData\Local\Temp\bron_backup.sql  (~670 MB)
```

Importar en MySQL de XAMPP (desde Shell o phpMyAdmin):
```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS professionalplus CHARACTER SET latin1;"
mysql -u root professionalplus < "C:\Users\roloo\AppData\Local\Temp\bron_backup.sql"
```

Esto tarda ~10-15 minutos por el tamaño del dump.

### Paso 2 — Crear schema BRON

```bash
mysql -u root < migrate/00_schema_bron.sql
```

### Paso 3 — Ejecutar migraciones en orden

```bash
mysql -u root < migrate/01_migrate_productos.sql   # todos los productos activos
mysql -u root < migrate/02_migrate_clientes.sql    # solo clientes con ventas en 2026
mysql -u root < migrate/03_migrate_ventas.sql      # solo ventas 2026 + CAE AFIP
mysql -u root < migrate/04_migrate_venta_items.sql # ítems de esas ventas
mysql -u root < migrate/05_migrate_cc_clientes.sql # movimientos CC 2026
```

Cada script imprime un `resultado` al finalizar con la cantidad de registros.

---

## Criterios aplicados

| Tabla BRON            | Fuente ProfPlus          | Filtro                                      |
|-----------------------|--------------------------|---------------------------------------------|
| `productos`           | `stock`                  | `BORRADO=0, tipo=0` — catálogo completo      |
| `clientes`            | `clientes`               | Con al menos una venta en 2026               |
| `ventas`              | `ventas` + `ventas_electronicas` | `BORRADO=0, esventa=1, Fecha >= 46023` |
| `venta_items`         | `stock_movimiento`       | `tipo=0`, enlacepadreid en ventas 2026       |
| `cc_movimientos`      | `clientescc`             | `BORRADO=0`, cliente migrado, Fecha >= 46023 |

## Notas técnicas

- **Fecha serial Delphi**: `DATE_ADD('1899-12-30', INTERVAL Fecha DAY)` — 46023 = 2026-01-01
- **precio_unitario en venta_items**: precio de lista histórico obtenido de `precios_movimientos`
  (último registro `lp=0` antes de la `FechaADD` de cada venta). Si la venta tenía descuento
  general (`ventas.descuentopor`), los precios por ítem son de lista; el total del encabezado
  (`ventas.total`) sigue siendo exacto. Fallback: precio actual si no hay historial.
- **condicion_iva**: derivado del campo `IVA int` de `clientes` (1=RI, 5=CF, 6=Mono, etc.)
- **Encoding**: ProfPlus usa `latin1`. Las queries convierten con `CONVERT(... USING utf8mb4)`.
