import { useState } from 'react';
import { Check, Copy, LockKey } from '@phosphor-icons/react';
import BloqueCodigo from './BloqueCodigo.jsx';
import Markdown from './Markdown.jsx';
import Metodo from './Metodo.jsx';
import { useDocs } from '../contexto.js';

/** Tabla de campos (parametros o cuerpo), con los anidados sangrados. */
function TablaCampos({ filas, conUbicacion = false }) {
    return (
        <div className="campos">
            {filas.map((f) => (
                <div key={`${f.en ?? ''}${f.nombre}`} className="campo" style={{ '--nivel': f.profundidad ?? 0 }}>
                    <div className="campo-cabeza">
                        <code className="campo-nombre">{f.nombre}</code>
                        {f.tipo && <span className="campo-tipo">{f.tipo}</span>}
                        {conUbicacion && <span className="campo-tipo">{f.en}</span>}
                        {f.requerido && <span className="campo-requerido">obligatorio</span>}
                    </div>
                    {f.descripcion && <Markdown>{f.descripcion}</Markdown>}
                </div>
            ))}
        </div>
    );
}

/** Clase de color segun el codigo de respuesta. */
const tonoCodigo = (codigo) => (codigo.startsWith('2') ? 'ok' : codigo.startsWith('4') ? 'aviso' : 'error');

/**
 * Pagina de un endpoint, como en los portales de APIs: a la izquierda que
 * hace y que recibe; a la derecha, la peticion en cuatro lenguajes y la
 * respuesta de ejemplo, siempre a la vista.
 */
export default function PaginaOperacion({ op }) {
    const { datos } = useDocs();
    const [copiado, setCopiado] = useState(false);
    const urlCompleta = `${datos.api_url}${op.ruta}`;

    const copiarUrl = async () => {
        try {
            await navigator.clipboard.writeText(urlCompleta);
            setCopiado(true);
            setTimeout(() => setCopiado(false), 1500);
        } catch {
            setCopiado(false);
        }
    };

    const respuestasJson = op.respuestas.filter((r) => r.ejemplo !== null);

    return (
        <article className="pagina pagina-operacion">
            <div className="operacion-texto">
                <header className="pagina-cabeza">
                    <span className="pagina-grupo">{op.grupo}</span>
                    <h1>{op.titulo}</h1>
                </header>

                <button type="button" className="endpoint" onClick={copiarUrl} title="Copiar URL">
                    <Metodo metodo={op.metodo} />
                    <code>{op.ruta}</code>
                    {copiado ? <Check size={16} weight="bold" /> : <Copy size={16} />}
                </button>

                {op.permiso && (
                    <p className="permiso">
                        <LockKey size={16} weight="duotone" /> Exige el permiso <code>{op.permiso}</code> en el token.
                    </p>
                )}

                {op.descripcion && <Markdown>{op.descripcion}</Markdown>}

                <h2 className="ancla" id="autorizacion">Autorización</h2>
                <TablaCampos filas={[{ nombre: 'Authorization', tipo: 'header', requerido: true, descripcion: 'Token de su empresa: `Bearer 12|siat_live_...` (o `siat_test_...` en pruebas).' }]} />

                {op.parametros.length > 0 && (
                    <>
                        <h2 className="ancla" id="parametros">Parámetros</h2>
                        <TablaCampos filas={op.parametros} conUbicacion />
                    </>
                )}

                {op.cuerpo && (
                    <>
                        <h2 className="ancla" id="cuerpo">Cuerpo <span className="tipo-contenido">{op.cuerpo.tipo}</span></h2>
                        <TablaCampos filas={op.cuerpo.filas} />
                    </>
                )}

                <h2 className="ancla" id="respuestas">Respuestas</h2>
                <div className="respuestas">
                    {op.respuestas.map((r) => (
                        <div key={r.codigo} className="respuesta">
                            <span className={`estado-http estado-${tonoCodigo(r.codigo)}`}>{r.codigo}</span>
                            <Markdown>{r.descripcion || ' '}</Markdown>
                        </div>
                    ))}
                </div>

                {op.notas && (
                    <>
                        <h2 className="ancla" id="detalle">Detalle y reglas</h2>
                        <Markdown>{op.notas}</Markdown>
                    </>
                )}
            </div>

            <aside className="operacion-ejemplos">
                <div className="ejemplos-fijos">
                    <BloqueCodigo pestanas={op.ejemplos} />
                    {respuestasJson.length > 0 && (
                        <BloqueCodigo
                            pestanas={respuestasJson.map((r) => ({
                                nombre: r.codigo,
                                lenguaje: 'json',
                                codigo: JSON.stringify(r.ejemplo, null, 2),
                            }))}
                        />
                    )}
                </div>
            </aside>
        </article>
    );
}
