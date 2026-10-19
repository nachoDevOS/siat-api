import { CloudSlash, ArrowUUpLeft, Stack, Printer, Flask, ArrowsClockwise } from '@phosphor-icons/react';
import Revelar from './Revelar.jsx';
import DemoContingencia from './DemoContingencia.jsx';

/**
 * Rejilla de funciones con celdas de distinto tamano: la contingencia y la
 * factura lista para imprimir son lo que mas pesa al elegir proveedor.
 */
const funciones = [
    {
        icono: CloudSlash,
        titulo: 'La venta nunca se frena',
        texto: 'Si el SIAT deja de responder, el sistema abre el evento de contingencia, emite fuera de línea y envía los paquetes cuando el servicio vuelve. Sin que su cajero haga nada.',
        clase: 'celda-grande',
        demo: true,
    },
    {
        icono: ArrowUUpLeft,
        titulo: 'Devoluciones y anulaciones',
        texto: 'Notas de crédito-débito por línea, anulación, reversión y anulación administrativa.',
        clase: '',
    },
    {
        icono: Stack,
        titulo: 'Volumen alto',
        texto: 'Lotes de hasta 20 ventas por petición y emisión masiva en paquetes de 500.',
        clase: '',
    },
    {
        icono: Flask,
        titulo: 'Ambiente de pruebas propio',
        texto: 'Un token de pruebas emite contra el SIAT piloto con el mismo flujo, sin validez fiscal.',
        clase: 'celda-ancha celda-patron',
    },
    {
        icono: Printer,
        titulo: 'Factura lista para imprimir',
        texto: 'Hoja carta, ticket de 80 mm, mini ticket con QR o un ZIP con el XML firmado. Enlaces públicos para compartir por WhatsApp.',
        clase: 'celda-ancha',
    },
    {
        icono: ArrowsClockwise,
        titulo: 'Códigos al día, solos',
        texto: 'CUIS, CUFD y catálogos del SIN se renuevan solos. Usted no administra ningún código.',
        clase: 'celda-ancha',
    },
];

/**
 * El brillo de la tarjeta sigue al cursor. Se escribe directo en variables
 * CSS del elemento: nada de estado de React en cada movimiento.
 */
const seguirCursor = (e) => {
    const caja = e.currentTarget.getBoundingClientRect();
    e.currentTarget.style.setProperty('--mx', `${e.clientX - caja.left}px`);
    e.currentTarget.style.setProperty('--my', `${e.clientY - caja.top}px`);
};

export default function Funciones() {
    return (
        <section id="funciones" className="seccion seccion-alterna">
            <div className="contenedor">
                <Revelar className="encabezado-centrado">
                    <h2 className="titulo-seccion">Lo que su sistema deja de resolver</h2>
                    <p className="parrafo">Todo lo del SIN corre de nuestro lado. Su sistema solo vende.</p>
                </Revelar>
                <div className="bento">
                    {funciones.map(({ icono: Icono, titulo, texto, clase, demo }, i) => (
                        <Revelar key={titulo} className={`celda ${clase}`} retraso={i * 70} onPointerMove={seguirCursor}>
                            <span className="celda-icono"><Icono size={24} weight="duotone" /></span>
                            <h3>{titulo}</h3>
                            <p>{texto}</p>
                            {demo && <DemoContingencia />}
                        </Revelar>
                    ))}
                </div>
            </div>
        </section>
    );
}
