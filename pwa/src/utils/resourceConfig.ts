import { markRaw } from 'vue'

// Extract IRI from value (can be string IRI or object with @id)
export function extractIri(value: any): string | null {
  if (!value) return null
  if (typeof value === 'string') return value
  if (typeof value === 'object' && value['@id']) return value['@id']
  return null
}

// Helper to get fields from config (supports object with fields property)
export function getConfigFields(config: any): any[] {
  if (config && typeof config === 'object' && Array.isArray(config.fields)) return config.fields
  return []
}

// Helper to normalize config item (shorthand { field: value } -> { field, value })
export function normalizeConfigItem(item: any): { field: string; value: string } | null {
  if (typeof item !== 'object' || item === null) return null

  const keys = Object.keys(item)
  if (keys.length !== 1) return null

  const field = keys[0]
  const value = item[field]

  return { field, value }
}

// Determine field type from property range
export function getFieldType(prop: any): string {
  if (prop.isRelation) return 'relation'

  const range = prop.property?.range
  if (!range) return 'string'

  // Check for date vs datetime - order matters since 'dateTime' contains 'date'
  // Must check dateTime first
  if (range.includes('dateTime') || range.includes('DateTime')) return 'datetime'
  if (range.includes('date') || range.includes('Date')) return 'date'

  const typeMap: Record<string, string> = {
    'boolean': 'boolean',
    'integer': 'integer',
    'float': 'number',
    'decimal': 'number',
    'double': 'number',
    'text': 'textarea',
    'json': 'json',
    'array': 'array'
  }

  for (const [key, type] of Object.entries(typeMap)) {
    if (range.toLowerCase().includes(key)) return type
  }

  return 'string'
}

// Cache for custom components
const componentCache: Record<string, any> = {}

// Load custom component dynamically with caching
export async function loadCustomComponent(
  componentName: string,
  type: string,
  basePath: string
): Promise<any> {
  const cacheKey = `${type}/${componentName}`

  if (componentCache[cacheKey]) {
    return componentCache[cacheKey]
  }

  try {
    const component = await import(`${basePath}/${type}/${componentName}.vue`)
    componentCache[cacheKey] = markRaw(component.default || component)
    return componentCache[cacheKey]
  } catch (error) {
    console.warn(`Failed to load custom component: ${componentName}`, error)
    return null
  }
}

// Get cached component
export function getCachedComponent(type: string, componentName: string): any {
  return componentCache[`${type}/${componentName}`] || null
}

// Get all cached components
export function getCachedComponents(): Record<string, any> {
  return { ...componentCache }
}
