<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;

class HardexResetPassword extends ResetPassword
{
    public function __construct(string $token, private readonly string $messageLocale)
    {
        parent::__construct($token);
    }

    protected function buildMailMessage($url): MailMessage
    {
        $broker = config('auth.defaults.passwords');
        $minutes = (int) config("auth.passwords.{$broker}.expire", 60);
        $text = fn (string $key, array $replace = []): string => Lang::get('password_reset.email.'.$key, $replace, $this->messageLocale);

        return (new MailMessage)
            ->view(['html' => 'mail.auth.reset-password', 'text' => 'mail.auth.reset-password-text'], [
                'messageLocale' => $this->messageLocale,
                'fallback' => $text('fallback'),
            ])
            ->subject($text('subject'))
            ->greeting($text('brand'))
            ->line($text('greeting'))
            ->line($text('intro'))
            ->action($text('action'), $url)
            ->line($text('expiry', ['minutes' => $minutes]))
            ->line($text('ignore'))
            ->salutation($text('footer'));
    }
}
