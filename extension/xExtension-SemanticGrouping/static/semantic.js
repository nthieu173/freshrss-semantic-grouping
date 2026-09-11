'use strict';

document.addEventListener('click', (event) => {
	if (!(event.target instanceof Element)) {
		return;
	}
	const toggle = event.target.closest('[data-semantic-group-toggle]');
	if (!(toggle instanceof HTMLButtonElement)) {
		return;
	}
	const memberContainerId = toggle.getAttribute('aria-controls');
	const memberContainer = memberContainerId === null ? null : document.getElementById(memberContainerId);
	if (memberContainer === null) {
		return;
	}
	const expanded = toggle.getAttribute('aria-expanded') === 'true';
	toggle.setAttribute('aria-expanded', String(!expanded));
	toggle.textContent = expanded ? 'Expand group' : 'Collapse group';
	memberContainer.hidden = expanded;
});
