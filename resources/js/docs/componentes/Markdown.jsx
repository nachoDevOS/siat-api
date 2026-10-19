import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { Info } from '@phosphor-icons/react';
import BloqueCodigo from './BloqueCodigo.jsx';
import { slug } from '../contenido/util.js';
import { useDocs } from '../contexto.js';

/** Texto plano de los hijos de un encabezado, para su id. */
function texto(hijos) {
    if (typeof hijos === 'string' || typeof hijos === 'number') {
        return String(hijos);
    }
    if (Array.isArray(hijos)) {
        return hijos.map(texto).join('');
    }

    return hijos?.props?.children ? texto(hijos.props.children) : '';
}

/**
 * Markdown de las guias con los bloques del portal: encabezados con ancla,
 * codigo resaltado, tablas con scroll y citas como avisos. Los enlaces a los
 * archivos de docs/integracion apuntan a sus descargas.
 */
export default function Markdown({ children }) {
    const { datos, enlace } = useDocs();

    const destino = (href = '') => {
        if (href.startsWith('openapi.yaml')) {
            return datos.rutas.openapi;
        }
        if (href.startsWith('postman')) {
            return datos.rutas.coleccion;
        }

        return enlace(href);
    };

    const encabezado = (Etiqueta) => function Encabezado({ children: hijos }) {
        // "5.1 Eventos" -> "Eventos": la numeracion es de API.md, aca la da el menu.
        const lista = Array.isArray(hijos) ? [...hijos] : [hijos];
        if (typeof lista[0] === 'string') {
            lista[0] = lista[0].replace(/^\d+(\.\d+)*\.?\s+/, '');
        }
        hijos = lista;
        const id = slug(texto(hijos));

        return (
            <Etiqueta id={id} className="ancla">
                <a href={`#${id}`} aria-hidden="true" tabIndex={-1}>#</a>
                {hijos}
            </Etiqueta>
        );
    };

    return (
        <div className="prosa">
            <ReactMarkdown
                remarkPlugins={[remarkGfm]}
                components={{
                    h1: encabezado('h2'),
                    h2: encabezado('h2'),
                    h3: encabezado('h3'),
                    h4: encabezado('h4'),
                    a: ({ href, children: hijos }) => <a href={destino(href)}>{hijos}</a>,
                    table: ({ children: hijos }) => <div className="tabla"><table>{hijos}</table></div>,
                    blockquote: ({ children: hijos }) => (
                        <div className="aviso"><Info size={20} weight="duotone" /><div>{hijos}</div></div>
                    ),
                    pre: ({ children: hijos }) => hijos,
                    code: ({ className, children: hijos }) => {
                        const lenguaje = /language-(\w+)/.exec(className ?? '')?.[1];
                        const contenido = String(hijos);

                        if (!lenguaje && !contenido.includes('\n')) {
                            return <code>{hijos}</code>;
                        }

                        return <BloqueCodigo compacto pestanas={[{ nombre: lenguaje ?? 'texto', lenguaje, codigo: contenido }]} />;
                    },
                }}
            >
                {children}
            </ReactMarkdown>
        </div>
    );
}
