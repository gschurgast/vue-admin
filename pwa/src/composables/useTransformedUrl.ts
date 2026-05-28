/**
 * Build the public transformation URL for an asset.
 *
 * Pure string builder — NO fetch, NO reactivity (per Phase 5 D-12 / D-13).
 * Sync transformations only. The route `/t/{code}/{assetId}.{ext}` is served
 * by the PHP orchestrator (Phase 3 PublicTransformationController) and may be
 * fronted by a CDN; the optional `VITE_PUBLIC_TRANSFORMATION_BASE` env var
 * holds that origin.
 *
 * Examples:
 *   useTransformedUrl('product-thumb', 42, 'webp')
 *     → '/t/product-thumb/42.webp'                       (no base)
 *     → 'https://cdn.example.com/t/product-thumb/42.webp' (with base)
 */
const base = (import.meta.env.VITE_PUBLIC_TRANSFORMATION_BASE as string | undefined) ?? ''

export function useTransformedUrl(code: string, assetId: number, ext: string): string {
  return `${base}/t/${code}/${assetId}.${ext}`
}
