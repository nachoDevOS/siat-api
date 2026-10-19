import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
// Tipografias de la marca, servidas desde el build.
import '@fontsource/poppins/600.css';
import '@fontsource/poppins/700.css';
import '@fontsource-variable/inter';
import '@fontsource-variable/jetbrains-mono';
import App from './App.jsx';
import './docs.css';

// Lo que necesita del servidor (marca, URL de la API, descargas) llega
// incrustado en la vista Blade.
const datos = JSON.parse(document.getElementById('docs-datos').textContent);

createRoot(document.getElementById('docs')).render(
    <StrictMode>
        <App datos={datos} />
    </StrictMode>,
);
