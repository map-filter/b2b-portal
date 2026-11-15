<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case ClientAdmin = 'client_admin';
    case ClientBuyer = 'client_buyer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Администратор',
            self::Manager => 'Менеджер',
            self::ClientAdmin => 'Руководитель клиента',
            self::ClientBuyer => 'Закупщик клиента',
        };
    }

    public function isStaff(): bool
    {
        return in_array($this, [self::Admin, self::Manager], true);
    }

    public function isClient(): bool
    {
        return ! $this->isStaff();
    }
}
