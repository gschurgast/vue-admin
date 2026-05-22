import type { Generated__Taxonomy } from '../../../types/schemas'

/**
 * Live API shape for a taxonomy node. We allow `parent` to be a string IRI in
 * addition to the generated embedded-object form: the serializer can return
 * either depending on MaxDepth resolution and how the item was loaded.
 */
export type TaxonomyItem = Omit<Generated__Taxonomy, 'parent'> & {
  parent?: Generated__Taxonomy | string | null
  // The tree fetches additional ad-hoc fields (e.g. derived label); keep open.
  [key: string]: any
}

export interface TreeNode {
  id: number
  code: string
  label?: string
  position: number
  parentId: number | null
  raw: TaxonomyItem
  children: TreeNode[]
}

export type DropPosition = 'before' | 'inside' | 'after'

export interface DropTarget {
  id: number
  position: DropPosition
}
