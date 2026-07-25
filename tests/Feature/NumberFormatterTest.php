<?php

use App\Support\NumberFormatter;

test('money values display without decimal places', function () {
    expect(NumberFormatter::money(1000.00))->toBe('1,000')
        ->and(NumberFormatter::money(58000.00))->toBe('58,000')
        ->and(NumberFormatter::money(0.00))->toBe('0')
        ->and(NumberFormatter::money(2500000.00))->toBe('2,500,000')
        ->and(NumberFormatter::money('28,000.50'))->toBe('28,001')
        ->and(NumberFormatter::money(''))->toBe('0')
        ->and(NumberFormatter::money(null))->toBe('0');
});

test('quantity values display without unnecessary trailing zeros', function () {
    expect(NumberFormatter::quantity(1.0000))->toBe('1')
        ->and(NumberFormatter::quantity(1.5000))->toBe('1.5')
        ->and(NumberFormatter::quantity(2.2500))->toBe('2.25')
        ->and(NumberFormatter::quantity('3.7500'))->toBe('3.75');
});

test('compact money omits zero decimals without rounding meaningful cents', function () {
    expect(NumberFormatter::moneyCompact(9500000.00))->toBe('9,500,000')
        ->and(NumberFormatter::moneyCompact(0.00))->toBe('0')
        ->and(NumberFormatter::moneyCompact('28,000.50'))->toBe('28,000.50')
        ->and(NumberFormatter::moneyCompact('42,000.75'))->toBe('42,000.75');
});
