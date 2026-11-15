<?php

namespace App\Enums;

enum OrderStatus: string
{
    case New = 'new';
    case Confirmed = 'confirmed';
    case Assembling = 'assembling';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новый',
            self::Confirmed => 'Подтверждён',
            self::Assembling => 'Комплектуется',
            self::Shipped => 'Отгружен',
            self::Completed => 'Выполнен',
            self::Cancelled => 'Отменён',
        };
    }

    /**
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::New => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Assembling, self::Cancelled],
            self::Assembling => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Completed],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $status): bool
    {
        return in_array($status, $this->next(), true);
    }
}
