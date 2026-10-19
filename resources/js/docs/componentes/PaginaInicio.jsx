import { motion, useReducedMotion } from 'motion/react';
import { ArrowRight, BookOpen, Code, Key, Lightning, Robot, WebhooksLogo, Flask, ShieldCheck } from '@phosphor-icons/react';
import Markdown from './Markdown.jsx';
import BloqueCodigo from './BloqueCodigo.jsx';
import { introduccion } from '../contenido/guia.js';
import { useDocs } from '../contexto.js';

/**
 * Portada de la documentacion: que es la API, por donde empezar y los dos
 * ambientes, con accesos a lo que mas se busca.
 */
export default function PaginaInicio() {
    const { datos, enlace } = useDocs();
    const reducir = useReducedMotion();

    const accesos = [
        { icono: Lightning, titulo: 'Integración paso a paso', texto: 'De cero a su primera factura, en orden.', href: enlace('integracion-paso-a-paso') },
        { icono: Key, titulo: 'Autenticación', texto: 'Token Bearer, perfiles y permisos.', href: enlace('autenticacion-y-cabeceras') },
        { icono: Code, titulo: 'Emitir una factura', texto: 'El endpoint principal, campo por campo.', href: enlace('post-facturas') },
        { icono: WebhooksLogo, titulo: 'Webhooks', texto: 'Enterarse del resultado sin consultar.', href: enlace('webhooks') },
        { icono: BookOpen, titulo: 'Probar con Postman', texto: 'Las peticiones en orden, listas.', href: enlace('probar-con-postman-o-thunder-client') },
        { icono: Robot, titulo: 'Agentes de IA', texto: 'Servidor MCP con el mismo token.', href: enlace('agentes-de-ia-servidor-mcp') },
    ];

    const prueba = [{
        nombre: 'Probar la conexión',
        lenguaje: 'bash',
        codigo: `curl ${datos.api_url}/estado \\\n  -H "Authorization: Bearer $SIAT_TOKEN"`,
    }];

    return (
        <article className="pagina">
            <motion.header
                className="inicio-cabeza"
                initial={reducir ? false : { opacity: 0, y: 16 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.6, ease: [0.16, 1, 0.3, 1] }}
            >
                <span className="chip"><Lightning size={15} weight="fill" /> API REST v1</span>
                <h1>Documentación de la <span className="texto-degradado">API de facturación</span></h1>
                <p className="bajada">
                    Su sistema de ventas manda la venta en JSON y recibe la factura con CUF, firmada y lista para imprimir. El SOAP, la firma digital y los códigos del SIN corren de nuestro lado.
                </p>
                <div className="url-base">
                    <span>URL base</span>
                    <code>{datos.api_url}</code>
                </div>
            </motion.header>

            <section className="accesos">
                {accesos.map(({ icono: Icono, titulo, texto, href }, i) => (
                    <motion.a
                        key={titulo}
                        href={href}
                        className="acceso"
                        initial={reducir ? false : { opacity: 0, y: 14 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.5, delay: 0.1 + i * 0.05 }}
                    >
                        <span className="acceso-icono"><Icono size={22} weight="duotone" /></span>
                        <strong>{titulo}</strong>
                        <small>{texto}</small>
                        <ArrowRight size={16} className="acceso-flecha" />
                    </motion.a>
                ))}
            </section>

            <h2 className="ancla" id="ambientes">Dos ambientes, un mismo flujo</h2>
            <div className="ambientes">
                <div className="ambiente">
                    <Flask size={22} weight="duotone" />
                    <strong>Pruebas</strong>
                    <code>siat_test_...</code>
                    <p>Emite contra el SIAT piloto con el mismo flujo, sin validez fiscal. Ideal para integrar.</p>
                </div>
                <div className="ambiente ambiente-produccion">
                    <ShieldCheck size={22} weight="duotone" />
                    <strong>Producción</strong>
                    <code>siat_live_...</code>
                    <p>Facturas con validez fiscal. Para pasar a producción solo cambia el token: la URL es la misma.</p>
                </div>
            </div>

            <h2 className="ancla" id="primera-llamada">Su primera llamada</h2>
            <BloqueCodigo pestanas={prueba} />

            <h2 className="ancla" id="resumen">Resumen</h2>
            <Markdown>{introduccion}</Markdown>
        </article>
    );
}
