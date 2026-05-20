import { computed, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'

type Rule = (v: any) => true | string

export function useAttributeValidation(definition: Ref<any | null | undefined>) {
  const { t } = useI18n()

  const helpText = computed<string>(() => definition.value?.helpText || '')

  function build(kind: 'text' | 'number'): Rule[] {
    const def = definition.value
    if (!def) return []
    const rules: Rule[] = []
    const vr = def.validationRules || {}

    if (def.isRequired) {
      rules.push((v) => (v !== null && v !== undefined && v !== '') || t('validation.required'))
    }
    if (kind === 'text') {
      if (vr.minLength != null) {
        rules.push((v) => !v || String(v).length >= vr.minLength || t('validation.minLength', { limit: vr.minLength }))
      }
      if (vr.maxLength != null) {
        rules.push((v) => !v || String(v).length <= vr.maxLength || t('validation.maxLength', { limit: vr.maxLength }))
      }
      if (vr.pattern) {
        const re = new RegExp(vr.pattern)
        rules.push((v) => !v || re.test(String(v)) || (vr.patternMessage || t('validation.invalidFormat')))
      }
    }
    if (kind === 'number') {
      if (vr.min != null) {
        rules.push((v) => v == null || v === '' || Number(v) >= vr.min || t('validation.min', { limit: vr.min }))
      }
      if (vr.max != null) {
        rules.push((v) => v == null || v === '' || Number(v) <= vr.max || t('validation.max', { limit: vr.max }))
      }
    }
    return rules
  }

  const textRules = computed(() => build('text'))
  const numberRules = computed(() => build('number'))

  return { helpText, textRules, numberRules }
}