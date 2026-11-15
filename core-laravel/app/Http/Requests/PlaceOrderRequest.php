<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlaceOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:2000'],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'desired_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
