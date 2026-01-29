<?php
/**
 * iLocker Password Reset (Request + Reset)
 * Shortcodes:
 *  - [ilocker_request_password]
 *  - [ilocker_reset_password]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Configurable settings (can be overridden via wp-config.php or filters).
if ( ! defined( 'ILOCKER_RESET_PAGE_SLUG' ) ) {
	define( 'ILOCKER_RESET_PAGE_SLUG', 'set-new-password' );
}
if ( ! defined( 'ILOCKER_RESET_DELAY_MIN_MS' ) ) {
	define( 'ILOCKER_RESET_DELAY_MIN_MS', 1200 );
}
if ( ! defined( 'ILOCKER_RESET_DELAY_MAX_MS' ) ) {
	define( 'ILOCKER_RESET_DELAY_MAX_MS', 2200 );
}
if ( ! defined( 'ILOCKER_RESET_RATE_LIMIT_MAX' ) ) {
	define( 'ILOCKER_RESET_RATE_LIMIT_MAX', 5 );
}
if ( ! defined( 'ILOCKER_RESET_RATE_LIMIT_WINDOW' ) ) {
	define( 'ILOCKER_RESET_RATE_LIMIT_WINDOW', 15 * MINUTE_IN_SECONDS );
}
if ( ! defined( 'ILOCKER_RESET_FROM_EMAIL' ) ) {
	define( 'ILOCKER_RESET_FROM_EMAIL', '' );
}
if ( ! defined( 'ILOCKER_RESET_FROM_NAME' ) ) {
	define( 'ILOCKER_RESET_FROM_NAME', '' );
}

function ilocker_pr_get_ip() {
	$ip = '';
	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
	} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$ip = sanitize_text_field( wp_unslash( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] ) );
	} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}
	return $ip;
}

function ilocker_pr_rate_limit_key( $suffix ) {
	return 'ilocker_pr_' . md5( $suffix );
}

function ilocker_pr_is_rate_limited( $ip, $login_or_email ) {
	$ip_key    = ilocker_pr_rate_limit_key( 'ip_' . $ip );
	$user_key  = ilocker_pr_rate_limit_key( 'user_' . strtolower( $login_or_email ) );
	$ip_count  = (int) get_transient( $ip_key );
	$user_count = (int) get_transient( $user_key );

	if ( $ip_count >= ILOCKER_RESET_RATE_LIMIT_MAX || $user_count >= ILOCKER_RESET_RATE_LIMIT_MAX ) {
		return true;
	}

	set_transient( $ip_key, $ip_count + 1, ILOCKER_RESET_RATE_LIMIT_WINDOW );
	set_transient( $user_key, $user_count + 1, ILOCKER_RESET_RATE_LIMIT_WINDOW );

	return false;
}

function ilocker_pr_add_delay() {
	$min = max( 0, (int) ILOCKER_RESET_DELAY_MIN_MS );
	$max = max( $min, (int) ILOCKER_RESET_DELAY_MAX_MS );
	$ms  = random_int( $min, $max );
	usleep( $ms * 1000 );
}

function ilocker_pr_turnstile_verify() {
	$token  = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
	$secret = defined( 'ILOCKER_TURNSTILE_SECRET' ) ? ILOCKER_TURNSTILE_SECRET : '';

	if ( empty( $secret ) ) {
		return true;
	}
	if ( empty( $token ) ) {
		return false;
	}

	$req = wp_remote_post(
		'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		array(
			'body' => array(
				'secret'   => $secret,
				'response' => $token,
				'remoteip' => ilocker_pr_get_ip(),
			),
		)
	);

	$res = json_decode( wp_remote_retrieve_body( $req ) );
	return ( $res && ! empty( $res->success ) );
}

function ilocker_pr_reset_url( $key, $login ) {
	$path = '/' . trim( ILOCKER_RESET_PAGE_SLUG, '/' ) . '/';
	$url  = home_url( $path );
	$url  = add_query_arg(
		array(
			'action' => 'rp',
			'key'    => rawurlencode( $key ),
			'login'  => rawurlencode( $login ),
		),
		$url
	);
	return set_url_scheme( $url, 'https' );
}

function ilocker_pr_branded_email_subject( $title ) {
	return 'Réinitialisation de mot de passe — iLocker';
}

function ilocker_pr_branded_email_message( $message, $key, $user_login, $user_data ) {
	$reset_url = ilocker_pr_reset_url( $key, $user_login );
	$name      = $user_data && ! empty( $user_data->display_name ) ? esc_html( $user_data->display_name ) : 'Cher client';
	$logo_url  = home_url( '/wp-content/uploads/2024/09/logo.png' );

	$html  = '<div style="font-family:\'DM Sans\',Arial,sans-serif;background-color:#F5F5FA;padding:40px 20px;color:#00001A;">';
	$html .= '<div style="max-width:600px;margin:0 auto;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,0.08);border:1px solid #E6E6EC;">';
	
	// Header: Blue 50 Background with Logo
	$html .= '<div style="background-color:#EEEEFF;padding:32px 40px;text-align:center;">';
	$html .= '<img src="' . esc_url( $logo_url ) . '" alt="iLocker" style="max-height:40px;width:auto;border:0;display:inline-block;" />';
	$html .= '</div>';

	// Body Content
	$html .= '<div style="padding:40px 40px 32px 40px;">';
	$html .= '<h2 style="margin:0 0 24px 0;font-family:\'Unbounded\',Arial,sans-serif;font-size:20px;font-weight:700;color:#00001A;">Réinitialisation de mot de passe</h2>';
	
	$html .= '<p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#54547E;">Bonjour <strong>' . $name . '</strong>,</p>';
	$html .= '<p style="margin:0 0 24px 0;font-size:16px;line-height:1.6;color:#54547E;">Nous avons bien reçu votre demande de réinitialisation de mot de passe. Pour définir vos nouveaux identifiants et sécuriser votre compte, veuillez cliquer sur le bouton ci-dessous :</p>';
	
	// Button: Matching Theme Style (Rounded + Primary Blue)
	$html .= '<div style="margin:32px 0;text-align:left;">';
	$html .= '<a href="' . esc_url( $reset_url ) . '" style="display:inline-block;background-color:#0000FC;color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;text-align:center;box-shadow:0 4px 12px rgba(0,0,252,0.15);">Réinitialiser mon mot de passe</a>';
	$html .= '</div>';
	
	// Secondary info
	$html .= '<p style="margin:0 0 12px 0;font-size:14px;color:#54547E;line-height:1.5;">Ce lien est temporaire et valide pour une durée de 24 heures.</p>';
	$html .= '<p style="margin:0 0 0 0;font-size:14px;color:#54547E;line-height:1.5;">Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer cet email en toute sécurité. Votre compte reste parfaitement protégé.</p>';
	
	// Fallback Link
	$html .= '<div style="margin-top:32px;padding-top:24px;border-top:1px solid #E6E6EC;font-size:12px;color:#8A8AA7;line-height:1.5;">';
	$html .= 'Lien direct (si le bouton ne fonctionne pas) :<br/><a href="' . esc_url( $reset_url ) . '" style="color:#0000FC;text-decoration:none;word-break:break-all;">' . esc_url( $reset_url ) . '</a>';
	$html .= '</div>';
	
	$html .= '</div>'; // End Body

	// Footer: Light Background
	$html .= '<div style="background-color:#F5F5FA;padding:24px 40px;border-top:1px solid #E6E6EC;text-align:center;font-size:13px;color:#8A8AA7;line-height:1.6;">';
	$html .= '<p style="margin:0 0 12px 0;"><strong>Besoin d\'aide ?</strong> Si vous avez reçu cet email par erreur, merci de contacter notre support à <a href="mailto:contact@ilocker.com.tn" style="color:#0000FC;text-decoration:none;font-weight:600;">contact@ilocker.com.tn</a>.</p>';
	$html .= '<p style="margin:0;">&copy; ' . date('Y') . ' iLocker. Tous droits réservés.</p>';
	$html .= '</div>';

	$html .= '</div>'; // End Container
	$html .= '</div>'; // End Wrapper

	return $html;
}

function ilocker_pr_store_mail_error( $error ) {
	if ( ! $error instanceof WP_Error ) {
		return;
	}
	$codes = $error->get_error_codes();
	$messages = array();
	foreach ( $codes as $code ) {
		$messages[] = $code . ': ' . $error->get_error_message( $code );
	}
	set_transient( 'ilocker_pr_last_mail_error', implode( ' | ', $messages ), 5 * MINUTE_IN_SECONDS );
}

add_action( 'wp_mail_failed', 'ilocker_pr_store_mail_error' );

if ( ! empty( ILOCKER_RESET_FROM_EMAIL ) ) {
	add_filter( 'wp_mail_from', function( $from ) {
		return sanitize_email( ILOCKER_RESET_FROM_EMAIL );
	} );
}
if ( ! empty( ILOCKER_RESET_FROM_NAME ) ) {
	add_filter( 'wp_mail_from_name', function( $name ) {
		return sanitize_text_field( ILOCKER_RESET_FROM_NAME );
	} );
}


function ilocker_pr_send_reset_email( $user_login ) {
	$user_data = get_user_by( 'login', $user_login );
	if ( ! $user_data && strpos( $user_login, '@' ) !== false ) {
		$user_data = get_user_by( 'email', $user_login );
	}
	if ( ! $user_data ) {
		return new WP_Error( 'invalid_user', __( 'Invalid user.' ) );
	}

	$key = get_password_reset_key( $user_data );
	if ( is_wp_error( $key ) ) {
		return $key;
	}

	$subject = ilocker_pr_branded_email_subject( '' );
	$message = ilocker_pr_branded_email_message( '', $key, $user_data->user_login, $user_data );
	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	$sent = wp_mail( $user_data->user_email, $subject, $message, $headers );
	if ( ! $sent ) {
		$error = new WP_Error( 'mail_failed', __( 'Email failed.' ) );
		ilocker_pr_store_mail_error( $error );
		return $error;
	}

	return true;
}

// Function to log debug messages to a transient
function ilocker_pr_log( $message ) {
	$log = get_transient( 'ilocker_pr_debug_flow' );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$log[] = date( 'H:i:s' ) . ' - ' . $message;
	if ( count( $log ) > 10 ) {
		array_shift( $log );
	}
	set_transient( 'ilocker_pr_debug_flow', $log, HOUR_IN_SECONDS );
}

add_action( 'init', function() {
	if ( isset( $_GET['il_pr_clear_log'] ) && current_user_can( 'manage_options' ) ) {
		delete_transient( 'ilocker_pr_debug_flow' );
	}
} );

// Request handler (High priority init to catch early)
add_action( 'init', function() {
	// Trigger if 'ilocker_pr_login' is present AND 'ilocker_pr_pass1' is NOT (distinguishes from reset form)
	// We no longer rely on the submit button name 'ilocker_pr_request_submit' as it can be unreliable.
	if ( empty( $_POST['ilocker_pr_login'] ) || isset( $_POST['ilocker_pr_pass1'] ) ) {
		return;
	}

	// Start logging
	ilocker_pr_log( '--- New POST Request (Caught via input check) ---' );
	
	$redirect_url = remove_query_arg( array( 'il_pr_error', 'il_pr_success' ) );

	if ( ! isset( $_POST['ilocker_pr_nonce'] ) ) {
		ilocker_pr_log( 'Nonce missing in POST.' );
		wp_safe_redirect( add_query_arg( 'il_pr_error', 'expired', $redirect_url ) );
		exit;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ilocker_pr_nonce'] ) ), 'ilocker_pr_request' ) ) {
		ilocker_pr_log( 'Nonce verification failed.' );
		wp_safe_redirect( add_query_arg( 'il_pr_error', 'expired', $redirect_url ) );
		exit;
	}
	ilocker_pr_log( 'Nonce passed.' );

	if ( ! ilocker_pr_turnstile_verify() ) {
		ilocker_pr_log( 'Turnstile failed.' );
		wp_safe_redirect( add_query_arg( 'il_pr_error', 'captcha', $redirect_url ) );
		exit;
	}
	ilocker_pr_log( 'Turnstile passed.' );

	$login = isset( $_POST['ilocker_pr_login'] ) ? sanitize_text_field( wp_unslash( $_POST['ilocker_pr_login'] ) ) : '';
	$login = trim( $login );

	if ( empty( $login ) ) {
		ilocker_pr_log( 'Login field empty.' );
		ilocker_pr_add_delay();
		wp_safe_redirect( add_query_arg( 'il_pr_success', 'sent', $redirect_url ) );
		exit;
	}

	$ip = ilocker_pr_get_ip();
	if ( ilocker_pr_is_rate_limited( $ip, $login ) ) {
		ilocker_pr_log( 'Rate limited IP/User.' );
		ilocker_pr_add_delay();
		wp_safe_redirect( add_query_arg( 'il_pr_success', 'sent', $redirect_url ) );
		exit;
	}

	$user_data = false;
	if ( strpos( $login, '@' ) !== false ) {
		$user_data = get_user_by( 'email', $login );
	} else {
		$user_data = get_user_by( 'login', $login );
	}

	if ( $user_data ) {
		ilocker_pr_log( 'User found: ' . $user_data->ID );
		$result = ilocker_pr_send_reset_email( $user_data->user_login );
		if ( needs_turnstile_fix( $result ) ) {
             // Handle if wp_mail returns something unexpected, though unlikely here
        }
		if ( is_wp_error( $result ) ) {
			ilocker_pr_log( 'Mail Error: ' . $result->get_error_message() );
		} else {
			ilocker_pr_log( 'Mail sent successfully.' );
		}
	} else {
		ilocker_pr_log( 'User not found.' );
	}

	ilocker_pr_add_delay();
	wp_safe_redirect( add_query_arg( 'il_pr_success', 'sent', $redirect_url ) );
	exit;

}, 1 ); // Priority 1 to run before anything else

function needs_turnstile_fix( $res ) { return false; } 

// Handler for password request submission (via admin-post.php)
function ilocker_pr_process_request_form() {
	// Deprecated in favor of init handler above
}

// Reset handler (new password)
add_action( 'template_redirect', function() {
	// Check for password fields instead of submit button for reliability
	if ( empty( $_POST['ilocker_pr_pass1'] ) || empty( $_POST['ilocker_pr_key'] ) ) {
		return;
	}

	global $wp;
	$base_url = remove_query_arg( array( 'il_pr_error', 'il_pr_success', 'key', 'login', 'action' ), home_url( add_query_arg( array(), $wp->request ) ) );

	if ( ! isset( $_POST['ilocker_pr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ilocker_pr_nonce'] ) ), 'ilocker_pr_reset' ) ) {
		wp_safe_redirect( add_query_arg( 'il_pr_error', 'expired', $base_url ) );
		exit;
	}

	$login = isset( $_POST['ilocker_pr_login'] ) ? sanitize_text_field( wp_unslash( $_POST['ilocker_pr_login'] ) ) : '';
	$key   = isset( $_POST['ilocker_pr_key'] ) ? sanitize_text_field( wp_unslash( $_POST['ilocker_pr_key'] ) ) : '';
	$pass1 = isset( $_POST['ilocker_pr_pass1'] ) ? (string) wp_unslash( $_POST['ilocker_pr_pass1'] ) : '';
	$pass2 = isset( $_POST['ilocker_pr_pass2'] ) ? (string) wp_unslash( $_POST['ilocker_pr_pass2'] ) : '';

	if ( empty( $login ) || empty( $key ) ) {
		wp_safe_redirect( add_query_arg( 'il_pr_error', 'invalid', $base_url ) );
		exit;
	}

	$user = check_password_reset_key( $key, $login );
	if ( is_wp_error( $user ) ) {
		wp_safe_redirect( add_query_arg( 'il_pr_error', 'invalid', $base_url ) );
		exit;
	}

	if ( empty( $pass1 ) || strlen( $pass1 ) < 8 ) {
		wp_safe_redirect( add_query_arg( 'il_pr_error', 'weak', $base_url ) );
		exit;
	}
	if ( $pass1 !== $pass2 ) {
		wp_safe_redirect( add_query_arg( 'il_pr_error', 'mismatch', $base_url ) );
		exit;
	}

	reset_password( $user, $pass1 );
	wp_safe_redirect( home_url( '/compte/' ) ); // Redirect to login/account page
	exit;
} );

function ilocker_pr_base_styles() {
	return '<style>
	.ilocker-card{background:var(--bg-surface,#fff);border:1px solid #e6e6ec;border-radius:14px;box-shadow:0 12px 32px rgba(0,0,0,0.08);padding:28px;max-width:460px;margin:0 auto;color:#54547e;font-family:DM Sans,Arial,sans-serif}
	.ilocker-title{font-family:Unbounded,Arial,sans-serif;color:#00001a;font-size:22px;margin:0 0 10px 0}
	.ilocker-sub{font-size:14px;margin-bottom:18px}
	.ilocker-group{margin-bottom:16px}
	.ilocker-group label{display:block;font-weight:600;color:#00001a;margin-bottom:6px}
	.ilocker-group input{width:100%;padding:12px 14px;border-radius:10px;border:1px solid #e6e6ec;background:#fff}
	.ilocker-btn{display:inline-block;width:100%;background:#0000fc;color:#fff;border:none;border-radius:10px;padding:12px 16px;font-weight:700;cursor:pointer}
	.ilocker-alert{padding:10px 12px;border-radius:10px;border:1px solid #e6e6ec;margin-bottom:16px;font-size:14px}
	.ilocker-alert.error{background:#fdecea;color:#e74c3c;border-color:#f5c6cb}
	.ilocker-alert.success{background:#e6fffa;color:#2ecc71;border-color:#b2f5ea}
	</style>';
}

function ilocker_pr_turnstile_widget() {
	$key = defined( 'ILOCKER_TURNSTILE_SITE' ) ? ILOCKER_TURNSTILE_SITE : '';
	if ( ! $key ) {
		return '';
	}
	ob_start();
	?>
	<div class="cf-turnstile" style="margin:16px 0;min-height:65px;"></div>
	<script>
	if (typeof turnstile === 'undefined') {
		var script = document.createElement('script');
		script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
		script.async = true; script.defer = true;
		document.body.appendChild(script);
	}
	document.addEventListener("DOMContentLoaded", function() {
		function renderWidgets() {
			var widgets = document.querySelectorAll('.cf-turnstile');
			widgets.forEach(function(w) {
				if (!w.dataset.rendered && typeof turnstile !== 'undefined') {
					turnstile.render(w, { sitekey: '<?php echo esc_js( $key ); ?>', theme: 'light' });
					w.dataset.rendered = "true";
				}
			});
		}
		renderWidgets();
		setTimeout(renderWidgets, 1000);
	});
	</script>
	<?php
	return ob_get_clean();
}

// Shortcode: request form
add_shortcode( 'ilocker_request_password', function() {
	// Hide form for logged in users EXCEPT admins who might be testing
	if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
		return '<div class="ilocker-alert">Vous êtes déjà connecté.</div>';
	}

	$msg_text = '';
	$msg_type = '';

	if ( isset( $_GET['il_pr_error'] ) ) {
		$msg_type = 'error';
		switch ( sanitize_text_field( wp_unslash( $_GET['il_pr_error'] ) ) ) {
			case 'captcha':
				$msg_text = 'Sécurité échouée. Veuillez réessayer.';
				break;
			case 'expired':
				$msg_text = 'Session expirée. Rechargez la page.';
				break;
			default:
				$msg_text = 'Une erreur est survenue. Veuillez réessayer.';
				break;
		}
	}

	if ( isset( $_GET['il_pr_success'] ) ) {
		$msg_type = 'success';
		$msg_text = 'Si un compte correspond, un lien de réinitialisation a été envoyé.';
	}

	ob_start();
	echo ilocker_pr_base_styles();
	?>
	<div class="ilocker-card">
		<div class="ilocker-title">Mot de passe oublié</div>
		<div class="ilocker-sub">Entrez votre email ou identifiant pour recevoir un lien sécurisé.</div>

		<?php if ( $msg_text ) : ?>
			<div class="ilocker-alert <?php echo esc_attr( $msg_type ); ?>">
				<?php echo esc_html( $msg_text ); ?>
			</div>
		<?php endif; ?>

		<form method="post" action="">
			<div class="ilocker-group">
				<label for="ilocker_pr_login">Identifiant ou Email</label>
				<input type="text" id="ilocker_pr_login" name="ilocker_pr_login" required placeholder="votre@email.com" />
			</div>

			<?php echo ilocker_pr_turnstile_widget(); ?>

			<?php wp_nonce_field( 'ilocker_pr_request', 'ilocker_pr_nonce' ); ?>
			<button type="submit" name="ilocker_pr_request_submit" class="ilocker-btn">ENVOYER LE LIEN</button>
		</form>
	</div>
	<?php
	return ob_get_clean();
} );

// Shortcode: reset form
add_shortcode( 'ilocker_reset_password', function() {
	$msg_text = '';
	$msg_type = '';

	if ( isset( $_GET['il_pr_error'] ) ) {
		$msg_type = 'error';
		switch ( sanitize_text_field( wp_unslash( $_GET['il_pr_error'] ) ) ) {
			case 'invalid':
				$msg_text = 'Lien invalide ou expiré.';
				break;
			case 'weak':
				$msg_text = 'Mot de passe trop court (8 caractères minimum).';
				break;
			case 'mismatch':
				$msg_text = 'Les mots de passe ne correspondent pas.';
				break;
			default:
				$msg_text = 'Une erreur est survenue. Veuillez réessayer.';
				break;
		}
	}

	if ( isset( $_GET['il_pr_success'] ) ) {
		$msg_type = 'success';
		$msg_text = 'Votre mot de passe a été réinitialisé.';
	}

	$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	$login = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : '';

	ob_start();
	echo ilocker_pr_base_styles();
	?>
	<div class="ilocker-card">
		<div class="ilocker-title">Nouveau mot de passe</div>
		<div class="ilocker-sub">Choisissez un mot de passe robuste pour votre compte.</div>

		<?php if ( $msg_text ) : ?>
			<div class="ilocker-alert <?php echo esc_attr( $msg_type ); ?>">
				<?php echo esc_html( $msg_text ); ?>
			</div>
		<?php endif; ?>

		<form method="post" action="">
			<input type="hidden" name="ilocker_pr_key" value="<?php echo esc_attr( $key ); ?>" />
			<input type="hidden" name="ilocker_pr_login" value="<?php echo esc_attr( $login ); ?>" />

			<div class="ilocker-group">
				<label for="ilocker_pr_pass1">Nouveau mot de passe</label>
				<input type="password" id="ilocker_pr_pass1" name="ilocker_pr_pass1" required minlength="8" />
			</div>
			<div class="ilocker-group">
				<label for="ilocker_pr_pass2">Confirmer le mot de passe</label>
				<input type="password" id="ilocker_pr_pass2" name="ilocker_pr_pass2" required minlength="8" />
			</div>

			<?php wp_nonce_field( 'ilocker_pr_reset', 'ilocker_pr_nonce' ); ?>
			<button type="submit" name="ilocker_pr_reset_submit" class="ilocker-btn">RÉINITIALISER</button>
		</form>
	</div>
	<?php
	return ob_get_clean();
} );

// Load Turnstile script when needed
add_action( 'wp_footer', function() {
	$key = defined( 'ILOCKER_TURNSTILE_SITE' ) ? ILOCKER_TURNSTILE_SITE : '';
	if ( ! $key ) {
		return;
	}
	?>
	<script>
	if (typeof turnstile === 'undefined') {
		var script = document.createElement('script');
		script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
		script.async = true; script.defer = true;
		document.body.appendChild(script);
	}
	document.addEventListener("DOMContentLoaded", function() {
		function renderWidgets() {
			var widgets = document.querySelectorAll('.cf-turnstile');
			widgets.forEach(function(w) {
				if (!w.dataset.rendered && typeof turnstile !== 'undefined') {
					turnstile.render(w, { sitekey: '<?php echo esc_js( $key ); ?>', theme: 'light' });
					w.dataset.rendered = "true";
				}
			});
		}
		renderWidgets();
		setTimeout(renderWidgets, 1000);
	});
	</script>
	<?php
}, 99 );

