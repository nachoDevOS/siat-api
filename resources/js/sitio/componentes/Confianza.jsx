import { Key, Fingerprint, ShieldCheck, Lock, Buildings } from '@phosphor-icons/react';
import Revelar from './Revelar.jsx';

const garantias = [
    { icono: Key, titulo: 'Un token por empresa', texto: 'Con perfiles: una caja emite y consulta, pero no anula. Se puede limitar por IP y por fecha.' },
    { icono: Fingerprint, titulo: 'Firma digital de cada factura', texto: 'El XML se firma con el certificado de su empresa, guardado cifrado.' },
    { icono: ShieldCheck, titulo: 'Avisos firmados', texto: 'Cada webhook lleva una firma HMAC para que su sistema compruebe que salió de acá.' },
    { icono: Lock, titulo: 'Pruebas separadas de lo real', texto: 'El token de pruebas nunca emite facturas con validez fiscal.' },
    { icono: Buildings, titulo: 'Sus datos, solo suyos', texto: 'Cada empresa ve únicamente sus sucursales, puntos de venta y facturas.' },
];

export default function Confianza() {
    return (
        <section className="seccion seccion-alterna">
            <div className="contenedor">
                <Revelar className="encabezado-centrado">
                    <h2 className="titulo-seccion">Pensado para manejar documentos fiscales</h2>
                </Revelar>
                <div className="garantias">
                    {garantias.map(({ icono: Icono, titulo, texto }, i) => (
                        <Revelar key={titulo} className="garantia" retraso={i * 60}>
                            <span className="garantia-icono"><Icono size={22} weight="duotone" /></span>
                            <div>
                                <h3>{titulo}</h3>
                                <p>{texto}</p>
                            </div>
                        </Revelar>
                    ))}
                </div>
            </div>
        </section>
    );
}
