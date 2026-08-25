import * as React from 'react'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Form } from '@/components/ui/form'
import { useUpdateComposerUpstream } from '@/api/hooks'
import { useForm } from '@/hooks/useForm'
import { ComposerUpstreamFormElements } from '@/components/form/composer-upstream-form-elements'
import { ComposerUpstream, updateComposerUpstreamInput } from '@/api'
import { DialogProps } from '@radix-ui/react-dialog'
import { ReactNode, useState } from 'react'

export function EditComposerUpstreamDialog({ upstream, trigger }: { upstream: ComposerUpstream; trigger?: ReactNode } & DialogProps) {
    const mutation = useUpdateComposerUpstream()
    const [open, setOpen] = useState(false)
    const { form, onSubmit, isPending } = useForm({
        mutation,
        schema: updateComposerUpstreamInput,
        defaultValues: {
            id: upstream.id,
            name: upstream.name,
            url: upstream.url,
            authType: upstream.authType,
            username: '',
            password: '',
            token: '',
            enabled: upstream.enabled,
        },
        onSuccess() {
            form.setValue('password', '')
            form.setValue('token', '')
            setOpen(false)
        },
    })

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger || <Button>Edit upstream</Button>}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Manage Composer upstream</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={onSubmit} className="space-y-4">
                        <ComposerUpstreamFormElements edit form={form} />
                        <Button loading={isPending} type="submit">
                            Save changes
                        </Button>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    )
}
