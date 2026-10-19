import { useEffect, useState } from 'react';

/**
 * "En esta pagina": los subtitulos de la pagina actual, con el que se esta
 * leyendo marcado (IntersectionObserver, sin escuchar el scroll).
 */
export default function Indice({ clave }) {
    const [titulos, setTitulos] = useState([]);
    const [activo, setActivo] = useState(null);

    useEffect(() => {
        const nodos = [...document.querySelectorAll('.contenido h2.ancla[id], .contenido h3.ancla[id]')];
        setTitulos(nodos.map((n) => ({ id: n.id, texto: n.textContent.replace(/^#/, ''), nivel: n.tagName === 'H3' ? 3 : 2 })));
        setActivo(nodos[0]?.id ?? null);

        if (!('IntersectionObserver' in window) || nodos.length === 0) {
            return undefined;
        }

        const observador = new IntersectionObserver(
            (entradas) => {
                const visible = entradas.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];
                if (visible) {
                    setActivo(visible.target.id);
                }
            },
            { rootMargin: '-80px 0px -70% 0px' },
        );
        nodos.forEach((n) => observador.observe(n));

        return () => observador.disconnect();
    }, [clave]);

    if (titulos.length < 2) {
        return null;
    }

    return (
        <nav className="indice" aria-label="En esta página">
            <span className="lateral-titulo">En esta página</span>
            {titulos.map((t) => (
                <a key={t.id} href={`#${t.id}`} className={`${t.nivel === 3 ? 'indice-sub' : ''} ${t.id === activo ? 'activo' : ''}`}>
                    {t.texto}
                </a>
            ))}
        </nav>
    );
}
