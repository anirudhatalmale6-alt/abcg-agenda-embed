/* Sizes the agenda iframes to the height their content reports. */
(function () {
	'use strict';

	if (window.__abcgEmbedParent) {
		return;
	}
	window.__abcgEmbedParent = true;

	var MIN_HEIGHT = 60;

	function frames() {
		return document.querySelectorAll('iframe.abcg-embed-frame');
	}

	/* Matching on contentWindow keeps working after an ASP.NET postback, which
	   reloads the frame on a URL that no longer carries our id. */
	function frameFor(source, id) {
		var list = frames();
		for (var i = 0; i < list.length; i++) {
			if (list[i].contentWindow === source) {
				return list[i];
			}
		}
		return id ? document.getElementById(id) : null;
	}

	function apply(frame, height) {
		if (!frame) {
			return;
		}
		var h = Math.max(MIN_HEIGHT, Math.round(height));
		if (frame.getAttribute('data-abcg-h') === String(h)) {
			return;
		}
		frame.setAttribute('data-abcg-h', String(h));
		frame.setAttribute('height', String(h));
		frame.style.height = h + 'px';
	}

	window.addEventListener(
		'message',
		function (event) {
			if (event.origin !== window.location.origin) {
				return;
			}
			var data = event.data;
			if (!data || data.__abcgEmbed !== 1) {
				return;
			}
			apply(frameFor(event.source, data.id), data.height);
		},
		false
	);

	// Safety net for the first paint, in case a message lands before this runs.
	function sweep() {
		var list = frames();
		for (var i = 0; i < list.length; i++) {
			var frame = list[i];
			if (frame.getAttribute('data-abcg-h')) {
				continue;
			}
			try {
				var doc = frame.contentDocument;
				if (!doc || !doc.body) {
					continue;
				}
				var h = doc.body.scrollHeight || 0;
				if (h > 0) {
					apply(frame, h + 16);
				}
			} catch (e) {}
		}
	}

	var sweeps = setInterval(sweep, 500);
	setTimeout(function () {
		clearInterval(sweeps);
	}, 10000);
})();
