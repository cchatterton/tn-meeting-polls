# Changelog

All notable changes to TN Meeting Polls are recorded here.

## 0.1.6 - 2026-11-22

- Add per-organiser meeting duration and earliest/latest hour settings.
- Add a personal, alphabetical attendee contact list with add, edit and remove actions.
- Search saved contacts and add them to a new poll without retyping names and email addresses.
- Show SMTP2GO MIME permission failures on the poll while delivery retries are pending.

## 0.1.5 - 2026-11-22

- Offer and validate meeting start times in 10-minute steps.
- Send calendar requests through SMTP2GO's MIME endpoint when its WordPress mail plugin is active, preserving real invitation MIME for Outlook.

## 0.1.4 - 2026-11-22

- Send each calendar request as the primary text/calendar message content, without a file attachment.
- Default to the WordPress site timezone, including UTC offset settings.
- Set one duration for every proposed time and start the form with one attendee.

## 0.1.3 - 2026-11-22

- Send calendar invitations as inline calendar requests without separate ICS attachments, with RSVP enabled.
- Separate poll creation, open polls and history into distinct views.
- Choose dates first, add multiple start times to each date, and set each time's duration.

## 0.1.2 - 2026-11-22

- Publish the verified installable package and catalog release record.

## 0.1.1 - 2026-11-21

- Add Techn author branding, release metadata, licence, readmes and standard package build.
- Integrate the guarded TN Update Controller client for GitHub distribution.
- Apply the Techn interface palette, version watermark, rem-based CSS and visible focus treatment.

## 0.1.0 - 2026-11-21

- Initial local meeting poll implementation with verified voting, live results and calendar placeholders.
