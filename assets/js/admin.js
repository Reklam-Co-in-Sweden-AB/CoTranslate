/**
 * CoTranslate admin JavaScript
 */
(function ($) {
	'use strict';

	// Flikar
	$(document).on('click', '.cotranslate-tab', function () {
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
			claude_prompt: $('#cotranslate-claude-prompt').val(),
			default_language: $('#cotranslate-default-language').val(),
			enabled_languages: enabledLanguages,
			post_types: postTypes,
			translate_slugs: $('#cotranslate-translate-slugs').is(':checked') ? 1 : 0,
			frontend_editor: $('#cotranslate-frontend-editor').is(':checked') ? 1 : 0,
			floating_switcher: $('#cotranslate-floating-switcher').is(':checked') ? 1 : 0,
			floating_position: $('#cotranslate-floating-position').val(),
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

	// Återställ översättning
	$(document).on('click', '.cotranslate-reset-translation', function () {
		if (!confirm('Återställ till automatisk översättning? Den manuella overriden tas bort.')) {
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
		if (!confirm('Radera denna sträng?')) return;

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
