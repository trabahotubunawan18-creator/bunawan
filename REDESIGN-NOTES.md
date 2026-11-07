# Trabaho Tu Bunawan: UI/UX redesign notes

Scope: visual design, layout, responsiveness and accessibility only. No routes, queries, form field names, element IDs, validation, auth, permissions, or database code were changed.

## 1. Approach

The whole system is styled from one stylesheet (`assets/css/style.css`) and three dashboard layouts (seeker, employer, admin) plus one public layout. The redesign is therefore **CSS-first**: the stylesheet was rebuilt as a layered design system, and PHP was touched only in shared layout files, with small additive markup changes.

Every one of the 294 class names in the original stylesheet still exists in the new one (checked programmatically), so no page lost its styling hook. JS-driven states (`.js .wiz-step.current`, `.panel.show`, `.tab.active`, `.progress li.now/done`, `.app.open`, `.menu.open`, `[hidden]`) are preserved.

## 2. Visual improvements

- **Design tokens**: one set of colour, radius, spacing (4px scale), elevation and type variables. Existing variable names (`--forest`, `--green`, `--gold`, `--sage`, `--line`, ...) are kept, so inline `var(--...)` usages still work. Brand hues are unchanged.
- **Typography**: Inter for UI/body/tables (better for dense data), Poppins kept for the wordmark and headings (brand continuity). Consistent heading scale and tabular numerals on statistics.
- **App shell**: refined dark sidebar with grouped admin navigation, stronger active state, count badges, role context in the top bar ("Admin / Users", "Employer / Overview"), frosted sticky top bar.
- **Cards and stats**: one card style (1px border, soft shadow), consistent 24px panel padding, smaller icon tiles, compact stat cards.
- **Tables**: one table style for every module (uppercase muted headers, row hover, consistent cell padding, horizontal scroll inside the card on small screens instead of breaking the page).
- **Forms**: unified 44px controls, consistent labels, custom select chevron, visible green focus ring, dashed file inputs, segmented login/register tabs, connected wizard progress steps.
- **Buttons and feedback**: consistent primary / outline / gold / danger variants, disabled and pressed states; alerts with accent edge; status pills share one scale.
- **Public site**: calmer hero (removed the curved cut-out), glass chips, cleaner header with pill active state, refined job and category cards, deeper footer.
- **Empty states**: shipped the illustrations the markup already referenced.
- **Responsive**: breakpoints at 1100 / 1000 / 980 / 900 / 760 / 720 / 640 / 520 / 380. Sidebar becomes a drawer under 980px with every nav item preserved; stat cards go two-up on phones; page titles stay visible in the mobile top bar.
- **Accessibility**: green focus ring on light surfaces (gold on dark ones; the old gold-on-white ring had very low contrast), `sr-only` utility added (it was used but undefined), `aria-expanded`/`aria-controls` on the sidebar toggle, Escape closes the drawer, closed drawer is removed from tab order, reduced-motion respected.

## 3. Files modified

| File | Why |
|---|---|
| `assets/css/style.css` | Rebuilt as a layered design system (tokens, base, buttons, feedback, forms, surfaces/tables, public site, auth, inner pages, app shell, dashboard components, employer/admin, responsive, print). |
| `includes/header.php`, `dash_top.php`, `emp_top.php`, `admin_top.php`, `admin/login.php` | Font link now loads Inter + Poppins (same tag, new family list). |
| `includes/emp_top.php`, `includes/admin_top.php` | Role context label in the top bar. |
| `includes/admin_top.php` | Sidebar group labels (Moderation / Insights & content / System) and distinct icons for Reports, Messages, Activity log. Same links, same order, same destinations and counts. |
| `includes/dash_bottom.php` | Sidebar toggle: same click behaviour, plus `aria-expanded` sync and Escape-to-close. |
| `includes/helpers.php` | Three new icons added to `icon()` (`chart`, `clock`, `mail`). Existing icons untouched. |

New assets (all were already referenced by existing markup, which hid them via `onerror`): `favicon.ico`, `og-share.jpg`, `empty-no-jobs.svg`, `empty-no-applications.svg`, `empty-no-saved.svg`, `step-search.svg`, `step-verify.svg`, `badge-verified-business.svg`.

Line endings (CRLF) were preserved in every edited file.

## 4. Pages and modules reviewed

All 75 files in the archive were inventoried. Styling coverage was checked by cross-referencing every class used in every PHP file against the stylesheet.

- **Public**: index, jobs, job, employers, about, contact, login, register (wizard), logout
- **Seeker** (9): index, browse, saved, applications, profile, resume, notifications, settings, resume-download
- **Employer** (11): index, post-job, my-jobs, applicants, company-profile, verification, premium, notifications, settings, doc, resume-download
- **Admin** (17): login, index, verification, verify-review, flagged, jobs, job-review, users, employers, complaints, reports, categories, premium, messages, activity-log, settings, doc

## 5. Testing: what was and was not done

**Done**
- Class parity: 0 of 294 original class selectors dropped; stylesheet braces balanced.
- Diff review: only the files listed above differ from the original; every PHP/JS change was read line by line.
- Line endings preserved.
- Visual rendering in headless Chromium at 1440, 820 and 390 px wide, using static pages built from the real markup structure of: public home, login, employer wizard step, seeker applications (with empty state), employer dashboard, employer post-job form, admin dashboard, admin users table, admin employers table. Result: no horizontal overflow and no JS errors at any size (one overflow found on the seeker top bar at 390px and fixed).
- Mobile drawer behaviour: opens, `aria-expanded` updates, Escape closes, backdrop click closes, closed state is not focusable.

**Not done**
- **The PHP application was not run.** No PHP or MySQL runtime was available, so no login, session, CRUD, upload, export, search, pagination, or role-access flow was exercised, and `php -l` was not run. The PHP edits are small and mechanical, but please run the checklist below on your server before deploying.
- The real pages were not rendered; the fixtures reproduce their markup, not their data.
- Inter was not installed in the test environment, so previews used a wider fallback font. Real rendering should be slightly more compact.
- No cross-browser testing beyond Chromium; no screen-reader testing.

**Suggested smoke test**: log in as each role; open every sidebar item; submit one form per role; run the employer wizard through all steps; resize to phone width and open the drawer; print the admin Reports page; confirm that the font and favicon load.

## 6. Issues found (not changed, as they are outside a visual redesign)

1. **Nine PHP files are empty (0 bytes) and render blank pages**: `privacy.php`, `terms.php`, `safety.php`, `faq.php`, `forgot-password.php`, `reset-password.php`, `verify-email.php`, `404.php`, `employer-profile.php`. Several are linked from the UI: Terms and Privacy from the register and login pages (users are asked to agree to them), all four info pages from the footer, forgot-password from login, and `employer-profile.php` from the admin employers page. Their content needs to be written. Legal text should come from you, so none was invented.
2. `admin/employers.php` is not linked from the admin sidebar (reachable by URL only). It used unstyled Bootstrap-style classes; those are now styled to match. Adding it to the nav is a one-line change if intended.
3. `admin/login.php` references `logo-icon.svg`, but the project ships `logo-icon.jpg`, so the admin sign-in logo is hidden. Also still missing: `about-team.jpg`, `logo-white.svg` (photo and brand assets I did not want to invent).
4. About 150 inline `style="..."` attributes remain (mostly margin nudges, plus data-driven bar widths and heights, which must stay inline). They still work; converting the static ones to utility classes is a safe follow-up.
5. `hero-bunawan.jpg` and `login-side.jpg` are byte-identical files.
6. `prem-grid` (employer premium) is a class hook with no styles. Harmless.

## 7. Recommended follow-ups (need functional changes)

- Write the blank pages above; add a custom 404 and wire it with `ErrorDocument 404` or equivalent.
- Add loading/submitting states to long forms (needs small JS additions).
- Consider moving remaining inline styles into the stylesheet.
