import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
// Tipografias de la marca (Solucion Digital): Poppins en titulos, Inter en
// el texto. Servidas desde el build, sin depender de Google Fonts.
import '@fontsource/poppins/600.css';
import '@fontsource/poppins/700.css';
import '@fontsource-variable/inter';
import '@fontsource-variable/jetbrains-mono';
import App from './App.jsx';
import './sitio.css';

// Los datos del servidor (marca, contacto, rutas) llegan incrustados en la
// vista Blade: la pagina no hace peticiones al cargar.
const datos = JSON.parse(document.getElementById('sitio-datos').textContent);

createRoot(document.getElementById('sitio')).render(
    <StrictMode>
        <App datos={datos} />
    </StrictMode>,
);
