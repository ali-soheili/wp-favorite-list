/* Favorite List — settings page */
jQuery(function ($) {
	'use strict';

	var cfg = window.FLAdmin || {};

	// Live icon preview.
	$('.fl-icon-input').on('input change', function () {
		var $img = $(this).closest('.fl-icon-field').find('.fl-icon-preview img');
		$img.attr('src', this.value.trim() || $img.data('default'));
	});

	// Pick an icon from the Media Library (bundled with WordPress, no internet needed).
	$('.fl-pick-icon').on('click', function (e) {
		e.preventDefault();
		var $input = $(this).closest('.fl-icon-field').find('.fl-icon-input');
		if (!window.wp || !wp.media) { $input.trigger('focus'); return; }

		var frame = wp.media({
			title: cfg.chooseIcon,
			button: { text: cfg.useIcon },
			multiple: false
		});
		frame.on('select', function () {
			var file = frame.state().get('selection').first().toJSON();
			$input.val(file.url).trigger('change');
		});
		frame.open();
	});

	// Copy code snippets. navigator.clipboard needs HTTPS, so fall back for http:// intranet sites.
	$('.fl-copy').on('click', function () {
		var $btn = $(this);
		var text = $('#' + $btn.data('target')).text();

		function done() {
			$btn.text(cfg.copied);
			setTimeout(function () { $btn.text(cfg.copy); }, 1500);
		}

		function fallback() {
			var $t = $('<textarea readonly>').val(text).css({ position: 'fixed', top: 0, left: 0, opacity: 0 }).appendTo('body');
			$t[0].select();
			try { document.execCommand('copy'); done(); } catch (err) {}
			$t.remove();
		}

		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done, fallback);
		} else {
			fallback();
		}
	});
});
