// Helper genérico para leer/escribir JSON en localStorage — centraliza el
// try/catch que se repetía suelto en varios lugares (session.ts, algunas
// páginas de la SPA), para que ningún call site nuevo se olvide de manejar
// un JSON corrupto, una clave ausente, o localStorage lleno/deshabilitado.

export function leerJSON<T>(clave: string): T | null {
	try {
		const raw = localStorage.getItem(clave);
		return raw ? (JSON.parse(raw) as T) : null;
	} catch {
		return null;
	}
}

export function guardarJSON(clave: string, valor: unknown): void {
	try {
		localStorage.setItem(clave, JSON.stringify(valor));
	} catch {
		// localStorage lleno o deshabilitado (modo privado, cuota) — no bloquear
		// la operación principal por esto.
	}
}
