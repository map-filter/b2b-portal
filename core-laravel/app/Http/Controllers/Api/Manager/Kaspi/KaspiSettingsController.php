<?php

namespace App\Http\Controllers\Api\Manager\Kaspi;

use App\Enums\Kaspi\KaspiMode;
use App\Http\Controllers\Controller;
use App\Models\Kaspi\KaspiSettings;
use App\Models\Kaspi\KaspiStore;
use App\Models\Kaspi\KaspiSyncRun;
use App\Models\PriceType;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Kaspi\KaspiSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class KaspiSettingsController extends Controller
{
    public function show(): array
    {
        $settings = KaspiSettings::current();
        $token = $settings->token();

        return ['data' => [
            'mode' => $settings->mode->value,
            'modes' => collect(KaspiMode::cases())->map(fn ($mode) => ['value' => $mode->value, 'label' => $mode->label()]),
            'api_url' => $settings->apiUrl(),
            'api_url_custom' => $settings->api_url,
            'token_set' => $token !== '',
            'token_hint' => $token !== '' ? Str::mask($token, '•', 3, max(strlen($token) - 6, 1)) : null,
            'merchant_id' => $settings->merchant_id,
            'company_name' => $settings->company_name,
            'price_type_id' => $settings->price_type_id,
            'notify_user_id' => $settings->notify_user_id,
            'poll_minutes' => $settings->poll_minutes,
            'sync_days' => $settings->sync_days,
            'auto_accept' => $settings->auto_accept,
            'is_enabled' => $settings->is_enabled,
            'last_synced_at' => $settings->last_synced_at?->toIso8601String(),
            'pricelist_url' => $this->priceListUrl($settings),
            'stores' => KaspiStore::with('warehouse')->orderBy('id')->get()->map(fn ($s) => [
                'id' => $s->id, 'store_id' => $s->store_id, 'name' => $s->name, 'warehouse_id' => $s->warehouse_id, 'is_active' => $s->is_active,
            ]),
            'warehouses' => Warehouse::orderBy('id')->get(['id', 'name']),
            'price_types' => PriceType::orderBy('id')->get(['id', 'name']),
            'staff' => User::query()->whereIn('role', ['admin', 'manager'])->orderBy('name')->get(['id', 'name', 'telegram_chat_id'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'telegram' => (bool) $u->telegram_chat_id]),
            'runs' => KaspiSyncRun::latest('id')->limit(15)->get(),
        ]];
    }

    public function update(Request $request): array
    {
        $data = $request->validate([
            'mode' => ['sometimes', Rule::enum(KaspiMode::class)],
            'api_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'api_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            'merchant_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price_type_id' => ['sometimes', 'nullable', 'exists:price_types,id'],
            'notify_user_id' => ['sometimes', 'nullable', 'exists:users,id'],
            'poll_minutes' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'sync_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'auto_accept' => ['sometimes', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
            'stores' => ['sometimes', 'array', 'max:50'],
            'stores.*.store_id' => ['required', 'string', 'max:64', 'distinct'],
            'stores.*.name' => ['required', 'string', 'max:255'],
            'stores.*.warehouse_id' => ['required', 'exists:warehouses,id'],
            'stores.*.is_active' => ['boolean'],
        ]);

        DB::transaction(function () use ($data) {
            $settings = KaspiSettings::current();
            $fields = collect($data)->except('stores');
            if ($fields->has('api_token') && blank($fields['api_token'])) {
                $fields->forget('api_token');
            }
            $settings->update($fields->all());

            if (isset($data['stores'])) {
                $keep = collect($data['stores'])->pluck('store_id');
                KaspiStore::whereNotIn('store_id', $keep)->delete();
                foreach ($data['stores'] as $store) {
                    KaspiStore::updateOrCreate(['store_id' => $store['store_id']], $store + ['is_active' => true]);
                }
            }
        });

        return $this->show();
    }

    public function sync(KaspiSync $sync): array
    {
        $run = $sync->run('manual');

        abort_if($run->error !== null, 502, $run->error);

        return ['data' => $run];
    }

    private function priceListUrl(KaspiSettings $settings): string
    {
        return rtrim(config('app.url'), '/')."/api/kaspi/pricelist/{$settings->pricelist_token}.xml";
    }
}
