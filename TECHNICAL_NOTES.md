# Technical notes — Intake24 Survey Integration

*Companion to [README.md](README.md), which is the guide for research coordinators and project users. This file is for REDCap administrators and developers: what the module actually does under the hood, and what was verified about Intake24 itself.*

> Intake24 is actively developed, so treat these as findings with a date on them rather than a specification. If your instance runs a different version, or behaves differently from what's written here, **your instance is the authority — please report it** (an issue at [github.com/jlmnrc/intake24_integration](https://github.com/jlmnrc/intake24_integration/issues) is ideal, since it keeps the correction next to the code) **so this document can be fixed.** The parts describing *this module's* behaviour are read from the code in this repository and stay accurate as long as the version numbers match.
>
> Statements about **Intake24 v3** are the exception: they come from the module's existing implementation and its comments, not from v3 source, and are the least verified material in this document.

## Hooks and entry points

- `redcap_save_record` — builds the token and link, writes it plus recall #1's schedule date, then redirects. Guarded by `$instrument == $triggering_instrument_name` and by the generated-URL field being empty for the record-event.
- `intake24_update.php` — the notification endpoint, declared in `config.json` under both `no-auth-pages` (no REDCap login) and `no-csrf-pages` (CSRF exemption for external POSTs under framework v16). It only delegates to `updateFromIntake24()`; REDCap requires the file to exist for the page to route.

`intake24_version` is `required: true`, so it is always set on a project saved through the configuration screen. Both code paths nonetheless fall back to **v3** when it is empty, on the basis that an unset version means a project configured before the setting existed — which could only have been a v3 survey.

The User ID is read from the **saved record** via `Records::getData()`, never from `$_POST`. That means calculated and piped fields work as the identifier, and it keeps `$_POST` out of the taint path for the generated link.

## Event handling

The two halves of the module differ, which is why longitudinal support depends on whether scheduling is on.

`redcap_save_record` is **event-scoped throughout**: the existing-link check and the User ID read both index `$recordData[$record][$event_id][...]`, and the save uses `REDCap::getEventNames(true, true, $event_id)` for `redcap_event_name`. So the link is written back to the submitting event, arm included. Two consequences:

- `Records::getData()` in `array` format partitions by event, so a field whose form is not designated to (or not filled in) the submitting event reads as blank. There is no cross-event fallback: a blank User ID yields a token with an empty `username`, which Intake24 rejects. The record ID field is the dependable choice, since REDCap maintains a record-ID row for every record-event.
- The guard is per record-**event**, not per record, so a triggering instrument designated to several events produces one link per event, each with the same username.

`processApiRequest()` is **pinned to `$Proj->firstEventId`** and writes all completion times and schedule dates there. This is the sole source of the "first event only" limitation, and it is unreachable when scheduling is off — the handler rejects the request before any save.

Repeating events are handled by neither path: REDCap places that data under `$recordData[$record]['repeat_instances'][$event_id][...]`, which the flat `[$record][$event_id]` lookups never reach, so the existing-link guard always reads empty and every submission regenerates and redirects.

`redirect()` at the end of `redcap_save_record` is called irrespective of whether `saveMyData()` succeeded; save failures are logged but do not stop the participant reaching Intake24.

That redirect also terminates the participant's REDCap session for this submission — it fires from inside `redcap_save_record`, before REDCap renders whatever would normally follow, so the survey queue, *Auto-continue*, and any survey-completion text are all pre-empted. Nothing the module does brings the participant back: the completion notification is a **server-to-server POST** to `intake24_update.php` and carries no browser with it. Hence the design rule in the user-facing section — the triggering instrument must be the last thing the participant is asked to do.

**The v4 `redirectUrl` claim does not provide a return path.** Traced through both ends of Intake24 v4: the API parses it, places it in `CreateUserResponse` (`survey.service.ts`), and never uses it again; the survey app's `createUserGuard` destructures only `{ authToken }` from that response (`apps/survey/src/router/guards.ts`) and discards the rest. The `?redirect=` query parameter in the generated link is unrelated — it accepts only `home`, `recall` or `feedback`, i.e. internal views, not URLs. So in v4 the *Redirect URL* setting is inert, and its only practical effect is the failure mode: an invalid value is rejected by Zod and takes the create-user call down, which is what the `FILTER_VALIDATE_URL` guard exists to prevent. The setting is retained because v3 does consume its `redirect` claim.

**Where a v4 return trip actually comes from — the survey scheme, not this module.** `getFollowUpUrl()` collects every `redirect-prompt` in `surveyScheme.prompts.submission`; each carries a `url` whose `{identifier}` placeholder is substituted with `userId`, `username`, `urlAuthToken` or a named custom field, plus a `timer` and a `target`. The result is returned as `followUpUrl` on the submission response, and `RedirectPrompt.vue` calls `window.open(followUpUrl, target)` — automatically once the timer elapses, or only on a click when `timer` is `0`.

A study that wants participants back in REDCap after the recall configures that prompt in the **Intake24 admin tool**, pointing it at a REDCap survey link (`{identifier}` is the natural way to pass the record ID through). Two caveats that keep the design rule intact: it is a fresh page load — with `target: '_blank'`, a new tab — so it cannot resume a survey queue mid-flight; and it depends on the participant not closing the tab at the completion screen. Treat it as a courtesy hand-off, not as a step you can rely on for data collection.

## Link formats

**v4** (token in the path, matching the survey app's `/:surveyId/create-user/:token` history route — not a hash route):

```
https://<host>.intake24.app/survey/<survey-slug>/create-user/<TOKEN>?redirect=recall
```

**v3** (token as a query parameter):

```
https://<host>/surveys/<survey-slug>?createUser=<TOKEN>
```

The slug always comes from the *Survey URL* setting and is never hard-coded. `?redirect=recall` in v4 opens the recall directly, skipping the survey home page.

## Token claims by version

Both versions use an HS256 JWT signed with the shared secret.

| Claim | v3 | v4 | Notes |
|---|---|---|---|
| participant | `user` | `username` | From the *User ID* field. The lowercase `username` matters — a wrong or missing name fails validation with HTTP 400. An empty value is rejected the same way (`expected string to have >=1 characters`), which is why the module reads the identifier from the saved record. |
| expiry | — | `exp` | Optional on Intake24's side but enforced when present: a lapsed token is refused with HTTP 403 `jwt expired`, and a token carrying no `exp` at all is accepted. From `token_lifetime_days`, defaulting to **90 days** when the setting has never been saved. `getTokenLifetimeSeconds()` returns `null` — the signal to omit the claim — *only* for an explicit `0`; null, `''`, negative and non-numeric values all fall back to the default, so a missing or corrupt setting can never silently produce a link that lives forever. It deliberately never returns `0`, which would emit `exp: <now>` and produce a dead-on-arrival link, and caps anything above 3650 days. The default lives in code because the framework documents config.json's `default` attribute as unreliable. The claim is computed at link-creation time, since the link is built once per record and never reissued. |
| redirect | `redirect` | `redirectUrl` | v3 always sends it. v4 includes it **only** if it passes `FILTER_VALIDATE_URL`; that guard is load-bearing, because a malformed value fails validation (`Invalid URL at "redirectUrl"`) and takes the whole create-user call down with it. In v4 the value is echoed back in the create-user response and does not move the participant; the post-survey hand-off is a redirect step in the Intake24 survey scheme. |

## Verified create-user behaviour (Intake24 v4)

Observed on **26 July 2026** against **Intake24 v4 `2026.2.0`**, by posting module-signed tokens to `POST /api/surveys/<slug>/create-user` on a live instance — these are recorded responses, not inferences. Re-check them if you upgrade Intake24:

| Token | Result |
|---|---|
| `username` + `exp` 90 days ahead | **200**, respondent created — the module's default path |
| `username`, no `exp` at all | **200**, respondent created — `exp` is genuinely optional |
| `username` + expired `exp` | **403** `jwt expired` — so a lifetime, once set, is enforced |
| `username` + valid `redirectUrl` | **200**, and `redirectUrl` echoed back in the response body |
| `username` set to `""` | **400** validation error, `expected string to have >=1 characters` |
| Signed with the wrong secret | **403** `invalid signature` |
| v3-shaped claims (`user`/`redirect`) | **400** validation error, `username` undefined |
| Malformed `redirectUrl` | **400** validation error, `Invalid URL` |

On the Intake24 side, `createRespondentWithJWT()` verifies with `algorithms: ['HS256', 'HS512']` (the module signs HS256) and then parses the payload with a non-strict Zod object, which is why extra JWT claims such as `exp` and `iat` pass through harmlessly rather than tripping validation. It also requires the survey's *Allow user generation* flag and a non-empty gen-user key, otherwise the call is refused before the token is even read.

That schema accepts three claims the module does not currently send — `name`, `password` and `customFields` — which is the hook any future work on passing participant details or study metadata into Intake24 would use.

## The notification endpoint

```
<redcap>/api/?type=module&prefix=intake24_integration&page=intake24_update&projectid=<PID>
```

The raw body is read from `php://input` **before** `RestUtility::processRequest()`, which would otherwise consume the stream. The configured API token is injected as `$_POST['token']` so the request authenticates as a normal API call — Intake24 has no way to hold a secret in the URL, and this assumes only authorised users can read the module's configuration. The project id used for saving comes from the token, not the query string, and the target event is always `$Proj->firstEventId`.

Payload shape differs by version:

- **v3** (flat): `{ "userName": "<id>", "endTime": "...", ... }`
- **v4** (nested): `{ "type": "survey.session.submitted", "data": { "endTime": "...", "user": { "aliases": [ { "username": "<id>" } ] } } }`

A body missing either the username or `endTime` — a *session started* event, say — is logged and answered with HTTP 400. `endTime` arrives as UTC ISO-8601 and is converted to the REDCap server's timezone (DST-aware) before being stored as `Y-m-d H:i`; an unparseable timestamp falls back to a literal string slice.

Slot assignment is by first empty *completed* field: recall 3 populated → logged and ignored; recall 2 populated → write recall 3 and set `<scheduling_form>_complete = 2`; recall 1 populated → write recall 2 and recall 3's schedule; otherwise → write recall 1 and recall 2's schedule.

## Signature verification

`verifyIntake24Signature()` returns true only when the `Authorization: Bearer <JWT>` header is present, the token has three parts, the header declares **HS256** (rejecting the `alg: none` bypass and algorithm-confusion attacks), the HMAC matches under `hash_equals()` (constant time), and any `exp` claim is still valid within 60 seconds of clock skew. The header is read from `HTTP_AUTHORIZATION`, then `REDIRECT_HTTP_AUTHORIZATION`, then `apache_request_headers()`, since servers expose it inconsistently.

Failures are logged whether or not enforcement is on. With `require_signed_notifications` on, a failure returns HTTP 401; all other errors return 400.

The whole block is inside `if ($intake24_version === 'v4')`, so on a v3 project neither `notification_secret` nor `require_signed_notifications` is ever read — the endpoint accepts any well-formed body for the configured project id. Both settings therefore branch on `intake24_version` = `v4` as well as `schedule-enabled`, so they are hidden rather than sitting on the configuration screen looking load-bearing.

## Reminder date calculation

`calculateReminderDate($completedTime, $days)` converts the incoming string with `strtotime()` first — passing a date string straight to `date('N', ...)` was an earlier bug that coerced to a small integer and made every record look like a Thursday. `$days` comes from `reminder_days`, clamped to 1–7 and defaulting to 3 for projects saved before that setting existed. A Friday completion (`date('N') === 5`) returns `+2 days` at `10:00:00`; everything else returns `+$days` preserving the time of day. Output format is `Y-m-d H:i:s`.

## Error handling notes

`getSaveErrors()` normalises whatever `REDCap::saveData()` returns — array, JSON string, or neither — before touching `['errors']` or calling `count()`. This avoids the PHP 8 fatal `TypeError` that the released module hit when the key was absent, and the variant that still fataled when the response was a string.

`redirect()` reimplements REDCap's helper because external modules can't call `exit()`; it uses `exitAfterHook()`, JSON-encodes the URL when headers are already sent, and strips raw CR/LF before writing a `Location` header.

## Tests

`tests/Intake24IntegrationTest.php` extends `ExternalModules\ModuleBaseTest` and reaches private methods by reflection. It covers reminder arithmetic (weekday, Friday, slash-format input, garbage input), token lifetime conversion (configured days, explicit zero → no expiry, unsaved/negative/garbage → 90-day default, overridable default, upper cap), base64url round-tripping, signature verification (valid, wrong secret, `alg: none`, expired, unexpired-with-`exp`, missing header, empty secret, malformed token), and `getSaveErrors()` across array, scalar, JSON-string and degenerate responses.

## Migrating a survey from v3 to v4

- Change the *Intake24 version* setting and update the *Survey URL* path from `/surveys/` to `/survey/`.
- In Intake24 admin, set the notification event to *Survey session submitted* — v4 split the single v3 notification into separate started, submitted and cancelled events.
- Confirm the *Redirect URL* is a valid absolute URL, or accept that v4 will drop the claim.
- Signature verification becomes available: run with enforcement off until the log confirms valid signatures, then turn it on.
