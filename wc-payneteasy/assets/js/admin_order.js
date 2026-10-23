jQuery($ => {
	const order_id = payneteasy_ajax_var.order_id

	if (!payneteasy_ajax_var.is_order_payneteasy)
		return

	const $statusColumn = $('<div id="payneteasy-order-actions"></div>').insertAfter('.order_data_column_container')

	const showNotice = (message, type = 'error') => {
		$statusColumn.find('.notice').remove()

		const $notice = $(`<div class="notice notice-${type}"><p></p></div>`)
			.find('p').text(message).end().prependTo($statusColumn)

		setTimeout(() => $notice.css('opacity', 0).one('transitionend', () => $notice.remove()), 4000)
	}

	const request = (action, extra = {}) =>
		$.ajax({ url: payneteasy_ajax_var.api_url, method: 'POST', data: { action, nonce: payneteasy_ajax_var.nonce, order_id, ...extra } })
		.done((data) => {
			data.success
				? location.reload()
				: showNotice(data.error_text ?? data.info_text, data.info_text ? 'info' : 'error') })
		.fail(() => showNotice('An error occurred while processing the request.'))

	const addModal = (id, title, body, okLabel, footerClass) => $('body').append(`
		<script type="text/template" id="tmpl-${id}">
			<div class="wc-backbone-modal">
				<div class="wc-backbone-modal-content">
					<section class="wc-backbone-modal-main" role="main">
						<header class="wc-backbone-modal-header">
							<h1>${title}</h1>
							<button class="modal-close modal-close-link dashicons dashicons-no-alt"><span class="screen-reader-text">Close modal panel</span></button>
						</header>
						<article>${body}</article>
						<footer>
							<div class="inner ${footerClass}">
								<button id="btn-ok" class="button button-primary button-large">${okLabel}</button>
							</div>
						</footer>
					</section>
				</div>
			</div>
			<div class="wc-backbone-modal-backdrop modal-close"></div>
		</${'script'}>
	`)

	$statusColumn.append(`
		<p class="form-field form-field-wide pne-action">
			${payneteasy_ajax_var.paynet_order_id ? `<span class="order_number pne-transaction-id">ID ${payneteasy_ajax_var.paynet_order_id}</span>` : ''}
			<button class="button custom-action" id="payneteasy-button-check-status">Check status</button>
		</p>
	`)

	$('#payneteasy-button-check-status').on('click', (event) => {
		event.preventDefault()
		request('check_status')
	})

	if (payneteasy_ajax_var.show_capture) {
		$statusColumn.append(`
			<p class="form-field form-field-wide pne-action">
				<button class="button custom-action" id="payneteasy-button-capture">Capture</button>
			</p>
		`)

		addModal('pne-capture-form', 'Capture payment', `
			<form id="payneteasy-capture-fields">
				<p class="form-field form-field-wide">
					<label for="payneteasy-capture-amount">Amount to capture (full amount by default; enter less for a partial capture):</label>
					<input type="number" step="0.01" min="0" max="${payneteasy_ajax_var.capture_amount}" id="payneteasy-capture-amount" value="${payneteasy_ajax_var.capture_amount}" required>
				</p>
			</form>`, 'Capture', 'pne-capture-footer')

		$('#payneteasy-button-capture').on('click', function(event) {
			event.preventDefault()
			$(this).WCBackboneModal({ template: 'pne-capture-form' })

			$('#btn-ok').on('click', (event) => {
				if (!$('#payneteasy-capture-fields')[0].reportValidity()) {
					event.preventDefault()
					event.stopPropagation()
				}
			})
		})

		$(document.body).on('wc_backbone_modal_response', (event, target) => {
			if (target == 'pne-capture-form')
				request('capture', { amount: $('#payneteasy-capture-amount').val() }) })
	}
})
