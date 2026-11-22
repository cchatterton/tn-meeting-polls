=== TN Meeting Polls ===
Contributors:
Tags: scheduling, meetings, polls, calendar
Requires at least: 7.0
Requires PHP: 7.4
Stable tag: 0.1.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create meeting polls with Prefer, Yes and No voting, email code verification and optional calendar placeholders.

== Description ==

TN Meeting Polls lets logged-in WordPress users suggest meeting times, add attendees and watch responses update. Attendees receive a private link and verify a one-time email code before voting. Each attendee can mark each time Prefer, Yes or No.

The organiser can opt individual attendees into tentative calendar placeholders, close a proposed time for everyone, choose a final time, or cancel the poll. An attendee's No vote cancels only that attendee's placeholder for the selected time. Finalising sends the chosen invitation to everyone who has not voted No for it and cancels other placeholders.

Open polls can be edited from their poll card. Editing opens the Create a poll tab with the current details prefilled. Organisers can add or remove proposed times and invitees, then save. Votes and calendar identities for unchanged times and retained invitees are kept. Removed placeholders receive cancellations; new options and invitees receive invitations. Removed invitees lose access to their poll voting link. Existing polls with mixed durations show a warning before a single duration is applied to all times.

== Installation ==

1. Copy the tn-meeting-polls directory into wp-content/plugins/ and activate it.
2. Use the Meeting Polls menu in WordPress, or add [tn_meeting_polls] to a page for a logged-in organiser dashboard.
3. Configure reliable outgoing mail for WordPress. Test mail delivery and calendar updates with the clients your attendees use.

== How it works ==

Logged-in users can create polls with up to 10 proposed times and 50 attendees. Create, Open polls, History, Attendees and Settings have separate views. Each organiser can save a default meeting length and earliest and latest meeting hours. The form starts with one attendee and defaults to the WordPress site timezone, including fixed UTC offsets. Organisers set one duration for the whole poll, then choose dates and add one or more start times in 10-minute steps to each date. The Attendees view stores a personal contact list, ordered by name. In Create a poll, search the list by name or email and add matching contacts to the poll, or type new attendees directly. Attendees receive a private voting link, request a six-digit email code to verify themselves, and vote without a WordPress account. The voting page shows meeting duration near the title, groups proposed times under one heading per local date, and places each time beside its voting choices. The organiser sees vote counts and individual responses for open polls, refreshed every 15 seconds.

Calendar placeholders are opt-in per attendee. Each proposed time is sent as a separate iCalendar invitation with text/calendar as the primary message content, without a file attachment, so supported email and calendar clients can offer Accept or Decline. When SMTP2GO's WordPress mail plugin is active, calendar requests use its MIME API so the calendar content type is preserved. The configured SMTP2GO API key needs permission to send MIME email. An attendee's No cancels only their own placeholder. Closing a time cancels all its placeholders. Finalising sends a confirmed invitation to everyone who has not voted No for the chosen time and cancels other placeholders. Cancelling a poll cancels all outstanding placeholders.

Invitation and calendar mail is queued and processed through WP-Cron. On low-traffic sites, configure a real server cron to trigger wp-cron.php regularly. A successful mail API response means the message was accepted for delivery; it does not prove mailbox delivery. Calendar clients differ in how they process iCalendar updates.

== Privacy ==

The plugin stores attendee names, email addresses, votes, code hashes, session hashes, personal contact lists and calendar delivery state in site database tables. Organiser settings are stored in WordPress user metadata. Codes expire after 10 minutes. Verified voting sessions expire after 24 hours. The plugin does not delete polls automatically.

== Changelog ==

= 0.1.5 =
* Offer and validate meeting start times in 10-minute steps.

= 0.1.4 =
* Send calendar requests as primary text/calendar messages without attachments.
* Default to the WordPress site timezone, including UTC offsets.
* Set one duration for all proposed times and start with one attendee row.

= 0.1.3 =
* Send calendar invitations in the message without a separate ICS attachment and request RSVP.
* Separate Create, Open polls and History views.
* Group proposed start times by date and calculate end times from each duration.

= 0.1.2 =
* Publish the verified installable package and catalog release record.

= 0.1.1 =
* Add Techn release metadata, licence, controller integration and branded interface refinements.
* Package with the standard build process.
