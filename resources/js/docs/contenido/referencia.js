import YAML from 'yaml';
import fuente from '../../../../docs/integracion/openapi.yaml?raw';
import { claveEndpoint, notasDeEndpoint } from './guia.js';
import { slug, textoPlano } from './util.js';

/**
 * Referencia de la API generada desde docs/integracion/openapi.yaml: una
 * pagina por operacion, con parametros, cuerpo, respuestas y ejemplos.
 * Un test del backend compara este archivo con las rutas reales, asi que la
 * referencia no puede quedar desactualizada.
 */
export const especificacion = YAML.parse(fuente);

const METODOS = ['get', 'post', 'put', 'patch', 'delete'];

/** Resuelve los $ref de componentes, con tope de profundidad por si hay ciclos. */
export function resolver(valor, profundidad = 0) {
    if (profundidad > 8 || valor === null || typeof valor !== 'object') {
        return valor;
    }

    if (Array.isArray(valor)) {
        return valor.map((v) => resolver(v, profundidad + 1));
    }

    if (typeof valor.$ref === 'string') {
        const destino = valor.$ref.replace(/^#\//, '').split('/').reduce((nodo, clave) => nodo?.[clave], especificacion);

        return resolver(destino, profundidad + 1);
    }

    return Object.fromEntries(Object.entries(valor).map(([k, v]) => [k, resolver(v, profundidad + 1)]));
}

/** Ejemplo armado desde un esquema: usa 'example' si lo hay, si no, valores tipicos. */
export function ejemplo(esquema, profundidad = 0) {
    if (!esquema || profundidad > 6) {
        return null;
    }

    if (esquema.example !== undefined) {
        return esquema.example;
    }

    if (esquema.enum) {
        return esquema.enum[0];
    }

    if (esquema.type === 'object' || esquema.properties) {
        return Object.fromEntries(
            Object.entries(esquema.properties ?? {}).map(([nombre, prop]) => [nombre, ejemplo(prop, profundidad + 1)]),
        );
    }

    if (esquema.type === 'array') {
        return [ejemplo(esquema.items, profundidad + 1)];
    }

    if (esquema.type === 'string') {
        return { 'date-time': '2026-10-18T10:15:00-04:00', date: '2026-10-18', uri: 'https://...', email: 'cliente@correo.bo', uuid: '2f6c1d3e-8a41-4b0e-9c55-1f2a3b4c5d6e', binary: '(archivo)' }[esquema.format] ?? 'texto';
    }

    if (esquema.type === 'integer') {
        return esquema.minimum ?? 0;
    }

    if (esquema.type === 'number') {
        return esquema.minimum ?? 0;
    }

    if (esquema.type === 'boolean') {
        return true;
    }

    return null;
}

/**
 * Filas para la tabla de un esquema: nombre (con su ruta si esta anidado),
 * tipo, si es obligatorio y su descripcion.
 */
export function filasDeEsquema(esquema, prefijo = '', profundidad = 0) {
    if (!esquema || profundidad > 3) {
        return [];
    }

    const objeto = esquema.type === 'array' ? esquema.items : esquema;
    const requeridos = new Set(objeto?.required ?? []);
    const filas = [];

    for (const [nombre, prop] of Object.entries(objeto?.properties ?? {})) {
        const ruta = prefijo ? `${prefijo}.${nombre}` : nombre;
        const esLista = prop.type === 'array';
        const interno = esLista ? prop.items : prop;

        filas.push({
            nombre: esLista ? `${ruta}[]` : ruta,
            tipo: esLista ? `array<${interno?.type ?? 'object'}>` : (prop.type ?? (prop.properties ? 'object' : '')),
            requerido: requeridos.has(nombre),
            descripcion: [
                prop.description,
                prop.enum ? `Valores: ${prop.enum.map((v) => `\`${v}\``).join(', ')}` : null,
                prop.maxLength ? `Máx. ${prop.maxLength} caracteres` : null,
                prop.nullable ? 'Puede ser `null`' : null,
            ].filter(Boolean).join('. '),
            profundidad,
        });

        if (interno?.properties) {
            filas.push(...filasDeEsquema(interno, esLista ? `${ruta}[]` : ruta, profundidad + 1));
        }
    }

    return filas;
}

/** Ejemplos de codigo de una operacion en cuatro lenguajes. */
function ejemplosDeCodigo(op, apiUrl) {
    const url = `${apiUrl}${op.ruta}`;
    const cuerpo = op.cuerpo?.ejemplo;
    const multipart = op.cuerpo?.tipo === 'multipart/form-data';
    const json = cuerpo && !multipart ? JSON.stringify(cuerpo, null, 2) : null;
    const metodo = op.metodo.toUpperCase();

    const curl = [
        `curl -X ${metodo} "${url}"`,
        '  -H "Authorization: Bearer $SIAT_TOKEN"',
        '  -H "Accept: application/json"',
        ...(multipart ? ['  -F "archivo=@certificado.p12"', '  -F "passphrase=..."'] : []),
        ...(json ? ['  -H "Content-Type: application/json"', `  -d '${json.replace(/\n/g, '\n  ')}'`] : []),
    ].join(' \\\n');

    const js = [
        `const respuesta = await fetch('${url}', {`,
        `  method: '${metodo}',`,
        '  headers: {',
        '    Authorization: `Bearer ${process.env.SIAT_TOKEN}`,',
        "    Accept: 'application/json',",
        ...(json ? ["    'Content-Type': 'application/json',"] : []),
        '  },',
        ...(json ? [`  body: JSON.stringify(${json.replace(/\n/g, '\n  ')}),`] : []),
        '});',
        '',
        'const datos = await respuesta.json();',
    ].join('\n');

    const php = [
        "$respuesta = Http::withToken(env('SIAT_TOKEN'))",
        '    ->acceptJson()',
        ...(multipart ? ["    ->attach('archivo', file_get_contents('certificado.p12'), 'certificado.p12')"] : []),
        `    ->${op.metodo}('${url}'${json ? `, ${phpArray(cuerpo, 1)}` : multipart ? ", ['passphrase' => '...']" : ''});`,
        '',
        '$datos = $respuesta->json();',
    ].join('\n');

    const python = [
        'import os, requests',
        '',
        `respuesta = requests.${op.metodo}(`,
        `    "${url}",`,
        '    headers={"Authorization": f"Bearer {os.environ[\'SIAT_TOKEN\']}"},',
        ...(json ? [`    json=${json.replace(/\n/g, '\n    ').replace(/\bnull\b/g, 'None').replace(/\btrue\b/g, 'True').replace(/\bfalse\b/g, 'False')},`] : []),
        ...(multipart ? ['    files={"archivo": open("certificado.p12", "rb")},', '    data={"passphrase": "..."},'] : []),
        ')',
        'datos = respuesta.json()',
    ].join('\n');

    return [
        { nombre: 'cURL', lenguaje: 'bash', codigo: curl },
        { nombre: 'JavaScript', lenguaje: 'javascript', codigo: js },
        { nombre: 'PHP', lenguaje: 'php', codigo: php },
        { nombre: 'Python', lenguaje: 'python', codigo: python },
    ];
}

/** Arreglo de PHP legible a partir de un valor JSON. */
function phpArray(valor, nivel) {
    const sangria = '    '.repeat(nivel + 1);
    const cierre = '    '.repeat(nivel);

    if (Array.isArray(valor)) {
        return `[\n${valor.map((v) => `${sangria}${phpArray(v, nivel + 1)}`).join(',\n')},\n${cierre}]`;
    }

    if (valor && typeof valor === 'object') {
        return `[\n${Object.entries(valor).map(([k, v]) => `${sangria}'${k}' => ${phpArray(v, nivel + 1)}`).join(',\n')},\n${cierre}]`;
    }

    return typeof valor === 'string' ? `'${valor}'` : String(valor);
}

/** Todas las operaciones, en el orden de las etiquetas del spec. */
export function operaciones(apiUrl) {
    const ordenEtiquetas = (especificacion.tags ?? []).map((t) => t.name);
    const lista = [];

    for (const [ruta, item] of Object.entries(especificacion.paths)) {
        for (const metodo of METODOS) {
            const crudo = item[metodo];

            if (!crudo) {
                continue;
            }

            const op = resolver(crudo);
            const contenido = Object.entries(op.requestBody?.content ?? {})[0];

            const operacion = {
                metodo,
                ruta,
                slug: slug(`${metodo}-${ruta.replace(/[{}]/g, '')}`),
                titulo: op.summary ?? `${metodo.toUpperCase()} ${ruta}`,
                grupo: op.tags?.[0] ?? 'Otros',
                descripcion: op.description ?? '',
                permiso: crudo['x-permiso'],
                parametros: (op.parameters ?? []).map((p) => ({
                    nombre: p.name,
                    en: p.in,
                    tipo: p.schema?.type ?? '',
                    requerido: Boolean(p.required || p.in === 'path'),
                    descripcion: [p.description, p.schema?.enum ? `Valores: ${p.schema.enum.map((v) => `\`${v}\``).join(', ')}` : null].filter(Boolean).join('. '),
                })),
                cuerpo: contenido
                    ? { tipo: contenido[0], filas: filasDeEsquema(contenido[1].schema), ejemplo: ejemplo(contenido[1].schema) }
                    : null,
                respuestas: Object.entries(op.responses ?? {}).map(([codigo, r]) => {
                    const esquema = Object.values(r.content ?? {})[0]?.schema;
                    const json = r.content?.['application/json'];

                    return {
                        codigo,
                        descripcion: r.description ?? '',
                        ejemplo: json ? ejemplo(esquema) : null,
                        tipo: Object.keys(r.content ?? {})[0] ?? null,
                    };
                }),
                notas: notasDeEndpoint[claveEndpoint(metodo, ruta)] ?? null,
            };

            operacion.ejemplos = ejemplosDeCodigo(operacion, apiUrl);
            operacion.texto = textoPlano(`${operacion.titulo} ${ruta} ${operacion.descripcion} ${operacion.notas ?? ''}`);
            lista.push(operacion);
        }
    }

    const orden = (op) => {
        const i = ordenEtiquetas.indexOf(op.grupo);

        return i === -1 ? 99 : i;
    };

    return lista.sort((a, b) => orden(a) - orden(b));
}
