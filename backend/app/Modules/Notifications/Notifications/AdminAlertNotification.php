<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Panel alert (new paid order, cancellation request, payment anomaly, late
 * payment refund…): database (GET /admin/notifications) + e-mail.
 * On-demand recipients (settings notifications.admin_alert_emails) get mail only.
 */
final class AdminAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly ?string $link = null,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('[Painel] '.$this->title)->greeting($this->title);
        if ($this->body !== null) {
            $mail->line($this->body);
        }
        if ($this->link !== null) {
            $mail->action('Abrir no painel', rtrim((string) config('app.url'), '/').$this->link);
        }

        return $mail;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => $this->type, 'title' => $this->title, 'body' => $this->body, 'link' => $this->link];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->type;
    }
}
