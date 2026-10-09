import { apiGet, apiPost } from '@/lib/api'

/**
 * What a project's code is doing, as مهتم knows it.
 *
 * Everything here was written by a webhook or by the repair sweep — opening the
 * screen never calls GitHub, so a page that is slow to render is a slow
 * database and never a spent API budget.
 */

export type Readiness = { state: 'ready' | 'partial' | 'broken'; reasons: string[] }

export interface DevelopmentRepository {
  id: string
  full_name: string
  url: string
  default_branch: string
  develop_branch: string | null
  production_branch: string | null
  readiness: Readiness
  last_webhook_at: string | null
  last_sync_at: string | null
}

/** The word the list shows. Computed on the server so the rules live in one place. */
export type PullRequestReadiness =
  | 'merged' | 'closed' | 'draft' | 'ci_failed'
  | 'waiting_on_ci' | 'changes_requested' | 'ready_to_merge' | 'open'

export interface DevelopmentPullRequest {
  number: number
  title: string
  author: string | null
  head_branch: string
  base_branch: string
  state: 'open' | 'merged' | 'closed'
  draft: boolean
  checks: 'unknown' | 'pending' | 'passed' | 'failed'
  review: string
  readiness: PullRequestReadiness
  url: string
  opened_at: string | null
  merged_at: string | null
}

export interface DevelopmentRun {
  name: string
  branch: string | null
  outcome: 'passed' | 'failed' | 'running' | 'queued' | 'cancelled' | 'skipped' | 'unknown'
  conclusion: string | null
  commit_sha: string | null
  url: string
  completed_at: string | null
}

export interface DevelopmentDeployment {
  environment: string
  state: string
  /**
   * The only word that may be read as "live" is `verified`. `reported` means
   * the deploy job finished without error and nobody has asked the application
   * — which is exactly what the previous pipeline showed as deployed while
   * nothing had shipped.
   */
  truth: 'verified' | 'reported' | 'failed' | 'superseded' | 'in_progress'
  verified_at: string | null
  commit_sha: string | null
  ref: string | null
  url: string | null
  at: string | null
}

export interface DevelopmentRelease {
  tag: string
  name: string | null
  prerelease: boolean
  author: string | null
  url: string
  published_at: string | null
}

export type ActivityKind =
  | 'commit' | 'pr_opened' | 'pr_merged' | 'pr_closed'
  | 'ci_passed' | 'ci_failed' | 'deployed' | 'deploy_failed' | 'released'

export interface DevelopmentActivity {
  id: string
  kind: ActivityKind
  title: string
  actor: string | null
  ref: string | null
  commit_sha: string | null
  url: string | null
  meta: Record<string, unknown> | null
  at: string | null
}

export interface DevelopmentView {
  repository: DevelopmentRepository | null
  pull_requests?: DevelopmentPullRequest[]
  ci?: { staging: DevelopmentRun | null; production: DevelopmentRun | null }
  deployments?: DevelopmentDeployment[]
  release?: DevelopmentRelease | null
  activity?: DevelopmentActivity[]
}

export function fetchDevelopment(projectId: string) {
  return apiGet<DevelopmentView>(`/projects/${projectId}/development`)
}

export interface GitHubConnection {
  state: 'connected' | 'disconnected' | 'needs_reauth' | 'rate_limited' | 'error'
  account: string | null
  connected: boolean
  has_token: boolean
  last_error: string | null
  last_checked_at?: string | null
  budget: { remaining: number | null; limit: number | null; resets_at: string | null; low: boolean } | null
}

export interface GitHubRepositoryHealth {
  id: string
  project: string | null
  project_id: string | null
  full_name: string
  readiness: Readiness
  webhook_status: string
  signature_failures: number
  last_webhook_at: string | null
  last_sync_at: string | null
  last_error: string | null
  pending_deliveries: number
  failed_deliveries: number
}

export function fetchGitHub() {
  return apiGet<{ connection: GitHubConnection; repositories?: GitHubRepositoryHealth[] }>(
    '/integrations/github',
  )
}

export function connectGitHub(token: string) {
  return apiPost<{ connection: GitHubConnection }>('/integrations/github', { token })
}

export function testGitHub() {
  return apiPost<{ connection: GitHubConnection }>('/integrations/github/test', {})
}

/**
 * Not "retry failed deliveries": the payload is never stored, so there is
 * nothing to replay. This asks GitHub what the state is and repairs from that.
 */
export function syncGitHub(repository?: string) {
  return apiPost<{ queued: number }>('/integrations/github/sync', repository ? { repository } : {})
}

export function fetchRepositories(search?: string) {
  return apiGet<{ repositories: Array<{ id: number; full_name: string; default_branch: string; private: boolean }> }>(
    `/integrations/github/repositories${search ? `?search=${encodeURIComponent(search)}` : ''}`,
  )
}

export function linkRepository(projectId: string, body: { full_name: string; develop_branch?: string | null; production_branch?: string | null }) {
  return apiPost(`/projects/${projectId}/repository`, body)
}
