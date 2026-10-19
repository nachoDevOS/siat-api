import { useState } from 'react';
import { motion } from 'motion/react';
import { EnvelopeSimple, PaperPlaneTilt, WhatsappLogo, Check } from '@phosphor-icons/react';
import Revelar from './Revelar.jsx';

const vacio = { nombre: '', empresa: '', correo: '', telefono: '', interes: '', mensaje: '', sitio_web: '' };

/**
 * Formulario de contacto: lo guarda el servidor (/contacto) para el panel.
 * Estados: escribiendo, enviando, enviado, y errores por campo debajo de
 * cada uno. WhatsApp y correo solo aparecen si estan configurados.
 */
export default function Contacto({ datos }) {
    const [valores, setValores] = useState(vacio);
    const [errores, setErrores] = useState({});
    const [estado, setEstado] = useState('listo');
    const [aviso, setAviso] = useState('');

    const cambiar = (e) => setValores((v) => ({ ...v, [e.target.name]: e.target.value }));

    const enviar = async (e) => {
        e.preventDefault();
        setEstado('enviando');
        setErrores({});
        setAviso('');

        try {
            const respuesta = await fetch(datos.rutas.contacto, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(valores),
            });
            const cuerpo = await respuesta.json().catch(() => ({}));

            if (respuesta.status === 201) {
                setEstado('enviado');
                setAviso(cuerpo.mensaje);
                setValores(vacio);
                return;
            }

            setEstado('listo');

            if (respuesta.status === 422) {
                setErrores(Object.fromEntries(Object.entries(cuerpo.errors ?? {}).map(([campo, lista]) => [campo, lista[0]])));
                return;
            }

            setAviso(respuesta.status === 429
                ? 'Recibimos varios mensajes seguidos. Espere un minuto e intente de nuevo.'
                : 'No se pudo enviar el mensaje. Intente de nuevo en unos minutos.');
        } catch {
            setEstado('listo');
            setAviso('No hay conexión. Revise su internet e intente de nuevo.');
        }
    };

    const campo = (nombre, etiqueta, props = {}) => (
        <div className={`campo ${props.ancho ? 'campo-ancho' : ''}`}>
            <label htmlFor={`contacto-${nombre}`}>{etiqueta}</label>
            {props.como === 'textarea' ? (
                <textarea
                    id={`contacto-${nombre}`}
                    name={nombre}
                    rows={4}
                    value={valores[nombre]}
                    onChange={cambiar}
                    aria-invalid={Boolean(errores[nombre])}
                    aria-describedby={errores[nombre] ? `error-${nombre}` : undefined}
                    required={props.requerido}
                />
            ) : (
                <input
                    id={`contacto-${nombre}`}
                    name={nombre}
                    type={props.tipo ?? 'text'}
                    autoComplete={props.autocompletar}
                    value={valores[nombre]}
                    onChange={cambiar}
                    aria-invalid={Boolean(errores[nombre])}
                    aria-describedby={errores[nombre] ? `error-${nombre}` : undefined}
                    required={props.requerido}
                />
            )}
            {errores[nombre] && <span id={`error-${nombre}`} className="campo-error">{errores[nombre]}</span>}
        </div>
    );

    return (
        <section id="contacto" className="seccion seccion-alterna">
            <div className="contenedor contacto-rejilla">
                <Revelar className="contacto-texto">
                    <h2 className="titulo-seccion">Cuéntenos de su sistema</h2>
                    <p className="parrafo">
                        Le respondemos con los pasos para conectarlo y un token de pruebas para empezar a integrar.
                    </p>
                    {(datos.whatsapp || datos.correo) && (
                        <ul className="canales">
                            {datos.whatsapp && (
                                <li>
                                    <a className="canal" href={`https://wa.me/${datos.whatsapp}`} target="_blank" rel="noopener">
                                        <WhatsappLogo size={22} weight="duotone" /> Escribir por WhatsApp
                                    </a>
                                </li>
                            )}
                            {datos.correo && (
                                <li>
                                    <a className="canal" href={`mailto:${datos.correo}`}>
                                        <EnvelopeSimple size={22} weight="duotone" /> {datos.correo}
                                    </a>
                                </li>
                            )}
                        </ul>
                    )}
                </Revelar>

                <Revelar retraso={100}>
                    {estado === 'enviado' ? (
                        <div className="formulario formulario-enviado" role="status">
                            <motion.span
                                className="enviado-icono"
                                initial={{ scale: 0.4, opacity: 0 }}
                                animate={{ scale: 1, opacity: 1 }}
                                transition={{ type: 'spring', stiffness: 260, damping: 16 }}
                            >
                                <Check size={30} weight="bold" />
                            </motion.span>
                            <h3>Mensaje enviado</h3>
                            <p>{aviso}</p>
                            <button type="button" className="boton boton-secundario" onClick={() => setEstado('listo')}>
                                Escribir otro mensaje
                            </button>
                        </div>
                    ) : (
                        <form className="formulario" onSubmit={enviar} noValidate>
                            <div className="campos">
                                {campo('nombre', 'Nombre', { requerido: true, autocompletar: 'name' })}
                                {campo('empresa', 'Empresa (opcional)', { autocompletar: 'organization' })}
                                {campo('correo', 'Correo', { tipo: 'email', requerido: true, autocompletar: 'email' })}
                                {campo('telefono', 'Teléfono (opcional)', { tipo: 'tel', autocompletar: 'tel' })}

                                <div className="campo campo-ancho">
                                    <label htmlFor="contacto-interes">¿Qué busca?</label>
                                    <select
                                        id="contacto-interes"
                                        name="interes"
                                        value={valores.interes}
                                        onChange={cambiar}
                                        aria-invalid={Boolean(errores.interes)}
                                        required
                                    >
                                        <option value="" disabled>Elija una opción</option>
                                        {Object.entries(datos.intereses).map(([clave, etiqueta]) => (
                                            <option key={clave} value={clave}>{etiqueta}</option>
                                        ))}
                                    </select>
                                    {errores.interes && <span className="campo-error">{errores.interes}</span>}
                                </div>

                                {campo('mensaje', 'Mensaje', { como: 'textarea', requerido: true, ancho: true })}

                                {/* Trampa para bots: oculta para las personas. */}
                                <div className="trampa" aria-hidden="true">
                                    <label htmlFor="contacto-sitio_web">Sitio web</label>
                                    <input id="contacto-sitio_web" name="sitio_web" tabIndex={-1} autoComplete="off" value={valores.sitio_web} onChange={cambiar} />
                                </div>
                            </div>

                            {aviso && <p className="formulario-aviso" role="alert">{aviso}</p>}

                            <button type="submit" className="boton boton-primario" disabled={estado === 'enviando'}>
                                {estado === 'enviando' ? 'Enviando...' : <>Enviar mensaje <PaperPlaneTilt size={18} weight="bold" /></>}
                            </button>
                        </form>
                    )}
                </Revelar>
            </div>
        </section>
    );
}
