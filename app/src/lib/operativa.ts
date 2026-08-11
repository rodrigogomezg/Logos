// Port de las funciones cajaOperativaId/sucursalActivaId/depositoActivoId/
// tieneMultiSucursal de pos/auth.js. Mismo contrato sessionStorage (por
// pestaña, no por localStorage) para que conviva sin fricción con páginas
// legacy todavía no migradas que leen/escriben las mismas claves.
import { leerSesion, puede, type Sesion } from './session';

const CLAVE_CAJA_OV = 'logos_caja_override';
const CLAVE_SUCURSAL_OV = 'logos_sucursal_override';

export function cajaOperativaId(): number | null {
	const s = leerSesion();
	if (!s) return null;
	if (!puede('cajas_todas')) return s.caja_id;
	const ov = sessionStorage.getItem(CLAVE_CAJA_OV);
	return ov ? Number(ov) : s.caja_id;
}

export function sucursalActivaId(): number | null {
	const s = leerSesion();
	if (!s) return null;
	const ov = sessionStorage.getItem(CLAVE_SUCURSAL_OV);
	return ov ? Number(ov) : (s.sucursal_id || 1);
}

export function depositoActivoId(): number | null {
	const s = leerSesion();
	return s ? s.deposito_id || 1 : null;
}

export function tieneMultiSucursal(s: Sesion | null): boolean {
	return Array.isArray(s?.sucursales) && s.sucursales.length > 1;
}

// Igual que pos/auth.js: al cambiar de sucursal desde el selector, se
// despacha el mismo CustomEvent en window — páginas legacy conviviendo en la
// misma sesión/pestaña (poco común pero posible durante la migración) siguen
// reaccionando igual que hoy.
export function setSucursalActiva(sucursalId: number): void {
	sessionStorage.setItem(CLAVE_SUCURSAL_OV, String(sucursalId));
	window.dispatchEvent(new CustomEvent('sucursal-changed', { detail: { sucursal_id: sucursalId } }));
}

export function setCajaOperativa(cajaId: number, cajaIdDeLogin: number): void {
	if (cajaId === cajaIdDeLogin) {
		sessionStorage.removeItem(CLAVE_CAJA_OV);
	} else {
		sessionStorage.setItem(CLAVE_CAJA_OV, String(cajaId));
	}
	window.dispatchEvent(new CustomEvent('caja-changed', { detail: { caja_id: cajaId } }));
}
