/**
 * Franja de logos de lo que puede consumir la API: cualquier sistema que se
 * conecte por HTTPS. Logos reales de Simple Icons, en un solo tono que funciona en
 * claro y oscuro. Es la unica franja en movimiento de la pagina.
 */
const logos = [
    ['php', 'PHP'], ['laravel', 'Laravel'], ['nodedotjs', 'Node.js'], ['python', 'Python'],
    ['dotnet', '.NET'], ['openjdk', 'Java'], ['go', 'Go'], ['react', 'React'],
    ['vuedotjs', 'Vue'], ['flutter', 'Flutter'], ['woocommerce', 'WooCommerce'], ['odoo', 'Odoo'],
];

export default function Integraciones() {
    const fila = logos.map(([slug, nombre]) => (
        <img key={slug} src={`https://cdn.simpleicons.org/${slug}/8a8f98`} alt={nombre} title={nombre} width="34" height="34" loading="lazy" />
    ));

    return (
        <section className="integraciones" aria-label="Se integra con">
            <p>Se conecta desde cualquier sistema por HTTPS, con conexión cifrada</p>
            <div className="marquesina">
                <div className="marquesina-pista">
                    {fila}
                    <span aria-hidden="true" className="marquesina-copia">{fila}</span>
                </div>
            </div>
        </section>
    );
}
