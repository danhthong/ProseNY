(function ($) {
	'use strict';

	const config = window.nycfcAdmin || {};
	let pollTimer = null;
	let batchRunning = false;

	function ajax(action, data) {
		const payload = Object.assign({}, data || {}, {
			action: action,
			nonce: config.nonce
		});

		return $.ajax({
			url: config.ajaxUrl,
			method: 'POST',
			data: payload
		});
	}

	function formatStatus(status) {
		if (!status) {
			return 'Idle';
		}

		return status.charAt(0).toUpperCase() + status.slice(1);
	}

	function formatEta(minutes) {
		if (!isFinite(minutes) || minutes <= 0) {
			return 'N/A';
		}

		if (minutes < 1) {
			return '< 1 minute';
		}

		return Math.ceil(minutes) + ' minutes';
	}

	function calculateSpeed(processed, startedAt) {
		if (!startedAt || processed <= 0) {
			return 0;
		}

		const elapsedMinutes = (Date.now() / 1000 - startedAt) / 60;

		if (elapsedMinutes <= 0) {
			return 0;
		}

		return Math.round(processed / elapsedMinutes);
	}

	function calculateEta(total, processed, speed) {
		if (speed <= 0) {
			return 'N/A';
		}

		const remaining = Math.max(0, total - processed);
		return formatEta(remaining / speed);
	}

	function updateLog(logEntries) {
		const $log = $('#nycfc-activity-log');

		if (!$log.length || !Array.isArray(logEntries)) {
			return;
		}

		$log.empty();

		logEntries.forEach(function (entry) {
			const time = entry.time || '';
			const message = entry.message || '';
			$log.append(
				$('<div>', {
					class: 'nycfc-log-entry',
					text: '[' + time + '] ' + message
				})
			);
		});

		$log.scrollTop($log[0].scrollHeight);
	}

	function updateButtons(progress, hasRows, hasExport) {
		const status = progress.crawl_status || 'idle';

		$('#nycfc-start-btn').prop('disabled', !hasRows || status === 'running');
		$('#nycfc-pause-btn').prop('disabled', status !== 'running');
		$('#nycfc-resume-btn').prop('disabled', status !== 'paused');

		if (hasExport) {
			$('#nycfc-download-btn').show();
		} else {
			$('#nycfc-download-btn').hide();
		}
	}

	function updateProgressBar(total, processed) {
		const percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
		$('#nycfc-progress-bar').css('width', percent + '%');
	}

	function updateUi(payload) {
		const progress = payload.progress || {};
		const total = parseInt(progress.total_rows, 10) || 0;
		const processed = parseInt(progress.processed_rows, 10) || 0;
		const success = parseInt(progress.success_rows, 10) || 0;
		const failed = parseInt(progress.failed_rows, 10) || 0;
		const currentRow = parseInt(progress.current_row, 10) || 0;
		const startedAt = parseInt(progress.started_at, 10) || 0;
		const remaining = parseInt(payload.rows_remaining, 10);
		const speed = calculateSpeed(processed, startedAt);
		const status = progress.crawl_status || 'idle';

		updateProgressBar(total, processed);

		$('#nycfc-current-row').text(currentRow);
		$('#nycfc-current-url').text(progress.current_url || 'None');
		$('#nycfc-progress-text').text(processed + ' / ' + total);
		$('#nycfc-rows-completed').text(processed);
		$('#nycfc-rows-remaining').text(isNaN(remaining) ? Math.max(0, total - processed) : remaining);
		$('#nycfc-success-count').text(success);
		$('#nycfc-failed-count').text(failed);
		$('#nycfc-speed').text(speed + ' rows/min');
		$('#nycfc-eta').text(calculateEta(total, processed, speed));
		$('#nycfc-status').text(formatStatus(status));

		if (payload.download_url) {
			$('#nycfc-download-btn').attr('href', payload.download_url);
		}

		updateButtons(progress, !!payload.has_rows, !!payload.has_export);
		updateLog(payload.log || []);

		if (status === 'running' && !batchRunning) {
			runBatchLoop();
		}

		if (status !== 'running') {
			stopBatchLoop();
		}
	}

	function showMessage($el, message, isError) {
		$el
			.removeClass('is-error is-success')
			.addClass(isError ? 'is-error' : 'is-success')
			.text(message)
			.show();
	}

	function pollProgress() {
		ajax('nycfc_get_progress')
			.done(function (response) {
				if (response.success) {
					updateUi(response.data);
				}
			});
	}

	function startPolling() {
		if (pollTimer) {
			return;
		}

		pollTimer = window.setInterval(pollProgress, config.pollMs || 2000);
	}

	function stopPolling() {
		if (pollTimer) {
			window.clearInterval(pollTimer);
			pollTimer = null;
		}
	}

	function runBatchLoop() {
		if (batchRunning) {
			return;
		}

		batchRunning = true;

		function nextBatch() {
			ajax('nycfc_process_batch')
				.done(function (response) {
					if (!response.success) {
						batchRunning = false;
						return;
					}

					updateUi(response.data);

					const status = (response.data.progress || {}).crawl_status;
					const complete = !!response.data.complete;

					if (status === 'running' && !complete) {
						nextBatch();
						return;
					}

					batchRunning = false;
				})
				.fail(function () {
					batchRunning = false;
				});
		}

		nextBatch();
	}

	function stopBatchLoop() {
		batchRunning = false;
	}

	$(function () {
		startPolling();
		pollProgress();

		$('#nycfc-upload-form').on('submit', function (event) {
			event.preventDefault();

			const fileInput = document.getElementById('nycfc-csv-file');
			const $message = $('#nycfc-upload-message');

			if (!fileInput || !fileInput.files.length) {
				showMessage($message, config.strings.uploadError, true);
				return;
			}

			const formData = new FormData();
			formData.append('action', 'nycfc_upload_csv');
			formData.append('nonce', config.nonce);
			formData.append('csv_file', fileInput.files[0]);

			$('#nycfc-upload-btn').prop('disabled', true);

			$.ajax({
				url: config.ajaxUrl,
				method: 'POST',
				data: formData,
				processData: false,
				contentType: false
			})
				.done(function (response) {
					if (response.success) {
						showMessage($message, response.data.message || config.strings.uploadSuccess, false);
						updateUi(response.data);
					} else {
						showMessage($message, (response.data && response.data.message) || config.strings.uploadError, true);
					}
				})
				.fail(function () {
					showMessage($message, config.strings.uploadError, true);
				})
				.always(function () {
					$('#nycfc-upload-btn').prop('disabled', false);
				});
		});

		$('#nycfc-start-btn').on('click', function () {
			ajax('nycfc_start_crawl').done(function (response) {
				if (response.success) {
					updateUi(response.data);
					runBatchLoop();
				}
			});
		});

		$('#nycfc-pause-btn').on('click', function () {
			ajax('nycfc_pause_crawl').done(function (response) {
				if (response.success) {
					updateUi(response.data);
					stopBatchLoop();
				}
			});
		});

		$('#nycfc-resume-btn').on('click', function () {
			ajax('nycfc_resume_crawl').done(function (response) {
				if (response.success) {
					updateUi(response.data);
					runBatchLoop();
				}
			});
		});

		$('#nycfc-reset-btn').on('click', function () {
			if (!window.confirm('Reset crawl progress and delete stored data?')) {
				return;
			}

			stopBatchLoop();

			ajax('nycfc_reset_crawl').done(function (response) {
				if (response.success) {
					updateUi(response.data);
					$('#nycfc-upload-message').hide().text('');
					$('#nycfc-csv-file').val('');
				}
			});
		});
	});
})(jQuery);
