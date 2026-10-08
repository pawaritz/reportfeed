# Reportfeed (local_reportfeed)

Emails scheduled learning-progress reports as CSV from Moodle: a weekly feed for an HR or LMS admin
whose mailbox automation reads the files, and a weekly digest for teachers about their own courses.

**Status: release candidate (0.9.0).** Feature complete for 1.0. Tested with PHPUnit on PostgreSQL 17, MySQL 8.4 and
MariaDB 11.4, with Behat in headless Chromium, in a real browser, and at scale (50,000 learners, a 9-million-row log).
GitHub CI and real mail providers are not yet tested (see "Known limits"). Try it on a test site first.

## What it adds beyond core

Moodle's Report builder can already email a scheduled CSV to Moodle users. Reportfeed does not replace that. It adds
what a custom report does not give you out of the box:

- **A fixed, versioned CSV contract** for the HR feed (same columns in the same order every run, `user_id` always
  present, ISO 8601 UTC timestamps, formula-injection guard), a unique file name per run and a key-value block in
  every email body (file name, schema version, period end, row count, size, SHA-256), so unattended automation can
  read it without guessing.
- **A run log** with status, error, rows and bytes per file, and a resend button for runs that did not complete.
  Retries never email someone who was already served.
- **Frequencies that match how HR works**: weekly, every 2 weeks, 1st and 15th, or monthly (a fixed day, the last day,
  the first or last weekday you pick, the first or last working day), in the site timezone.
- **Course bundles**: name a set of courses once, reuse it in several schedules.
- **CSV or Excel**, per schedule. Big reports are split into numbered parts under the size cap instead of being dropped.
- **Activity-level files** you tick on, column by column: activity completion, assignments, quizzes and engagement.
- **A learner roster** for keeping a master list (for example HR data) in step with Moodle: who is a learner now, marked
  new or unchanged since the last send, plus anyone removed with the reason. It can be the only file a schedule sends.
- **A Getting started page** with a live checklist of the setup steps (mail, cron, attachments, receiving user, first
  schedule, test send), so a new administrator can see what is still missing.
- **A teacher digest** that each teacher controls per course (on or off, day, hour, columns, colleagues to nominate).
  Every recipient only gets what their own Moodle permissions allow: capability, group mode and grade visibility.

## Requirements

- Moodle 5.3 (build 2026100500) or later in the 5.3 branch, PHP 8.3+.
- Cron running at least every five minutes.
- Outgoing email configured, and Moodle's `allowattachments` setting turned on. The plugin warns when it is off,
  because Moodle then sends emails without their CSV file and without any error. Reportfeed refuses to send in that state.

## Installation

1. Copy the `reportfeed` folder to `<moodle>/public/local/reportfeed`, or upload the ZIP at
   Site administration > Plugins > Install plugins.
2. Visit Site administration > Notifications and finish the upgrade.
3. Open Site administration > Plugins > Local plugins > Reportfeed > Getting started and follow the checklist.

No Composer step and no third-party libraries. Nothing is sent until you tick **Enable Reportfeed**.

## Using it

**Getting started.** Local plugins > Getting started lists the steps in order with a live status for each: Moodle's
outgoing mail (SMTP server, port, security, user, password, no-reply address, and SPF and DKIM at your mail provider),
"Allow attachments", cron, the Reportfeed master switch, a user who holds the receiving capability, a first schedule,
its recipients, a test send, and a look at the first real run. Reportfeed has no mail settings of its own: it uses Moodle's.

**HR feed.** Give the receiving user (a person or a service account) a role with `local/reportfeed:receivehrfeed`.
Then, under Local plugins > Reportfeed schedules, add a schedule: how often and when (site timezone), which courses
(all courses, some categories, chosen courses or a named bundle), which identity columns to include, the file format
(CSV or Excel), optional zip and byte order mark, and the recipients. Each recipient gets one email per file and part:
`learner_course` (one row per learner and course) and `learner_summary` (one row per learner), or whichever of those
two and the roster you tick under "Learner files", plus any activity files
you ticked. Subject: `[Reportfeed] hr_feed | <file> | <site> | <YYYY-MM-DD>`, with ` | part N of M` when a file was split.
"Run now" queues a run outside the schedule; "Send test to me" sends the files to you only; "Preview" explains every
column in plain words and shows the first rows.

**Learner roster.** Tick "Learner roster" under "Learner files" (alone, if that is all the receiving system needs). One
row per learner in the covered courses, with `status` (active or inactive), `change` (new, unchanged or removed since
the roster last sent) and, for a removed learner, a `reason`: `account_deleted`, `account_suspended`, `not_enrolled`
(no enrolment left in the covered courses) or `not_active_learner` (enrolment suspended or ended, or no longer a
learner). A removed learner appears once, in the first roster after leaving. The very first roster marks everyone as
new. The email says how many learners there are now and how many are new and removed. Each run stores its own result
(the newest three runs of a schedule are kept), so a retry sends the same roster, and a run that did not send is not
used as the comparison. Narrowing a schedule's courses shows as removals. Only people enrolled as learners in the
covered courses are listed, not every Moodle account.

**Bundles.** Local plugins > Reportfeed course bundles. A bundle cannot be deleted while a schedule uses it.

**Activity files.** In the schedule form, under "Activity-level files", tick any of: activity completion (one row per
learner and tracked activity), assignments (due date, submission status, grade), quizzes (attempts, status, grade) and
engagement (course views, activity views, active days, site logins in a period). For each you choose exactly which
columns are included. The learner id, the identity columns and the course and activity are always there. Completion,
assignment and quiz data are a snapshot at send time. Engagement counts cover a period: since the previous send
(default), the month just ended (default for monthly schedules) or this month so far. Engagement needs the standard log
store. These files have one row per learner and activity, so they get large quickly: a site setting (default
1,000,000 rows) stops a file from being built and the recipients get a notice instead.

**People outside Moodle.** Switched off by default. When an administrator turns on "Allow external recipients" and
gives someone the capability `local/reportfeed:editexternalrecipients`, that person can add email addresses (any
domain, up to 50 per schedule) to a schedule. **Learner data then leaves Moodle for people who have no account, so
Moodle cannot limit what they see or do with it.** Make sure your privacy policy and consent allow it. The run log lists
the addresses; the schedule form carries a warning; turning the setting off stops all emails to those addresses.

**Teacher digest.** Under Reportfeed settings, choose the site mode: *off* (default), *opt-in* (teachers switch it on
in each course) or *opt-out* (teachers get a default digest until they change it; only visible, not-ended courses).
The email starts with counts only (completed, in progress, not started, inactive, average completion; never a learner's
name) and links to the course and to the page where the teacher changes or stops the digest; the CSV is attached.
You can add your own introduction and footer (plain text, up to 1,000 characters each) in the settings; they appear on
the teacher digest only, never in the HR feed. A teacher opens a course, then More > Weekly digest. Default digest: Monday 07:00, completion status, completion
percent, last access to the course, days inactive. Grade columns are offered only to teachers who may see grades.

**Run log.** Local plugins > Reportfeed run log shows every run. There is no failure email by design: look here.

## Settings

| Setting | Default | Meaning |
|---|---|---|
| Enable Reportfeed | off | Master switch for both audiences |
| Teacher digest mode | off | off, opt-in or opt-out |
| Inactivity threshold (days) | 14 | Days without course access after which a learner is reported as inactive |
| Attachment size cap (MB) | 10 | A file over this size is split into numbered parts, each under the cap |
| Most parts per report | 10 | A report that would need more parts sends a notice without a file |
| Most rows in an activity file | 1,000,000 | A larger activity file is not built; recipients get a notice |
| Teacher digest introduction and footer | empty | Optional text at the top and bottom of every teacher digest email (not the HR feed) |
| Allow external recipients | off | Lets holders of the external-recipients capability add outside addresses |
| Run log retention (days) | 90 | Finished runs older than this are deleted daily; the minimum is 7 |

## Capabilities

| Capability | Context | Default |
|---|---|---|
| `local/reportfeed:manageschedules` | System | Manager |
| `local/reportfeed:receivehrfeed` | System | None (assign to the HR or service-account user) |
| `local/reportfeed:receiveteacherdigest` | Course | Teacher, Non-editing teacher |
| `local/reportfeed:editexternalrecipients` | System | None (marked as a privacy and spam risk; assign with care) |

## Email sender, SPF and DKIM

Reports are sent through Moodle's normal mail path, so the sender is your site's no-reply address. If the HR mailbox
or its automation rejects or junks the mail, check that your site's sending domain has valid SPF and DKIM records for
your mail provider. Reportfeed cannot fix that from inside Moodle, and it says "handed to Moodle mail", not "delivered":
Moodle does not learn what happens after the mail server accepts a message.

## Personal data (GDPR, PDPA)

The reports contain learner names, ids, progress, last access and, for those who may see them, grades. They leave
Moodle by email, so the recipient's mailbox becomes the place that data lives. Choose recipients accordingly, and
check your own policy on sending learner data by email, including for children's data and cross-border mail.

If you use external recipients, the files go to mailboxes outside your control and outside Moodle's privacy tools: a
Moodle export or deletion request for a learner does not reach what was already sent. Tell learners, and keep that list short.

What Reportfeed itself stores: HR feed recipients, external email addresses typed in by an administrator, schedule owner, a log of runs and deliveries (user id, status,
reason), and teacher digest preferences. The report files are not kept: each is attached to its email and then removed
from Moodle. Run-log entries are purged after the retention period. The privacy provider exports and deletes this data
per user and per context. Uninstalling the plugin removes its tables and settings.

## Known limits

- Tested on PostgreSQL 17, MySQL 8.4 and MariaDB 11.4. The GitHub Actions workflow has not been run.
- Real mail providers were not tested, only a local SMTP sink.
- The learner roster stores a small snapshot per run (user id and change), and a retry of an old run after newer runs have
  been pruned compares against nothing and shows everyone as new.
- Engagement counts come from the standard log store only, count the days the log is kept, and do not measure time spent.
  Site logins are site-wide, so the same figure is on every course row of a learner.
- Assignments: a group or user override of the due date is not applied (only a learner's extension), and team submissions
  are not followed. A restricted activity still counts as tracked and can show as incomplete.
- If a failed split report is retried after the data changed, the parts can differ from the first attempt; recipients who
  already received a part keep it.
- Excel cells hold numbers as numbers; a text cell starting with `=`, `+`, `-` or `@` gets a leading apostrophe.
- "Send test to me" builds the files during the web request, so a very large site may hit the PHP time limit.
- In opt-out digest mode, a teacher who gets the capability after the weekly default moment first receives a digest the following week.
- One site timezone for all schedules. Moodle sends external addresses through its mail function, not the message API, so they have no notification preferences.
- English only.

## Support

Report bugs and requests through the issue tracker of the plugin's repository.

## Licence

GNU GPL v3 or later. See `COPYING.txt`.

## Copyright

2026 Pawarit Pingmuang, Edvanced.me
