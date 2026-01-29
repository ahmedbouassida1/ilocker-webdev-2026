/**
 * iLocker Lost Password System
 * Style: Identique Login/Register (Classes CSS unifiées)
 * Security: Cloudflare Turnstile & Nonces
 */

// 1. MOTEUR DE TRAITEMENT (Envoi de l'email de réinitialisation)
add_action('template_redirect', function() {
    
    // On écoute la soumission du formulaire "Lost Password"
    if (!isset($_POST['il_lost_submit'])) return;

    global $wp;
    $base_url = remove_query_arg(['il_lost_error', 'il_lost_success'], home_url(add_query_arg([], $wp->request)));

    // A. Sécurité Nonce
    if (!isset($_POST['il_lost_nonce']) || !wp_verify_nonce($_POST['il_lost_nonce'], 'il_lost_action')) {
        wp_safe_redirect(add_query_arg('il_lost_error', 'expired', $base_url)); exit;
    }

    // B. Cloudflare Turnstile
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

    // C. Traitement
    if ($is_human || empty($secret)) {
        
        $login = trim($_POST['il_user_login']);
        
        // Utilisation de la fonction native WordPress pour gérer la récupération
        $errors = new WP_Error();
        
        if ( empty( $login ) ) {
            $errors->add('empty_username', __('Entrez un identifiant ou un email.'));
        } elseif ( strpos( $login, '@' ) ) {
            $user_data = get_user_by( 'email', trim( $login ) );
            if ( empty( $user_data ) ) {
                $errors->add('invalid_email', __('Email introuvable.'));
            }
        } else {
            $user_data = get_user_by( 'login', trim( $login ) );
            if ( empty( $user_data ) ) {
                $errors->add('invalid_combo', __('Identifiant introuvable.'));
            }
        }

        if ( $errors->has_errors() ) {
            wp_safe_redirect(add_query_arg('il_lost_error', 'invalid', $base_url)); exit;
        }

        // Si l'utilisateur existe, on déclenche l'envoi de l'email standard WP
        // La fonction retrieve_password() fait tout le travail (génération clé + email)
        $user_login = $user_data->user_login;
        $result = retrieve_password($user_login);

        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('il_lost_error', 'generic', $base_url)); exit;
        } else {
            // SUCCÈS : On redirige avec un message de confirmation
            wp_safe_redirect(add_query_arg('il_lost_success', 'sent', $base_url)); exit;
        }

    } else {
        wp_safe_redirect(add_query_arg('il_lost_error', 'captcha', $base_url)); exit;
    }
});

// 2. SHORTCODE [ilocker_lost_password]
add_shortcode('ilocker_lost_password', function() {
    
    // Si déjà connecté, inutile de rester ici
    if (is_user_logged_in()) {
        return '<div class="il-alert">Vous êtes déjà connecté. <a href="'.wc_get_page_permalink('myaccount').'">Aller à mon compte</a></div>';
    }

    // Gestion des Messages
    $msg_text = '';
    $msg_type = ''; // 'error' ou 'success' (pour le style CSS vert/rouge)

    if (isset($_GET['il_lost_error'])) {
        $msg_type = 'error'; // Sera rouge via CSS .il-alert
        switch ($_GET['il_lost_error']) {
            case 'invalid': $msg_text = "Compte introuvable avec ces informations."; break;
            case 'captcha': $msg_text = "Sécurité échouée. Veuillez réessayer."; break;
            case 'expired': $msg_text = "Session expirée. Rechargez la page."; break;
            case 'generic': $msg_text = "Erreur lors de l'envoi. Contactez le support."; break;
        }
    }
    
    if (isset($_GET['il_lost_success'])) {
        $msg_type = 'success'; // On utilisera une classe inline pour le vert car votre CSS n'a pas .success
        $msg_text = "L'email de réinitialisation a été envoyé ! Vérifiez votre boîte de réception.";
    }

    ob_start(); ?>
    
    <div class="il-register-card" style="max-width: 450px;">
        <p style="text-align:center; margin-bottom:24px; color:var(--text-secondary); font-size:14px;">
            Entrez votre email ou identifiant pour recevoir un lien de création de nouveau mot de passe.
        </p>

        <?php if ($msg_text): ?>
            <div class="il-alert" style="<?php echo ($msg_type === 'success') ? 'background:#e6fffa; color:#2ecc71; border-color:#b2f5ea;' : ''; ?>">
                <?php echo ($msg_type === 'success') ? '✅' : '⚠️'; ?> <?php echo esc_html($msg_text); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            
            <div class="il-input-group">
                <label>Identifiant ou Email</label>
                <input type="text" name="il_user_login" required placeholder="votre@email.com">
            </div>

            <div class="cf-turnstile" style="margin: 20px 0; min-height:65px;"></div>
            
            <?php wp_nonce_field('il_lost_action', 'il_lost_nonce'); ?>
            
            <button type="submit" name="il_lost_submit" class="il-btn-primary">ENVOYER LE LIEN</button>
            
            <div style="text-align:center; margin-top:20px; font-size:14px;">
                <a href="<?php echo wc_get_page_permalink('myaccount'); ?>" style="color:var(--ilocker-500); text-decoration:none; font-weight:600;">
                    ← Retour à la connexion
                </a>
            </div>
        </form>
    </div>
    
    <?php return ob_get_clean();
});

// 3. CHARGEMENT SCRIPT CLOUDFLARE (Sécurité)
// On s'assure que le script est chargé même si on est sur la page "Mot de passe oublié" seule.
add_action('wp_footer', function() {
    $key = defined('ILOCKER_TURNSTILE_SITE') ? ILOCKER_TURNSTILE_SITE : '';
    if (!$key) return;
    ?>
    <script>
    if (typeof turnstile === 'undefined') {
        var script = document.createElement('script');
        script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        script.async = true; script.defer = true;
        document.body.appendChild(script);
    }
    document.addEventListener("DOMContentLoaded", function() {
        function renderLostWidget() {
            var widgets = document.querySelectorAll('.cf-turnstile');
            widgets.forEach(function(w) {
                if (!w.dataset.rendered && typeof turnstile !== 'undefined') {
                    turnstile.render(w, { sitekey: '<?php echo esc_js($key); ?>', theme: 'light' });
                    w.dataset.rendered = "true";
                }
            });
        }
        renderLostWidget();
        setTimeout(renderLostWidget, 1000);
    });
    </script>
    <?php
}, 99);