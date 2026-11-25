'use strict';
// The Stories view: "Load more" like FreshRSS's normal view (with "auto load
// more", the next page loads as the footer nears the bottom of the screen),
// collapsible topics and stories, and marking them read in place.
// FreshRSS's own scripts only page .flux articles, and its mark-on-scroll
// reads .not_read, so this view uses its own classes.
(function () {
	let loading = false;
	let failed = false;

	function footer() {
		return document.querySelector('.stories-footer');
	}

	function nearFooter() {
		const f = footer();
		return f !== null && f.getBoundingClientRect().top <= window.innerHeight * 1.5;
	}

	function loadMore() {
		const button = document.getElementById('stories_load_more');
		if (loading || button === null) {
			return;
		}
		loading = true;
		button.classList.add('loading');
		fetch(button.dataset.url, { credentials: 'same-origin' })
			.then((response) => response.ok ? response.text() : Promise.reject(new Error(String(response.status))))
			.then((text) => {
				const page = new DOMParser().parseFromString(text, 'text/html');
				const column = document.querySelector('.stories-column');
				const f = footer();
				page.querySelectorAll('.stories-column > .stories-item').forEach((item) => {
					// Read since its page was shown, an item comes again among the read ones.
					if (column.querySelector(':scope > .stories-item[data-key="' + CSS.escape(item.dataset.key) + '"]') !== null) {
						return;
					}
					const node = document.adoptNode(item);
					column.insertBefore(node, f);
					restoreCollapsed(node);
				});
				const inner = page.querySelector('.stories-footer .stream-footer-inner');
				if (inner !== null) {
					f.replaceChild(document.adoptNode(inner), f.querySelector('.stream-footer-inner'));
				}
				bind();
			})
			.catch(() => {
				failed = true;	// keep the button for a manual retry, stop auto-loading
				button.classList.remove('loading');
			})
			.finally(() => {
				loading = false;
				autoLoad();
			});
	}

	function autoLoad() {
		const f = footer();
		if (!failed && f !== null && f.dataset.auto === '1' && nearFooter()) {
			loadMore();
		}
	}

	function bind() {
		const button = document.getElementById('stories_load_more');
		if (button !== null) {
			button.addEventListener('click', (event) => {
				event.preventDefault();
				failed = false;
				loadMore();
			});
		}
	}

	let timer = 0;
	function onScroll() {
		clearTimeout(timer);
		timer = setTimeout(autoLoad, 150);
	}

	// Topics and stories collapsed by hand, remembered in this browser only and
	// until a newer article joins them: each key keeps the newest one it held.
	// Those with nothing unread need no memory, the page collapses them itself.
	const storageKey = 'semantic-stories-collapsed-until';

	function collapsedKeys() {
		try {
			const keys = JSON.parse(localStorage.getItem(storageKey) || '{}');
			return keys !== null && typeof keys === 'object' && !Array.isArray(keys) ? keys : {};
		} catch (e) {
			return {};
		}
	}

	function setCollapsed(section, collapsed, remember) {
		section.classList.toggle('collapsed', collapsed);
		const button = section.querySelector(':scope > .collapsible-header .stories-toggle');
		if (button !== null) {
			button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
		}
		if (remember && section.dataset.key) {
			const keys = collapsedKeys();
			delete keys[section.dataset.key];
			if (collapsed) {
				keys[section.dataset.key] = section.dataset.newest || '';
			}
			try {
				localStorage.setItem(storageKey, JSON.stringify(Object.fromEntries(Object.entries(keys).slice(-500))));
			} catch (e) {
				// Storage unavailable: the state just isn't remembered.
			}
		}
	}

	function restoreCollapsed(root) {
		const keys = collapsedKeys();
		const sections = [root, ...root.querySelectorAll('.collapsible')];
		sections.forEach((section) => {
			if (section.classList.contains('collapsible') && Object.hasOwn(keys, section.dataset.key)
					&& keys[section.dataset.key] === section.dataset.newest) {
				setCollapsed(section, true, false);
			}
		});
	}

	// Unread counts in topic and story headers, recounted from the page. Like
	// the page itself, a topic or story left with nothing unread collapses.
	function refreshCounts() {
		document.querySelectorAll('.semantic-stories .collapsible[data-unread]').forEach((section) => {
			const unread = section.querySelectorAll('li.story-article.unread').length;
			if (unread === 0 && section.dataset.unread !== '0') {
				setCollapsed(section, true, false);
			}
			section.dataset.unread = String(unread);
			const count = section.querySelector(':scope > .collapsible-header .unread-count');
			if (count !== null) {
				if (unread === 0) {
					count.remove();
				} else {
					count.querySelector('.story-unread').textContent = unread + ' unread';
				}
			}
			if (section.classList.contains('story')) {
				section.classList.toggle('story-read', unread === 0);
			}
		});
		document.querySelectorAll('form.stories-mark-read').forEach((form) => {
			const pending = [...form.querySelectorAll('input[name="id[]"]')].some((input) =>
				document.querySelector('li.story-article.unread[data-entry="' + CSS.escape(input.value) + '"]') !== null);
			if (!pending) {
				form.remove();
			}
		});
	}

	function markRead(form) {
		const button = form.querySelector('button');
		const url = new URL(form.action);
		url.searchParams.set('ajax', '1');
		button.disabled = true;
		fetch(url, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
			.then((response) => {
				if (!response.ok) {
					throw new Error(String(response.status));
				}
				form.querySelectorAll('input[name="id[]"]').forEach((input) => {
					document.querySelectorAll('li.story-article[data-entry="' + CSS.escape(input.value) + '"]').forEach((li) => {
						li.classList.remove('unread');
					});
				});
				refreshCounts();	// collapses what is left with nothing unread
			})
			.catch(() => {
				button.disabled = false;
				form.submit();	// fall back to the normal form post
			});
	}

	document.addEventListener('click', (event) => {
		const toggle = event.target.closest('.semantic-stories .stories-toggle');
		if (toggle !== null) {
			const section = toggle.closest('.collapsible');
			setCollapsed(section, !section.classList.contains('collapsed'), true);
		}
	});

	document.addEventListener('submit', (event) => {
		const form = event.target.closest('form.stories-mark-read');
		if (form !== null) {
			event.preventDefault();
			markRead(form);
		}
	});

	function init() {
		try {
			localStorage.removeItem('semantic-stories-collapsed');	// kept collapsed forever
		} catch (e) {
			// Storage unavailable: nothing was remembered.
		}
		const column = document.querySelector('.semantic-stories .stories-column');
		if (column !== null) {
			restoreCollapsed(column);
		}
		if (footer() === null) {
			return;
		}
		bind();
		window.addEventListener('scroll', onScroll, { passive: true });
		window.addEventListener('resize', onScroll);
		autoLoad();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
