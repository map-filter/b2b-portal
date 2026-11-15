<?php

namespace App\Models\Kaspi;

use App\Enums\Kaspi\KaspiMode;
use App\Models\PriceType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['mode', 'api_url', 'api_token', 'merchant_id', 'company_name', 'pricelist_token', 'price_type_id', 'notify_user_id', 'poll_minutes', 'sync_days', 'auto_accept', 'is_enabled', 'last_synced_at'])]
class KaspiSettings extends Model
{
    protected $table = 'kaspi_settings';

    protected $hidden = ['api_token'];

    protected function casts(): array
    {
        return [
            'mode' => KaspiMode::class,
            'api_token' => 'encrypted',
            'auto_accept' => 'boolean',
            'is_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? self::create([
            'pricelist_token' => config('services.kaspi.pricelist_token') ?: Str::random(40),
            'company_name' => config('b2b.supplier.name'),
        ]);
    }

    public function standalone(): bool
    {
        return $this->mode === KaspiMode::Standalone;
    }

    public function apiUrl(): string
    {
        return rtrim($this->api_url ?: (string) config('services.kaspi.url'), '/');
    }

    public function token(): string
    {
        return (string) ($this->api_token ?: config('services.kaspi.token'));
    }

    public function priceType(): BelongsTo
    {
        return $this->belongsTo(PriceType::class);
    }

    public function notifyUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notify_user_id');
    }
}
