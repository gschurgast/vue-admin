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
    enumValues?: string[]
}

export interface Resource {
    name: string
    title: string
    description: string
    properties: EnhancedProperty[]
    operations: HydraOperation[]
    collectionOperations: HydraOperation[]
    menuGroup: string | null
}

interface OpenApiSchema {
    openapi: string
    components?: {
        schemas?: Record<string, any>
    }
    [key: string]: any
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
    public openApiSchema: OpenApiSchema | null
    public resources: Map<string, Resource>
    private fetchPromise: Promise<HydraSchema> | null
    private onUnauthorized: (() => void) | null = null

    constructor() {
        this.client = axios.create({
            baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8080',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/ld+json'
            }
        })
        this.schema = null
        this.openApiSchema = null
        this.resources = new Map()
        this.fetchPromise = null // Track ongoing fetch to prevent duplicates

        // Add request interceptor to include auth token
        this.client.interceptors.request.use(
            (config) => {
                const token = localStorage.getItem('auth_token')
                if (token) {
                    config.headers.Authorization = `Bearer ${token}`
                }
                return config
            },
            (error) => Promise.reject(error)
        )

        // Add response interceptor to handle 401 errors
        this.client.interceptors.response.use(
            (response) => response,
            (error) => {
                if (error.response?.status === 401) {
                    localStorage.removeItem('auth_token')
                    localStorage.removeItem('auth_user')
                    if (this.onUnauthorized) {
                        this.onUnauthorized()
                    }
                }
                return Promise.reject(error)
            }
        )
    }

    setOnUnauthorized(callback: () => void) {
        this.onUnauthorized = callback
    }

    async login(email: string, password: string): Promise<{ token: string }> {
        const response = await this.client.post('/api/login', { email, password })
        const token = response.data.token
        localStorage.setItem('auth_token', token)
        return { token }
    }

    logout() {
        localStorage.removeItem('auth_token')
        localStorage.removeItem('auth_user')
        // Reset schema to force refetch after login
        this.schema = null
        this.openApiSchema = null
        this.resources.clear()
    }

    isAuthenticated(): boolean {
        return !!localStorage.getItem('auth_token')
    }

    getToken(): string | null {
        return localStorage.getItem('auth_token')
    }

    async getUser(userId: number): Promise<any> {
        const response = await this.client.get(`/api/users/${userId}`)
        return response.data
    }

    async updateUser(userId: number, data: { firstName?: string; lastName?: string; email?: string }): Promise<any> {
        const response = await this.client.patch(`/api/users/${userId}`, data, {
            headers: {
                'Content-Type': 'application/merge-patch+json'
            }
        })
        return response.data
    }

    async uploadUserPicture(userId: number, file: File): Promise<any> {
        const formData = new FormData()
        formData.append('picture', file)
        const response = await this.client.post(`/api/users/${userId}/picture`, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        })
        return response.data
    }

    async deleteUserPicture(userId: number): Promise<void> {
        await this.client.delete(`/api/users/${userId}/picture`)
    }

    async fetchSchema(force = false): Promise<HydraSchema> {
        if (!force && this.schema) return this.schema

        // If already fetching, return the same promise
        if (this.fetchPromise) {
            return this.fetchPromise
        }

        // Clear existing data when forcing
        if (force) {
            this.schema = null
            this.openApiSchema = null
            this.resources.clear()
        }

        this.fetchPromise = (async () => {
            try {
                // Fetch both schemas in parallel
                const [hydraResponse, openApiResponse] = await Promise.all([
                    this.client.get('/api/docs.jsonld'),
                    this.client.get('/api/docs', {
                        headers: { 'Accept': 'application/vnd.openapi+json' }
                    })
                ])

                this.schema = hydraResponse.data
                this.openApiSchema = openApiResponse.data

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

                        // Enhance properties with relation detection and enum values
                        const enhancedProperties: EnhancedProperty[] = (resource.supportedProperty || []).map((prop: HydraProperty) => {
                            // Check if it's a relation: either @type is 'Link' or range points to another entity (starts with #)
                            const range = prop.property?.range
                            const isRelation = prop.property?.['@type'] === 'Link' ||
                                (typeof range === 'string' && range.startsWith('#') && !range.includes('xmls:'))
                            const relatedResource = isRelation && typeof range === 'string' ? range.replace('#', '') : null
                            const fieldName = prop.property?.label || prop.title

                            // Get enum values from OpenAPI schema
                            const enumValues = this.getEnumValuesFromOpenApi(resourceName, fieldName)

                            return {
                                ...prop,
                                isRelation,
                                relatedResource,
                                enumValues
                            }
                        })

                        this.resources.set(resourceName, {
                            name: resourceName,
                            title: resource.title || resourceName,
                            description: resource.description || '',
                            properties: enhancedProperties,
                            operations: resource.supportedOperation || [],
                            collectionOperations: [], // Initialize collection operations
                            menuGroup: null // Will be set from OpenAPI
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

                    // Third pass: parse OpenAPI paths for x-menu-group on any HTTP method
                    // (a single path can host operations with different tags, e.g. GET=Conversation + DELETE=ConversationDelete)
                    if (this.openApiSchema?.paths) {
                        for (const [, methods] of Object.entries(this.openApiSchema.paths)) {
                            for (const method of ['get', 'post', 'put', 'patch', 'delete']) {
                                const operation = (methods as any)?.[method]
                                if (!operation?.['x-menu-group']) continue
                                const tag = operation.tags?.[0]
                                if (tag && this.resources.has(tag)) {
                                    const resource = this.resources.get(tag)
                                    if (resource) {
                                        resource.menuGroup = operation['x-menu-group']
                                        this.resources.set(tag, resource)
                                    }
                                }
                            }
                        }
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

    private getEnumValuesFromOpenApi(resourceName: string, fieldName: string): string[] | undefined {
        if (!this.openApiSchema?.components?.schemas) return undefined

        // Look for the write schema (used for forms)
        const schemaPatterns = [
            `${resourceName}-`, // e.g., AttributeDefinition-attribute_definition.write
            `${resourceName}.jsonld-`, // e.g., AttributeDefinition.jsonld-attribute_definition.read
            resourceName // e.g., AttributeDefinition
        ]

        for (const [schemaName, schema] of Object.entries(this.openApiSchema.components.schemas)) {
            // Check if this schema is for the resource we're looking for
            const matchesResource = schemaPatterns.some(pattern => schemaName.startsWith(pattern))
            if (!matchesResource) continue

            // Look for the field in properties
            if (schema && typeof schema === 'object' && 'properties' in schema) {
                const properties = schema.properties as Record<string, any>
                if (properties[fieldName] && Array.isArray(properties[fieldName].enum)) {
                    return properties[fieldName].enum
                }
            }
        }

        return undefined
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

    async getByIri(iri: string): Promise<any> {
        try {
            const response = await this.client.get(iri)
            return response.data
        } catch (error) {
            console.error('Failed to fetch by IRI:', error)
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

    isResourceHidden(resourceName: string): boolean {
        const resource = this.getResource(resourceName)
        return resource?.menuGroup === 'hidden'
    }

    getResourceMenuGroup(resourceName: string): string | null {
        const resource = this.getResource(resourceName)
        return resource?.menuGroup || null
    }
}

export default new ApiPlatformService()
