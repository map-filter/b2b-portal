<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TelegramLinkController extends Controller
{
    /**
     * Одноразовая ссылка на бота для привязки Telegram, живёт 15 минут.
     */
    public function store(Request $request): JsonResponse
    {
        $code = Str::lower(Str::random(24));
        Cache::put("telegram-link:{$code}", $request->user()->id, now()->addMinutes(15));

        return response()->json([
            'code' => $code,
            'url' => 'https://t.me/'.config('services.telegram.bot_username')."?start={$code}",
            'linked' => (bool) $request->user()->telegram_chat_id,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->update(['telegram_chat_id' => null, 'telegram_username' => null]);

        return response()->json(['linked' => false]);
    }
}
