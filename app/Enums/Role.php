<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Finance = 'finance';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Finance => 'Finans',
            self::Viewer => 'Görüntüleyici',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
