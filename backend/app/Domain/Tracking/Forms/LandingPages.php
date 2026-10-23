<?php

namespace App\Domain\Tracking\Forms;

use App\Models\LandingForm;
use App\Models\Tenant;
use App\Models\TrackingLink;
use App\Support\TenantContext;

/**
 * Which landing page a public token opens — or nothing at all.
 *
 * The token is the only thing the visitor holds, so everything is checked here and a failure of any
 * kind is the same answer: the link must be live and open a landing page, its form must be published, and
 * the form must belong to the very workspace the link does. A link pointing at somebody else's form (a
 * bug, a hand edit) finds nothing rather than showing — or filling — another company's page.
 */
class LandingPages
{
    public function __construct(private TenantContext $context) {}

    /** @return array{link:TrackingLink, form:LandingForm, tenant:Tenant}|null */
    public function resolve(string $token): ?array
    {
        $link = $this->context->withoutScope(fn () => TrackingLink::withoutGlobalScopes()->where('token', $token)->first());

        if ($link === null || ! $link->isLive() || $link->mode !== 'landing' || $link->landing_form_id === null) {
            return null;
        }

        $tenant = Tenant::withoutGlobalScopes()->find($link->tenant_id);
        $form = $this->context->withoutScope(fn () => LandingForm::withoutGlobalScopes()->find($link->landing_form_id));

        if ($tenant === null || ($tenant->is_active ?? true) === false || $form === null || $form->tenant_id !== $link->tenant_id || ! $form->isPublished()) {
            return null;
        }

        return ['link' => $link, 'form' => $form, 'tenant' => $tenant];
    }
}
