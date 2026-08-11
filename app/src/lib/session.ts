// Mismo contrato que pos/auth.js — el objeto que se guarda acá en
// localStorage bajo la clave "logos_sesion" tiene que tener EXACTAMENTE esta
// forma, byte a byte, para que las páginas legacy sin migrar (que leen este
// mismo storage) sigan autenticando durante la convivencia SvelteKit+MPA.
// Ver pos/login.html::iniciarSesion() para la fuente de verdad original.

export interface Sucursal {
	id: number;
	nombre: string;
	nombre_fantasia: string | null;
	deposito_principal_id: number | null;
}

export interface Sesion {
	usuario_id: number;
	nombre: string;
	rol: 'admin' | 'user';
	permisos: Record<string, boolean>;
	caja_id: number;
	caja_nombre: string;
	sucursales: Sucursal[];
	sucursal_id: number;
	deposito_id: number;
	token: string;
}

const CLAVE = 'logos_sesion';

export function leerSesion(): Sesion | null {
	try {
		const raw = localStorage.getItem(CLAVE);
		if (!raw) return null;
		const s = JSON.parse(raw) as Partial<Sesion>;
		if (!s.usuario_id || !s.caja_id || !s.token) return null;
		return s as Sesion;
	} catch {
		return null;
	}
}

export function guardarSesion(s: Sesion): void {
	localStorage.setItem(CLAVE, JSON.stringify(s));
}

export function limpiarSesion(): void {
	localStorage.removeItem(CLAVE);
}

// Igual que el logout de pos/auth.js: invalida la sesión en el servidor
// antes de limpiar la local. A diferencia del 401-handler de api.ts (que
// limpia sin avisar al backend porque el 401 YA significa "esta sesión no
// sirve más"), un logout explícito del usuario sí tiene que avisarle al
// servidor. Tolerante a fallos de red — igual limpia local y redirige.
export async function cerrarSesion(): Promise<void> {
	const s = leerSesion();
	if (s) {
		try {
			// API_BASE hardcodeado acá (no importado de api.ts) para evitar un
			// import circular — api.ts ya importa de este archivo.
			await fetch('/Logos/api/usuarios/logout', {
				method: 'POST',
				headers: { 'X-Auth-Token': s.token }
			});
		} catch {
			// sin conexión — no bloquear el logout local por esto
		}
	}
	limpiarSesion();
}

export function nombreDispositivo(): string | null {
	try {
		return localStorage.getItem('logos_device_name');
	} catch {
		return null;
	}
}

export function puede(permiso: string): boolean {
	const s = leerSesion();
	if (!s) return false;
	return s.rol === 'admin' || !!s.permisos?.[permiso];
}
