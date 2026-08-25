import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { PlusIcon } from 'lucide-react'
import * as React from 'react'
import { FormRepositorySelect } from '@/components/form/elements/form-repository-select'
import { FormSourceSelect } from '@/components/form/elements/form-source-select'
import { FormSourceProjectCheckboxGroup } from '@/components/form/elements/form-source-project-checkbox-group'
import { FormSwitch } from '@/components/form/elements/form-switch'
import { FormInput } from '@/components/form/elements/form-input'
import { Form } from '@/components/ui/form'
import { useSources, useStorePackage } from '@/api/hooks'
import { useForm } from '@/hooks/useForm'
import { toast } from 'sonner'
import { useInnerDialog } from '@/components/dialog/use-search-dialog'
import { useAuth } from '@/auth'
import { PACKAGE_CREATE } from '@/permission'
import { DialogProps } from '@radix-ui/react-dialog'

export type AddPackageDialogProps = DialogProps
export function AddPackageDialog(props: AddPackageDialogProps) {
    const { can } = useAuth()
    const mutation = useStorePackage()
    const dialogProps = useInnerDialog(props)

    const { form, onSubmit, isPending } = useForm({
        mutation,
        defaultValues: {
            repository: '',
            source: '',
            projects: [],
            webhook: true,
            name: '',
        },
        onSuccess() {
            toast('Package import has been started')

            form.reset()
            dialogProps.onOpenChange?.(false)
        },
    })

    const source = form.watch('source')
    const sources = useSources()
    const selectedSource = sources.data?.find((item) => item.id === source)

    return (
        <Dialog {...dialogProps}>
            <DialogTrigger asChild>
                {can(PACKAGE_CREATE) && (
                    <Button>
                        <PlusIcon className="h-4 w-4 mr-2" />
                        Add Package
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add New Package</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form
                        onSubmit={onSubmit}
                        className="space-y-4"
                    >
                        <FormRepositorySelect
                            description="Select the repository where this package will be added."
                            control={form.control}
                        />
                        <FormSourceSelect
                            description="Choose the source from which to add a package."
                            control={form.control}
                        />
                        {selectedSource?.provider === 'composer' && (
                            <FormInput
                                name="name"
                                label="Exact package name"
                                placeholder="vendor/package"
                                description="Enter one exact Composer package name, such as dedoc/scramble-pro."
                                control={form.control}
                            />
                        )}
                        {selectedSource && selectedSource.provider !== 'composer' && (
                            <>
                                <FormSourceProjectCheckboxGroup
                                    source={source}
                                    description="Select one or more projects to add as packages."
                                    control={form.control}
                                />
                                <FormSwitch
                                    label="Webhook"
                                    description="Automatically synchronize new pushes for tags and branches with webhooks."
                                    name="webhook"
                                    control={form.control}
                                />
                            </>
                        )}
                        <Button
                            type="submit"
                            loading={isPending}
                        >
                            Add package
                        </Button>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    )
}
