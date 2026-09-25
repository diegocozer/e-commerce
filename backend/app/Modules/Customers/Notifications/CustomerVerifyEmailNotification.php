<?php

declare(strict_types=1);

namespace App\Modules\Customers\Notifications;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\EmailVerificationSignature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class CustomerVerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(Customer $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(Customer $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirme seu e-mail')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Confirme seu e-mail para receber as novidades dos seus pedidos.')
            ->action('Confirmar e-mail', EmailVerificationSignature::url($notifiable));
    }
}
