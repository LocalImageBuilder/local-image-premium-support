(function () {
	'use strict';

	if (typeof lipsImageAlts === 'undefined') {
		return;
	}

	const config = lipsImageAlts;
	const form = document.querySelector('.lips-alt-builder-form');
	const previewField = document.getElementById('lips-alt-preview-value');
	const scanButton = document.getElementById('lips-alt-run-scan');
	const progressWrap = document.getElementById('lips-alt-scan-progress');
	const progressBar = document.getElementById('lips-alt-scan-progress-bar');
	const progressText = document.getElementById('lips-alt-scan-progress-text');
	const summaryWrap = document.getElementById('lips-alt-scanner-summary');
	const resultsWrap = document.getElementById('lips-alt-scanner-results');
	const resultsBody = document.getElementById('lips-alt-scanner-body');
	const statTotal = document.getElementById('lips-alt-stat-total');
	const statMissing = document.getElementById('lips-alt-stat-missing');
	const statWith = document.getElementById('lips-alt-stat-with');
	const lastScan = document.getElementById('lips-alt-scan-last');
	const lastScanTime = document.getElementById('lips-alt-scan-last-time');
	const idleLabel = scanButton && scanButton.textContent.trim() === config.i18n.rescan
		? config.i18n.rescan
		: config.i18n.runScan;

	function getSelectedPostTypes() {
		return Array.from(document.querySelectorAll('[data-lips-scan-post-type]:checked')).map(function (input) {
			return input.value;
		});
	}

	function getSelectedSettings() {
		return {
			page_title: !!form?.querySelector('[data-lips-alt-part="page_title"]')?.checked,
			site_title: !!form?.querySelector('[data-lips-alt-part="site_title"]')?.checked,
			page_slug: !!form?.querySelector('[data-lips-alt-part="page_slug"]')?.checked,
			focus_keyword: !!form?.querySelector('[data-lips-alt-part="focus_keyword"]')?.checked,
		};
	}

	function buildPreview() {
		if (!previewField || !form) {
			return;
		}

		const settings = getSelectedSettings();
		const parts = [];

		if (settings.page_title && config.preview.page_title) {
			parts.push(config.preview.page_title);
		}
		if (settings.site_title && config.preview.site_title) {
			parts.push(config.preview.site_title);
		}
		if (settings.page_slug && config.preview.page_slug) {
			parts.push(config.preview.page_slug);
		}
		if (settings.focus_keyword && config.smartcrawlActive && config.preview.focus_keyword) {
			parts.push(config.preview.focus_keyword);
		}

		previewField.value = parts.join(config.separator);
	}

	function bindPreview() {
		if (!form) {
			return;
		}

		form.querySelectorAll('[data-lips-alt-part]').forEach(function (input) {
			input.addEventListener('change', buildPreview);
		});

		buildPreview();
	}

	function updateSummary(total, missing, withAlt) {
		statTotal.textContent = String(total);
		statMissing.textContent = String(missing);
		statWith.textContent = String(withAlt);
		summaryWrap.hidden = false;
	}

	function updateProgress(processed, total) {
		const percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
		progressBar.style.width = percent + '%';
		progressText.textContent = processed + ' / ' + total;
	}

	function appendRows(rows) {
		const fragment = document.createDocumentFragment();

		rows.forEach(function (row) {
			const tr = document.createElement('tr');

			const thumbCell = document.createElement('td');
			if (row.thumbnail) {
				const img = document.createElement('img');
				img.src = row.thumbnail;
				img.alt = '';
				img.width = 50;
				img.height = 50;
				img.className = 'lips-alt-scanner__thumb';
				thumbCell.appendChild(img);
			} else {
				thumbCell.textContent = '—';
			}

			const fileCell = document.createElement('td');
			fileCell.textContent = row.filename || '—';

			const urlCell = document.createElement('td');
			if (row.url) {
				const link = document.createElement('a');
				link.href = row.url;
				link.target = '_blank';
				link.rel = 'noopener noreferrer';
				link.textContent = row.url;
				urlCell.appendChild(link);
			} else {
				urlCell.textContent = '—';
			}

			const storedCell = document.createElement('td');
			storedCell.textContent = row.stored_alt ? row.stored_alt : config.i18n.none;
			if (!row.stored_alt) {
				storedCell.className = 'is-muted';
			}

			const renderedCell = document.createElement('td');
			if (row.effective_alt) {
				renderedCell.appendChild(document.createTextNode(row.effective_alt));
				if (row.is_dynamic) {
					const badge = document.createElement('span');
					badge.className = 'lips-alt-scanner__badge';
					badge.textContent = config.i18n.dynamic;
					renderedCell.appendChild(document.createElement('br'));
					renderedCell.appendChild(badge);
				}
			} else {
				renderedCell.textContent = config.i18n.none;
				renderedCell.className = 'is-missing';
			}

			const usageCell = document.createElement('td');
			usageCell.textContent = row.usage || '—';

			tr.appendChild(thumbCell);
			tr.appendChild(fileCell);
			tr.appendChild(urlCell);
			tr.appendChild(storedCell);
			tr.appendChild(renderedCell);
			tr.appendChild(usageCell);
			fragment.appendChild(tr);
		});

		resultsBody.appendChild(fragment);
	}

	async function runScan() {
		if (!scanButton || !resultsBody) {
			return;
		}

		scanButton.disabled = true;
		scanButton.textContent = config.i18n.scanning;
		resultsBody.innerHTML = '';
		resultsWrap.hidden = true;
		progressWrap.hidden = false;

		let offset = 0;
		let totalPosts = 0;
		let imagesFound = 0;
		let missing = 0;
		let withAlt = 0;
		let scannedAt = '';
		let completed = false;
		const postTypes = getSelectedPostTypes();

		if (!postTypes.length) {
			window.alert(config.i18n.selectPostType);
			scanButton.disabled = false;
			scanButton.textContent = idleLabel;
			return;
		}

		try {
			do {
				const body = new URLSearchParams();
				body.append('action', 'lips_scan_images');
				body.append('nonce', config.scanNonce);
				body.append('offset', String(offset));
				postTypes.forEach(function (postType) {
					body.append('post_types[]', postType);
				});

				const response = await fetch(config.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
					},
					body: body.toString(),
				});

				const payload = await response.json();

				if (!payload.success) {
					throw new Error('scan failed');
				}

				totalPosts = Math.max(totalPosts, payload.data.total || 0);

				if (payload.data.scanned_at) {
					scannedAt = payload.data.scanned_at;
				}

				if (!payload.data.batch) {
					updateSummary(imagesFound, missing, withAlt);
					updateProgress(Math.min(offset, totalPosts), totalPosts);
					break;
				}

				offset += payload.data.batch;
				imagesFound = payload.data.images_found || imagesFound;

				payload.data.rows.forEach(function (row) {
					if (row.has_alt) {
						withAlt += 1;
					} else {
						missing += 1;
					}
				});

				if (payload.data.rows.length) {
					appendRows(payload.data.rows);
					resultsWrap.hidden = false;
				}

				updateSummary(imagesFound, missing, withAlt);
				updateProgress(Math.min(offset, totalPosts), totalPosts);
			} while (offset < totalPosts);

			completed = true;
		} catch (error) {
			window.alert(config.i18n.scanError);
		}

		scanButton.disabled = false;
		scanButton.textContent = completed ? config.i18n.rescan : idleLabel;

		if (completed && scannedAt && lastScan && lastScanTime) {
			lastScanTime.textContent = scannedAt;
			lastScan.hidden = false;
		}

		if (imagesFound === 0) {
			resultsBody.innerHTML = '<tr><td colspan="6">' + config.i18n.noResults + '</td></tr>';
			resultsWrap.hidden = false;
		}
	}

	if (scanButton) {
		scanButton.addEventListener('click', runScan);
	}

	bindPreview();
})();
