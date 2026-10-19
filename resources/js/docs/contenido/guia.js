import fuente from '../../../../docs/integracion/API.md?raw';
import { slug, textoPlano, tituloLimpio } from './util.js';

/**
 * Parte docs/integracion/API.md (la guia que tambien se le entrega al
 * cliente) en paginas: una por cada seccion "## ". La seccion de endpoints
 * se reparte: las notas de cada "### `METODO /ruta`" se pegan a la pagina de
 * ese endpoint en la referencia, y el resto queda como guia propia.
 *
 * Se lee al compilar: editar API.md y volver a compilar actualiza las docs.
 */

/** Grupo del menu de cada seccion, por su titulo limpio. */
const GRUPOS = {
    'Integración paso a paso': 'Empezar',
    'Autenticación y cabeceras': 'Conceptos',
    Errores: 'Conceptos',
    'Estados de la factura': 'Conceptos',
    Webhooks: 'Conceptos',
    'Recomendaciones de integración': 'Conceptos',
    'Agentes de IA (servidor MCP)': 'Herramientas',
    'Probar con Postman o Thunder Client': 'Herramientas',
    Soporte: 'Ayuda',
};

/** Parte un texto Markdown por un nivel de encabezado, sin mirar dentro de bloques de codigo. */
function partir(texto, marca) {
    const partes = [];
    let actual = { titulo: null, lineas: [] };
    let enCodigo = false;

    for (const linea of texto.split('\n')) {
        if (linea.startsWith('```')) {
            enCodigo = !enCodigo;
        }

        if (!enCodigo && linea.startsWith(marca)) {
            partes.push(actual);
            actual = { titulo: linea.slice(marca.length).trim(), lineas: [] };
            continue;
        }

        actual.lineas.push(linea);
    }

    partes.push(actual);

    return partes.map((p) => ({ titulo: p.titulo, cuerpo: p.lineas.join('\n').trim() }));
}

/** 'GET /facturas/{cuf}' -> 'GET /facturas/{}' para casar con la referencia. */
export function claveEndpoint(metodo, ruta) {
    return `${metodo.toUpperCase()} ${ruta.trim().replace(/\{[^}]+\}/g, '{}')}`;
}

const secciones = partir(fuente, '## ');

/** Lo que va antes de la primera seccion: el resumen de la guia. */
export const introduccion = secciones[0].cuerpo.replace(/^# .*\n/, '').trim();

/** Notas detalladas de cada endpoint, por clave 'METODO /ruta'. */
export const notasDeEndpoint = {};

/** Paginas de guia: { slug, titulo, grupo, markdown, texto }. */
export const guias = [];

for (const seccion of secciones.slice(1)) {
    const titulo = tituloLimpio(seccion.titulo);

    if (titulo === 'Endpoints') {
        for (const sub of partir(seccion.cuerpo, '### ').slice(1)) {
            const metodoRuta = sub.titulo.match(/`(GET|POST|PUT|DELETE|PATCH)\s+([^`]+)`/);

            if (metodoRuta) {
                notasDeEndpoint[claveEndpoint(metodoRuta[1], metodoRuta[2])] = sub.cuerpo;
                continue;
            }

            // Sin la ruta al final ("Emision masiva: /masiva/...") ni la aclaracion entre parentesis.
            const tituloSub = tituloLimpio(sub.titulo).replace(/\s*\(su sistema NO la arma\)/, '').replace(/:\s*\/\S+$/, '');
            guias.push({ slug: slug(tituloSub), titulo: tituloSub, grupo: 'Conceptos', markdown: sub.cuerpo, texto: textoPlano(sub.cuerpo) });
        }
        continue;
    }

    guias.push({
        slug: slug(titulo),
        titulo,
        grupo: GRUPOS[titulo] ?? 'Conceptos',
        markdown: seccion.cuerpo,
        texto: textoPlano(seccion.cuerpo),
    });
}
