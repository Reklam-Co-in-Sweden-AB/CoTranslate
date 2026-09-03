/**
 * CoTranslate admin JavaScript
 */
(function ($) {
	'use strict';

	// Färgväljare (WordPress inbyggda Iris) — tom = ärv sajtens färger
	if ($.fn.wpColorPicker) {
		$('.cotranslate-color-field').wpColorPicker();
	}

	// Flikar (länkflikar med href navigerar som vanligt)
	$(document).on('click', '.cotranslate-tab', function () {
		if ($(this).is('a')) return;
		var tab = $(this).data('tab');
		$('.cotranslate-tab').removeClass('active');
		$(this).addClass('active');
		$('.cotranslate-tab-content').removeClass('active');
		$('#tab-' + tab).addClass('active');
	});

	// Motorväljare: visa/dölj rätt inställningar
	var engineDescs = {};
	$('#cotranslate-engine option').each(function () {
		engineDescs[$(this).val()] = $(this).text();
	});

	$('#cotranslate-engine').on('change', function () {
		var engine = $(this).val();
		$('#cotranslate-deepl-settings').toggle(engine === 'deepl');
		$('#cotranslate-claude-settings').toggle(engine === 'claude');
	});

	// Testa Claude-anslutning
	$('#cotranslate-test-claude').on('click', function () {
		var $btn = $(this);
		var $status = $('#cotranslate-claude-status');
		var apiKey = $('#cotranslate-claude-key').val();

		$btn.prop('disabled', true);
		$status.html('<span class="cotranslate-loading">Testar...</span>');

		$.post(cotranslateAdmin.ajaxUrl, {
			action: 'cotranslate_test_api',
			nonce: cotranslateAdmin.nonce,
			api_key: apiKey,
			engine: 'claude'
		}, function (response) {
			$btn.prop('disabled', false);
			if (response.success) {
				$status.html('<span class="cotranslate-success">' + response.data.message + '</span>');
			} else {
				$status.html('<span class="cotranslate-error">' + response.data + '</span>');
			}
		});
	});

	// Testa DeepL-anslutning
	$('#cotranslate-test-api').on('click', function () {
		var $btn = $(this);
		var $status = $('#cotranslate-api-status');
		var apiKey = $('#cotranslate-api-key').val();

		$btn.prop('disabled', true);
		$status.html('<span class="cotranslate-loading">Testar...</span>');

		$.post(cotranslateAdmin.ajaxUrl, {
			action: 'cotranslate_test_api',
			nonce: cotranslateAdmin.nonce,
			api_key: apiKey
		}, function (response) {
			$btn.prop('disabled', false);
			if (response.success) {
				var d = response.data;
				var percent = (d.character_count / d.character_limit * 100).toFixed(1);
				$status.html(
					'<span class="cotranslate-success">' + d.message + '</span>' +
					'<br>Förbrukat: ' + d.character_count.toLocaleString() +
					' / ' + d.character_limit.toLocaleString() +
					' tecken (' + percent + '%)'
				);
			} else {
				$status.html('<span class="cotranslate-error">' + response.data + '</span>');
			}
		}).fail(function () {
			$btn.prop('disabled', false);
			$status.html('<span class="cotranslate-error">Nätverksfel.</span>');
		});
	});

	// Spara API-nyckel
	$('#cotranslate-save-api-key').on('click', function () {
		var apiKey = $('#cotranslate-api-key').val();
		var $status = $('#cotranslate-api-status');

		$.post(cotranslateAdmin.ajaxUrl, {
			action: 'cotranslate_test_api',
			nonce: cotranslateAdmin.nonce,
			api_key: apiKey,
			save_key: 'true'
		}, function (response) {
			if (response.success) {
				$status.html('<span class="cotranslate-success">Nyckel sparad och verifierad!</span>');
			} else {
				$status.html('<span class="cotranslate-error">' + response.data + '</span>');
			}
		});
	});

	// Spara inställningar
	$('#cotranslate-save-settings').on('click', function () {
		var $btn = $(this);
		$btn.prop('disabled', true).text('Sparar...');

		// Samla enabled languages
		var enabledLanguages = [];
		$('input[name="cotranslate_enabled_languages[]"]:checked').each(function () {
			enabledLanguages.push($(this).val());
		});

		// Samla post types
		var postTypes = [];
		$('input[name="cotranslate_post_types[]"]:checked').each(function () {
			postTypes.push($(this).val());
		});

		// Samla domänmappning
		var domainMap = [];
		$('.cotranslate-domain-row').each(function () {
			var domain = $(this).find('.cotranslate-domain').val();
			var lang = $(this).find('.cotranslate-domain-lang').val();
			if (domain) {
				domainMap.push({ domain: domain, language: lang });
			}
		});

		$.post(cotranslateAdmin.ajaxUrl, {
			action: 'cotranslate_save_settings',
			nonce: cotranslateAdmin.nonce,
			engine: $('#cotranslate-engine').val(),
			api_key: $('#cotranslate-api-key').val(),
			claude_api_key: $('#cotranslate-claude-key').val(),
			default_language: $('#cotranslate-default-language').val(),
			enabled_languages: enabledLanguages,
			post_types: postTypes,
			translate_slugs: $('#cotranslate-translate-slugs').is(':checked') ? 1 : 0,
			frontend_editor: $('#cotranslate-frontend-editor').is(':checked') ? 1 : 0,
			floating_switcher: $('#cotranslate-floating-switcher').is(':checked') ? 1 : 0,
			floating_position: $('#cotranslate-floating-position').val(),
			floating_style: $('#cotranslate-floating-style').val(),
			switcher_bg_color: $('#cotranslate-bg-color').val(),
			switcher_text_color: $('#cotranslate-text-color').val(),
			auto_detect: $('#cotranslate-auto-detect').is(':checked') ? 1 : 0,
			delete_on_uninstall: $('#cotranslate-delete-on-uninstall').is(':checked') ? 1 : 0,
			domain_map: domainMap
		}, function (response) {
			$btn.prop('disabled', false).text('Spara inställningar');
			if (response.success) {
				alert('Inställningar sparade!');
			} else {
				alert('Fel: ' + response.data);
			}
		});
	});

	// Bulk-översätt alla poster med progress bar
	var bulkAborted = false;

	$('#cotranslate-translate-all').on('click', function () {
		var $btn = $(this);
		var $status = $('#cotranslate-translate-all-status');

		if (!confirm('Detta översätter alla publicerade poster via DeepL. Det kan ta en stund. Fortsätt?')) {
			return;
		}

		$btn.prop('disabled', true);
		bulkAborted = false;
		var totalTranslated = 0;
		var totalErrors = 0;
		var startTime = Date.now();

		$status.html(
			'<div class="cotranslate-bulk-progress">' +
			'<div class="cotranslate-usage-bar"><div class="cotranslate-usage-fill" id="cotranslate-bulk-bar" style="width:0%"></div></div>' +
			'<p id="cotranslate-bulk-text">Startar...</p>' +
			'<button type="button" class="button" id="cotranslate-bulk-abort">Avbryt</button>' +
			'</div>'
		);

		$('#cotranslate-bulk-abort').on('click', function () {
			bulkAborted = true;
			$(this).prop('disabled', true).text('Avbryter...');
		});

		function processBatch(offset) {
			if (bulkAborted) {
				$('#cotranslate-bulk-text').text('Avbruten. ' + totalTranslated + ' översatta, ' + totalErrors + ' fel.');
				$btn.prop('disabled', false);
				return;
			}

			$.post(cotranslateAdmin.ajaxUrl, {
				action: 'cotranslate_bulk_translate_batch',
				nonce: cotranslateAdmin.nonce,
				offset: offset
			}, function (response) {
				if (!response.success) {
					$('#cotranslate-bulk-text').html('<span class="cotranslate-error">' + response.data + '</span>');
					$btn.prop('disabled', false);
					return;
				}

				var d = response.data;
				totalTranslated += d.translated;
				totalErrors += d.errors;

				// Uppdatera progress bar
				$('#cotranslate-bulk-bar').css('width', d.percent + '%');

				// Tidsuppskattning
				var elapsed = (Date.now() - startTime) / 1000;
				var postsProcessed = Math.min(d.offset, d.total);
				var rate = postsProcessed / elapsed;
				var remaining = rate > 0 ? Math.round((d.total - postsProcessed) / rate) : 0;
				var timeStr = remaining > 60
					? Math.round(remaining / 60) + ' min kvar'
					: remaining + ' sek kvar';

				$('#cotranslate-bulk-text').text(
					postsProcessed + ' / ' + d.total + ' poster (' + d.percent + '%) — ' +
					totalTranslated + ' översatta — ' + timeStr
				);

				if (d.done) {
					$('#cotranslate-bulk-text').html(
						'<span class="cotranslate-success">Klart! ' + totalTranslated +
						' översättningar, ' + totalErrors + ' fel.</span>'
					);
					$('#cotranslate-bulk-abort').hide();
					$btn.prop('disabled', false);
				} else {
					processBatch(d.offset);
				}
			}).fail(function () {
				$('#cotranslate-bulk-text').html('<span class="cotranslate-error">Nätverksfel. Försök igen.</span>');
				$btn.prop('disabled', false);
			});
		}

		processBatch(0);
	});

	// Översätt enskild post
	$('#cotranslate-translate-post').on('click', function () {
		var postId = $('#cotranslate-post-id').val();
		var language = $('#cotranslate-post-language').val();
		var $status = $('#cotranslate-translate-post-status');

		if (!postId) {
			$status.html('<span class="cotranslate-error">Ange ett post-ID.</span>');
			return;
		}

		$status.html('<span class="cotranslate-loading">Översätter...</span>');

		$.post(cotranslateAdmin.ajaxUrl, {
			action: 'cotranslate_translate_post',
			nonce: cotranslateAdmin.nonce,
			post_id: postId,
			language: language
		}, function (response) {
			if (response.success) {
				$status.html('<span class="cotranslate-success">' + response.data + '</span>');
			} else {
				$status.html('<span class="cotranslate-error">' + response.data + '</span>');
			}
		});
	});

	// Redigera översättning (öppna modal)
	$(document).on('click', '.cotranslate-edit-translation', function () {
		var postId = $(this).data('post-id');
		var language = $(this).data('language');

		$('#edit-post-id').val(postId);
		$('#edit-language').val(language);

		// Hämta befintlig data från tabellraden
		var $row = $(this).closest('tr');
		$('#edit-title').val($row.find('td:eq(3)').text().trim());

		$('#cotranslate-edit-modal').show();
	});

	// Spara redigerad översättning
	$('#cotranslate-save-edit').on('click', function () {
		var $status = $('#cotranslate-edit-status');
		$status.html('<span class="cotranslate-loading">Sparar...</span>');

		$.post(cotranslateAdmin.ajaxUrl, {
			action: 'cotranslate_update_translation',
			nonce: cotranslateAdmin.nonce,
			post_id: $('#edit-post-id').val(),
			language: $('#edit-language').val(),
			title: $('#edit-title').val(),
			content: $('#edit-content').val(),
			excerpt: $('#edit-excerpt').val(),
			slug: $('#edit-slug').val()
		}, function (response) {
			if (response.success) {
				$status.html('<span class="cotranslate-success">' + response.data + '</span>');
				setTimeout(function () {
					$('#cotranslate-edit-modal').hide();
					location.reload();
				}, 1000);
			} else {
				$status.html('<span class="cotranslate-error">' + response.data + '</span>');
			}
		});
	});

	// Översätt om en sida (icke-handrättad rad under Sidor)
	$(document).on('click', '.cotranslate-retranslate', function () {
		var $btn = $(this);
		$btn.prop('disabled', true).text('Översätter...');

		$.post(cotranslateAdmin.ajaxUrl, {
			action: 'cotranslate_translate_post',
			nonce: cotranslateAdmin.nonce,
			post_id: $btn.data('post-id'),
			language: $btn.data('language')
		}, function (response) {
			if (response.success) {
				location.reload();
			} else {
				$btn.prop('disabled', false).text('Översätt om');
				$btn.after('<span class="cotranslate-error"> ' + response.data + '</span>');
			}
		}).fail(function () {
			$btn.prop('disabled', false).text('Översätt om');
		});
	});

	// Släpp handrättning
	$(document).on('click', '.cotranslate-reset-translation', function () {
		if (!confirm('Släpp handrättningen? Sidan översätts om automatiskt och den handrättade texten försvinner.')) {
			return;
		}

		var $btn = $(this);
		$.post(cotranslateAdmin.ajaxUrl, {
			action: 'cotranslate_reset_translation',
			nonce: cotranslateAdmin.nonce,
			post_id: $btn.data('post-id'),
			language: $btn.data('language')
		}, function (response) {
			if (response.success) {
				location.reload();
			} else {
				alert('Fel: ' + response.data);
			}
		});
	});

	// Stäng modaler
	$(document).on('click', '.cotranslate-modal-close, .cotranslate-modal-close-btn', function () {
		$('.cotranslate-modal').hide();
	});

	$(document).on('keydown', function (e) {
		if (e.key === 'Escape') {
			$('.cotranslate-modal').hide();
		}
	});

	// ===== STRÄNGHANTERING =====

	// Redigera sträng (öppna modal)
	$(document).on('click', '.cotranslate-edit-string', function () {
		var $btn = $(this);
		$('#string-edit-id').val($btn.data('id'));
		$('#string-edit-source').val($btn.data('source'));
		$('#string-edit-language').val($btn.data('language'));
		$('#string-edit-original').text($btn.data('source'));
		$('#string-edit-translation').val($btn.data('translated'));
		$('#cotranslate-string-edit-status').html('');
		$('#cotranslate-string-modal').show();
		$('#string-edit-translation').focus();
	});

	// Spara sträng
	$('#cotranslate-save-string').on('click', function () {
		var $status = $('#cotranslate-string-edit-status');
		$status.html('<span class="cotranslate-loading">Sparar...</span>');

		$.post(cotranslateAdmin.ajaxUrl, {
			action: 'cotranslate_update_string',
			nonce: cotranslateAdmin.nonce,
			source_text: $('#string-edit-source').val(),
			translated_text: $('#string-edit-translation').val(),
			language: $('#string-edit-language').val()
		}, function (response) {
			if (response.success) {
				$status.html('<span class="cotranslate-success">' + response.data + '</span>');
				setTimeout(function () {
					$('#cotranslate-string-modal').hide();
					location.reload();
				}, 800);
			} else {
				$status.html('<span class="cotranslate-error">' + response.data + '</span>');
			}
		});
	});

	// Radera sträng
	$(document).on('click', '.cotranslate-delete-string', function () {
		if (!confirm('Radera den här texten? Den plockas upp igen vid nästa besök om den fortfarande finns på sajten.')) return;

		var $btn = $(this);
		$.post(cotranslateAdmin.ajaxUrl, {
			action: 'cotranslate_delete_string',
			nonce: cotranslateAdmin.nonce,
			string_id: $btn.data('id')
		}, function (response) {
			if (response.success) {
				$btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
			} else {
				alert('Fel: ' + response.data);
			}
		});
	});

	// Hämta API-användning
	$('#cotranslate-refresh-usage').on('click', function () {
		var $data = $('#cotranslate-usage-data');
		$data.html('<span class="cotranslate-loading">Hämtar...</span>');

		$.post(cotranslateAdmin.ajaxUrl, {
			action: 'cotranslate_get_usage',
			nonce: cotranslateAdmin.nonce
		}, function (response) {
			if (response.success) {
				var d = response.data;
				var barClass = d.percent > 95 ? 'danger' : (d.percent > 80 ? 'warning' : '');
				$data.html(
					'<div class="cotranslate-usage-bar">' +
					'<div class="cotranslate-usage-fill ' + barClass + '" style="width:' + d.percent + '%"></div>' +
					'</div>' +
					'<p>' + d.character_count.toLocaleString() + ' / ' + d.character_limit.toLocaleString() +
					' tecken (' + d.percent + '%)</p>'
				);
			} else {
				$data.html('<span class="cotranslate-error">' + response.data + '</span>');
			}
		});
	});

	// Domänmappning: lägg till rad
	$('#cotranslate-add-domain').on('click', function () {
		var $container = $('#cotranslate-domain-map');
		var $first = $container.find('.cotranslate-domain-row:first');

		if ($first.length) {
			var $clone = $first.clone();
			$clone.find('input').val('');
			$container.append($clone);
		} else {
			// Skapa ny rad
			$container.append(
				'<div class="cotranslate-domain-row">' +
				'<input type="text" class="cotranslate-domain" placeholder="exempel.no" />' +
				'<select class="cotranslate-domain-lang">' +
				$('#cotranslate-default-language').html() +
				'</select>' +
				'<button type="button" class="button cotranslate-remove-domain">Ta bort</button>' +
				'</div>'
			);
		}
	});

	// Domänmappning: ta bort rad
	$(document).on('click', '.cotranslate-remove-domain', function () {
		$(this).closest('.cotranslate-domain-row').remove();
	});

	// Skanna alla sidor och översätt
	$('#cotranslate-scan-all').on('click', function () {
		var $btn = $(this);
		var $status = $('#cotranslate-scan-all-status');

		if (!confirm('Detta skannar alla publicerade sidor för varje språk och översätter strängarna. Kan ta en stund. Fortsätt?')) {
			return;
		}

		$btn.prop('disabled', true);
		var scanAborted = false;
		var totalNewStrings = 0;
		var startTime = Date.now();

		$status.html(
			'<div class="cotranslate-bulk-progress">' +
			'<div class="cotranslate-usage-bar"><div class="cotranslate-usage-fill" id="cotranslate-scan-bar" style="width:0%"></div></div>' +
			'<p id="cotranslate-scan-text">Startar...</p>' +
			'<button type="button" class="button" id="cotranslate-scan-abort">Avbryt</button>' +
			'</div>'
		);

		$('#cotranslate-scan-abort').on('click', function () {
			scanAborted = true;
			$(this).prop('disabled', true).text('Avbryter...');
		});

		function scanBatch(offset, langIndex) {
			if (scanAborted) {
				$('#cotranslate-scan-text').text('Avbruten. ' + totalNewStrings + ' nya strängar samlade.');
				$btn.prop('disabled', false);
				return;
			}

			$.post(cotranslateAdmin.ajaxUrl, {
				action: 'cotranslate_scan_all',
				nonce: cotranslateAdmin.nonce,
				offset: offset,
				lang_index: langIndex
			}, function (response) {
				if (!response.success) {
					$('#cotranslate-scan-text').html('<span class="cotranslate-error">' + response.data + '</span>');
					$btn.prop('disabled', false);
					return;
				}

				var d = response.data;
				totalNewStrings += d.new_strings || 0;

				if (d.done) {
					$('#cotranslate-scan-bar').css('width', '100%');
					$('#cotranslate-scan-text').html(
						'<span class="cotranslate-success">' + d.message +
						' Totalt ' + totalNewStrings + ' nya strängar översatta.</span>'
					);
					$('#cotranslate-scan-abort').hide();
					$btn.prop('disabled', false);
				} else {
					$('#cotranslate-scan-bar').css('width', (d.percent || 0) + '%');
					$('#cotranslate-scan-text').text(d.message || 'Skannar...');
					scanBatch(d.offset, d.lang_index);
				}
			}).fail(function () {
				$('#cotranslate-scan-text').html('<span class="cotranslate-error">Nätverksfel. Försök igen.</span>');
				$btn.prop('disabled', false);
			});
		}

		scanBatch(0, 0);
	});

	// Skanna enskild sida för strängar
	$('#cotranslate-scan-page').on('click', function () {
		var url = $('#cotranslate-scan-url').val();
		var $status = $('#cotranslate-scan-status');

		if (!url) {
			$status.html('<span class="cotranslate-error">Ange en URL.</span>');
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true);
		$status.html('<span class="cotranslate-loading">Skannar sidan...</span>');

		$.post(cotranslateAdmin.ajaxUrl, {
			action: 'cotranslate_scan_page',
			nonce: cotranslateAdmin.nonce,
			url: url
		}, function (response) {
			$btn.prop('disabled', false);
			if (response.success) {
				$status.html('<span class="cotranslate-success">' + response.data.message + '</span>');
			} else {
				$status.html('<span class="cotranslate-error">' + response.data + '</span>');
			}
		}).fail(function () {
			$btn.prop('disabled', false);
			$status.html('<span class="cotranslate-error">Nätverksfel.</span>');
		});
	});

	// Översätt alla köade strängar
	$('#cotranslate-process-strings').on('click', function () {
		var $btn = $(this);
		var $status = $('#cotranslate-process-strings-status');

		$btn.prop('disabled', true);
		$status.html('<span class="cotranslate-loading">Översätter strängar via DeepL...</span>');

		$.post(cotranslateAdmin.ajaxUrl, {
			action: 'cotranslate_process_strings',
			nonce: cotranslateAdmin.nonce
		}, function (response) {
			$btn.prop('disabled', false);
			if (response.success) {
				$status.html('<span class="cotranslate-success">' + response.data.message + '</span>');
			} else {
				$status.html('<span class="cotranslate-error">' + response.data + '</span>');
			}
		}).fail(function () {
			$btn.prop('disabled', false);
			$status.html('<span class="cotranslate-error">Nätverksfel.</span>');
		});
	});

	// Exportera översättningar
	$(document).on('click', '#cotranslate-export-posts, #cotranslate-export-strings', function () {
		var type = $(this).attr('id') === 'cotranslate-export-strings' ? 'strings' : 'posts';

		$.post(cotranslateAdmin.ajaxUrl, {
			action: 'cotranslate_export_translations',
			nonce: cotranslateAdmin.nonce,
			export_type: type
		}, function (response) {
			if (response.success) {
				// Ladda ner som fil
				var blob = new Blob([response.data.csv], { type: 'text/csv;charset=utf-8;' });
				var link = document.createElement('a');
				link.href = URL.createObjectURL(blob);
				link.download = response.data.filename;
				link.click();
			} else {
				alert('Exportfel: ' + response.data);
			}
		});
	});

	// Importera översättningar
	$(document).on('click', '#cotranslate-import-btn', function () {
		var fileInput = document.getElementById('cotranslate-import-file');
		var type = $('#cotranslate-import-type').val();

		if (!fileInput.files.length) {
			alert('Välj en CSV-fil.');
			return;
		}

		var reader = new FileReader();
		reader.onload = function (e) {
			$.post(cotranslateAdmin.ajaxUrl, {
				action: 'cotranslate_import_translations',
				nonce: cotranslateAdmin.nonce,
				csv_data: e.target.result,
				import_type: type
			}, function (response) {
				if (response.success) {
					alert(response.data.message);
				} else {
					alert('Importfel: ' + response.data);
				}
			});
		};
		reader.readAsText(fileInput.files[0]);
	});

	// Migrera från v2
	$('#cotranslate-migrate-v2').on('click', function () {
		if (!confirm('Importera översättningar från Coscribe Translator v2? Befintliga CoTranslate-data bevaras.')) {
			return;
		}

		var $btn = $(this);
		var $status = $('#cotranslate-migrate-status');

		$btn.prop('disabled', true);
		$status.html('<span class="cotranslate-loading">Migrerar...</span>');

		$.post(cotranslateAdmin.ajaxUrl, {
			action: 'cotranslate_migrate_v2',
			nonce: cotranslateAdmin.nonce
		}, function (response) {
			$btn.prop('disabled', false);
			if (response.success) {
				$status.html('<span class="cotranslate-success">' + response.data.message + '</span>');
			} else {
				$status.html('<span class="cotranslate-error">' + response.data + '</span>');
			}
		});
	});

})(jQuery);

/**
 * Kö-loop (delas av Översikt, Ordlista och "Översätt hela sajten").
 *
 * Kör kön i förgrunden tills den är tom och skriver status i angivna element.
 */
(function ($) {
	'use strict';

	/**
	 * @param {jQuery}   $text   Element för statustext.
	 * @param {jQuery}   $bar    Förloppsstapel (fyllnadselementet), valfritt.
	 * @param {Function} done    Callback när kön är tom eller stoppad (ok: bool).
	 */
	window.cotranslateRunQueue = function ($text, $bar, done) {
		var startTotal = null;

		function step() {
			$.post(cotranslateAdmin.ajaxUrl, {
				action: 'cotranslate_process_queue_now',
				nonce: cotranslateAdmin.nonce
			}, function (response) {
				if (!response.success) {
					$text.html('<span class="cotranslate-error">' + response.data + ' Resten körs i bakgrunden.</span>');
					if (done) done(false);
					return;
				}
				var left = response.data.posts + response.data.strings;
				if (startTotal === null) startTotal = Math.max(left, 1);
				if ($bar) $bar.css('width', Math.min(100, Math.round((1 - left / startTotal) * 100)) + '%');

				if (left > 0) {
					$text.text('Översätter... ' + response.data.posts + ' sidor och ' + response.data.strings + ' texter kvar.');
					step();
				} else {
					if ($bar) $bar.css('width', '100%');
					$text.html('<span class="cotranslate-success">Klart! Kön är tom.</span>');
					if (done) done(true);
				}
			}).fail(function () {
				$text.html('<span class="cotranslate-error">Nätverksfel. Resten körs i bakgrunden.</span>');
				if (done) done(false);
			});
		}

		step();
	};
})(jQuery);

/**
 * Ordlista: omöversättningsloop och släpp av handrättningar.
 */
(function ($) {
	'use strict';

	var $progress = $('#cotranslate-glossary-progress');
	if (!$progress.length) return;

	function run() {
		$progress.show();
		window.cotranslateRunQueue($('#cotranslate-glossary-progress-text'), $('#cotranslate-glossary-bar'));
	}

	// Starta loopen automatiskt om något köades vid senaste spar
	if (parseInt($progress.data('pending'), 10) > 0) {
		run();
	}

	// Släpp handrättning och översätt om
	$(document).on('click', '.cotranslate-glossary-release', function () {
		var $btn = $(this);
		$btn.prop('disabled', true).text('Släpper...');

		$.post(cotranslateAdmin.ajaxUrl, {
			action: 'cotranslate_glossary_release',
			nonce: cotranslateAdmin.nonce,
			kind: $btn.data('kind'),
			id: $btn.data('id'),
			language: $btn.data('language')
		}, function (response) {
			if (response.success) {
				$btn.closest('li').fadeOut(200, function () { $(this).remove(); });
				$('#cotranslate-glossary-progress-text').text('Översätter om...');
				run();
			} else {
				$btn.prop('disabled', false).text('Släpp och översätt om');
				$btn.after('<span class="cotranslate-error"> ' + response.data + '</span>');
			}
		});
	});
})(jQuery);

/**
 * Översikt: "Kör kön nu", "Översätt hela sajten" och ihopfällbart infoblock.
 */
(function ($) {
	'use strict';

	if (!$('.cotranslate-overview').length) return;

	var $progress = $('#cotranslate-queue-progress');
	var $text = $('#cotranslate-queue-text');
	var $bar = $('#cotranslate-queue-bar');
	var $runBtn = $('#cotranslate-run-queue');
	var $siteBtn = $('#cotranslate-translate-site');

	function lock(locked) {
		$runBtn.prop('disabled', locked);
		$siteBtn.prop('disabled', locked);
	}

	function reloadSoon() {
		setTimeout(function () { location.reload(); }, 1200);
	}

	// Kör kön nu
	$runBtn.on('click', function () {
		lock(true);
		$progress.show();
		$bar.css('width', '0%');
		$text.text('Startar...');
		window.cotranslateRunQueue($text, $bar, function () { reloadSoon(); });
	});

	// Översätt hela sajten: 1) sidor, 2) hämta texter från alla sidor, 3) kör kön
	$siteBtn.on('click', function () {
		if (!confirm('Går igenom hela sajten och översätter allt som inte redan är översatt eller handrättat. Det kan ta en stund och förbrukar tecken hos översättningsmotorn. Fortsätt?')) {
			return;
		}
		lock(true);
		$progress.show();
		$bar.css('width', '0%');
		$text.text('Steg 1 av 3: översätter sidor...');

		function phasePosts(offset) {
			$.post(cotranslateAdmin.ajaxUrl, {
				action: 'cotranslate_bulk_translate_batch',
				nonce: cotranslateAdmin.nonce,
				offset: offset
			}, function (response) {
				if (!response.success) {
					$text.html('<span class="cotranslate-error">' + response.data + '</span>');
					lock(false);
					return;
				}
				var d = response.data;
				$bar.css('width', Math.round(d.percent / 3) + '%');
				$text.text('Steg 1 av 3: översätter sidor... ' + Math.min(d.offset, d.total) + ' / ' + d.total);
				if (d.done) {
					phaseScan(0, 0);
				} else {
					phasePosts(d.offset);
				}
			}).fail(function () {
				$text.html('<span class="cotranslate-error">Nätverksfel. Försök igen.</span>');
				lock(false);
			});
		}

		function phaseScan(offset, langIndex) {
			$.post(cotranslateAdmin.ajaxUrl, {
				action: 'cotranslate_scan_all',
				nonce: cotranslateAdmin.nonce,
				offset: offset,
				lang_index: langIndex
			}, function (response) {
				if (!response.success) {
					$text.html('<span class="cotranslate-error">' + response.data + '</span>');
					lock(false);
					return;
				}
				var d = response.data;
				if (d.done) {
					$bar.css('width', '67%');
					$text.text('Steg 3 av 3: kör kön...');
					window.cotranslateRunQueue($text, $bar, function () { reloadSoon(); });
				} else {
					$bar.css('width', Math.round(33 + (d.percent || 0) / 3) + '%');
					$text.text('Steg 2 av 3: hämtar texter... ' + (d.message || ''));
					phaseScan(d.offset, d.lang_index);
				}
			}).fail(function () {
				$text.html('<span class="cotranslate-error">Nätverksfel. Försök igen.</span>');
				lock(false);
			});
		}

		phasePosts(0);
	});

	// "Så fungerar det": minns ihopfällt läge per webbläsare
	var howto = document.getElementById('cotranslate-howto');
	if (howto) {
		try {
			if (localStorage.getItem('cotranslateHowtoClosed') === '1') howto.open = false;
		} catch (e) { /* localStorage kan vara blockerat */ }
		howto.addEventListener('toggle', function () {
			try {
				localStorage.setItem('cotranslateHowtoClosed', howto.open ? '0' : '1');
			} catch (e) { /* ignorera */ }
		});
	}
})(jQuery);
