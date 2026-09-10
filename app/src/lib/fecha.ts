/**
 * Formatea una fecha "YYYY-MM-DD" (columna DATE de MySQL, sin hora) a "DD/MM".
 *
 * A propósito NO pasa por `new Date(...)`: ese string lo interpreta el motor
 * JS como medianoche UTC, y formatearlo después con la zona horaria local
 * del navegador (Argentina, UTC-3) lo corre un día para atrás — bug real
 * reportado por un cliente (ventas del 05/09 se mostraban como 04/09 en
 * Operaciones, mientras Ventas, que ya usaba este mismo slicing, las
 * mostraba bien). Slicing de string puro es inmune al timezone.
 */
export function fmtFechaCorta(s: string | null | undefined): string {
	return s ? s.slice(8, 10) + '/' + s.slice(5, 7) : '—';
}

/** Igual que fmtFechaCorta pero con año: "YYYY-MM-DD" → "DD/MM/YYYY". */
export function fmtFecha(s: string | null | undefined): string {
	return s ? `${s.slice(8, 10)}/${s.slice(5, 7)}/${s.slice(0, 4)}` : '—';
}
