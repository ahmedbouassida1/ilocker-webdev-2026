<?php
/**
 * iLocker Alerts System
 * Centralized handling for user feedback messages (Login, Register, Reset)
 */

if (!function_exists('ilocker_get_alert_html')) {
    /**
     * Generate HTML for an alert box.
     */
    function ilocker_get_alert_html($message, $type = 'error') {
        if (empty($message)) return '';

        $class = 'il-alert-error';
        $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>'; // Error X

        if ($type === 'success') {
            $class = 'il-alert-success';
            $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>'; // Success Check
        } elseif ($type === 'info') {
            $class = 'il-alert-info';
            $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>'; // Info i
        }

        // Returns styled HTML used by CSS
        return sprintf(
            '<div class="il-alert %s" role="alert"><span class="il-alert-icon">%s</span> <span class="il-alert-content">%s</span></div>',
            esc_attr($class),
            $icon_svg,
            esc_html($message)
        );
    }
}

if (!function_exists('ilocker_check_url_alerts')) {
    /**
     * Check URL parameters and return corresponding alert HTML.
     */
    function ilocker_check_url_alerts() {
        // 0. EMAIL VERIFICATION (success / error)
        if (isset($_GET['il_ev_success'])) {
            $v = sanitize_key(wp_unslash($_GET['il_ev_success']));
            switch ($v) {
                case 'sent':
                    return ilocker_get_alert_html('Un email de vérification vient de vous être envoyé. Veuillez vérifier votre boîte de réception.', 'success');
                case 'verified':
                    return ilocker_get_alert_html('Votre adresse email a été vérifiée. Vous pouvez maintenant vous connecter.', 'success');
            }
        }

        if (isset($_GET['il_ev_error'])) {
            $v = sanitize_key(wp_unslash($_GET['il_ev_error']));
            switch ($v) {
                case 'expired':
                    return ilocker_get_alert_html('Lien expiré. Veuillez demander un nouveau lien de vérification.', 'error');
                case 'rate':
                    return ilocker_get_alert_html('Trop de tentatives. Veuillez réessayer plus tard.', 'error');
                case 'mail':
                    return ilocker_get_alert_html('Votre compte a été créé, mais l’email de vérification n’a pas pu être envoyé. Merci de contacter le support.', 'error');
                case 'invalid':
                default:
                    return ilocker_get_alert_html('Lien de vérification invalide.', 'error');
            }
        }

        // 0b. LOGIN OTP (success / error)
        if (isset($_GET['il_otp_success'])) {
            $v = sanitize_key(wp_unslash($_GET['il_otp_success']));
            switch ($v) {
                case 'sent':
                    return ilocker_get_alert_html('Code envoyé. Veuillez vérifier votre boîte email et saisir le code pour finaliser la connexion.', 'success');
                case 'verified':
                    return ilocker_get_alert_html('Code vérifié. Connexion en cours…', 'success');
            }
        }

        if (isset($_GET['il_otp_error'])) {
            $v = sanitize_key(wp_unslash($_GET['il_otp_error']));
            switch ($v) {
                case 'invalid':
                    return ilocker_get_alert_html('Code incorrect. Veuillez réessayer.', 'error');
                case 'expired':
                    return ilocker_get_alert_html('Code expiré. Veuillez vous reconnecter pour recevoir un nouveau code.', 'error');
                case 'attempts':
                    return ilocker_get_alert_html('Trop de tentatives. Veuillez vous reconnecter pour recevoir un nouveau code.', 'error');
                case 'rate':
                    return ilocker_get_alert_html('Trop de demandes. Veuillez réessayer plus tard.', 'error');
                case 'headers':
                    return ilocker_get_alert_html('Impossible de finaliser la connexion (en-têtes déjà envoyés). Merci de contacter le support.', 'error');
                case 'mail':
                default:
                    return ilocker_get_alert_html('Impossible d\'envoyer le code. Veuillez réessayer.', 'error');
            }
        }

        // 1. LOGIN ERRORS
        if (isset($_GET['il_login_error'])) {
            $v = sanitize_key(wp_unslash($_GET['il_login_error']));
            switch ($v) {
                case 'invalid': return ilocker_get_alert_html('Identifiant ou mot de passe incorrect.', 'error');
                case 'captcha': return ilocker_get_alert_html('Erreur de sécurité (Captcha). Veuillez réessayer.', 'error');
                case 'expired': return ilocker_get_alert_html('Session expirée. Veuillez recharger la page.', 'error');
                case 'unverified': return ilocker_get_alert_html('Votre email n\'est pas encore vérifié. Veuillez vérifier votre boîte email.', 'info');
                default:        return ilocker_get_alert_html('Erreur de connexion.', 'error');
            }
        }

        // 2. REGISTRATION ERRORS
        if (isset($_GET['il_reg_error'])) {
            $v = sanitize_key(wp_unslash($_GET['il_reg_error']));
            switch ($v) {
                case 'mismatch': return ilocker_get_alert_html('Les mots de passe ne correspondent pas.', 'error');
                case 'exists':   return ilocker_get_alert_html('Cet email est déjà utilisé.', 'error');
                case 'captcha':  return ilocker_get_alert_html('Erreur de vérification humaine.', 'error');
                case 'expired':  return ilocker_get_alert_html('Formulaire expiré. Veuillez réessayer.', 'error');
                case 'generic':  
                default:         return ilocker_get_alert_html('Une erreur est survenue lors de l\'inscription.', 'error');
            }
        }

        // 3. GENERIC / FALLBACKS
        if (isset($_GET['login']) && $_GET['login'] === 'failed') {
             return ilocker_get_alert_html('Échec de la connexion.', 'error');
        }

        return '';
    }
}
