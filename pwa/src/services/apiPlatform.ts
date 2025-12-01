import axios, { type AxiosInstance } from 'axios'

interface HydraOperation {
    method: string
    returns: string
    [key: string]: any
}

interface HydraProperty {
    property: {
        '@type': string
        range?: string | { 'owl:equivalentClass': { 'owl:allValuesFrom': { '@id': string } } }[]
        supportedOperation?: HydraOperation[]
        [key: string]: any
    }
    [key: string]: any
}

interface EnhancedProperty extends HydraProperty {
    isRelation: boolean
    relatedResource: string | null
}

export interface Resource {
    name: string
    title: string
    description: string
    properties: EnhancedProperty[]
    operations: HydraOperation[]
    collectionOperations: HydraOperation[]
}

interface HydraClass {
    '@id': string
    title?: string
    description?: string
    supportedProperty?: HydraProperty[]
    supportedOperation?: HydraOperation[]
}

interface HydraSchema {
    supportedClass: HydraClass[]
    [key: string]: any
}

interface ListResponse<T = any> {
    data: T[]
    total: number
}

class ApiPlatformService {
    public client: AxiosInstance
    public schema: HydraSchema | null
    public resources: Map<string, Resource>
    private fetchPromise: Promise<HydraSchema> | null

    constructor() {
        this.client = axios.create({
            baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8080',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/ld+json'
            }
        })
        this.schema = null
        this.resources = new Map()
        this.fetchPromise = null // Track ongoing fetch to prevent duplicates
    }

    async fetchSchema(): Promise<HydraSchema> {
        if (this.schema) return this.schema

        // If already fetching, return the same promise
        if (this.fetchPromise) {
            return this.fetchPromise
        }

        this.fetchPromise = (async () => {
            try {
                // Fetch Hydra documentation
                const response = await this.client.get('/api/docs.jsonld')
                this.schema = response.data

                // Parse resources from Hydra documentation
                if (this.schema && this.schema.supportedClass) {
                    // First pass: create resources map
                    this.schema.supportedClass.forEach((resource: HydraClass) => {
                        // Skip built-in Hydra/API Platform classes
                        if (resource['@id'].startsWith('http://') ||
                            resource['@id'].includes('Entrypoint') ||
                            resource['@id'].includes('ConstraintViolation') ||
                            resource['@id'].includes('Error')) {
                            return
                        }

                        const resourceName = resource['@id'].replace('#', '')

                        // Enhance properties with relation detection
                        const enhancedProperties: EnhancedProperty[] = (resource.supportedProperty || []).map((prop: HydraProperty) => {
                            const isRelation = prop.property?.['@type'] === 'Link'
                            const relatedResource = isRelation && typeof prop.property.range === 'string' ? prop.property.range.replace('#', '') : null

                            return {
                                ...prop,
                                isRelation,
                                relatedResource
                            }
                        })

                        this.resources.set(resourceName, {
                            name: resourceName,
                            title: resource.title || resourceName,
                            description: resource.description || '',
                            properties: enhancedProperties,
                            operations: resource.supportedOperation || [],
                            collectionOperations: [] // Initialize collection operations
                        })
                    })

                    // Second pass: parse Entrypoint for collection operations
                    const entrypoint = this.schema.supportedClass.find((r: HydraClass) => r['@id'].includes('Entrypoint'))
                    if (entrypoint && entrypoint.supportedProperty) {
                        entrypoint.supportedProperty.forEach((prop: HydraProperty) => {
                            const operations = prop.property?.supportedOperation || []

                            // Try to find the related resource
                            // 1. Check range if it's a direct link (simple case)
                            // 2. Check owl:allValuesFrom if it's a collection (complex case)
                            let relatedResourceName: string | null = null

                            const range = prop.property?.range
                            if (Array.isArray(range)) {
                                const collectionRange = range.find((r: any) => r['owl:equivalentClass'])
                                if (collectionRange) {
                                    const resourceId = collectionRange['owl:equivalentClass']?.['owl:allValuesFrom']?.['@id']
                                    if (resourceId) {
                                        relatedResourceName = resourceId.replace('#', '')
                                    }
                                }
                            } else if (typeof range === 'string') {
                                relatedResourceName = range.replace('#', '')
                            }

                            if (relatedResourceName && this.resources.has(relatedResourceName)) {
                                const resource = this.resources.get(relatedResourceName)
                                if (resource) {
                                    resource.collectionOperations = operations
                                    this.resources.set(relatedResourceName, resource)
                                }
                            }
                        })
                    }
                }

                if (!this.schema) {
                    throw new Error('Schema not found')
                }

                return this.schema
            } catch (error) {
                console.error('Failed to fetch API schema:', error)
                throw error
            } finally {
                this.fetchPromise = null
            }
        })()

        return this.fetchPromise
    }

    getResources(): Resource[] {
        return Array.from(this.resources.values())
    }

    getResource(name: string): Resource | undefined {
        return this.resources.get(name)
    }

    getResourcePath(resourceName: string): string {
        // Convert PascalCase to snake_case (e.g. ChatMessage -> chat_message)
        const snakeCaseName = resourceName
            .replace(/[A-Z]/g, (letter, index) => index === 0 ? letter.toLowerCase() : `_${letter.toLowerCase()}`)

        return `/api/${snakeCaseName}s`
    }

    async getList(resourcePath: string, params: any = {}): Promise<ListResponse> {
        const { page = 1, itemsPerPage = 30, ...filters } = params

        try {
            const response = await this.client.get(resourcePath, {
                params: {
                    page,
                    itemsPerPage,
                    ...filters
                }
            })

            return {
                data: response.data.member || response.data['hydra:member'] || [],
                total: response.data.totalItems || response.data['hydra:totalItems'] || 0
            }
        } catch (error) {
            console.error('Failed to fetch list:', error)
            throw error
        }
    }

    async getOne(resourcePath: string, id: string | number): Promise<any> {
        try {
            const response = await this.client.get(`${resourcePath}/${id}`)
            return response.data
        } catch (error) {
            console.error('Failed to fetch item:', error)
            throw error
        }
    }

    async create(resourcePath: string, data: any): Promise<any> {
        try {
            const response = await this.client.post(resourcePath, data, {
                headers: {
                    'Content-Type': 'application/ld+json'
                }
            })
            return response.data
        } catch (error) {
            console.error('Failed to create item:', error)
            throw error
        }
    }

    async update(resourcePath: string, id: string | number, data: any): Promise<any> {
        try {
            const response = await this.client.patch(`${resourcePath}/${id}`, data, {
                headers: {
                    'Content-Type': 'application/merge-patch+json'
                }
            })
            return response.data
        } catch (error) {
            console.error('Failed to update item:', error)
            throw error
        }
    }

    async delete(resourcePath: string, id: string | number): Promise<boolean> {
        try {
            await this.client.delete(`${resourcePath}/${id}`)
            return true
        } catch (error) {
            console.error('Failed to delete item:', error)
            throw error
        }
    }

    getOperations(resourceName: string): HydraOperation[] {
        const resource = this.getResource(resourceName)
        return resource ? resource.operations : []
    }

    getCollectionOperations(resourceName: string): HydraOperation[] {
        const resource = this.getResource(resourceName)
        return resource ? (resource.collectionOperations || []) : []
    }

    hasCollectionOperation(resourceName: string, method: string): boolean {
        const operations = this.getCollectionOperations(resourceName)
        return operations.some(op => op.method === method)
    }

    hasItemOperation(resourceName: string, method: string): boolean {
        const operations = this.getOperations(resourceName)
        return operations.some(op => {
            // Check for method match
            if (op.method !== method) return false

            // For GET, ensure it's NOT a collection operation
            if (method === 'GET') {
                return op.returns !== 'hydra:Collection' && op.returns !== 'http://www.w3.org/ns/hydra/core#Collection'
            }

            // PUT, PATCH, DELETE are item operations
            if (['PUT', 'PATCH', 'DELETE'].includes(method)) return true

            return false
        })
    }
}

export default new ApiPlatformService()
