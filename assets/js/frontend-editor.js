/**
 * CoTranslate frontend-editor
 *
 * Tillåter visuell redigering av översättningar direkt på sajten.
 * Sparar via AJAX till samma Translation Store som admin-panelen.
 */
(function () {
	'use strict';

	var editMode = false;
	var editableElements = [];

	document.addEventListener('DOMContentLoaded', function () {
		var toggleBtn = document.getElementById('cotranslate-edit-mode-btn');
		if (!toggleBtn) return;

		toggleBtn.addEventListener('click', function () {
			editMode = !editMode;
			toggleBtn.classList.toggle('active', editMode);
			toggleBtn.querySelector('.cotranslate-edit-label').textContent = editMode ? 'Avsluta' : 'Redigera';

			if (editMode) {
				enableEditMode();
			} else {
				disableEditMode();
			}
		});

		// Modal: stäng
		document.addEventListener('click', function (e) {
			if (e.target.classList.contains('cotranslate-editor-close') || e.target.id === 'cotranslate-editor-cancel') {
				closeModal();
			}
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				closeModal();
			}
		});

		// Modal: spara
		var saveBtn = document.getElementById('cotranslate-editor-save');
		if (saveBtn) {
			saveBtn.addEventListener('click', saveTranslation);
		}
	});

	function enableEditMode() {
		// Hitta redigerbara textelement
		var selectors = 'h1, h2, h3, h4, h5, h6, p, span, a, li, td, th, label, button, .entry-title, .page-title';
		var elements = document.querySelectorAll(selectors);

		elements.forEach(function (el) {
			// Hoppa över CoTranslate:s egna element
			if (el.closest('#cotranslate-editor-toggle') || el.closest('#cotranslate-editor-modal') ||
				el.closest('.cotranslate-switcher') || el.closest('.cotranslate-floating')) {
				return;
			}

			// Hoppa över element utan direkt text
			var text = getDirectText(el);
			if (!text || text.length < 2 || text.length > 500) return;

			el.classList.add('cotranslate-editable');
			el.addEventListener('click', onEditableClick);
			editableElements.push(el);
		});
	}

	function disableEditMode() {
		editableElements.forEach(function (el) {
			el.classList.remove('cotranslate-editable', 'cotranslate-manual');
			el.removeEventListener('click', onEditableClick);
		});
		editableElements = [];
	}

	function onEditableClick(e) {
		if (!editMode) return;
		e.preventDefault();
		e.stopPropagation();

		var el = e.currentTarget;
		var text = getDirectText(el);
		var translatedText = text;

		// På page builder-sajter sparas ALL text som strängar
		// (post content-filter skippar page builder-innehåll).
		// Bara titlar sparas som post-translations.
		var type = 'string';
		var postId = 0;
		var field = '';

		var isTitle = el.classList.contains('entry-title') || el.closest('.entry-title') ||
			(el.tagName === 'H1' && el.closest('article, .hentry, .post'));

		if (isTitle) {
			// Titlar kan sparas som post-translation
			var article = el.closest('article, .hentry, .post, main');
			if (article) {
				var postIdMatch = article.className.match(/post-(\d+)/);
				if (postIdMatch) {
					postId = parseInt(postIdMatch[1], 10);
				} else if (typeof cotranslateFrontend !== 'undefined') {
					postId = cotranslateFrontend.postId || 0;
				}
			}

			if (postId > 0) {
				type = 'post';
				field = 'title';
			}
		}

		openModal(text, translatedText, type, postId, field);
	}

	function openModal(currentText, translatedText, type, postId, field) {
		var modal = document.getElementById('cotranslate-editor-modal');
		if (!modal) return;

		// Texten som syns på sidan kan redan vara översatt.
		// För strängar: source_text = den text som visas (den matchas mot databasen).
		// Användaren skriver ny översättning i textfältet.
		document.getElementById('cotranslate-editor-original').textContent = currentText;
		document.getElementById('cotranslate-editor-translation').value = translatedText;
		document.getElementById('cotranslate-editor-type').value = type;
		document.getElementById('cotranslate-editor-post-id').value = postId;
		document.getElementById('cotranslate-editor-field').value = field;
		document.getElementById('cotranslate-editor-source-text').value = currentText;
		document.getElementById('cotranslate-editor-status').innerHTML = '';

		// Uppdatera modal-titel baserat på typ
		var titleEl = document.getElementById('cotranslate-editor-modal-title');
		if (titleEl) {
			titleEl.textContent = type === 'post' ? 'Redigera titel' : 'Redigera översättning';
		}

		modal.style.display = 'flex';
		document.getElementById('cotranslate-editor-translation').focus();
	}

	function closeModal() {
		var modal = document.getElementById('cotranslate-editor-modal');
		if (modal) modal.style.display = 'none';
	}

	function saveTranslation() {
		var statusEl = document.getElementById('cotranslate-editor-status');
		statusEl.innerHTML = '<span class="cotranslate-editor-success">Sparar...</span>';

		var type = document.getElementById('cotranslate-editor-type').value;
		var value = document.getElementById('cotranslate-editor-translation').value;

		var data = new FormData();
		data.append('action', 'cotranslate_frontend_save');
		data.append('nonce', cotranslateFrontend.nonce);
		data.append('type', type);
		data.append('language', cotranslateFrontend.language);
		data.append('value', value);

		if (type === 'post') {
			data.append('post_id', document.getElementById('cotranslate-editor-post-id').value);
			data.append('field', document.getElementById('cotranslate-editor-field').value);
		} else {
			data.append('source_text', document.getElementById('cotranslate-editor-source-text').value);
		}

		fetch(cotranslateFrontend.ajaxUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		})
		.then(function (response) { return response.json(); })
		.then(function (result) {
			if (result.success) {
				statusEl.innerHTML = '<span class="cotranslate-editor-success">' + result.data + '</span>';
				setTimeout(function () {
					closeModal();
					// Ladda om med cache-bust för att kringgå servercache
					var url = window.location.href.split('?')[0];
					var params = new URLSearchParams(window.location.search);
					params.set('nocache', Date.now());
					window.location.href = url + '?' + params.toString();
				}, 800);
			} else {
				statusEl.innerHTML = '<span class="cotranslate-editor-error">' + result.data + '</span>';
			}
		})
		.catch(function () {
			statusEl.innerHTML = '<span class="cotranslate-editor-error">Nätverksfel.</span>';
		});
	}

	/**
	 * Hämta direkt textinnehåll (exklusive barn-element).
	 */
	function getDirectText(el) {
		var text = '';
		for (var i = 0; i < el.childNodes.length; i++) {
			if (el.childNodes[i].nodeType === Node.TEXT_NODE) {
				text += el.childNodes[i].textContent;
			}
		}
		return text.trim();
	}
})();
