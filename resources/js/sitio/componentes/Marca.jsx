/**
 * Logo y nombre de la marca. Si la imagen no carga, queda solo el nombre.
 */
export default function Marca({ marca, logo, subtitulo }) {
    return (
        <>
            {logo && (
                <img
                    src={logo}
                    alt=""
                    className="marca-logo"
                    width="40"
                    height="40"
                    onError={(e) => { e.currentTarget.style.display = 'none'; }}
                />
            )}
            <span>
                {marca}
                {subtitulo && <small>{subtitulo}</small>}
            </span>
        </>
    );
}
