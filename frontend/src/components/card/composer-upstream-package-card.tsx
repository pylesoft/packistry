import * as React from 'react'
import { formatDistance } from 'date-fns'
import { RefreshCw, ShieldCheck, ShieldOff } from 'lucide-react'
import { toast } from 'sonner'
import { ComposerUpstream } from '@/api'
import { useRefreshComposerPackage } from '@/api/hooks'
import { useAuth } from '@/auth'
import { PACKAGE_UPDATE } from '@/permission'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { cn } from '@/lib/utils'

export function ComposerUpstreamPackageCard({
    upstream,
    packageId,
    lastCheckedAt,
    lastSyncedAt,
    lastError,
    refreshActive = false,
}: {
    upstream: ComposerUpstream
    packageId: string
    lastCheckedAt?: Date | null
    lastSyncedAt?: Date | null
    lastError?: string | null
    refreshActive?: boolean
}) {
    const mutation = useRefreshComposerPackage()
    const { can } = useAuth()
    const status = lastError ? 'unhealthy' : lastCheckedAt || lastSyncedAt ? 'healthy' : 'unknown'
    const refreshing = refreshActive || mutation.isPending

    function refresh() {
        mutation.mutate(packageId, {
            onSuccess: ({ batchId }) =>
                toast('Composer package refresh has been started', {
                    description: `Batch ${batchId}`,
                }),
        })
    }

    return (
        <Card className="h-full">
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between gap-3">
                    <CardTitle className="text-base">Composer upstream</CardTitle>
                    <Badge
                        variant="outline"
                        className="capitalize"
                    >
                        {status === 'healthy' ? (
                            <ShieldCheck className="h-3.5 w-3.5 mr-1 text-green-600" />
                        ) : status === 'unhealthy' ? (
                            <ShieldOff className="h-3.5 w-3.5 mr-1 text-red-600" />
                        ) : null}
                        {status}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent className="space-y-3">
                <div>
                    <p className="font-medium">{upstream.name}</p>
                    <p className="text-sm text-muted-foreground truncate">{upstream.url}</p>
                </div>
                <div className="flex items-center justify-between text-sm text-muted-foreground">
                    <span>{upstream.enabled ? 'Enabled' : 'Disabled'}</span>
                    <span>
                        {lastSyncedAt
                            ? `Synced ${formatDistance(lastSyncedAt, new Date(), { addSuffix: true })}`
                            : 'Not synchronized yet'}
                    </span>
                </div>
                {lastError && <p className="text-sm text-destructive line-clamp-2">{lastError}</p>}
                {can(PACKAGE_UPDATE) && (
                    <Button
                        variant="outline"
                        size="sm"
                        className={cn('w-full', refreshing && 'cursor-wait')}
                        disabled={!upstream.enabled || refreshing}
                        loading={refreshing}
                        onClick={refresh}
                    >
                        {!refreshing && <RefreshCw className="h-4 w-4 mr-2" />}
                        {refreshActive ? 'Refresh in progress' : 'Refresh now'}
                    </Button>
                )}
            </CardContent>
        </Card>
    )
}
