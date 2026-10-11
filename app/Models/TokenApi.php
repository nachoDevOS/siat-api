<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token de API de un sistema de ventas (tabla personal_access_tokens).
 *
 * Extiende el de Sanctum solo para leer 'ips_permitidas', la columna propia
 * que restringe desde donde se acepta el token. Se registra en
 * AppServiceProvider con Sanctum::usePersonalAccessTokenModel().
 */
class TokenApi extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected $casts = [
        'abilities' => 'json',
        'ips_permitidas' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Si el token se puede usar desde esa IP. Sin lista = desde cualquiera.
     */
    public function aceptaIp(?string $ip): bool
    {
        $permitidas = $this->ips_permitidas ?? [];

        return $permitidas === [] || in_array($ip, $permitidas, true);
    }

    public function estaVencido(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
