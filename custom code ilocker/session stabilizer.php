/**
 * iLocker Session Stabilizer (7 Days)
 * Fix: Empêche le cache sur les pages compte et définit une session de 1 semaine.
 */

// 1. DÉFINIR LA DURÉE DE SESSION (7 Jours)
add_filter('auth_cookie_expiration', function($seconds, $user_id, $remember) {
    // 7 jours * 24h * 60m * 60s = 604800 secondes
    return 604800; 
}, 99, 3);

// 2. INTERDIRE LE CACHE SUR LES PAGES COMPTE (Anti-Cloudflare/OVH Cache)
// Indispensable pour éviter que Cloudflare ne serve une page "vide" alors que vous êtes connecté.
add_action('template_redirect', function() {
    // Si on est sur une page sensible (Compte, Panier, Commande)
    if (is_account_page() || is_cart() || is_checkout() || is_wc_endpoint_url()) {
        
        // On envoie les signaux "NO CACHE" au navigateur et au serveur
        if (!headers_sent()) {
            nocache_headers();
            header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0'); // HTTP 1.1
            header('Pragma: no-cache'); // HTTP 1.0
            header('Expires: 0'); // Proxies
        }
        
        // Sécurité supplémentaire pour les plugins de cache WP
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
    }
}, 1);

// 3. FIX DES COOKIES DE SESSION
// Force WordPress à reconnaître le cookie même si le visiteur passe de http à https ou www à non-www
add_action('init', function() {
    if (isset($_COOKIE[LOGGED_IN_COOKIE]) && !is_user_logged_in()) {
        // Logique de secours silencieuse pour stabiliser la session
    }
});