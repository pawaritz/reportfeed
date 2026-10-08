# Changes

## 0.9.1 (release candidate)

Fixes from an independent code review (security, privacy and SQL, contract, name check). No schema change.

- Teacher digests: a teacher whose enrolment in the course is suspended or ended no longer receives that course's
  learner data (the role alone is not enough), and cannot be named as a nominee.
- Activity files: assignment and quiz grades that the teacher hid (or hid until a later date) are blank, as in the
  learner's own gradebook and in the learner files.
- Course total on a scale or as text: `grade` and `grade_percent` stay empty instead of showing a scale index.
- `completion_percent` now counts completed and passed activities only, as Moodle does. A failed activity still makes
  the learner `in_progress`.
- Learner roster: the column `change` is now `change_type` (`change` is a reserved word in MySQL). The file was new in
  0.9.0 and nothing was released with the old name beyond the release candidate.
- Learner roster: the whole result of a run is stored in one transaction, and the retention job never deletes the newest
  sent roster of a schedule, so a monthly schedule with a short retention does not mark everyone as new.
- Learner files left in the file pool by a killed worker are removed after a day.

## 0.9.0 (release candidate)

- Getting started page: a live checklist of the setup steps (outgoing mail, attachments, cron, master switch, receiving
  user, schedule, recipients, test send, first run), linked from the settings and the schedules page.
- Learner roster file (`learner_roster`, contract v1 addition): one row per learner with status, change since the last
  send (new, unchanged, removed), a reason for removed learners and the account creation time. Each run stores its own
  result so retries are identical; the email states the headcount. Snapshot table with privacy export and erase.
- Choose the learner files per schedule: learners by course, learner summary and roster, in any combination (at least
  one file overall). Existing schedules keep the first two.
- Optional introduction and footer text for the teacher digest email (settings). The HR feed email is unchanged.
- Verified on MySQL 8.4 as well as PostgreSQL and MariaDB; Behat scenarios added and run in headless Chromium;
  engagement file measured on a 9-million-row log table; a second worker on the same run sends no second email.
- Fix: none needed in the engagement query; hidden courses are covered by "all courses" (test added).

## 0.7.0 (beta, v1.1: M9 to M14)

- More frequencies: every week, every 2 weeks, 1st and 15th, and monthly (a day of the month, the last day, the first
  or last chosen weekday, the first or last working day). Everything follows the site timezone.
- Course bundles: name a set of courses once and point any number of schedules at it.
- Excel (.xlsx) as well as CSV, chosen per schedule. Excel cells keep numbers as numbers.
- Large reports are split into numbered parts ("part 1 of N"), each under the size cap, instead of being dropped.
  A report that would need more parts than the limit sends a notice without a file. Zip is still available.
- Recipients outside Moodle: an administrator with a separate capability can add email addresses (any domain) to a
  schedule. Off by default; the run log lists the addresses; the form carries a warning that the data leaves Moodle.
- Activity-level files, chosen per schedule and per column: activity completion, assignments, quizzes and
  engagement (course views, activity views, active days, logins) over a stated period. A site row limit protects
  large sites. Engagement needs the standard log store.
- Friendlier screens: a preview page that explains every column in plain language and shows the first rows, a
  summary line and status badge per schedule, and a health notice when something will stop reports.
- Teacher digest email: starts with counts only (no learner names), has an HTML and a plain-text version, and links
  to the course and to the page where a teacher changes or stops the digest. The CSV is unchanged.
- Contract stays at version 1: only additions (part keys, part file names, new optional files).
- Fix: saving a schedule whose recipients are only outside addresses no longer fails on the empty recipient picker.

## 0.6.1 (beta, M8)

- Scale: learners of a course are loaded 2,000 at a time instead of all at once. On a test with 50,000 learners in each of
  5 courses (250,000 rows) peak memory fell from 221 MB to 36 MB for the course file, and the whole feed fits in 128 MB.
  The output is identical.

## 0.6.0 (beta, M6)

- Retention: a daily task deletes finished run-log entries and their delivery rows after the configured period
  (default 90 days, never below 7); queued and running runs are never purged. Digest preferences of deleted
  courses are removed with it.
- Hardening pass over every screen and action (session key, capability, parameter types, escaping); grade columns
  are also refused on save for a teacher who may not see grades; editing a schedule that no longer exists stops
  with an error instead of saving; the digest screen no longer starts output before it handles the form.
- README with what the plugin adds beyond core, requirements, capabilities, personal data and mail notes.

## 0.5.0 (alpha, M5)

- Teacher digest: a teacher sets a weekly digest per course (day, hour, columns, optional colleagues they
  nominate) from the course navigation under "More". One email and one CSV per recipient and course. The site
  admin chooses off, opt-in or opt-out (ships off). In opt-out mode teachers without a saved preference get the
  defaults (Monday 07:00; completion status and percent, last access, days inactive; no grades) for visible,
  running courses; an explicit preference always wins.
- A digest recipient only receives what their own Moodle permissions allow: capability, groups, grade columns.

## 0.4.0 (alpha, M4)

- Admin screens: schedules list and editor, run log with status and error, "run now", "send test to me",
  resend for runs that did not complete, health warnings (feature switched off, attachments disabled, cron late).

## 0.3.0 (alpha, M3)

- Scheduler: a dispatcher every 5 minutes creates one run per schedule and send date (unique key, site timezone,
  daylight-saving safe); an ad hoc task sends it. A retry never emails someone already served; 3 attempts, then
  the run is recorded as failed (no failure email; the run log screen and resend button arrive in M4).
- HR feed sends two emails per recipient, one CSV each (subject `[Reportfeed] hr_feed | <file> | <site> | <date>`),
  with optional zip and a size cap. Suspended, deleted or capability-less recipients are skipped and logged.
- Full privacy provider for the new tables.

## 0.2.0 (alpha, M2)

- Data provider: learner-per-course and learner-summary rows from set-based SQL (tracked learners
  with active enrolments only, progress as the learner sees it, bulk grades with hidden grades left
  empty, last access), scoped by the recipient's capability, groups and grade permissions.
- Contract v1 CSV writer: RFC 4180 quoting, ISO 8601 UTC timestamps, formula-injection guard,
  optional byte order mark, metadata for the run log. Golden files in `tests/fixtures`.
- Still sends nothing: scheduling and delivery arrive in M3.

## 0.1.0 (alpha, M1)

- Skeleton: version metadata for Moodle 5.3, three capabilities, two message providers
  (`hrfeed` forced email, `teacherdigest` mutable), settings page with an attachments
  health notice, null privacy provider, PHPUnit smoke tests, CI workflow.
