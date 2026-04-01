/**
 * CoTranslate språkväljare — JavaScript
 *
 * Hanterar dropdown toggle, tangentbordsnavigering och tillgänglighet.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		initDropdowns();
	});

	function initDropdowns() {
		var dropdowns = document.querySelectorAll('.cotranslate-dropdown');

		dropdowns.forEach(function (dropdown) {
			var toggle = dropdown.querySelector('.cotranslate-dropdown-toggle');
			var menu = dropdown.querySelector('.cotranslate-dropdown-menu');

			if (!toggle || !menu) return;

			// Klick toggle
			toggle.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();

				var isOpen = dropdown.getAttribute('data-open') === 'true';

				// Stäng alla andra dropdowns
				closeAllDropdowns();

				if (!isOpen) {
					dropdown.setAttribute('data-open', 'true');
					toggle.setAttribute('aria-expanded', 'true');
					// Fokusera första alternativet
					var firstLink = menu.querySelector('a');
					if (firstLink) firstLink.focus();
				}
			});

			// Tangentbordsnavigering
			dropdown.addEventListener('keydown', function (e) {
				var links = menu.querySelectorAll('a');
				var currentIndex = Array.from(links).indexOf(document.activeElement);

				switch (e.key) {
					case 'Escape':
						closeDropdown(dropdown, toggle);
						toggle.focus();
						e.preventDefault();
						break;

					case 'ArrowDown':
						e.preventDefault();
						if (currentIndex < links.length - 1) {
							links[currentIndex + 1].focus();
						} else {
							links[0].focus();
						}
						break;

					case 'ArrowUp':
						e.preventDefault();
						if (currentIndex > 0) {
							links[currentIndex - 1].focus();
						} else {
							links[links.length - 1].focus();
						}
						break;

					case 'Tab':
						closeDropdown(dropdown, toggle);
						break;
				}
			});
		});

		// Stäng dropdown vid klick utanför
		document.addEventListener('click', function () {
			closeAllDropdowns();
		});
	}

	function closeDropdown(dropdown, toggle) {
		dropdown.setAttribute('data-open', 'false');
		if (toggle) toggle.setAttribute('aria-expanded', 'false');
	}

	function closeAllDropdowns() {
		document.querySelectorAll('.cotranslate-dropdown').forEach(function (d) {
			var t = d.querySelector('.cotranslate-dropdown-toggle');
			closeDropdown(d, t);
		});
	}
})();
