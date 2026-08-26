import * as React from 'react'
import { createFileRoute, Link, useNavigate } from '@tanstack/react-router'
import { useBatches, usePackage, usePackageDownloads, usePackageVersions } from '@/api/hooks'
import { RepositoryCard } from '@/components/card/repository-card'
import { SourceCard } from '@/components/card/source-card'
import { LoadingRepositoryCard } from '@/components/card/loading-repository-card'
import { LoadingSourceCard } from '@/components/card/loading-source-card'
import { versionQuery } from '@/api/version'
import { VersionTable } from '@/components/table/version-table'
import { navigateOnSort } from '@/components/paginated-table'
import { navigateOnSearch, SearchBar } from '@/components/page/search-bar'
import { DownloadsCard } from '@/components/card/downloads-card'
import { Heading } from '@/components/page/heading'
import { Empty } from '@/components/empty'
import { Button } from '@/components/ui/button'
import { PackageIcon } from 'lucide-react'
import { is404 } from '@/api/axios'
import { CopyCommandTooltip } from '@/components/ui/tooltip'
import { PackageActionsDropdownMenu } from '@/components/dropdown-menu/package-actions-dropdown-menu'
import { ComposerSourcePackageCard } from '@/components/card/composer-source-package-card'
import { useAuth } from '@/auth'
import { BATCH_READ } from '@/permission'

export const Route = createFileRoute('/_auth/packages/$packageId')({
    validateSearch: versionQuery,
    component: PackagesComponent,
})

function PackagesComponent() {
    const { packageId } = Route.useParams()
    const search = Route.useSearch()

    const navigate = useNavigate()
    const query = usePackage(packageId)
    const { can } = useAuth()
    const downloads = usePackageDownloads(packageId)
    const versions = usePackageVersions(packageId, search)
    const composerSource = query.data?.source?.provider === 'composer' ? query.data.source : undefined
    const vcsSource = query.data?.source?.provider !== 'composer' ? query.data?.source : undefined
    const canReadBatches = !!composerSource && can(BATCH_READ)
    const batches = useBatches({
        enabled: canReadBatches,
        pollWhile: (items) =>
            (items || []).some(
                (batch) =>
                    batch.package?.id === query.data?.id && batch.finishedAt === null && batch.cancelledAt === null
            ),
    })
    const command = `composer require ${query.data?.name}`
    const refreshActive =
        canReadBatches &&
        (batches.data || []).some(
            (batch) => batch.package?.id === query.data?.id && batch.finishedAt === null && batch.cancelledAt === null
        )
    useRefetchPackageWhenRefreshFinishes(packageId, refreshActive, query.refetch)

    if (is404(query)) {
        return (
            <Empty
                icon={<PackageIcon />}
                title="Package not found"
                className="mt-24"
                button={
                    <Link to="/packages">
                        <Button>Back to Packages</Button>
                    </Link>
                }
            />
        )
    }

    return (
        <>
            <Heading title={query.data?.name}>
                <div className="flex items-center space-x-4">
                    <CopyCommandTooltip command={command} />
                    <PackageActionsDropdownMenu pkg={query.data} />
                </div>
            </Heading>
            <DownloadsCard data={downloads.data} />
            <div className="grid gap-4 md:grid-cols-2 items-stretch">
                {query.data?.repository ? (
                    <RepositoryCard
                        className="h-full"
                        repository={query.data.repository}
                    />
                ) : (
                    <LoadingRepositoryCard className="h-full" />
                )}
                {vcsSource ? (
                    <SourceCard
                        className="h-full"
                        source={vcsSource}
                    />
                ) : (
                    query.data?.source === undefined && <LoadingSourceCard className="h-full" />
                )}
                {composerSource && query.data && (
                    <ComposerSourcePackageCard
                        source={composerSource}
                        packageId={query.data.id}
                        lastCheckedAt={query.data.upstreamCheckedAt}
                        lastSyncedAt={query.data.upstreamSyncedAt}
                        lastError={query.data.upstreamLastError}
                        refreshActive={refreshActive}
                    />
                )}
            </div>
            <SearchBar
                name="Versions"
                search={search.filters?.search}
                onSearch={navigateOnSearch(navigate)}
            />
            <VersionTable
                query={versions}
                sort={search.sort}
                onSort={navigateOnSort(navigate)}
            />
        </>
    )
}

function useRefetchPackageWhenRefreshFinishes(
    packageId: string,
    refreshActive: boolean,
    refetchPackage: () => Promise<unknown>
) {
    const previous = React.useRef({ packageId, refreshActive: false })

    React.useEffect(() => {
        if (previous.current.packageId !== packageId) {
            previous.current = { packageId, refreshActive }

            return
        }

        if (previous.current.refreshActive && !refreshActive) {
            void refetchPackage()
        }

        previous.current.refreshActive = refreshActive
    }, [packageId, refreshActive, refetchPackage])
}
