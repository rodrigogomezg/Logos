import adapter from '@sveltejs/adapter-static';
import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vite';

export default defineConfig({
	plugins: [
		sveltekit({
			compilerOptions: {
				// Force runes mode for the project, except for libraries. Can be removed in svelte 6.
				runes: ({ filename }) =>
					filename.split(/[/\\]/).includes('node_modules') ? undefined : true
			},

			// adapter-static: el build final tiene que poder servirse como archivos
			// estáticos desde el mismo PHP (o, más adelante, desde Tauri) — no hay
			// runtime Node en producción. fallback:'index.html' habilita modo SPA
			// (routing 100% del lado del cliente), necesario porque todos los datos
			// vienen de la API PHP en runtime, no de load functions de SvelteKit.
			adapter: adapter({
				pages: 'build',
				assets: 'build',
				fallback: 'index.html',
				precompress: false,
				strict: true
			})
		})
	],
	preview: {
		proxy: {
			'/Logos/api': { target: 'http://localhost', changeOrigin: true },
			'/Logos/pos/neo.css': { target: 'http://localhost', changeOrigin: true },
			'/Logos/pos/pos-base.css': { target: 'http://localhost', changeOrigin: true },
			'/Logos/pos/pos-base-core.css': { target: 'http://localhost', changeOrigin: true },
			'/Logos/pos/fonts': { target: 'http://localhost', changeOrigin: true },
			'/Logos/logos_logo.png': { target: 'http://localhost', changeOrigin: true }
		}
	},
	server: {
		proxy: {
			// Fase 1A (POC): en dev, todo /Logos/api/* pega al backend PHP real
			// que ya corre en XAMPP (mismo entorno que usa el resto del proyecto
			// hoy — ver CLAUDE.md). Evita CORS sin tener que tocar nada del lado
			// de PHP para este spike.
			'/Logos/api': {
				target: 'http://localhost',
				changeOrigin: true
			},
			// Reutilizar el sistema de diseño existente (neo.css/pos-base.css) y el
			// logo tal cual, en vez de duplicarlos acá — evita que la copia del POC
			// se desalinee visualmente de las páginas legacy con las que convive.
			'/Logos/pos/neo.css': { target: 'http://localhost', changeOrigin: true },
			'/Logos/pos/pos-base.css': { target: 'http://localhost', changeOrigin: true },
			'/Logos/pos/pos-base-core.css': { target: 'http://localhost', changeOrigin: true },
			'/Logos/pos/fonts': { target: 'http://localhost', changeOrigin: true },
			'/Logos/logos_logo.png': { target: 'http://localhost', changeOrigin: true }
		}
	}
});
