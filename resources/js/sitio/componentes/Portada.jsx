import { motion, useMotionValue, useReducedMotion, useSpring, useTransform } from 'motion/react';
import { ArrowRight, Receipt } from '@phosphor-icons/react';
import BotonMagnetico from './BotonMagnetico.jsx';
import DemoEmision from './DemoEmision.jsx';

const palabras = ['Facturación', 'electrónica', 'en', 'línea,', 'desde', 'su', 'propio', 'sistema'];

// Posicion (top, left) y retraso de cada cuadrito del fondo.
const chispas = [['14%', '4%', 0], ['22%', '47%', 1.2], ['68%', '3%', 0.6], ['12%', '88%', 2], ['78%', '52%', 1.6], ['58%', '95%', 0.9]];

export default function Portada({ datos }) {
    const reducir = useReducedMotion();

    // La aurora del fondo sigue apenas al cursor: da profundidad sin distraer.
    const px = useMotionValue(0);
    const py = useMotionValue(0);
    const ax = useSpring(useTransform(px, (v) => v * 40), { stiffness: 40, damping: 20 });
    const ay = useSpring(useTransform(py, (v) => v * 30), { stiffness: 40, damping: 20 });

    const mover = (e) => {
        // En pantallas tactiles no hay cursor que seguir: solo costaria.
        if (reducir || e.pointerType !== 'mouse') {
            return;
        }
        const caja = e.currentTarget.getBoundingClientRect();
        px.set((e.clientX - caja.left) / caja.width - 0.5);
        py.set((e.clientY - caja.top) / caja.height - 0.5);
    };

    return (
        <section id="inicio" className="portada" onPointerMove={mover}>
            <motion.div className="aurora" style={{ x: ax, y: ay }} aria-hidden="true" />
            <div className="rejilla-fondo" aria-hidden="true" />
            {/* Cuadritos luminosos de la marca, flotando apenas. */}
            <div className="chispas" aria-hidden="true">
                {chispas.map(([top, left, retraso], i) => (
                    <motion.span
                        key={i}
                        style={{ top, left }}
                        animate={reducir ? undefined : { y: [0, -14, 0], opacity: [0.35, 0.8, 0.35] }}
                        transition={{ duration: 5 + i, repeat: Infinity, delay: retraso, ease: 'easeInOut' }}
                    />
                ))}
            </div>

            <div className="contenedor portada-rejilla">
                <div className="portada-texto">
                    <motion.span
                        className="chip"
                        initial={reducir ? false : { opacity: 0, y: -10 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.6 }}
                    >
                        <Receipt size={16} weight="duotone" /> {datos.marca} | Facturación electrónica SIAT
                    </motion.span>
                    <h1>
                        {palabras.map((palabra, i) => [
                            <motion.span
                                key={palabra + i}
                                className={i < 2 ? 'palabra texto-degradado' : 'palabra'}
                                initial={reducir ? false : { opacity: 0, y: 28 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ duration: 0.7, delay: 0.08 * i, ease: [0.16, 1, 0.3, 1] }}
                            >
                                {palabra}
                            </motion.span>,
                            // El espacio va afuera: dentro de un inline-block se pierde.
                            ' ',
                        ])}
                    </h1>
                    <motion.p
                        className="portada-bajada"
                        initial={reducir ? false : { opacity: 0, y: 16 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.7, delay: 0.6 }}
                    >
                        Su sistema manda la venta en JSON. Nosotros hablamos con el SIAT y le devolvemos la factura firmada y lista para imprimir.
                    </motion.p>
                    <motion.div
                        className="portada-acciones"
                        initial={reducir ? false : { opacity: 0, y: 16 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.7, delay: 0.75 }}
                    >
                        <BotonMagnetico href="#contacto" className="boton boton-primario boton-grande">
                            Contactar <ArrowRight size={18} weight="bold" />
                        </BotonMagnetico>
                        <a href={datos.rutas.documentacion} className="boton boton-secundario boton-grande">
                            Ver documentación
                        </a>
                    </motion.div>
                </div>

                <motion.div
                    initial={reducir ? false : { opacity: 0, y: 30, scale: 0.98 }}
                    animate={{ opacity: 1, y: 0, scale: 1 }}
                    transition={{ duration: 0.9, delay: 0.3, ease: [0.16, 1, 0.3, 1] }}
                >
                    <DemoEmision />
                </motion.div>
            </div>
        </section>
    );
}
