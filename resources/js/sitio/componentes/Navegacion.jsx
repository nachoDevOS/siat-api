import { useState } from 'react';
import { List, X } from '@phosphor-icons/react';
import Marca from './Marca.jsx';

const enlaces = [
    { href: '#como-funciona', texto: 'Cómo funciona' },
    { href: '#funciones', texto: 'Funciones' },
    { href: '#desarrolladores', texto: 'Desarrolladores' },
    { href: '#preguntas', texto: 'Preguntas' },
];

export default function Navegacion({ datos }) {
    const [abierta, setAbierta] = useState(false);
    const cerrar = () => setAbierta(false);

    return (
        <header className="nav">
            <div className="contenedor nav-fila">
                <a href="#inicio" className="marca" onClick={cerrar}>
                    <Marca marca={datos.marca} logo={datos.logo} subtitulo="Facturación electrónica SIAT" />
                </a>

                <nav aria-label="Principal" className={abierta ? 'nav-enlaces abierta' : 'nav-enlaces'}>
                    {enlaces.map((e) => (
                        <a key={e.href} href={e.href} onClick={cerrar}>{e.texto}</a>
                    ))}
                    <a href="#contacto" className="boton boton-primario nav-cta" onClick={cerrar}>Contactar</a>
                </nav>

                <button
                    type="button"
                    className="nav-menu"
                    aria-expanded={abierta}
                    aria-label={abierta ? 'Cerrar menú' : 'Abrir menú'}
                    onClick={() => setAbierta((a) => !a)}
                >
                    {abierta ? <X size={24} /> : <List size={24} />}
                </button>
            </div>
        </header>
    );
}
