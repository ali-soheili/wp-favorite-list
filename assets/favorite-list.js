/* Favorite List — front end */
(function () {
	'use strict';

	var cfg = window.FavoriteList;
	if (!cfg) { return; }

	var ids = new Set((cfg.favorites || []).map(Number));
	var nonce = cfg.nonce;
	var loggedIn = !!cfg.loggedIn;
	var seq = {};        // latest request number per post (ignores out-of-order replies)
	var inFlight = 0;

	var SELECTOR = '.fl-favorite-btn[data-post-id]';

	function request(data) {
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: new URLSearchParams(data).toString()
		}).then(function (r) {
			return r.json().catch(function () { return { success: false, data: { code: 'bad_response' } }; });
		});
	}

	function goToLogin(url) {
		url = url || cfg.loginUrl;
		window.location.href = url.split('{current_url}').join(encodeURIComponent(window.location.href));
	}

	/* ---------- markup ---------- */

	// Hand-written links (<a class="fl-favorite-btn"><img></a>) get the same wrapper
	// as the PHP output, so the ring is always centred on the icon.
	function wrapIcons(root) {
		(root || document).querySelectorAll(SELECTOR + ' img').forEach(function (img) {
			if (img.parentNode.classList && img.parentNode.classList.contains('fl-icon-wrap')) { return; }
			var wrap = document.createElement('span');
			wrap.className = 'fl-icon-wrap';
			img.parentNode.insertBefore(wrap, img);
			wrap.appendChild(img);
		});
	}

	/* ---------- painting ---------- */

	function paint(btn, active) {
		btn.classList.toggle('is-active', active);
		btn.setAttribute('aria-pressed', active ? 'true' : 'false');
		btn.setAttribute('title', !loggedIn ? cfg.i18n.login : (active ? cfg.i18n.remove : cfg.i18n.add));

		var img = btn.querySelector('img');
		if (img) { img.src = active ? cfg.icons.active : cfg.icons.inactive; }

		// Optional: <a ... data-label="Add" data-label-active="Remove">
		var label = btn.querySelector('.fl-favorite-label');
		if (label && btn.dataset.label && btn.dataset.labelActive) {
			label.textContent = active ? btn.dataset.labelActive : btn.dataset.label;
		}
	}

	function paintPost(postId) {
		var active = ids.has(postId);
		document.querySelectorAll('.fl-favorite-btn[data-post-id="' + postId + '"]').forEach(function (b) {
			paint(b, active);
		});
	}

	function paintAll() {
		document.querySelectorAll(SELECTOR).forEach(function (b) {
			paint(b, ids.has(Number(b.dataset.postId)));
		});
	}

	function setLocal(postId, active) {
		if (active) { ids.add(postId); } else { ids.delete(postId); }
		paintPost(postId);
	}

	/* ---------- animation (on click only) ---------- */

	function animate(btn, active) {
		btn.classList.remove('fl-anim-beat', 'fl-anim-unbeat');
		void btn.offsetWidth; // restart the animation on repeated clicks
		btn.classList.add(active ? 'fl-anim-beat' : 'fl-anim-unbeat');
	}

	document.addEventListener('animationend', function (e) {
		if (e.target.tagName !== 'IMG') { return; }
		var btn = e.target.closest('.fl-favorite-btn');
		if (btn) { btn.classList.remove('fl-anim-beat', 'fl-anim-unbeat'); }
	});

	/* ---------- favorites page ---------- */

	function removeFromLists(postId) {
		document.querySelectorAll('.fl-list .fl-item[data-post-id="' + postId + '"]').forEach(function (li) {
			var wrap = li.closest('.fl-list-wrap');
			li.classList.add('is-removing');
			setTimeout(function () {
				li.remove();
				if (wrap && !wrap.querySelector('.fl-item')) {
					var list = wrap.querySelector('.fl-list');
					if (list) { list.remove(); }
					var empty = wrap.querySelector('.fl-empty');
					if (empty) { empty.hidden = false; }
				}
			}, 350);
		});
	}

	function updateCounts(postId, count) {
		document.querySelectorAll('.fl-favorite-count[data-post-id="' + postId + '"]').forEach(function (el) {
			el.textContent = count;
		});
	}

	/* ---------- server sync ---------- */

	function refresh() {
		return request({ action: 'fl_get_favorites' }).then(function (res) {
			if (!res || !res.success) { return; }
			nonce = res.data.nonce;
			loggedIn = !!res.data.loggedIn;
			if (inFlight === 0) {
				ids = new Set((res.data.ids || []).map(Number));
				paintAll();
			}
		}).catch(function () {});
	}

	function save(postId, active, retried) {
		return request({
			action: 'fl_set_favorite',
			nonce: nonce,
			post_id: postId,
			active: active ? 1 : 0
		}).then(function (res) {
			if (!res.success && res.data && res.data.code === 'bad_nonce' && !retried) {
				return refresh().then(function () { return save(postId, active, true); });
			}
			return res;
		});
	}

	/* ---------- click ---------- */

	document.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest(SELECTOR);
		if (!btn) { return; }

		e.preventDefault();
		e.stopPropagation();

		if (!loggedIn) { goToLogin(); return; }

		var postId = parseInt(btn.dataset.postId, 10);
		if (!postId) { return; }

		var want = !ids.has(postId);
		var mine = seq[postId] = (seq[postId] || 0) + 1;

		setLocal(postId, want); // instant feedback
		animate(btn, want);
		inFlight++;

		save(postId, want).then(function (res) {
			if (mine !== seq[postId]) { return; } // a newer click took over

			if (res && res.success) {
				setLocal(postId, !!res.data.active);
				updateCounts(postId, res.data.count);
				if (!res.data.active) { removeFromLists(postId); }
				return;
			}

			setLocal(postId, !want); // undo
			if (res && res.data && res.data.code === 'login_required') {
				goToLogin(res.data.loginUrl);
			} else if (window.console) {
				console.warn('[Favorite List]', res);
			}
		}).catch(function (err) {
			if (mine === seq[postId]) { setLocal(postId, !want); }
			if (window.console) { console.warn('[Favorite List]', err); }
		}).then(function () {
			inFlight--;
		});
	});

	/* ---------- init ---------- */

	wrapIcons();
	paintAll();
	// Re-check with the server so cached pages still show the right state.
	if (document.querySelector(SELECTOR)) { refresh(); }
})();
