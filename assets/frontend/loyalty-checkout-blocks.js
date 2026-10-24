/**
 * "Redeem loyalty points" in the Cart and Checkout blocks.
 *
 * A React component in the order summary's discounts slot. The figures come from the cart's
 * Store API data (extensions['epasscard-loyalty']); points are applied with a cart extension
 * update. Typed values survive other cart updates because React owns the input.
 */
(function () {
	'use strict';

	var wc = window.wc || {};
	var wp = window.wp || {};
	var checkout = wc.blocksCheckout;
	var element = wp.element;
	if (!checkout || !element || !wp.plugins || typeof wp.plugins.registerPlugin !== 'function') {
		return;
	}

	var NAMESPACE = 'epasscard-loyalty';
	var settings = (wc.wcSettings && typeof wc.wcSettings.getSetting === 'function')
		? wc.wcSettings.getSetting(NAMESPACE + '_data', {})
		: {};
	var i18n = settings.i18n || {};
	var sprintf = wp.i18n && wp.i18n.sprintf ? wp.i18n.sprintf : function (format) { return format; };
	var el = element.createElement;
	var useState = element.useState;
	var useEffect = element.useEffect;

	function toInt(value) {
		return parseInt(value || 0, 10) || 0;
	}

	function text(key) {
		return i18n[key] || '';
	}

	function updateCart(points) {
		var data = { namespace: NAMESPACE, data: { points: points } };
		if (typeof checkout.extensionCartUpdate === 'function') {
			return checkout.extensionCartUpdate(data);
		}
		var store = wp.data && wp.data.dispatch ? wp.data.dispatch('wc/store/cart') : null;
		if (store && typeof store.applyExtensionCartUpdate === 'function') {
			return store.applyExtensionCartUpdate(data);
		}
		return Promise.reject(new Error(text('error')));
	}

	function errorMessage(error) {
		var message = error && (error.message || (error.data && error.data.message));
		if (!message) {
			return text('error');
		}
		// Store API messages are escaped HTML text: show them as plain text.
		var holder = document.createElement('textarea');
		holder.innerHTML = String(message);
		return holder.value;
	}

	function Widget(props) {
		var quote = (props.extensions || {})[NAMESPACE] || null;
		var applied = quote ? toInt(quote.applied_points) : 0;
		var initial = String(applied > 0 ? applied : toInt(quote && quote.requested_points));

		var valueState = useState(initial);
		var busyState = useState(false);
		var statusState = useState(null);
		var value = valueState[0];
		var setValue = valueState[1];
		var busy = busyState[0];
		var setBusy = busyState[1];
		var status = statusState[0];
		var setStatus = statusState[1];

		// Follow the server only when the applied points change (apply, remove, a smaller cart).
		useEffect(function () {
			setValue(String(applied > 0 ? applied : toInt(quote && quote.requested_points)));
		}, [applied]); // eslint-disable-line react-hooks/exhaustive-deps

		if (!settings.enabled || !quote || !quote.enabled) {
			return null;
		}

		var balance = toInt(quote.balance);
		// Nothing to redeem: no empty box for members with 0 points.
		if (!quote.is_guest && balance <= 0 && applied <= 0) {
			return null;
		}

		var id = 'epc-loyalty-points-' + (props.context === 'woocommerce/cart' ? 'cart' : 'checkout');
		var children = [el('h3', { key: 'title', className: 'epc-loyalty-redemption__title' }, text('title'))];

		if (quote.is_guest) {
			children.push(
				el('p', { key: 'guest', className: 'epc-loyalty-redemption__message' },
					quote.message || text('guest'),
					quote.login_url ? ' ' : null,
					quote.login_url ? el('a', { href: quote.login_url }, text('signIn')) : null
				)
			);
			return el('div', { className: 'epc-loyalty-redemption epc-loyalty-redemption--blocks' }, children);
		}

		var line = sprintf(text('balance'), Math.max(0, balance - applied));
		if (applied > 0) {
			line += ' ' + sprintf(text('applied'), applied);
		}
		children.push(el('p', { key: 'balance', className: 'epc-loyalty-redemption__balance' }, line));

		if (quote.message && !quote.can_redeem && applied <= 0) {
			children.push(el('p', { key: 'message', className: 'epc-loyalty-redemption__message' }, quote.message));
			return el('div', { className: 'epc-loyalty-redemption epc-loyalty-redemption--blocks' }, children);
		}

		function submit(points) {
			setBusy(true);
			setStatus(null);
			updateCart(points).then(
				function () {
					setBusy(false);
					setStatus({ type: 'success', message: text('done') });
				},
				function (error) {
					setBusy(false);
					setStatus({ type: 'error', message: errorMessage(error) });
				}
			);
		}

		children.push(
			el('div', { key: 'controls', className: 'epc-loyalty-redemption__controls' },
				el('label', { htmlFor: id, className: 'screen-reader-text' }, text('label')),
				el('input', {
					id: id,
					className: 'epc-loyalty-redemption__input',
					type: 'number',
					min: 0,
					step: toInt(quote.increment) || 1,
					max: Math.max(toInt(quote.max_points), applied),
					value: value,
					disabled: busy,
					onChange: function (event) {
						setValue(event.target.value);
					},
					onKeyDown: function (event) {
						if (event.key === 'Enter') {
							event.preventDefault();
							submit(toInt(value));
						}
					}
				}),
				el('button', {
					type: 'button',
					className: 'button wp-element-button epc-loyalty-redemption__apply',
					disabled: busy,
					onClick: function () {
						submit(toInt(value));
					}
				}, text('apply')),
				el('button', {
					type: 'button',
					className: 'button wp-element-button epc-loyalty-redemption__remove',
					disabled: busy || applied <= 0,
					onClick: function () {
						setValue('0');
						submit(0);
					}
				}, text('remove'))
			)
		);

		children.push(
			el('p', { key: 'hint', className: 'epc-loyalty-redemption__hint' },
				sprintf(text('hint'), String(quote.points_per_currency), String(quote.currency || ''), toInt(quote.max_points))
			)
		);
		children.push(
			el('p', {
				key: 'status',
				className: 'epc-loyalty-redemption__status' + (status ? ' is-' + status.type : ''),
				role: status && status.type === 'error' ? 'alert' : 'status'
			}, status ? status.message : '')
		);

		return el('div', { className: 'epc-loyalty-redemption epc-loyalty-redemption--blocks' }, children);
	}

	var Slot = checkout.ExperimentalDiscountsMeta;
	if (Slot) {
		wp.plugins.registerPlugin('epasscard-loyalty-redemption', {
			scope: 'woocommerce-checkout',
			render: function () {
				// The fill clones its child with { extensions, cart, context }.
				return el(Slot, null, el(Widget));
			}
		});
	}

	// Show "Loyalty points" instead of the internal coupon code (epc-loyalty-<id>).
	if (typeof checkout.registerCheckoutFilters === 'function') {
		var prefix = settings.couponPrefix || 'epc-loyalty-';
		checkout.registerCheckoutFilters(NAMESPACE, {
			coupons: function (coupons) {
				return (coupons || []).map(function (coupon) {
					if (coupon && typeof coupon.code === 'string' && coupon.code.indexOf(prefix) === 0) {
						return Object.assign({}, coupon, { label: text('couponLabel') || coupon.label });
					}
					return coupon;
				});
			}
		});
	}
})();
