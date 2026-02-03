<?php
/**
 * iLocker Login System (Unified Design)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. MOTEUR DE TRAITEMENT
add_action('template_redirect', function() {
    if (!isset($_POST['il_login_submit'])) return;

    global $wp;
    $base_url = remove_query_arg(['il_login_error', 'il_login_success', 'il_otp_error', 'il_otp_success'], home_url(add_query_arg([], $wp->request)));

    if (!isset($_POST['il_login_nonce']) || !wp_verify_nonce($_POST['il_login_nonce'], 'il_login_action')) {
        wp_safe_redirect(add_query_arg('il_login_error', 'expired', $base_url)); exit;
    }

    $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field(wp_unslash($_POST['cf-turnstile-response'])) : '';
    $secret = defined('ILOCKER_TURNSTILE_SECRET') ? ILOCKER_TURNSTILE_SECRET : '';
    $is_human = false;

    if ($secret && $token) {
        $req = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'body' => ['secret' => $secret, 'response' => $token, 'remoteip' => $_SERVER['REMOTE_ADDR']]
        ]);
        $res = json_decode(wp_remote_retrieve_body($req));
        if ($res && $res->success) $is_human = true;
    }

    if ($is_human || empty($secret)) {
        $login    = isset($_POST['il_username']) ? sanitize_text_field(wp_unslash($_POST['il_username'])) : '';
        $password = isset($_POST['il_password']) ? (string) wp_unslash($_POST['il_password']) : '';

        // Validate credentials WITHOUT logging in yet. We only set auth cookies after OTP verification.
        $user = wp_authenticate($login, $password);

        if (is_wp_error($user)) {
            $codes = $user->get_error_codes();
            if (in_array('ilocker_email_unverified', $codes, true)) {
                wp_safe_redirect(add_query_arg('il_login_error', 'unverified', $base_url)); exit;
            }
            wp_safe_redirect(add_query_arg('il_login_error', 'invalid', $base_url)); exit;
        }

        if (!($user instanceof WP_User)) {
            wp_safe_redirect(add_query_arg('il_login_error', 'invalid', $base_url)); exit;
        }

        // Create OTP challenge and send OTP email (mandatory on every login).
        if (!function_exists('ilocker_otp_create_challenge')) {
            // Fallback: if module isn't loaded for any reason, login normally.
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID, true);
            $redirect_to = function_exists('ilocker_ev_user_account_url') ? ilocker_ev_user_account_url() : home_url('/user-account/');
            wp_safe_redirect($redirect_to); exit;
        }

        $challenge_id = ilocker_otp_create_challenge($user);
        if (is_wp_error($challenge_id)) {
            $code = sanitize_key($challenge_id->get_error_code());
            if ($code === 'mail_failed') {
                $code = 'mail';
            }
            $login_url = function_exists('ilocker_otp_login_url') ? ilocker_otp_login_url(array('il_otp_error' => $code)) : add_query_arg('il_otp_error', $code, $base_url);
            wp_safe_redirect($login_url); exit;
        }

        $otp_url = function_exists('ilocker_otp_view_url') ? ilocker_otp_view_url($challenge_id, array('il_otp_success' => 'sent')) : add_query_arg(array('il_auth' => 'otp', 'il_otp' => $challenge_id, 'il_otp_success' => 'sent'), (function_exists('ilocker_ev_user_account_url') ? ilocker_ev_user_account_url() : home_url('/user-account/')));
        wp_safe_redirect($otp_url);
        exit;
    } else {
        wp_safe_redirect(add_query_arg('il_login_error', 'captcha', $base_url)); exit;
    }
});

// 2. SHORTCODE [ilocker_login]
add_shortcode('ilocker_login', function() {
    // Note: Ensure functions from alerts.php are loaded globally via another snippet
    if (is_user_logged_in()) {
        $url = function_exists('ilocker_ev_user_account_url') ? ilocker_ev_user_account_url() : wc_get_page_permalink('myaccount');
        return '<script>window.location.href="'.esc_url($url).'";</script>';
    }

    $base_url = ! empty( $GLOBALS['ilocker_user_account_base_url'] ) ? $GLOBALS['ilocker_user_account_base_url'] : '';
    if ( ! $base_url && ! empty( $_SERVER['REQUEST_URI'] ) ) {
        $base_url = home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) );
    }
    $forgot_url = $base_url ? add_query_arg( 'il_auth', 'forgot', remove_query_arg( 'il_auth', $base_url ) ) : '';

    $svg_show = '<svg class="il-eye-icon il-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px; height:20px; color:#666;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
    $svg_hide = '<svg class="il-eye-icon il-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px; height:20px; color:#666; display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

    ob_start(); ?>
    
    <div class="il-register-card il-login-mode">
        <?php echo ilocker_check_url_alerts(); ?>

        <form method="post" action="">
            <div class="il-input-group">
                <label>Identifiant ou Email</label>
                <input type="text" name="il_username" required placeholder="votre@email.com" value="<?php echo isset($_POST['il_username']) ? esc_attr($_POST['il_username']) : ''; ?>">
            </div>
            
            <div class="il-input-group">
                <label>Mot de passe</label>
                <div class="il-password-wrapper">
                    <input type="password" name="il_password" id="il-login-pass" required placeholder="••••••••" style="padding-right:45px !important;">
                    <span class="il-pass-toggle" onclick="toggleLoginPass('il-login-pass', this)">
                        <?php echo $svg_show . $svg_hide; ?>
                    </span>
                </div>
            </div>

            <?php if ( $forgot_url ) : ?>
                <div class="il-auth-meta">
                    <a class="il-auth-forgot-link" href="<?php echo esc_url( $forgot_url ); ?>">Mot de passe oublié ?</a>
                </div>
            <?php endif; ?>

            <?php
            $show_resend = ( isset($_GET['il_login_error']) && sanitize_key(wp_unslash($_GET['il_login_error'])) === 'unverified' );
            if ( $show_resend ) :
                $prefill = '';
            ?>
                <div style="margin: 14px 0 0; padding: 14px; border: 1px solid #E6E6EC; border-radius: 10px; background: #F5F5FA;">
                    <div style="font-weight:700; color:#00001A; margin-bottom:6px;">Email non vérifié</div>
                    <div style="color:#54547E; font-size:14px; line-height:1.5; margin-bottom:12px;">
                        Entrez votre email pour recevoir un nouveau lien de vérification.
                    </div>
                    <form method="post" action="">
                        <div class="il-input-group" style="margin-bottom: 12px;">
                            <label>Email</label>
                            <input type="email" name="il_ev_login" required placeholder="votre@email.com" value="<?php echo esc_attr($prefill); ?>">
                        </div>
                        <?php wp_nonce_field('il_ev_resend_action', 'il_ev_resend_nonce'); ?>
                        <button type="submit" name="il_ev_resend_submit" class="button alt ast-button" style="width:100%; background:#0000FC;">RENVOYER L’EMAIL</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="cf-turnstile" style="margin: 20px 0;"></div>
            <?php wp_nonce_field('il_login_action', 'il_login_nonce'); ?>
            
            <button type="submit" name="il_login_submit" class="button alt ast-button il-full-width">SE CONNECTER</button>
        </form>
    </div>
    <?php return ob_get_clean();
});

// 3. CHARGEMENT SCRIPT CLOUDFLARE
add_action('wp_footer', function() {
    $key = defined('ILOCKER_TURNSTILE_SITE') ? ILOCKER_TURNSTILE_SITE : '';
    if (!$key) return;
    ?>
    <script>
    (function(){
        if (typeof window.ilockerRenderTurnstileWidgets !== 'function') {
            window.ilockerRenderTurnstileWidgets = function(){
                if (typeof turnstile === 'undefined') return;
                var widgets = document.querySelectorAll('.cf-turnstile');
                widgets.forEach(function(widget) {
                    if (!widget.dataset.rendered) {
                        turnstile.render(widget, { sitekey: '<?php echo esc_js($key); ?>', theme: 'light' });
                        widget.dataset.rendered = "true";
                    }
                });
            };
        }

        if (typeof turnstile === 'undefined' && !window.__ilockerTurnstileScriptLoading) {
            window.__ilockerTurnstileScriptLoading = true;
            var script = document.createElement('script');
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
            script.defer = true;
            script.onload = function(){
                try { window.ilockerRenderTurnstileWidgets(); } catch(e) {}
            };
            document.body.appendChild(script);
        }

        document.addEventListener("DOMContentLoaded", function() {
            try { window.ilockerRenderTurnstileWidgets(); } catch(e) {}
            setTimeout(function(){ try { window.ilockerRenderTurnstileWidgets(); } catch(e) {} }, 1000);
        });

        // Also attempt immediately for fast loads.
        setTimeout(function(){ try { window.ilockerRenderTurnstileWidgets(); } catch(e) {} }, 0);
    })();
    </script>
    <?php
}, 99);
