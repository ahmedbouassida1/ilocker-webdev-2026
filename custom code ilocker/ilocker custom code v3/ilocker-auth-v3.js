(function () {
	'use strict';

	if (window.__ilAuthV3Loaded) return;
	window.__ilAuthV3Loaded = true;

	// Expose legacy inline handlers as globals so AJAX-inserted HTML keeps working.
	window.toggleLoginPass = function (fieldId, btn) {
		var input = document.getElementById(fieldId);
		if (!input || !btn) return;
		var iconShow = btn.querySelector('.il-show');
		var iconHide = btn.querySelector('.il-hide');

		if (input.type === 'password') {
			input.type = 'text';
			if (iconShow) iconShow.style.display = 'none';
			if (iconHide) iconHide.style.display = 'block';
		} else {
			input.type = 'password';
			if (iconShow) iconShow.style.display = 'block';
			if (iconHide) iconHide.style.display = 'none';
		}
	};

	window.togglePass = function (fieldId, btn) {
		return window.toggleLoginPass(fieldId, btn);
	};

	window.togglePro = function (isPro) {
		var box = document.getElementById('il-pro-fields');
		if (!box) return;
		var inputs = box.querySelectorAll('input');
		box.style.display = isPro ? 'block' : 'none';
		for (var i = 0; i < inputs.length; i++) {
			inputs[i].required = !!isPro;
		}
	};

	window.checkStrength = function (password) {
		password = typeof password === 'string' ? password : '';
		var bars = [
			document.getElementById('bar-1'),
			document.getElementById('bar-2'),
			document.getElementById('bar-3'),
			document.getElementById('bar-4'),
		];
		var text = document.getElementById('strength-text');
		var score = 0;

		if (password.length > 5) score++;
		if (password.length > 8 && /[0-9]/.test(password)) score++;
		if (password.length > 10 && /[A-Z]/.test(password)) score++;
		if (password.length > 12 && /[^A-Za-z0-9]/.test(password)) score++;

		for (var i = 0; i < bars.length; i++) {
			if (bars[i]) bars[i].className = 'il-bar';
		}
		if (text) text.innerHTML = '';

		if (password.length <= 0 || !text) return;

		if (score === 0) {
			if (bars[0]) bars[0].classList.add('weak');
			text.innerHTML = 'Faible';
			text.style.color = '#e74c3c';
		} else if (score === 1) {
			if (bars[0]) bars[0].classList.add('weak');
			if (bars[1]) bars[1].classList.add('weak');
			text.innerHTML = 'Moyen';
			text.style.color = '#e74c3c';
		} else if (score === 2) {
			if (bars[0]) bars[0].classList.add('medium');
			if (bars[1]) bars[1].classList.add('medium');
			if (bars[2]) bars[2].classList.add('medium');
			text.innerHTML = 'Correct';
			text.style.color = '#f1c40f';
		} else {
			for (var j = 0; j < bars.length; j++) {
				if (bars[j]) bars[j].classList.add('strong');
			}
			text.innerHTML = 'Fort';
			text.style.color = '#1abc9c';
		}
	};

	function getAuthFromUrl(url) {
		try {
			var u = new URL(url, window.location.origin);
			var auth = u.searchParams.get('il_auth') || 'login';
			if (auth !== 'login' && auth !== 'register' && auth !== 'forgot' && auth !== 'otp') {
				auth = 'login';
			}
			return auth;
		} catch (e) {
			return 'login';
		}
	}

	function setActiveTab(shell, auth) {
		var tabs = shell.querySelectorAll('.il-auth-tabs .il-auth-tab');
		for (var i = 0; i < tabs.length; i++) {
			tabs[i].classList.remove('active');
			var view = tabs[i].getAttribute('data-il-auth-view') || '';
			// Treat "forgot" as "login" for highlighting.
			var normalized = (auth === 'forgot' || auth === 'otp') ? 'login' : auth;
			if (view === normalized) tabs[i].classList.add('active');
		}
	}

	function safeCallTurnstileRender() {
		try {
			if (typeof window.ilockerRenderTurnstileWidgets === 'function') {
				window.ilockerRenderTurnstileWidgets();
			}
		} catch (e) {}
	}

	function loadAuth(shell, auth, targetUrl, pushState) {
		if (!shell) return;
		var content = shell.querySelector('#il-auth-content');
		if (!content) return;

		// OTP view depends on the challenge id in the URL query params.
		// Loading it via AJAX would lose that context, so force a full navigation.
		if (auth === 'otp') {
			window.location.href = targetUrl;
			return;
		}

		setActiveTab(shell, auth);

		var ajaxUrl = (window.IL_AUTH_V3 && IL_AUTH_V3.ajaxUrl) ? IL_AUTH_V3.ajaxUrl : '';
		var nonce = (window.IL_AUTH_V3 && IL_AUTH_V3.nonce) ? IL_AUTH_V3.nonce : '';
		if ((!ajaxUrl || !nonce) && shell && shell.dataset) {
			ajaxUrl = ajaxUrl || shell.dataset.ilAuthAjaxUrl || '';
			nonce = nonce || shell.dataset.ilAuthNonce || '';
		}

		if (!ajaxUrl || !nonce) {
			// No AJAX config available; fall back to normal navigation.
			window.location.href = targetUrl;
			return;
		}

		content.setAttribute('aria-busy', 'true');
		content.classList.add('il-auth-loading');

		var baseUrl;
		try {
			var base = new URL(window.location.href, window.location.origin);
			base.searchParams.delete('il_auth');
			baseUrl = base.toString();
		} catch (e) {
			baseUrl = '';
		}

		var body = new FormData();
		body.append('action', 'il_auth_v3_nav');
		body.append('nonce', nonce);
		body.append('view', auth);
		if (baseUrl) body.append('base_url', baseUrl);

		fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		})
			.then(function (r) {
				return r.json();
			})
			.then(function (json) {
				if (!json || !json.success || !json.data || typeof json.data.html !== 'string') {
					throw new Error('bad_response');
				}
				content.innerHTML = json.data.html;
				safeCallTurnstileRender();
			})
			.catch(function () {
				window.location.href = targetUrl;
			})
			.finally(function () {
				content.removeAttribute('aria-busy');
				content.classList.remove('il-auth-loading');
			});

		if (pushState) {
			try {
				window.history.pushState({ il_auth: auth }, '', targetUrl);
			} catch (e) {}
		}
	}

	function bind() {
		var shell = document.querySelector('[data-il-auth-shell]');
		if (!shell) return;

		document.addEventListener(
			'click',
			function (e) {
				var a = e.target && e.target.closest ? e.target.closest('a') : null;
				if (!a) return;
				if (!shell.contains(a)) return;

				// Only handle auth navigation links.
				var isTab = a.classList.contains('il-auth-tab');
				var isForgot = a.classList.contains('il-auth-forgot-link');
				var isBack = a.classList.contains('il-auth-back-link');
				if (!isTab && !isForgot && !isBack) return;

				var href = a.getAttribute('href');
				if (!href) return;

				var auth = getAuthFromUrl(href);
				if (e.stopImmediatePropagation) e.stopImmediatePropagation();
				if (e.stopPropagation) e.stopPropagation();
				e.preventDefault();
				loadAuth(shell, auth, href, true);
			},
			true
		);

		window.addEventListener('popstate', function () {
			var auth = getAuthFromUrl(window.location.href);
			loadAuth(shell, auth, window.location.href, false);
		});

		// Ensure Turnstile is rendered on first load.
		safeCallTurnstileRender();
		setActiveTab(shell, getAuthFromUrl(window.location.href));
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bind);
	} else {
		bind();
	}
})();
