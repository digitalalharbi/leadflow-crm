<?php

namespace App\Domain\Tracking\Forms;

use App\Models\LandingForm;
use App\Models\LandingFormVersion;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The line between a draft and what the world sees.
 *
 * A form row is the draft a manager is editing; publishing freezes it as a version and points the form at it. The public page serves the
 * version, so changing the draft never reaches a campaign already running. Publishing a draft identical to what is live changes nothing.
 */
class FormPublisher
{
    public function __construct(private FormSchema $schema) {}

    /**
     * What stops this draft from being published, keyed as the API reports it. Empty when it can go live.
     *
     * @return array<string,string>
     */
    public function problems(LandingForm $form): array
    {
        $fields = $form->fields ?? [];
        $errors = [];

        foreach ($this->schema->definitionErrors($fields, $form->layout) as $key => $code) {
            $errors["fields.{$key}"] = $code;
        }

        $normal = $this->schema->normalize($fields);
        $keys = array_column($normal, 'key');
        $snapshot = $errors === [] ? FormDefinition::snapshot($form) : null;

        foreach (FormDesign::errors((array) $form->design, (array) $form->settings, $keys) as $path => $code) {
            $errors[$path] = $code;
        }

        if (! FormDesign::imageOk($form->logo_url) || ! FormDesign::imageOk($form->image_url)) {
            $errors['logo_url'] = 'invalid_image';
        }

        if ($form->page !== null) {
            $errors += LandingPageConfig::errors(LandingPageConfig::normalize($form->page));

            if ($form->project_id === null) {
                $errors['project_id'] = 'project_required';
            }
        }

        return $snapshot === null && $errors === [] ? [] : $errors;
    }

    /** The version that is live, made now if this form was published before versions existed. */
    public function live(LandingForm $form): ?LandingFormVersion
    {
        if (! $form->isPublished()) {
            return null;
        }

        if ($form->published_version_id !== null) {
            return $form->publishedVersion;
        }

        return $this->publish($form, null);
    }

    public function publish(LandingForm $form, ?User $by): LandingFormVersion
    {
        $snapshot = FormDefinition::snapshot($form);
        $checksum = FormDefinition::checksum($snapshot);

        $current = $form->published_version_id ? $form->publishedVersion : null;

        if ($current !== null && $current->checksum === $checksum && $form->status === 'published') {
            return $current;
        }

        return DB::transaction(function () use ($form, $by, $snapshot, $checksum) {
            $next = (int) LandingFormVersion::withoutGlobalScopes()->where('landing_form_id', $form->id)->max('version') + 1;

            $version = LandingFormVersion::create([
                'landing_form_id' => $form->id, 'version' => $next, 'variant' => $snapshot['settings']['variant']['key'] ?? 'A',
                'snapshot' => $snapshot, 'checksum' => $checksum, 'published_by' => $by?->id, 'published_at' => now(),
            ]);

            $form->forceFill(['status' => 'published', 'published_version_id' => $version->id, 'published_at' => now()])->save();
            $form->setRelation('publishedVersion', $version);

            if ($form->page !== null) {
                $this->pageAddress($form);
            }

            return $version;
        });
    }

    /** Is the draft different from what is live? */
    /**
     * A published page has an address and a link under it: the visit, the submission, the beacons and the analytics then run
     * through what every tracking link already uses, instead of a second path beside it.
     */
    public function pageAddress(LandingForm $form): void
    {
        if ($form->slug === null) {
            app(LandingSlugs::class)->assign($form);
        }

        $link = $form->default_link_id ? TrackingLink::find($form->default_link_id) : null;

        if ($link === null) {
            $link = TrackingLink::create([
                'name' => mb_substr('صفحة '.$form->name, 0, 160), 'channel' => 'landing_page', 'mode' => 'landing', 'kind' => 'link',
                'project_id' => $form->project_id, 'landing_form_id' => $form->id, 'is_active' => true, 'destination_url' => null,
            ]);
            $form->forceFill(['default_link_id' => $link->id])->save();
        } elseif ($link->project_id !== $form->project_id) {
            $link->forceFill(['project_id' => $form->project_id])->save();
        }
    }

    public function hasUnpublishedChanges(LandingForm $form): bool
    {
        $live = $form->published_version_id ? $form->publishedVersion : null;

        return $live !== null && $live->checksum !== FormDefinition::checksum(FormDefinition::snapshot($form));
    }
}
