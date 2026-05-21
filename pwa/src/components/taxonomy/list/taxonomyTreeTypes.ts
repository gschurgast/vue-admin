export interface TaxonomyItem {
  id: number
  '@id'?: string
  code: string
  position?: number
  parent?: { '@id'?: string; id?: number } | string | null
  translations?: Array<{ locale: string; label: string }>
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
