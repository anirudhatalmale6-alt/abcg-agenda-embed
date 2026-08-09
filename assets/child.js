/* Runs inside the embedded agenda page and reports its real content height. */
(function () {
	'use strict';

	var BUFFER = 16;
	var last = -1;
	var frameId = '';

	try {
		var m = /[?&]abcgid=([^&#]+)/.exec(window.location.search);
		if (m) {
			frameId = decodeURIComponent(m[1]);
		}
	} catch (e) {}

	/*
	 * Measured from the lowest visible element edge, never from body/html
	 * scrollHeight: those stretch to fill the iframe viewport, so feeding them
	 * back as the new iframe height makes it grow without ever settling.
	 * Hidden elements are skipped or the invisible "Lire plus" dialog would
	 * inflate the height on every load.
	 */
	function measure() {
		var body = document.body;
		if (!body) {
			return 0;
		}

		var offset = window.pageYOffset || 0;
		var max = 0;
		var els = body.querySelectorAll('*');

		for (var i = 0; i < els.length; i++) {
			var el = els[i];
			var cs;
			try {
				cs = window.getComputedStyle(el);
			} catch (e) {
				continue;
			}
			if (!cs || cs.display === 'none' || cs.visibility === 'hidden' || cs.position === 'fixed') {
				continue;
			}

			var rect = el.getBoundingClientRect();
			if (!rect.height && !rect.width) {
				continue;
			}

			var bottom = rect.bottom + offset;
			var mb = parseFloat(cs.marginBottom);
			if (!isNaN(mb) && mb > 0) {
				bottom += mb;
			}
			if (bottom > max) {
				max = bottom;
			}
		}

		var bodyStyle = window.getComputedStyle(body);
		max += parseFloat(bodyStyle.marginBottom) || 0;
		max += parseFloat(bodyStyle.paddingBottom) || 0;

		return max > 0 ? Math.ceil(max) + BUFFER : 0;
	}

	function send(force) {
		var h = measure();
		if (!h) {
			return;
		}
		if (!force && Math.abs(h - last) < 2) {
			return;
		}
		last = h;
		try {
			parent.postMessage(
				{ __abcgEmbed: 1, id: frameId, height: h },
				window.location.origin
			);
		} catch (e) {}
	}

	function ping() {
		send(false);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			send(true);
		});
	} else {
		send(true);
	}

	window.addEventListener('load', function () {
		send(true);
	});
	window.addEventListener('resize', ping);

	// Late reflows: web fonts, images, and the ASP.NET postback that opens the
	// detail box all change the height after the initial paint.
	var fast = setInterval(ping, 250);
	setTimeout(function () {
		clearInterval(fast);
		setInterval(ping, 1000);
	}, 6000);

	if (window.ResizeObserver && document.body) {
		try {
			new ResizeObserver(ping).observe(document.body);
		} catch (e) {}
	}

	if (window.MutationObserver && document.body) {
		try {
			new MutationObserver(ping).observe(document.body, {
				childList: true,
				subtree: true,
				attributes: true,
				attributeFilter: ['style', 'class']
			});
		} catch (e) {}
	}

	var imgs = document.images || [];
	for (var j = 0; j < imgs.length; j++) {
		if (!imgs[j].complete) {
			imgs[j].addEventListener('load', ping);
			imgs[j].addEventListener('error', ping);
		}
	}
})();
