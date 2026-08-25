import * as React from 'react'
import { ExternalLinkIcon, Globe2, ShieldCheck } from 'lucide-react'
import { ComposerUpstream } from '@/api'
import { useAuth } from '@/auth'
import { COMPOSER_UPSTREAM_DELETE, COMPOSER_UPSTREAM_UPDATE } from '@/permission'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { EditComposerUpstreamDialog } from '@/components/dialog/edit-composer-upstream-dialog'
import { cn } from '@/lib/utils'
import { DeleteComposerUpstreamButton } from '@/components/button/delete-composer-upstream-button'

export function ComposerUpstreamCard({ upstream, className }: { upstream: ComposerUpstream; className?: string }) {
    const { can } = useAuth()
    const validated = !!upstream.lastCheckedAt

    return (
        <Card className={cn('overflow-hidden', className)}>
            <CardContent className="p-0">
                <div className="bg-slate-700 text-white p-6 pb-4">
                    <div className="flex justify-between items-center gap-3">
                        <h3 className="font-semibold text-lg truncate">{upstream.name}</h3>
                        <Globe2 className="h-6 w-6 shrink-0" />
                    </div>
                    <p className="text-sm mt-1 opacity-90 truncate">{upstream.url}</p>
                </div>
                <div className="p-6 pt-4 space-y-4">
                    <div className="flex flex-wrap gap-2 items-center">
                        <Badge variant="outline">COMPOSER</Badge>
                        <Badge
                            variant="outline"
                            className="capitalize"
                        >
                            {upstream.authType === 'basic' ? 'HTTP Basic' : upstream.authType}
                        </Badge>
                        <Badge
                            variant="outline"
                            className={cn(validated && 'bg-green-500/10 text-green-600 border-green-500/20')}
                        >
                            {validated && <ShieldCheck className="h-3.5 w-3.5 mr-1" />}
                            {validated ? 'Validated' : 'Not validated'}
                        </Badge>
                    </div>
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <span>{upstream.hasCredentials ? 'Credentials configured' : 'No credentials'}</span>
                        <span>{upstream.enabled ? 'Enabled' : 'Disabled'}</span>
                    </div>
                    <div className="flex space-x-2">
                        {can(COMPOSER_UPSTREAM_UPDATE) && (
                            <EditComposerUpstreamDialog
                                upstream={upstream}
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
                            href={upstream.url}
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
                    {can(COMPOSER_UPSTREAM_DELETE) && (
                        <DeleteComposerUpstreamButton
                            upstream={upstream}
                            className="w-full text-destructive"
                        />
                    )}
                </div>
            </CardContent>
        </Card>
    )
}
