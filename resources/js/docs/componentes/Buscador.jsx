import { useEffect, useMemo, useRef, useState } from 'react';
import { AnimatePresence, motion } from 'motion/react';
import { MagnifyingGlass, ArrowElbowDownLeft } from '@phosphor-icons/react';
import Metodo from './Metodo.jsx';
import { normalizar } from '../contenido/util.js';
import { useDocs } from '../contexto.js';

/**
 * Buscador de toda la documentacion (Ctrl+K o /). Busca en titulos, rutas y
 * texto; los titulos pesan mas. Se maneja con flechas y Enter.
 */
export default function Buscador({ paginas, abierto, setAbierto, onIr }) {
    const { enlace } = useDocs();
    const [consulta, setConsulta] = useState('');
    const [elegido, setElegido] = useState(0);
    const entrada = useRef(null);

    useEffect(() => {
        const tecla = (e) => {
            if ((e.key === 'k' && (e.ctrlKey || e.metaKey)) || (e.key === '/' && !['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName))) {
                e.preventDefault();
                setAbierto(true);
            }
            if (e.key === 'Escape') {
                setAbierto(false);
            }
        };
        window.addEventListener('keydown', tecla);

        return () => window.removeEventListener('keydown', tecla);
    }, [setAbierto]);

    useEffect(() => {
        if (abierto) {
            setConsulta('');
            setElegido(0);
            setTimeout(() => entrada.current?.focus(), 30);
        }
    }, [abierto]);

    const resultados = useMemo(() => {
        const q = normalizar(consulta.trim());

        if (!q) {
            return paginas.slice(0, 8);
        }

        return paginas
            .map((p) => {
                const titulo = normalizar(`${p.titulo} ${p.ruta ?? ''}`);
                const cuerpo = normalizar(p.texto ?? '');
                const puntaje = (titulo.includes(q) ? 10 : 0) + (titulo.startsWith(q) ? 5 : 0) + (cuerpo.includes(q) ? 1 : 0);
                const i = cuerpo.indexOf(q);

                return { ...p, puntaje, extracto: i >= 0 ? `...${(p.texto ?? '').slice(Math.max(0, i - 40), i + 80)}...` : null };
            })
            .filter((p) => p.puntaje > 0)
            .sort((a, b) => b.puntaje - a.puntaje)
            .slice(0, 12);
    }, [consulta, paginas]);

    const teclas = (e) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setElegido((i) => Math.min(i + 1, resultados.length - 1));
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            setElegido((i) => Math.max(i - 1, 0));
        }
        if (e.key === 'Enter' && resultados[elegido]) {
            onIr(resultados[elegido].slug);
            setAbierto(false);
        }
    };

    return (
        <AnimatePresence>
            {abierto && (
                <motion.div className="buscador-fondo" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} onClick={() => setAbierto(false)}>
                    <motion.div
                        className="buscador"
                        role="dialog"
                        aria-label="Buscar en la documentación"
                        initial={{ opacity: 0, y: -12, scale: 0.98 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: -8 }}
                        transition={{ duration: 0.18 }}
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="buscador-entrada">
                            <MagnifyingGlass size={20} />
                            <input
                                ref={entrada}
                                value={consulta}
                                onChange={(e) => { setConsulta(e.target.value); setElegido(0); }}
                                onKeyDown={teclas}
                                placeholder="Buscar: emitir, webhook, anular, 401..."
                                aria-label="Buscar"
                            />
                            <kbd>Esc</kbd>
                        </div>
                        <div className="buscador-resultados">
                            {resultados.length === 0 && <p className="buscador-vacio">Sin resultados para “{consulta}”.</p>}
                            {resultados.map((r, i) => (
                                <a
                                    key={r.slug}
                                    href={enlace(r.slug)}
                                    className={i === elegido ? 'resultado elegido' : 'resultado'}
                                    onMouseEnter={() => setElegido(i)}
                                    onClick={() => setAbierto(false)}
                                >
                                    <span className="resultado-titulo">
                                        {r.metodo && <Metodo metodo={r.metodo} chico />}
                                        {r.titulo}
                                        <small>{r.grupo}</small>
                                    </span>
                                    {r.extracto && <span className="resultado-extracto">{r.extracto}</span>}
                                    {i === elegido && <ArrowElbowDownLeft size={16} className="resultado-enter" />}
                                </a>
                            ))}
                        </div>
                    </motion.div>
                </motion.div>
            )}
        </AnimatePresence>
    );
}
