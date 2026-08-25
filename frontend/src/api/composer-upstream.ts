import { z } from 'zod'
import { get, patch as patchRequest, post } from '@/api/axios'

export const composerUpstreamAuthType = z.enum(['none', 'basic', 'bearer'])
export type ComposerUpstreamAuthType = z.infer<typeof composerUpstreamAuthType>

export const composerUpstreamHealthStatus = z.enum(['unknown', 'healthy', 'unhealthy'])
export type ComposerUpstreamHealthStatus = z.infer<typeof composerUpstreamHealthStatus>

const nullableDate = z.coerce.date().nullable().optional()

export const composerUpstream = z.object({
    id: z.coerce.string(),
    name: z.string(),
    url: z.string(),
    authType: composerUpstreamAuthType,
    hasCredentials: z.boolean().default(false),
    enabled: z.boolean().default(true),
    healthStatus: composerUpstreamHealthStatus,
    lastCheckedAt: nullableDate,
    lastSyncedAt: nullableDate,
    lastError: z.string().nullable().optional(),
    createdAt: z.coerce.date(),
    updatedAt: z.coerce.date(),
})

export type ComposerUpstream = z.infer<typeof composerUpstream>

export const storeComposerUpstreamInput = z.object({
    name: z.string(),
    url: z.string(),
    authType: composerUpstreamAuthType,
    username: z.string().optional(),
    password: z.string().optional(),
    token: z.string().optional(),
    enabled: z.boolean(),
})

export type StoreComposerUpstreamInput = z.infer<typeof storeComposerUpstreamInput>

export function fetchComposerUpstreams() {
    return get(composerUpstream.array(), '/composer-upstreams')
}

export function storeComposerUpstream(input: StoreComposerUpstreamInput) {
    return post(composerUpstream, '/composer-upstreams', input)
}

export const updateComposerUpstreamInput = storeComposerUpstreamInput.extend({
    id: z.string(),
    username: z.string().optional(),
    password: z.string().optional(),
    token: z.string().optional(),
})

export type UpdateComposerUpstreamInput = z.infer<typeof updateComposerUpstreamInput>

export function updateComposerUpstream({ id, ...input }: UpdateComposerUpstreamInput) {
    return patchRequest(composerUpstream, `/composer-upstreams/${id}`, input)
}

export const enrollComposerPackageInput = z.object({
    repositoryId: z.string(),
    name: z.string(),
})

export type EnrollComposerPackageInput = z.infer<typeof enrollComposerPackageInput>

export function enrollComposerPackage(upstreamId: string, input: EnrollComposerPackageInput) {
    return post(z.unknown(), `/composer-upstreams/${upstreamId}/packages`, input)
}

export function refreshComposerUpstream(upstreamId: string) {
    return post(z.unknown(), `/composer-upstreams/${upstreamId}/refresh`, {})
}

export function refreshComposerPackage(packageId: string) {
    return post(z.unknown(), `/packages/${packageId}/refresh`, {})
}

export function composerUpstreamStatus(upstream: Pick<ComposerUpstream, 'healthStatus'>) {
    return upstream.healthStatus
}
