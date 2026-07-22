<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\Factura;
use App\Models\PuntoVenta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Factura>
 */
class FacturaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'punto_venta_id' => PuntoVenta::factory(),
            'cufd_id' => null,
            'cafc_id' => null,
            'cuf' => strtoupper(fake()->bothify('########????')),
            'numero_factura' => fake()->numberBetween(1, 9999),
            'fecha_emision' => now(),
            'comprador_tipo_documento' => 1,
            'comprador_numero_documento' => (string) fake()->numberBetween(1000000, 9999999),
            'comprador_complemento' => null,
            'comprador_razon_social' => mb_strtoupper(fake()->name()),
            'comprador_email' => fake()->safeEmail(),
            'metodo_pago' => 1,
            'numero_tarjeta' => null,
            'moneda' => 1,
            'tipo_cambio' => 1,
            'subtotal' => 150.00,
            'descuento_global' => 0,
            'gift_card' => 0,
            'anticipo' => 0,
            'monto_total' => 150.00,
            'monto_total_moneda' => 150.00,
            'monto_total_sujeto_iva' => 150.00,
            'leyenda' => 'Ley N 453: El proveedor debe habilitar medios para el reclamo.',
            'usuario' => 'caja-01',
            'codigo_documento_sector' => 1,
            'tipo_emision' => Factura::EMISION_EN_LINEA,
            'estado' => Factura::ESTADO_PENDIENTE,
            'referencia_externa' => 'VTA-'.fake()->unique()->numerify('######'),
        ];
    }

    public function validada(): static
    {
        return $this->state(fn (): array => [
            'estado' => Factura::ESTADO_VALIDADA,
            'validada_en' => now(),
            'codigo_recepcion' => (string) fake()->numberBetween(1000000000, 9999999999),
        ]);
    }
}
