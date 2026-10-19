import Marca from './Marca.jsx';

export default function Pie({ datos }) {
    return (
        <footer className="pie">
            <div className="contenedor pie-fila">
                <span className="marca">
                    <Marca marca={datos.marca} logo={datos.logo} />
                </span>
                <nav aria-label="Pie" className="pie-enlaces">
                    <a href={datos.rutas.documentacion}>Documentación</a>
                    <a href="#preguntas">Preguntas</a>
                    <a href="#contacto">Contacto</a>
                </nav>
                <small>Facturación electrónica en línea ante el SIAT de Impuestos Nacionales de Bolivia.</small>
            </div>
        </footer>
    );
}
