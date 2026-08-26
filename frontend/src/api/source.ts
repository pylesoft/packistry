import { z } from 'zod'
import { del, get, patch, post } from '@/api/axios'
import { sourceProvider } from '@/api/source-provider'

export const composerSourceAuthType = z.enum(['none', 'basic', 'bearer'])
export type ComposerSourceAuthType = z.infer<typeof composerSourceAuthType>

const baseSource = z.object({
    id: z.coerce.string(),
    name: z.string(),
    url: z.string(),
    createdAt: z.coerce.date(),
    updatedAt: z.coerce.date(),
})

export const source = z.discriminatedUnion('provider', [
    z.object({
        provider: z.literal('gitlab'),
        metadata: z.object({}),
        ...baseSource.shape,
    }),
    z.object({
        provider: z.literal('github'),
        metadata: z.object({}),
        ...baseSource.shape,
    }),
    z.object({
        provider: z.literal('gitea'),
        metadata: z.object({}),
        ...baseSource.shape,
    }),
    z.object({
        provider: z.literal('bitbucket'),
        metadata: z.object({
            workspace: z.string().optional(),
        }),
        ...baseSource.shape,
    }),
    z.object({
        provider: z.literal('composer'),
        authType: composerSourceAuthType,
        hasCredentials: z.boolean().default(false),
        enabled: z.boolean().default(true),
        lastCheckedAt: z.coerce.date().nullable().optional(),
        packagesCount: z.number().optional(),
        ...baseSource.shape,
    }),
])

export type Source = z.infer<typeof source>

export function fetchSources() {
    return get(source.array(), '/sources')
}

export const storeSourceInput = z.object({
    name: z.string(),
    provider: sourceProvider,
    url: z.string(),
    token: z.string().optional(),
    metadata: z.any().optional(),
    authType: composerSourceAuthType.optional(),
    username: z.string().optional(),
    password: z.string().optional(),
    enabled: z.boolean().optional(),
})

export type StoreSourceInput = z.infer<typeof storeSourceInput>

function sourceInput(input: StoreSourceInput | UpdateSourceInput) {
    const { authType, enabled, username, password, token, metadata, ...base } = input

    if (input.provider !== 'composer') {
        return { ...base, ...(token ? { token } : {}), metadata }
    }

    return {
        ...base,
        authType,
        enabled,
        ...(username ? { username } : {}),
        ...(password ? { password } : {}),
        ...(token ? { token } : {}),
    }
}

export function storeSource(input: StoreSourceInput) {
    return post(source, '/sources', sourceInput(input))
}

export const updateSourceInput = z.object({
    id: z.string(),
    name: z.string(),
    provider: sourceProvider,
    url: z.string(),
    token: z.string().optional(),
    metadata: z.any().optional(),
    authType: composerSourceAuthType.optional(),
    username: z.string().optional(),
    password: z.string().optional(),
    enabled: z.boolean().optional(),
})

export type UpdateSourceInput = z.infer<typeof updateSourceInput>

export function updateSource(input: z.infer<typeof updateSourceInput>) {
    const { id, provider, authType, enabled, username, password, token, metadata, ...base } = input
    const values =
        provider === 'composer'
            ? {
                  ...base,
                  authType,
                  enabled,
                  ...(username ? { username } : {}),
                  ...(password ? { password } : {}),
                  ...(token ? { token } : {}),
              }
            : { ...base, ...(token ? { token } : {}), metadata }

    return patch(source, `/sources/${id}`, values)
}

export function deleteSource(sourceId: string) {
    return del(source, `/sources/${sourceId}`)
}

export const sourceProject = z.object({
    id: z.coerce.string(),
    name: z.string(),
    fullName: z.string(),
    url: z.string(),
    webUrl: z.string(),
})

export function fetchSourceProjects(source: string, search?: string) {
    return get(sourceProject.array(), `/sources/${source}/projects?search=${search}`)
}
