<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Notifications\Channels\WhatsAppChannel;
use App\Modules\Notifications\DTOs\WhatsAppMessage;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderPresenter;
use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base of the customer order notifications (RN-NOT): e-mail (pt-BR markdown),
 * database (timeline /me/notifications) and the WhatsApp stub when enabled.
 * Queued on `notifications`, dispatched only after commit; the order is
 * reloaded when sending (events carry ids only).
 */
abstract class OrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /** Machine type of the in-app notification (e.g. "order_paid"). */
    abstract public function type(): string;

    abstract protected function subject(Order $order): string;

    /** @return list<string> e-mail paragraphs */
    abstract protected function lines(Order $order): array;

    protected function whatsAppTemplate(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = ['mail', 'database'];
        if ($this->whatsAppTemplate() !== null && app(SettingsRepository::class)->bool(SettingKey::NotificationsWhatsappEnabled)) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order();
        $store = (string) app(SettingsRepository::class)->string(SettingKey::StoreName);

        return (new MailMessage)
            ->subject($this->subject($order).' — '.$store)
            ->markdown('notifications::mail.order', [
                'title' => $this->subject($order),
                'greeting' => 'Olá, '.$order->customer_name.'!',
                'lines' => $this->lines($order),
                'order' => $order,
                'items' => $order->items->map(static fn ($i): array => [
                    'name' => $i->product_name.($i->variant_name !== '' ? ' — '.$i->variant_name : ''),
                    'configuration' => OrderPresenter::configurationLabel($i),
                    'total' => self::money($i->total_cents),
                ])->all(),
                'totals' => [
                    'Subtotal' => self::money($order->subtotal_cents),
                    'Desconto' => $order->discount_cents > 0 ? '− '.self::money($order->discount_cents) : null,
                    'Frete' => self::money($order->shipping_cents - $order->shipping_discount_cents),
                    'Total' => self::money($order->total_cents),
                ],
                'pix' => $this->pix($order),
                'actionUrl' => $this->orderUrl($order),
                'store' => $store,
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $order = $this->order();
        $lines = $this->lines($order);

        return [
            'type' => $this->type(),
            'title' => $this->subject($order),
            'body' => $lines[0] ?? null,
            'link' => '/minha-conta/pedidos/'.$order->uuid,
            'order_uuid' => $order->uuid,
            'order_number' => $order->number,
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->type();
    }

    public function toWhatsApp(object $notifiable): ?WhatsAppMessage
    {
        $template = $this->whatsAppTemplate();
        if ($template === null) {
            return null;
        }
        $order = $this->order();

        return new WhatsAppMessage($template, ['number' => $order->number, 'tracking_code' => (string) $order->tracking_code]);
    }

    /** @return array{copy_paste: string, expires_at: string}|null */
    protected function pix(Order $order): ?array
    {
        return null;
    }

    protected function order(): Order
    {
        return Order::query()->with(['items', 'payments'])->findOrFail($this->orderId);
    }

    protected function orderUrl(Order $order): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/').'/minha-conta/pedidos/'.$order->uuid;
    }

    protected static function money(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    protected static function localTime(?\Carbon\CarbonInterface $date): string
    {
        return $date === null ? '' : $date->copy()->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i');
    }
}
