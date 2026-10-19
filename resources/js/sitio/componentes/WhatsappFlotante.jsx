import { motion, useReducedMotion } from 'motion/react';
import { WhatsappLogo } from '@phosphor-icons/react';

/**
 * Acceso directo a WhatsApp, siempre a mano, como en el sitio de la marca.
 * Solo aparece si el numero esta configurado.
 */
export default function WhatsappFlotante({ numero, marca }) {
    const reducir = useReducedMotion();

    if (!numero) {
        return null;
    }

    const texto = encodeURIComponent(`Hola ${marca}, quiero información sobre la facturación electrónica en línea.`);

    return (
        <motion.a
            href={`https://wa.me/${numero}?text=${texto}`}
            target="_blank"
            rel="noopener"
            className="whatsapp-flotante"
            aria-label="Escribir por WhatsApp"
            initial={reducir ? false : { opacity: 0, scale: 0.6, y: 20 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            transition={{ delay: 1.2, type: 'spring', stiffness: 220, damping: 16 }}
            whileHover={{ y: -3 }}
            whileTap={{ scale: 0.95 }}
        >
            <WhatsappLogo size={22} weight="fill" />
            <span>WhatsApp</span>
        </motion.a>
    );
}
