import { BookOpen, Code, Robot, ArrowRight } from '@phosphor-icons/react';
import BloqueCodigo from './BloqueCodigo.jsx';
import Revelar from './Revelar.jsx';

export default function Desarrolladores({ datos }) {
    const ejemplos = [
        {
            nombre: 'JavaScript',
            codigo: `const respuesta = await fetch('${datos.api_url}/facturas', {
  method: 'POST',
  headers: {
    'Authorization': \`Bearer \${process.env.SIAT_TOKEN}\`,
    'Content-Type': 'application/json',
    'X-Idempotency-Key': venta.id,
  },
  body: JSON.stringify(venta),
});

const { factura } = await respuesta.json();
imprimir(factura.url_publica_rollo);`,
        },
        {
            nombre: 'PHP',
            codigo: `$factura = Http::withToken(env('SIAT_TOKEN'))
    ->withHeaders(['X-Idempotency-Key' => $venta->id])
    ->post('${datos.api_url}/facturas', $venta->toArray())
    ->json('factura');

return redirect($factura['url_publica_pdf']);`,
        },
        {
            nombre: 'Python',
            codigo: `import os, requests

r = requests.post(
    "${datos.api_url}/facturas",
    json=venta,
    headers={
        "Authorization": f"Bearer {os.environ['SIAT_TOKEN']}",
        "X-Idempotency-Key": venta["id"],
    },
)
factura = r.json()["factura"]`,
        },
    ];

    const recursos = [
        { icono: BookOpen, titulo: 'Documentación completa', texto: 'Cada endpoint, campo y error, con ejemplos.', href: datos.rutas.documentacion },
        { icono: Code, titulo: 'Colección de Postman', texto: 'Las peticiones en orden, listas para probar.', href: datos.rutas.coleccion },
        { icono: Robot, titulo: 'Servidor MCP para agentes', texto: `Claude, Cursor o VS Code facturan con el mismo token en ${datos.mcp_url}`, href: null },
    ];

    return (
        <section id="desarrolladores" className="seccion">
            <div className="contenedor dev-rejilla">
                <Revelar className="dev-texto">
                    <span className="etiqueta">Para desarrolladores</span>
                    <h2 className="titulo-seccion">Una API REST que se integra en una tarde</h2>
                    <p className="parrafo">
                        JSON, token Bearer y respuestas con el motivo exacto cuando algo no cuadra. Repetir una petición con la misma clave nunca duplica una factura.
                    </p>
                    <ul className="recursos">
                        {recursos.map(({ icono: Icono, titulo, texto, href }) => (
                            <li key={titulo}>
                                {href ? (
                                    <a href={href} className="recurso">
                                        <Icono size={22} weight="duotone" />
                                        <span>
                                            <strong>{titulo}</strong>
                                            <small>{texto}</small>
                                        </span>
                                        <ArrowRight size={16} className="recurso-flecha" />
                                    </a>
                                ) : (
                                    <div className="recurso">
                                        <Icono size={22} weight="duotone" />
                                        <span>
                                            <strong>{titulo}</strong>
                                            <small>{texto}</small>
                                        </span>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </Revelar>
                <Revelar retraso={100} className="dev-codigo">
                    <BloqueCodigo titulo="Ejemplos de integración" pestanas={ejemplos} />
                </Revelar>
            </div>
        </section>
    );
}
