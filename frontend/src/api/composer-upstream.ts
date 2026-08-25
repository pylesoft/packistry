import { z } from 'zod'
import { del, get, patch as patchRequest, post } from '@/api/axios'

export const composerUpstreamAuthType = z.enum(['none', 'basic', 'bearer'])
export type ComposerUpstreamAuthType = z.infer<typeof composerUpstreamAuthType>

const nullableDate = z.coerce.date().nullable().optional()

export const composerUpstream = z.object({
    id: z.coerce.string(),
    name: z.string(),
    url: z.string(),
    authType: composerUpstreamAuthType,
    hasCredentials: z.boolean().default(false),
    enabled: z.boolean().default(true),
    lastCheckedAt: nullableDate,
    packagesCount: z.number().optional(),
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

export function deleteComposerUpstream(upstreamId: string) {
    return del(composerUpstream, `/composer-upstreams/${upstreamId}`)
}

export const enrollComposerPackageInput = z.object({
    repositoryId: z.string(),
    name: z.string(),
})

export type EnrollComposerPackageInput = z.infer<typeof enrollComposerPackageInput>

export const enrollComposerPackageResponse = z.object({
    package: z.object({
        id: z.coerce.string(),
        name: z.string(),
    }),
    batchId: z.coerce.string(),
})

export const refreshComposerPackageResponse = z.object({
    accepted: z.boolean(),
    batchId: z.coerce.string(),
})

export function enrollComposerPackage(upstreamId: string, input: EnrollComposerPackageInput) {
    return post(enrollComposerPackageResponse, `/composer-upstreams/${upstreamId}/packages`, input)
}

export function refreshComposerPackage(packageId: string) {
    return post(refreshComposerPackageResponse, `/packages/${packageId}/refresh`, {})
}
