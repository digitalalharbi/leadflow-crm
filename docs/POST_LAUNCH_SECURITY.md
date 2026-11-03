# Post-launch security hardening

## Move sign-in sessions to HttpOnly cookies

**Status: not done. Session security is not at its highest level yet.**

Sign-in is a Sanctum bearer token in `localStorage`. Script running on the page could read it, so a cross-site scripting bug would expose live sessions. No Secure, HttpOnly or SameSite cookie carries a sign-in today.

Already in place, and limiting the damage: 30-day expiry (12 hours without "remember me"), a device list with sign-out, sessions ended on a password change, CSRF not applying to bearer requests, and React escaping by default. The CSP covers only narrow directives, not script sources.

**Why it was not changed before launch.** Assessed Nov 2026 as high risk to do together with a first release:

- Sessions are tokens throughout: support sessions swap tokens and restore the platform's, workspace switching swaps tokens, and platform sessions depend on token abilities.
- The device list and per-session expiry read token rows, and would need rebuilding on server sessions.
- Realtime authorization, the installable app's offline handling and every browser test assume a bearer header.
- A mistake here locks everybody out or weakens the isolation the audits just verified.

**What it takes.** Same-origin Sanctum stateful mode, CSRF on every write, session regeneration at login and at each token swap above, the device list on server sessions, and the full browser suite. A strict script CSP is worth doing first: it is smaller and lowers the same risk.
