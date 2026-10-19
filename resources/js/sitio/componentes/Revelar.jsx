import { motion, useReducedMotion } from 'motion/react';

/**
 * Aparece suave al entrar en pantalla, una sola vez: marca la lectura de la
 * pagina seccion por seccion. Con "reducir movimiento" se muestra quieto.
 */
export default function Revelar({ children, como = 'div', retraso = 0, className = '', ...resto }) {
    const reducir = useReducedMotion();
    const Etiqueta = motion[como];

    return (
        <Etiqueta
            className={className}
            initial={reducir ? false : { opacity: 0, y: 22 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true, amount: 0.2 }}
            transition={{ duration: 0.7, delay: retraso / 1000, ease: [0.16, 1, 0.3, 1] }}
            {...resto}
        >
            {children}
        </Etiqueta>
    );
}
