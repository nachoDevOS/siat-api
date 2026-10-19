import { useEffect, useRef, useState } from 'react';
import { AnimatePresence, motion, useInView, useReducedMotion } from 'motion/react';
import { CheckCircle, QrCode } from '@phosphor-icons/react';

/**
 * Cuenta el producto en cuatro segundos: se escribe la peticion real, vuelve
 * el 201 con el CUF y sale el ticket ya validado. Corre cuando esta a la
 * vista y se repite con pausa; con "reducir movimiento" muestra el final.
 * Los datos son de ejemplo.
 */
const PETICION = `POST /api/v1/facturas
Authorization: Bearer siat_live_••••••
X-Idempotency-Key: VENTA-000123

{
  "sucursal": 0, "punto_venta": 0,
  "comprador": {
    "tipo_documento": 5,
    "numero_documento": "1023456028",
    "razon_social": "FERRETERIA EL SOL SRL"
  },
  "metodo_pago": 1,
  "items": [{
    "descripcion": "Martillo 16 oz",
    "cantidad": 2, "precio_unitario": 45.50
  }]
}`;

const RESPUESTA = `HTTP/1.1 201 Created
{
  "cuf": "46C9E2A0F1B7D30C5A8E...",
  "numero_factura": 1842,
  "estado": "VALIDADA",
  "url_publica_rollo": "https://.../rollo"
}`;

export default function DemoEmision() {
    const reducir = useReducedMotion();
    const ref = useRef(null);
    const visible = useInView(ref, { amount: 0.4 });
    const [escrito, setEscrito] = useState(reducir ? PETICION.length : 0);
    const [fase, setFase] = useState(reducir ? 3 : 0);
    const [vuelta, setVuelta] = useState(0);

    useEffect(() => {
        if (reducir || !visible) {
            return undefined;
        }

        // 0 escribiendo, 1 enviando, 2 respuesta, 3 ticket; y otra vuelta.
        const timers = [];
        let i = 0;
        setEscrito(0);
        setFase(0);

        // 25 cuadros por segundo alcanzan para que se lea como tipeo y
        // cuestan la mitad que 60: en celulares de gama media se notaba.
        const escribir = setInterval(() => {
            i += 15;
            setEscrito(Math.min(i, PETICION.length));

            if (i >= PETICION.length) {
                clearInterval(escribir);
                timers.push(setTimeout(() => setFase(1), 250));
                timers.push(setTimeout(() => setFase(2), 1050));
                timers.push(setTimeout(() => setFase(3), 1800));
                timers.push(setTimeout(() => setVuelta((v) => v + 1), 8000));
            }
        }, 40);

        return () => {
            clearInterval(escribir);
            timers.forEach(clearTimeout);
        };
    }, [visible, reducir, vuelta]);

    return (
        <div ref={ref} className="demo" aria-label="Ejemplo de emisión de una factura">
            <div className="codigo demo-codigo">
                <div className="codigo-barra">
                    <span className="demo-puntos" aria-hidden="true"><i /><i /><i /></span>
                    <span className="demo-titulo">Su sistema → API</span>
                    <span className={`demo-estado fase-${fase}`}>
                        {fase === 0 && 'Escribiendo...'}
                        {fase === 1 && 'Enviando al SIAT'}
                        {fase >= 2 && '201 Created'}
                    </span>
                </div>
                <pre aria-hidden="true">
                    <code>
                        {PETICION.slice(0, escrito)}
                        {fase === 0 && <span className="demo-cursor" />}
                    </code>
                </pre>
                <AnimatePresence>
                    {fase >= 2 && (
                        <motion.pre
                            className="demo-respuesta"
                            aria-hidden="true"
                            initial={reducir ? false : { opacity: 0, height: 0 }}
                            animate={{ opacity: 1, height: 'auto' }}
                            exit={{ opacity: 0, height: 0 }}
                            transition={{ duration: 0.45, ease: [0.16, 1, 0.3, 1] }}
                        >
                            <code>{RESPUESTA}</code>
                        </motion.pre>
                    )}
                </AnimatePresence>
            </div>

            <AnimatePresence>
                {fase >= 3 && (
                    <motion.div
                        className="ticket"
                        initial={reducir ? false : { opacity: 0, y: 40, rotate: 0 }}
                        animate={{ opacity: 1, y: 0, rotate: -4 }}
                        exit={{ opacity: 0, y: 30 }}
                        transition={{ type: 'spring', stiffness: 160, damping: 18 }}
                    >
                        <div className="ticket-sello"><CheckCircle size={16} weight="fill" /> Validada por el SIN</div>
                        <strong>FACTURA N° 1842</strong>
                        <small>Con derecho a crédito fiscal</small>
                        <div className="ticket-linea"><span>2 × Martillo 16 oz</span><span>91.00</span></div>
                        <div className="ticket-total"><span>TOTAL Bs</span><span>91.00</span></div>
                        <QrCode size={64} weight="regular" className="ticket-qr" />
                        <small className="ticket-pie">Ticket 80 mm listo para imprimir</small>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}
