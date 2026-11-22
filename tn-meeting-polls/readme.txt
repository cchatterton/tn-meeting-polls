=== TN Meeting Polls ===
Contributors:
Tags: scheduling, meetings, polls, calendar
Requires at least: 7.0
Requires PHP: 7.4
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create meeting polls with Prefer, Yes and No voting, email code verification and optional calendar placeholders.

== Description ==

TN Meeting Polls lets logged-in WordPress users suggest meeting times, add attendees and watch responses update. Attendees receive a private link and verify a one-time email code before voting. Each attendee can mark each time Prefer, Yes or No.

The organiser can opt individual attendees into tentative calendar placeholders, close a proposed time for everyone, choose a final time, or cancel the poll. An attendee's No vote cancels only that attendee's placeholder for the selected time. Finalising sends the chosen invitation to everyone who has not voted No for it and cancels other placeholders.

== Installation ==

1. Copy the tn-meeting-polls directory into wp-content/plugins/ and activate it.
2. Use the Meeting Polls menu in WordPress, or add [tn_meeting_polls] to a page for a logged-in organiser dashboard.
3. Configure reliable outgoing mail for WordPress. Test mail delivery and calendar updates with the clients your attendees use.

== How it works ==

Logged-in users can create polls with up to 10 proposed times and 50 attendees. Attendees receive a private voting link, request a six-digit email code, and vote without a WordPress account. The organiser sees vote counts and individual responses, refreshed every 15 seconds.

Calendar placeholders are opt-in per attendee. Each proposed time is sent as a separate iCalendar invitation. An attendee's No cancels only their own placeholder. Closing a time cancels all its placeholders. Finalising sends a confirmed invitation to everyone who has not voted No for the chosen time and cancels other placeholders. Cancelling a poll cancels all outstanding placeholders.

Invitation and calendar mail is queued and processed through WP-Cron. On low-traffic sites, configure a real server cron to trigger wp-cron.php regularly. wp_mail() succeeding means the message was handed to the site's mail transport; it does not prove mailbox delivery. Calendar clients differ in how they process iCalendar updates.

== Privacy ==

The plugin stores attendee names, email addresses, votes, code hashes, session hashes and calendar delivery state in site database tables. Codes expire after 10 minutes. Verified voting sessions expire after 24 hours. The plugin does not delete polls automatically.

== Changelog ==

= 0.1.2 =
* Publish the verified installable package and catalog release record.

= 0.1.1 =
* Add Techn release metadata, licence, controller integration and branded interface refinements.
* Package with the standard build process.
