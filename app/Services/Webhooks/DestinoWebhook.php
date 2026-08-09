<?php

namespace App\Services\Webhooks;

/**
 * Decide si una URL de webhook es un destino seguro al que podemos hacer POST.
 *
 * La URL la escribe el cliente en el panel, asi que es entrada no confiable: si
 * no se filtra, alguien puede apuntarla a http://127.0.0.1 o al servicio de
 * metadatos del proveedor cloud (169.254.169.254) y usar nuestro servidor como
 * proxy hacia la red interna. Eso es SSRF.
 *
 * La comprobacion resuelve el host por DNS y exige que TODAS sus direcciones
 * sean publicas. No cubre el rebinding de DNS (que la IP cambie entre esta
 * comprobacion y la conexion real); para eso haria falta fijar la IP en el
 * cliente HTTP, que es el siguiente paso si el modelo de amenaza lo pide.
 */
class DestinoWebhook
{
    /**
     * Rangos que jamas deben alcanzarse desde un webhook, mas alla de los que
     * ya descarta FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE.
     *
     * @var list<string>
     */
    private const BLOQUEADOS = [
        // Metadatos de instancia en AWS/GCP/Azure: la fuga clasica de credenciales.
        '169.254.169.254',
    ];

    /**
     * Devuelve el motivo por el que la URL NO es aceptable, o null si lo es.
     */
    public function motivoRechazo(string $url): ?string
    {
        $partes = parse_url($url);

        if ($partes === false || blank($partes['host'] ?? null)) {
            return 'La URL del webhook no es valida.';
        }

        $esquema = strtolower($partes['scheme'] ?? '');

        if (! in_array($esquema, ['http', 'https'], true)) {
            return "Esquema no permitido en el webhook: '{$esquema}'.";
        }

        // En desarrollo se apunta a localhost a proposito; en produccion no.
        if (config('siat.webhooks.permitir_destinos_privados')) {
            return null;
        }

        if ($esquema !== 'https') {
            return 'El webhook debe usar https.';
        }

        $host = $partes['host'];
        $direcciones = $this->resolver($host);

        if ($direcciones === []) {
            return "No se pudo resolver el host del webhook: '{$host}'.";
        }

        foreach ($direcciones as $ip) {
            if (! $this->esPublica($ip)) {
                return "El webhook apunta a una direccion interna ({$ip}).";
            }
        }

        return null;
    }

    public function esAceptable(string $url): bool
    {
        return $this->motivoRechazo($url) === null;
    }

    /**
     * Direcciones IP del host. Si el host ya es una IP literal, se devuelve tal
     * cual sin consultar DNS.
     *
     * @return list<string>
     */
    private function resolver(string $host): array
    {
        // Los hosts IPv6 vienen entre corchetes en la URL.
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return gethostbynamel($host) ?: [];
    }

    private function esPublica(string $ip): bool
    {
        if (in_array($ip, self::BLOQUEADOS, true)) {
            return false;
        }

        // NO_PRIV_RANGE descarta 10/8, 172.16/12, 192.168/16 y fc00::/7.
        // NO_RES_RANGE descarta loopback, link-local y demas reservados.
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
