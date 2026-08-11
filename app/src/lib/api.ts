import { writable } from 'svelte/store';
import { leerSesion, limpiarSesion, nombreDispositivo } from './session';

// Mismo valor resuelto que window.API en pos/config.js (la ruta base de este
// deploy es fija — /Logos —, no hace falta la detección dinámica que hace
// config.js para las páginas legacy).
export const API_BASE = '/Logos/api';

export function apiUrl(path: string): string {
	return `${API_BASE}${path.startsWith('/') ? path : '/' + path}`;
}

// Igual que el contador _netFails de pos/auth.js: un solo fallo de red puede
// ser un glitch, pero 2 seguidos sin ninguna respuesta HTTP exitosa de por
// medio es "el servidor no responde" de verdad — mostrar el overlay de
// pantalla completa (montado una vez en (app)/+layout.svelte) en vez de
// dejar que cada fetch individual falle en silencio. Caso real ya visto en
// este proyecto (ver CLAUDE.md, incidente QUIC): sin esto, un cliente puede
// quedarse mirando una pantalla que "no hace nada" sin ninguna pista de que
// el servidor se cayó.
export const servidorCaido = writable(false);
let netFails = 0;

/**
 * fetch autenticado: agrega X-Auth-Token (+ X-Device-Name si el equipo ya
 * tiene nombre asignado) y redirige a /login si el server contesta 401 —
 * mismo comportamiento que el monkeypatch de window.fetch en pos/auth.js.
 * Usar esto para cualquier llamada que requiera sesión; para las públicas
 * (login, estado de instalación) usar fetch(apiUrl(...)) directo.
 */
export async function api(path: string, init: RequestInit = {}): Promise<Response> {
	const sesion = leerSesion();
	const headers = new Headers(init.headers);
	if (sesion) headers.set('X-Auth-Token', sesion.token);
	const dev = nombreDispositivo();
	if (dev) headers.set('X-Device-Name', dev);

	let res: Response;
	try {
		res = await fetch(apiUrl(path), { ...init, headers });
	} catch (err) {
		if (++netFails >= 2) servidorCaido.set(true);
		throw err;
	}
	netFails = 0;
	servidorCaido.set(false);
	if (res.status === 401) {
		limpiarSesion();
		window.location.href = '/login';
	}
	return res;
}

/** Igual que api() pero devuelve el JSON parseado directo. */
export async function apiJson<T = unknown>(path: string, init: RequestInit = {}): Promise<T> {
	const res = await api(path, init);
	return res.json() as Promise<T>;
}
