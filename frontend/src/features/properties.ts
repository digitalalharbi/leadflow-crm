import { apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'

export type PropertyType = 'apartment' | 'villa' | 'townhouse' | 'duplex' | 'land' | 'office' | 'shop' | 'other'
export type PropertyStatus = 'available' | 'reserved' | 'sold' | 'rented' | 'hidden'

export interface Property {
  id: string
  project?: { id: string; name: string } | null
  code: string
  title: string | null
  property_type: PropertyType
  type_label: string
  transaction_type: 'sale' | 'rent'
  price: number | null
  city: string | null
  district: string | null
  bedrooms: number | null
  bathrooms: number | null
  area_sqm: number | null
  floor: number | null
  status: PropertyStatus
  features: string[]
  notes: string | null
}

export const PROPERTY_TYPES: PropertyType[] = ['apartment', 'villa', 'townhouse', 'duplex', 'land', 'office', 'shop', 'other']
export const PROPERTY_STATUSES: PropertyStatus[] = ['available', 'reserved', 'sold', 'rented', 'hidden']

export interface PropertyInput {
  code: string
  title?: string | null
  property_type: PropertyType
  transaction_type?: 'sale' | 'rent'
  price?: number | null
  city?: string | null
  district?: string | null
  bedrooms?: number | null
  bathrooms?: number | null
  area_sqm?: number | null
  floor?: number | null
  status?: PropertyStatus
  notes?: string | null
}

export interface ProjectProperties {
  properties: Property[]
  summary: Record<PropertyStatus, number>
}

export const fetchProjectProperties = (projectId: string, status?: PropertyStatus) =>
  apiGet<ProjectProperties>(`/projects/${projectId}/properties`, status ? { status } : undefined)

export const createProperty = (projectId: string, input: PropertyInput) =>
  apiPost<{ property: Property }>(`/projects/${projectId}/properties`, input)

export const updateProperty = (id: string, input: Partial<PropertyInput>) => apiPatch<{ property: Property }>(`/properties/${id}`, input)

export const deleteProperty = (id: string) => apiDelete(`/properties/${id}`)
