/**
 * Client-side mirror of the server-side EDITOR-08 warning derivation.
 *
 * Provides instant feedback in the editor while the user is composing a
 * transformation pipeline; the server (Phase 4 plan 04-05) remains the
 * source of truth — see WarningBanner.vue which merges both sources.
 *
 * Mirrored rule (must stay aligned with the server warning codes):
 *   - `remove-background-requires-png`:
 *       output extension is jpg/jpeg AND the pipeline contains a
 *       `remove_background` step AND no `add_background` step appears
 *       after it. Emitted with the index of the offending remove step.
 *
 * Pure function. Wrap in a `computed()` at the call site if reactivity
 * is needed (the function itself imports no Vue reactivity primitives).
 */

export interface TransformationStep {
  id?: string
  type: string
  params?: Record<string, unknown>
}

export interface TransformationWarning {
  code: string
  message?: string
  stepIndex?: number
}

const JPEG_EXTS = new Set(['jpg', 'jpeg'])

export function useTransformationWarnings(
  steps: readonly TransformationStep[] | undefined,
  outputExt: string | undefined
): TransformationWarning[] {
  if (!steps || steps.length === 0) return []
  const ext = (outputExt ?? '').toLowerCase()
  if (!JPEG_EXTS.has(ext)) return []

  const warnings: TransformationWarning[] = []

  for (let i = 0; i < steps.length; i++) {
    if (steps[i]?.type !== 'remove_background') continue
    // Look ahead for any add_background step after this remove_background.
    let mitigated = false
    for (let j = i + 1; j < steps.length; j++) {
      if (steps[j]?.type === 'add_background') {
        mitigated = true
        break
      }
    }
    if (!mitigated) {
      warnings.push({
        code: 'remove-background-requires-png',
        stepIndex: i,
      })
    }
  }

  return warnings
}
