import { FormInput } from '@/components/form/elements/form-input'
import * as React from 'react'
import { ReactElement } from 'react'
import { UseFormReturn } from 'react-hook-form'
import { FormSourceProviderSelect } from '@/components/form/elements/form-source-provider-select'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Info } from 'lucide-react'
import { providerNames, providerUrls, SourceProvider } from '@/api/source-provider'
import { StoreSourceInput, UpdateSourceInput } from '@/api'
import { FormSelect } from '@/components/form/elements/form-select'
import { FormSwitch } from '@/components/form/elements/form-switch'

export function SourceFormElements({
    form,
    disableProvider,
    existingComposerAuthType,
    hasCredentials = false,
}: {
    form: UseFormReturn<StoreSourceInput | UpdateSourceInput>
    disableProvider?: boolean
    existingComposerAuthType?: 'none' | 'basic' | 'bearer'
    hasCredentials?: boolean
}) {
    const url = form.watch('url')
    const provider = form.watch('provider') as SourceProvider | undefined | ''
    const authType = form.watch('authType')
    const preservesComposerCredentials = disableProvider && hasCredentials && authType === existingComposerAuthType

    return (
        <>
            <FormInput
                name="name"
                label="Name"
                description="Provide a name for this source to easily identify it."
                control={form.control}
            />
            <FormSourceProviderSelect
                disabled={disableProvider}
                description="Select the platform where your source is hosted."
                onChange={(value) => {
                    const nextProvider = value as SourceProvider

                    form.setValue('url', providerUrls[nextProvider])

                    if (nextProvider === 'composer') {
                        form.setValue('authType', 'none')
                        form.setValue('enabled', true)
                    }
                }}
                control={form.control}
            />
            <FormInput
                label="URL"
                name="url"
                placeholder="e.g. https://sub.domain.com"
                description={
                    provider === 'composer'
                        ? 'Use the HTTPS Composer repository base URL. Credentials are never sent to other origins.'
                        : 'For self-hosted variants update the url e.g. https://git.company.com.'
                }
                control={form.control}
            />
            {provider && provider !== 'composer' && (
                <TokenCreationAlert
                    url={url}
                    provider={provider}
                />
            )}
            {provider === 'composer' ? (
                <>
                    <FormSelect
                        name="authType"
                        label="Authentication"
                        description="Choose how Packistry authenticates with this Composer repository."
                        options={[
                            { value: 'none', label: 'None' },
                            { value: 'basic', label: 'HTTP Basic' },
                            { value: 'bearer', label: 'Bearer token' },
                        ]}
                        control={form.control}
                    />
                    {authType === 'basic' && (
                        <>
                            <FormInput
                                name="username"
                                label="Username"
                                description="Enter the username required by this Composer repository."
                                control={form.control}
                            />
                            <FormInput
                                name="password"
                                label={
                                    disableProvider ? 'Replacement password or license key' : 'Password or license key'
                                }
                                type="password"
                                description={
                                    preservesComposerCredentials
                                        ? 'Leave blank to preserve the existing write-only credential.'
                                        : 'This secret is encrypted and is never returned to the browser after saving.'
                                }
                                control={form.control}
                            />
                        </>
                    )}
                    {authType === 'bearer' && (
                        <FormInput
                            name="token"
                            label={disableProvider ? 'Replacement bearer token' : 'Bearer token'}
                            type="password"
                            description={
                                preservesComposerCredentials
                                    ? 'Leave blank to preserve the existing write-only credential.'
                                    : 'This secret is encrypted and is never returned to the browser after saving.'
                            }
                            control={form.control}
                        />
                    )}
                    {authType === 'none' && (
                        <Alert>
                            <Info className="h-4 w-4" />
                            <AlertTitle>No credentials will be sent</AlertTitle>
                            <AlertDescription>
                                Packistry will connect to this Composer repository without an Authorization header.
                            </AlertDescription>
                        </Alert>
                    )}
                    <FormSwitch
                        name="enabled"
                        label="Enabled"
                        description="Disabled sources keep their cached packages available but do not refresh."
                        control={form.control}
                    />
                </>
            ) : (
                <>
                    <FormInput
                        label="Token"
                        name="token"
                        type="password"
                        description={
                            disableProvider
                                ? 'Leave blank to preserve the existing write-only credential.'
                                : 'Enter your access token for authentication.'
                        }
                        control={form.control}
                    />

                    {provider === 'bitbucket' && (
                        <FormInput
                            label="Workspace"
                            name="metadata.workspace"
                            control={form.control}
                            description="Private repositories may be accessed within a workspace."
                        />
                    )}
                </>
            )}
        </>
    )
}

type VcsSourceProvider = Exclude<SourceProvider, 'composer'>

function TokenCreationAlert({ url, provider }: { url: string; provider: VcsSourceProvider }) {
    const fullUrl = url.indexOf('://') === -1 ? 'https://' + url : url

    const providerExplanations: Record<VcsSourceProvider, ReactElement> = {
        gitea: (
            <>
                Navigate to{' '}
                <a
                    rel="noreferrer"
                    target="_blank"
                    className="underline"
                    href={fullUrl + '/user/settings/applications'}
                >
                    {fullUrl}
                    /user/settings/applications
                </a>{' '}
                and create a token with repository read and write permission
            </>
        ),
        github: (
            <>
                Navigate to{' '}
                <a
                    rel="noreferrer"
                    target="_blank"
                    className="underline"
                    href={'https://github.com/settings/tokens/new'}
                >
                    https://github.com/settings/tokens/new
                </a>{' '}
                and create a token with repo scope
            </>
        ),
        gitlab: (
            <>
                Navigate to{' '}
                <a
                    rel="noreferrer"
                    target="_blank"
                    className="underline"
                    href={fullUrl + '/-/user_settings/personal_access_tokens'}
                >
                    {fullUrl + '/-/user_settings/personal_access_tokens'}
                </a>{' '}
                and create a token with api scope
            </>
        ),
        bitbucket: (
            <>
                Navigate to{' '}
                <a
                    rel="noreferrer"
                    target="_blank"
                    className="underline"
                    href={'https://id.atlassian.com/manage-profile/security/api-tokens'}
                >
                    https://id.atlassian.com/manage-profile/security/api-tokens
                </a>{' '}
                and create an API token with read:repository:bitbucket, read:webhook:bitbucket and
                write:webhook:bitbucket. Base64 encode email:api-token to create a token.
            </>
        ),
    }

    return (
        <Alert>
            <Info className="h-4 w-4" />
            <AlertTitle>{providerNames[provider]}</AlertTitle>
            <AlertDescription>{providerExplanations[provider]}</AlertDescription>
        </Alert>
    )
}
