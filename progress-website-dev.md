# progress-website-dev.md

Project: iLocker Dev website (WordPress + Elementor + WooCommerce)

## How to use this log
- Append newest entries at the top.
- Keep entries short and actionable: what changed, why, files touched, and how to verify.

---

## 2026-02-03 — OTP UI: secondary buttons outlined
- Improved OTP step button hierarchy: kept “VÉRIFIER” as the primary filled CTA, changed “RENVOYER” and “ANNULER” to outlined styles (cancel uses a neutral outline).
- Files: custom code ilocker/ilocker custom code v3/ilocker-otp.php, custom code ilocker/ilocker custom code v3/ilocker-css-v3.css, progress-website-dev.md
- Verification: open `/user-account/?il_auth=otp&il_otp=...` and confirm the two secondary buttons are outlined and clearly distinct from the primary.

## 2026-02-02 — Email OTP login (5 digits)
- Added mandatory email OTP (5 digits) for every login: password is validated first, then OTP is emailed; user enters OTP to complete login and land on `/user-account/`.
- OTP is stored hashed in a transient challenge with expiry (10 min), max attempts (5), and resend/send rate limiting (5 per 15 min per IP/user).
- Unverified-email users remain blocked by the existing verification filter (OTP is not issued until email is verified).
- Files: custom code ilocker/ilocker custom code v3/ilocker-otp.php, custom code ilocker/ilocker custom code v3/login form.php, custom code ilocker/ilocker custom code v3/ilocker-user-account.php, custom code ilocker/ilocker custom code v3/ilocker-auth-v3.js, custom code ilocker/ilocker custom code v3/alerts.php, custom code ilocker/functions-astra.php, progress-website-dev.md
- Verification: login with correct password → confirm redirect to OTP step and email received; enter correct OTP → logged in; wrong OTP 5x → forced restart; resend OTP >5 times/15min → rate limited; unverified account → shows verification resend flow, no OTP.

## 2026-02-02 — Use functions-astra.php as loader
- Switched the canonical iLocker V3 loader to `custom code ilocker/functions-astra.php` (instead of the root `functions.php`) and ensured `ilocker-email-verification.php` is included.
- Files: custom code ilocker/functions-astra.php, progress-website-dev.md
- Verification: hit `/user-account` (logged out and logged in) and confirm verification alerts/resend flow still works; verify email link continues to redirect back to `/user-account`.

## 2026-02-02 — Email verification for new customers
- Added an email verification system: new customer registrations receive a branded verification email; logins are blocked until verification; verification link activates the account and redirects to `/user-account`.
- Added resend verification flow (rate-limited) surfaced when a user tries to login with an unverified account.
- Updated login/register redirects to prefer `/user-account` (wrapper page) when available.
- Files: functions.php, custom code ilocker/ilocker custom code v3/ilocker-email-verification.php, custom code ilocker/ilocker custom code v3/register form.php, custom code ilocker/ilocker custom code v3/login form.php, custom code ilocker/ilocker custom code v3/alerts.php, progress-website-dev.md
- Verification: register a new account → confirm you’re redirected back to `/user-account` with a “verification sent” alert; click the email link → confirm “verified” alert and ability to login; attempt login before verification → confirm blocked with resend form.

## 2026-02-02 — Auth tabs + AJAX switch (login/register)
- Styled the auth navigation as pill tabs (Connexion / Inscription) and switched auth flows in-page using AJAX + History API, matching the dashboard’s SPA feel.
- Moved inline JS from login/register forms into a single enqueued script so AJAX-loaded forms keep working (password eye toggle, pro toggle, strength meter).
- Added “Mot de passe oublié ?” as a link inside the login form (instead of a top nav item).
- Files: custom code ilocker/ilocker custom code v3/ilocker-user-account.php, custom code ilocker/ilocker custom code v3/ilocker-auth-v3.js, custom code ilocker/ilocker custom code v3/login form.php, custom code ilocker/ilocker custom code v3/register form.php, custom code ilocker/ilocker custom code v3/ilocker-css-v3.css
- Verification: open `/user-account` while logged out; click Connexion/Inscription without full reload, use back/forward, click “Mot de passe oublié ?”, and confirm Turnstile + password toggles still work after switching.

## 2026-02-02 — Fix header shortcode [ilk-account]
- Fixed `[ilk-account]` printing literally by making the module a valid PHP file (added `<?php` + `ABSPATH` guard) and ensuring it is required from the theme bootstrap.
- Added a consolidated iLocker stylesheet and enqueued it from theme code (no WPCode snippets) so header/account UI styles are versioned and portable.
- Files: custom code ilocker/ilocker custom code v3/custom account icon.php, functions.php, progress-website-dev.md
- Verification: place `[ilk-account]` in the header (Elementor Shortcode widget) and confirm it renders (icon + optional “Hi, …”) and links to `/user-account`.

## 2026-02-02 — Move shortcode code into /ilocker custom code v3
- Moved the required shortcode files into `custom code ilocker/ilocker custom code v3/` (alerts, login/register, password reset, wrapper shortcode, and UI V3 PHP/CSS/JS) to keep V3 code isolated.
- Updated includes to load from the new folder and fixed UI V3 asset base URL/path so CSS/JS still enqueue correctly after the move.
- Added a fallback so includes/assets still work if the site runs a child theme but you upload the folder into Astra (parent theme).
- Guarded `ilocker_pr_get_ip()` with `function_exists()` to avoid fatal redeclare when an old WPCode snippet is still active.
- Updated `[ilk-account]` (custom account icon) to link to the `/user-account` page instead of WooCommerce My Account.
- Files: function.php, functions.php, custom code ilocker/ilocker custom code v3/user-interface-v3/il-user-interface.php, progress-website-dev.md
- Verification: load the Elementor page using `[ilocker_user_account]` and confirm the V3 dashboard styles/scripts load + AJAX tab navigation still works.

## 2026-02-01 — Bootstrap shortcodes via functions.php + /user-account wrapper
- Added a proper theme bootstrap `functions.php` (WP-required filename) to load the existing Astra base from `function.php` and then include all iLocker shortcode files in a safe order (alerts → login/register → password reset → UI V3).
- Added `[ilocker_user_account]` wrapper shortcode to use on the Elementor `/user-account` page: shows login/register/forgot flows when logged out, and loads the V3 dashboard when logged in.
- Updated UI V3 enqueue detection so CSS/JS also load when the page uses `[ilocker_user_account]` (including Elementor `_elementor_data` storage).
- Files: functions.php, custom code ilocker/ilocker-user-account.php, custom code ilocker/user-interface-v3/il-user-interface.php, progress-website-dev.md
- Verification: on `/user-account`, add `[ilocker_user_account]`; logged-out shows auth forms, logged-in shows V3 dashboard with working CSS/JS and AJAX navigation.

## 2026-01-31 — Table responsiveness hardening (Account UI V3)
- Wrapped dashboard tables with a dedicated horizontal scroll container and removed parent overflow clipping that could block scroll on mobile.
- Added `.il-table-scroll` CSS to standardize overflow behavior and scope small-screen table min-width forcing to only tables inside that wrapper.
- Follow-up: forced tables to size-to-content on mobile (override inline `width:100%`) and prevented cell wrapping so real horizontal overflow/scroll appears reliably.
- Elementor follow-up: enabled cache-busting via `filemtime()` for CSS/JS and added Elementor-targeted scroll hardening for nested horizontal scrolling.
- Files: custom code ilocker/user-interface-v3/il-user-interface.php, custom code ilocker/user-interface-v3/il-user-interface.css
- Verification: test Orders/Downloads/Subscriptions tables on mobile widths; confirm horizontal swipe scroll works.

## 2026-01-31 — Use Media Library SVGs in sidebar navigation
- Added a cached lookup for SVG attachments by filename and render nav icons as `<img>` when available.
- Kept existing inline SVGs as fallbacks so the UI stays functional if a file is missing.
- Updated mapping so each view looks for a canonical `<view>.svg` first (e.g. `downloads.svg`, `orders.svg`, `addresses.svg`, `subscriptions.svg`).
- Files: custom code ilocker/user-interface-v3/il-user-interface.php, custom code ilocker/user-interface-v3/il-user-interface.css
- Verification: open the shortcode page; confirm nav shows the uploaded SVG icons (orders/downloads/adresse/account/etc.).

## 2026-01-31 — Add project logging requirement
- Added/updated repo-local Copilot instructions to require documenting work in this file.
- Files: .github/copilot-instructions.md, progress-website-dev.md
- Verification: N/A (documentation only)

## Current focus / status
- Account UI V3: SPA-like My Account via shortcode; tab navigation + history working.
- Remaining UX work: mobile/tablet table responsiveness and general polishing.
