import { createFileRoute, Link } from '@tanstack/react-router'
import * as React from 'react'
import { ReactNode, useState } from 'react'
import { useComposerUpstreams, useSources } from '@/api/hooks'
import { AddSourceDialog } from '@/components/dialog/add-source-dialog'
import { AddComposerUpstreamDialog } from '@/components/dialog/add-composer-upstream-dialog'
import { Heading } from '@/components/page/heading'
import { SourceCard } from '@/components/card/source-card'
import FailedToLoad from '@/components/failed-to-load'
import { LoadingSourceCard } from '@/components/card/loading-source-card'
import { Empty } from '@/components/empty'
import { Button } from '@/components/ui/button'
import { CodeIcon } from 'lucide-react'
import { SearchBar } from '@/components/page/search-bar'
import { z } from 'zod'
import { useSearchDialog } from '@/components/dialog/use-search-dialog'
import { ComposerUpstreamCard } from '@/components/card/composer-upstream-card'
import { ComposerUpstream, Source } from '@/api'
import { useAuth } from '@/auth'
import { COMPOSER_UPSTREAM_READ } from '@/permission'

export const Route = createFileRoute('/_auth/sources')({
    validateSearch: z.object({
        open: z.boolean().optional(),
    }),
    component: SourcesComponent,
})

function SourcesComponent() {
    const query = useSources()
    const { can } = useAuth()
    const composerQuery = useComposerUpstreams({ enabled: can(COMPOSER_UPSTREAM_READ) })
    const search = Route.useSearch()
    const dialogProps = useSearchDialog(search)

    const [searchTerm, setSearchTerm] = useState('')

    const filteredSources = (query.data || []).filter(
        (source) =>
            source.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
            source.url.toLowerCase().includes(searchTerm.toLowerCase())
    )

    return (
        <>
            <Heading title="Sources">
                <div className="flex items-center gap-3">
                    <AddSourceDialog {...dialogProps} />
                    <AddComposerUpstreamDialog />
                </div>
            </Heading>
            <SearchBar
                name="sources"
                search={searchTerm}
                onSearch={setSearchTerm}
            />
            <PageContent
                sources={filteredSources}
                query={query}
                composerUpstreams={(composerQuery.data || []).filter(
                    (upstream) =>
                        upstream.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
                        upstream.url.toLowerCase().includes(searchTerm.toLowerCase())
                )}
                composerQuery={composerQuery}
            />
        </>
    )
}

function PageContent({
    sources,
    query,
    composerUpstreams,
    composerQuery,
}: {
    sources: Source[]
    query: ReturnType<typeof useSources>
    composerUpstreams: ComposerUpstream[]
    composerQuery: ReturnType<typeof useComposerUpstreams>
}) {
    const Grid = ({ children }: { children: ReactNode }) => {
        return <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">{children}</div>
    }

    if (query.isLoading || composerQuery.isLoading) {
        return (
            <Grid>
                <LoadingSourceCard />
                <LoadingSourceCard />
                <LoadingSourceCard />
            </Grid>
        )
    }

    if (query.isError || composerQuery.isError) {
        return <FailedToLoad />
    }

    if (sources.length === 0 && composerUpstreams.length === 0) {
        return (
            <div>
                <Empty
                    className="mt-24"
                    icon={<CodeIcon />}
                    title="No Sources"
                    description="You haven't created any sources yet. Create a source to get started."
                    button={
                        <Link
                            to="."
                            search={{ open: true }}
                        >
                            <Button>
                                <span className="mr-2">+</span> Add Source
                            </Button>
                        </Link>
                    }
                />
            </div>
        )
    }

    return (
        <Grid>
            {sources.map((source) => (
                <SourceCard
                    key={source.id}
                    source={source}
                />
            ))}
            {composerUpstreams.map((upstream) => (
                <ComposerUpstreamCard
                    key={`composer-${upstream.id}`}
                    upstream={upstream}
                />
            ))}
        </Grid>
    )
}
