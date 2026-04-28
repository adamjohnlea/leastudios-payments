/* global wp, leastudiosPaymentsBlock */
(function () {
	'use strict';

	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var ServerSideRender = wp.serverSideRender || wp.components.ServerSideRender;
	var Placeholder = wp.components.Placeholder;

	var products = (leastudiosPaymentsBlock && leastudiosPaymentsBlock.products) || [];

	// Build options for the product/price picker.
	function buildPriceOptions() {
		var options = [{ label: '-- Select a product/price --', value: '0' }];

		products.forEach(function (product) {
			product.prices.forEach(function (price) {
				var amount = formatAmount(price.amount, price.currency);
				var suffix = price.type === 'recurring' && price.interval ? '/' + price.interval : '';
				options.push({
					label: product.name + ' — ' + amount + suffix,
					value: String(price.id)
				});
			});
		});

		return options;
	}

	function formatAmount(cents, currency) {
		var symbols = { usd: '$', gbp: '£', eur: '€', cad: 'CA$', aud: 'A$', nzd: 'NZ$', chf: 'CHF ', jpy: '¥' };
		var symbol = symbols[currency] || currency.toUpperCase() + ' ';
		if (currency === 'jpy') {
			return symbol + cents;
		}
		return symbol + (cents / 100).toFixed(2);
	}

	registerBlockType('leastudios-payments/checkout', {
		title: 'leaStudios Payment',
		description: 'Embed a Stripe checkout for a product.',
		category: 'widgets',
		icon: 'money-alt',
		supports: {
			html: false,
			multiple: true
		},

		edit: function (props) {
			var priceId = props.attributes.priceId || 0;
			var priceOptions = buildPriceOptions();

			var inspectorPanel = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: 'Payment Settings', initialOpen: true },
					el(SelectControl, {
						label: 'Product / Price',
						value: String(priceId),
						options: priceOptions,
						onChange: function (val) {
							props.setAttributes({ priceId: parseInt(val, 10) });
						}
					})
				)
			);

			var content;
			if (priceId > 0) {
				content = el(ServerSideRender, {
					block: 'leastudios-payments/checkout',
					attributes: props.attributes
				});
			} else {
				content = el(
					Placeholder,
					{
						icon: 'money-alt',
						label: 'leaStudios Payment',
						instructions: 'Select a product and price from the block settings panel.'
					}
				);
			}

			return el('div', null, inspectorPanel, content);
		},

		save: function () {
			return null;
		}
	});
})();
