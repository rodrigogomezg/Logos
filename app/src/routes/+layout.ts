// SPA pura: todos los datos vienen de la API PHP en runtime, no tiene sentido
// que SvelteKit intente renderizar en servidor ni precargar en build time.
export const ssr = false;
export const prerender = false;
