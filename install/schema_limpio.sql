
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_movimientos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `turno_id` int(11) NOT NULL,
  `tipo` enum('ingreso','retiro','transferencia') NOT NULL,
  `medio_pago` enum('efectivo','transferencia','tarjeta') NOT NULL DEFAULT 'efectivo',
  `medio_pago_destino` enum('efectivo','transferencia','tarjeta') DEFAULT NULL,
  `monto` decimal(12,2) NOT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_turnos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `caja_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `fondo_inicial` decimal(12,2) NOT NULL DEFAULT 0.00,
  `abierto_en` datetime NOT NULL,
  `cerrado_en` datetime DEFAULT NULL,
  `estado` enum('abierto','cerrado') NOT NULL DEFAULT 'abierto',
  `total_efectivo` decimal(12,2) DEFAULT NULL,
  `total_tarjeta` decimal(12,2) DEFAULT NULL,
  `total_transferencia` decimal(12,2) DEFAULT NULL,
  `total_cheque` decimal(12,2) DEFAULT NULL,
  `total_cc` decimal(12,2) DEFAULT NULL,
  `total_ingresos` decimal(12,2) DEFAULT NULL,
  `total_retiros` decimal(12,2) DEFAULT NULL,
  `efectivo_esperado` decimal(12,2) DEFAULT NULL,
  `efectivo_contado` decimal(12,2) DEFAULT NULL,
  `diferencia` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cajas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `tipo` enum('venta','compra') NOT NULL DEFAULT 'venta',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `orden` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cc_asignaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `movimiento_id` int(11) NOT NULL,
  `venta_id` int(11) DEFAULT NULL,
  `monto` decimal(14,4) NOT NULL,
  `compra_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mov` (`movimiento_id`),
  KEY `idx_ven` (`venta_id`),
  KEY `fk_cca_com` (`compra_id`),
  CONSTRAINT `fk_cca_com` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cca_mov` FOREIGN KEY (`movimiento_id`) REFERENCES `cuenta_corriente_movimientos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cca_ven` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  `cuit` varchar(20) DEFAULT NULL,
  `condicion_iva` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `domicilio` varchar(255) DEFAULT NULL,
  `localidad` varchar(100) DEFAULT NULL,
  `provincia` varchar(100) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `limite_credito` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `saldo_cuenta_corriente` decimal(14,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  KEY `idx_nombre` (`nombre`),
  KEY `idx_cuit` (`cuit`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compra_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `compra_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `costo_unitario` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `iva_porcentaje` decimal(5,2) NOT NULL DEFAULT 21.00,
  `iva_monto` decimal(14,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  KEY `idx_compra` (`compra_id`),
  KEY `idx_producto` (`producto_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compra_pagos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `compra_id` int(11) NOT NULL,
  `tipo_pago` varchar(20) NOT NULL,
  `monto` decimal(14,4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_compra` (`compra_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compras` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `proveedor_id` int(11) DEFAULT NULL,
  `total` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `estado` varchar(20) NOT NULL DEFAULT 'completado',
  `caja_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `tipo_comprobante` varchar(20) DEFAULT NULL,
  `numero_comprobante` varchar(50) DEFAULT NULL,
  `subtotal` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `iva_monto` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `percepcion_iibb_porcentaje` decimal(5,2) DEFAULT NULL,
  `percepcion_iibb_monto` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `tipo_pago` varchar(20) NOT NULL DEFAULT 'efectivo',
  PRIMARY KEY (`id`),
  KEY `idx_fecha` (`fecha`),
  KEY `idx_proveedor` (`proveedor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `configuracion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `razon_social` varchar(255) NOT NULL DEFAULT '',
  `cuit` varchar(20) NOT NULL DEFAULT '',
  `condicion_iva` varchar(50) NOT NULL DEFAULT 'Responsable Inscripto',
  `domicilio` varchar(255) DEFAULT NULL,
  `iibb` varchar(50) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `punto_venta` int(11) NOT NULL DEFAULT 1,
  `iva_porcentaje` decimal(5,2) NOT NULL DEFAULT 21.00,
  `impresora_nombre` varchar(255) DEFAULT NULL,
  `carpeta_comprobantes` varchar(500) DEFAULT NULL,
  `carpeta_backups` varchar(500) DEFAULT NULL,
  `clave_autorizacion_hash` varchar(255) DEFAULT NULL,
  `actualizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cuenta_corriente_movimientos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entidad_tipo` enum('cliente','proveedor') NOT NULL,
  `entidad_id` int(11) NOT NULL,
  `tipo` varchar(20) NOT NULL,
  `monto` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `referencia_id` int(11) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `medio_pago` varchar(20) DEFAULT NULL,
  `pago_datos` text DEFAULT NULL,
  `comprobante` varchar(500) DEFAULT NULL,
  `fecha` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_entidad` (`entidad_tipo`,`entidad_id`),
  KEY `idx_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `movimientos_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `tipo` varchar(20) NOT NULL,
  `cantidad` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `referencia_id` int(11) DEFAULT NULL,
  `fecha` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_producto` (`producto_id`),
  KEY `idx_fecha` (`fecha`),
  KEY `idx_tipo` (`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `categoria` varchar(100) DEFAULT NULL,
  `subcategoria` varchar(100) DEFAULT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `proveedor` varchar(150) DEFAULT NULL,
  `precio_venta` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `costo_actual` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `stock_actual` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `stock_minimo` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `iva_porcentaje` decimal(5,2) NOT NULL DEFAULT 21.00,
  PRIMARY KEY (`id`),
  KEY `idx_codigo` (`codigo`),
  KEY `idx_nombre` (`nombre`),
  KEY `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productos_import_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lote_id` int(11) NOT NULL,
  `producto_id` int(11) DEFAULT NULL,
  `codigo` varchar(50) DEFAULT NULL,
  `accion` enum('crear','actualizar','error','desactivado') NOT NULL,
  `antes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`antes`)),
  `despues` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`despues`)),
  `mensaje` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lote` (`lote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productos_import_lotes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `proveedor` varchar(150) NOT NULL,
  `archivo` varchar(255) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `total_filas` int(11) NOT NULL DEFAULT 0,
  `creados` int(11) NOT NULL DEFAULT 0,
  `actualizados` int(11) NOT NULL DEFAULT 0,
  `errores` int(11) NOT NULL DEFAULT 0,
  `desactivados` int(11) NOT NULL DEFAULT 0,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productos_import_plantillas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `proveedor` varchar(150) NOT NULL,
  `mapeo` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`mapeo`)),
  `opciones` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`opciones`)),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_proveedor` (`proveedor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `proveedores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  `cuit` varchar(20) DEFAULT NULL,
  `condicion_iva` varchar(50) DEFAULT NULL,
  `saldo_cuenta_corriente` decimal(14,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  KEY `idx_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `pin_hash` varchar(255) NOT NULL,
  `rol` enum('admin','user') NOT NULL DEFAULT 'user',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vendedores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `venta_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `precio_unitario` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `precio_original` decimal(12,2) DEFAULT NULL,
  `ajuste_desc` varchar(30) DEFAULT NULL,
  `ajuste_visible` tinyint(1) NOT NULL DEFAULT 1,
  `costo_unitario` decimal(14,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  KEY `idx_venta` (`venta_id`),
  KEY `idx_producto` (`producto_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `venta_pagos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `tipo_pago` varchar(20) NOT NULL,
  `monto` decimal(14,4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_venta` (`venta_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ventas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `vendedor_id` int(11) DEFAULT NULL,
  `total` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `tipo_comprobante` varchar(50) DEFAULT NULL,
  `numero_afip` varchar(50) DEFAULT NULL,
  `cae` varchar(50) DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'completado',
  `tipo_pago` varchar(20) NOT NULL DEFAULT 'efectivo',
  `observaciones` varchar(255) DEFAULT NULL,
  `origen_descripcion` text DEFAULT NULL,
  `envio_precio` decimal(10,2) DEFAULT NULL,
  `envio_direccion` varchar(500) DEFAULT NULL,
  `caja_id` int(11) DEFAULT NULL,
  `turno_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fecha` (`fecha`),
  KEY `idx_cliente` (`cliente_id`),
  KEY `idx_numero_afip` (`numero_afip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

