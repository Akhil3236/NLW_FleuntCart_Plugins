/**
 * FluentCart Webhook Retry — admin JS.
 *
 * On the FluentCart order page (Vue-rendered), watches the DOM for the
 * "Webhook failed" banner and injects a Retry button next to it.
 *
 * On our own admin pages, drives the list/table UI.
 */
(function () {
	'use strict';

	if (!window.FCWR) {
		return;
	}

	const FCWR = window.FCWR;

	// ---------------------------------------------------------------------
	// Tiny helpers
	// ---------------------------------------------------------------------

	const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

	function api(path, opts = {}) {
		return wp.apiFetch({
			url: FCWR.restRoot + path,
			method: opts.method || 'GET',
			data: opts.data,
			headers: {
				'X-WP-Nonce': FCWR.nonce,
			},
		});
	}

	function toast(message, type = 'info') {
		const el = document.createElement('div');
		el.className = 'fcwr-toast fcwr-toast-' + type;
		el.textContent = message;
		document.body.appendChild(el);
		// Force reflow so the transition fires.
		void el.offsetWidth;
		el.classList.add('fcwr-toast-show');

		setTimeout(() => {
			el.classList.remove('fcwr-toast-show');
			setTimeout(() => el.remove(), 300);
		}, 3500);
	}

	function escapeHtml(s) {
		if (s === null || s === undefined) return '';
		return String(s).replace(/[&<>"']/g, c => ({
			'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
		}[c]));
	}

	// ---------------------------------------------------------------------
	// 1) Inject retry button on FluentCart order pages
	// ---------------------------------------------------------------------

	/**
	 * Pull the order ID from the FluentCart hash URL: `#/orders/357/view`
	 */
	function currentOrderId() {
		const m = (location.hash || '').match(/\/orders\/(\d+)/);
		return m ? parseInt(m[1], 10) : null;
	}

	/**
	 * Heuristically detect FluentCart's webhook error banner.
	 * FluentCart renders a red message that contains both "webhook" and "failed".
	 * Adjust the selectors here if FluentCart changes its markup.
	 */
	function findWebhookErrorBanner() {
		const candidates = $$('.el-message--error, .el-alert--error, .el-message, .fc-alert, [class*="error"], [class*="alert"]');
		return candidates.filter(el => {
			if (el.dataset.fcwrProcessed) return false;
			const text = (el.textContent || '').toLowerCase();
			return text.includes('webhook') && text.includes('fail');
		});
	}

	/**
	 * Find rows in the order Activity timeline that describe a failed webhook.
	 */
	function findActivityFailureRows() {
		// Activity entries vary across themes — match anything that contains both keywords.
		const all = $$('div, li, tr').filter(el => {
			if (el.dataset.fcwrActivityProcessed) return false;
			if (el.children.length > 6) return false; // skip large containers
			const text = (el.textContent || '').toLowerCase();
			return text.includes('webhook') && (text.includes('failed') || text.includes('fail'));
		});
		return all;
	}

	/**
	 * Find the most recent failed webhook log for an order.
	 *
	 * @returns Promise<object|null>
	 */
	function findLatestFailedLogForOrder(orderId) {
		return api('/logs?status=failed&order_id=' + encodeURIComponent(orderId) + '&per_page=1')
			.then(res => (res && res.items && res.items.length) ? res.items[0] : null);
	}

	/**
	 * Build the retry button element.
	 */
	function buildRetryButton(orderId, options = {}) {
		const btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'fcwr-retry-btn ' + (options.size === 'sm' ? 'fcwr-retry-btn-sm' : '');
		btn.innerHTML = `
			<span class="fcwr-retry-icon" aria-hidden="true">↻</span>
			<span class="fcwr-retry-label">${escapeHtml(FCWR.i18n.retry)}</span>
		`;
		btn.addEventListener('click', () => handleRetryClick(btn, orderId, options.logId));
		return btn;
	}

	/**
	 * Click handler.
	 */
	async function handleRetryClick(btn, orderId, logIdOverride) {
		if (btn.disabled) return;

		const labelEl = btn.querySelector('.fcwr-retry-label');
		const originalLabel = labelEl ? labelEl.textContent : '';

		btn.disabled = true;
		btn.classList.add('fcwr-loading');
		if (labelEl) labelEl.textContent = FCWR.i18n.retrying;

		try {
			let logId = logIdOverride;
			if (!logId) {
				const log = await findLatestFailedLogForOrder(orderId);
				if (!log) {
					toast(FCWR.i18n.noFailedWebhook, 'error');
					return;
				}
				logId = log.id;
			}

			const result = await api('/logs/' + logId + '/retry', { method: 'POST' });

			if (result && result.success) {
				toast(result.message || FCWR.i18n.success, 'success');
				btn.classList.add('fcwr-success');
				// Optional: refresh page to update FluentCart's own state.
				setTimeout(() => window.location.reload(), 1200);
			} else {
				toast((result && result.message) || FCWR.i18n.failed, 'error');
			}
		} catch (err) {
			let message = FCWR.i18n.failed;
			if (err && err.message) message = err.message;
			toast(message, 'error');
		} finally {
			btn.disabled = false;
			btn.classList.remove('fcwr-loading');
			if (labelEl) labelEl.textContent = originalLabel;
		}
	}

	/**
	 * Inject the retry button next to a banner.
	 */
	function injectButtonForBanner(banner, orderId) {
		banner.dataset.fcwrProcessed = '1';

		const wrapper = document.createElement('span');
		wrapper.className = 'fcwr-banner-button-wrapper';
		wrapper.appendChild(buildRetryButton(orderId));

		// Try to place inside the banner if there's room; otherwise after it.
		const target = banner.querySelector('.el-message__content, .el-alert__content') || banner;

		if (target === banner) {
			banner.insertAdjacentElement('beforeend', wrapper);
		} else {
			target.appendChild(wrapper);
		}
	}

	/**
	 * Inject smaller retry button in an Activity row.
	 */
	function injectButtonForActivityRow(row, orderId) {
		row.dataset.fcwrActivityProcessed = '1';

		const wrapper = document.createElement('span');
		wrapper.className = 'fcwr-activity-button-wrapper';
		wrapper.appendChild(buildRetryButton(orderId, { size: 'sm' }));

		row.appendChild(wrapper);
	}

	/**
	 * Scan and inject buttons everywhere they belong on the current page.
	 */
	function scanAndInject() {
		const orderId = currentOrderId();
		if (!orderId) return;

		findWebhookErrorBanner().forEach(banner => injectButtonForBanner(banner, orderId));
		findActivityFailureRows().forEach(row => injectButtonForActivityRow(row, orderId));
	}

	/**
	 * Boot the DOM observer for FluentCart's order page.
	 */
	function bootFCObserver() {
		if (!FCWR.isFCPage) return;

		// Initial scan.
		scanAndInject();

		// React to FluentCart's Vue navigation + dynamic content updates.
		const observer = new MutationObserver(() => {
			// Throttle by using requestAnimationFrame.
			if (window.__fcwrPending) return;
			window.__fcwrPending = true;
			requestAnimationFrame(() => {
				window.__fcwrPending = false;
				scanAndInject();
			});
		});

		observer.observe(document.body, { childList: true, subtree: true });

		// Also rescan on hash changes (FluentCart uses hash routing).
		window.addEventListener('hashchange', () => setTimeout(scanAndInject, 300));
	}

	// ---------------------------------------------------------------------
	// 2) Logs admin page
	// ---------------------------------------------------------------------

	function bootLogsPage() {
		const root = document.getElementById('fcwr-logs-app');
		if (!root) return;

		const state = {
			items: [],
			total: 0,
			page: 1,
			perPage: 20,
			status: 'failed',
		};

		function render() {
			const rows = state.items.map(item => `
				<tr data-id="${item.id}">
					<td>${item.id}</td>
					<td>${item.order_id ?? '—'}</td>
					<td><code>${escapeHtml(item.url)}</code></td>
					<td>${item.method}</td>
					<td>${item.response_code ?? '—'}</td>
					<td>
						<span class="fcwr-status fcwr-status-${item.status}">
							${escapeHtml(item.status)}
						</span>
					</td>
					<td>${item.retry_count}</td>
					<td>${escapeHtml(item.created_at)}</td>
					<td class="fcwr-row-actions">
						<button class="button button-primary fcwr-row-retry" data-id="${item.id}">↻ Retry</button>
						<button class="button fcwr-row-view" data-id="${item.id}">View</button>
						<button class="button fcwr-row-delete" data-id="${item.id}">Delete</button>
					</td>
				</tr>
			`).join('');

			const totalPages = Math.max(1, Math.ceil(state.total / state.perPage));

			root.innerHTML = `
				<div class="fcwr-toolbar">
					<select id="fcwr-status-filter">
						<option value="failed" ${state.status === 'failed' ? 'selected' : ''}>Failed only</option>
						<option value="success" ${state.status === 'success' ? 'selected' : ''}>Success only</option>
						<option value="all" ${state.status === 'all' ? 'selected' : ''}>All</option>
					</select>
					<span class="fcwr-total">${state.total} entries</span>
				</div>
				<table class="widefat striped fcwr-table">
					<thead>
						<tr>
							<th>ID</th>
							<th>Order</th>
							<th>URL</th>
							<th>Method</th>
							<th>Code</th>
							<th>Status</th>
							<th>Retries</th>
							<th>Created</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						${rows || '<tr><td colspan="9" class="fcwr-empty">No webhook logs yet.</td></tr>'}
					</tbody>
				</table>
				<div class="fcwr-pagination">
					<button class="button" id="fcwr-prev" ${state.page <= 1 ? 'disabled' : ''}>Prev</button>
					<span>Page ${state.page} / ${totalPages}</span>
					<button class="button" id="fcwr-next" ${state.page >= totalPages ? 'disabled' : ''}>Next</button>
				</div>
				<div id="fcwr-detail-panel" class="fcwr-detail-panel" style="display:none;"></div>
			`;

			wireEvents();
		}

		function wireEvents() {
			const filter = document.getElementById('fcwr-status-filter');
			if (filter) {
				filter.addEventListener('change', e => {
					state.status = e.target.value;
					state.page = 1;
					load();
				});
			}

			const prev = document.getElementById('fcwr-prev');
			if (prev) prev.addEventListener('click', () => { state.page--; load(); });

			const next = document.getElementById('fcwr-next');
			if (next) next.addEventListener('click', () => { state.page++; load(); });

			$$('.fcwr-row-retry', root).forEach(btn => {
				btn.addEventListener('click', () => retryById(parseInt(btn.dataset.id, 10), btn));
			});

			$$('.fcwr-row-view', root).forEach(btn => {
				btn.addEventListener('click', () => showDetail(parseInt(btn.dataset.id, 10)));
			});

			$$('.fcwr-row-delete', root).forEach(btn => {
				btn.addEventListener('click', () => deleteRow(parseInt(btn.dataset.id, 10)));
			});
		}

		async function load() {
			root.classList.add('fcwr-loading-page');
			try {
				const res = await api(`/logs?status=${state.status}&page=${state.page}&per_page=${state.perPage}`);
				state.items = res.items || [];
				state.total = res.total || 0;
				render();
			} catch (err) {
				root.innerHTML = `<div class="notice notice-error"><p>${escapeHtml(err.message || 'Failed to load.')}</p></div>`;
			} finally {
				root.classList.remove('fcwr-loading-page');
			}
		}

		async function retryById(id, btn) {
			if (btn) {
				btn.disabled = true;
				btn.textContent = FCWR.i18n.retrying;
			}
			try {
				const result = await api('/logs/' + id + '/retry', { method: 'POST' });
				toast(result.message || (result.success ? FCWR.i18n.success : FCWR.i18n.failed),
					result.success ? 'success' : 'error');
				await load();
			} catch (err) {
				toast(err.message || FCWR.i18n.failed, 'error');
				if (btn) {
					btn.disabled = false;
					btn.textContent = '↻ Retry';
				}
			}
		}

		async function showDetail(id) {
			const panel = document.getElementById('fcwr-detail-panel');
			panel.style.display = 'block';
			panel.innerHTML = '<p>Loading…</p>';

			try {
				const log = await api('/logs/' + id);
				panel.innerHTML = `
					<h3>Log #${log.id}</h3>
					<table class="form-table">
						<tr><th>URL</th><td><code>${escapeHtml(log.url)}</code></td></tr>
						<tr><th>Method</th><td>${log.method}</td></tr>
						<tr><th>Response code</th><td>${log.response_code ?? '—'}</td></tr>
						<tr><th>Error</th><td>${escapeHtml(log.error_message || '—')}</td></tr>
						<tr><th>Created</th><td>${escapeHtml(log.created_at)}</td></tr>
						<tr><th>Retry count</th><td>${log.retry_count}</td></tr>
						<tr><th>Request headers</th><td><pre>${escapeHtml(JSON.stringify(log.request_headers, null, 2))}</pre></td></tr>
						<tr><th>Request body</th><td><pre>${escapeHtml(log.request_body || '')}</pre></td></tr>
						<tr><th>Response body</th><td><pre>${escapeHtml(log.response_body || '')}</pre></td></tr>
					</table>
					<button class="button" id="fcwr-close-detail">Close</button>
				`;
				document.getElementById('fcwr-close-detail').addEventListener('click', () => {
					panel.style.display = 'none';
				});
			} catch (err) {
				panel.innerHTML = `<div class="notice notice-error"><p>${escapeHtml(err.message)}</p></div>`;
			}
		}

		async function deleteRow(id) {
			if (!window.confirm(FCWR.i18n.confirmDelete)) return;
			try {
				await api('/logs/' + id, { method: 'DELETE' });
				toast('Deleted.', 'success');
				await load();
			} catch (err) {
				toast(err.message || 'Delete failed.', 'error');
			}
		}

		load();
	}

	// ---------------------------------------------------------------------
	// Boot
	// ---------------------------------------------------------------------

	function boot() {
		if (FCWR.isFCPage) {
			bootFCObserver();
		}
		if (FCWR.isFCWRPage) {
			bootLogsPage();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
