<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\Gateways\MercadoPagoGateway;
use App\Modules\Payments\Gateways\SandboxGateway;
use Illuminate\Support\Manager;

/**
 * Gateway drivers (ADR-010): `sandbox` | `mercadopago`, default from
 * config('payments.driver'). Tests may swap a driver with extend().
 *
 * @method PaymentGatewayInterface driver(?string $driver = null)
 */
final class PaymentGatewayManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('payments.driver', 'sandbox');
    }

    public function gateway(?string $driver = null): PaymentGatewayInterface
    {
        return $this->driver($driver);
    }

    protected function createSandboxDriver(): PaymentGatewayInterface
    {
        return new SandboxGateway($this->container->make('cache.store'));
    }

    protected function createMercadopagoDriver(): PaymentGatewayInterface
    {
        return new MercadoPagoGateway((array) $this->config->get('payments.drivers.mercadopago', []));
    }
}
