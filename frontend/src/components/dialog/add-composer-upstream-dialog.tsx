import * as React from 'react'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { PlusIcon } from 'lucide-react'
import { Form } from '@/components/ui/form'
import { useStoreComposerUpstream } from '@/api/hooks'
import { useForm } from '@/hooks/useForm'
import { ComposerUpstreamFormElements } from '@/components/form/composer-upstream-form-elements'
import { useInnerDialog } from '@/components/dialog/use-search-dialog'
import { useAuth } from '@/auth'
import { COMPOSER_UPSTREAM_CREATE } from '@/permission'
import { DialogProps } from '@radix-ui/react-dialog'
import { storeComposerUpstreamInput } from '@/api'

export function AddComposerUpstreamDialog(props: DialogProps) {
    const { can } = useAuth()
    const mutation = useStoreComposerUpstream()
    const dialogProps = useInnerDialog(props)
    const { form, onSubmit, isPending } = useForm({
        mutation,
        schema: storeComposerUpstreamInput,
        defaultValues: {
            name: '',
            url: '',
            authType: 'none' as const,
            username: '',
            password: '',
            token: '',
            enabled: true,
        },
        onSuccess() {
            form.reset()
            dialogProps.onOpenChange?.(false)
        },
    })

    return (
        <Dialog {...dialogProps}>
            <DialogTrigger asChild>
                {can(COMPOSER_UPSTREAM_CREATE) && (
                    <Button variant="outline">
                        <PlusIcon className="h-4 w-4 mr-2" />
                        Add Composer upstream
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add Composer upstream</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form
                        onSubmit={onSubmit}
                        className="space-y-4"
                    >
                        <ComposerUpstreamFormElements form={form} />
                        <Button
                            loading={isPending}
                            type="submit"
                        >
                            Add upstream
                        </Button>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    )
}
