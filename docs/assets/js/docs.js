(function () {
	'use strict';

	var NAV = {
		user: {
			label: 'User Guide',
			items: [
				{ id: 'getting-started', title: 'Getting started', href: 'getting-started.html' },
				{ id: 'connection', title: 'Connection', href: 'connection.html' },
				{ id: 'integrations', title: 'Integrations', href: 'integrations.html' },
				{ id: 'mapping', title: 'Pass Template mapping', href: 'mapping.html' },
				{ id: 'passes-and-email', title: 'Passes & email', href: 'passes-and-email.html' },
				{ id: 'notifications', title: 'Push notifications', href: 'notifications.html' },
				{ id: 'api-log', title: 'API log', href: 'api-log.html' },
				{ id: 'my-account', title: 'My Account & shortcode', href: 'my-account.html' }
			]
		},
		developer: {
			label: 'Developer Guide',
			items: [
				{ id: 'architecture', title: 'Architecture', href: 'architecture.html' },
				{ id: 'hooks', title: 'Hooks reference', href: 'hooks.html' },
				{ id: 'custom-modules', title: 'Custom modules', href: 'custom-modules.html' },
				{ id: 'mapping-extensions', title: 'Mapping extensions', href: 'mapping-extensions.html' },
				{ id: 'pass-email', title: 'Pass email API', href: 'pass-email.html' },
				{ id: 'push-notifications', title: 'Push notifications', href: 'push-notifications.html' },
				{ id: 'database', title: 'Database', href: 'database.html' }
			]
		}
	};

	function qs(sel, ctx) {
		return (ctx || document).querySelector(sel);
	}

	function qsa(sel, ctx) {
		return Array.prototype.slice.call((ctx || document).querySelectorAll(sel));
	}

	function getBasePath() {
		var body = document.body;
		if (body.classList.contains('doc-page')) {
			return body.getAttribute('data-section') === 'user' ? '../' : '../';
		}
		return '';
	}

	function getSectionPrefix() {
		var section = document.body.getAttribute('data-section');
		return section === 'developer' ? 'developer/' : 'user/';
	}

	function buildSidebar() {
		var sidebar = qs('.doc-sidebar');
		if (!sidebar) {
			return;
		}

		var section = document.body.getAttribute('data-section');
		var page = document.body.getAttribute('data-page');
		var prefix = getSectionPrefix();
		var otherSection = section === 'user' ? 'developer' : 'user';
		var otherPrefix = otherSection === 'user' ? '../user/' : '../developer/';
		var otherHref = otherSection === 'user' ? '../user/getting-started.html' : '../developer/architecture.html';

		var html = '<div class="doc-sidebar__search"><input type="search" id="doc-search" placeholder="Search docs…" autocomplete="off" aria-label="Search documentation"></div>';

		['user', 'developer'].forEach(function (key) {
			var group = NAV[key];
			var isCurrent = key === section;
			html += '<div class="doc-sidebar__group" data-nav-group="' + key + '">';
			html += '<div class="doc-sidebar__label">' + group.label + '</div>';
			html += '<ul class="doc-sidebar__nav">';

			group.items.forEach(function (item) {
				var href = isCurrent ? item.href : otherPrefix + item.href;
				var active = isCurrent && item.id === page ? ' is-active' : '';
				html += '<li data-title="' + item.title.toLowerCase() + '">';
				html += '<a href="' + href + '" class="' + active.trim() + '">' + item.title + '</a>';
				html += '</li>';
			});

			html += '</ul></div>';
		});

		html += '<div class="doc-sidebar__group"><div class="doc-sidebar__label">Links</div><ul class="doc-sidebar__nav">';
		html += '<li><a href="../index.html">Documentation home</a></li>';
		html += '<li><a href="https://epasscard.com/" target="_blank" rel="noopener">epasscard.com</a></li>';
		html += '<li><a href="https://app.epasscard.com/" target="_blank" rel="noopener">app.epasscard.com</a></li>';
		html += '</ul></div>';

		sidebar.innerHTML = html;

		var search = qs('#doc-search', sidebar);
		if (search) {
			search.addEventListener('input', function () {
				var q = search.value.toLowerCase().trim();
				qsa('.doc-sidebar__nav li[data-title]', sidebar).forEach(function (li) {
					var match = !q || li.getAttribute('data-title').indexOf(q) !== -1;
					li.classList.toggle('is-hidden', !match);
				});
			});
		}
	}

	function initCopyButtons() {
		qsa('.code-block').forEach(function (block) {
			var btn = qs('.code-block__copy', block);
			var pre = qs('pre', block);
			if (!btn || !pre) {
				return;
			}
			btn.addEventListener('click', function () {
				var text = pre.textContent;
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function () {
						showCopied(btn);
					});
				} else {
					var ta = document.createElement('textarea');
					ta.value = text;
					document.body.appendChild(ta);
					ta.select();
					document.execCommand('copy');
					document.body.removeChild(ta);
					showCopied(btn);
				}
			});
		});
	}

	function showCopied(btn) {
		var original = btn.textContent;
		btn.textContent = 'Copied!';
		btn.classList.add('is-copied');
		setTimeout(function () {
			btn.textContent = original;
			btn.classList.remove('is-copied');
		}, 2000);
	}

	function initMobileNav() {
		var toggle = qs('.menu-toggle');
		var nav = qs('.site-nav');
		var sidebarToggle = qs('.sidebar-toggle');
		var sidebar = qs('.doc-sidebar');
		var backdrop = qs('.sidebar-backdrop');

		if (toggle && nav) {
			toggle.addEventListener('click', function () {
				nav.classList.toggle('is-open');
			});
		}

		function closeSidebar() {
			if (sidebar) {
				sidebar.classList.remove('is-open');
			}
			if (backdrop) {
				backdrop.classList.remove('is-visible');
			}
		}

		if (sidebarToggle && sidebar) {
			sidebarToggle.addEventListener('click', function () {
				sidebar.classList.toggle('is-open');
				if (backdrop) {
					backdrop.classList.toggle('is-visible', sidebar.classList.contains('is-open'));
				}
			});
		}

		if (backdrop) {
			backdrop.addEventListener('click', closeSidebar);
		}
	}

	function initPager() {
		var pager = qs('.doc-pager');
		if (!pager) {
			return;
		}

		var section = document.body.getAttribute('data-section');
		var page = document.body.getAttribute('data-page');
		var items = NAV[section] ? NAV[section].items : [];
		var idx = -1;

		items.forEach(function (item, i) {
			if (item.id === page) {
				idx = i;
			}
		});

		if (idx < 0) {
			return;
		}

		var html = '';

		if (idx > 0) {
			var prev = items[idx - 1];
			html += '<a class="doc-pager__link doc-pager__link--prev" href="' + prev.href + '">';
			html += '<span class="doc-pager__label">Previous</span>';
			html += '<span class="doc-pager__title">← ' + prev.title + '</span></a>';
		} else {
			html += '<span></span>';
		}

		if (idx < items.length - 1) {
			var next = items[idx + 1];
			html += '<a class="doc-pager__link doc-pager__link--next" href="' + next.href + '">';
			html += '<span class="doc-pager__label">Next</span>';
			html += '<span class="doc-pager__title">' + next.title + ' →</span></a>';
		}

		pager.innerHTML = html;
	}

	function highlightActiveNav() {
		var section = document.body.getAttribute('data-section');
		qsa('.site-nav__link[data-nav]').forEach(function (link) {
			link.classList.toggle('is-active', link.getAttribute('data-nav') === section);
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		buildSidebar();
		initCopyButtons();
		initMobileNav();
		initPager();
		highlightActiveNav();
	});
})();
