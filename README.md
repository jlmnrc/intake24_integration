### REDCap External Module
********************************************************************************
# Intake24 Survey Integration
********************************************************************************

## Introduction
[Intake24](https://intake24.com/) is a nutrient survey tool that provides a graphical food/drink survey for the research community. REDCap is usually used to capture participant information and then redirect to the Intake24 survey for the nutrition data capture. This external module links the REDCap record with the user in Intake24: when a participant completes the triggering instrument in REDCap (e.g. consent, demographics), the module creates a signed sign-in link for Intake24, stores it on the record, and redirects the participant straight into the survey.

Optionally, the module can also **schedule a series of three dietary recalls** and listen for completion notifications from Intake24, recording each completion time and scheduling the next reminder automatically.

## Compatibility
- REDCap 10.0.0 or later.
- PHP 7.0 or later (tested up to PHP 8.3).
- Supports both **Intake24 v3** and **Intake24 v4**, selectable per project (different Intake24 instances may run different versions). The version is set with the *Intake24 version* setting; the module adjusts the link format, the token claim names, and notification handling accordingly.

## Limitations
- Does not support longitudinal events (the first event is used for scheduling).
- Does not support repeating instruments/events for the triggering or scheduling forms.

## How it works
1. The participant submits the configured **triggering instrument** in REDCap.
2. The module builds a signed token (HS256 JWT) identifying the participant, appends it to your survey URL as an Intake24 v4 *create-user* link, stores that link on the record, and redirects the participant to it.
3. If **scheduling** is enabled, the module also records the schedule date for recall #1.
4. Each time the participant submits a recall, Intake24 sends a *Survey session submitted* notification to the module's web service. The module records the completion time, schedules the next reminder, and — once all three recalls are complete — marks the scheduling instrument as complete.

## The sign-in link
The module builds a signed token (HS256 JWT) and appends it to your survey URL. The format and claim names depend on the configured version.

**Intake24 v4** (path-based):

```
https://<your-host>.intake24.app/survey/<survey-slug>/create-user/<TOKEN>?redirect=recall
```

**Intake24 v3** (query parameter):

```
https://<your-host>/surveys/<survey-slug>?createUser=<TOKEN>
```

The token claims also differ by version:

| Claim         | v3         | v4          | Notes |
|---------------|------------|-------------|-------|
| participant   | `user`     | `username`  | Taken from the configured *User ID* field. Note v4 uses the lowercase `username`. |
| `exp`         | —          | recommended | Token expiry (Unix timestamp). Default lifetime is 90 days; adjust `$token_lifetime_days` in the module if your reminder window is longer. v3 tokens do not expire. |
| redirect      | `redirect` | `redirectUrl` (optional) | Sent only when the *Redirect URL* setting is non-empty. In v4 this is echoed back to the calling system and is **not** used to redirect the participant; the post-survey hand-off is configured as a step in the Intake24 survey scheme instead. |

In v4 the optional `?redirect=recall` opens the recall directly, skipping the survey home page. A v3-style link is ignored by a v4 instance (the participant lands on the login page), which is why the version must match the instance.

## Configuration in Intake24 admin
In your survey's **External communication** settings at the Intake24 admin tool:
- Keep *Allow user generation* on.
- Set the **JWT secret for M2M communication** — this is the shared secret used both to sign the sign-in link and to sign the completion notifications. Use the same value for the module's *Secret Key* setting below.
- Set the **submission notification URL** to the module's web service (see *Web service endpoint*), and choose the **Survey session submitted** event (not *Survey session started*, which fires at the beginning of a recall and sends no completion data).

## External Module configurable variables
The following are configured per project before the module can be used.

### Intake24 version of this survey's instance
Choose **Intake24 v4** or **Intake24 v3** to match the instance your survey runs on. This controls the link format, the token claim names, and whether incoming notifications are signature-verified.

### Intake24.com Survey URL
The base URL of your Intake24 survey. Match the path to your version: v4 uses the singular `/survey/` path, e.g. `https://brazil.intake24.app/survey/Brpiloto`; v3 uses `/surveys/`, e.g. `https://intake24.com/surveys/LEHS_Survey`. The slug is taken from this setting and is never hard-coded.

### Intake24 Secret Key for signing the JSON payload
The shared secret used to sign the sign-in token. This must match the *JWT secret for M2M communication* configured for your survey at admin.intake24.com.

### Intake24 Redirect URL stored in the JSON payload
The URL placed in the optional `redirectUrl` claim (see the token table above).

### Intake24 User ID stored in the JSON payload
Which REDCap field supplies the participant identifier sent to Intake24. This should be unique in your project, e.g. `record_id`.

### The instrument/form name that will trigger the creation of the Intake24 URL
Which REDCap instrument, upon submission, generates the link and redirects the participant to Intake24.

### The field name that stores the generated Intake24 URL with the JSON payload
A REDCap text field used to store the generated link (useful for reminder emails). Consider hiding it from normal users.

## Scheduling (optional)
Enable **Enable Scheduling?** to reveal the scheduling settings. When enabled, the module manages three recalls:

- **Recall schedule date/time fields** (`schedule_time_1/2/3`): when each recall reminder is due. Recall #1 is set when the triggering instrument is submitted; #2 and #3 are set automatically after the previous recall is completed.
- **Recall completed date/time fields** (`completed_time_1/2/3`): populated from the Intake24 completion notification.
- **Scheduling instrument**: the form marked complete once all three recalls are done.
- **API Token**: a REDCap API token used by the web service to write back to the record (the notification arrives without a logged-in user, so the module authenticates the save with this token).

Reminder dates are calculated as three days after the completion time. As a special case, recalls completed on a Friday are scheduled for the following Sunday at 10:00. (Adjust this rule in `calculateReminderDate()` if your study needs different behaviour.)

### Securing the completion notifications (v4)
Intake24 v4 signs each notification with the shared secret and sends it as a Bearer token in the `Authorization` header. The module can verify this so that only genuine Intake24 calls are processed. (Intake24 v3 does not sign notifications, so verification is automatically skipped for v3 projects — leave the enforcement option off for those.)

- **Secret for verifying incoming Intake24 notifications**: the M2M secret. Leave blank to reuse the *Secret Key* above (the usual case).
- **Reject incoming notifications that are not validly signed?**: leave **OFF** first — the module logs whether a valid signature arrived without blocking, so you can confirm signatures are coming through. Then turn it **ON** to reject unsigned or invalid requests (HTTP 401).

> On nginx/php-fpm the `Authorization` header is not always forwarded to PHP. If verification logs show the signature is missing even though Intake24 is signing, add `fastcgi_param HTTP_AUTHORIZATION $http_authorization;` to your server configuration.

## Web service endpoint
The completion notifications are received at the module's no-auth page:

```
https://<your-redcap>/api/?type=module&prefix=intake24_integration&page=intake24_update&projectid=<PID>
```

Set this as the *submission notification URL* in Intake24. The module reads the notification as a single JSON document from the request body.

## Switching between v3 and v4
Set the *Intake24 version* setting to match your instance. The differences the module handles for you:
- **Link format**: v3 uses `...?createUser=<TOKEN>`; v4 reads the token from the path, `.../create-user/<TOKEN>`. A v3-style link silently lands on the login page in a v4 instance, and vice versa.
- **Token field names**: `user`/`redirect` (v3) versus `username`/`redirectUrl` plus the recommended `exp` (v4).
- **Survey URL path**: `/surveys/` (v3) versus `/survey/` (v4) — set the *Survey URL* accordingly.
- **Notification signing**: only v4 signs notifications (verified by the module); v3 does not.

When migrating an existing survey from v3 to v4, also update the notification event in Intake24 admin to *Survey session submitted* (v4 split the single v3 notification into separate started/submitted/cancelled events).

## Author
John Liman, Monash University.
