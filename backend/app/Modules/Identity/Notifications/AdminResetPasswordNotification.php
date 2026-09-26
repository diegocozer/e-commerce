<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Support\AdminUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AdminResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token, public readonly bool $invitation = false)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(AdminUser $notifiable): array
    {
        return ['mail'];
    }

    public function url(AdminUser $notifiable): string
    {
        return AdminUrl::resetPassword($this->token, $notifiable->email);
    }

    public function toMail(AdminUser $notifiable): MailMessage
    {
        return $this->invitation
            ? (new MailMessage)
                ->subject('Convite para o painel')
                ->greeting('Olá, '.$notifiable->name.'!')
                ->line('Você foi convidado para acessar o painel administrativo.')
                ->action('Definir senha', $this->url($notifiable))
                ->line('O link expira em 72 horas.')
            : (new MailMessage)
                ->subject('Redefinição de senha do painel')
                ->line('Recebemos um pedido para redefinir sua senha do painel.')
                ->action('Redefinir senha', $this->url($notifiable))
                ->line('O link expira em 30 minutos. Se você não fez o pedido, ignore este e-mail.');
    }
}
