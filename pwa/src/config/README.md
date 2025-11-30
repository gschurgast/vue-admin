# Resource Configuration

This directory contains JSON configuration files to customize the display order of fields for each resource.

## File Format

Each resource can have a configuration file named `{ResourceName}.json` (e.g., `Author.json`, `Book.json`).

### Structure

```json
{
  "search": ["field1", "field2"],
  "list": ["field1", "field2", "field3"],
  "edit": ["field1", "field3", "field2"]
}
```

### Keys

- **search**: Order of fields in the search form (legacy, use filters instead)
- **filters**: Custom filter configuration with field types and labels
- **list**: Order of columns in the table list view
- **edit**: Order of fields in the create/edit form

### Filters Configuration

The `filters` key allows you to customize the search form with different filter types:

```json
{
  "filters": [
    "title",
    "description",
    { "field": "publicationDate", "type": "date", "label": "Publication Date" },
    { "field": "status", "type": "text", "label": "Status", "component": "CustomStatusFilter" }
  ]
}
```

**Filter Options:**
- **String format**: Simple text search (e.g., `"title"`)
- **Object format**: Custom filter with options
  - `field`: Field name (required)
  - `type`: Filter type - `"text"`, `"date"`, or `"date-range"` (default: `"text"`)
  - `label`: Custom label (optional, defaults to "Search {field}" or "Filter {field}")
  - `component`: Custom filter component name (optional, loads from `src/components/filters/`)

**Filter Types:**
- `"text"` - Single text input
- `"date"` - Single date picker (exact date match using both `[after]` and `[before]`)
- `"date-range"` - Two date pickers (From/To) for filtering between dates

**Date Filter Behavior:**
- Single `"date"` filters are automatically converted to use both `fieldName[after]` and `fieldName[before]` with the same date
- This finds records with exact date match
- For flexible date ranges (only from, only to, or between), use `"date-range"` type

### Custom Component Override

You can specify a custom component for any field by using an object instead of a string:

```json
{
  "list": [
    "title",
    { "field": "publicationDate", "component": "CustomDateCell" },
    "author"
  ],
  "edit": [
    "title",
    { "field": "author", "component": "CustomAuthorField" },
    "description"
  ]
}
```

**Component Resolution:**
- List components: `src/components/list/{ComponentName}.vue`
- Form components: `src/components/fields/{ComponentName}.vue`

If the custom component fails to load, the default component is used automatically.

## Behavior

- **When a config file exists:**
  - Only fields listed in the config are shown
  - Fields appear in the order specified in the config
  - Fields not in the config are completely hidden from that view
  
- **When no config file exists:**
  - All applicable fields are shown in their default order

- If a field name in the config doesn't match any actual field, it's ignored
- Config files are loaded dynamically when navigating to a resource
- Custom components are pre-loaded when the config is parsed

## Examples

### Author.json
```json
{
  "search": ["name", "bio"],
  "list": ["name", "isAlive", "birthDate", "bio"],
  "edit": ["name", "birthDate", "isAlive", "bio"]
}
```

### Book.json with Custom Components
```json
{
  "search": ["description", "title"],
  "list": [
    "title",
    { "field": "publicationDate", "component": "CustomDateCell" },
    "description",
    "author"
  ],
  "edit": [
    "title",
    { "field": "author", "component": "EnhancedAuthorField" },
    "publicationDate",
    "description"
  ]
}
```

## Creating Custom Components

### List Cell Component

Create in `src/components/list/YourComponent.vue`:

```vue
<template>
  <div>{{ displayValue }}</div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  item: { type: Object, required: true },
  value: { type: [String, Number, Boolean, Object, Array], default: null }
})

const displayValue = computed(() => {
  // Your custom logic here
  return props.value
})
</script>
```

### Form Field Component

Create in `src/components/fields/YourComponent.vue`:

```vue
<template>
  <v-text-field
    :model-value="modelValue"
    @update:model-value="$emit('update:modelValue', $event)"
    :label="label"
  />
</template>

<script setup>
const props = defineProps({
  field: { type: Object, default: () => ({}) },
  modelValue: { type: [String, Number, Boolean, Object], default: null },
  label: { type: String, required: true },
  required: { type: Boolean, default: false }
})

defineEmits(['update:modelValue'])
</script>
```

## Notes

- Field names must match the exact property names from the API schema
- Invalid field names in the config will be ignored
- The configuration system is case-sensitive
- Custom components must follow the same prop interface as default components
