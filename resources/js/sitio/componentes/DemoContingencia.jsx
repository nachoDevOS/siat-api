import { useEffect, useRef, useState } from 'react';
import { AnimatePresence, motion, useInView, useReducedMotion } from 'motion/react';

/**
 * La contingencia contada en vivo: en linea, cae el SIAT, se sigue
 * vendiendo fuera de linea y al volver se envia el paquete. Es lo que mas
 * pesa al elegir proveedor, por eso es la unica tarjeta que se mueve sola.
 */
const estados = [
    { color: '#22c55e', titulo: 'En línea con el SIAT', texto: 'Cada factura viaja y se valida en segundos.' },
    { color: '#f43f5e', titulo: 'El SIAT no responde', texto: 'Se abre el evento de contingencia solo.' },
    { color: '#f59e0b', titulo: 'Se sigue vendiendo', texto: 'Las facturas salen fuera de línea, válidas e imprimibles.' },
    { color: '#22d3ee', titulo: 'Servicio recuperado', texto: 'El paquete viaja al SIN y cada factura queda validada.' },
];

export default function DemoContingencia() {
    const reducir = useReducedMotion();
    const ref = useRef(null);
    const visible = useInView(ref, { amount: 0.5 });
    const [paso, setPaso] = useState(0);

    useEffect(() => {
        if (reducir || !visible) {
            return undefined;
        }
        const reloj = setInterval(() => setPaso((p) => (p + 1) % estados.length), 2400);

        return () => clearInterval(reloj);
    }, [visible, reducir]);

    const actual = estados[paso];

    return (
        <div className="contingencia" ref={ref} aria-live="polite">
            <div className="contingencia-estado">
                <motion.span className="contingencia-luz" animate={{ color: actual.color }} transition={{ duration: 0.4 }} />
                <AnimatePresence mode="wait">
                    <motion.div
                        key={paso}
                        initial={reducir ? false : { opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: -8 }}
                        transition={{ duration: 0.3 }}
                    >
                        <strong>{actual.titulo}</strong>
                        <span>{actual.texto}</span>
                    </motion.div>
                </AnimatePresence>
            </div>
            <div className="contingencia-pasos" aria-hidden="true">
                {estados.map((e, i) => <i key={e.titulo} className={i <= paso ? 'hecho' : ''} />)}
            </div>
        </div>
    );
}
