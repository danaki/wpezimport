<?php
/**
 * Unit Converter class for weight and dimension conversions
 * 
 * This class handles converting values from various units to the standard
 * units used by WooCommerce (kg for weight, m for dimensions).
 *
 * @package Wpezimport\Wpezimport
 */

namespace Wpezimport\Wpezimport;

class UnitConverter
{
    /**
     * Convert weight to kilograms
     *
     * @param mixed $value The weight value to convert
     * @param string $from_unit The source unit ('kg' or 'g')
     * @return float|mixed The converted value in kg, or original if already kg
     */
    public static function convertWeight($value, string $from_unit = 'kg')
    {
        if (empty($value)) {
            return $value;
        }

        $numeric_value = floatval($value);

        switch (strtolower($from_unit)) {
            case 'g':
                return $numeric_value / 1000;
            case 'kg':
            default:
                return $numeric_value;
        }
    }

    /**
     * Convert dimension to meters
     *
     * @param mixed $value The dimension value to convert
     * @param string $from_unit The source unit ('m', 'cm', or 'mm')
     * @return float|mixed The converted value in meters, or original if already m
     */
    public static function convertDimension($value, string $from_unit = 'm')
    {
        if (empty($value)) {
            return $value;
        }

        $numeric_value = floatval($value);

        switch (strtolower($from_unit)) {
            case 'cm':
                return $numeric_value / 100;
            case 'mm':
                return $numeric_value / 1000;
            case 'm':
            default:
                return $numeric_value;
        }
    }

    /**
     * Convert weight from kilograms to another unit
     *
     * @param mixed $value The weight value in kg
     * @param string $to_unit The target unit ('kg' or 'g')
     * @return float|mixed The converted value
     */
    public static function convertWeightFrom($value, string $to_unit = 'kg')
    {
        if (empty($value)) {
            return $value;
        }

        $numeric_value = floatval($value);

        switch (strtolower($to_unit)) {
            case 'g':
                return $numeric_value * 1000;
            case 'kg':
            default:
                return $numeric_value;
        }
    }

    /**
     * Convert dimension from meters to another unit
     *
     * @param mixed $value The dimension value in meters
     * @param string $to_unit The target unit ('m', 'cm', or 'mm')
     * @return float|mixed The converted value
     */
    public static function convertDimensionFrom($value, string $to_unit = 'm')
    {
        if (empty($value)) {
            return $value;
        }

        $numeric_value = floatval($value);

        switch (strtolower($to_unit)) {
            case 'cm':
                return $numeric_value * 100;
            case 'mm':
                return $numeric_value * 1000;
            case 'm':
            default:
                return $numeric_value;
        }
    }
}
