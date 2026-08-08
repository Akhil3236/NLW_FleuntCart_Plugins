/* Mollie Subscription Manager — admin-combined.js
 * Loaded in <head>, wraps in jQuery ready so DOM, jQuery and MSM are ready.
 * Two responsibilities:
 *   1. Dashboard form on the plugin's own admin page
 *   2. Inline reactivation card injected into the FluentCart subscription detail view
 */
jQuery(function ($) {
    'use strict';

    var AJAX     = MSM.ajax_url;
    var REST     = MSM.rest_url;
    var NONCE    = MSM.nonce;
    var WP_NONCE = MSM.wp_nonce;
    var TODAY    = MSM.today;
    var DEF_DESC = MSM.def_desc || 'Monthly Subscription';
    var IS_FC    = MSM.is_fc === '1';

    function ajaxPost(action, data) {
        return $.post(AJAX, $.extend({ action: action, nonce: NONCE }, data));
    }
    function ok(msg, extra) {
        return '<div class="msm-result-ok"><strong>' + msg + '</strong>' + (extra ? '<div class="msm-result-details">' + extra + '</div>' : '') + '</div>';
    }
    function err(msg) { return '<div class="msm-result-err">' + msg + '</div>'; }

    // ─── Dashboard: Manual Reactivate ────────────────────────────────────────
    $('#msm-dash-reactivate-btn').on('click', function () {
        var $btn    = $(this);
        var $result = $('#msm-dash-result');
        var fc_id   = $('#msm-fc-id').val().trim();
        var start   = $('#msm-dash-start').val().trim();
        var amount  = $('#msm-dash-amount').val().trim();

        if (!fc_id) { $result.html(err('Please enter a Subscription ID.')); return; }
        if (!start) { $result.html(err('Please select a start date.')); return; }
        if (!confirm('Reactivate subscription #' + fc_id + '?\n\nStart: ' + start + '\nNo payment required from the customer.')) return;

        $btn.prop('disabled', true).text('Processing...');
        $result.html('<div class="msm-spinner">Checking mandate and creating subscription...</div>');

        ajaxPost('msm_reactivate', { fc_id: fc_id, start_date: start, amount: amount })
            .then(function (res) {
                if (res.success) {
                    var d = res.data;
                    $result.html(ok('Subscription reactivated',
                        'Mollie ID: <code>' + d.new_mollie_sub_id + '</code><br>' +
                        'Mandate: <code>' + d.mandate_id + '</code><br>' +
                        'Start: ' + d.start_date + ' &middot; Next: <strong>' + d.next_payment + '</strong><br>' +
                        'Amount: ' + d.amount));
                    $btn.text('Done');
                } else {
                    $result.html(err(res.data));
                    $btn.prop('disabled', false).text('Reactivate Subscription');
                }
            })
            .fail(function () {
                $result.html(err('Server error. Please try again.'));
                $btn.prop('disabled', false).text('Reactivate Subscription');
            });
    });

    // ─── Logs: clear ─────────────────────────────────────────────────────────
    $('#msm-clear-logs').on('click', function () {
        if (!confirm('Clear all logs?')) return;
        ajaxPost('msm_clear_logs').then(function (res) { if (res.success) location.reload(); });
    });

    // =========================================================================
    //  FLUENTCART INLINE REACTIVATION CARD
    //  URL pattern: admin.php?page=fluent-cart#/subscriptions/N/view
    // =========================================================================
    if (!IS_FC) return;

    var SUB_HASH    = /#\/subscriptions\/(\d+)\/view/;
    var CARD_ID     = 'msm-reactivate-card';
    var currentFcId = 0;
    var lastHash    = '';
    var debounce    = null;

    function getFcId() {
        var m = (window.location.hash || '').match(SUB_HASH);
        return m ? parseInt(m[1], 10) : 0;
    }

    function buildCard(fcId, info) {
        var card = document.createElement('div');
        card.id        = CARD_ID;
        card.className = 'msm-fc-card';
        card.dataset.subscriptionId = String(fcId);

        var bodyHtml = '';

        // ── Header always shows ──
        var headerHtml =
            '<div class="msm-fc-card-header">' +
                '<span class="msm-fc-title">Mollie Subscription Reactivation</span>' +
            '</div>';

        // ── Build body based on subscription state ──
        if (!info.is_mollie) {
            bodyHtml =
                '<div class="msm-fc-notice msm-fc-notice-info">' +
                'This subscription does not use Mollie as its payment gateway. Reactivation is only available for Mollie subscriptions.' +
                '</div>';
        } else if (info.status === 'active' || info.status === 'pending') {
            bodyHtml =
                '<div class="msm-fc-notice msm-fc-notice-success">' +
                'This subscription is currently <strong>' + info.status + '</strong>. No reactivation needed.' +
                '</div>';
        } else if (!info.has_mandate) {
            bodyHtml =
                '<div class="msm-fc-notice msm-fc-notice-warning">' +
                '<strong>No active mandate found.</strong><br>' +
                'The customer needs to complete a payment first. After their next successful payment, this subscription will be reactivated automatically — or you can do it manually here once a mandate is created.' +
                '</div>';
        } else {
            // Eligible for manual reactivation
            bodyHtml =
                '<p class="msm-fc-desc">This subscription is <strong>cancelled</strong> but the customer has a valid Mollie mandate. You can reactivate it without requiring a new payment from the customer.</p>' +
                '<div class="msm-fc-meta">' +
                    '<div class="msm-fc-meta-row"><span>Customer ID</span><code>' + info.customer_id + '</code></div>' +
                    '<div class="msm-fc-meta-row"><span>Active mandate</span><code>' + info.mandate_id + '</code></div>' +
                '</div>' +
                '<div class="msm-fc-form">' +
                    '<div class="msm-fc-field">' +
                        '<label for="msm-fc-start">Start date</label>' +
                        '<input type="date" id="msm-fc-start" value="' + TODAY + '" min="' + TODAY + '" class="msm-fc-input" />' +
                        '<small class="msm-fc-hint">First charge date. Sets the recurring billing cycle.</small>' +
                    '</div>' +
                    '<div class="msm-fc-field">' +
                        '<label for="msm-fc-amount">Amount</label>' +
                        '<div class="msm-fc-amount-row">' +
                            '<input type="text" id="msm-fc-amount" value="' + (info.amount || '') + '" placeholder="29.95" class="msm-fc-input" />' +
                            '<span class="msm-fc-currency">' + (info.currency || 'EUR') + '</span>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="msm-fc-actions">' +
                    '<button type="button" class="msm-fc-btn-primary" id="msm-fc-submit">Reactivate Subscription</button>' +
                '</div>' +
                '<div id="msm-fc-result" class="msm-fc-result"></div>';
        }

        card.innerHTML = headerHtml + '<div class="msm-fc-card-body">' + bodyHtml + '</div>';

        // Wire up the submit button if present
        var btn = card.querySelector('#msm-fc-submit');
        if (btn) {
            btn.addEventListener('click', function () {
                var start  = card.querySelector('#msm-fc-start').value;
                var amount = card.querySelector('#msm-fc-amount').value.trim();
                var $res   = $(card.querySelector('#msm-fc-result'));

                if (!start)  { $res.html(err('Please select a start date.')); return; }
                if (!amount || isNaN(amount) || parseFloat(amount) <= 0) {
                    $res.html(err('Please enter a valid amount.')); return;
                }
                if (!confirm('Reactivate subscription #' + fcId + '?\n\nStart: ' + start + '\nAmount: ' + amount + ' ' + (info.currency || 'EUR') + '\n\nThe customer will not be asked to pay again now.')) return;

                btn.disabled = true;
                btn.textContent = 'Reactivating...';
                $res.html('<div class="msm-spinner">Creating subscription on Mollie...</div>');

                ajaxPost('msm_reactivate', { fc_id: fcId, start_date: start, amount: amount })
                    .then(function (r) {
                        if (r.success) {
                            var d = r.data;
                            $res.html(ok('Subscription reactivated',
                                'New Mollie ID: <code>' + d.new_mollie_sub_id + '</code><br>' +
                                'Mandate: <code>' + d.mandate_id + '</code><br>' +
                                'Start: ' + d.start_date + ' &middot; Next payment: <strong>' + d.next_payment + '</strong>'
                            ));
                            btn.textContent = 'Done';
                            // Reload after a delay so FluentCart UI shows new status
                            setTimeout(function () { location.reload(); }, 2500);
                        } else {
                            $res.html(err(r.data));
                            btn.disabled = false;
                            btn.textContent = 'Reactivate Subscription';
                        }
                    })
                    .fail(function () {
                        $res.html(err('Server error. Please try again.'));
                        btn.disabled = false;
                        btn.textContent = 'Reactivate Subscription';
                    });
            });
        }

        return card;
    }

    function removeCard() {
        var el = document.getElementById(CARD_ID);
        if (el) el.remove();
    }

    function tryInsertCard(card) {
        // Look for FluentCart's subscription main container
        var main = document.querySelector('.fct-single-subscription-main');
        if (main) {
            var first = main.firstElementChild;
            if (first) first.parentNode.insertBefore(card, first.nextSibling);
            else main.insertBefore(card, main.firstChild);
            return true;
        }
        return false;
    }

    function injectCard(fcId) {
        var existing = document.getElementById(CARD_ID);
        if (existing && existing.dataset.subscriptionId === String(fcId)) return;
        removeCard();

        ajaxPost('msm_get_sub_info', { fc_id: fcId }).then(function (res) {
            if (getFcId() !== fcId) return;
            if (!res.success) return;

            var card = buildCard(fcId, res.data);
            var attempts = 0;
            function attempt() {
                if (getFcId() !== fcId) return;
                if (document.getElementById(CARD_ID)) return;
                if (tryInsertCard(card)) return;
                if (++attempts < 30) setTimeout(attempt, 150);
            }
            setTimeout(attempt, 100);
        });
    }

    // ─── Route watcher (SPA navigation) ──────────────────────────────────────
    function onRouteChange() {
        var hash = window.location.hash || '';
        if (hash === lastHash) return;
        lastHash = hash;
        var fcId = getFcId();
        removeCard();
        clearTimeout(debounce);
        if (!fcId) { currentFcId = 0; return; }
        debounce = setTimeout(function () {
            currentFcId = fcId;
            injectCard(fcId);
        }, 200);
    }

    window.addEventListener('hashchange', onRouteChange);
    window.addEventListener('popstate',   onRouteChange);
    new MutationObserver(function () { onRouteChange(); }).observe(document.body, { childList: true, subtree: true });
    onRouteChange();
});
