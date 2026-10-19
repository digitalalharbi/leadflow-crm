import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Bot, KeyRound, Plus, Send, ShieldOff, Sparkles, User as UserIcon, Wrench } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, Input, Modal, PageHeader } from '@/components/ui'
import { MessageBlocks } from '@/components/agents/MessageBlocks'
import {
  AGENT_KEYS, forgetAiKey, saveAiKey, sendMessage, startConversation, useAiStatus, useConversations, useThread,
  type AgentKey, type ChatMessage,
} from '@/features/agentChat'
import { can, useAuth } from '@/stores/auth'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import { cn } from '@/lib/cn'

const SUGGESTIONS = ['performance', 'analyse', 'propose', 'creatives', 'quality', 'report'] as const

/**
 * Talking to the agents.
 *
 * Ask in plain words; the answer comes back as a few lines and the figures
 * themselves — tables, rankings, findings — and any change the agent wants is
 * an action card with approve, reject and edit right there. Nothing the agent
 * says changes an ad account; only the approve button does, and only through
 * the gateway.
 *
 * Without an AI key the same conversation works from the agents' rules, and
 * every reply says so — the blocked state is shown, not hidden.
 */
export function AgentsPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const user = useAuth((s) => s.user)

  const [threadId, setThreadId] = useState<string | null>(null)
  const [agent, setAgent] = useState<AgentKey>('orchestrator')
  const [text, setText] = useState('')
  const [keyOpen, setKeyOpen] = useState(false)
  const [key, setKey] = useState('')
  const bottom = useRef<HTMLDivElement>(null)

  const ai = useAiStatus()
  const threads = useConversations()
  const thread = useThread(threadId)

  const send = useMutation({
    mutationFn: (message: string) => (threadId ? sendMessage(threadId, message, agent) : startConversation(message, agent)),
    onSuccess: (result) => {
      setText('')
      setThreadId(result.id)
      queryClient.setQueryData(['agent-thread', result.id], result)
      void queryClient.invalidateQueries({ queryKey: ['agent-conversations'] })
      void queryClient.invalidateQueries({ queryKey: ['proposals'] })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const storeKey = useMutation({
    mutationFn: () => saveAiKey(key),
    onSuccess: (status) => {
      queryClient.setQueryData(['agent-ai'], status)
      setKey('')
      setKeyOpen(false)
      toast(status.verified_at ? t('agentChat.keyOk') : (status.last_error ?? t('agentChat.keyFailed')), status.verified_at ? 'success' : 'error')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const dropKey = useMutation({
    mutationFn: forgetAiKey,
    onSuccess: (status) => queryClient.setQueryData(['agent-ai'], status),
  })

  useEffect(() => {
    bottom.current?.scrollIntoView({ block: 'end' })
  }, [thread.data?.messages.length, send.isPending])

  const ready = ai.data?.status.state === 'ready'
  const messages = (thread.data?.messages ?? []).filter((m) => m.role !== 'tool')
  const toolsUsed = (i: number) => {
    const all = thread.data?.messages ?? []
    const at = all.indexOf(messages[i])
    const out: string[] = []
    for (let j = at - 1; j >= 0 && all[j].role === 'tool'; j--) out.unshift(all[j].content)
    return out
  }

  return (
    <>
      <PageHeader title={t('agentChat.title')} />

      <div className="grid gap-3 lg:grid-cols-12">
        <aside className="space-y-3 lg:col-span-3">
          {/* Whether the agents can think — stated, with the way to fix it. */}
          <Card data-testid="ai-status" data-state={ai.data?.status.state ?? 'loading'}>
            <CardBody className="space-y-2">
              <p className="flex items-center gap-2 text-[0.8125rem] font-semibold">
                {ready ? <Sparkles className="size-4 text-[var(--brand)]" aria-hidden /> : <ShieldOff className="size-4 text-[var(--color-warning)]" aria-hidden />}
                {ready ? t('agentChat.aiOn') : t('agentChat.aiOff')}
              </p>
              <p className="text-[0.75rem] text-[var(--color-ink-muted)]">
                {ready ? t(`agentChat.source.${ai.data?.source ?? 'platform'}`) : t('agentChat.aiOffHint')}
              </p>
              {ai.data?.last_error && <p className="text-[0.6875rem] text-[var(--color-danger)]">{ai.data.last_error}</p>}
              {can.manageIntegrations(user) && (
                <div className="flex gap-2">
                  <Button size="sm" variant="outline" icon={<KeyRound className="size-4" />} onClick={() => setKeyOpen(true)} data-testid="ai-key-open">
                    {ai.data?.source === 'workspace' ? t('agentChat.changeKey') : t('agentChat.addKey')}
                  </Button>
                  {ai.data?.source === 'workspace' && (
                    <Button size="sm" variant="ghost" loading={dropKey.isPending} onClick={() => dropKey.mutate()}>{t('agentChat.removeKey')}</Button>
                  )}
                </div>
              )}
            </CardBody>
          </Card>

          <Card>
            <CardBody className="space-y-1.5">
              <p className="mb-1 text-[0.6875rem] font-medium text-[var(--color-ink-subtle)]">{t('agentChat.askWho')}</p>
              {AGENT_KEYS.map((key) => (
                <button
                  key={key}
                  type="button"
                  onClick={() => setAgent(key)}
                  aria-pressed={agent === key}
                  data-testid={`pick-${key}`}
                  className={cn(
                    'flex w-full items-center gap-2 rounded-[var(--radius-control)] px-2 py-1.5 text-start text-[0.8125rem]',
                    agent === key ? 'bg-[var(--brand-soft)] font-semibold text-[var(--brand)]' : 'hover:bg-[var(--color-surface-muted)]',
                  )}
                >
                  <Bot className="size-4 shrink-0" aria-hidden />
                  {t(`agents.name.${key}`)}
                </button>
              ))}
            </CardBody>
          </Card>

          <Card>
            <CardBody className="space-y-1">
              <Button size="sm" variant="ghost" icon={<Plus className="size-4" />} onClick={() => setThreadId(null)} data-testid="new-conversation">
                {t('agentChat.new')}
              </Button>
              {(threads.data ?? []).slice(0, 12).map((th) => (
                <button
                  key={th.id}
                  type="button"
                  onClick={() => setThreadId(th.id)}
                  className={cn('block w-full truncate rounded-[var(--radius-control)] px-2 py-1.5 text-start text-[0.75rem]', th.id === threadId ? 'bg-[var(--color-surface-muted)] font-medium' : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-muted)]')}
                  dir="auto"
                >
                  {th.title}
                </button>
              ))}
            </CardBody>
          </Card>
        </aside>

        <section className="flex min-h-[32rem] flex-col lg:col-span-9" data-testid="conversation">
          <div className="flex-1 space-y-4 pb-3">
            {messages.length === 0 && !send.isPending && (
              <Card>
                <CardBody className="space-y-3 py-8 text-center">
                  <Bot className="mx-auto size-8 text-[var(--brand)]" aria-hidden />
                  <p className="text-[0.9375rem] font-semibold">{t('agentChat.emptyTitle')}</p>
                  <div className="flex flex-wrap justify-center gap-2">
                    {SUGGESTIONS.map((s) => (
                      <button key={s} type="button" onClick={() => send.mutate(t(`agentChat.suggest.${s}`))} className="rounded-full bg-[var(--color-surface-muted)] px-3 py-1.5 text-[0.75rem] hover:bg-[var(--brand-soft)]" data-testid={`suggest-${s}`}>
                        {t(`agentChat.suggest.${s}`)}
                      </button>
                    ))}
                  </div>
                </CardBody>
              </Card>
            )}

            {messages.map((m, i) => <Bubble key={i} message={m} tools={m.role === 'agent' ? toolsUsed(i) : []} />)}

            {send.isPending && (
              <p className="flex items-center gap-2 text-[0.8125rem] text-[var(--color-ink-muted)]" data-testid="agent-thinking">
                <Bot className="size-4 animate-pulse" aria-hidden />
                {t('agentChat.thinking', { agent: t(`agents.name.${agent}`) })}
              </p>
            )}
            <div ref={bottom} />
          </div>

          <form
            className="sticky bottom-0 flex items-end gap-2 rounded-[var(--radius-card)] bg-[var(--color-surface)] p-2 shadow-[var(--shadow-raised)]"
            onSubmit={(e) => {
              e.preventDefault()
              if (text.trim() !== '' && !send.isPending) send.mutate(text.trim())
            }}
          >
            <textarea
              value={text}
              onChange={(e) => setText(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                  e.preventDefault()
                  if (text.trim() !== '' && !send.isPending) send.mutate(text.trim())
                }
              }}
              rows={1}
              placeholder={t('agentChat.placeholder', { agent: t(`agents.name.${agent}`) })}
              className="max-h-40 min-h-10 flex-1 resize-none bg-transparent px-2 py-2 text-[0.875rem] outline-none"
              data-testid="chat-input"
              dir="auto"
            />
            <Button type="submit" icon={<Send className="size-4" />} loading={send.isPending} disabled={text.trim() === ''} data-testid="chat-send" aria-label={t('agentChat.send')} />
          </form>
        </section>
      </div>

      <Modal
        open={keyOpen}
        onClose={() => setKeyOpen(false)}
        title={t('agentChat.keyTitle')}
        description={t('agentChat.keyHint')}
        size="sm"
        footer={
          <>
            <Button variant="ghost" onClick={() => setKeyOpen(false)}>{t('common.cancel')}</Button>
            <Button loading={storeKey.isPending} disabled={key.trim().length < 20} onClick={() => storeKey.mutate()} data-testid="ai-key-save">{t('adConnections.saveAndTest')}</Button>
          </>
        }
      >
        <Input label="Anthropic API key" type="password" autoComplete="off" dir="ltr" value={key} onChange={(e) => setKey(e.target.value)} data-testid="ai-key-input" />
      </Modal>
    </>
  )
}

function Bubble({ message, tools }: { message: ChatMessage; tools: string[] }) {
  const { t } = useTranslation()
  const mine = message.role === 'user'
  const blocks = message.data?.blocks ?? []

  return (
    <div className={cn('flex gap-2.5', mine && 'flex-row-reverse')} data-testid={mine ? 'msg-user' : 'msg-agent'} data-mode={message.data?.mode}>
      <span className={cn('grid size-8 shrink-0 place-items-center rounded-full', mine ? 'bg-[var(--color-chrome-fill)]' : 'bg-[var(--brand)] text-[var(--brand-contrast)]')} aria-hidden>
        {mine ? <UserIcon className="size-4" /> : <Bot className="size-4" />}
      </span>
      <div className={cn('min-w-0 max-w-full flex-1 space-y-2', mine && 'flex flex-col items-end')}>
        {!mine && (
          <p className="flex flex-wrap items-center gap-1.5 text-[0.6875rem] text-[var(--color-ink-subtle)]">
            <span className="font-semibold text-[var(--color-ink)]">{t(`agents.name.${message.agent ?? 'orchestrator'}`)}</span>
            {message.data?.mode === 'rules' && <span className="rounded-full bg-[color-mix(in_srgb,var(--color-warning)_14%,transparent)] px-1.5 text-[var(--color-warning)]" data-testid="rules-mode">{t('agentChat.rulesMode')}</span>}
            {tools.length > 0 && (
              <span className="inline-flex items-center gap-1" title={tools.join(', ')}>
                <Wrench className="size-3" aria-hidden />
                {tools.map((tool) => t(`agentChat.tool.${tool}`, { defaultValue: tool })).join(' · ')}
              </span>
            )}
          </p>
        )}
        <div className={cn('inline-block max-w-[48rem] whitespace-pre-wrap rounded-[var(--radius-card)] px-3.5 py-2.5 text-[0.875rem] leading-relaxed', mine ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : 'bg-[var(--color-surface)] shadow-[var(--shadow-card)]')} dir="auto">
          {message.content}
        </div>
        {blocks.length > 0 && <div className="w-full"><MessageBlocks blocks={blocks} /></div>}
      </div>
    </div>
  )
}
