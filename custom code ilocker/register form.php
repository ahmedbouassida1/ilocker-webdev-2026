/**
 * iLocker Registration System (Flaticon Style Icons)
 * Features: Password Strength Meter, Country Select, SVG Eye Toggle.
 */

// 1. MOTEUR DE TRAITEMENT
add_action('template_redirect', function() {
    if (!isset($_POST['il_reg_submit'])) return;

    global $wp;
    $base_url = remove_query_arg(['il_reg_error', 'il_reg_success'], home_url(add_query_arg([], $wp->request)));

    if (!isset($_POST['il_reg_nonce']) || !wp_verify_nonce($_POST['il_reg_nonce'], 'il_reg_action')) {
        wp_safe_redirect(add_query_arg('il_reg_error', 'expired', $base_url)); exit;
    }

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
        
        if ($_POST['il_password'] !== $_POST['il_password_confirm']) {
            wp_safe_redirect(add_query_arg('il_reg_error', 'mismatch', $base_url)); exit;
        }

        $email = sanitize_email($_POST['il_email']);
        $password = $_POST['il_password'];
        $ui_role = sanitize_text_field($_POST['il_role']);
        
        if (email_exists($email) || username_exists($email)) {
            wp_safe_redirect(add_query_arg('il_reg_error', 'exists', $base_url)); exit;
        }

        $user_id = wp_create_user($email, $password, $email);

        if (is_wp_error($user_id)) {
            wp_safe_redirect(add_query_arg('il_reg_error', 'generic', $base_url)); exit;
        }

        $user = new WP_User($user_id);
        $user->set_role('customer'); 
        
        update_user_meta($user_id, 'first_name', sanitize_text_field($_POST['il_firstname']));
        update_user_meta($user_id, 'last_name', sanitize_text_field($_POST['il_lastname']));
        update_user_meta($user_id, 'billing_phone', sanitize_text_field($_POST['il_phone']));
        update_user_meta($user_id, 'billing_address_1', sanitize_text_field($_POST['il_address']));
        update_user_meta($user_id, 'billing_city', sanitize_text_field($_POST['il_city']));
        update_user_meta($user_id, 'billing_country', sanitize_text_field($_POST['il_country']));
        
        if ($ui_role === 'professional') {
            update_user_meta($user_id, 'billing_company', sanitize_text_field($_POST['il_company']));
            update_user_meta($user_id, 'billing_matricule_fiscale', sanitize_text_field($_POST['il_fiscal_id']));
            update_user_meta($user_id, 'ilocker_account_type', 'professional');
        } else {
            update_user_meta($user_id, 'ilocker_account_type', 'particular');
        }

        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        wp_safe_redirect(wc_get_page_permalink('myaccount'));
        exit;

    } else {
        wp_safe_redirect(add_query_arg('il_reg_error', 'captcha', $base_url)); exit;
    }
});

// 2. SHORTCODE
add_shortcode('ilocker_register', function() {
    if (is_user_logged_in()) return '<script>window.location.href="'.wc_get_page_permalink('myaccount').'";</script>';

    $msg = '';
    if (isset($_GET['il_reg_error'])) {
        switch ($_GET['il_reg_error']) {
            case 'mismatch': $msg = "Les mots de passe ne correspondent pas."; break;
            case 'exists': $msg = "Cet email est déjà utilisé."; break;
            case 'captcha': $msg = "Erreur de sécurité."; break;
            default: $msg = "Une erreur est survenue.";
        }
    }

    $countries = class_exists('WC_Countries') ? (new WC_Countries)->get_countries() : ['TN' => 'Tunisie', 'FR' => 'France'];
    $icon_user = '/wp-content/uploads/2026/01/user.png';
    $icon_pro  = '/wp-content/uploads/2026/01/professional.png';

    // SVG ICONS (Flaticon Style)
    $svg_show = '<svg class="il-eye-icon il-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
    $svg_hide = '<svg class="il-eye-icon il-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

    ob_start(); ?>
    
    <div class="il-register-card">
        <?php if ($msg): ?>
            <div class="il-alert">⚠️ <?php echo esc_html($msg); ?></div>
        <?php endif; ?>

        <form method="post" action="">
            
            <div class="il-type-selector">
                <label class="il-type-option">
                    <input type="radio" name="il_role" value="customer" checked onchange="togglePro(false)">
                    <div class="il-type-box">
                        <img src="<?php echo $icon_user; ?>" class="il-icon-img" alt="Particulier">
                        <span class="il-label">Particulier</span>
                    </div>
                </label>
                <label class="il-type-option">
                    <input type="radio" name="il_role" value="professional" onchange="togglePro(true)">
                    <div class="il-type-box">
                        <img src="<?php echo $icon_pro; ?>" class="il-icon-img" alt="Professionnel">
                        <span class="il-label">Professionnel</span>
                    </div>
                </label>
            </div>

            <div class="il-grid-2">
                <div class="il-input-group">
                    <label>Prénom</label>
                    <input type="text" name="il_firstname" required placeholder="Votre prénom">
                </div>
                <div class="il-input-group">
                    <label>Nom</label>
                    <input type="text" name="il_lastname" required placeholder="Votre nom">
                </div>
            </div>

            <div id="il-pro-fields" style="display:none;">
                <div class="il-input-group">
                    <label>Nom de l'entreprise *</label>
                    <input type="text" name="il_company" id="field-company" placeholder="Raison sociale">
                </div>
                <div class="il-input-group">
                    <label>Matricule Fiscale *</label>
                    <input type="text" name="il_fiscal_id" id="field-fiscal" placeholder="Ex: 1234567/A/M/000">
                </div>
            </div>

            <div class="il-input-group">
                <label>Email</label>
                <input type="email" name="il_email" required placeholder="votre@email.com">
            </div>
            
             <div class="il-input-group">
                <label>Téléphone</label>
                <input type="tel" name="il_phone" required placeholder="+216 ...">
            </div>

            <div class="il-input-group">
                <label>Pays</label>
                <div class="il-select-wrapper">
                    <select name="il_country" required>
                        <option value="">Sélectionnez un pays...</option>
                        <?php foreach($countries as $code => $name): ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($code, 'TN'); ?>><?php echo esc_html($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="il-grid-2">
                <div class="il-input-group">
                    <label>Ville</label>
                    <input type="text" name="il_city" required placeholder="Votre ville">
                </div>
                <div class="il-input-group">
                    <label>Adresse</label>
                    <input type="text" name="il_address" required placeholder="Rue, numéro...">
                </div>
            </div>

            <div class="il-input-group">
                <label>Mot de passe</label>
                <div class="il-password-wrapper">
                    <input type="password" name="il_password" id="il-pass-1" required placeholder="Créer un mot de passe" onkeyup="checkStrength(this.value)">
                    <span class="il-pass-toggle" onclick="togglePass('il-pass-1', this)">
                        <?php echo $svg_show; echo $svg_hide; ?>
                    </span>
                </div>
                <div class="il-strength-meter">
                    <div class="il-bar" id="bar-1"></div>
                    <div class="il-bar" id="bar-2"></div>
                    <div class="il-bar" id="bar-3"></div>
                    <div class="il-bar" id="bar-4"></div>
                </div>
                <div class="il-strength-text" id="strength-text"></div>
            </div>

            <div class="il-input-group">
                <label>Confirmer</label>
                <div class="il-password-wrapper">
                    <input type="password" name="il_password_confirm" id="il-pass-2" required placeholder="Répétez le mot de passe">
                    <span class="il-pass-toggle" onclick="togglePass('il-pass-2', this)">
                         <?php echo $svg_show; echo $svg_hide; ?>
                    </span>
                </div>
            </div>

            <div class="cf-turnstile" style="margin: 20px 0; min-height:65px;"></div>
            
            <?php wp_nonce_field('il_reg_action', 'il_reg_nonce'); ?>
            
            <button type="submit" name="il_reg_submit" class="il-btn-primary">S'INSCRIRE</button>
        </form>
    </div>

    <script>
        function togglePro(isPro) {
            var box = document.getElementById('il-pro-fields');
            var inputs = box.querySelectorAll('input');
            box.style.display = isPro ? 'block' : 'none';
            inputs.forEach(el => el.required = isPro);
        }

        function togglePass(fieldId, btn) {
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

        // Strength Logic
        function checkStrength(password) {
            var bars = [document.getElementById('bar-1'), document.getElementById('bar-2'), document.getElementById('bar-3'), document.getElementById('bar-4')];
            var text = document.getElementById('strength-text');
            var score = 0;

            if (password.length > 5) score++;
            if (password.length > 8 && /[0-9]/.test(password)) score++;
            if (password.length > 10 && /[A-Z]/.test(password)) score++;
            if (password.length > 12 && /[^A-Za-z0-9]/.test(password)) score++;

            bars.forEach(b => b.className = 'il-bar');
            text.innerHTML = "";

            if (password.length > 0) {
                if(score === 0) { bars[0].classList.add('weak'); text.innerHTML = "Faible"; text.style.color = "#e74c3c"; }
                else if(score === 1) { bars[0].classList.add('weak'); bars[1].classList.add('weak'); text.innerHTML = "Moyen"; text.style.color = "#e74c3c"; }
                else if(score === 2) { bars[0].classList.add('medium'); bars[1].classList.add('medium'); bars[2].classList.add('medium'); text.innerHTML = "Correct"; text.style.color = "#f1c40f"; }
                else if(score >= 3) { bars.forEach(b => b.classList.add('strong')); text.innerHTML = "Fort"; text.style.color = "#1abc9c"; }
            }
        }
    </script>
    <?php return ob_get_clean();
});