import { useState } from 'react';
import { Check, Copy } from '@phosphor-icons/react';

/**
 * Codigo real de la API con pestanas (cURL, JavaScript, PHP...) y boton de
 * copiar. No es una captura falsa: es exactamente lo que se manda.
 */
export default function BloqueCodigo({ pestanas, titulo }) {
    const [activa, setActiva] = useState(0);
    const [copiado, setCopiado] = useState(false);

    const copiar = async () => {
        try {
            await navigator.clipboard.writeText(pestanas[activa].codigo);
            setCopiado(true);
            setTimeout(() => setCopiado(false), 1800);
        } catch {
            setCopiado(false);
        }
    };

    return (
        <figure className="codigo">
            <div className="codigo-barra">
                {pestanas.length > 1 ? (
                    <div className="codigo-pestanas" role="tablist" aria-label={titulo}>
                        {pestanas.map((p, i) => (
                            <button
                                key={p.nombre}
                                type="button"
                                role="tab"
                                aria-selected={i === activa}
                                className={i === activa ? 'activa' : ''}
                                onClick={() => setActiva(i)}
                            >
                                {p.nombre}
                            </button>
                        ))}
                    </div>
                ) : (
                    <figcaption>{pestanas[0].nombre}</figcaption>
                )}
                <button type="button" className="codigo-copiar" onClick={copiar} aria-label="Copiar código">
                    {copiado ? <Check size={16} weight="bold" /> : <Copy size={16} />}
                    <span>{copiado ? 'Copiado' : 'Copiar'}</span>
                </button>
            </div>
            <pre tabIndex={0}>
                <code>{pestanas[activa].codigo}</code>
            </pre>
        </figure>
    );
}
