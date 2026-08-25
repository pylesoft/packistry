import * as React from 'react'
import axios from 'axios'
import { toast } from 'sonner'
import { ComposerUpstream } from '@/api'
import { useDeleteComposerUpstream } from '@/api/hooks'
import { Button, ButtonProps } from '@/components/ui/button'

export type DeleteComposerUpstreamButtonProps = {
    upstream: Pick<ComposerUpstream, 'id' | 'name'>
} & ButtonProps

export function DeleteComposerUpstreamButton({ upstream, ...props }: DeleteComposerUpstreamButtonProps) {
    const mutation = useDeleteComposerUpstream()

    function remove() {
        mutation.mutate(upstream.id, {
            onSuccess: () => toast(`${upstream.name} has been deleted`),
            onError: (error) => {
                const message = axios.isAxiosError(error)
                    ? error.response?.data?.errors?.upstream?.[0] || error.response?.data?.message
                    : undefined

                toast.error('Unable to delete Composer upstream', {
                    description: typeof message === 'string' ? message : 'Please try again.',
                })
            },
        })
    }

    return (
        <Button
            variant="ghost"
            onClick={remove}
            dangerous={{
                title: 'Delete Composer Upstream?',
                description: `Are you sure you want to permanently delete ${upstream.name}?`,
                confirm: {
                    loading: mutation.isPending,
                },
            }}
            {...props}
        >
            Remove
        </Button>
    )
}
