<?php

namespace App\Enums\Kaspi;

enum KaspiStatus: string
{
    case ApprovedByBank = 'APPROVED_BY_BANK';
    case AcceptedByMerchant = 'ACCEPTED_BY_MERCHANT';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case Cancelling = 'CANCELLING';
    case ReturnRequested = 'KASPI_DELIVERY_RETURN_REQUESTED';
    case Returned = 'RETURNED';
    case Arrived = 'ARRIVED';

    public function label(): string
    {
        return match ($this) {
            self::ApprovedByBank => 'Ждёт принятия',
            self::AcceptedByMerchant => 'Принят',
            self::Completed => 'Выполнен',
            self::Cancelled => 'Отменён',
            self::Cancelling => 'Отменяется',
            self::ReturnRequested => 'Ожидает возврата',
            self::Returned => 'Возвращён',
            self::Arrived => 'Прибыл на склад',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Returned], true);
    }

    public function countsAsSale(): bool
    {
        return ! in_array($this, [self::Cancelled, self::Cancelling, self::Returned], true);
    }
}
