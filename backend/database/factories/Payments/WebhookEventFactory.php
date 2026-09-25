<?php

declare(strict_types=1);

namespace Database\Factories\Payments;

use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Enums\WebhookEventStatus;
use App\Modules\Payments\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebhookEvent> */
class WebhookEventFactory extends Factory
{
    protected $model = WebhookEvent::class;

    public function definition(): array
    {
        return [
            'provider' => PaymentProvider::Sandbox,
            'external_id' => 'evt_'.fake()->unique()->uuid(),
            'event_type' => 'payment.updated',
            'payload' => ['type' => 'payment.updated', 'data' => ['id' => fake()->uuid()]],
            'signature_valid' => true,
            'status' => WebhookEventStatus::Received,
            'attempts' => 0,
            'received_ip' => fake()->ipv4(),
        ];
    }
}
