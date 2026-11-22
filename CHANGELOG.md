# Changelog

All notable changes to TN Meeting Polls are recorded here.

## 0.1.20 - 2026-11-22

- Remove the five-minute future cutoff from poll creation and editing.
- Explain invalid times without implying future dates are required.

## 0.1.19 - 2026-11-22

- Treat preferred meeting hours as time-picker navigation hints instead of save restrictions.
- Seed an empty native time picker at a ten-minute value so it does not display the current off-step minute.

## 0.1.18 - 2026-11-22

- Show poll cards as an accordion, with one poll open at a time and the first poll open initially.
- Preserve the open poll and its Individual votes state during live refresh.
- Extend the Meeting Polls admin background through the full page height.

## 0.1.17 - 2026-11-22

- Restore the original single browser time input while retaining 10-minute validation.
- Make Contacts and Templates use matching record tables and Add/Edit modals.
- Rename the Meeting templates tab to Templates.

## 0.1.16 - 2026-11-22

- Add daily WP-Cron cleanup of historical polls using each organiser’s retention days since the poll’s last update. Existing polls receive a fresh retention window.
- Add manual historical poll deletion after queued mail is complete, including related records.
- Add meeting templates with add, edit, delete and open actions; opening a template prefills Create/Edit.
- Move Add Contact into a modal beside Invite selected, and rename the Contacts, Create/Edit and Open/In-Progress tabs.

## 0.1.15 - 2026-11-22

- Submit poll actions and contact removal directly without browser confirmation dialogs.
- Move Cancel poll to the right with space below Individual votes.

## 0.1.14 - 2026-11-22

- Select saved attendees from the contact table and open Create a poll with those people already added.

## 0.1.13 - 2026-11-22

- Show saved attendees in a table, with name, email, Save and Remove on one row per attendee.

## 0.1.12 - 2026-11-22

- Put the meeting duration beside the public poll title, show the description below, and add space before the verification card.

## 0.1.11 - 2026-11-22

- Name tentative calendar meetings `[Placeholder] Meeting Name`, including their cancellation updates. Confirmed meetings use the normal name.

## 0.1.10 - 2026-11-22

- Offer explicit 10-minute start choices so the browser cannot insert its current minute.
- Move voter details into the verification card before voting.
- Remove the Scheduling poll label and version from the public voting page.

## 0.1.9 - 2026-11-22

- Make the voting page use identity verification wording and a single-row code entry.
- Show meeting duration at the top, remove repeated end times, and place each time beside its three vote choices.
- Remove the placeholder explanation below the vote options.

## 0.1.8 - 2026-11-22

- Group attendee voting choices under one heading per local date, with individual times and votes below it.

## 0.1.7 - 2026-11-22

- Edit open polls from the first tab with current dates, times and invitees prefilled.
- Add or remove proposed times and invitees while keeping votes and calendar identities for unchanged options.
- Queue cancellations for removed placeholders and invitations for new times and people.
- Revoke voting links for removed invitees and preserve unchanged legacy times when editing.

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
