<?php

namespace App\Domain\Onboarding;

use App\DataTransferObjects\IncomingLead;
use App\Enums\IntegrationProvider;
use App\Models\AutomationRun;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\Leads\LeadIngestionService;

/**
 * Sends one lead through the real pipeline and reports what happened to it.
 *
 * This is the answer to the only question a workspace has on its first day:
 * does any of this actually work for *us*? Reading nine settings screens and
 * concluding "probably" is what people do instead, and they find out it did not
 * when a real buyer goes uncalled.
 *
 * It uses the genuine ingestion path — the same normalisation, the same
 * duplicate matcher, the same scoring and assignment a Meta webhook gets. A
 * diagnostic that takes a shortcut proves the shortcut works.
 *
 * The lead is marked `is_test` and a global scope keeps it out of every number
 * that describes the business, so proving the setup works does not move the
 * conversion rate.
 */
class TestLeadDiagnostic
{
    public function __construct(private readonly LeadIngestionService $ingestion) {}

    /** Recognisable at a glance in a list, and obviously not a real person. */
    public const NAME = 'عميل تجريبي — فحص الإعداد';

    public function run(Tenant $tenant): array
    {
        $incoming = new IncomingLead(
            provider: IntegrationProvider::GenericWebhook,
            fullName: self::NAME,
            phone: $this->unusedPhone($tenant),
            email: 'setup-check@'.$tenant->slug.'.test',
            city: 'الرياض',
            platform: 'meta',
            source: 'paid_social',
            campaignName: 'فحص الإعداد',
            campaignId: 'setup-check',
            adName: 'إعلان تجريبي',
            formName: 'نموذج تجريبي',
            providerLeadId: 'setup-check-'.now()->timestamp,
            purchaseTimeline: 'within_month',
            budgetMin: 800_000,
            budgetMax: 1_200_000,
            clickId: 'setup-check-click-'.now()->timestamp,
            utmSource: 'setup-check',
            utmCampaign: 'setup-check',
        );

        $result = $this->ingestion->ingest($tenant, $incoming);

        if (! $result->lead) {
            return [
                'ok' => false,
                'lead' => null,
                'steps' => [$this->step('ingestion', false, $result->reason ?? 'لم يُقبل العميل التجريبي.')],
            ];
        }

        $lead = $result->lead;
        $wasDuplicate = $result->isDuplicate();
        $lead->forceFill(['is_test' => true])->saveQuietly();
        $lead->refresh();

        return [
            'ok' => true,
            'lead' => ['id' => $lead->uuid, 'name' => $lead->full_name],
            'steps' => $this->inspect($lead, $incoming, $wasDuplicate),
        ];
    }

    /**
     * What the pipeline did, stage by stage.
     *
     * Each step says what happened rather than whether a box is ticked:
     * "assigned to نورة الشمري by least-busy" tells a manager their rota works;
     * a green tick tells them nothing they can check.
     */
    private function inspect(Lead $lead, IncomingLead $incoming, bool $wasDuplicate = false): array
    {
        $runs = AutomationRun::where('lead_id', $lead->id)->get();

        return [
            $this->step('ingestion', true, 'وصل العميل وأُنشئ سجله.'),

            $this->step(
                'normalization',
                $lead->phone_normalized !== null,
                $lead->phone_normalized
                    ? "حُوّل الرقم {$incoming->phone} إلى {$lead->phone_normalized}."
                    : 'تعذّر تحويل الرقم إلى صيغة قابلة للاتصال.',
            ),

            /*
             * Reported, not assumed. If this says "duplicate" the diagnostic
             * has found a real problem with its own phone generator, and a
             * hard-coded "no duplicates" would have hidden it.
             */
            $this->step(
                'dedup',
                ! $wasDuplicate,
                $wasDuplicate
                    ? 'طابق العميل التجريبي سجلًا موجودًا — وهذا غير متوقع.'
                    : 'لا يوجد سجل مكرر لهذا الرقم.',
            ),

            $this->step(
                'attribution',
                filled($lead->platform) && filled($lead->campaign_name),
                filled($lead->platform)
                    ? "نُسب إلى {$lead->platform} · {$lead->campaign_name}"
                        .($lead->click_id ? ' مع معرّف النقرة.' : ' بلا معرّف نقرة.')
                    : 'لم تُسجَّل أي بيانات إسناد.',
            ),

            $this->step(
                'scoring',
                $lead->score > 0,
                $lead->score > 0
                    ? "حُسب التقييم: {$lead->score} من 100."
                    : 'لم يُحتسب أي تقييم — راجع قواعد التقييم.',
            ),

            $this->step(
                'assignment',
                $lead->assigned_to !== null,
                $lead->assignee
                    ? "أُسند إلى {$lead->assignee->name}."
                    : 'لم يُسند لأحد — لا يوجد مندوب نشط، أو الإسناد التلقائي متوقف.',
            ),

            /*
             * Automation runs on a queue, so an empty trace a second after
             * ingestion usually means "not yet", not "never". Saying so beats
             * reporting a failure the workspace cannot act on — and the trace
             * on the lead page fills in as soon as the worker catches up.
             */
            $this->step(
                'automation',
                true,
                $runs->isEmpty()
                    ? 'لم تُسجَّل قواعد بعد — تُنفَّذ القواعد في الخلفية، وستظهر في أثر العميل خلال لحظات.'
                    : "نُظر في {$runs->count()} قاعدة، طُبِّق منها ".$runs->where('outcome', 'applied')->count().'.',
            ),

            $this->step(
                'notification',
                $lead->assigned_to !== null,
                $lead->assigned_to !== null
                    ? 'سيصل التنبيه إلى المسؤول عن العميل.'
                    : 'لا تنبيه: لا يوجد مسؤول لإشعاره.',
            ),
        ];
    }

    /**
     * A phone number that cannot collide with a real customer and cannot
     * collide with a previous run: the dedup step is one of the things being
     * tested, and a second test lead matching the first would report a
     * duplicate rather than a fresh path.
     */
    private function unusedPhone(Tenant $tenant): string
    {
        /*
         * Local format, the way a web form actually submits it — so the
         * normalisation step has something to do and the report can show it
         * doing it. Handing the pipeline a number already in E.164 produced
         * "converted +966598195624 to +966598195624", which proves nothing and
         * reads like a bug.
         *
         * It must also not collide with a real customer or with a previous run.
         * A timestamp did both only until somebody clicked twice in the same
         * second, which returned a 500 — the unique index on (tenant, phone)
         * doing its job. So the number is checked against what is there.
         */
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $local = '059'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
            $normalized = '+9665'.mb_substr($local, 2);

            $taken = Lead::withTestLeads()
                ->where('phone_normalized', $normalized)
                ->exists();

            if (! $taken) {
                return $local;
            }
        }

        // Twenty collisions in a seven-digit space means something else is
        // wrong; failing loudly beats returning a number that will 500.
        throw new \RuntimeException('Could not find an unused test phone number.');
    }

    private function step(string $key, bool $ok, string $detail): array
    {
        return ['key' => $key, 'ok' => $ok, 'detail' => $detail];
    }
}
