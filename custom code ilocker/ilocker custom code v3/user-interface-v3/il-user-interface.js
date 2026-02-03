jQuery(document).ready(function($) {
    window.__ilUIV3Loaded = true;
    
    // --- AJAX NAVIGATION ---
    
    function ilV3ParseUrl(url) {
        try {
            var u = new URL(url, window.location.origin);
            return {
                url: u.toString(),
                view: u.searchParams.get('il_view') || 'dashboard',
                orderId: u.searchParams.get('order_id') || u.searchParams.get('view-order') || '',
                ilEdit: u.searchParams.get('il_edit') || ''
            };
        } catch (e) {
            return { url: url, view: 'dashboard', orderId: '', ilEdit: '' };
        }
    }

    function ilV3SetActive(view) {
        if (!view) return;
        var links = document.querySelectorAll('#il-v3-wrapper .il-v3-sidebar a.il-v3-nav-item:not(.logout)');
        for (var i = 0; i < links.length; i++) {
            links[i].classList.remove('active');
        }
        for (var j = 0; j < links.length; j++) {
            var href = links[j].getAttribute('href') || '';
            var parsed = ilV3ParseUrl(href);
            if (parsed.view === view) {
                links[j].classList.add('active');
                break;
            }
        }
    }

    function ilV3LoadByUrl(url, opts) {
        opts = opts || {};
        var shouldPush = opts.push === true;

        var parsed = ilV3ParseUrl(url);
        ilV3SetActive(parsed.view);

        var $content = $('#il-v3-content');
        $content.css('opacity', '0.5');

        function pushUrl(newTitle) {
            if (!shouldPush) return;
            if (window.history.pushState) {
                try {
                    window.history.pushState({path: parsed.url}, newTitle || '', parsed.url);
                } catch (e) {
                    console.warn('History pushState failed', e);
                }
            }
        }

        function finish(html, newTitle) {
            $content.html(html).css('opacity', '1');
            $content.find('> div').addClass('il-v3-fade-in');
            if (newTitle) document.title = newTitle;
            pushUrl(newTitle);
            if ($(window).width() < 900) {
                $('html, body').animate({
                    scrollTop: $('#il-v3-content').offset().top - 120
                }, 500);
            }
        }

        // Prefer WP AJAX endpoint
        if (window.IL_UI_V3 && IL_UI_V3.ajaxUrl && IL_UI_V3.nonce) {
            $.ajax({
                url: IL_UI_V3.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'il_ui_v3_nav',
                    nonce: IL_UI_V3.nonce,
                    view: parsed.view,
                    order_id: parsed.orderId,
                    il_edit: parsed.ilEdit
                },
                success: function(resp) {
                    if (resp && resp.success && resp.data && typeof resp.data.html === 'string') {
                        finish(resp.data.html, document.title);
                    } else {
                        console.warn('iLocker V3: AJAX endpoint returned unexpected response', resp);
                        $content.css('opacity', '1');
                        pushUrl(document.title);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('iLocker V3: AJAX endpoint error', status, error);
                    $content.css('opacity', '1');
                    pushUrl(document.title);
                }
            });
            return;
        }

        // Fallback: GET and extract content
        $.ajax({
            url: parsed.url,
            method: 'GET',
            success: function(response) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(response, 'text/html');
                var newContentEl = doc.getElementById('il-v3-content');
                var newTitleEl = doc.querySelector('title');
                var newTitle = newTitleEl ? newTitleEl.textContent : '';

                if (newContentEl) {
                    finish(newContentEl.innerHTML, newTitle);
                } else {
                    console.warn('iLocker V3: Could not find #il-v3-content in response.');
                    $content.css('opacity', '1');
                    pushUrl(newTitle);
                }
            },
            error: function(xhr, status, error) {
                console.error('iLocker V3: AJAX Error', status, error);
                $content.css('opacity', '1');
            }
        });
    }

    // Capture-phase listener: prevents full navigation even if other scripts bind click handlers.
    document.addEventListener('click', function(e) {
        var a = e.target && e.target.closest ? e.target.closest('#il-v3-wrapper .il-v3-sidebar a.il-v3-nav-item:not(.logout)') : null;
        if (!a) return;
        if (e.stopImmediatePropagation) e.stopImmediatePropagation();
        e.stopPropagation();
        e.preventDefault();
        ilV3LoadByUrl(a.getAttribute('href'), { push: true });
    }, true);

    // Handle Browser Back/Forward Buttons
    window.onpopstate = function() {
        // Load the previous/next state via AJAX (no full refresh).
        ilV3LoadByUrl(window.location.href, { push: false });
    };

});
