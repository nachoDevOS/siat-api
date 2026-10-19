<?php

namespace App\Models\Sitio;

use Database\Factories\Sitio\SolicitudContactoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Pedido de contacto que llega desde el formulario del sitio publico.
 */
class SolicitudContacto extends Model
{
    /** @use HasFactory<SolicitudContactoFactory> */
    use HasFactory;

    /**
     * Lo que puede buscar el interesado, con su etiqueta para el sitio y el panel.
     *
     * @var array<string, string>
     */
    public const INTERESES = [
        'integrar' => 'Conectar mi sistema de ventas a la API',
        'sin_sistema' => 'Facturar y todavia no tengo sistema',
        'proveedor' => 'Soy desarrollador y quiero ofrecerlo a mis clientes',
        'otro' => 'Otra consulta',
    ];

    protected $table = 'solicitudes_contacto';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'atendida_en' => 'datetime',
        ];
    }

    public function estaAtendida(): bool
    {
        return $this->atendida_en !== null;
    }

    public function etiquetaInteres(): string
    {
        return self::INTERESES[$this->interes] ?? $this->interes;
    }
}
