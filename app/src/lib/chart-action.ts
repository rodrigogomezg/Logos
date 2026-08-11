import { Chart, type ChartConfiguration } from 'chart.js/auto';

// Action Svelte para montar/actualizar/destruir un gráfico Chart.js sobre un
// <canvas>. Reemplaza el patrón legacy de `charts['id'] = new Chart(...)` +
// `destroyCharts()` manual — acá el ciclo de vida del chart queda atado al
// del propio nodo DOM (se recrea si el config cambia, se destruye solo al
// desmontar), sin que la página tenga que llevar un registro global.
export function chartjs(node: HTMLCanvasElement, config: ChartConfiguration) {
	let chart = new Chart(node, config);
	return {
		update(newConfig: ChartConfiguration) {
			chart.destroy();
			chart = new Chart(node, newConfig);
		},
		destroy() {
			chart.destroy();
		}
	};
}

export const PALETA = ['#163B66', '#27AE60', '#E74C3C', '#F39C12', '#9B59B6', '#1ABC9C', '#E67E22', '#2980B9', '#C0392B', '#16A085'];

export function colorSet(n: number): string[] {
	return Array.from({ length: n }, (_, i) => PALETA[i % PALETA.length]);
}
