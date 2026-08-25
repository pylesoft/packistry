import { z } from 'zod'
import { GitFork, GithubIcon, GitlabIcon, Globe2 } from 'lucide-react'

export const GITEA = 'gitea'
export const GITLAB = 'gitlab'
export const GITHUB = 'github'
export const BITBUCKET = 'bitbucket'
export const COMPOSER = 'composer'

export const sourceProviders = [GITHUB, GITEA, GITLAB, BITBUCKET, COMPOSER] as const
export const sourceProvider = z.enum(sourceProviders)

export type SourceProvider = z.infer<typeof sourceProvider>

export const providerUrls: Record<SourceProvider, string> = {
    gitea: 'https://gitea.com',
    github: 'https://api.github.com',
    gitlab: 'https://gitlab.com',
    bitbucket: 'https://api.bitbucket.org',
    composer: '',
}

export const providerNames: Record<SourceProvider, string> = {
    gitea: 'Gitea',
    github: 'GitHub',
    gitlab: 'GitLab',
    bitbucket: 'Bitbucket',
    composer: 'Composer Repository',
}

export const providerIcons: Record<SourceProvider, typeof GithubIcon> = {
    github: GithubIcon,
    gitlab: GitlabIcon,
    gitea: GitFork,
    bitbucket: GitFork,
    composer: Globe2,
}

export const providerColors: Record<SourceProvider, string> = {
    github: 'bg-[#24292e] text-white',
    gitlab: 'bg-[#e2492f] text-white',
    gitea: 'bg-[#609926] text-white',
    bitbucket: 'bg-[#0052cc] text-white',
    composer: 'bg-slate-700 text-white',
}
