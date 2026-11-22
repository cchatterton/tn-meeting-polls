# TN Meeting Polls

Author: Techn  
Version: 0.1.3
Status: MVP  
Branding mode: Author Branded

## Purpose

Create meeting-time polls for logged-in organisers and verified email voting for attendees without WordPress accounts.

## Use

Activate the plugin and open **Meeting Polls** in WordPress. The Create, Open polls and History views are separate. In Create, choose a date, add one or more start times for that date, and set each duration in minutes. To give organisers a front-end dashboard, add `[tn_meeting_polls]` to a page. Voting links are emailed to attendees. Configure a reliable WordPress mail transport and a server cron that triggers WP-Cron on low-traffic sites.

## Calendar behaviour

An organiser may enable placeholders per attendee. Each proposed time produces a separate iCalendar request in the email message, without a separate file attachment. Supported clients can offer Accept or Decline. An attendee voting No cancels their placeholder for that time. Closing a time cancels all its placeholders. Choosing a final time sends a confirmed event to everyone except attendees who voted No for it and cancels other placeholders.

Calendar messages use stable attendee/time UIDs and revision sequences. WordPress mail success is a handoff, not proof of delivery. Test invitation and cancellation handling with the calendar clients used by your attendees before production use.

## Storage

Six site-prefixed tables hold polls, slots, attendees, votes, event states and queued mail jobs. These tables support per-attendee/per-slot voting and calendar state with unique keys and bounded queue processing. Voting tokens are derived from site authentication salts and per-attendee random nonces; only their hashes are used for lookup. The plugin stores attendee names, emails, votes, hashed verification codes and session hashes. It does not automatically delete polls.

## Updates

The plugin uses TN Update Controller's guarded client integration for GitHub update discovery. The controller is optional for ordinary poll operation. Install it through the plugin row action or directly from [TN Update Controller](https://github.com/cchatterton/tn-update-controller).
