# TN Meeting Polls

Author: Techn  
Version: 0.1.9
Status: MVP  
Branding mode: Author Branded

## Purpose

Create meeting-time polls for logged-in organisers and verified email voting for attendees without WordPress accounts.

## Use

Activate the plugin and open **Meeting Polls** in WordPress. Create, Open polls, History, Attendees and Settings are separate views. Settings saves each organiser's default duration and earliest and latest meeting hours. Attendees stores a personal, alphabetical contact list. In Create, the time zone defaults to the WordPress site setting; choose dates and start times in 10-minute steps, then search saved contacts by name or email to add them to the poll. New attendee details can also be typed directly. To give organisers a front-end dashboard, add `[tn_meeting_polls]` to a page. Voting links are emailed to attendees. Configure a reliable WordPress mail transport and a server cron that triggers WP-Cron on low-traffic sites.

Open polls have an Edit poll link. It opens the Create tab with current values. Saving retains unchanged vote and calendar records, cancels placeholders for removed times or invitees, and sends revised options and new invitations. Removed invitees can no longer use their voting link. Previously closed times can be retained or removed; only open times accept votes.

The attendee voting page shows meeting duration near the title and each local date once, with every proposed time beside its Prefer, Yes and No controls. Code verification is presented in one row before voting.

## Calendar behaviour

An organiser may enable placeholders per attendee. Each proposed time produces a separate iCalendar request as the email's primary text/calendar content, without a file attachment. Supported clients can offer Accept or Decline. An attendee voting No cancels their placeholder for that time. Closing a time cancels all its placeholders. Choosing a final time sends a confirmed event to everyone except attendees who voted No for it and cancels other placeholders.

Calendar messages use stable attendee/time UIDs and revision sequences. WordPress mail success is a handoff, not proof of delivery. Test invitation and cancellation handling with the calendar clients used by your attendees before production use.

## Storage

Seven site-prefixed tables hold polls, slots, poll attendees, votes, event states, queued mail jobs and organisers' saved contacts. Meeting preferences are stored as user metadata. These tables support per-attendee/per-slot voting and calendar state with unique keys and bounded queue processing. Voting tokens are derived from site authentication salts and per-attendee random nonces; only their hashes are used for lookup. The plugin stores attendee names, emails, votes, hashed verification codes and session hashes. It does not automatically delete polls.

## Updates

The plugin uses TN Update Controller's guarded client integration for GitHub update discovery. The controller is optional for ordinary poll operation. Install it through the plugin row action or directly from [TN Update Controller](https://github.com/cchatterton/tn-update-controller).
