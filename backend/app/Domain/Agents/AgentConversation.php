<?php

namespace App\Domain\Agents;

use App\Domain\Actions\ProposalPresenter;
use App\Domain\Agents\Brain\AgentBrain;
use App\Domain\Agents\Brain\BrainFactory;
use App\Models\AgentMessage;
use App\Models\AgentThread;
use App\Models\User;
use Throwable;

/**
 * A conversation with the agents.
 *
 * With a model: the chosen agent (the orchestrator unless the person picked
 * one) thinks with its tools in a bounded loop, each tool call recorded. Without
 * one: the same tools, chosen by the person's words, answer directly — and the
 * reply says that it came from the rules, not from a model.
 *
 * Every message, tool call, model name and token count is kept on the thread:
 * "why did the agent propose a pause" is always one click from the proposal.
 */
final class AgentConversation
{
    public const AGENTS = ['orchestrator', 'campaign', 'analyst', 'creative', 'leads', 'reports'];

    /** What an agent says when it answers from its rules rather than a model. */
    private const RULES_REPLY = [
        'analyst' => 'هذه الأرقام كما هي في «مهتم» — الإجابة من القواعد لأن الذكاء الاصطناعي غير مفعّل.',
        'campaign' => 'حللتُ الحملات بقواعد العميل، وما يحتاج تغييرًا ينتظر موافقتك أدناه — لم يتغير شيء بعد.',
        'creative' => 'هذه حالة الإعلانات: المرهق والضعيف والأفضل. كتابة نصوص جديدة تحتاج الذكاء الاصطناعي.',
        'leads' => 'هذه جودة العملاء لكل حملة، من الأفضل إلى الأضعف.',
        'reports' => 'كتبتُ تقرير هذا الأسبوع وحفظته.',
        'orchestrator' => 'هذه الأرقام كما هي في «مهتم».',
    ];

    public function __construct(private BrainFactory $brains, private AgentToolbox $tools) {}

    public function reply(AgentThread $thread, User $user, string $text, ?string $agent = null): AgentMessage
    {
        $agent = in_array($agent, self::AGENTS, true) ? $agent : ($thread->agent ?: 'orchestrator');
        $thread->say('user', null, mb_substr($text, 0, 4000), ['agent' => $agent]);

        $brain = $this->brains->make();

        if ($brain->status()['state'] !== 'ready') {
            return $this->fromRules($thread, $user, $text, $brain->status()['reason']);
        }

        try {
            return $this->fromModel($brain, $thread, $user, $agent);
        } catch (Throwable $e) {
            // A failing model is a stated state too: fall back, and say why.
            report($e);

            return $this->fromRules($thread, $user, $text, 'model_error', mb_substr($e->getMessage(), 0, 300));
        }
    }

    private function fromModel(AgentBrain $brain, AgentThread $thread, User $user, string $agent, int $depth = 0): AgentMessage
    {
        $messages = $this->history($thread);
        $blocks = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];
        $model = null;
        $text = '';

        for ($round = 0; $round < (int) config('services.anthropic.max_tool_rounds', 6); $round++) {
            $reply = $brain->think($agent, AgentPrompts::system($agent, $thread->project_id), $messages, $this->tools->definitionsFor($agent));
            $model = $reply->model;
            $usage['input_tokens'] += (int) ($reply->usage['input_tokens'] ?? 0);
            $usage['output_tokens'] += (int) ($reply->usage['output_tokens'] ?? 0);
            $text = $reply->text;

            if ($reply->toolCalls === []) {
                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => $reply->content];
            $results = [];

            foreach ($reply->toolCalls as $call) {
                $out = $call['name'] === 'ask_agent' && $depth === 0
                    ? $this->delegate($brain, $thread, $user, $call['input'])
                    : $this->tools->run($agent, $call['name'], $call['input'], $user);

                $thread->say('tool', $agent, $call['name'], ['tool' => $call['name'], 'input' => $call['input'], 'ok' => ! isset($out['result']['error'])]);
                array_push($blocks, ...$out['blocks']);

                // Everything a tool returns is data, and is marked as such.
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $call['id'], 'content' => '<data>'.json_encode($out['result'], JSON_UNESCAPED_UNICODE).'</data>'];
            }

            $messages[] = ['role' => 'user', 'content' => $results];
        }

        return $thread->say('agent', $agent, $text !== '' ? $text : '—', [
            'blocks' => $blocks, 'mode' => 'ai', 'model' => $model, 'usage' => $usage, 'prompt_version' => AgentPrompts::VERSION,
        ]);
    }

    /** The orchestrator asks a specialist: one nested turn, its blocks surfaced. */
    private function delegate(AgentBrain $brain, AgentThread $thread, User $user, array $input): array
    {
        $agent = in_array($input['agent'] ?? null, ['campaign', 'analyst', 'creative', 'leads', 'reports'], true) ? $input['agent'] : 'analyst';
        $sub = AgentThread::create(['agent' => $agent, 'project_id' => $thread->project_id, 'started_by' => $user->id, 'title' => mb_substr((string) ($input['question'] ?? ''), 0, 120)]);
        $sub->say('user', null, (string) ($input['question'] ?? ''), ['delegated_from' => $thread->uuid]);
        $answer = $this->fromModel($brain, $sub, $user, $agent, 1);

        return ['result' => ['agent' => $agent, 'answer' => $answer->content], 'blocks' => $answer->data['blocks'] ?? []];
    }

    /** The person's words choose the tools; the reply says it came from the rules. */
    private function fromRules(AgentThread $thread, User $user, string $text, ?string $reason, ?string $detail = null): AgentMessage
    {
        $t = mb_strtolower($text);
        $has = fn (array $words) => collect($words)->contains(fn ($w) => str_contains($t, $w));

        [$agent, $calls] = match (true) {
            $has(['تقرير', 'report']) => ['reports', [['build_weekly_report', []]]],
            $has(['إعلان', 'اعلان', 'إبداع', 'creative', 'fatigue', 'إرهاق']) => ['creative', [['review_creatives', []]]],
            $has(['جودة', 'quality']) => ['leads', [['lead_quality', []]]],
            $has(['اقترح', 'اقتراح', 'propose', 'suggest', 'وفر', 'وفّر']) => ['campaign', [['analyze_campaigns', []]]],
            $has(['حلل', 'تحليل', 'analy', 'مشكلة', 'anomal']) => ['analyst', [['analyze_campaigns', []]]],
            $has(['حملات', 'campaigns', 'الأفضل', 'best']) => ['analyst', [['list_campaigns', ['sort' => 'qualified']]]],
            default => ['analyst', [['get_performance', ['days' => 30]]]],
        };

        $blocks = [];
        $findings = [];

        foreach ($calls as [$tool, $input]) {
            $out = $this->tools->run($agent, $tool, $input, $user);
            $thread->say('tool', $agent, $tool, ['tool' => $tool, 'input' => $input, 'ok' => ! isset($out['result']['error'])]);
            array_push($blocks, ...$out['blocks']);

            if (in_array($tool, ['analyze_campaigns'], true)) {
                $findings = $out['result'];
            }
        }

        // Asked for proposals: the Campaign Agent turns the findings into them,
        // through the gateway like everything else.
        if ($agent === 'campaign' && $findings !== []) {
            $objects = app(Analyst::class)->analyze();
            foreach (app(CampaignAgent::class)->act($objects) as $proposal) {
                $blocks[] = ['type' => 'proposal', 'proposal' => ProposalPresenter::present($proposal)];
            }
        }

        return $thread->say('agent', $agent, self::RULES_REPLY[$agent], [
            'blocks' => $blocks, 'mode' => 'rules', 'blocked_reason' => $reason, 'detail' => $detail,
        ]);
    }

    /** The conversation so far, as the model reads it: text only, newest twenty. */
    private function history(AgentThread $thread): array
    {
        $rows = $thread->messages()->whereIn('role', ['user', 'agent'])->latest('id')->limit(20)->get()->reverse()->values();

        // The API wants turns that alternate and start with the person; a turn
        // that failed earlier can leave two of the same in a row, so they fold.
        $turns = [];
        foreach ($rows as $m) {
            $role = $m->role === 'user' ? 'user' : 'assistant';
            if ($turns !== [] && end($turns)['role'] === $role) {
                $turns[count($turns) - 1]['content'][] = ['type' => 'text', 'text' => $m->content];
            } else {
                $turns[] = ['role' => $role, 'content' => [['type' => 'text', 'text' => $m->content]]];
            }
        }

        while ($turns !== [] && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }

        return $turns;
    }
}
