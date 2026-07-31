# Intake24 Survey Integration

*A REDCap External Module — by John Liman, SPHPM, Monash University*

## What this module does

[Intake24](https://intake24.com/) is an online tool that asks people what they ate and drank, using pictures instead of forms. Many studies use REDCap to collect consent and participant details, then send the participant across to Intake24 to record their diet.

Normally that hand-off is fiddly: someone has to create an Intake24 account for each participant and email them a link. **This module does it automatically.**

When a participant finishes a form you nominate in REDCap (for example your consent or demographics form), the module:

1. Creates their Intake24 sign-in link behind the scenes — no account setup, no password for the participant to remember.
2. Saves that link into a REDCap field, so you can reuse it in reminder emails.
3. Sends the participant straight into their diet recall.

> ⚠️ **The hand-off is one-way — put the diet recall last.** Once the module sends the participant to Intake24, they do **not** come back to REDCap when the recall is finished. As far as REDCap is concerned that survey submission is already over. So anything you scheduled to happen *after* the triggering form — the next survey in a survey queue, an "Auto-continue to next survey" step, your own thank-you page — is never reached, and the participant is left sitting on Intake24's completion screen.
>
> Design around it: collect **everything else you need from the participant first**, and make the form that triggers Intake24 the last one they see. If you have follow-up questions that must be asked after the recall, send them as a separate invitation later rather than chaining them onto the same sitting.
>
> Intake24 *can* be configured to forward participants on to an address of your choosing once they finish — see *Sending participants somewhere after the recall*. That's a fresh page, though, not a continuation of where they were in REDCap, and they may close the tab before following it. It's a courtesy, not something to rely on.

If you switch on **scheduling**, it does more. It tracks a series of **three diet recalls**: it records the date and time each one was finished, works out when the next one is due, and ticks off your tracking form once all three are done. You don't have to watch for completions or diarise reminders yourself.

> **The module does not send the reminders.** It writes the *due dates* into your REDCap fields. Use REDCap's own Alerts & Notifications (or Automated Survey Invitations) to send the actual emails, pointing them at the schedule date fields and the stored link.

## Before you start

You will need:

- A REDCap project where you can enable external modules.
- An Intake24 survey, plus access to the **Intake24 admin tool** for it (you'll copy a couple of values between the two systems).
- To know whether your Intake24 site runs **version 3 or version 4**. If you're not sure, ask whoever administers your Intake24 instance — this matters, and a mismatch is the most common cause of setup problems. Version 4 addresses look like `https://something.intake24.app/survey/...`; version 3 addresses use `/surveys/` (note the "s").
- If you want scheduling: a REDCap **API token** is required for this project, which your REDCap administrator can issue.

**Requirements:** REDCap 14.6.4 or later, PHP 7.0 or later (tested up to PHP 8.3). Works with both Intake24 v3 and v4, chosen per project.

> **A note on accuracy.** What this guide says about *Intake24's* behaviour was checked against **Intake24 v4, version `2026.2.0`**. Intake24 is developed continuously, so a newer version may not behave identically. If something here doesn't match what your Intake24 site actually does, **your site is right and this document is out of date** — please say so, via any of the routes under *Getting help, or telling us something is wrong* below. The v3 notes are the least certain of all: they reflect how the module was built rather than testing against a v3 site.

**Limits to check before you design your project:**

- **The diet recall has to be the last thing the participant does.** The hand-off to Intake24 is one-way, so nothing you place after the triggering form will ever be seen — see the warning above. Order your survey queue with the triggering form at the end.
- **Longitudinal projects:** fine for link generation, *not* for scheduling. Without scheduling the module works in whichever event the participant submits the form. With scheduling on, all completion times and due dates are written to the **first event only**, so use a classic (non-longitudinal) project. See *Using this in a longitudinal project* below.
- **Repeating instruments and repeating events** are not supported for the triggering form or the scheduling form. On a repeating event the module can't see the existing link, so it builds a new one and redirects again on every single submission.
- If you use scheduling, the participant identifier you send to Intake24 **must be the record ID field**. See the warning under *Intake24 User ID* below.

## Setting it up

There are two halves: settings in Intake24, and settings in REDCap. Do the Intake24 side first, because you'll need to copy a value across.

### Part 1 — in the Intake24 admin tool

Open your survey and go to **External communication**:

1. Leave **Allow user generation** switched on. This is what lets the module create participant accounts.
2. Find **JWT secret for M2M communication**. This is a shared password between Intake24 and REDCap. Copy it — you'll paste it into REDCap in a moment. Keep it private; treat it like any other credential.
3. Only if you're using scheduling: set the **submission notification URL** to your REDCap address in this pattern, replacing the two `<...>` parts:

   ```
   https://<your-redcap-address>/api/?type=module&prefix=intake24_integration&page=intake24_update&projectid=<your-project-id>
   ```

   Your project ID is the `pid` number in your REDCap project's web address.
4. For the notification event, choose **Survey session submitted**. Don't choose *Survey session started* — that fires when a participant begins a recall and carries no completion time, so the module logs it and turns it away.

### Part 2 — in REDCap

Enable the module in your project, open its configuration, and fill in the settings below. All of them are required by the configuration screen.

#### The basics

| Setting | What to put in it |
|---|---|
| **Intake24 version of this survey's instance** | Pick v4 or v3 to match your Intake24 site. |
| **Intake24.com Survey URL** | Your survey's web address, with no trailing slash. For v4: `https://brazil.intake24.app/survey/Brpiloto`. For v3: `https://intake24.com/surveys/LEHS_Survey`. Match the `/survey/` vs `/surveys/` part to your version. The survey name is read from here, so this is the only place it's set. |
| **Intake24 Secret Key** | The *JWT secret for M2M communication* you copied from Intake24. |
| **Intake24 Redirect URL** | The configuration screen requires a value, but **in v4 it has no effect** — Intake24 v4 accepts the address and then ignores it, so it does *not* control where the participant goes after the recall. Put a complete address starting `https://` in it regardless: the module only forwards the value when it's a valid absolute URL, and a valid one keeps your settings and what Intake24 receives in step. In **v3** it is sent as the post-survey destination. To send v4 participants somewhere afterwards, use the **redirect prompt** in your Intake24 survey scheme instead — see *Sending participants somewhere after the recall* below. |
| **Intake24 User ID** | The REDCap field identifying the participant to Intake24. **If you use scheduling, this must be your record ID field** (usually `record_id`) — see the warning below. |
| **How long the sign-in link stays valid** | v4 only. Defaults to **90 days** if you leave it alone. Read *Choosing how long links stay valid* below before accepting that — the clock starts earlier than most people expect, and 90 days is not enough for every study design. |
| **Triggering instrument** | The form that, once submitted, sends the participant to Intake24. |
| **Field that stores the generated URL** | A plain text field where the module saves the sign-in link. **Hide it from users who don't need it:** anyone holding the link can open that participant's recall without a password. |

> ⚠️ **Why the User ID field must be the record ID when scheduling is on.** The module puts this field's value into the link as the Intake24 username, and Intake24 sends that same username back when a recall is finished. The module then looks for a **record with that ID** in order to save the completion time. If the User ID field is something else — a participant code, an email — the link works fine but every completion is rejected with *"Cannot find record"*, and no completion times or reminder dates are ever written. Without scheduling, any unique field is fine.

#### Choosing how long links stay valid

A participant's sign-in link stops working after a set period, controlled by the **How long should the sign-in link stay valid?** setting (Intake24 v4 only — v3 links can't expire). **Leave it alone and you get 90 days.**

**The clock starts when the link is created**, which is the moment the participant submits your triggering form. It does *not* restart when you send a reminder. Each participant gets one link, reused for all three recalls and every reminder email about them, so the period has to cover the whole stretch from enrolment to their final recall.

That catches people out in two designs, and in both of them the 90-day default is a poor fit:

- **Consent well before the recall phase.** If participants consent at a baseline visit and the dietary component doesn't start until two or three months later, 90 days can lapse before they've done anything.
- **Participants who go quiet.** Someone who enrols, does recall 1, then disappears for a few months comes back to a dead link.

**How to choose:**

| Your situation | Suggested setting |
|---|---|
| Recalls all happen within a few weeks of enrolment | **90 days** (the default) is ample |
| Enrolment is months ahead of the recall phase, or you expect long gaps | 180 or 365 days |
| A data-governance rule requires links to lapse quickly | The shortest period that still covers your slowest realistic participant, then add a wide margin |
| Long-running study, and you'd rather manage access by hiding the link field | **Never expires** — but only as a deliberate choice, since the link then stays usable forever |

Work out your worst case — enrolment to last recall, including the stragglers — and choose something comfortably longer. A limit that's too short fails silently and late: the participant simply can't get in, there's no warning in REDCap, and you'll only hear about it if they tell you.

**If a link does expire,** there's currently no one-click reissue. You clear the URL field on the record and re-submit the triggering form to build a fresh one, which also resets that participant's recall #1 due date, so you may need to correct it by hand. Ask your REDCap administrator to walk through it with you the first time.

**Changing the setting later only affects new participants.** The expiry is sealed inside each link when it's made, so raising the limit mid-study does nothing for anyone already enrolled.

#### Scheduling three recalls (optional)

Tick **Enable Scheduling?** and more settings appear.

You'll need six date/time fields — three for *when a recall is due*, three for *when it was finished*:

| Setting | What it's for |
|---|---|
| **Recall #1, #2, #3 schedule date/time** | When each recall is due. #1 is filled in when the triggering form is submitted; #2 and #3 are filled in automatically once the previous recall is finished. |
| **Recall #1, #2, #3 completed date/time** | Filled in automatically when Intake24 reports a recall was submitted. |
| **Number of days until the next reminder** | The gap between a finished recall and the next due date. 1 to 7 days; default 3. |
| **Instrument to mark complete** | The tracking form marked *Complete* once all three recalls are finished. |
| **API Token** | This project's REDCap API token. The module needs it because the message from Intake24 arrives with nobody logged in, so it has no other way to save to the record. It must belong to this project and have write access. |

Give those six fields a date/time validation that includes seconds (`Y-M-D H:M:S`) if you can. The module writes due dates with seconds and completion times without, so a seconds-capable field accepts both without complaint.

**Two behaviours that surprise people:**

- **Recall #1 isn't due immediately.** Its date is set to the reminder gap *after* the triggering form is submitted — with the default, three days later, not the same day.
- **A recall finished on a Friday** pushes the next due date to **Sunday at 10:00am**, instead of the usual gap. This keeps recalls off the start of the working week, and it applies whatever day count you choose.

#### Making completion messages secure (v4 only)

Version 4 signs each completion message, letting the module confirm it genuinely came from Intake24 rather than from anyone who happened to learn the address. Two settings control this:

- **Secret for verifying incoming notifications** — leave **blank** in most cases and the module reuses the Secret Key you already entered. Fill it in only if your survey uses a different secret for external communication.
- **Reject notifications that are not validly signed?** — **leave OFF to begin with.** The module writes the outcome to the REDCap log either way without turning anything away. Once a test recall shows *"signature verified"* in the log, switch it **ON** so unsigned or forged messages are refused.

Version 3 doesn't sign its messages, so the module skips this check entirely for v3 projects — completion messages are accepted on the strength of the address alone. Both settings above are hidden when the version is set to v3, because neither would have any effect there.

#### Sending participants somewhere after the recall (optional)

Because the hand-off is one-way, participants finish on Intake24's completion screen by default. If you'd rather they were sent on somewhere — a thank-you page, a study website, or even back to another REDCap survey — that's arranged **in Intake24, not in this module.** The *Intake24 Redirect URL* setting won't do it in v4.

Ask your Intake24 administrator to add a **redirect prompt** to your survey scheme's submission section. It lets you set:

- **The address to send them to.** You can include the placeholder `{identifier}` and Intake24 will substitute the participant's identifier — usually their username, which is your record ID. That's how you'd point at a REDCap survey and have it land on the right record.
- **A countdown**, in seconds, after which they're forwarded automatically. Set it to zero and nothing happens unless they click the button.
- **Whether it opens in the same tab or a new one.**

Three things to be realistic about before you depend on it:

- **It can't continue a REDCap survey queue.** It's a brand-new page, so REDCap treats it as a separate visit, not a resumption of the one they left.
- **Participants can simply close the tab** at the completion screen and never follow it. Anything essential should be collected before the recall, or sent as its own invitation later.
- **A REDCap survey link you point at must be one that accepts the participant.** Talk it through with your REDCap administrator — a plain public survey link plus the record identifier is the usual approach.

Used well, this is a nice courtesy — participants aren't left wondering whether they're finished. Just don't treat it as a reliable data-collection step.

### Using this in a longitudinal project

**With scheduling switched off, longitudinal projects are supported.** The module notices which event the participant submitted the triggering form in, and writes the link back to that same event. Multiple arms are handled correctly too.

Three things have to line up, though, because the module only ever looks at the **one event the form was submitted in** — it never borrows a value from a different event:

1. **The User ID field must hold a value in that event.** Designate its form to the event you trigger from, or use the record ID field, which REDCap keeps populated in every event. If the field is empty in that event, the link is built with an empty username and Intake24 refuses to create the account.
2. **The URL field must be designated to that event**, or there's nowhere for the link to be saved.
3. **Test each event you trigger from.** If either field above is misconfigured, the participant is *still* sent to Intake24 — the redirect happens whether or not the save worked — so the only visible sign is a failure on the Logging page. Nothing in the participant's experience looks wrong.

**One design decision to make.** The module treats each event separately when checking whether a link already exists. If you designate the triggering form to several events, the participant gets **a separate link in each event**, all carrying the same Intake24 username.

On Intake24 v4 that is safe but perhaps not what you'd assume. A repeat request for a username that already exists does *not* fail and does *not* create a duplicate: Intake24 finds the existing account and hands back the same respondent. So every event's link signs the participant into **one shared Intake24 account**, and all their recalls accumulate against it regardless of which REDCap event sent them. That's usually right for repeated recalls on the same person — but if your design needs each event's recalls kept separate on the Intake24 side, you'd need a User ID that varies by event, not the record ID.

**With scheduling switched on, use a classic project.** Completion times and due dates are always written to the first event, no matter which event the recall relates to, so the three-recall tracking is only coherent in a non-longitudinal project.

## Checking that it works

Run one test participant through end to end. Every step below is recorded on your project's **Logging** page under *Intake24 Integration*, which is the first place to look if something stalls.

1. Complete the triggering form as a participant would. You should land in Intake24 without being asked to log in.
2. Look at the record. The URL field should hold a long link, and — if scheduling is on — recall #1 should have a due date a few days out.
3. Finish the recall in Intake24. Within moments the *recall #1 completed* field should fill in, *recall #2 due* should appear, and the log should show both the signature result and *"Saved to recall 1 completed time"*.
4. Repeat for recalls #2 and #3. After the third, your scheduling form should be marked *Complete*.

## If something goes wrong

**The participant lands on an Intake24 login page instead of in their recall.**
Almost always the version setting doesn't match the Intake24 site — a v3-style link means nothing to a v4 site, and vice versa. Check the version dropdown and the `/survey/` vs `/surveys/` part of your Survey URL.

**The survey after the triggering form is never completed, or nobody sees the thank-you message.**
Expected, not a fault. The participant is sent to Intake24 as the triggering form is saved and never returns, so nothing queued behind it runs. Move those items **before** the triggering form, or invite the participant to them separately afterwards.

**Submitting the triggering form does nothing the second time.**
That's deliberate. The module generates the link **once per record and event**: if the URL field already holds a value there, it doesn't rebuild the link or redirect again. To force a fresh link, clear that field and submit the form again — but note this also resets recall #1's due date.

**Intake24 rejects the link, or the participant gets an error creating their account.**
Usually the User ID field was empty for that record: an empty identifier is refused outright. Check the record has a value in the User ID field — and in a longitudinal project, check it has a value **in the event the form was submitted in**. Other causes worth ruling out: the Secret Key doesn't match the one in Intake24, *Allow user generation* has been switched off on the survey, or the version setting doesn't match the instance. The Redirect URL is *not* a likely cause — the module leaves it out of the link entirely when it isn't a valid absolute address, precisely so a half-finished value can't break account creation.

**The participant reached Intake24, but the link was never saved in REDCap.**
The redirect happens even when the write-back fails, so this points at the URL field rather than the link itself. Check the Logging page for a save error, and confirm the URL field is long enough for a several-hundred-character link and — in a longitudinal project — designated to the event you triggered from.

**Completion times never appear.**
Work through these in order: Is scheduling ticked? Is the User ID field the record ID field (see the warning above)? Is the notification URL in Intake24 exactly right, project ID included? Is the event **Survey session submitted** rather than *started*? Is the API token present, valid, and for this project? The Logging page will usually name the problem outright.

**The log says the signature is missing, but Intake24 is definitely signing.**
This is a web server configuration issue, not a module fault — some servers don't pass authorisation details through to REDCap. Send your administrator this note: *on nginx/php-fpm, add `fastcgi_param HTTP_AUTHORIZATION $http_authorization;` to the server configuration.* Leave enforcement off until it's resolved.

**A fourth recall was submitted.**
It's logged and ignored; nothing is overwritten. Related: the module decides which slot to fill by finding the first empty *completed* field, so if you manually clear one, the next notification refills it.

**A participant's link has stopped working.**
If you've set a link lifetime, check when they were enrolled — the limit runs from then, not from the last reminder. See *Choosing how long links stay valid*. If the setting is *Never expires*, look instead at the version setting and Survey URL (first entry above) and confirm the record still has a value in the User ID field.

**Nothing happens at all.**
Check the module is enabled for this project and that every required setting is filled in.

## Getting help, or telling us something is wrong

**If you are a research coordinator or project user: start with your own REDCap administrator.** They can see the module's log entries and settings, which is where the cause of most problems is visible, and REDCap's own guidance is for administrators rather than end users to contact module authors. Tell them what you were doing, which record it was, and roughly when — the Logging page is timestamped, so that's usually enough to find it.

Two things worth including, because they narrow it down quickly: **which Intake24 version** your project is set to, and **which Intake24 version your site actually runs**. A mismatch between those two is the single most common cause of trouble.

**If you are a REDCap administrator or developer**, either of these is fine:

- **Open an issue on GitHub:** [github.com/jlmnrc/intake24_integration](https://github.com/jlmnrc/intake24_integration/issues). Preferred for anything reproducible, and the best place for corrections to this document — it keeps the discussion attached to the code.
- **Email the author.** On REDCap's External Modules page the author's name is a mailto link with the module and version already in the subject line, so clicking it there saves you looking anything up.

Useful to include: the module version, your REDCap version, your Intake24 version, the relevant lines from the project's **Logging** page (the module writes its own entries there), and whether scheduling is switched on.

**Corrections to this guide are genuinely welcome.** The notes on how Intake24 behaves were verified against one version at one point in time — see *A note on accuracy* above. If your instance does something different, that's worth knowing about, and your instance is the authority, not this document.
