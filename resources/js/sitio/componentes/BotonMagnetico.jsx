import { motion, useMotionValue, useReducedMotion, useSpring } from 'motion/react';

/**
 * Boton que se inclina apenas hacia el cursor: invita a la accion principal.
 * Usa valores de movimiento (sin estado de React) para no re-renderizar.
 */
export default function BotonMagnetico({ href, className = '', children, ...resto }) {
    const reducir = useReducedMotion();
    const x = useMotionValue(0);
    const y = useMotionValue(0);
    const sx = useSpring(x, { stiffness: 220, damping: 18 });
    const sy = useSpring(y, { stiffness: 220, damping: 18 });

    const mover = (e) => {
        if (reducir) {
            return;
        }
        const caja = e.currentTarget.getBoundingClientRect();
        x.set((e.clientX - caja.left - caja.width / 2) * 0.25);
        y.set((e.clientY - caja.top - caja.height / 2) * 0.35);
    };

    const soltar = () => {
        x.set(0);
        y.set(0);
    };

    return (
        <motion.a
            href={href}
            className={className}
            style={{ x: sx, y: sy }}
            onPointerMove={mover}
            onPointerLeave={soltar}
            whileTap={{ scale: 0.97 }}
            {...resto}
        >
            {children}
        </motion.a>
    );
}
