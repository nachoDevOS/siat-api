import { useState } from 'react';
import { Highlight } from 'prism-react-renderer';
import { Check, Copy } from '@phosphor-icons/react';

/**
 * Bloque de codigo con pestanas, resaltado y boton de copiar.
 * prism-react-renderer no trae bash ni PHP: se usa la gramatica mas cercana.
 */
const GRAMATICA = { php: 'clike', bash: 'clike', sh: 'clike', shell: 'clike', http: 'yaml', js: 'javascript', node: 'javascript' };

/** Colores de la marca: cian, violeta y grises sobre el azul noche. */
const tema = {
    plain: { color: '#cbd5e1', backgroundColor: 'transparent' },
    styles: [
        { types: ['comment', 'prolog'], style: { color: '#64748b', fontStyle: 'italic' } },
        { types: ['string', 'char', 'attr-value', 'inserted'], style: { color: '#67e8f9' } },
        { types: ['number', 'boolean', 'constant'], style: { color: '#fbbf24' } },
        { types: ['keyword', 'atrule', 'selector'], style: { color: '#c084fc' } },
        { types: ['function', 'class-name'], style: { color: '#7dd3fc' } },
        { types: ['property', 'attr-name', 'key'], style: { color: '#a5f3fc' } },
        { types: ['punctuation', 'operator'], style: { color: '#94a3b8' } },
    ],
};

export default function BloqueCodigo({ pestanas, compacto = false }) {
    const [activa, setActiva] = useState(0);
    const [copiado, setCopiado] = useState(false);
    const actual = pestanas[Math.min(activa, pestanas.length - 1)];

    const copiar = async () => {
        try {
            await navigator.clipboard.writeText(actual.codigo);
            setCopiado(true);
            setTimeout(() => setCopiado(false), 1600);
        } catch {
            setCopiado(false);
        }
    };

    return (
        <div className={compacto ? 'codigo codigo-compacto' : 'codigo'}>
            <div className="codigo-barra">
                <div className="codigo-pestanas" role="tablist">
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
                <button type="button" className="codigo-copiar" onClick={copiar} aria-label="Copiar código">
                    {copiado ? <Check size={15} weight="bold" /> : <Copy size={15} />}
                    <span>{copiado ? 'Copiado' : 'Copiar'}</span>
                </button>
            </div>
            <Highlight code={actual.codigo.trimEnd()} language={GRAMATICA[actual.lenguaje] ?? actual.lenguaje ?? 'plain'} theme={tema}>
                {({ tokens, getLineProps, getTokenProps }) => (
                    <pre tabIndex={0}>
                        {tokens.map((linea, i) => (
                            <div key={i} {...getLineProps({ line: linea })}>
                                {linea.map((token, j) => <span key={j} {...getTokenProps({ token })} />)}
                            </div>
                        ))}
                    </pre>
                )}
            </Highlight>
        </div>
    );
}
