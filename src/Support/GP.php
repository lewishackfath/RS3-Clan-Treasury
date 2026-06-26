<?php

declare(strict_types=1);

namespace Treasury\Support;

final class GP
{
    public static function parse(string|int|null $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return 0;
        }

        $normalised = strtolower(str_replace([',', ' ', '_'], '', $raw));
        $multiplier = 1;

        if (str_ends_with($normalised, 'gp')) {
            $normalised = substr($normalised, 0, -2);
        }

        if (str_ends_with($normalised, 'b')) {
            $multiplier = 1_000_000_000;
            $normalised = substr($normalised, 0, -1);
        } elseif (str_ends_with($normalised, 'm')) {
            $multiplier = 1_000_000;
            $normalised = substr($normalised, 0, -1);
        } elseif (str_ends_with($normalised, 'k')) {
            $multiplier = 1_000;
            $normalised = substr($normalised, 0, -1);
        }

        if (!is_numeric($normalised)) {
            throw new \InvalidArgumentException('Amount must be a number, or use k/m/b shorthand such as 10m.');
        }

        $amount = (float)$normalised * $multiplier;
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        return (int)round($amount);
    }

    public static function format(int|string|null $amount): string
    {
        $amount = (int)$amount;
        $sign = $amount < 0 ? '-' : '';
        $amount = abs($amount);

        if ($amount >= 1_000_000_000 && $amount % 1_000_000_000 === 0) {
            return $sign . number_format($amount / 1_000_000_000) . 'b';
        }

        if ($amount >= 1_000_000 && $amount % 1_000_000 === 0) {
            return $sign . number_format($amount / 1_000_000) . 'm';
        }

        if ($amount >= 1_000_000_000) {
            return $sign . rtrim(rtrim(number_format($amount / 1_000_000_000, 2), '0'), '.') . 'b';
        }

        if ($amount >= 1_000_000) {
            return $sign . rtrim(rtrim(number_format($amount / 1_000_000, 2), '0'), '.') . 'm';
        }

        if ($amount >= 1_000) {
            return $sign . rtrim(rtrim(number_format($amount / 1_000, 1), '0'), '.') . 'k';
        }

        return $sign . number_format($amount) . ' GP';
    }
}
