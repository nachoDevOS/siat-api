/**
 * Utilidades de texto compartidas por las guias y la referencia.
 */

/** 'Autenticación y cabeceras' -> 'autenticacion-y-cabeceras' */
export function slug(texto) {
    return String(texto)
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[`*_]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/** Sin tildes y en minusculas, para buscar. */
export function normalizar(texto) {
    return String(texto).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/**
 * Titulo limpio de un encabezado de API.md: sin numeracion ("2.5 "), sin
 * acentos graves y con los guiones largos pasados a dos puntos.
 */
export function tituloLimpio(texto) {
    return String(texto)
        .replace(/^\d+(\.\d+)*\.?\s+/, '')
        .replace(/`/g, '')
        .replace(/\s+[—–]\s+/g, ': ')
        .trim();
}

/** Texto plano de un bloque Markdown, para el buscador. */
export function textoPlano(markdown) {
    return String(markdown)
        .replace(/```[\s\S]*?```/g, ' ')
        .replace(/[#>*_`|[\]()-]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}
