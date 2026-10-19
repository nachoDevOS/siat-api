import { useRef } from 'react';
import { motion, useReducedMotion, useScroll, useSpring } from 'motion/react';
import { Plugs, Receipt, Bell } from '@phosphor-icons/react';
import Revelar from './Revelar.jsx';

const pasos = [
    {
        icono: Plugs,
        titulo: 'Conecte su sistema',
        texto: 'Le entregamos un token para su empresa. Primero integra contra el SIAT piloto, sin validez fiscal, y después cambia el token por el de producción.',
    },
    {
        icono: Receipt,
        titulo: 'Emita con una llamada',
        texto: 'Manda la venta y recibe el CUF al instante. CUIS, CUFD, XML, firma digital y envío al SIN corren de nuestro lado.',
    },
    {
        icono: Bell,
        titulo: 'Entérese del resultado',
        texto: 'El SIN valida en segundos y su sistema recibe el aviso por webhook. Si el SIAT se cae, la venta sigue y la factura viaja después.',
    },
];

export default function ComoFunciona() {
    const ref = useRef(null);
    const reducir = useReducedMotion();

    // La linea que une los pasos se llena mientras se recorre la seccion:
    // marca el orden en que pasan las cosas.
    const { scrollYProgress } = useScroll({ target: ref, offset: ['start 75%', 'end 55%'] });
    const progreso = useSpring(scrollYProgress, { stiffness: 90, damping: 24 });

    return (
        <section id="como-funciona" className="seccion">
            <div className="contenedor">
                <Revelar className="encabezado-centrado">
                    <span className="etiqueta">Cómo funciona</span>
                    <h2 className="titulo-seccion">Tres pasos, y su sistema no toca el SOAP del SIN</h2>
                </Revelar>

                <div className="pasos-envoltura" ref={ref}>
                    <div className="pasos-pista" aria-hidden="true">
                        <motion.div className="pasos-progreso" style={{ scaleX: reducir ? 1 : progreso }} />
                    </div>
                    <ol className="pasos">
                        {pasos.map(({ icono: Icono, titulo, texto }, i) => (
                            <Revelar como="li" key={titulo} className="paso" retraso={i * 120}>
                                <span className="paso-icono"><Icono size={26} weight="duotone" /></span>
                                <h3>{titulo}</h3>
                                <p>{texto}</p>
                            </Revelar>
                        ))}
                    </ol>
                </div>
            </div>
        </section>
    );
}
