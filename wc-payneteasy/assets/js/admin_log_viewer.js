jQuery($ => {
	function anyExpanded()
		{ return $('#pne-log-entries .pne-log-entry-body').filter((_, that) => $(that).is(':visible')).length > 0 }

	function updateToggleLabel()
		{ $('#pne-log-toggle-all').text(anyExpanded() ? 'Collapse all' : 'Expand all') }

	function setAll(expanded) {
		$('#pne-log-entries .pne-log-entry-body').toggle(expanded)
		$('#pne-log-entries .pne-log-toggle').html(expanded ? '&#9662;' : '&#9656;')
		updateToggleLabel()
	}

	function applyTypeFilters() {
		const checked = $('#pne-log-type-filters .pne-log-type-filter:checked').map((_, that) => that.value).get()
		$('#pne-log-entries .pne-log-entry').each((_, that) => $(that).toggle(checked.indexOf(that.dataset.level) !== -1))
	}

	function fetchLog(page, resetTypes = false) {
		const payload = {
			action: 'pne_log_viewer_fetch',
			nonce: pneLogViewer.nonce,
			date: $('#pne-log-date-select').val(),
			per_page: $('#pne-log-per-page-select').val(),
			page
		}

		if (!resetTypes)
			payload.types = $('#pne-log-type-filters .pne-log-type-filter:checked').map((_, that) => that.value).get().join(',')

		$.post(pneLogViewer.ajaxUrl, payload)
			.done(resp => {
				$('#pne-log-entries').html(resp.html)
				$('#pne-log-type-filters').html(resp.filters)
				$('#pne-log-page-select-wrap').html(resp.pageSelect)
				updateToggleLabel()
				applyTypeFilters() })
			.fail(() => alert('Failed to load log entries.'))
	}

	function pollCounts() {
		if ($('#pne-log-date-select').val() !== $('#pne-log-date-select option:first').val())
			return

		$.post(pneLogViewer.ajaxUrl, { action: 'pne_log_viewer_counts', nonce: pneLogViewer.nonce, date: $('#pne-log-date-select').val() })
			.done(counts => $.each(counts, (type, count) => {
				const $count = $('.pne-log-type-count[data-type="'+type+'"]')
				if (!$count.length)
					return

				const delta = count - parseInt($count.text(), 10)
				$('.pne-log-type-delta[data-type="'+type+'"]').text(delta > 0 ? '+'+delta : '')
			}))
	}

	setInterval(pollCounts, 5000)

	$('#pne-log-date-select').on('change', () => fetchLog(1, true))
	$('#pne-log-per-page-select').on('change', () => fetchLog(1))
	$(document).on('change', '#pne-log-page-select', e => fetchLog(e.target.value))

	$('#pne-log-toggle-all').on('click', () => setAll(!anyExpanded()))

	$(document).on('change', '.pne-log-type-filter', applyTypeFilters)

	$(document).on('click', '.pne-log-entry-header', e => {
		const that = e.currentTarget
		const $body = $(that).closest('.pne-log-entry').find('.pne-log-entry-body')
		$body.toggle()
		$(that).find('.pne-log-toggle').html($body.is(':visible') ? '&#9662;' : '&#9656;')
		updateToggleLabel()
	})

	updateToggleLabel()
})
