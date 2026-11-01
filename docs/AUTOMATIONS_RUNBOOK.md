# Automations runbook

**Model:** trigger → conditions (all / any) → actions, each with an optional delay; business hours and quiet hours per
workspace timezone; "unless replied" and stop-processing guards; a per-lead depth limit stops loops.

## Triggers
lead created / updated (stage changed) / assigned / qualified / replied / no reply / interest changed / score changed /
outcome recorded / appointment created / **reservation created** / won / lost / SLA breached / re-engaged / email opened or
clicked / file opened / sheet row / call completed.

## Actions
send WhatsApp (approved template outside 24h), send email, send brochure/file (signed link), create follow-up, assign,
change stage, add tag, notify user/manager, schedule call, send webhook — see Automation Centre for the live list.

## Stop conditions (built in)
A lead stops receiving automated messages when they reply (human conversation takes over), a person writes by hand
(human takeover pause), a visit or reservation is created, the lead is won or lost, or the channel is opted out /
suppressed / invalid / bounced.

## Default nurture journey
Installed on every workspace and editable: immediate welcome → 15 min → 1 day → 2 days, inside business hours, one
message per step, no step after a reply.

## Debugging a lead
Lead page → Automation trace shows each rule that matched, skipped (and why) or acted. The rule simulator replays a rule
against past leads without sending anything.
