/**
 * Liberty WP Migrator by LibertyDev — Resilient Chunked State-Machine & Backup Scheduler Client
 */
(function () {
	'use strict';

	if (typeof window.LWPM_CONFIG === 'undefined') {
		return;
	}

	const cfg = window.LWPM_CONFIG;
	let currentLang = cfg.activeLang === 'ar' ? 'ar' : 'en';
	let chunkSize = Math.max(256 * 1024, parseInt(cfg.chunkSize, 10) || 1048576);
	let backupsList = Array.isArray(cfg.backups) ? cfg.backups : [];
	let scheduleState = cfg.schedule || {};

	let activeJobId = null;
	let isRunning = false;
	let cancelRequested = false;
	let activeAbortController = null;
	let currentStepKey = 'progress_preparing';
	let toastTimer = null;

	// DOM Elements
	const appEl = document.getElementById('lwpm-app');
	const langBtn = document.getElementById('lwpm-lang-toggle');
	const tabBtns = document.querySelectorAll('.lwpm-tab');
	const panels = {
		export: document.getElementById('lwpm-panel-export'),
		import: document.getElementById('lwpm-panel-import'),
		schedule: document.getElementById('lwpm-panel-schedule'),
		backups: document.getElementById('lwpm-panel-backups'),
	};

	const errorBox = document.getElementById('lwpm-error-box');
	const errorText = document.getElementById('lwpm-error-text');
	const errorClose = document.getElementById('lwpm-error-close');
	const toastBox = document.getElementById('lwpm-toast-box');
	const toastText = document.getElementById('lwpm-toast-text');

	const progressPanel = document.getElementById('lwpm-progress-panel');
	const progressLabel = document.getElementById('lwpm-progress-label');
	const progressDetail = document.getElementById('lwpm-progress-detail');
	const progressPct = document.getElementById('lwpm-progress-pct');
	const progressBar = document.getElementById('lwpm-progress-bar');
	const progressTrack = progressPanel ? progressPanel.querySelector('.lwpm-progress-track') : null;
	const progressLog = document.getElementById('lwpm-progress-log');
	const retryNotice = document.getElementById('lwpm-retry-notice');
	const cancelJobBtn = document.getElementById('lwpm-cancel-job-btn');

	// Export & Backup Shared Ready Banner
	const exportReadyBox = document.getElementById('lwpm-export-ready');
	const exportReadyMeta = document.getElementById('lwpm-export-ready-meta');
	const exportDownloadLink = document.getElementById('lwpm-export-download-link');

	// Export Elements
	const replaceRowsWrap = document.getElementById('lwpm-replace-rows');
	const addReplaceBtn = document.getElementById('lwpm-add-replace-btn');
	const startExportBtn = document.getElementById('lwpm-start-export-btn');

	// Import Elements
	const dropzoneWrap = document.getElementById('lwpm-dropzone-wrap');
	const dropzone = document.getElementById('lwpm-dropzone');
	const fileInput = document.getElementById('lwpm-file-input');
	const serverLimitNote = document.getElementById('lwpm-server-limit-note');
	const inspectBox = document.getElementById('lwpm-inspect-box');
	const confirmImportBtn = document.getElementById('lwpm-confirm-import-btn');
	const cancelInspectBtn = document.getElementById('lwpm-cancel-inspect-btn');
	const importDoneBox = document.getElementById('lwpm-import-done-box');
	const visitSiteLink = document.getElementById('lwpm-visit-site-link');
	const reloadAdminLink = document.getElementById('lwpm-reload-admin-link');

	// Backup & Schedule Elements
	const manualNoteInput = document.getElementById('lwpm-manual-note');
	const manualBackupBtn = document.getElementById('lwpm-manual-backup-btn');
	const schedFreqSelect = document.getElementById('lwpm-sched-frequency');
	const schedDayWrap = document.getElementById('lwpm-sched-day-wrap');
	const saveScheduleBtn = document.getElementById('lwpm-save-schedule-btn');
	const runSchedTestBtn = document.getElementById('lwpm-run-sched-test-btn');
	const schedStateBadge = document.getElementById('lwpm-sched-state-badge');
	const schedNextRunEl = document.getElementById('lwpm-sched-next-run');
	const schedLastRunEl = document.getElementById('lwpm-sched-last-run');

	// Backups Vault Elements
	const backupsCountBadge = document.getElementById('lwpm-backups-count');
	const backupsTable = document.getElementById('lwpm-backups-table');
	const backupsTbody = document.getElementById('lwpm-backups-tbody');
	const backupsEmpty = document.getElementById('lwpm-backups-empty');

	function t(key) {
		const dict = (cfg.i18n && cfg.i18n[currentLang]) ? cfg.i18n[currentLang] : {};
		return dict[key] || key;
	}

	function escHtml(str) {
		const div = document.createElement('div');
		div.textContent = str == null ? '' : String(str);
		return div.innerHTML;
	}

	function formatBytes(bytes) {
		const n = Number(bytes) || 0;
		if (n < 1024) return n + ' B';
		if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
		if (n < 1073741824) return (n / 1048576).toFixed(2) + ' MB';
		return (n / 1073741824).toFixed(2) + ' GB';
	}

	function sleep(ms) {
		return new Promise((resolve) => setTimeout(resolve, ms));
	}

	function showError(msg) {
		if (!errorBox || !errorText) return;
		errorText.textContent = t('error_prefix') + msg;
		errorBox.hidden = false;
		errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}

	function hideError() {
		if (errorBox) errorBox.hidden = true;
	}

	function showToast(msg) {
		if (!toastBox || !toastText) return;
		toastText.textContent = msg;
		toastBox.hidden = false;
		if (toastTimer) clearTimeout(toastTimer);
		toastTimer = setTimeout(() => {
			toastBox.hidden = true;
		}, 4500);
	}

	function updateProgress(stepKey, pct, detailText, logText) {
		if (!progressPanel) return;
		progressPanel.hidden = false;
		if (stepKey) {
			currentStepKey = stepKey;
			progressLabel.textContent = t(stepKey);
		}
		const clamped = Math.max(0, Math.min(100, Math.round(Number(pct) || 0)));
		progressPct.textContent = clamped + '%';
		progressBar.style.width = clamped + '%';
		if (progressTrack) {
			progressTrack.setAttribute('aria-valuenow', String(clamped));
		}
		if (typeof detailText === 'string') {
			progressDetail.textContent = detailText;
		}
		if (typeof logText === 'string') {
			progressLog.textContent = logText;
		}
	}

	function hideProgress() {
		if (progressPanel) progressPanel.hidden = true;
		if (retryNotice) retryNotice.hidden = true;
	}

	function switchTab(tabName) {
		if (isRunning) return;
		tabBtns.forEach((btn) => {
			const match = btn.getAttribute('data-tab') === tabName;
			btn.classList.toggle('is-active', match);
			btn.setAttribute('aria-selected', match ? 'true' : 'false');
		});
		Object.keys(panels).forEach((k) => {
			if (panels[k]) {
				panels[k].hidden = k !== tabName;
				panels[k].classList.toggle('is-active', k === tabName);
			}
		});
	}

	function toggleWeeklyDayVisibility() {
		if (!schedFreqSelect || !schedDayWrap) return;
		schedDayWrap.hidden = schedFreqSelect.value !== 'lwpm_weekly';
	}

	function renderScheduleStatus() {
		if (!schedStateBadge || !schedNextRunEl || !schedLastRunEl) return;
		const enabled = Boolean(scheduleState && scheduleState.settings && scheduleState.settings.enabled);

		schedStateBadge.textContent = enabled ? t('status_active') : t('status_disabled');
		schedStateBadge.className = 'lwpm-stat-value ' + (enabled ? 'lwpm-status-on' : 'lwpm-status-off');

		schedNextRunEl.textContent = (enabled && scheduleState.next_run_str) ? scheduleState.next_run_str : '—';
		schedLastRunEl.textContent = (scheduleState && scheduleState.last_run_str) ? scheduleState.last_run_str : t('status_none');
	}

	function applyLanguage(lang) {
		currentLang = lang === 'ar' ? 'ar' : 'en';
		const dict = cfg.i18n[currentLang];
		if (!dict || !appEl) return;

		appEl.setAttribute('dir', dict.dir);
		appEl.setAttribute('data-lang', currentLang);

		document.querySelectorAll('[data-i18n]').forEach((el) => {
			const k = el.getAttribute('data-i18n');
			if (dict[k]) {
				el.textContent = dict[k];
			}
		});

		document.querySelectorAll('[data-ret-n]').forEach((opt) => {
			const n = opt.getAttribute('data-ret-n');
			if (dict.ret_keep_n) {
				opt.textContent = dict.ret_keep_n.replace('%d', n);
			}
		});

		if (serverLimitNote && dict.server_limit_note) {
			serverLimitNote.textContent = dict.server_limit_note.replace('%s', cfg.maxServerPost || '2 MB');
		}
		if (manualNoteInput && dict.manual_note_placeholder) {
			manualNoteInput.setAttribute('placeholder', dict.manual_note_placeholder);
		}
		const notifyEmailInput = document.getElementById('lwpm-sch-notify-email');
		if (notifyEmailInput && dict.sched_email_placeholder) {
			notifyEmailInput.setAttribute('placeholder', dict.sched_email_placeholder);
		}

		document.querySelectorAll('.lwpm-find-input').forEach((inp) => {
			inp.setAttribute('placeholder', dict.find_placeholder || '');
		});
		document.querySelectorAll('.lwpm-replace-input').forEach((inp) => {
			inp.setAttribute('placeholder', dict.replace_placeholder || '');
		});

		if (!progressPanel.hidden && currentStepKey && dict[currentStepKey]) {
			progressLabel.textContent = dict[currentStepKey];
		}

		renderScheduleStatus();
		renderBackupsTable();
	}

	async function postAjax(action, fields = {}, options = {}) {
		const maxRetries = options.maxRetries != null ? options.maxRetries : 4;
		let attempt = 0;

		while (true) {
			if (cancelRequested && !options.ignoreCancel) {
				throw new Error('CANCELLED');
			}

			const formData = new FormData();
			formData.append('action', action);
			formData.append('nonce', cfg.nonce);

			Object.keys(fields).forEach((k) => {
				if (fields[k] !== undefined && fields[k] !== null) {
					formData.append(k, fields[k]);
				}
			});

			activeAbortController = new AbortController();

			try {
				const resp = await fetch(cfg.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData,
					signal: activeAbortController.signal,
				});

				if (resp.status === 413 && options.isUploadChunk) {
					const err413 = new Error('HTTP_413');
					err413.status = 413;
					throw err413;
				}

				if (resp.status === 502 || resp.status === 503 || resp.status === 504 || resp.status === 408) {
					throw new Error('TRANSIENT_HTTP_' + resp.status);
				}

				const text = await resp.text();
				let json;
				try {
					json = JSON.parse(text);
				} catch (parseErr) {
					const braceIdx = text.indexOf('{"success"');
					if (braceIdx !== -1) {
						json = JSON.parse(text.slice(braceIdx));
					} else {
						throw new Error('Invalid server response (' + resp.status + ')');
					}
				}

				if (retryNotice) retryNotice.hidden = true;

				if (!json || json.success !== true) {
					const msg = (json && json.data && json.data.message) ? json.data.message : 'Operation failed on server.';
					const fatalErr = new Error(msg);
					fatalErr.isServerLogicalError = true;
					throw fatalErr;
				}

				return json.data;
			} catch (err) {
				if (err.name === 'AbortError' || err.message === 'CANCELLED') {
					throw new Error('CANCELLED');
				}
				if (err.status === 413 || err.isServerLogicalError) {
					throw err;
				}

				attempt += 1;
				if (attempt > maxRetries) {
					throw err;
				}

				if (retryNotice) retryNotice.hidden = false;
				const backoffMs = Math.min(8000, 1200 * Math.pow(1.8, attempt - 1));
				await sleep(backoffMs);
			}
		}
	}

	function renderBackupsTable() {
		if (backupsCountBadge) {
			backupsCountBadge.textContent = String(backupsList.length);
		}
		if (!backupsTbody || !backupsTable || !backupsEmpty) return;

		backupsTbody.innerHTML = '';

		if (!backupsList.length) {
			backupsTable.hidden = true;
			backupsEmpty.hidden = false;
			return;
		}

		backupsTable.hidden = false;
		backupsEmpty.hidden = true;

		backupsList.forEach((b) => {
			const trig = b.trigger || 'export';
			let typeLabel = t('type_export');
			let pillClass = 'lwpm-type-export';
			if (trig === 'manual') {
				typeLabel = t('type_manual');
				pillClass = 'lwpm-type-manual';
			} else if (trig === 'scheduled') {
				typeLabel = t('type_scheduled');
				pillClass = 'lwpm-type-scheduled';
			}

			const noteHtml = b.note ? `<span class="lwpm-note-sub">${escHtml(b.note)}</span>` : '';

			const tr = document.createElement('tr');
			tr.innerHTML = `
				<td>
					<strong class="lwpm-ltr">${escHtml(b.filename)}</strong>
					${noteHtml}
				</td>
				<td><span class="lwpm-type-pill ${pillClass}">${escHtml(typeLabel)}</span></td>
				<td><span class="lwpm-ltr">${escHtml(b.source_url || '')}</span></td>
				<td><span class="lwpm-ltr">${escHtml(b.created_at || '')}</span></td>
				<td><span class="lwpm-ltr">${escHtml(b.size_human || '')}</span></td>
				<td>
					<div class="lwpm-row-actions">
						<a href="${escHtml(b.download_url || '#')}" class="lwpm-btn lwpm-btn-primary lwpm-btn-sm">
							<span class="dashicons dashicons-download"></span>
							<span>${escHtml(t('btn_download'))}</span>
						</a>
						<button type="button" class="lwpm-btn lwpm-btn-secondary lwpm-btn-sm lwpm-restore-btn" data-file="${escHtml(b.filename)}">
							<span class="dashicons dashicons-backup"></span>
							<span>${escHtml(t('btn_restore'))}</span>
						</button>
						<button type="button" class="lwpm-btn lwpm-btn-danger-outline lwpm-btn-sm lwpm-delete-btn" data-file="${escHtml(b.filename)}">
							<span class="dashicons dashicons-trash"></span>
							<span>${escHtml(t('btn_delete'))}</span>
						</button>
					</div>
				</td>
			`;
			backupsTbody.appendChild(tr);
		});
	}

	function addReplaceRow(findVal = '', replaceVal = '') {
		if (!replaceRowsWrap) return;
		const row = document.createElement('div');
		row.className = 'lwpm-replace-row';
		row.innerHTML = `
			<input type="text" class="lwpm-find-input" placeholder="${escHtml(t('find_placeholder'))}" value="${escHtml(findVal)}">
			<input type="text" class="lwpm-replace-input" placeholder="${escHtml(t('replace_placeholder'))}" value="${escHtml(replaceVal)}">
			<button type="button" class="lwpm-remove-row-btn" title="Remove">&times;</button>
		`;
		row.querySelector('.lwpm-remove-row-btn').addEventListener('click', () => {
			row.remove();
		});
		replaceRowsWrap.appendChild(row);
	}

	function collectExportOptions() {
		const customReplaces = [];
		document.querySelectorAll('.lwpm-replace-row').forEach((row) => {
			const s = row.querySelector('.lwpm-find-input').value;
			const r = row.querySelector('.lwpm-replace-input').value;
			if (s && s !== r) {
				customReplaces.push({ search: s, replace: r });
			}
		});

		return {
			exclude_cache: document.getElementById('lwpm-exp-exclude-cache').checked,
			exclude_transients: document.getElementById('lwpm-exp-exclude-transients').checked,
			exclude_revisions: document.getElementById('lwpm-exp-exclude-revisions').checked,
			exclude_spam: document.getElementById('lwpm-exp-exclude-spam').checked,
			exclude_media: document.getElementById('lwpm-exp-exclude-media').checked,
			exclude_themes: document.getElementById('lwpm-exp-exclude-themes').checked,
			exclude_plugins: document.getElementById('lwpm-exp-exclude-plugins').checked,
			include_custom_tables: document.getElementById('lwpm-exp-custom-tables').checked,
			custom_replaces: customReplaces,
		};
	}

	/**
	 * Shared chunked runner for Export, 1-Click Manual Backup, and Scheduled Test Backup.
	 */
	async function runArchiveJobLoop(initAction, initPayload) {
		if (isRunning) return;
		hideError();
		exportReadyBox.hidden = true;

		isRunning = true;
		cancelRequested = false;
		if (startExportBtn) startExportBtn.disabled = true;
		if (manualBackupBtn) manualBackupBtn.disabled = true;
		if (runSchedTestBtn) runSchedTestBtn.disabled = true;

		updateProgress('progress_preparing', 2, '', 'init');

		try {
			const startRes = await postAjax(initAction, initPayload);
			activeJobId = startRes.job_id;

			updateProgress(startRes.step_key || 'progress_scanning_files', startRes.percent || 4, startRes.detail || '', startRes.phase || '');

			while (!cancelRequested) {
				const tick = await postAjax('lwpm_export_tick', {
					job_id: activeJobId,
				});

				if (tick.done) {
					updateProgress('progress_finalizing_zip', 100, tick.size_human || '', 'complete');
					if (Array.isArray(tick.backups)) {
						backupsList = tick.backups;
						renderBackupsTable();
					}
					if (tick.schedule) {
						scheduleState = tick.schedule;
						renderScheduleStatus();
					}
					exportReadyMeta.textContent = `${tick.filename} (${tick.size_human})`;
					exportDownloadLink.setAttribute('href', tick.download_url);
					exportReadyBox.hidden = false;
					if (manualNoteInput && initAction === 'lwpm_manual_backup_start') {
						manualNoteInput.value = '';
					}
					hideProgress();
					break;
				}

				updateProgress(tick.step_key, tick.percent, tick.detail || '', tick.phase || '');
			}
		} catch (err) {
			hideProgress();
			if (err.message !== 'CANCELLED') {
				showError(err.message || 'Archive creation failed.');
			}
		} finally {
			isRunning = false;
			activeJobId = null;
			if (startExportBtn) startExportBtn.disabled = false;
			if (manualBackupBtn) manualBackupBtn.disabled = false;
			if (runSchedTestBtn) runSchedTestBtn.disabled = false;
		}
	}

	async function saveScheduleSettings() {
		if (isRunning) return;
		hideError();
		saveScheduleBtn.disabled = true;

		const payload = {
			enabled: document.getElementById('lwpm-sched-enabled').checked,
			frequency: document.getElementById('lwpm-sched-frequency').value,
			day_of_week: parseInt(document.getElementById('lwpm-sched-day').value, 10) || 5,
			run_time: document.getElementById('lwpm-sched-time').value || '03:00',
			retention: parseInt(document.getElementById('lwpm-sched-retention').value, 10) || 0,
			exclude_cache: document.getElementById('lwpm-sch-exclude-cache').checked,
			exclude_transients: document.getElementById('lwpm-sch-exclude-transients').checked,
			exclude_revisions: document.getElementById('lwpm-sch-exclude-revisions').checked,
			exclude_spam: document.getElementById('lwpm-sch-exclude-spam').checked,
			exclude_media: document.getElementById('lwpm-sch-exclude-media').checked,
			notify_enabled: document.getElementById('lwpm-sch-notify-enabled').checked,
			notify_email: document.getElementById('lwpm-sch-notify-email').value || '',
		};

		try {
			const res = await postAjax('lwpm_save_schedule', {
				schedule: JSON.stringify(payload),
			});
			if (res.schedule) {
				scheduleState = res.schedule;
				renderScheduleStatus();
			}
			if (Array.isArray(res.backups)) {
				backupsList = res.backups;
				renderBackupsTable();
			}
			showToast(t('schedule_saved_msg'));
		} catch (err) {
			showError(err.message || 'Could not save schedule settings.');
		} finally {
			saveScheduleBtn.disabled = false;
		}
	}

	function showInspectionCard(info) {
		activeJobId = info.job_id;
		dropzoneWrap.hidden = true;
		importDoneBox.hidden = true;

		document.getElementById('lwpm-ins-source').textContent = info.source_url || '-';
		document.getElementById('lwpm-ins-dest').textContent = info.dest_url || '-';
		document.getElementById('lwpm-ins-versions').textContent = `WP ${info.wp_version || '-'} / PHP ${info.php_version || '-'}`;
		document.getElementById('lwpm-ins-files').textContent = info.files_count || '0';
		document.getElementById('lwpm-ins-tables').textContent = String(info.tables_count || 0);
		document.getElementById('lwpm-ins-size').textContent = info.data_size_human || '-';

		inspectBox.hidden = false;
	}

	function resetImportView() {
		inspectBox.hidden = true;
		dropzoneWrap.hidden = false;
		if (fileInput) fileInput.value = '';
	}

	async function handleFileSelected(file) {
		if (!file || isRunning) return;
		hideError();
		exportReadyBox.hidden = true;
		importDoneBox.hidden = true;

		if (!file.name.toLowerCase().endsWith('.zip')) {
			showError(t('invalid_zip_ext'));
			return;
		}

		isRunning = true;
		cancelRequested = false;
		dropzoneWrap.hidden = true;

		updateProgress('progress_uploading_chunks', 1, `0 / ${formatBytes(file.size)}`, 'upload');

		try {
			const initRes = await postAjax('lwpm_import_init_upload', {
				filename: file.name,
				total_size: file.size,
			});

			activeJobId = initRes.job_id;
			let offset = 0;

			while (offset < file.size) {
				if (cancelRequested) {
					throw new Error('CANCELLED');
				}

				const end = Math.min(file.size, offset + chunkSize);
				const blobSlice = file.slice(offset, end);

				try {
					const chunkRes = await postAjax(
						'lwpm_import_upload_chunk',
						{
							job_id: activeJobId,
							offset: offset,
							chunk: new File([blobSlice], 'chunk.part', { type: 'application/octet-stream' }),
						},
						{ isUploadChunk: true }
					);

					offset = Number(chunkRes.uploaded_bytes) || end;
					const uploadPct = Math.max(1, Math.min(95, Math.floor((offset / file.size) * 95)));
					updateProgress(
						'progress_uploading_chunks',
						uploadPct,
						`${formatBytes(offset)} / ${formatBytes(file.size)}`,
						`chunk ${formatBytes(chunkSize)}`
					);
				} catch (chunkErr) {
					if (chunkErr.status === 413 && chunkSize > 256 * 1024) {
						chunkSize = Math.max(256 * 1024, Math.floor(chunkSize / 2));
						continue;
					}
					throw chunkErr;
				}
			}

			updateProgress('progress_inspecting_zip', 98, file.name, 'inspect');
			const inspectInfo = await postAjax('lwpm_import_inspect', {
				job_id: activeJobId,
			});

			hideProgress();
			isRunning = false;
			showInspectionCard(inspectInfo);
		} catch (err) {
			hideProgress();
			isRunning = false;
			activeJobId = null;
			resetImportView();
			if (err.message !== 'CANCELLED') {
				showError(err.message || 'Upload failed.');
			}
		}
	}

	async function startRestoreFromSavedBackup(filename) {
		if (isRunning) return;
		hideError();
		exportReadyBox.hidden = true;
		switchTab('import');
		dropzoneWrap.hidden = true;
		inspectBox.hidden = true;
		importDoneBox.hidden = true;

		isRunning = true;
		cancelRequested = false;
		updateProgress('progress_inspecting_zip', 50, filename, 'inspect');

		try {
			const inspectInfo = await postAjax('lwpm_import_from_backup', {
				filename: filename,
			});
			hideProgress();
			isRunning = false;
			showInspectionCard(inspectInfo);
		} catch (err) {
			hideProgress();
			isRunning = false;
			resetImportView();
			if (err.message !== 'CANCELLED') {
				showError(err.message || 'Could not inspect backup.');
			}
		}
	}

	async function executeConfirmedImport() {
		if (isRunning || !activeJobId) return;
		hideError();

		const jobId = activeJobId;
		const importOptions = {
			keep_current_admin: document.getElementById('lwpm-imp-keep-admin').checked,
			skip_guid: document.getElementById('lwpm-imp-skip-guid').checked,
		};

		inspectBox.hidden = true;
		isRunning = true;
		cancelRequested = false;

		updateProgress('progress_extracting_files', 8, '', 'confirm');

		try {
			const startRes = await postAjax('lwpm_import_confirm', {
				job_id: jobId,
				import_options: JSON.stringify(importOptions),
			});

			updateProgress(startRes.step_key || 'progress_extracting_files', startRes.percent || 10, startRes.detail || '', startRes.phase || '');

			while (!cancelRequested) {
				const tick = await postAjax('lwpm_import_tick', {
					job_id: jobId,
				});

				if (tick.done) {
					if (tick.new_nonce) {
						cfg.nonce = tick.new_nonce;
					}
					updateProgress('progress_swapping_tables', 100, '', 'complete');
					hideProgress();

					if (tick.home_url && visitSiteLink) {
						visitSiteLink.setAttribute('href', tick.home_url);
					}
					if (tick.admin_url && reloadAdminLink) {
						reloadAdminLink.setAttribute('href', tick.admin_url);
					}
					importDoneBox.hidden = false;
					break;
				}

				updateProgress(tick.step_key, tick.percent, tick.detail || '', tick.phase || '');
			}
		} catch (err) {
			hideProgress();
			resetImportView();
			if (err.message !== 'CANCELLED') {
				showError(err.message || 'Import failed.');
			}
		} finally {
			isRunning = false;
			activeJobId = null;
		}
	}

	async function cancelActiveJob() {
		const jobToCancel = activeJobId;
		cancelRequested = true;
		if (activeAbortController) {
			try {
				activeAbortController.abort();
			} catch (e) {}
		}

		hideProgress();
		resetImportView();

		if (jobToCancel) {
			activeJobId = null;
			try {
				await postAjax('lwpm_cancel_job', { job_id: jobToCancel }, { ignoreCancel: true, maxRetries: 1 });
			} catch (e) {}
		}
	}

	// ==================== EVENT BINDINGS ====================

	tabBtns.forEach((btn) => {
		btn.addEventListener('click', () => {
			switchTab(btn.getAttribute('data-tab'));
		});
	});

	if (langBtn) {
		langBtn.addEventListener('click', () => {
			const next = currentLang === 'ar' ? 'en' : 'ar';
			applyLanguage(next);
			postAjax('lwpm_set_lang', { lang: next }, { ignoreCancel: true, maxRetries: 0 }).catch(() => {});
		});
	}

	if (errorClose) {
		errorClose.addEventListener('click', hideError);
	}

	if (addReplaceBtn) {
		addReplaceBtn.addEventListener('click', () => addReplaceRow());
	}

	if (startExportBtn) {
		startExportBtn.addEventListener('click', () => {
			runArchiveJobLoop('lwpm_export_start', {
				options: JSON.stringify(collectExportOptions()),
			});
		});
	}

	if (manualBackupBtn) {
		manualBackupBtn.addEventListener('click', () => {
			const noteVal = manualNoteInput ? manualNoteInput.value.trim() : '';
			runArchiveJobLoop('lwpm_manual_backup_start', {
				note: noteVal,
			});
		});
	}

	if (schedFreqSelect) {
		schedFreqSelect.addEventListener('change', toggleWeeklyDayVisibility);
	}

	if (saveScheduleBtn) {
		saveScheduleBtn.addEventListener('click', saveScheduleSettings);
	}

	if (runSchedTestBtn) {
		runSchedTestBtn.addEventListener('click', () => {
			runArchiveJobLoop('lwpm_run_scheduled_test', {});
		});
	}

	if (dropzone && fileInput) {
		dropzone.addEventListener('click', () => {
			if (!isRunning) fileInput.click();
		});
		dropzone.addEventListener('keydown', (e) => {
			if ((e.key === 'Enter' || e.key === ' ') && !isRunning) {
				e.preventDefault();
				fileInput.click();
			}
		});
		fileInput.addEventListener('change', () => {
			if (fileInput.files && fileInput.files[0]) {
				handleFileSelected(fileInput.files[0]);
			}
		});

		['dragenter', 'dragover'].forEach((evtName) => {
			dropzone.addEventListener(evtName, (e) => {
				e.preventDefault();
				e.stopPropagation();
				if (!isRunning) dropzone.classList.add('is-dragover');
			});
		});

		['dragleave', 'drop'].forEach((evtName) => {
			dropzone.addEventListener(evtName, (e) => {
				e.preventDefault();
				e.stopPropagation();
				dropzone.classList.remove('is-dragover');
			});
		});

		dropzone.addEventListener('drop', (e) => {
			if (isRunning) return;
			const dt = e.dataTransfer;
			if (dt && dt.files && dt.files[0]) {
				handleFileSelected(dt.files[0]);
			}
		});
	}

	if (confirmImportBtn) {
		confirmImportBtn.addEventListener('click', executeConfirmedImport);
	}
	if (cancelInspectBtn) {
		cancelInspectBtn.addEventListener('click', cancelActiveJob);
	}
	if (cancelJobBtn) {
		cancelJobBtn.addEventListener('click', cancelActiveJob);
	}

	if (backupsTbody) {
		backupsTbody.addEventListener('click', async (e) => {
			const restoreBtn = e.target.closest('.lwpm-restore-btn');
			if (restoreBtn) {
				const file = restoreBtn.getAttribute('data-file');
				if (file) startRestoreFromSavedBackup(file);
				return;
			}

			const deleteBtn = e.target.closest('.lwpm-delete-btn');
			if (deleteBtn && !isRunning) {
				const file = deleteBtn.getAttribute('data-file');
				if (!file || !window.confirm(t('confirm_delete'))) {
					return;
				}
				deleteBtn.disabled = true;
				try {
					const res = await postAjax('lwpm_delete_backup', { filename: file });
					if (Array.isArray(res.backups)) {
						backupsList = res.backups;
						renderBackupsTable();
					}
				} catch (err) {
					deleteBtn.disabled = false;
					showError(err.message || 'Delete failed.');
				}
			}
		});
	}

	window.addEventListener('beforeunload', (e) => {
		if (isRunning) {
			e.preventDefault();
			e.returnValue = '';
		}
	});

	// Initial UI setup
	toggleWeeklyDayVisibility();
	renderScheduleStatus();
	renderBackupsTable();
})();
