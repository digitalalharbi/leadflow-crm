<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Who may listen to what.
 *
 * Every channel here is private and every callback answers one question: does
 * this person belong to the workspace this channel names? Nothing on a channel
 * is trusted to be unguessable — the uuid in the name is a label, not a secret,
 * and a tenant's whole live feed would otherwise hang on nobody ever seeing one
 * in a log, a screenshot or another workspace's shared link.
 *
 * What travels on them is deliberately thin: that something changed and which
 * lead it was, never the lead itself. The browser then refetches through the
 * ordinary endpoints, where the policies run — so a payload cannot show a rep a
 * customer the API would not have given them.
 *
 * Every channel names `sanctum` explicitly. Without it the broadcaster asks the
 * default guard, which is `web`, and this API issues no session — so it finds
 * nobody and refuses every subscription, including the ones it should allow.
 * The failure is quiet in the worst way: each rule still *looks* enforced,
 * because everything is denied.
 */
$guards = ['guards' => ['sanctum']];

/**
 * The workspace's own feed: arrivals, assignments, replies.
 *
 * Limited to the people who can already see every lead. A rep watching this
 * would be told that a lead exists which their own queue will never show them,
 * which is both useless to them and more than they are allowed to know.
 */
Broadcast::channel('tenant.{tenantUuid}.leads', function (User $user, string $tenantUuid) {
    return $user->tenant?->uuid === $tenantUuid
        && ($user->isAdmin() || $user->isManager());
}, $guards);

/**
 * The workspace's development feed: a pull request, a build, a deployment.
 *
 * Anyone who can open a project can watch it. Unlike the lead feed, there is
 * nothing here about a particular customer — a merged pull request is not
 * somebody's phone number — so the narrower rule would only mean a rep looking
 * at a project whose screen never updates.
 */
Broadcast::channel('tenant.{tenantUuid}.development', function (User $user, string $tenantUuid) {
    return $user->tenant?->uuid === $tenantUuid && ! $user->isSuperAdmin();
}, $guards);

/**
 * One person's own feed: a lead landed on your queue, your customer replied.
 *
 * Keyed by uuid rather than the primary key. The database id is an internal
 * number that appears nowhere else in the API, and putting it in a channel name
 * leaks both the identifier and the size of the table it came from.
 */
Broadcast::channel('user.{userUuid}', function (User $user, string $userUuid) {
    return $user->uuid === $userUuid;
}, $guards);
