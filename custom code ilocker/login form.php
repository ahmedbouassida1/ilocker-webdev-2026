/**
 * iLocker Login System (Unified Design)
 * Fix: Integration parfaite de l'icône "Oeil" (SVG) comme sur le registre.
 * Fix: Bouton utilise le style du thème.
 */

// 1. MOTEUR DE TRAITEMENT
add_action('template_redirect', function() {
    if (!isset($_POST['il_login_submit'])) return;

    global $wp;
    $base_url = remove_query_arg(['il_login_error', 'il_login_success'], home_url(add_query_arg([], $wp->request)));

    // Sécurité Nonce
    if (!isset($_POST['il_login_nonce']) || !wp_verify_nonce($_POST['il_login_nonce'], 'il_login_action')) {
        wp_safe_redirect(add_query_arg('il_login_error', 'expired', $base_url)); exit;
    }

    // Turnstile
    $token = $_POST['cf-turnstile-response'] ?? '';
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
        $creds = array(
            'user_login'    => trim($_POST['il_username']),
            'user_password' => $_POST['il_password'],
            'remember'      => true
        );
        $user = wp_signon($creds, is_ssl());

        if (is_wp_error($user)) {
            wp_safe_redirect(add_query_arg('il_login_error', 'invalid', $base_url)); exit;
        } else {
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID, true);
            wp_safe_redirect(wc_get_page_permalink('myaccount')); exit;
        }
    } else {
        wp_safe_redirect(add_query_arg('il_login_error', 'captcha', $base_url)); exit;
    }
});

// 2. SHORTCODE [ilocker_login]
add_shortcode('ilocker_login', function() {
    if (is_user_logged_in()) return '<script>window.location.href="'.wc_get_page_permalink('myaccount').'";</script>';

    $msg = '';
    if (isset($_GET['il_login_error'])) {
        switch ($_GET['il_login_error']) {
            case 'invalid': $msg = "Identifiant ou mot de passe incorrect."; break;
            case 'captcha': $msg = "Erreur de sécurité."; break;
            case 'expired': $msg = "Session expirée."; break;
            default: $msg = "Erreur de connexion.";
        }
    }

    // --- DÉFINITION DES ICÔNES SVG (Identiques au registre) ---
    // Eye Open
    $svg_show = '<svg class="il-eye-icon il-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px; height:20px; color:#666;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
    
    // Eye Closed
    $svg_hide = '<svg class="il-eye-icon il-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px; height:20px; color:#666; display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

    ob_start(); ?>
    
    <div class="il-register-card il-login-mode">
        
        <?php if ($msg): ?>
            <div class="il-alert" style="margin-bottom:20px; color:red; text-align:center;">⚠️ <?php echo esc_html($msg); ?></div>
        <?php endif; ?>

        <form method="post" action="">
            
            <div class="il-input-group">
                <label>Identifiant ou Email</label>
                <input type="text" name="il_username" required placeholder="votre@email.com" value="<?php echo isset($_POST['il_username']) ? esc_attr($_POST['il_username']) : ''; ?>">
            </div>
            
            <div class="il-input-group">
                <label>Mot de passe</label>
                <div class="il-password-wrapper" style="position:relative;">
                    <input type="password" name="il_password" id="il-login-pass" required placeholder="••••••••" style="padding-right:45px !important;">
                    
                    <span class="il-pass-toggle" onclick="toggleLoginPass('il-login-pass', this)" style="position:absolute; right:15px; top:50%; transform:translateY(-50%); cursor:pointer; z-index:10;">
                        <?php echo $svg_show . $svg_hide; ?>
                    </span>
                </div>
            </div>

            <div class="cf-turnstile" style="margin: 20px 0;"></div>
            
            <?php wp_nonce_field('il_login_action', 'il_login_nonce'); ?>
            
            <button type="submit" name="il_login_submit" class="il-btn-primary button">SE CONNECTER</button>
            
        </form>
    </div>

    <script>
        function toggleLoginPass(fieldId, btn) {
            var input = document.getElementById(fieldId);
            var iconShow = btn.querySelector('.il-show');
            var iconHide = btn.querySelector('.il-hide');
            
            if (input.type === "password") {
                input.type = "text";
                iconShow.style.display = 'none';
                iconHide.style.display = 'block';
            } else {
                input.type = "password";
                iconShow.style.display = 'block';
                iconHide.style.display = 'none';
            }
        }
    </script>
    <?php return ob_get_clean();
});

// 3. CHARGEMENT SCRIPT CLOUDFLARE
add_action('wp_footer', function() {
    $key = defined('ILOCKER_TURNSTILE_SITE') ? ILOCKER_TURNSTILE_SITE : '';
    if (!$key) return;
    ?>
    <script>
    if (typeof turnstile === 'undefined') {
        var script = document.createElement('script');
        script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        script.async = true; script.defer = true; document.body.appendChild(script);
    }
    document.addEventListener("DOMContentLoaded", function() {
        function renderLoginWidget() {
            var widgets = document.querySelectorAll('.cf-turnstile');
            widgets.forEach(function(widget) {
                if (!widget.dataset.rendered && typeof turnstile !== 'undefined') {
                    turnstile.render(widget, { sitekey: '<?php echo esc_js($key); ?>', theme: 'light' });
                    widget.dataset.rendered = "true";
                }
            });
        }
        renderLoginWidget(); setTimeout(renderLoginWidget, 1000);
    });
    </script>
    <?php
}, 99);