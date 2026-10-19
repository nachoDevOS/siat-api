import Navegacion from './componentes/Navegacion.jsx';
import Portada from './componentes/Portada.jsx';
import Integraciones from './componentes/Integraciones.jsx';
import ComoFunciona from './componentes/ComoFunciona.jsx';
import Funciones from './componentes/Funciones.jsx';
import Desarrolladores from './componentes/Desarrolladores.jsx';
import Confianza from './componentes/Confianza.jsx';
import Preguntas from './componentes/Preguntas.jsx';
import Contacto from './componentes/Contacto.jsx';
import Pie from './componentes/Pie.jsx';
import WhatsappFlotante from './componentes/WhatsappFlotante.jsx';

/**
 * Portada publica con la identidad de Solucion Digital. Cada seccion es un
 * componente propio en ./componentes.
 * Sin informacion de cobro: el contacto es la unica llamada a la accion.
 */
export default function App({ datos }) {
    return (
        <>
            <Navegacion datos={datos} />
            <main>
                <Portada datos={datos} />
                <Integraciones />
                <ComoFunciona />
                <Funciones />
                <Desarrolladores datos={datos} />
                <Confianza />
                <Preguntas />
                <Contacto datos={datos} />
            </main>
            <Pie datos={datos} />
            <WhatsappFlotante numero={datos.whatsapp} marca={datos.marca} />
        </>
    );
}
