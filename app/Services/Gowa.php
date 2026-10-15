<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class Gowa
{
    public function sendText(string $deviceId, string $phone, string $message): array
    {
        return $this->request($deviceId)->post('/send/message', [
            'phone' => $phone,
            'message' => $message,
        ])->throw()->json();
    }

    public function sendFile(string $deviceId, string $phone, string $path, ?string $caption = null): array
    {
        $this->assertReadableFile($path);

        return $this->request($deviceId)
            ->attach('file', fopen($path, 'r'), basename($path))
            ->post('/send/file', array_filter(['phone' => $phone, 'caption' => $caption], fn ($value) => $value !== null))
            ->throw()->json();
    }

    public function sendImage(string $deviceId, string $phone, string $path, ?string $caption = null): array
    {
        $this->assertReadableFile($path);

        return $this->request($deviceId)
            ->attach('image', fopen($path, 'r'), basename($path))
            ->post('/send/image', array_filter(['phone' => $phone, 'caption' => $caption], fn ($value) => $value !== null))
            ->throw()->json();
    }

    public function isOnWhatsApp(string $deviceId, string $phone): bool
    {
        return (bool) $this->request($deviceId)
            ->get('/user/check', ['phone' => $phone])
            ->throw()
            ->json('results.is_on_whatsapp', false);
    }

    public function deviceStatus(string $deviceId): array
    {
        return $this->request($deviceId)
            ->get('/devices/'.rawurlencode($deviceId).'/status')
            ->throw()
            ->json();
    }

    public function deviceState(array $response): string
    {
        if ((bool) data_get($response, 'results.is_logged_in')) {
            return 'logged_in';
        }

        if ((bool) data_get($response, 'results.is_connected')) {
            return 'connected';
        }

        return 'disconnected';
    }

    private function request(string $deviceId): PendingRequest
    {
        if (blank($deviceId)) {
            throw new InvalidArgumentException(__('application.a_company_whatsapp_device_id_is_required_e4e98c92'));
        }

        $url = config('gowa.url');
        $username = config('gowa.username');
        $password = config('gowa.password');

        if (blank($url) || blank($username) || blank($password)) {
            throw new RuntimeException(__('application.gowa_server_credentials_are_not_configured_0c36f21d'));
        }

        return Http::baseUrl($url)
            ->withBasicAuth($username, $password)
            ->withHeader('X-Device-Id', $deviceId)
            ->acceptJson()
            ->timeout((int) config('gowa.timeout', 30))
            ->connectTimeout((int) config('gowa.connect_timeout', 10));
    }

    private function assertReadableFile(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException(__('application.whatsapp_attachment_is_missing_or_unreadable_5c9a3fd9'));
        }
    }
}
