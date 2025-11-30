# Component Customization Guide

All list cell and form field components receive the full object/field as props, enabling advanced customization.

## List Cell Components

### Props Available

All list cell components receive:
- `item` - Full data item object from the table row
- `value` - The specific value for this cell
- Additional context-specific props

### Example: Custom Date Formatting Based on Item

```vue
<!-- DateCell.vue -->
<template>
  <span :class="{ 'text-error': isOld }">{{ formattedDate }}</span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  item: { type: Object, required: true },
  value: { type: [String, Date], default: null }
})

const formattedDate = computed(() => {
  if (!props.value) return ''
  return new Date(props.value).toLocaleDateString()
})

// Custom logic using full item
const isOld = computed(() => {
  if (!props.value) return false
  const date = new Date(props.value)
  return Date.now() - date.getTime() > 365 * 24 * 60 * 60 * 1000
})
</script>
```

### Example: Conditional Boolean Display

```vue
<!-- BooleanCell.vue -->
<template>
  <v-icon :color="iconColor">
    {{ value ? 'mdi-check-circle' : 'mdi-close-circle' }}
  </v-icon>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  item: { type: Object, required: true },
  value: { type: Boolean, default: false }
})

// Use item context for conditional styling
const iconColor = computed(() => {
  // If author is deceased, show different colors
  if (props.item.deathDate) {
    return props.value ? 'grey' : 'grey-lighten-2'
  }
  return props.value ? 'success' : 'error'
})
</script>
```

## Form Field Components

### Props Available

All form field components receive:
- `field` - Full field configuration object with metadata
- `modelValue` - Current field value
- `label`, `required`, etc. - Specific field properties

### Field Object Structure

```javascript
{
  name: 'birthDate',
  label: 'Birth Date',
  type: 'date',
  required: false,
  isRelation: false,
  relatedResource: null  // Only for relations
}
```

### Example: Conditional Field Behavior

```vue
<!-- TextField.vue -->
<template>
  <v-text-field
    :model-value="modelValue"
    @update:model-value="$emit('update:modelValue', $event)"
    :label="label"
    :required="required"
    :hint="fieldHint"
    persistent-hint
  />
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  field: { type: Object, default: () => ({}) },
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  required: { type: Boolean, default: false }
})

// Add custom hints based on field name
const fieldHint = computed(() => {
  if (props.field.name === 'email') {
    return 'Must be a valid email address'
  }
  if (props.field.name === 'phone') {
    return 'Format: +1-234-567-8900'
  }
  return ''
})

defineEmits(['update:modelValue'])
</script>
```

### Example: Enhanced Relation Field

```vue
<!-- RelationField.vue -->
<template>
  <v-autocomplete
    :model-value="modelValue"
    @update:model-value="$emit('update:modelValue', $event)"
    :items="items"
    :item-title="displayField"
    item-value="@id"
    :label="label"
    clearable
    :loading="loading"
  />
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  field: { type: Object, default: () => ({}) },
  modelValue: { type: String, default: null },
  items: { type: Array, default: () => [] },
  label: { type: String, required: true },
  loading: { type: Boolean, default: false }
})

// Use different display field based on relation type
const displayField = computed(() => {
  if (props.field.relatedResource === 'Author') {
    return 'name'
  }
  if (props.field.relatedResource === 'Book') {
    return 'title'
  }
  return 'name'  // default
})

defineEmits(['update:modelValue'])
</script>
```

## Benefits

- **Contextual Rendering**: Access full item/field data for smart decisions
- **Dynamic Behavior**: Adjust display/validation based on related data
- **Cross-field Logic**: One field can influence another's behavior
- **Flexible Customization**: Easy to extend without changing core logic

## Use Cases

1. **Conditional Styling**: Color-code based on item status
2. **Dynamic Validation**: Field rules based on other field values
3. **Smart Defaults**: Pre-fill fields using related data
4. **Custom Formatting**: Display format based on item context
5. **Computed Values**: Calculate display from multiple item properties
