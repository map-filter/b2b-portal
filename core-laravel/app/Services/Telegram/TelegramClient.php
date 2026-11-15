<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramClient
{
    public function enabled(): bool
    {
        return filled(config('services.telegram.token'));
    }

    public function send(int $chatId, string $html): void
    {
        $response = Http::baseUrl('https://api.telegram.org/bot'.config('services.telegram.token').'/')
            ->timeout(10)
            ->post('sendMessage', [
                'chat_id' => $chatId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'link_preview_options' => ['is_disabled' => true],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Telegram: '.$response->json('description', $response->body()));
        }
    }
}
