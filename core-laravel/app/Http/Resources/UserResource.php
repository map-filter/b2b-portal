<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'telegram_linked' => (bool) $this->telegram_chat_id,
            'telegram_available' => filled(config('services.telegram.token')) && filled(config('services.telegram.bot_username')),
            'company' => $this->whenLoaded('company', fn () => $this->company ? [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'legal_name' => $this->company->legal_name,
                'bin' => $this->company->bin,
                'legal_address' => $this->company->legal_address,
                'credit_limit' => $this->company->credit_limit,
                'payment_delay_days' => $this->company->payment_delay_days,
                'manager' => $this->company->manager ? [
                    'name' => $this->company->manager->name,
                    'email' => $this->company->manager->email,
                    'phone' => $this->company->manager->phone,
                ] : null,
                'contract' => $this->company->defaultContract ? [
                    'number' => $this->company->defaultContract->number,
                    'ends_at' => $this->company->defaultContract->ends_at?->toDateString(),
                    'discount_percent' => $this->company->defaultContract->discount_percent,
                    'price_type' => $this->company->defaultContract->priceType->name,
                ] : null,
            ] : null),
        ];
    }
}
