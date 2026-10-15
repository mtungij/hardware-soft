<?php

namespace App\Support;

use InvalidArgumentException;

final class WhatsAppPhone
{
    public static function normalize(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';

        if (str_starts_with($digits, '0')) {
            $digits = '255'.substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $digits = '255'.$digits;
        }

        if (! preg_match('/^255[67]\d{8}$/', $digits)) {
            throw new InvalidArgumentException(__('application.enter_a_valid_tanzania_mobile_number_e27a3051'));
        }

        return $digits;
    }
}
