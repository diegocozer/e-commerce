<?php

declare(strict_types=1);

namespace App\Modules\Customers\Notifications;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\StorefrontUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class CustomerResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(Customer $notifiable): array
    {
        return ['mail'];
    }

    public function url(Customer $notifiable): string
    {
        return StorefrontUrl::to('redefinir-senha', ['token' => $this->token, 'email' => $notifiable->email]);
    }

    public function toMail(Customer $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Redefinição de senha')
            ->greeting('Olá!')
            ->line('Recebemos um pedido para redefinir a senha da sua conta.')
            ->action('Redefinir senha', $this->url($notifiable))
            ->line('O link expira em 60 minutos. Se você não fez o pedido, ignore este e-mail.');
    }
}
