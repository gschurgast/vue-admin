<?php

namespace App\Enum;

enum AttributeType: string
{
    /**
     * Simple short text, unformatted.
     * Use for basic information fields.
     * Example: "Oak", "Black", "Model X".
     */
    case TEXT = 'text';

    /**
     * Multiline plain text with no formatting.
     * Use for technical descriptions or simple instructions.
     * Example: "Assembly required. Delivered in 2 packages."
     */
    case TEXTAREA = 'textarea';

    /**
     * Rich text with HTML formatting.
     * Use for long marketing descriptions, formatted blocks, or editorial content.
     */
    case RICHTEXT = 'richtext';

    /**
     * Standard floating-point number.
     * Use for any numeric measurement that can include decimals.
     * Example: dimensions (160.5), capacity (12.3 L), power ratings.
     */
    case NUMBER = 'number';

    /**
     * Integer number.
     * Use for counts or values that must be whole numbers.
     * Example: number of seats, number of packages, voltage (220).
     */
    case INTEGER = 'integer';

    /**
     * Decimal number with controlled precision.
     * Use when NUMBER does not provide enough precision.
     * Example: cost price, production tolerances.
     */
    case DECIMAL = 'decimal';

    /**
     * Boolean true/false.
     * Use for toggle attributes.
     * Example: "Requires assembly", "Is convertible", "Is recyclable".
     */
    case BOOLEAN = 'boolean';

    /**
     * Single value from a predefined list.
     * Requires AttributeOption and AttributeOptionTranslation.
     * Example: marketing color, style ("Scandinavian", "Industrial").
     */
    case ENUM = 'enum';

    /**
     * Multiple values from a predefined list.
     * Requires AttributeOption.
     * Example: product tags ("eco", "UV resistant", "outdoor").
     */
    case MULTI_ENUM = 'multienum';

    /**
     * Media asset (image, video, PDF, technical sheet).
     * Use for any file associated with the product.
     */
    case MEDIA = 'media';

    /**
     * Reference to another entity (relation inside the PIM).
     * Example: collection, series, color group, parent product.
     */
    case RELATION = 'relation';

    /**
     * Complex or structured data stored as JSON.
     * Use sparingly for dynamic or supplier-specific schemas.
     */
    case JSON = 'json';

    /**
     * Measurement composed of a value + unit.
     * More semantic and cleaner than NUMBER + TEXT unit fields.
     * Example: 230 cm, 12 kg, 650 W, 55 dB.
     */
    case MEASURE = 'measure';
}
