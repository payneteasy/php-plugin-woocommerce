window.PneCheckout = {
	checks: {
		credit_card_number: { test: v => /^\d{13,19}$/.test(v = v.replace(/\D+/g, '')) && PneCheckout.luhnValid(v) },
		card_printed_name: /^\s*\p{L}[\p{L} .'-]*$/u,
		expire_month: /^(?:0[1-9]|1[0-2])$/,
		expire_year: /^20\d\d$/,
		cvv2: /^\d{3,4}$/,
		ssn: /^\w+/ },

	luhnValid(ccn) {
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
	},

	formatCcn: value => value.replace(/\D+/g, '').slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 '),

	expired(month, year) {
		const now = new Date()
		return year * 12 + Number(month) < now.getFullYear() * 12 + now.getMonth() + 1
	},

	invalid(values) {
		const bad = Object.keys(values).filter(k => !this.checks[k].test(values[k]))
		if ('expire_month' in values && !bad.some(k => k.startsWith('expire_')) && this.expired(values.expire_month, values.expire_year))
			bad.push('expire_month', 'expire_year')
		return bad
	},

	onCcnKeyDown(e) {
		const el = e.target, p = el.selectionStart
		if (e.key == 'Backspace' && p == el.selectionEnd && el.value[p - 1] == ' ')
			el.setSelectionRange(p - 1, p - 1)
	},

	onCcnInput(e) {
		const el = e.target, digits = el.value.slice(0, el.selectionStart).replace(/\D+/g, '').length
		el.value = this.formatCcn(el.value)
		let pos = 0
		for (let seen = 0; seen < digits && pos < el.value.length; pos++)
			if (el.value[pos] != ' ')
				seen++
		el.setSelectionRange(pos, pos)
		return el.value
	},

	validate(box, row, invalid) {
		if (!box?.offsetParent)
			return true

		const fields = [...box.querySelectorAll('[name]')].filter(f => f.name in this.checks)
		const bad = this.invalid(Object.fromEntries(fields.map(f => [f.name, f.value])))

		fields.forEach(f => f.closest(row).classList.toggle(invalid, bad.includes(f.name)))
		fields.find(f => bad.includes(f.name))?.focus()

		return bad.length == 0
	},

	init({ box, row, invalid, submit }) {
		document.addEventListener('keydown', e => e.target.matches(box + ' [name=credit_card_number]') && this.onCcnKeyDown(e))

		document.addEventListener('input', e => {
			if (!e.target.matches(box + ' [name]'))
				return
			if (e.target.name == 'credit_card_number')
				this.onCcnInput(e)
			e.target.closest(row).classList.remove(invalid)
		})

		submit.forEach(([target, event, selector]) => jQuery(target).on(event, selector, () => this.validate(document.querySelector(box), row, invalid)))
	} }

window.pneCheckoutSettings && jQuery(() => PneCheckout.init(pneCheckoutSettings))
