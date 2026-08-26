import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { EditSourceDialog } from '@/components/dialog/edit-source-dialog'
import { Button } from '@/components/ui/button'
import { ExternalLinkIcon, GitlabIcon, ShieldCheck } from 'lucide-react'
import * as React from 'react'
import { Source } from '@/api'
import { providerColors, providerIcons } from '@/api/source-provider'
import { cn } from '@/lib/utils'
import { useAuth } from '@/auth'
import { SOURCE_UPDATE } from '@/permission'

export function SourceCard({ source, className }: { source: Source; className?: string }) {
    const ProviderIcon = providerIcons[source.provider] || GitlabIcon
    const { can } = useAuth()

    return (
        <Card
            key={source.id}
            className={cn('overflow-hidden', className)}
        >
            <CardContent className="p-0">
                <div className={`p-6 pb-4 ${providerColors[source.provider] || 'bg-primary text-primary-foreground'}`}>
                    <div className="flex justify-between items-center">
                        <h3 className="font-semibold text-lg">{source.name}</h3>
                        <ProviderIcon className="h-6 w-6" />
                    </div>
                    <div className="flex justify-between">
                        <p className="text-sm mt-1 opacity-90">{source.url}</p>
                        <p className="text-sm mt-1 opacity-90">
                            {source.provider === 'bitbucket' && source.metadata.workspace
                                ? `Workspace: ${source.metadata.workspace}`
                                : ''}
                        </p>
                    </div>
                </div>
                <div className="p-6 pt-4">
                    <div className="flex flex-wrap justify-between items-center gap-2 mb-4">
                        <Badge
                            variant="outline"
                            className="text-xs"
                        >
                            {source.provider === 'composer' ? 'COMPOSER' : source.provider.toUpperCase()}
                        </Badge>
                        {source.provider === 'composer' ? (
                            <>
                                <Badge
                                    variant="outline"
                                    className="capitalize"
                                >
                                    {source.authType === 'basic' ? 'HTTP Basic' : source.authType}
                                </Badge>
                                <Badge
                                    variant="outline"
                                    className={cn(
                                        source.lastCheckedAt && 'bg-green-500/10 text-green-600 border-green-500/20'
                                    )}
                                >
                                    {source.lastCheckedAt && <ShieldCheck className="h-3.5 w-3.5 mr-1" />}
                                    {source.lastCheckedAt ? 'Validated' : 'Not validated'}
                                </Badge>
                            </>
                        ) : (
                            <span className="text-sm text-muted-foreground">Token: ••••••••</span>
                        )}
                    </div>
                    {source.provider === 'composer' && (
                        <div className="flex items-center justify-between text-sm text-muted-foreground mb-4">
                            <span>{source.hasCredentials ? 'Credentials configured' : 'No credentials'}</span>
                            <span>{source.enabled ? 'Enabled' : 'Disabled'}</span>
                        </div>
                    )}
                    <div className="flex space-x-2">
                        {can(SOURCE_UPDATE) && (
                            <EditSourceDialog
                                source={source}
                                trigger={
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="w-full"
                                    >
                                        Manage
                                    </Button>
                                }
                            />
                        )}
                        <a
                            href={source.url}
                            className="w-full"
                            rel="noreferrer"
                            target="_blank"
                            tabIndex={-1}
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                className="w-full"
                            >
                                <ExternalLinkIcon className="h-4 w-4 mr-2" />
                                Open
                            </Button>
                        </a>
                    </div>
                </div>
            </CardContent>
        </Card>
    )
}
