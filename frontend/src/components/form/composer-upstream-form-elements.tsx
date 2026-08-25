import * as React from 'react'
import { UseFormReturn } from 'react-hook-form'
import { Info } from 'lucide-react'
import { StoreComposerUpstreamInput, UpdateComposerUpstreamInput } from '@/api'
import { FormInput } from '@/components/form/elements/form-input'
import { FormSelect } from '@/components/form/elements/form-select'
import { FormSwitch } from '@/components/form/elements/form-switch'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'

type ComposerUpstreamFormValues = StoreComposerUpstreamInput | UpdateComposerUpstreamInput

export function ComposerUpstreamFormElements({
    form,
    edit = false,
}: {
    form: UseFormReturn<ComposerUpstreamFormValues>
    edit?: boolean
}) {
    const authType = form.watch('authType')

    return (
        <>
            <FormInput
                name="name"
                label="Name"
                description="A recognizable name for this Composer repository."
                control={form.control}
            />
            <FormInput
                name="url"
                label="Base URL"
                placeholder="https://composer.example.com"
                description="Use the Composer repository origin only; credentials are never sent to other origins."
                control={form.control}
            />
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
                        placeholder="Account email"
                        description="Paid Composer repositories commonly use the account email here."
                        control={form.control}
                    />
                    <FormInput
                        name="password"
                        label={edit ? 'Replacement password or license key' : 'Password or license key'}
                        type="password"
                        description={
                            edit
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
                    label={edit ? 'Replacement bearer token' : 'Bearer token'}
                    type="password"
                    description={
                        edit
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
                description="Disabled upstreams keep their cached packages available but do not refresh."
                control={form.control}
            />
        </>
    )
}
