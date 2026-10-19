import { useDocs } from '../contexto.js';
import Metodo from './Metodo.jsx';

/**
 * Menu lateral por grupos. Los endpoints muestran su metodo; la pagina
 * actual queda marcada.
 */
export default function Lateral({ grupos, actual, abierto, onNavegar }) {
    const { enlace } = useDocs();

    return (
        <nav className={abierto ? 'lateral abierto' : 'lateral'} aria-label="Documentación">
            {grupos.map(({ nombre, paginas }) => (
                <div key={nombre} className="lateral-grupo">
                    <span className="lateral-titulo">{nombre}</span>
                    {paginas.map((p) => (
                        <a
                            key={p.slug}
                            href={enlace(p.slug)}
                            className={p.slug === actual ? 'lateral-enlace activo' : 'lateral-enlace'}
                            aria-current={p.slug === actual ? 'page' : undefined}
                            onClick={onNavegar}
                        >
                            {p.metodo && <Metodo metodo={p.metodo} chico />}
                            <span>{p.tituloMenu ?? p.titulo}</span>
                        </a>
                    ))}
                </div>
            ))}
        </nav>
    );
}
