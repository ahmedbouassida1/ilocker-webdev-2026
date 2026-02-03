---
applyTo: "**"
---

# iLocker WebDev Copilot Instructions

## Scope
- Project: iLocker WordPress site (Elementor + WooCommerce)
- Primary languages: PHP, HTML, CSS, JavaScript
- Always follow WordPress best practices and security standards.

## Security (Mandatory)
- Validate and sanitize all input (use sanitize_text_field, sanitize_email, absint, esc_url_raw, etc.).
- Escape all output (use esc_html, esc_attr, esc_url, wp_kses as appropriate).
- Use nonces for all state-changing requests and verify them.
- Prevent user enumeration in auth flows (use uniform responses and timing).
- Apply rate limiting for auth endpoints using transients.
- Prefer HTTPS for all reset/login URLs.
- Do not expose secrets; use constants via wp-config.php or environment.

## WordPress Conventions
- Prefer native WP APIs (retrieve_password, check_password_reset_key, wp_mail, wp_safe_redirect, wp_nonce_field).
- Use add_action/add_shortcode only; avoid direct output outside shortcodes or templates.
- Keep code compatible with current WP and PHP versions used on production.

## UI / Branding
- Use iLocker tokens and styling from i_locker_stylish_html_style_guide.html.
- Typography: Unbounded for headings, DM Sans for body.
- Primary color: #0000FC; Text: #00001A; Secondary text: #54547E.
- Keep layout minimal, accessible, and mobile-first.

## Email Branding
- Send branded HTML emails with iLocker colors and typography.
- Include safe fallback (direct link) and clear security messaging.

## Performance
- Avoid heavy queries on every request; cache with transients when possible.
- Do not enqueue scripts/styles globally unless needed.

## Testing Notes
- Features must work on https://www.dev.ilocker.com.tn/
- Validate shortcodes in Elementor pages.

## File Management
- Keep custom features in dedicated files and include via functions.php or a plugin.
- Use clear, consistent naming: ilocker_* for functions, shortcodes, and actions.

## Project Logging (Required)
- Maintain a running dev log in the repo root: progress-website-dev.md.
- Before making changes, skim the latest entries to avoid regressions/duplicate work.
- After making changes (code, config, CSS, JS), append a short entry including:
	- Date/time, what changed, why, files touched, and any verification steps.
	- Open issues / next steps if anything remains.
