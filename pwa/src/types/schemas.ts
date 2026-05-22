/**
 * Canonical TypeScript aliases for API Platform resources.
 *
 * Imports from this file ALWAYS resolve to the up-to-date schema generated
 * from the OpenAPI spec. Regenerate `api.d.ts` after any API change:
 *
 *     make generate-types
 *
 * Convention: every alias is prefixed `Generated__<Resource>` so the source
 * is obvious at usage sites. When adding a new API resource, append one line
 * below that points at its canonical read variant.
 */
import type { components } from './api'

type S = components['schemas']

// --- Domain resources ---
export type Generated__AttributeDefinition = S['AttributeDefinition.jsonld-attribute_definition.read']
export type Generated__AttributeOption = S['AttributeOption.jsonld-option.read']
export type Generated__AttributeOptionTranslation = S['AttributeOptionTranslation.jsonld-option.read']
export type Generated__Author = S['Author.jsonld']
export type Generated__Book = S['Book.jsonld']
export type Generated__Collection = S['Collection.jsonld-collection.read']
export type Generated__CollectionTranslation = S['CollectionTranslation.jsonld-collection.read']
export type Generated__Parameter = S['Parameter.jsonld-parameter.read']
export type Generated__Product = S['Product.jsonld-product.read']
export type Generated__ProductAttributeValue = S['ProductAttributeValue.jsonld-value.read']
export type Generated__ProductVariant = S['ProductVariant.jsonld-variant.read']
export type Generated__Taxonomy = S['Taxonomy.jsonld-taxonomy.read']
export type Generated__TaxonomyTranslation = S['TaxonomyTranslation.jsonld-taxonomy.read']
export type Generated__User = S['User.jsonld-user.read']

// --- Custom DTOs / actions ---
export type Generated__ChatRequest = S['ChatRequest.jsonld-chat.read']
export type Generated__Conversation = S['Conversation.jsonld-conversation.read']
export type Generated__ConversationDelete = S['ConversationDelete.jsonld']
export type Generated__GenerateProductContentRequest = S['GenerateProductContentRequest.jsonld']
export type Generated__GenerateVariantContentRequest = S['GenerateVariantContentRequest.jsonld']
export type Generated__LocaleRequest = S['LocaleRequest.jsonld']
export type Generated__ProductAttributeValuesRequest = S['ProductAttributeValuesRequest.jsonld']
export type Generated__TaxonomyReorder = S['TaxonomyReorder.jsonld-taxonomy_reorder.read']
export type Generated__TranslationRequest = S['TranslationRequest.jsonld-translation.read']

// --- Hydra envelopes (collection responses) ---
export interface HydraCollection<T> {
  '@context'?: string
  '@id'?: string
  '@type'?: string
  'hydra:member'?: T[]
  member?: T[]
  'hydra:totalItems'?: number
  totalItems?: number
}
