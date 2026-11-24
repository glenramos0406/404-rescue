(function () {
	'use strict';

	var cfg = window.rescue404Admin || {};

	var selectAll = document.getElementById('rescue404-select-all');
	if (selectAll) {
		selectAll.addEventListener('change', function () {
			var boxes = document.querySelectorAll('.rescue404-row-check');
			Array.prototype.forEach.call(boxes, function (box) {
				box.checked = selectAll.checked;
			});
		});
	}

	var bulk = document.getElementById('rescue404-bulk');
	if (bulk) {
		bulk.addEventListener('submit', function (event) {
			var select = bulk.querySelector('[name="bulk_action"]');
			if (!select || !select.value) {
				event.preventDefault();
				window.alert(cfg.failed || 'Choose an action first.');
				return;
			}
			if (select.value === 'delete') {
				var message = bulk.getAttribute('data-confirm-delete') || 'Delete the selected broken URLs?';
				if (!window.confirm(message)) {
					event.preventDefault();
				}
			}
		});
	}

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!form || form.getAttribute('data-ajax') !== 'toggle') {
			return;
		}
		if (!cfg.ajaxUrl || !window.fetch) {
			return;
		}
		event.preventDefault();
		var button = form.querySelector('button');
		var row = form.closest('tr');
		var data = new window.FormData();
		data.append('action', 'rescue404_toggle_redirect');
		data.append('nonce', cfg.nonce || '');
		data.append('redirect_id', form.querySelector('[name="redirect_id"]').value);
		data.append('active', form.querySelector('[name="active"]').value);
		if (button) {
			button.disabled = true;
			button.setAttribute('aria-busy', 'true');
		}
		window.fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success) {
				throw new Error((payload && payload.data && payload.data.message) || cfg.failed);
			}
			var active = String(payload.data.active) === '1';
			var hidden = form.querySelector('[name="active"]');
			if (hidden) {
				hidden.value = active ? '0' : '1';
			}
			if (button) {
				button.textContent = payload.data.label;
			}
			if (row) {
				var badge = row.querySelector('.rescue404-redirect-status');
				if (badge) {
					badge.textContent = payload.data.status;
					badge.classList.toggle('is-success', active);
					badge.classList.toggle('is-muted', !active);
				}
			}
		}).catch(function () {
			window.alert(cfg.failed || 'Something went wrong. Reload the page and try again.');
		}).finally(function () {
			if (button) {
				button.disabled = false;
				button.removeAttribute('aria-busy');
			}
		});
	});

	var findButton = document.getElementById('rescue404-find-page');
	var sourceInput = document.getElementById('rescue404-source');
	var destinationInput = document.getElementById('rescue404-destination');
	var suggestionBox = document.getElementById('rescue404-suggestions');

	function useSuggestion(event) {
		var button = event.currentTarget;
		if (destinationInput && button.getAttribute('data-url')) {
			destinationInput.value = button.getAttribute('data-url');
			destinationInput.focus();
		}
	}

	function renderSuggestions(items) {
		if (!suggestionBox) {
			return;
		}
		suggestionBox.textContent = '';
		if (!items || !items.length) {
			var empty = document.createElement('p');
			empty.className = 'rescue404-field-help';
			empty.textContent = cfg.empty || 'No close page was found. Type the destination yourself.';
			suggestionBox.appendChild(empty);
			return;
		}
		items.forEach(function (item) {
			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'rescue404-suggestion';
			button.setAttribute('data-url', item.url || '');
			var title = document.createElement('strong');
			title.textContent = item.title || item.url || '';
			var meta = document.createElement('span');
			meta.textContent = [item.label, item.kind].filter(Boolean).join(' · ');
			button.appendChild(title);
			button.appendChild(meta);
			button.addEventListener('click', useSuggestion);
			suggestionBox.appendChild(button);
		});
	}

	if (suggestionBox) {
		Array.prototype.forEach.call(suggestionBox.querySelectorAll('.rescue404-suggestion'), function (button) {
			button.addEventListener('click', useSuggestion);
		});
	}

	if (findButton && sourceInput && cfg.ajaxUrl && window.fetch) {
		findButton.addEventListener('click', function (event) {
			event.preventDefault();
			var data = new window.FormData();
			data.append('action', 'rescue404_suggest');
			data.append('nonce', cfg.nonce || '');
			data.append('source', sourceInput.value || '');
			findButton.disabled = true;
			findButton.setAttribute('aria-busy', 'true');
			window.fetch(cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			}).then(function (response) {
				return response.json();
			}).then(function (payload) {
				if (!payload || !payload.success) {
					throw new Error((payload && payload.data && payload.data.message) || cfg.failed);
				}
				renderSuggestions(payload.data.items || []);
			}).catch(function (error) {
				if (suggestionBox) {
					suggestionBox.textContent = '';
					var note = document.createElement('p');
					note.className = 'rescue404-field-help';
					note.textContent = error && error.message ? error.message : (cfg.failed || '');
					suggestionBox.appendChild(note);
				}
			}).finally(function () {
				findButton.disabled = false;
				findButton.removeAttribute('aria-busy');
			});
		});
	}
})();
