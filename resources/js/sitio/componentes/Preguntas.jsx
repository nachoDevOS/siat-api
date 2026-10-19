import { useState } from 'react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { Plus } from '@phosphor-icons/react';
import Revelar from './Revelar.jsx';

const preguntas = [
    {
        p: '¿Qué modalidad de facturación usa?',
        r: 'Facturación electrónica en línea ante el SIAT de Impuestos Nacionales, con firma digital. Hoy cubre la factura de compra-venta y la nota de crédito-débito.',
    },
    {
        p: '¿Necesito tener mi propio sistema de ventas?',
        r: 'La API está pensada para conectar un sistema existente (POS, ERP, tienda en línea). Si todavía no tiene uno, escríbanos y vemos cómo ayudarle.',
    },
    {
        p: '¿Qué pasa si se cae el internet o el SIAT?',
        r: 'La venta no se detiene. Se abre el evento de contingencia, las facturas se emiten fuera de línea con validez y se envían en paquete cuando vuelve el servicio.',
    },
    {
        p: '¿Puedo probar antes de facturar de verdad?',
        r: 'Sí. Con un token de pruebas su sistema emite contra el SIAT piloto con el mismo flujo, sin validez fiscal. Para pasar a producción solo cambia el token.',
    },
    {
        p: '¿Quién hace los trámites ante Impuestos Nacionales?',
        r: 'Le acompañamos en el alta: certificado digital, sucursales, puntos de venta y las pruebas del piloto que pide el SIN.',
    },
    {
        p: '¿Cómo imprimo la factura?',
        r: 'La respuesta de la emisión trae enlaces públicos: hoja carta, ticket de 80 mm, mini ticket con QR y ZIP. Su sistema solo abre el que necesite.',
    },
];

export default function Preguntas() {
    const reducir = useReducedMotion();
    const [abierta, setAbierta] = useState(null);

    return (
        <section id="preguntas" className="seccion">
            <div className="contenedor contenedor-angosto">
                <Revelar className="encabezado-centrado">
                    <h2 className="titulo-seccion">Preguntas frecuentes</h2>
                </Revelar>
                <div className="preguntas">
                    {preguntas.map(({ p, r }, i) => {
                        const abierto = abierta === i;

                        return (
                            <Revelar key={p} retraso={i * 50} className={abierto ? 'pregunta abierta' : 'pregunta'}>
                                <button
                                    type="button"
                                    aria-expanded={abierto}
                                    aria-controls={`respuesta-${i}`}
                                    onClick={() => setAbierta(abierto ? null : i)}
                                >
                                    {p}
                                    <motion.span className="pregunta-icono" animate={{ rotate: abierto ? 45 : 0 }}>
                                        <Plus size={20} weight="bold" />
                                    </motion.span>
                                </button>
                                <AnimatePresence initial={false}>
                                    {abierto && (
                                        <motion.div
                                            id={`respuesta-${i}`}
                                            className="pregunta-respuesta"
                                            initial={reducir ? false : { height: 0, opacity: 0 }}
                                            animate={{ height: 'auto', opacity: 1 }}
                                            exit={{ height: 0, opacity: 0 }}
                                            transition={{ duration: 0.3, ease: [0.16, 1, 0.3, 1] }}
                                        >
                                            <p>{r}</p>
                                        </motion.div>
                                    )}
                                </AnimatePresence>
                            </Revelar>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}
