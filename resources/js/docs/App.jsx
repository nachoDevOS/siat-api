import { useCallback, useEffect, useMemo, useState } from 'react';
import { motion, useReducedMotion } from 'motion/react';
import { ArrowLeft, ArrowRight, DownloadSimple, List, MagnifyingGlass, X } from '@phosphor-icons/react';
import { ContextoDocs } from './contexto.js';
import { guias } from './contenido/guia.js';
import { operaciones } from './contenido/referencia.js';
import Buscador from './componentes/Buscador.jsx';
import Indice from './componentes/Indice.jsx';
import Lateral from './componentes/Lateral.jsx';
import PaginaGuia from './componentes/PaginaGuia.jsx';
import PaginaInicio from './componentes/PaginaInicio.jsx';
import PaginaOperacion from './componentes/PaginaOperacion.jsx';

/** Orden de los grupos de guias en el menu; la referencia va entre medio. */
const GRUPOS_ANTES = ['Empezar', 'Conceptos', 'Herramientas'];
const GRUPOS_DESPUES = ['Ayuda'];

/**
 * Portal de documentacion de la API. Cada pagina tiene su URL
 * (/docs/api/{pagina}); la navegacion interna cambia la URL sin recargar.
 */
export default function App({ datos }) {
    const reducir = useReducedMotion();
    const base = datos.base;
    const slugDeUrl = () => decodeURIComponent(window.location.pathname.slice(base.length).replace(/^\/+|\/+$/g, ''));

    const [actual, setActual] = useState(slugDeUrl);
    const [menuAbierto, setMenuAbierto] = useState(false);
    const [buscando, setBuscando] = useState(false);

    const enlace = useCallback((destino = '') => {
        if (/^(https?:|mailto:|#)/.test(destino)) {
            return destino;
        }

        return destino ? `${base}/${destino}` : base;
    }, [base]);

    // Paginas en el orden del menu: inicio, guias, referencia por etiqueta y ayuda.
    const { grupos, paginas } = useMemo(() => {
        const ops = operaciones(datos.api_url).map((op) => ({ ...op, tituloMenu: op.titulo }));
        const inicio = { slug: '', titulo: 'Introducción', grupo: 'Empezar', texto: 'Inicio de la documentación' };
        const deGrupo = (nombre) => guias.filter((g) => g.grupo === nombre);
        const etiquetas = [...new Set(ops.map((op) => op.grupo))];

        const lista = [
            { nombre: 'Empezar', paginas: [inicio, ...deGrupo('Empezar')] },
            ...GRUPOS_ANTES.slice(1).map((nombre) => ({ nombre, paginas: deGrupo(nombre) })),
            ...etiquetas.map((nombre) => ({ nombre: `API: ${nombre}`, paginas: ops.filter((op) => op.grupo === nombre) })),
            ...GRUPOS_DESPUES.map((nombre) => ({ nombre, paginas: deGrupo(nombre) })),
        ].filter((g) => g.paginas.length > 0);

        return { grupos: lista, paginas: lista.flatMap((g) => g.paginas) };
    }, [datos.api_url]);

    const ir = useCallback((destino, hash = '') => {
        window.history.pushState({}, '', enlace(destino) + hash);
        setActual(destino);
        setMenuAbierto(false);

        if (hash) {
            setTimeout(() => document.getElementById(hash.slice(1))?.scrollIntoView({ behavior: reducir ? 'auto' : 'smooth' }), 60);
        } else {
            window.scrollTo({ top: 0 });
        }
    }, [enlace, reducir]);

    // Los enlaces internos navegan sin recargar; atras/adelante tambien.
    useEffect(() => {
        const clic = (e) => {
            const a = e.target.closest('a');
            if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || a.target) {
                return;
            }

            const url = new URL(a.href, window.location.href);
            const esDocs = url.origin === window.location.origin && (url.pathname === base || url.pathname.startsWith(`${base}/`));

            if (esDocs && !/\.(yaml|json)$/.test(url.pathname)) {
                e.preventDefault();
                ir(decodeURIComponent(url.pathname.slice(base.length).replace(/^\/+|\/+$/g, '')), url.hash);
            }
        };
        const atras = () => setActual(slugDeUrl());

        document.addEventListener('click', clic);
        window.addEventListener('popstate', atras);

        return () => {
            document.removeEventListener('click', clic);
            window.removeEventListener('popstate', atras);
        };
    }, [base, ir]);

    const indice = paginas.findIndex((p) => p.slug === actual);
    const pagina = paginas[indice];
    const anterior = indice > 0 ? paginas[indice - 1] : null;
    const siguiente = indice >= 0 && indice < paginas.length - 1 ? paginas[indice + 1] : null;
    const esOperacion = Boolean(pagina?.metodo);

    useEffect(() => {
        document.title = `${pagina ? pagina.titulo : 'Página no encontrada'} · Documentación de la API · ${datos.marca}`;
    }, [pagina, datos.marca]);

    return (
        <ContextoDocs.Provider value={{ datos, enlace }}>
            <header className="cabecera">
                <div className="cabecera-fila">
                    <button type="button" className="boton-menu" onClick={() => setMenuAbierto((a) => !a)} aria-label="Menú">
                        {menuAbierto ? <X size={22} /> : <List size={22} />}
                    </button>
                    <a href={enlace('')} className="marca">
                        {datos.logo && <img src={datos.logo} alt="" width="34" height="34" onError={(e) => { e.currentTarget.style.display = 'none'; }} />}
                        <span>{datos.marca}</span>
                        <span className="marca-docs">Docs</span>
                    </a>
                    <button type="button" className="buscar" onClick={() => setBuscando(true)}>
                        <MagnifyingGlass size={17} />
                        <span>Buscar en la documentación</span>
                        <kbd>Ctrl K</kbd>
                    </button>
                    <nav className="cabecera-enlaces">
                        <a href={datos.rutas.coleccion}><DownloadSimple size={16} /> Postman</a>
                        <a href={datos.rutas.openapi}><DownloadSimple size={16} /> OpenAPI</a>
                        <a href={datos.rutas.sitio}>Sitio</a>
                        <a href={datos.rutas.contacto} className="boton-contacto">Contactar</a>
                    </nav>
                </div>
            </header>

            <div className="marco">
                <Lateral grupos={grupos} actual={actual} abierto={menuAbierto} onNavegar={() => setMenuAbierto(false)} />
                {menuAbierto && <div className="velo" onClick={() => setMenuAbierto(false)} aria-hidden="true" />}

                <main className={esOperacion ? 'contenido contenido-ancho' : 'contenido'}>
                    <motion.div
                        key={actual}
                        initial={reducir ? false : { opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.35, ease: [0.16, 1, 0.3, 1] }}
                    >
                        {!pagina && (
                            <article className="pagina">
                                <h1>Página no encontrada</h1>
                                <p className="bajada">Esa página no existe en la documentación. Pruebe con el buscador o vuelva al inicio.</p>
                                <p><a href={enlace('')}>Ir al inicio de la documentación</a></p>
                            </article>
                        )}
                        {pagina && pagina.slug === '' && <PaginaInicio />}
                        {pagina && pagina.markdown !== undefined && <PaginaGuia pagina={pagina} />}
                        {pagina && esOperacion && <PaginaOperacion op={pagina} />}

                        {pagina && (
                            <nav className="paginacion" aria-label="Anterior y siguiente">
                                {anterior ? (
                                    <a href={enlace(anterior.slug)} className="paginacion-enlace">
                                        <small><ArrowLeft size={14} /> Anterior</small>
                                        <span>{anterior.titulo}</span>
                                    </a>
                                ) : <span />}
                                {siguiente && (
                                    <a href={enlace(siguiente.slug)} className="paginacion-enlace paginacion-siguiente">
                                        <small>Siguiente <ArrowRight size={14} /></small>
                                        <span>{siguiente.titulo}</span>
                                    </a>
                                )}
                            </nav>
                        )}
                    </motion.div>
                </main>

                {!esOperacion && <Indice clave={actual} />}
            </div>

            <Buscador paginas={paginas} abierto={buscando} setAbierto={setBuscando} onIr={ir} />
        </ContextoDocs.Provider>
    );
}
