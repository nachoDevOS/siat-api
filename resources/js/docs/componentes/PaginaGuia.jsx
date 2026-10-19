import Markdown from './Markdown.jsx';

/** Una seccion de la guia de integracion (API.md). */
export default function PaginaGuia({ pagina }) {
    return (
        <article className="pagina">
            <header className="pagina-cabeza">
                <span className="pagina-grupo">{pagina.grupo}</span>
                <h1>{pagina.titulo}</h1>
            </header>
            <Markdown>{pagina.markdown}</Markdown>
        </article>
    );
}
