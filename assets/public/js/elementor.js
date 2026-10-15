/* global jQuery, elementorFrontend */
(function ($) {
	'use strict';
	let registered = false;
	function register() {
		if (registered || !window.elementorFrontend || !elementorFrontend.hooks) return;
		registered = true;
		elementorFrontend.hooks.addAction('frontend/element_ready/tbreg_form.default', scope => {
			const root = scope && scope[0];
			if (root) document.dispatchEvent(new CustomEvent('tbreg:mount', {detail: root}));
		});
	}
	$(window).on('elementor/frontend/init', register);
	register();
}(jQuery));
