# ZP Vendor Portal

Custom plugin for the ZacPubs vendor registration and conference portal (phase 1).

## Structure

- `zp-vendor-portal.php` – bootstrap, constants, autoloader, activation hooks
- `includes/class-plugin.php` – main class; feature modules are registered in `load_modules()`
- `includes/class-roles.php` – `zp_vendor` role and portal capabilities
- `includes/class-activator.php` / `class-deactivator.php` – install steps (idempotent) and non-destructive deactivation

Classes use the `ZPVP` namespace. `ZPVP\Foo_Bar` loads `includes/class-foo-bar.php`;
sub-namespaces map to sub-folders (`ZPVP\Admin\Reports` → `includes/admin/class-reports.php`).

## Roles and capabilities

| Role / cap | Purpose |
|---|---|
| `zp_vendor` | Vendor contacts. Front-end only, no wp-admin access. |
| `zpvp_access_portal` | Access to vendor portal pages (vendors and administrators). |
| `zpvp_manage` | Staff screens in WP Admin (administrators). |

Deactivating the plugin does not remove roles or data.

## Pages (created on install, IDs stored in `zpvp_pages`)

| Key | Slug | Content |
|---|---|---|
| `register` | `/vendor-registration` | Gravity Forms "Vendor Registration" form (ID stored in `zpvp_registration_form_id`) |
| `login` | `/vendor-login` | `[zpvp_login]` – login, lost password, reset password, add vendor access |
| `dashboard` | `/my-conferences` | `[zpvp_dashboard]` – vendors and staff only |

Existing pages are never overwritten; a page that already has the slug is adopted.

## Access rules

- Vendors without staff capabilities are redirected away from wp-admin and have no admin bar.
- Logins through any form (including the hidden WordPress login) send vendors to My Conferences.
- Vendor-only users are redirected from WooCommerce My Account to My Conferences.
- Password reset emails for vendors link to `/vendor-login?action=rp`, not the hidden WordPress login.
- Registering with an email that already exists shows a link to log in and add vendor access to that account.

## Deployment

Pushing to `develop` deploys this plugin to the WP Engine dev environment
(`texascount1dev`) through `.github/workflows/deploy.yml`, using WP Engine's
official GitHub Action over the SSH Gateway. Only `wp-content/plugins/zp-vendor-portal/`
is touched. Files listed in `.deployignore` and dotfiles are not deployed.

Required repository secret: `WPE_SSHG_KEY_PRIVATE` (private half of a deploy
key whose public half is added to a WP Engine user with access to the install).

Do not edit plugin files on the server or upload zips once Git deploys are set
up: the next deploy overwrites the folder (`--delete`).

## Conferences (v0.3.0)

- `zp_conf_series` – a recurring conference, linked to its Gravity Forms (`gf_forms`) and to its
  booth field on the annual form (`annual_product_field`).
- `zp_conf_edition` – one yearly event: dates, venue, registration window, attendee edit deadline,
  booth capacity, status override and resources. Titles are generated from series + year.
- Fields are ACF local field groups defined in `class-conferences.php` (not editable in the ACF UI).
- `Conferences::status()` returns not_scheduled / upcoming / open / sold_out / closed / past.
  Booth counts come from the `zpvp_edition_booths_taken` filter (wired up by the registration module).
- **Vendor Portal → Setup** creates the initial series from the audit (`class-seeder.php`); re-running never duplicates.

## Registrations (v0.4.0)

- Table `{prefix}zpvp_registrations` – one row per company per conference, linked to the Gravity Forms entry
  (`gf_entry_id` + `series_id` is unique, so re-processing an entry never duplicates it).
- `Registration_Sync` records a registration on `gform_after_submission` for any form linked to a series.
  The annual form (44) creates one row per conference selected, using each series' `annual_product_field`.
- Field positions come from `Form_Map` (standard exhibitor template and annual form); override per form with the
  `zpvp_form_map` filter.
- Payment status: card = paid/pending/failed from Gravity Forms (updated on `gform_post_payment_completed`),
  check and PO = awaiting until staff use **Mark paid**, $0 = no charge.
- Linked forms require a vendor login (disable with `zpvp_require_login_for_registration`), and company,
  name and email are prefilled for logged-in vendors.
- Booth counts feed `Conferences::status()`, so a conference shows Sold out when capacity is reached.
- **Vendor Portal → Registrations** lists, filters and searches registrations; vendors see theirs on My Conferences.

## Registration admin, export and resources (v0.5.0)

- **Edit** a registration (Vendor Portal → Registrations → Edit): booth number, payment status and staff notes.
  Payment status changes are appended to the notes with the staff member's name and time.
- **Export CSV** exports the current filtered list (UTF-8 with BOM, formula-injection safe).
- Vendors see hotel, decorator and electricity information, the floor plan and other documents for the
  conferences they're registered for, on My Conferences.

## Attendees (v0.6.0)

- Table `{prefix}zpvp_attendees` – booth personnel per registration (name, title, email). Removed rows are kept
  with `status = removed` for the badge history.
- Imported from the form's Booth Personnel list field (field 45 in the standard template) when the registration is
  recorded; registrations recorded earlier are backfilled on upgrade.
- Vendors manage them at My Conferences → Booth personnel (`/my-conferences/?registration=ID`) until the
  conference's attendee edit deadline (or its start date if no deadline is set). Staff can edit any time from
  Vendor Portal → Registrations → Edit.
- Actions `zpvp_attendee_added`, `zpvp_attendee_updated`, `zpvp_attendee_removed` fire for the badge module.

## Badges (v0.7.0)

- Table `{prefix}zpvp_badges` – one badge per active attendee, kept in sync by `Badges::sync_registration()`
  whenever attendees change or staff edit the registration.
- Type: **standard** within the registration's included badges (form field 46), **additional** beyond them,
  **on-site change** when created or changed after the conference starts. Badges beyond included + purchased
  (form field 31 quantity) are flagged **needs payment**.
- Status: ready to print → printed; a printed badge whose name, title or company changes returns to the queue
  (reprint). Removing an attendee voids the badge.
- **Vendor Portal → Badges**: filter by conference, status and payment flag; print selected or all ready badges
  for a conference (4" × 3", six per Letter sheet), then mark them printed; send back for reprint; void.

## Invoices and receipts (v0.8.0)

- `Documents` builds a PDF per Gravity Forms entry with the built-in `Pdf` writer (no external libraries):
  receipt when every registration on the entry is paid or no-charge, otherwise invoice with payment instructions.
- Line items come from the entry's product fields; any difference to the form total is shown as
  "Processing fees and adjustments".
- Vendors download from My Conferences → Documents; staff from Registrations (row action) or the Edit screen.
  Links are nonce-protected and only work for the owning vendor or staff. Documents are generated from current
  data on every request, so they're always up to date (no stored files to regenerate).
- Issuer details and payment instructions: Vendor Portal → Setup → Invoices and receipts.

## Emails (v0.9.0)

- All email goes through `wp_mail` (Gravity SMTP / SendGrid) via `Mailer`, with a simple branded template
  (issuer details from Setup) and a log table `{prefix}zpvp_email_log` that also prevents duplicate sends.
- Automatic (toggle in **Vendor Portal → Messages**):
  - Registration confirmation with invoice/receipt PDF attached – **off by default** because the conference forms'
    own Gravity Forms notifications usually already confirm.
  - Payment received with receipt attached – when a card payment completes or staff mark a registration paid
    (sent once per form entry, when everything on it is paid).
  - Booth personnel reminder N days before the attendee edit deadline (daily cron `zpvp_daily`).
- **Messages**: email every vendor registered for a conference (all / paid / outstanding), with placeholders and a
  "send a test to me" option. More than 25 recipients are sent in the background with Action Scheduler.
  Each conference's edit screen has a shortcut to write a message.

## Reports (v0.10.0)

- **Vendor Portal → Reports**: totals (registrations, booths, booth personnel, registered value, paid, outstanding)
  and a per-conference table with booths sold vs capacity. Filters: conference, year, payment status (including
  "outstanding"), vendor/contact search and registration date range.
- CSV exports for registrations, booth personnel and badges, all respecting the current filters (`Csv` helper:
  UTF-8 BOM, formula-injection safe).
- Per-conference amounts are the booth amounts recorded per conference; the one-time annual fee on the annual form
  is not allocated to a conference.

## Vendor front-end redesign (v0.11.0)

- `Frontend`: portal pages use the Genesis full-width layout, get a `zpvp-portal` body class (theme page title hidden),
  and login/registration pages get a two-column layout (intro + form panel).
- Portal shell with navigation (My conferences, Account, Log out), navy/red palette matching the site header.
- My Conferences: upcoming registrations as exhibitor passes (booth number first), open conferences with a date
  block and "You are registered" state, conference information accordions, previous conferences.
- New Account page (`?view=account`): name, company, job title, phone, password change.
- Conference forms also prefill phone for logged-in vendors.

## Staff workspace (v0.12.0)

- **Vendor Portal → Dashboard** (`Admin_App` + `assets/js/admin-app.js`): conference switcher, live totals
  (booths sold vs capacity, registrations, paid, outstanding, badges to print), Registrations and Badges tabs.
- Registrations: search as you type, payment filter chips with counts, inline booth numbers (save on Enter/blur),
  one-click Mark paid, CSV export of the current view. Clicking a row opens a side panel with details, payment,
  badge counts, staff notes and a booth staff repeater (add/remove rows, save once).
- Badges: status chips, select rows, Print (opens the print sheet in a new tab), Mark printed, Send back, Void.
- Email vendors pop-up (also opened from a conference's "Write a message" button).
- REST API `zpvp/v1` (`Rest`): /summary, /registrations, /registrations/{id} (GET/POST), /{id}/paid, /{id}/booth,
  /{id}/attendees, /badges, /badges/bulk, /messages. All require `zpvp_manage` and the wp_rest nonce.
- Menu: Dashboard, Conferences, Reports, Settings, Conference Series. Setup, automatic email settings and the
  email log live under Settings. The classic Registrations, Badges and Messages screens remain reachable by URL.

## Simpler yearly setup (v0.13.0)

- Registration page is detected automatically: the published page containing one of the series' forms
  (shortcode or block, active forms first). The field on the Registration tab is now an optional override. A side box
  on each conference shows the page found and warns when that form is switched off in Gravity Forms.
- "Copy for next year" (row action and side box): creates a draft for the next year with the same series, venue,
  capacity and information, dates moved forward one year, never duplicating an existing series + year.
- Conference Series are managed from Vendor Portal → Settings instead of the main menu.

## Standalone install (v0.15.0)

- On activation (and on the next admin page load if Gravity Forms is activated later) the plugin creates its
  Vendor Registration form (tagged `zpvp_form = vendor_registration`, never duplicated) and its User Registration feed,
  then the portal pages. No site-specific form IDs are hard-coded.
- Missing Gravity Forms, User Registration add-on or ACF PRO are reported on the Plugins screen, the Dashboard and
  Settings → Overview.
- Vendor Portal → Settings → Conference series → "Connect more forms" (`admin.php?page=zpvp-connect`) detects
  conference registration forms (booth fields plus payment/product) and the annual multi-conference form (5+ booth
  dropdowns), suggests series names and annual booth fields, and creates series only for the forms staff tick.
  A banner offers this while no series exist.
- The annual form ID is stored in `zpvp_annual_form_id` (filter `zpvp_annual_form_id`).

## Conferences own their forms (v0.16.0)

- Staff work with one thing: **Conferences** (one per event per year). There are no series screens any more.
- Each conference has a **Conference name** (event name without the year; the title is "Name Year"), and on its
  Registration tab the **Registration form** (Gravity Forms by name, several allowed, e.g. an old and a new form) and
  the **Booth dropdown on the annual form** (by label).
- Routing rule: a form's new registrations go to the **newest published conference** that chose it
  (`Conferences::for_form()`); if none is published, the newest draft, so nothing is dropped. Annual-form bookings go
  to the conference that chose that dropdown for the annual form's year (`Conferences::for_annual_field()`); with no
  conference for that year yet they are kept under the event's name with `edition_id = 0`.
- The conference edit screen's **Registration** box says in plain words where each form's registrations go, and warns
  when a form is switched off, not on any page, or the conference is still a draft.
- **Settings → Registration forms** lists every linked form and the conference receiving its registrations, plus the
  annual form's dropdowns and the conference each books. "Connect more forms" now creates draft conferences.
- `zp_conf_series` remains as a hidden grouping maintained from the conference name (`Conferences::finalise()`), so
  `series_id`, reports and the `(gf_entry_id, series_id)` duplicate guard are unchanged. No database changes.
- One-time migration (`zpvp_conference_model = v2`): each series' forms, annual dropdown and name are copied onto its
  conferences; a series with no conference gets a draft conference for the annual form's year.
- `Form_Map::series_for_form()`, `Form_Map::annual_series()` and `Conferences::series_forms()` remain as wrappers.
## Annual form rolls over by itself (v0.17.0)

- `Annual_Form` (class-annual-form.php). The annual form's **season** is the newest year with a published conference
  that has a booth dropdown chosen (any status if none is published). Optional override: Settings → Registration forms
  (`zpvp_annual_season`, 0 = automatic). The form title is no longer read.
- Form text uses `{season}`, `{season_prev}`, `{season_next}`. They are filled in on `gform_pre_render`,
  `gform_pre_validation` and `gform_pre_submission_filter` (same result each time, on cloned fields, so choice values
  such as "Unable to Exhibit in {season}" validate and save with the real year) and in emails/confirmations via
  `gform_replace_merge_tags`.
- A booth dropdown description of `{conference_details}` shows its conference's dates, venue and city for the season.
  Without a start date it shows `annual_description_fallback` (the form's old text, saved on the conference; not copied
  to next year), else "Dates to be announced".
- A dropdown with no conference for the season is left off the form, and so is a section heading left empty.
- Pages: `[zpvp_season]`, `[zpvp_season offset="-1"]`; `{season}` works in page titles (also Yoast and menus).
- Settings → Registration forms → **Make years automatic** converts a form with fixed years once (preview first; JSON
  backup of the form and pages in `wp-content/zpvp-backups/`). Annual bookings use `Annual_Form::season()` for the year.
- Yearly workflow: copy each conference for next year, update dates/venue, update booth prices in the form, publish.
## Sponsorships and "Start next season" (v0.18.0)

- Conferences have a **Sponsorship form** field (Registration tab). Submissions are recorded as registrations of
  type `sponsorship` with `booths = 0`; the level ("Gold Sponsorship") is stored in the new `item` column, found by the
  product field whose label mentions "sponsor" (field 55 on most forms, 57 on form 38). Extra tickets and a requested
  complimentary booth go to `notes`. Same routing rule as registration forms (`Conferences::for_sponsorship_form()`).
  Map: `Form_Map::SPONSORSHIP` (total 56). Sponsorship forms also require a vendor login and are prefilled.
- Vendors see sponsorships on My Conferences with the level instead of booths, and without "Manage booth staff".
  `Registrations::summary()` and `Registrations::type_label()` give the wording everywhere.
- Invoice/receipt numbers match ZacPubs' Gravity Forms emails: `TCP-{form ID}-{entry ID}` (`Documents::number()`).
- Settings → Registration forms → **Start {year} season** creates a draft of every conference for the year after the
  newest published one, copied from each event's latest year (`Conferences::season_plan()` / `copy_to_year()`), never
  duplicating. "Copy for next year" uses the same copy.
## Conference series is back (v0.19.0)

- **Settings → Conference series** lists every series with its registration form, sponsorship form, annual-form
  dropdown and conferences by year, plus "Create series from forms", "Add series" and the Next season card.
- The **series** (`zp_conf_series`, `class-series.php`) owns the forms: `gf_forms`, `sponsorship_forms`,
  `annual_product_field`. Its edit screen lists its conferences and has "Add next year's conference".
- A **conference** picks its series (Event tab) and keeps only yearly details; its title is "Series Year" and renaming
  a series renames its conferences. The Registration box shows the series' forms and where registrations go.
- Routing: a form → its series → the series' newest published conference (draft fallback). If the series has no
  conference yet, the registration is still recorded under the series with `edition_id = 0`.
- "Create series from forms" (Setup) groups ticked forms by series name, links the annual dropdown, and can create a
  draft conference for the chosen year in each series that has none.
- Migration `zpvp_conference_model = v3` merged each conference's forms onto its series.

## Avery 5392 badges and sponsorship links (v0.20.0)

- Badge print sheet matches Avery 5392 exactly: letter page with no printer margin, 1in top/bottom and 0.25in side margins, 2 x 3 grid of 4in x 3in badges with no gaps. Staff can nudge the grid (right/down, inches) and print outlines for a plain-paper test; both are remembered per computer. Long names and companies shrink to fit.
- Sponsorship forms linked to their series per the client's list: 30 JPCA, 33 TACA, 38 CDCAT Fall, 46 CDCAT Winter, 47 CDCAT Annual (Summer). Form 31 (TACA Virtual) is not on the client's list and stays unlinked.


## Smarter form detection (v0.20.1)

- "Create series from forms" no longer pre-ticks test, historic, demo, badge, extra-representative, virtual, attendee or sponsorship forms (`Setup::is_extra_form()`).
- Annual booth dropdown matching: event words (winter, fall, summer, spring, legislative, west, south, north, east) must agree; short names such as TACERA or JPCA match a dropdown's initials; otherwise closest wording.


## Activation goes to the vendor login (v0.20.2)

- Activation links for the vendor registration form are handled by `Auth::activate_vendor()` (on `wp`, priority 5): the account is activated and the vendor is redirected to the vendor login page with "Your account is active" and their email filled in. Gravity Forms' own activation page (which links to wp-login.php) is no longer shown to vendors. Other forms' signups are untouched.

## Conference Builder (v0.21.0)

- **Vendor Portal → Conference Builder** (`admin.php?page=zpvp-builder&conference=ID`, `Builder` + `assets/js/builder.js`):
  one screen to set up a conference in six steps (Event, Forms, Field connectors, Registration, Vendor information,
  Review and publish). Also linked from each conference's row actions and edit screen ("Open in builder").
- **Flow strip**: Gravity Forms → Field connectors → Conference → Vendor portal → Badges · Invoices · Emails. Each node
  is green, amber or red from the same checks as the checklist; clicking it jumps to its step.
- **Live checks and preview**: every change is posted to `POST zpvp/v1/builder/{id}/check`, which evaluates the
  unsaved values with the plugin's own rules (status, routing to the newest published year, page detection, form on/off,
  capacity, deadlines) and returns the checklist, flow, "What happens" lines and a preview of the vendor's view
  (Open for registration card, exhibitor pass, conference information) in the same markup as My Conferences.
  Nothing is saved until Save draft / Publish. Publishing is blocked while any check needs attention.
- Forms, sponsorship forms and the annual dropdown are saved on the conference's **series** (they apply to every year).
- **Field connectors** (`Field_Maps`, option `zpvp_field_maps`): per form, which field holds company, email, payment
  method, total, booth personnel, badges and so on. The built-in template layouts in `Form_Map` apply automatically;
  only connectors that differ are stored, and they are merged through the `zpvp_form_map` filter (priority 5), so
  registration sync, prefill and documents use them. Missing required connectors are suggested from field types and
  labels. **Test with last entry** (`GET builder/form/{id}/test`, `Registration_Sync::preview_entry()`) shows what the
  latest entry would be recorded as, without saving anything.
- REST (all require `zpvp_manage`): `GET builder`, `POST builder/new`, `GET|POST builder/{id}`, `POST builder/{id}/check`,
  `GET|POST builder/form/{form}`, `GET builder/form/{form}/test`.

## Header menu link

`includes/class-menu.php` adds the vendor link to the end of the menu in the
`primary` theme location (the Company Menu on Magazine Pro). Nothing is saved
in the menu, so it works on any install with no setup.

| Visitor | Link |
|---|---|
| Logged out, or logged in without portal access | **Vendor Login** → `/vendor-login` |
| Vendors and staff (`zpvp_access_portal`) | **Vendor Portal** → `/my-conferences`, with a **Log out** sub-item |

Staff can rename both labels or hide the link in **Vendor Portal → Settings →
Menu link** (option `zpvp_menu`; blank labels fall back to the defaults above).
Developers can move it to another menu location with
`add_filter( 'zpvp_menu_location', fn() => 'secondary' );`.
