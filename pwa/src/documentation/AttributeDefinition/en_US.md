# Attribute Definition Documentation

## Overview

The Attribute Definition resource defines the structure and behavior of product attributes in your PIM (Product Information Management) system. Each attribute definition describes how a specific piece of product data should be stored, validated, and displayed.

## Fields

### Code
- **Type**: String (max 50 characters, unique)
- **Required**: Yes
- **Description**: A unique identifier for the attribute, used programmatically. Must follow camelCase or snake_case convention.
- **Example**: `colorMarketing`, `weight_kg`, `description`

### Type
- **Type**: Enum
- **Required**: Yes
- **Description**: Defines how the attribute value is stored and displayed. See [Attribute Types](#attribute-types) below.

### Is Localizable
- **Type**: Boolean
- **Default**: false
- **Description**: When enabled, this attribute can have different values per locale (language/region). Use for translatable content like descriptions, names, or marketing text.
- **Example**: A product description that needs French, English, and German versions.

### Is Scopable
- **Type**: Boolean
- **Default**: false
- **Description**: When enabled, this attribute can have different values per market/channel. Use for market-specific data like pricing tiers or regional specifications.
- **Example**: Different product specifications for EU vs US markets.

### Is Required
- **Type**: Boolean
- **Default**: false
- **Description**: When enabled, a value must be provided for this attribute when saving a product.

### Validation Rules
- **Type**: JSON (key-value pairs)
- **Required**: No
- **Description**: Custom validation constraints for the attribute value.
- **Common rules**:
  - `minLength`: Minimum character count for text
  - `maxLength`: Maximum character count for text
  - `min`: Minimum value for numbers
  - `max`: Maximum value for numbers
  - `pattern`: Regular expression for text validation

### Allowed Values
- **Type**: JSON (key-value pairs)
- **Required**: No
- **Description**: For simple enumerations, defines the list of acceptable values without needing separate AttributeOption entities.

### Unit
- **Type**: String (max 50 characters)
- **Required**: No
- **Description**: The measurement unit for MEASURE type attributes.
- **Example**: `cm`, `kg`, `W`, `dB`, `L`

### Default Value
- **Type**: String (max 255 characters)
- **Required**: No
- **Description**: The default value pre-filled when creating a new product attribute value.

### Help Text
- **Type**: Text
- **Required**: No
- **Description**: Guidance text displayed to users when editing this attribute. Use to explain the expected format or provide examples.

### Sort Order
- **Type**: Integer
- **Default**: 0
- **Description**: Controls the display order of attributes in forms and lists. Lower values appear first.

## Attribute Types

- **text**: Simple short text, unformatted. Examples: "Oak", "Black", "Model X"
- **textarea**: Multiline plain text. Examples: "Assembly required. Delivered in 2 packages."
- **richtext**: Rich text with HTML formatting. Examples: Marketing descriptions with bold, lists, links
- **number**: Floating-point number. Examples: 160.5, 12.3, 99.99
- **integer**: Whole number. Examples: 4, 220, 1000
- **decimal**: High-precision decimal. Examples: 0.00001, 199.99
- **boolean**: True/false toggle. Examples: Requires assembly: Yes/No
- **enum**: Single value from predefined list. Examples: Color: "Red" (from Red, Blue, Green)
- **multienum**: Multiple values from predefined list. Examples: Tags: ["eco", "outdoor"]
- **media**: File reference (image, PDF, video). Examples: product_manual.pdf
- **relation**: Reference to another entity. Examples: Parent product, Collection
- **json**: Complex structured data. Examples: Custom supplier data
- **measure**: Value with unit. Examples: 230 cm, 12 kg, 650 W

## Best Practices

1. **Code naming**: Use descriptive, consistent naming. Prefix related attributes (e.g., `dimension_width`, `dimension_height`, `dimension_depth`).

2. **Localization**: Only enable `isLocalizable` for truly translatable content. Technical specifications typically don't need localization.

3. **Scopability**: Use `isScopable` sparingly. Most attributes should have consistent values across markets.

4. **Type selection**:
   - Use `measure` instead of `number` + separate unit field
   - Use `enum`/`multienum` with AttributeOptions for values that need translations
   - Use `allowedValues` for simple, non-translatable enumerations

5. **Validation**: Always set appropriate validation rules to ensure data quality.

## Related Resources

- **AttributeOption**: Defines selectable values for `enum` and `multienum` type attributes
- **ProductAttributeValue**: Stores actual attribute values for products
