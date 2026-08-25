import * as React from 'react'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Globe2 } from 'lucide-react'
import { Form } from '@/components/ui/form'
import { useEnrollComposerPackage } from '@/api/hooks'
import { useForm } from '@/hooks/useForm'
import { FormRepositorySelect } from '@/components/form/elements/form-repository-select'
import { FormComposerUpstreamSelect } from '@/components/form/elements/form-composer-upstream-select'
import { FormInput } from '@/components/form/elements/form-input'
import { useInnerDialog } from '@/components/dialog/use-search-dialog'
import { useAuth } from '@/auth'
import { COMPOSER_UPSTREAM_READ, PACKAGE_CREATE } from '@/permission'
import { DialogProps } from '@radix-ui/react-dialog'
import { toast } from 'sonner'

export function EnrollComposerPackageDialog(props: DialogProps) {
    const { can } = useAuth()
    const mutation = useEnrollComposerPackage()
    const dialogProps = useInnerDialog(props)
    const { form, onSubmit, isPending } = useForm({
        mutation,
        defaultValues: {
            repositoryId: '',
            upstreamId: '',
            name: '',
        },
        onSuccess(result) {
            toast(`${result.package.name} synchronization has been started`, {
                description: `Batch ${result.batchId}`,
            })
            form.reset()
            dialogProps.onOpenChange?.(false)
        },
    })

    return (
        <Dialog {...dialogProps}>
            <DialogTrigger asChild>
                {can(PACKAGE_CREATE) && can(COMPOSER_UPSTREAM_READ) && (
                    <Button variant="outline">
                        <Globe2 className="h-4 w-4 mr-2" />
                        Enroll Composer package
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Enroll Composer package</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form
                        onSubmit={onSubmit}
                        className="space-y-4"
                    >
                        <FormRepositorySelect
                            name="repositoryId"
                            description="Select the existing repository that will serve this package."
                            control={form.control}
                        />
                        <FormComposerUpstreamSelect
                            description="Choose the Composer upstream that owns this package."
                            control={form.control}
                        />
                        <FormInput
                            name="name"
                            label="Exact package name"
                            placeholder="vendor/package"
                            description="Enter one exact Composer package name, such as dedoc/scramble-pro."
                            control={form.control}
                        />
                        <Button
                            type="submit"
                            loading={isPending}
                        >
                            Start synchronization
                        </Button>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    )
}
