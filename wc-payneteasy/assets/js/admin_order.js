jQuery($ => {
	const order_id = payneteasy_ajax_var.order_id

	if (!payneteasy_ajax_var.is_order_payneteasy)
		return

	const $statusColumn = $('<div id="payneteasy-order-actions"></div>').insertAfter('.order_data_column_container')

	const showNotice = (message, isError = true) => {
		$statusColumn.find('.notice').remove()

		$(`<div class="notice ${isError ? 'notice-error' : 'notice-success'}"><p></p></div>`)
			.find('p').text(message).end().prependTo($statusColumn)
	}

	if ($statusColumn.length) {
		$statusColumn.append(`
			<p class="form-field form-field-wide pne-action">
				${payneteasy_ajax_var.paynet_order_id ? `<span class="order_number pne-transaction-id">ID ${payneteasy_ajax_var.paynet_order_id}</span>` : ''}
				<button class="button custom-action" id="payneteasy-button-check-status">Check status</button>
			</p>
		`)

		$('#payneteasy-button-check-status').on('click', (event) => {
			event.preventDefault()

			$.ajax({ url: payneteasy_ajax_var.api_url, method: 'POST', data: { action: 'check_status', nonce: payneteasy_ajax_var.nonce, order_id: order_id } })
			.done((data) => {
				data.success
					? location.reload()
					: showNotice(data.message) })
			.fail(() => showNotice('An error occurred while processing the request.'))
		})

		if (payneteasy_ajax_var.show_capture) {
			$statusColumn.append(`
				<p class="form-field form-field-wide pne-action">
					<button class="button custom-action" id="payneteasy-button-capture">Capture</button>
				</p>
			`)

			$('body').append(`
				<script type="text/template" id="tmpl-pne-capture-form">
					<div class="wc-backbone-modal">
						<div class="wc-backbone-modal-content">
							<section class="wc-backbone-modal-main" role="main">
								<header class="wc-backbone-modal-header">
									<h1>Capture payment</h1>
									<button class="modal-close modal-close-link dashicons dashicons-no-alt"><span class="screen-reader-text">Close modal panel</span></button>
								</header>
								<article>
									<form id="payneteasy-capture-fields">
										<p class="form-field form-field-wide">
											<label for="payneteasy-capture-amount">Amount to capture (full amount by default; enter less for a partial capture):</label>
											<input type="number" step="0.01" min="0" max="${payneteasy_ajax_var.capture_amount}" id="payneteasy-capture-amount" value="${payneteasy_ajax_var.capture_amount}" required>
										</p>
									</form>
								</article>
								<footer>
									<div class="inner pne-capture-footer">
										<button id="btn-ok" class="button button-primary button-large">Capture</button>
									</div>
								</footer>
							</section>
						</div>
					</div>
					<div class="wc-backbone-modal-backdrop modal-close"></div>
				</${'script'}>
			`)

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
				if (target != 'pne-capture-form')
					return

				$.ajax({ url: payneteasy_ajax_var.api_url, method: 'POST',
					data: { action: 'capture', nonce: payneteasy_ajax_var.nonce, order_id: order_id, amount: $('#payneteasy-capture-amount').val() } })
				.done((data) => {
					data.success
						? location.reload()
						: showNotice(data.message) })
				.fail(() => showNotice('An error occurred while processing the request.')) })
		}

		if (payneteasy_ajax_var.rebill) {
			const daysUntil = (nextStr) => {
				const [y, m, d] = nextStr.slice(0, 10).split('-').map(Number)
				const today = new Date()
				today.setHours(0, 0, 0, 0)
				return Math.round((new Date(y, m - 1, d) - today) / 86400000)
			}

			const days = daysUntil(payneteasy_ajax_var.rebill.next)
			const schedule = payneteasy_ajax_var.rebill
			const mode = schedule.type == 'every_n_days'
				? `every ${schedule.interval} ${payneteasy_ajax_var.interval_unit}`
				: schedule.type == 'last_day'
					? 'last day of every month'
					: `day ${schedule.day} of every month`

			$statusColumn.append(`
				<p class="form-field form-field-wide pne-action pne-action-rebill">
					<span class="pne-rebill-info">next: ${payneteasy_ajax_var.rebill.next} (${days <= 0 ? 'today' : `in ${days} day${days == 1 ? '' : 's'}`})</span>
					<span class="pne-rebill-info pne-rebill-mode">${mode}</span>
					<button class="button custom-action" id="payneteasy-button-cancel-rebill">Cancel rebills</button>
				</p>
			`)

			$('#payneteasy-button-cancel-rebill').on('click', (event) => {
				event.preventDefault()

				if (!confirm(`Cancel recurring rebills for this order? Next one was due ${payneteasy_ajax_var.rebill.next}.`))
					return

				$.ajax({ url: payneteasy_ajax_var.api_url, method: 'POST', data: { action: 'cancel_rebill', nonce: payneteasy_ajax_var.nonce, order_id: order_id } })
				.done((data) => {
					data.success
						? location.reload()
						: showNotice(data.message) })
				.fail(() => showNotice('An error occurred while processing the request.'))
			})
		}
		else if (payneteasy_ajax_var.can_schedule) {
			$statusColumn.append(`
				<p class="form-field form-field-wide pne-action pne-action-rebill">
					<button class="button custom-action" id="payneteasy-button-schedule-rebill">Schedule rebills</button>
				</p>
			`)

			$('body').append(`
				<script type="text/template" id="tmpl-pne-rebill-form">
					<div class="wc-backbone-modal">
						<div class="wc-backbone-modal-content">
							<section class="wc-backbone-modal-main" role="main">
								<header class="wc-backbone-modal-header">
									<h1>Schedule rebills</h1>
									<button class="modal-close modal-close-link dashicons dashicons-no-alt"><span class="screen-reader-text">Close modal panel</span></button>
								</header>
								<article>
									<form id="payneteasy-rebill-fields">
										<p class="form-field form-field-wide">
											<label for="payneteasy-rebill-start">Start date:</label>
											<input type="date" id="payneteasy-rebill-start" required>
										</p>
										<p class="form-field form-field-wide">
											<label><input type="radio" name="pne-rebill-type" value="monthly" required> Selected day every month</label><br>
											<label><input type="radio" name="pne-rebill-type" value="every_n_days">
												Every <input type="number" id="payneteasy-rebill-interval" min="1" value="30"> ${payneteasy_ajax_var.interval_unit}</label><br>
											<label><input type="radio" name="pne-rebill-type" value="last_day"> Last day of every month</label>
										</p>
										<p id="payneteasy-rebill-warning" hidden></p>
									</form>
								</article>
								<footer>
									<div class="inner">
										<button id="btn-ok" class="button button-primary button-large">Confirm schedule</button>
									</div>
								</footer>
							</section>
						</div>
					</div>
					<div class="wc-backbone-modal-backdrop modal-close"></div>
				</${'script'}>
			`)

			$('#payneteasy-button-schedule-rebill').on('click', function(event) {
				event.preventDefault()
				$(this).WCBackboneModal({ template: 'pne-rebill-form' })

				$('#btn-ok').on('click', (event) => {
					if (!$('#payneteasy-rebill-fields')[0].reportValidity()) {
						event.preventDefault()
						event.stopPropagation()
					}
				})
			})

			$(document.body).on('change', '#payneteasy-rebill-start, input[name=pne-rebill-type]', () => {
				const type = $('input[name=pne-rebill-type]:checked').val()
				const start = $('#payneteasy-rebill-start').val()
				const day = start ? parseInt(start.split('-')[2], 10) : 0

				type == 'monthly' && day >= 29
					? $('#payneteasy-rebill-warning').text(`Day ${day} doesn't exist in every month — shorter months will use their last day instead.`).prop('hidden', false)
					: $('#payneteasy-rebill-warning').prop('hidden', true)
			})

			$(document.body).on('wc_backbone_modal_response', (event, target) => {
				if (target != 'pne-rebill-form')
					return

				$.ajax({ url: payneteasy_ajax_var.api_url, method: 'POST',
					data: { action: 'schedule_rebill', nonce: payneteasy_ajax_var.nonce, order_id: order_id,
						start: $('#payneteasy-rebill-start').val(), type: $('input[name=pne-rebill-type]:checked').val(),
						interval: $('#payneteasy-rebill-interval').val() } })
				.done((data) => {
					data.success
						? location.reload()
						: showNotice(data.message) })
				.fail(() => showNotice('An error occurred while processing the request.')) })
		}

		if (payneteasy_ajax_var.card_ref) {
			$statusColumn.append(`
				<p class="form-field form-field-wide pne-action">
					<button class="button custom-action" id="payneteasy-button-card-info">Card info</button>
				</p>
			`)

			$('body').append(`
				<script type="text/template" id="tmpl-pne-card-info">
					<div class="wc-backbone-modal">
						<div class="wc-backbone-modal-content">
							<section class="wc-backbone-modal-main" role="main">
								<header class="wc-backbone-modal-header">
									<h1>Card info</h1>
									<button class="modal-close modal-close-link dashicons dashicons-no-alt"><span class="screen-reader-text">Close modal panel</span></button>
								</header>
								<article><p id="payneteasy-card-info-text"></p></article>
							</section>
						</div>
					</div>
					<div class="wc-backbone-modal-backdrop modal-close"></div>
				</${'script'}>
			`)

			$('#payneteasy-button-card-info').on('click', function(event) {
				event.preventDefault()

				$.ajax({ url: payneteasy_ajax_var.api_url, method: 'POST', data: { action: 'card_info', nonce: payneteasy_ajax_var.nonce, order_id: order_id } })
				.done((data) => {
					if (!data.success)
						return showNotice(data.message)

					$(this).WCBackboneModal({ template: 'pne-card-info' })
					$('#payneteasy-card-info-text').text(data.message) })
				.fail(() => showNotice('An error occurred while processing the request.'))
			})
		}
	}
})
