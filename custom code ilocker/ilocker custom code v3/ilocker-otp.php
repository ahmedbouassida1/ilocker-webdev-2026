<?php
/**
 * iLocker Email OTP (Login)
 *
 * Flow:
 * - Step 1 (login): after correct password, send a 5-digit OTP to user email.
 * - Step 2 (otp): user enters OTP to complete login.
 *
 * Security:
 * - OTP is stored hashed in a transient (challenge), never in plaintext.
 * - Challenge expires, has attempt limits, and resend is rate limited.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Configurable settings (can be overridden via wp-config.php).
if ( ! defined( 'ILOCKER_OTP_EXPIRE' ) ) {
	define( 'ILOCKER_OTP_EXPIRE', 10 * MINUTE_IN_SECONDS );
}
if ( ! defined( 'ILOCKER_OTP_DIGITS' ) ) {
	define( 'ILOCKER_OTP_DIGITS', 5 );
}
if ( ! defined( 'ILOCKER_OTP_MAX_ATTEMPTS' ) ) {
	define( 'ILOCKER_OTP_MAX_ATTEMPTS', 5 );
}
if ( ! defined( 'ILOCKER_OTP_RATE_LIMIT_MAX' ) ) {
	define( 'ILOCKER_OTP_RATE_LIMIT_MAX', 5 );
}
if ( ! defined( 'ILOCKER_OTP_RATE_LIMIT_WINDOW' ) ) {
	define( 'ILOCKER_OTP_RATE_LIMIT_WINDOW', 15 * MINUTE_IN_SECONDS );
}
if ( ! defined( 'ILOCKER_OTP_DELAY_MIN_MS' ) ) {
	define( 'ILOCKER_OTP_DELAY_MIN_MS', 700 );
}
if ( ! defined( 'ILOCKER_OTP_DELAY_MAX_MS' ) ) {
	define( 'ILOCKER_OTP_DELAY_MAX_MS', 1600 );
}

function ilocker_otp_user_account_url() {
	$url = function_exists( 'ilocker_ev_user_account_url' ) ? ilocker_ev_user_account_url() : home_url( '/user-account/' );
	return set_url_scheme( $url, 'https' );
}

function ilocker_otp_debug_log( $message ) {
	$log = get_transient( 'ilocker_otp_debug_flow' );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$log[] = date( 'H:i:s' ) . ' - ' . (string) $message;
	if ( count( $log ) > 15 ) {
		array_shift( $log );
	}
	set_transient( 'ilocker_otp_debug_flow', $log, HOUR_IN_SECONDS );
}

function ilocker_otp_add_delay() {
	$min = max( 0, (int) ILOCKER_OTP_DELAY_MIN_MS );
	$max = max( $min, (int) ILOCKER_OTP_DELAY_MAX_MS );
	$ms  = random_int( $min, $max );
	usleep( $ms * 1000 );
}

function ilocker_otp_get_ip() {
	if ( function_exists( 'ilocker_ev_get_ip' ) ) {
		return ilocker_ev_get_ip();
	}

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

function ilocker_otp_get_ua_hash() {
	$ua = '';
	if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
	}
	return hash( 'sha256', (string) $ua );
}

function ilocker_otp_rate_limit_key( $suffix ) {
	return 'ilocker_otp_' . md5( (string) $suffix );
}

function ilocker_otp_is_rate_limited( $ip, $user_id ) {
	$ip      = (string) $ip;
	$uid_key = ilocker_otp_rate_limit_key( 'uid_' . (int) $user_id );
	$u_count = (int) get_transient( $uid_key );

	$ip_key   = '';
	$ip_count = 0;
	// If IP can't be detected, avoid a global/shared IP bucket.
	if ( $ip !== '' ) {
		$ip_key   = ilocker_otp_rate_limit_key( 'ip_' . $ip );
		$ip_count = (int) get_transient( $ip_key );
	}

	if ( $u_count >= ILOCKER_OTP_RATE_LIMIT_MAX ) {
		return true;
	}
	if ( $ip_key && $ip_count >= ILOCKER_OTP_RATE_LIMIT_MAX ) {
		return true;
	}

	set_transient( $uid_key, $u_count + 1, ILOCKER_OTP_RATE_LIMIT_WINDOW );
	if ( $ip_key ) {
		set_transient( $ip_key, $ip_count + 1, ILOCKER_OTP_RATE_LIMIT_WINDOW );
	}

	return false;
}

function ilocker_otp_generate_code() {
	$digits = max( 4, (int) ILOCKER_OTP_DIGITS );
	$max    = ( 10 ** $digits ) - 1;
	$num    = random_int( 0, $max );
	return str_pad( (string) $num, $digits, '0', STR_PAD_LEFT );
}

function ilocker_otp_generate_challenge_id() {
	try {
		return bin2hex( random_bytes( 16 ) );
	} catch ( Exception $e ) {
		return wp_generate_password( 32, false, false );
	}
}

function ilocker_otp_challenge_key( $challenge_id ) {
	return 'ilocker_otp_chal_' . md5( (string) $challenge_id );
}

function ilocker_otp_branded_email_subject() {
	return 'Code de connexion — iLocker';
}

function ilocker_otp_branded_email_message( WP_User $user, $code ) {
	$name     = ! empty( $user->display_name ) ? esc_html( $user->display_name ) : 'Cher client';
	$logo_url = home_url( '/wp-content/uploads/2024/09/logo.png' );
	$minutes  = max( 1, (int) floor( (int) ILOCKER_OTP_EXPIRE / MINUTE_IN_SECONDS ) );

	$html  = '<div style="font-family:\'DM Sans\',Arial,sans-serif;background-color:#F5F5FA;padding:40px 20px;color:#00001A;">';
	$html .= '<div style="max-width:600px;margin:0 auto;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,0.08);border:1px solid #E6E6EC;">';

	$html .= '<div style="background-color:#EEEEFF;padding:32px 40px;text-align:center;">';
	$html .= '<img src="' . esc_url( $logo_url ) . '" alt="iLocker" style="max-height:40px;width:auto;border:0;display:inline-block;" />';
	$html .= '</div>';

	$html .= '<div style="padding:40px 40px 32px 40px;">';
	$html .= '<h2 style="margin:0 0 24px 0;font-family:\'Unbounded\',Arial,sans-serif;font-size:20px;font-weight:700;color:#00001A;">Votre code de connexion</h2>';
	$html .= '<p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#54547E;">Bonjour <strong>' . $name . '</strong>,</p>';
	$html .= '<p style="margin:0 0 20px 0;font-size:16px;line-height:1.6;color:#54547E;">Voici votre code à usage unique pour vous connecter :</p>';

	$html .= '<div style="margin:24px 0 28px 0;">';
	$html .= '<div style="display:inline-block;background:#F5F5FA;border:1px solid #E6E6EC;border-radius:10px;padding:18px 22px;font-size:26px;letter-spacing:6px;font-weight:800;color:#00001A;">' . esc_html( (string) $code ) . '</div>';
	$html .= '</div>';

	$html .= '<p style="margin:0 0 12px 0;font-size:14px;color:#54547E;line-height:1.5;">Ce code expire dans <strong>' . esc_html( (string) $minutes ) . ' minutes</strong>.</p>';
	$html .= '<p style="margin:0;font-size:14px;color:#54547E;line-height:1.5;">Si vous n’êtes pas à l’origine de cette demande, ignorez cet email.</p>';

	$html .= '</div>';

	$html .= '<div style="background-color:#F5F5FA;padding:24px 40px;border-top:1px solid #E6E6EC;text-align:center;font-size:13px;color:#8A8AA7;line-height:1.6;">';
	$html .= '<p style="margin:0 0 12px 0;"><strong>Besoin d\'aide ?</strong> Contactez-nous à <a href="mailto:contact@ilocker.com.tn" style="color:#0000FC;text-decoration:none;font-weight:600;">contact@ilocker.com.tn</a>.</p>';
	$html .= '<p style="margin:0;">&copy; ' . date( 'Y' ) . ' iLocker. Tous droits réservés.</p>';
	$html .= '</div>';

	$html .= '</div>';
	$html .= '</div>';

	return $html;
}

function ilocker_otp_send_email( WP_User $user, $code ) {
	$subject = ilocker_otp_branded_email_subject();
	$message = ilocker_otp_branded_email_message( $user, $code );
	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	$sent = wp_mail( $user->user_email, $subject, $message, $headers );
	if ( ! $sent ) {
		return new WP_Error( 'mail_failed', 'Email failed.' );
	}
	return true;
}

function ilocker_otp_create_challenge( WP_User $user ) {
	$challenge_id = ilocker_otp_generate_challenge_id();
	$code         = ilocker_otp_generate_code();
	$ip           = ilocker_otp_get_ip();

	if ( ilocker_otp_is_rate_limited( $ip, $user->ID ) ) {
		return new WP_Error( 'rate', 'Rate limited.' );
	}

	$data = array(
		'user_id'     => (int) $user->ID,
		'otp_hash'    => wp_hash_password( (string) $code ),
		'expires_at'  => time() + (int) ILOCKER_OTP_EXPIRE,
		'attempts'    => 0,
		'ip'          => (string) $ip,
		'ua_hash'     => ilocker_otp_get_ua_hash(),
		'created_at'  => time(),
	);

	set_transient( ilocker_otp_challenge_key( $challenge_id ), $data, (int) ILOCKER_OTP_EXPIRE + MINUTE_IN_SECONDS );

	$sent = ilocker_otp_send_email( $user, $code );
	if ( is_wp_error( $sent ) ) {
		delete_transient( ilocker_otp_challenge_key( $challenge_id ) );
		return $sent;
	}

	return $challenge_id;
}

function ilocker_otp_view_url( $challenge_id, $extra_args = array() ) {
	$args = array_merge(
		array(
			'il_auth' => 'otp',
			'il_otp'  => (string) $challenge_id,
		),
		(array) $extra_args
	);
	$url = add_query_arg( $args, ilocker_otp_user_account_url() );
	return $url;
}

function ilocker_otp_login_url( $extra_args = array() ) {
	$url = add_query_arg( array_merge( array( 'il_auth' => 'login' ), (array) $extra_args ), ilocker_otp_user_account_url() );
	return $url;
}

function ilocker_otp_load_challenge( $challenge_id ) {
	$challenge_id = (string) $challenge_id;
	if ( $challenge_id === '' ) {
		return new WP_Error( 'invalid', 'Missing challenge.' );
	}

	$data = get_transient( ilocker_otp_challenge_key( $challenge_id ) );
	if ( ! is_array( $data ) || empty( $data['user_id'] ) || empty( $data['otp_hash'] ) ) {
		return new WP_Error( 'expired', 'Challenge expired.' );
	}

	$expires_at = isset( $data['expires_at'] ) ? (int) $data['expires_at'] : 0;
	if ( $expires_at > 0 && time() > $expires_at ) {
		delete_transient( ilocker_otp_challenge_key( $challenge_id ) );
		return new WP_Error( 'expired', 'Challenge expired.' );
	}

	return $data;
}

function ilocker_otp_save_challenge( $challenge_id, array $data ) {
	$ttl = MINUTE_IN_SECONDS;
	if ( isset( $data['expires_at'] ) ) {
		$ttl = max( MINUTE_IN_SECONDS, (int) $data['expires_at'] - time() + MINUTE_IN_SECONDS );
	}
	set_transient( ilocker_otp_challenge_key( $challenge_id ), $data, $ttl );
}

function ilocker_otp_handle_verify_post() {
	$challenge_id = isset( $_POST['il_otp_chal'] ) ? sanitize_text_field( wp_unslash( $_POST['il_otp_chal'] ) ) : '';
	$code_raw     = isset( $_POST['il_otp_code'] ) ? sanitize_text_field( wp_unslash( $_POST['il_otp_code'] ) ) : '';
	$code         = preg_replace( '/\D+/', '', (string) $code_raw );

	if ( ! isset( $_POST['il_otp_verify_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['il_otp_verify_nonce'] ) ), 'il_otp_verify_action' ) ) {
		wp_safe_redirect( ilocker_otp_login_url( array( 'il_otp_error' => 'expired' ) ) );
		exit;
	}

	$challenge = ilocker_otp_load_challenge( $challenge_id );
	if ( is_wp_error( $challenge ) ) {
		wp_safe_redirect( ilocker_otp_login_url( array( 'il_otp_error' => sanitize_key( $challenge->get_error_code() ) ) ) );
		exit;
	}

	$digits = max( 4, (int) ILOCKER_OTP_DIGITS );
	if ( strlen( $code ) !== $digits ) {
		ilocker_otp_add_delay();
		wp_safe_redirect( ilocker_otp_view_url( $challenge_id, array( 'il_otp_error' => 'invalid' ) ) );
		exit;
	}

	$user_id = (int) $challenge['user_id'];
	$user    = get_user_by( 'id', $user_id );
	if ( ! ( $user instanceof WP_User ) ) {
		delete_transient( ilocker_otp_challenge_key( $challenge_id ) );
		wp_safe_redirect( ilocker_otp_login_url( array( 'il_otp_error' => 'expired' ) ) );
		exit;
	}

	// Double-check email verification status (edge case).
	if ( function_exists( 'ilocker_ev_is_verified' ) && ! ilocker_ev_is_verified( $user_id ) && ! user_can( $user, 'manage_options' ) ) {
		delete_transient( ilocker_otp_challenge_key( $challenge_id ) );
		wp_safe_redirect( ilocker_otp_login_url( array( 'il_login_error' => 'unverified' ) ) );
		exit;
	}

	if ( empty( $challenge['otp_hash'] ) || ! wp_check_password( (string) $code, (string) $challenge['otp_hash'], $user_id ) ) {
		$attempts = isset( $challenge['attempts'] ) ? (int) $challenge['attempts'] : 0;
		$attempts++;
		$challenge['attempts'] = $attempts;

		if ( $attempts >= (int) ILOCKER_OTP_MAX_ATTEMPTS ) {
			delete_transient( ilocker_otp_challenge_key( $challenge_id ) );
			ilocker_otp_add_delay();
			wp_safe_redirect( ilocker_otp_login_url( array( 'il_otp_error' => 'attempts' ) ) );
			exit;
		}

		ilocker_otp_save_challenge( $challenge_id, $challenge );
		ilocker_otp_add_delay();
		wp_safe_redirect( ilocker_otp_view_url( $challenge_id, array( 'il_otp_error' => 'invalid' ) ) );
		exit;
	}

	// Success: login.
	delete_transient( ilocker_otp_challenge_key( $challenge_id ) );

	// If something already sent output, cookies/redirects may silently fail.
	if ( headers_sent( $file, $line ) ) {
		ilocker_otp_debug_log( 'headers_sent at ' . $file . ':' . $line );
		wp_safe_redirect( ilocker_otp_login_url( array( 'il_otp_error' => 'headers' ) ) );
		exit;
	}

	wp_set_current_user( $user_id );
	wp_clear_auth_cookie();
	// Set both cookie schemes to survive reverse-proxy / is_ssl inconsistencies.
	wp_set_auth_cookie( $user_id, true, false );
	wp_set_auth_cookie( $user_id, true, true );
	/** This action is documented in wp-login.php */
	do_action( 'wp_login', $user->user_login, $user );

	// Add a visible success flag in case something upstream blocks cookies.
	wp_safe_redirect( add_query_arg( 'il_otp_success', 'verified', ilocker_otp_user_account_url() ) );
	exit;
}

function ilocker_otp_handle_resend_post() {
	$challenge_id = isset( $_POST['il_otp_chal'] ) ? sanitize_text_field( wp_unslash( $_POST['il_otp_chal'] ) ) : '';

	if ( ! isset( $_POST['il_otp_resend_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['il_otp_resend_nonce'] ) ), 'il_otp_resend_action' ) ) {
		wp_safe_redirect( ilocker_otp_login_url( array( 'il_otp_error' => 'expired' ) ) );
		exit;
	}

	$challenge = ilocker_otp_load_challenge( $challenge_id );
	if ( is_wp_error( $challenge ) ) {
		wp_safe_redirect( ilocker_otp_login_url( array( 'il_otp_error' => 'expired' ) ) );
		exit;
	}

	$user_id = (int) $challenge['user_id'];
	$user    = get_user_by( 'id', $user_id );
	if ( ! ( $user instanceof WP_User ) ) {
		delete_transient( ilocker_otp_challenge_key( $challenge_id ) );
		wp_safe_redirect( ilocker_otp_login_url( array( 'il_otp_error' => 'expired' ) ) );
		exit;
	}

	$ip = ilocker_otp_get_ip();
	if ( ilocker_otp_is_rate_limited( $ip, $user_id ) ) {
		ilocker_otp_add_delay();
		wp_safe_redirect( ilocker_otp_view_url( $challenge_id, array( 'il_otp_error' => 'rate' ) ) );
		exit;
	}

	$code = ilocker_otp_generate_code();
	$challenge['otp_hash']   = wp_hash_password( (string) $code );
	$challenge['expires_at'] = time() + (int) ILOCKER_OTP_EXPIRE;
	$challenge['attempts']   = 0;

	ilocker_otp_save_challenge( $challenge_id, $challenge );

	$sent = ilocker_otp_send_email( $user, $code );
	// Uniform response on send failures.
	ilocker_otp_add_delay();
	wp_safe_redirect( ilocker_otp_view_url( $challenge_id, array( 'il_otp_success' => 'sent' ) ) );
	exit;
}

function ilocker_otp_handle_cancel_post() {
	$challenge_id = isset( $_POST['il_otp_chal'] ) ? sanitize_text_field( wp_unslash( $_POST['il_otp_chal'] ) ) : '';

	if ( ! isset( $_POST['il_otp_cancel_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['il_otp_cancel_nonce'] ) ), 'il_otp_cancel_action' ) ) {
		wp_safe_redirect( ilocker_otp_login_url() );
		exit;
	}

	if ( $challenge_id !== '' ) {
		delete_transient( ilocker_otp_challenge_key( $challenge_id ) );
	}

	wp_safe_redirect( ilocker_otp_login_url() );
	exit;
}

// Verify submission.
add_action( 'template_redirect', function () {
	if ( empty( $_POST['il_otp_verify_submit'] ) ) {
		return;
	}
	ilocker_otp_handle_verify_post();
} );

// Resend submission.
add_action( 'template_redirect', function () {
	if ( empty( $_POST['il_otp_resend_submit'] ) ) {
		return;
	}
	ilocker_otp_handle_resend_post();
} );

// Cancel submission.
add_action( 'template_redirect', function () {
	if ( empty( $_POST['il_otp_cancel_submit'] ) ) {
		return;
	}
	ilocker_otp_handle_cancel_post();
} );

// Admin-post endpoints (more reliable than template_redirect on some stacks).
add_action( 'admin_post_nopriv_ilocker_otp_verify', 'ilocker_otp_handle_verify_post' );
add_action( 'admin_post_ilocker_otp_verify', 'ilocker_otp_handle_verify_post' );

add_action( 'admin_post_nopriv_ilocker_otp_resend', 'ilocker_otp_handle_resend_post' );
add_action( 'admin_post_ilocker_otp_resend', 'ilocker_otp_handle_resend_post' );

add_action( 'admin_post_nopriv_ilocker_otp_cancel', 'ilocker_otp_handle_cancel_post' );
add_action( 'admin_post_ilocker_otp_cancel', 'ilocker_otp_handle_cancel_post' );

// Shortcode: OTP form.
add_shortcode( 'ilocker_otp', function () {
	if ( is_user_logged_in() ) {
		return '';
	}

	$challenge_id = isset( $_GET['il_otp'] ) ? sanitize_text_field( wp_unslash( $_GET['il_otp'] ) ) : '';
	if ( $challenge_id === '' ) {
		$login_url = ilocker_otp_login_url();
		return '<div class="il-register-card il-login-mode">' . ( function_exists( 'ilocker_get_alert_html' ) ? ilocker_get_alert_html( 'Session OTP invalide. Veuillez vous reconnecter.', 'error' ) : '' ) . '<div style="margin-top:12px;"><a class="il-auth-back-link" href="' . esc_url( $login_url ) . '">Retour à la connexion</a></div></div>';
	}

	$login_url = ilocker_otp_login_url();
	$post_url  = admin_url( 'admin-post.php' );

	$debug_on = false;
	if ( current_user_can( 'manage_options' ) && isset( $_GET['il_otp_debug'] ) ) {
		$debug_on = ( sanitize_key( wp_unslash( $_GET['il_otp_debug'] ) ) === '1' );
	}

	ob_start();
	?>
	<div class="il-register-card il-login-mode">
		<?php if ( function_exists( 'ilocker_check_url_alerts' ) ) { echo ilocker_check_url_alerts(); } ?>

		<?php if ( $debug_on ) : ?>
			<div class="il-alert il-alert-info" style="margin-bottom:12px;">
				<strong>OTP debug</strong><br/>
				logged_in: <?php echo is_user_logged_in() ? 'yes' : 'no'; ?>,
				is_ssl: <?php echo is_ssl() ? 'yes' : 'no'; ?>,
				host: <?php echo esc_html( isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '' ); ?>
			</div>
			<?php
			$log = get_transient( 'ilocker_otp_debug_flow' );
			if ( is_array( $log ) && ! empty( $log ) ) {
				echo '<pre style="background:#0b0b14;color:#E6E6EC;padding:12px;border-radius:10px;overflow:auto;max-height:220px;">' . esc_html( implode( "\n", $log ) ) . '</pre>';
			}
			?>
		<?php endif; ?>

		<div class="il-auth-title" style="margin-bottom:8px;font-weight:800;color:#00001A;">Code de vérification</div>
		<div style="margin-bottom:14px;color:#54547E;font-size:14px;line-height:1.5;">Nous avons envoyé un code à 5 chiffres à votre email. Saisissez-le pour finaliser la connexion.</div>

		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<div class="il-input-group">
				<label>Code (5 chiffres)</label>
				<input type="text" name="il_otp_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{5}" maxlength="5" required placeholder="_____" style="letter-spacing:6px;font-weight:800;" />
			</div>

			<input type="hidden" name="action" value="ilocker_otp_verify" />
			<input type="hidden" name="il_otp_chal" value="<?php echo esc_attr( $challenge_id ); ?>" />
			<?php wp_nonce_field( 'il_otp_verify_action', 'il_otp_verify_nonce' ); ?>
			<button type="submit" name="il_otp_verify_submit" class="button alt ast-button il-full-width">VÉRIFIER</button>
		</form>

		<div style="display:flex;gap:10px;margin-top:12px;">
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="flex:1;">
				<input type="hidden" name="action" value="ilocker_otp_resend" />
				<input type="hidden" name="il_otp_chal" value="<?php echo esc_attr( $challenge_id ); ?>" />
				<?php wp_nonce_field( 'il_otp_resend_action', 'il_otp_resend_nonce' ); ?>
				<button type="submit" name="il_otp_resend_submit" class="button il-btn-outline il-full-width">RENVOYER</button>
			</form>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="flex:1;">
				<input type="hidden" name="action" value="ilocker_otp_cancel" />
				<input type="hidden" name="il_otp_chal" value="<?php echo esc_attr( $challenge_id ); ?>" />
				<?php wp_nonce_field( 'il_otp_cancel_action', 'il_otp_cancel_nonce' ); ?>
				<button type="submit" name="il_otp_cancel_submit" class="button il-btn-outline-neutral il-full-width">ANNULER</button>
			</form>
		</div>

		<div style="margin-top:14px;text-align:center;">
			<a class="il-auth-back-link" href="<?php echo esc_url( $login_url ); ?>">Retour à la connexion</a>
		</div>
	</div>
	<?php
	return ob_get_clean();
} );
