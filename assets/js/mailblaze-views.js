(function () {
    var cfg = window.mailblazeViews;
    if (!cfg || !cfg.endpoint || !cfg.views || !cfg.views.length) {
        return;
    }

    var windowMs = cfg.throttleMs || 1800000;
    var storageKey = 'mb_product_views';

    function loadSent() {
        try {
            return JSON.parse(window.sessionStorage.getItem(storageKey) || '{}');
        } catch (err) {
            return {};
        }
    }

    function remember(key) {
        var sent = loadSent();
        sent[key] = Date.now();
        try {
            window.sessionStorage.setItem(storageKey, JSON.stringify(sent));
        } catch (err) {}
    }

    function fresh(key) {
        var sent = loadSent();
        return !sent[key] || (Date.now() - sent[key]) > windowMs;
    }

    function keyFor(view) {
        var cats = (view.category_ids || []).slice().map(String).sort().join(',');
        return [view.page || 'product', view.product_id || '', view.variant_id || '', cats].join('|');
    }

    function post(views) {
        var pending = [];
        views.forEach(function (view) {
            var key = keyFor(view);
            if (!fresh(key)) {
                return;
            }
            pending.push({
                page: view.page || 'product',
                product_id: view.product_id ? String(view.product_id) : '',
                variant_id: view.variant_id ? String(view.variant_id) : '',
                category_ids: view.category_ids || []
            });
            remember(key);
        });
        if (!pending.length || !window.fetch) {
            return;
        }
        var body = JSON.stringify({ views: pending.slice(0, 10) });
        function deliver(attempt) {
            window.fetch(cfg.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cfg.nonce || ''
                },
                body: body
            }).then(function (response) {
                if ((!response || !response.ok) && attempt < 1) {
                    deliver(attempt + 1);
                }
            }).catch(function () {
                if (attempt < 1) {
                    deliver(attempt + 1);
                }
            });
        }
        deliver(0);
    }

    post(cfg.views);

    if (window.jQuery && cfg.productId) {
        window.jQuery(document.body).on('found_variation', 'form.variations_form', function (event, variation) {
            if (!variation || !variation.variation_id) {
                return;
            }
            post([{
                page: 'product',
                product_id: String(cfg.productId),
                variant_id: String(variation.variation_id),
                category_ids: cfg.categoryIds || []
            }]);
        });
    }
}());
