<?php

namespace App\Enums\Kaspi;

enum KaspiDeliveryMode: string
{
    case Local = 'DELIVERY_LOCAL';
    case Pickup = 'DELIVERY_PICKUP';
    case RegionalToDoor = 'DELIVERY_REGIONAL_TODOOR';
    case RegionalPickup = 'DELIVERY_REGIONAL_PICKUP';

    public function label(bool $kaspiDelivery): string
    {
        return match ($this) {
            self::Local => $kaspiDelivery ? 'Kaspi Доставка по городу' : 'Доставка продавца',
            self::Pickup => 'Самовывоз',
            self::RegionalToDoor => 'Kaspi Доставка по области',
            self::RegionalPickup => 'Kaspi Доставка до пункта выдачи',
        };
    }

    public function channel(bool $kaspiDelivery): string
    {
        return match (true) {
            $this === self::Pickup => 'pickup',
            $kaspiDelivery => 'kaspi_delivery',
            default => 'own_delivery',
        };
    }
}
