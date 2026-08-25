import * as React from 'react'
import { FormSelect, FormSelectProps } from '@/components/form/elements/form-select'
import { useComposerUpstreams } from '@/api/hooks'
import { ComposerUpstream } from '@/api'
import { Optional } from '@/helpers'
import { Globe2 } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'

export function FormComposerUpstreamSelect(props: Omit<Optional<FormSelectProps, 'name' | 'label'>, 'options'>) {
    const query = useComposerUpstreams()
    const upstreams = (query.data || []).filter((upstream: ComposerUpstream) => upstream.enabled)

    return (
        <FormSelect
            label="Composer upstream"
            name="upstreamId"
            {...props}
            empty={{
                title: 'No Composer upstreams',
                icon: <Globe2 />,
                description: 'Add a Composer upstream before enrolling a package.',
                button: (
                    <Link to="/sources">
                        <Button variant="outline">Manage Sources</Button>
                    </Link>
                ),
            }}
            options={upstreams.map((upstream) => ({
                value: upstream.id,
                label: upstream.name,
            }))}
        />
    )
}
