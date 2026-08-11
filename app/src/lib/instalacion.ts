import { apiJson } from './api';

export type InfoInstalacion = {
	role: string | null;
	server_port: number | null;
	server_ip: string | null;
};

let cache: Promise<InfoInstalacion> | null = null;

/**
 * Info de bajo nivel de esta instalación (rol Servidor/Cliente), vía
 * GET /instalacion/info — reemplaza a window.logos.getConfig(), que solo
 * existe bajo Electron. Cacheado en memoria: no cambia durante la sesión.
 */
export function obtenerInfoInstalacion(): Promise<InfoInstalacion> {
	if (!cache) {
		cache = apiJson<InfoInstalacion>('/instalacion/info').catch(() => ({
			role: null,
			server_port: null,
			server_ip: null
		}));
	}
	return cache;
}

/** true salvo que la instalación esté explícitamente marcada como rol Cliente. */
export async function esRolServidor(): Promise<boolean> {
	const info = await obtenerInfoInstalacion();
	return info.role !== 'client';
}
