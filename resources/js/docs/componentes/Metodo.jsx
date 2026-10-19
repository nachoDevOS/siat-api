/** Insignia del metodo HTTP, con un color por metodo. */
export default function Metodo({ metodo, chico = false }) {
    return <span className={`metodo metodo-${metodo} ${chico ? 'metodo-chico' : ''}`}>{metodo.toUpperCase()}</span>;
}
