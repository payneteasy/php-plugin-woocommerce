(({ registerPaymentMethod }, { createElement: h, useState, useEffect }, { getSetting }) => {
	const settings = getSetting('paymentMethodData', {}).wc_payneteasy || getSetting('wc_payneteasy_data', {})
	const label = settings.title || 'Payneteasy'
	const icon = settings.icon && h('img', { src: settings.icon, alt: label, style: { maxHeight: '24px', marginRight: '4px' } })

	const isDark = !!document.querySelector('.wc-block-checkout.has-dark-controls')
	const colors = isDark
		? { bg: 'rgba(0, 0, 0, 0.1)', border: 'rgba(255, 255, 255, 0.4)', text: '#FFF', label: '#FFF' }
		: { bg: '#FFF', border: '#50575E', text: '#2B2D2F', label: '#757575' }

	function luhnValid(ccn) {
		let sum = 0
		const parity = ccn.length % 2
		for (let i = 0; i < ccn.length; i++) {
			let digit = Number(ccn[i])
			if (i % 2 == parity) {
				digit *= 2
				if (digit > 9)
					digit -= 9
			}
			sum += digit
		}
		return ccn.length > 0 && sum % 10 == 0
	}

	function field(fieldLabel, value, onChange, width, inputProps = {}) {
		const id = 'pne-' + fieldLabel.toLowerCase().replace(/[^a-z0-9]+/g, '-')
		return h('div', { className: 'wc-block-components-text-input' + (value ? ' is-active' : ''), style: { width, marginTop: 0 } },
			h('input', Object.assign({ type: 'text', id, value, onChange: e => onChange(e.target.value) }, inputProps)),
			h('label', { htmlFor: id }, fieldLabel))
	}

	function selectField(fieldLabel, value, onChange, options, width, placeholder) {
		const id = 'pne-' + fieldLabel.toLowerCase().replace(/[^a-z0-9]+/g, '-')
		return h('div', { style: { width } },
			h('label', { htmlFor: id, style: { display: 'block', marginBottom: '4px', fontSize: '14px', color: colors.label } }, fieldLabel),
			h('select', {
					id, value, onChange: e => onChange(e.target.value),
					style: { width: '100%', boxSizing: 'border-box', height: '3em', padding: '0 8px', border: `1px solid ${colors.border}`, borderRadius: '4px',
						fontFamily: 'inherit', fontSize: '16px', backgroundColor: colors.bg, color: colors.text } },
				h('option', { value: '' }, placeholder), ...options.map(o => h('option', { key: o, value: o }, o))))
	}

	function row(...items)
		{ return h('div', { style: { display: 'flex', gap: '12px' } }, ...items) }

	function Content(props) {
		const { eventRegistration, emitResponse } = props
		const { onPaymentSetup } = eventRegistration

		const t = settings.testCard || {}
		const [ccn, setCcn] = useState(t.ccn ? String(t.ccn) : '')
		const [name, setName] = useState(t.name || '')
		const [month, setMonth] = useState(t.month ? String(t.month).padStart(2, '0') : '')
		const [year, setYear] = useState(t.year ? String(t.year) : '')
		const [cvv, setCvv] = useState('')
		const [ssn, setSsn] = useState('')

		useEffect(() => {
			const unsubscribe = onPaymentSetup(async () => {
				if (!settings.IS_FORM) {
					if (!luhnValid(ccn))
						return { type: emitResponse.responseTypes.ERROR, message: 'Card number is invalid' }
					if (!name || !month || !year || !cvv)
						return { type: emitResponse.responseTypes.ERROR, message: 'Please fill in all card fields' }
				}

				if (settings.IS_SSN_REQUIRED && !ssn)
					return { type: emitResponse.responseTypes.ERROR, message: 'Document Number (CPF) is required' }

				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: { paymentMethodData: {
						credit_card_number: ccn,
						card_printed_name: name,
						expire_month: month,
						expire_year: year,
						cvv2: cvv,
						ssn,
						pne_browser_info: JSON.stringify(
							[ 'true', navigator.javaEnabled?.() ? 'true' : 'false', window.screen.colorDepth, window.screen.height, window.screen.width, new Date().getTimezoneOffset() ]) } } }
			})

			return unsubscribe
		}, [ccn, name, month, year, cvv, ssn, onPaymentSetup, emitResponse.responseTypes])

		if (settings.IS_FORM)
			return h('div', null, settings.IS_SSN_REQUIRED ? field('Document Number (CPF)', ssn, setSsn, '200px') : (settings.description || ''))

		const months = Array.from({ length: 12 }, (_, i) => String(i + 1).padStart(2, '0'))
		const thisYear = new Date().getFullYear()
		const years = Array.from({ length: 16 }, (_, i) => String(thisYear + i))

		return h('div', { style: { border: '1px solid #DDD', borderRadius: '4px', padding: '16px', display: 'flex', flexDirection: 'column', gap: '12px' } },
			row(field('Card Number', ccn, setCcn, '14em'), field('CVC', cvv, setCvv, '5em', {
				type: 'password',
				style: { height: '3em', boxSizing: 'border-box', textIndent: '8px', border: `1px solid ${colors.border}`, borderRadius: '4px',
					fontFamily: 'inherit', fontSize: '16px', width: '100%', backgroundColor: colors.bg, color: colors.text } })),
			row(selectField('Expiry month', month, setMonth, months, '6em', 'MM'), selectField('Expiry year', year, setYear, years, '10em', 'YYYY')),
			field('Printed name', name, setName, '20em'),
			settings.IS_SSN_REQUIRED ? field('Document Number (CPF)', ssn, setSsn, '20em') : null)
	}

	registerPaymentMethod({
		name: 'wc_payneteasy',
		label: icon ? h('span', null, icon, label) : label,
		content: h(Content),
		edit: h('div', null, settings.description || ''),
		canMakePayment: () => true,
		ariaLabel: label,
		supports: { features: settings.supports || ['products'] } })
})(window.wc.wcBlocksRegistry, window.wp.element, window.wc.wcSettings)
