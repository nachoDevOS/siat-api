import { createContext, useContext } from 'react';

/**
 * Lo que comparten todas las paginas: los datos del servidor y como armar
 * un enlace interno de la documentacion.
 */
export const ContextoDocs = createContext(null);

export const useDocs = () => useContext(ContextoDocs);
