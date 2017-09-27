<?php

namespace bigdropinc\cashier;

use yii\base\Exception;
use yii\helpers\StringHelper;

/**
 * Class Cashier
 *
 * @package bigdropinc\cashier
 */
class Cashier
{
    /**
     * The current currency.
     *
     * @var string
     */
    protected static $currency = 'usd';

    /**
     * The current currency symbol.
     *
     * @var string
     */
    protected static $currencySymbol = '$';

    /**
     * The custom currency formatter.
     *
     * @var callable
     */
    protected static $formatCurrencyUsing;

    /**
     * Set the currency to be used when billing users.
     *
     * @param string $currency
     * @param string|null $symbol
     */
    public static function useCurrency($currency, $symbol = null)
    {
        static::$currency = $currency;
        static::useCurrencySymbol($symbol ?: static::guessCurrencySymbol($currency));
    }

    /**
     * Guess the currency symbol for the given currency.
     *
     * @param string $currency
     *
     * @return string
     *
     * @throws Exception
     */
    protected static function guessCurrencySymbol($currency)
    {
        switch (strtolower($currency)) {
            case 'usd':
            case 'aud':
            case 'cad':
                return '$';
            case 'eur':
                return '€';
            case 'gbp':
                return '£';
            default:
                throw new Exception('Unable to guess symbol for currency. Please explicitly specify it.');
        }
    }

    /**
     * Get the currency currently in use.
     *
     * @return string
     */
    public static function usesCurrency()
    {
        return static::$currency;
    }

    /**
     * Set the currency symbol to be used when formatting currency.
     *
     * @param string $symbol
     */
    public static function useCurrencySymbol($symbol)
    {
        static::$currencySymbol = $symbol;
    }

    /**
     * Get the currency symbol currently in use.
     *
     * @return string
     */
    public static function usesCurrencySymbol()
    {
        return static::$currencySymbol;
    }

    /**
     * Set the custom currency formatter.
     *
     * @param callable $callback
     */
    public static function formatCurrencyUsing(callable $callback)
    {
        static::$formatCurrencyUsing = $callback;
    }

    /**
     * Format the given amount into a displayable currency.
     *
     * @param int $amount
     *
     * @return string
     */
    public static function formatAmount($amount)
    {
        if (static::$formatCurrencyUsing) {
            return call_user_func(static::$formatCurrencyUsing, $amount);
        }
        $amount = number_format($amount / 100, 2);
        if (StringHelper::startsWith($amount, '-')) {
            return '-' . static::usesCurrencySymbol() . ltrim($amount, '-');
        }

        return static::usesCurrencySymbol() . $amount;
    }
}
