/* global ZPVP_BUILDER, wp */
/**
 * Conference Builder (Vendor Portal → Conference Builder).
 *
 * Every change is checked on the server as you type (POST /builder/{id}/check)
 * so the flow strip, checklist and vendor preview follow the plugin's real
 * rules. Nothing is written until Save draft / Publish.
 */
( function () {
	'use strict';

	const C = window.ZPVP_BUILDER;
	const root = document.getElementById( 'zpvp-builder' );
	if ( ! C || ! root ) {
		return;
	}

	/* ---------------- Helpers ---------------- */

	const $ = ( sel, el = root ) => el.querySelector( sel );
	const $$ = ( sel, el = root ) => Array.from( el.querySelectorAll( sel ) );
	const esc = ( v ) => String( v ?? '' ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
	const money = ( n ) => '$' + Number( n || 0 ).toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	const RANK = { ok: 0, info: 0, warn: 1, error: 2 };
	const STATE_WORD = { ok: 'Ready', warn: 'Check', error: 'Needs attention' };

	async function api( path, opts = {} ) {
		const res = await fetch( C.rest + path, {
			method: opts.method || 'GET',
			headers: { 'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: opts.body ? JSON.stringify( opts.body ) : undefined,
			signal: opts.signal,
		} );
		const data = await res.json().catch( () => ( {} ) );
		if ( ! res.ok ) {
			throw new Error( data.message || 'Something went wrong. Please try again.' );
		}
		return data;
	}

	let toastTimer;
	function toast( msg, isError = false ) {
		let t = $( '.zpvp-b__toast' );
		if ( ! t ) {
			t = document.createElement( 'div' );
			t.className = 'zpvp-b__toast';
			t.setAttribute( 'role', 'status' );
			t.setAttribute( 'aria-live', 'polite' );
			root.appendChild( t );
		}
		t.textContent = msg;
		t.classList.toggle( 'is-error', isError );
		t.classList.add( 'is-visible' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( () => t.classList.remove( 'is-visible' ), 3600 );
	}

	function worst( checks, groups ) {
		let w = 0;
		checks.forEach( ( c ) => {
			if ( ! groups || groups.includes( c.group ) ) {
				w = Math.max( w, RANK[ c.level ] );
			}
		} );
		return [ 'ok', 'warn', 'error' ][ w ];
	}

	/* ---------------- State ---------------- */

	const S = {
		boot: null,
		id: 0,
		data: null, // last server state (saved values + evaluation)
		values: null, // working copy
		dirty: false,
		forms: {}, // connector descriptions by form id
		formDraft: {}, // unsaved connector picks by form id
		openForm: 0,
		tab: 'open',
		pendingSwitch: 0,
	};

	const STEPS = [
		{ key: 'event', n: 1, title: 'Event', groups: [ 'event' ], lead: 'Which event, which year, when and where.' },
		{ key: 'forms', n: 2, title: 'Forms', groups: [ 'forms' ], lead: 'The Gravity Forms vendors fill in. They belong to the series, so they apply to every year.' },
		{ key: 'connectors', n: 3, title: 'Field connectors', groups: [ 'connectors' ], lead: 'Which form field holds each piece of portal data. Standard forms connect automatically.' },
		{ key: 'registration', n: 4, title: 'Registration', groups: [ 'registration' ], lead: 'When vendors can register, booth capacity and the booth staff deadline.' },
		{ key: 'resources', n: 5, title: 'Vendor information', groups: [ 'resources' ], lead: 'What registered vendors see on My Conferences.' },
		{ key: 'publish', n: 6, title: 'Review and publish', groups: null, lead: 'Everything the builder checked, in one list.' },
	];

	/* ---------------- Boot ---------------- */

	async function start() {
		try {
			S.boot = await api( '' );
		} catch ( e ) {
			root.innerHTML = '<h1>Conference Builder</h1><div class="notice notice-error"><p>' + esc( e.message ) + '</p></div>';
			return;
		}
		const params = new URL( window.location.href ).searchParams;
		const first = Number( C.initial ) || S.boot.default;
		if ( ! first ) {
			renderEmpty();
		} else {
			await load( first );
		}
		const notes = {
			created: 'Copied for next year as a draft. Check the dates.',
			exists: 'Next year\'s conference already exists, so it was opened.',
			sent: 'Change notice sent to registered vendors.',
			cancelled: 'Change notice cancelled. Nothing was sent.',
			none: 'No change notice went out: no registered vendors, or sending failed. Check the email log.',
		};
		const note = params.get( 'zpvp_copy' ) || params.get( 'zpvp_notice' );
		if ( notes[ note ] ) {
			toast( notes[ note ], note === 'none' );
		}
		if ( params.get( 'new' ) ) {
			openNew();
		}
		[ 'new', 'zpvp_copy', 'zpvp_notice' ].forEach( ( k ) => params.delete( k ) );
		const clean = new URL( window.location.href );
		clean.search = params.toString();
		window.history.replaceState( null, '', clean.toString() );
	}

	async function load( id ) {
		root.classList.add( 'is-loading' );
		try {
			const data = await api( '/' + id );
			S.id = id;
			S.forms = {};
			S.formDraft = {};
			S.openForm = 0;
			setData( data, true );
			renderAll();
			const url = new URL( window.location.href );
			url.searchParams.set( 'conference', id );
			window.history.replaceState( null, '', url.toString() );
		} catch ( e ) {
			toast( e.message, true );
			if ( ! S.data ) {
				renderEmpty( e.message );
			}
		} finally {
			root.classList.remove( 'is-loading' );
		}
	}

	function setData( data, resetValues ) {
		S.data = data;
		if ( resetValues ) {
			S.values = JSON.parse( JSON.stringify( data.values ) );
			S.dirty = false;
		}
	}

	/* ---------------- Live check ---------------- */

	let checkTimer;
	let checkCtl;
	function changed() {
		S.dirty = true;
		renderSaveState();
		clearTimeout( checkTimer );
		checkTimer = setTimeout( check, 350 );
	}

	async function check() {
		if ( checkCtl ) {
			checkCtl.abort();
		}
		checkCtl = new AbortController();
		root.classList.add( 'is-checking' );
		try {
			const ev = await api( '/' + S.id + '/check', { method: 'POST', body: { values: S.values }, signal: checkCtl.signal } );
			Object.assign( S.data, ev );
			renderLive();
		} catch ( e ) {
			if ( e.name !== 'AbortError' ) {
				toast( e.message, true );
			}
		} finally {
			root.classList.remove( 'is-checking' );
		}
	}

	/* ---------------- Rendering: page ---------------- */

	function renderEmpty( msg ) {
		root.innerHTML = '<div class="zpvp-b__head"><h1 class="zpvp-b__title">Conference Builder</h1></div>'
			+ '<div class="zpvp-b__empty"><h2>No conferences yet</h2><p>' + esc( msg || 'Create the first conference to start.' ) + '</p>'
			+ '<button type="button" class="button button-primary" data-act="new">Add conference</button></div>';
	}

	function pickerHtml() {
		const byYear = {};
		S.boot.conferences.forEach( ( c ) => {
			( byYear[ c.year || 'No year' ] = byYear[ c.year || 'No year' ] || [] ).push( c );
		} );
		return Object.keys( byYear ).sort( ( a, b ) => String( b ).localeCompare( String( a ) ) ).map( ( y ) => '<optgroup label="' + esc( y ) + '">'
			+ byYear[ y ].map( ( c ) => '<option value="' + c.id + '"' + ( c.id === S.id ? ' selected' : '' ) + '>' + esc( c.title ) + ' · ' + esc( c.published ? c.status : 'Draft' ) + '</option>' ).join( '' )
			+ '</optgroup>' ).join( '' );
	}

	function renderAll() {
		root.innerHTML = '<div class="zpvp-b__head">'
			+ '<h1 class="zpvp-b__title">Conference Builder</h1>'
			+ '<label class="screen-reader-text" for="zpvp-b-pick">Conference</label>'
			+ '<select id="zpvp-b-pick" class="zpvp-b__pick">' + pickerHtml() + '</select>'
			+ '<button type="button" class="button" data-act="new">Add conference</button>'
			+ '<span class="zpvp-b__spacer"></span>'
			+ '<span class="zpvp-b__savestate" id="zpvp-b-savestate" aria-live="polite"></span>'
			+ '<a class="button" id="zpvp-b-classic" href="' + esc( S.data.editUrl ) + '">Classic editor</a>'
			+ '<button type="button" class="button" data-act="save">Save draft</button>'
			+ '<button type="button" class="button button-primary" data-act="publish" id="zpvp-b-publish"></button>'
			+ '</div>'
			+ '<div class="zpvp-b__switch" id="zpvp-b-switch" hidden></div>'
			+ '<nav class="zpvp-flow" id="zpvp-flow" aria-label="How this conference is connected"></nav>'
			+ '<div class="zpvp-b__grid">'
			+ '<div class="zpvp-b__steps">' + STEPS.map( stepShell ).join( '' ) + '</div>'
			+ '<aside class="zpvp-b__side" aria-label="Live preview">'
			+ '<div class="zpvp-b__card zpvp-prev">'
			+ '<div class="zpvp-prev__head"><h2>What vendors see</h2><span class="zpvp-prev__live" aria-hidden="true">Live</span></div>'
			+ '<div class="zpvp-prev__tabs" role="tablist" aria-label="Preview">'
			+ [ [ 'open', 'Open for registration' ], [ 'pass', 'After registering' ], [ 'info', 'Conference info' ] ].map( ( [ k, l ] ) => '<button type="button" role="tab" data-tab="' + k + '" aria-selected="' + ( S.tab === k ) + '">' + l + '</button>' ).join( '' )
			+ '</div>'
			+ '<p class="zpvp-prev__note" id="zpvp-prev-note"></p>'
			+ '<div class="zpvp-prev__frame"><div class="zpvp" id="zpvp-prev-body"></div></div>'
			+ '</div>'
			+ '<div class="zpvp-b__card"><h2 class="zpvp-b__h">What happens</h2><ul class="zpvp-b__summary" id="zpvp-b-summary"></ul></div>'
			+ '<div class="zpvp-b__card" id="zpvp-b-after"></div>'
			+ '</aside></div>';

		STEPS.forEach( ( s ) => renderStep( s.key ) );
		renderLive();
		renderAfter();
		initPickers();
	}

	function stepShell( s ) {
		return '<section class="zpvp-step" id="zpvp-step-' + s.key + '" data-step="' + s.key + '">'
			+ '<header class="zpvp-step__head"><span class="zpvp-step__n">' + s.n + '</span>'
			+ '<div><h2>' + esc( s.title ) + '</h2><p>' + esc( s.lead ) + '</p></div>'
			+ '<span class="zpvp-dot" data-dot="' + s.key + '"></span></header>'
			+ '<div class="zpvp-step__body" id="zpvp-body-' + s.key + '"></div></section>';
	}

	/** Parts that change with every check: flow, dots, preview, summary, checklist, info lines. */
	function renderLive() {
		const d = S.data;
		const checks = d.checks || [];

		$( '#zpvp-flow' ).innerHTML = d.flow.map( ( n, i ) => '<button type="button" class="zpvp-flow__node is-' + n.state + '" data-goto="' + n.key + '">'
			+ '<span class="zpvp-flow__state" aria-hidden="true"></span>'
			+ '<span class="zpvp-flow__text"><strong>' + esc( n.title ) + '</strong><small>' + esc( n.sub ) + '</small></span>'
			+ '<span class="screen-reader-text">' + esc( STATE_WORD[ n.state ] ) + '</span></button>'
			+ ( i < d.flow.length - 1 ? '<span class="zpvp-flow__arrow" aria-hidden="true"></span>' : '' ) ).join( '' );

		STEPS.forEach( ( s ) => {
			const st = worst( checks, s.groups );
			const dot = $( '[data-dot="' + s.key + '"]' );
			dot.className = 'zpvp-dot is-' + st;
			dot.textContent = STATE_WORD[ st ];
		} );

		const pub = $( '#zpvp-b-publish' );
		pub.textContent = S.data.published ? 'Update' : 'Publish';
		pub.disabled = d.counts.error > 0;
		pub.title = d.counts.error > 0 ? 'Fix the items marked "Needs attention" first' : '';
		$( '[data-act="save"]' ).textContent = S.data.published ? 'Switch to draft' : 'Save draft';
		renderSaveState();

		renderPreview();
		$( '#zpvp-b-summary' ).innerHTML = d.summary.map( ( l ) => '<li>' + esc( l ) + '</li>' ).join( '' );

		renderFormsInfo();
		renderConnectorList();
		renderCapacity();
		renderPublish();
	}

	/** Change notice, email and copy: they act on what is saved, so they follow the last save. */
	function renderAfter() {
		const el = $( '#zpvp-b-after' );
		const a = S.data.after;
		if ( ! el || ! a ) {
			return;
		}
		let notice;
		if ( ! S.data.published ) {
			notice = '<p class="zpvp-muted">Once published, vendors are emailed automatically when the dates, venue, information, floor plan, documents or booth staff deadline change.</p>';
		} else if ( ! a.noticesOn ) {
			notice = '<p class="zpvp-muted">Automatic change notices are off (Settings → Emails).</p>';
		} else if ( a.pending.length ) {
			notice = '<p><strong>Change notice waiting to send' + ( a.sendsAt ? ' at ' + esc( a.sendsAt ) : '' ) + ':</strong></p><ul class="zpvp-b__summary">'
				+ a.pending.map( ( l ) => '<li>' + esc( l ) + '</li>' ).join( '' ) + '</ul>'
				+ '<p><a class="button button-primary" data-leave href="' + esc( a.sendUrl ) + '">Send now</a> <a class="button" data-leave href="' + esc( a.cancelUrl ) + '">Don\'t send</a></p>';
		} else {
			notice = '<p class="zpvp-muted">Registered vendors are emailed ' + a.delay + ' minutes after you save changes to the dates, venue, information, floor plan, documents or booth staff deadline.</p>';
		}
		el.innerHTML = '<h2 class="zpvp-b__h">Vendors and next year</h2>' + notice
			+ ( a.lastNotice ? '<p class="zpvp-f__help">Last change notice: ' + esc( a.lastNotice ) + '.</p>' : '' )
			+ '<p class="zpvp-b__after-actions"><a class="button" data-leave href="' + esc( a.emailUrl ) + '">Email vendors (' + a.registrations + ')</a> '
			+ '<a class="button" data-leave href="' + esc( a.copyUrl ) + '">Copy for ' + esc( a.nextYear ) + '</a></p>';
	}

	function renderSaveState() {
		const el = $( '#zpvp-b-savestate' );
		if ( el ) {
			el.textContent = S.dirty ? 'Unsaved changes' : ( S.data.published ? 'Published · all changes saved' : 'Draft · all changes saved' );
			el.classList.toggle( 'is-dirty', S.dirty );
		}
	}

	function renderPreview() {
		const p = S.data.preview;
		$$( '.zpvp-prev__tabs [role="tab"]' ).forEach( ( b ) => b.setAttribute( 'aria-selected', String( b.dataset.tab === S.tab ) ) );
		$( '#zpvp-prev-body' ).innerHTML = p[ S.tab ];
		const note = $( '#zpvp-prev-note' );
		if ( S.tab === 'open' ) {
			note.textContent = p.openNote;
			note.className = 'zpvp-prev__note ' + ( p.visible ? 'is-ok' : 'is-warn' );
		} else {
			note.textContent = S.tab === 'pass' ? 'Example: a vendor who registered one booth with two booth staff.' : 'Shown to vendors registered for this conference.';
			note.className = 'zpvp-prev__note';
		}
	}

	/* ---------------- Steps ---------------- */

	function field( key, label, input, help ) {
		return '<div class="zpvp-f"><label for="zpvp-f-' + key + '">' + esc( label ) + '</label>' + input + ( help ? '<p class="zpvp-f__help">' + esc( help ) + '</p>' : '' ) + '</div>';
	}
	const text = ( key, type = 'text', extra = '' ) => '<input id="zpvp-f-' + key + '" type="' + type + '" data-field="' + key + '" value="' + esc( S.values[ key ] ) + '" ' + extra + '>';
	const dateTime = ( key ) => ( window.flatpickr
		? '<div class="zpvp-dp"><input id="zpvp-f-' + key + '" type="text" data-dp="datetime" data-key="' + key + '" placeholder="Not set"><button type="button" class="zpvp-dp__clear" data-dp-clear="' + key + '" aria-label="Clear">&times;</button></div>'
		: text( key, 'datetime-local' ) );

	function regPageAuto() {
		const r = S.data.regPage || {};
		if ( r.typed ) {
			return 'Link set in the classic editor: ' + r.typed;
		}
		return r.detected ? 'Automatic: ' + r.detected.title : 'Automatic (no page shows the form yet)';
	}

	/* ---------------- Date pickers (Flatpickr) ---------------- */

	const pickers = {};
	const DT_FORMAT = 'Y-m-d\\TH:i';

	function initPickers() {
		if ( ! window.flatpickr ) {
			return;
		}
		Object.keys( pickers ).forEach( ( k ) => {
			if ( ! document.body.contains( pickers[ k ].input ) ) {
				pickers[ k ].destroy();
				delete pickers[ k ];
			}
		} );
		const range = $( '[data-dp="range"]' );
		if ( range && ! pickers.dates ) {
			pickers.dates = window.flatpickr( range, {
				mode: 'range',
				dateFormat: 'Y-m-d',
				altInput: true,
				altFormat: 'M j, Y',
				showMonths: window.innerWidth > 900 ? 2 : 1,
				defaultDate: [ S.values.start_date, S.values.end_date || S.values.start_date ].filter( Boolean ),
				onChange( dates, str, fp ) {
					if ( dates.length === 2 ) {
						S.values.start_date = fp.formatDate( dates[ 0 ], 'Y-m-d' );
						S.values.end_date = fp.formatDate( dates[ 1 ], 'Y-m-d' );
						syncLimits();
						changed();
					}
				},
				onClose( dates, str, fp ) {
					if ( dates.length === 1 ) {
						// One click and close: a one-day event.
						fp.setDate( [ dates[ 0 ], dates[ 0 ] ], true );
					}
				},
			} );
			pickers.dates.altInput.id = 'zpvp-f-dates';
			range.removeAttribute( 'id' );
		}
		$$( '[data-dp="datetime"]' ).forEach( ( el ) => {
			const key = el.dataset.key;
			if ( pickers[ key ] ) {
				return;
			}
			pickers[ key ] = window.flatpickr( el, {
				enableTime: true,
				dateFormat: DT_FORMAT,
				altInput: true,
				altFormat: 'M j, Y h:i K',
				minuteIncrement: 15,
				defaultHour: key === 'registration_opens' ? 9 : 17,
				defaultDate: S.values[ key ] || null,
				onChange( dates, str ) {
					S.values[ key ] = dates.length ? str : '';
					syncLimits();
					changed();
				},
			} );
			pickers[ key ].altInput.id = 'zpvp-f-' + key;
			el.removeAttribute( 'id' );
		} );
		syncLimits();
	}

	/** Keep the pickers sensible: closing after opening, booth staff deadline before the conference ends. */
	function syncLimits() {
		const v = S.values;
		if ( pickers.registration_closes ) {
			pickers.registration_closes.set( 'minDate', v.registration_opens ? v.registration_opens.slice( 0, 10 ) : null );
		}
		if ( pickers.registration_opens ) {
			pickers.registration_opens.set( 'maxDate', v.end_date || v.start_date || null );
		}
		if ( pickers.attendee_edit_deadline ) {
			pickers.attendee_edit_deadline.set( 'maxDate', v.end_date || v.start_date || null );
		}
	}

	function clearPicker( key ) {
		if ( key === 'dates' ) {
			S.values.start_date = '';
			S.values.end_date = '';
		} else {
			S.values[ key ] = '';
		}
		if ( pickers[ key ] ) {
			pickers[ key ].clear( false );
		}
		syncLimits();
		changed();
	}

	function renderStep( key ) {
		const body = $( '#zpvp-body-' + key );
		const v = S.values;
		if ( key === 'event' ) {
			body.innerHTML = '<div class="zpvp-fgrid">'
				+ field( 'series', 'Conference series', '<select id="zpvp-f-series" data-field="series" data-num="1"><option value="0">Choose…</option>'
					+ S.boot.series.map( ( s ) => '<option value="' + s.id + '"' + ( s.id === v.series ? ' selected' : '' ) + '>' + esc( s.title ) + '</option>' ).join( '' ) + '</select>', 'The title is filled in from the series and year.' )
				+ field( 'year', 'Year', text( 'year', 'number', 'min="2015" max="2100" data-num="1"' ) )
				+ ( window.flatpickr
					? field( 'dates', 'Conference dates', '<div class="zpvp-dp"><input id="zpvp-f-dates" type="text" data-dp="range" placeholder="Choose the first and last day"><button type="button" class="zpvp-dp__clear" data-dp-clear="dates" aria-label="Clear the dates">&times;</button></div>', 'Click the first day, then the last day. Click one day twice for a one-day event.' )
					: field( 'start_date', 'Start date', text( 'start_date', 'date' ) ) + field( 'end_date', 'End date', text( 'end_date', 'date' ) ) )
				+ field( 'venue', 'Venue', text( 'venue' ) )
				+ field( 'city', 'City', text( 'city' ) )
				+ '</div>';
		} else if ( key === 'forms' ) {
			body.innerHTML = formPicker( 'forms', 'Registration form', 'The form exhibitors fill in for this event.' )
				+ formPicker( 'sponsorship_forms', 'Sponsorship form', 'Optional. Sponsorships are recorded with their level instead of booths.' )
				+ '<div class="zpvp-f"><label for="zpvp-f-annual">Booth dropdown on the annual form</label>'
				+ '<select id="zpvp-f-annual" data-field="annual_product_field"><option value="">Not on the annual form</option>'
				+ S.boot.annual.dropdowns.map( ( o ) => '<option value="' + esc( o.value ) + '"' + ( o.value === v.annual_product_field ? ' selected' : '' ) + '>' + esc( o.label ) + '</option>' ).join( '' )
				+ '</select><p class="zpvp-f__help">The annual form is taking bookings for ' + esc( S.boot.annual.season ) + '.</p></div>'
				+ '<div id="zpvp-forms-info" class="zpvp-forminfo"></div>';
		} else if ( key === 'connectors' ) {
			body.innerHTML = '<div id="zpvp-conn-list" class="zpvp-conn"></div>';
		} else if ( key === 'registration' ) {
			body.innerHTML = '<div class="zpvp-fgrid">'
				+ field( 'registration_opens', 'Registration opens', dateTime( 'registration_opens' ) )
				+ field( 'registration_closes', 'Registration closes', dateTime( 'registration_closes' ), 'Leave empty to keep it open until sold out.' )
				+ field( 'attendee_edit_deadline', 'Booth staff deadline', dateTime( 'attendee_edit_deadline' ), 'Vendors can change booth staff until then. Badges are printed after it.' )
				+ field( 'booth_capacity', 'Booth capacity', text( 'booth_capacity', 'number', 'min="0" placeholder="No limit"' ) )
				+ '</div><div id="zpvp-capacity" class="zpvp-cap"></div>'
				+ field( 'status_override', 'Status', '<select id="zpvp-f-status_override" data-field="status_override">'
					+ S.boot.overrides.map( ( o ) => '<option value="' + o.value + '"' + ( o.value === v.status_override ? ' selected' : '' ) + '>' + esc( o.label ) + '</option>' ).join( '' ) + '</select>', 'Leave on Automatic unless you need to override it.' )
				+ field( 'registration_url', 'Registration page', '<select id="zpvp-f-registration_url" data-field="registration_url" data-num="1"><option value="0">' + esc( regPageAuto() ) + '</option>'
					+ ( S.boot.pages || [] ).map( ( p ) => '<option value="' + p.id + '"' + ( p.id === v.registration_url ? ' selected' : '' ) + '>' + esc( p.title ) + '</option>' ).join( '' ) + '</select>', 'Where the Register button sends vendors. Leave on automatic unless the form is on more than one page.' );
		} else if ( key === 'resources' ) {
			const fp = S.data.floorPlan;
			body.innerHTML = '<div class="zpvp-f"><span class="zpvp-f__label">Floor plan</span><div class="zpvp-file" id="zpvp-floor">'
				+ ( fp && fp.id ? '<a href="' + esc( fp.url ) + '" target="_blank" rel="noopener">' + esc( fp.name ) + '</a> <button type="button" class="button-link zpvp-danger-link" data-act="floor-remove">Remove</button>' : '<span class="zpvp-muted">No file yet</span>' )
				+ ' <button type="button" class="button" data-act="floor-pick">' + ( fp && fp.id ? 'Replace' : 'Choose file' ) + '</button></div></div>'
				+ [ [ 'hotel_info', 'Hotel information' ], [ 'decorator_info', 'Decorator information' ], [ 'electricity_info', 'Electricity information' ] ].map( ( [ k, l ] ) => field( k, l, '<textarea id="zpvp-f-' + k + '" data-field="' + k + '" rows="4">' + esc( v[ k ] ) + '</textarea>' ) ).join( '' )
				+ '<p class="zpvp-f__help">Links and simple formatting are kept.</p>'
				+ '<div class="zpvp-f"><span class="zpvp-f__label">Documents</span>'
				+ ( v.resource_files.length
					? '<ul class="zpvp-docs">' + v.resource_files.map( ( r, i ) => {
						const f = S.data.files[ i ] || {};
						return '<li class="zpvp-docs__row"><label class="screen-reader-text" for="zpvp-doc-' + i + '">Label for ' + esc( f.name || 'document' ) + '</label>'
							+ '<input id="zpvp-doc-' + i + '" type="text" data-doc-label="' + i + '" value="' + esc( r.label ) + '" placeholder="' + esc( f.name || 'Label' ) + '">'
							+ ( f.url ? '<a href="' + esc( f.url ) + '" target="_blank" rel="noopener" class="zpvp-docs__file">' + esc( f.name ) + '</a>' : '<span class="zpvp-muted">File missing</span>' )
							+ '<button type="button" class="button-link" data-doc-move="' + i + ':-1"' + ( i ? '' : ' disabled' ) + ' aria-label="Move up">&uarr;</button>'
							+ '<button type="button" class="button-link" data-doc-move="' + i + ':1"' + ( i < v.resource_files.length - 1 ? '' : ' disabled' ) + ' aria-label="Move down">&darr;</button>'
							+ '<button type="button" class="button-link zpvp-danger-link" data-doc-remove="' + i + '">Remove</button></li>';
					} ).join( '' ) + '</ul>'
					: '<p class="zpvp-muted">No documents yet.</p>' )
				+ '<button type="button" class="button" data-act="doc-add">Add documents</button>'
				+ '<p class="zpvp-f__help">Exhibitor kits, schedules, forms. Registered vendors see them under Documents with the label you give them.</p></div>';
		} else if ( key === 'publish' ) {
			body.innerHTML = '<div id="zpvp-publish"></div>';
		}
	}

	function formPicker( key, label, help ) {
		const chosen = S.values[ key ] || [];
		const byId = Object.fromEntries( S.boot.forms.map( ( f ) => [ f.id, f ] ) );
		const chips = chosen.map( ( id ) => {
			const f = byId[ id ] || { id, title: 'Form ' + id, active: false };
			return '<li class="zpvp-chipf"><span>' + esc( f.title ) + ' <small>(form ' + id + ( f.active ? '' : ', off' ) + ')</small></span>'
				+ '<button type="button" class="zpvp-x" data-remove-form="' + key + ':' + id + '" aria-label="Remove ' + esc( f.title ) + '">&times;</button></li>';
		} ).join( '' );
		const options = S.boot.forms.filter( ( f ) => ! chosen.includes( f.id ) ).map( ( f ) => '<option value="' + f.id + '">' + esc( f.title ) + ' (form ' + f.id + ( f.active ? '' : ', off' ) + ')</option>' ).join( '' );
		return '<div class="zpvp-f"><label for="zpvp-add-' + key + '">' + esc( label ) + '</label>'
			+ ( chips ? '<ul class="zpvp-chipsf">' + chips + '</ul>' : '' )
			+ '<select id="zpvp-add-' + key + '" data-add-form="' + key + '"><option value="">' + ( chosen.length ? 'Add another form…' : 'Choose a form…' ) + '</option>' + options + '</select>'
			+ '<p class="zpvp-f__help">' + esc( help ) + '</p></div>';
	}

	function renderFormsInfo() {
		const el = $( '#zpvp-forms-info' );
		if ( ! el ) {
			return;
		}
		const list = S.data.formsInfo || [];
		el.innerHTML = list.length ? list.map( ( f ) => '<div class="zpvp-forminfo__row">'
			+ '<div class="zpvp-forminfo__name"><strong>' + esc( f.title ) + '</strong> <span class="zpvp-tag ' + ( f.active ? 'is-on' : 'is-off' ) + '">' + ( f.exists ? ( f.active ? 'On' : 'Switched off' ) : 'Missing' ) + '</span>'
			+ ( f.role === 'sponsorship' ? ' <span class="zpvp-tag">Sponsorship</span>' : '' ) + '</div>'
			+ '<p>' + esc( f.route ) + '</p>'
			+ '<p class="zpvp-muted">' + ( f.page ? 'On the page <a href="' + esc( f.page.url ) + '" target="_blank" rel="noopener">' + esc( f.page.title ) + '</a>' : 'Not on a published page' )
			+ ( f.exists ? ' · <a href="' + esc( f.edit ) + '">Edit in Gravity Forms</a>' : '' ) + '</p>'
			+ f.issues.filter( ( i ) => i[ 0 ] !== 'info' ).map( ( i ) => '<p class="zpvp-line is-' + i[ 0 ] + '">' + esc( i[ 1 ] ) + '</p>' ).join( '' )
			+ '</div>' ).join( '' ) : '';
	}

	function renderCapacity() {
		const el = $( '#zpvp-capacity' );
		if ( ! el ) {
			return;
		}
		const b = S.data.booths;
		if ( ! b.capacity ) {
			el.innerHTML = '<p class="zpvp-muted">' + b.taken + ' booth(s) taken so far. No limit set.</p>';
			return;
		}
		const pct = Math.min( 100, Math.round( ( b.taken / b.capacity ) * 100 ) );
		el.innerHTML = '<div class="zpvp-cap__meter" role="img" aria-label="' + b.taken + ' of ' + b.capacity + ' booths taken"><span style="width:' + pct + '%"' + ( pct >= 100 ? ' class="is-full"' : '' ) + '></span></div>'
			+ '<p class="zpvp-muted">' + b.taken + ' of ' + b.capacity + ' booths taken · ' + Math.max( 0, b.capacity - b.taken ) + ' left</p>';
	}

	function renderPublish() {
		const el = $( '#zpvp-publish' );
		if ( ! el ) {
			return;
		}
		const d = S.data;
		const order = { error: 0, warn: 1, info: 2, ok: 3 };
		const list = d.checks.slice().sort( ( a, b ) => order[ a.level ] - order[ b.level ] );
		const titleFor = Object.fromEntries( STEPS.map( ( s ) => [ s.key, s.title ] ) );
		el.innerHTML = '<div class="zpvp-ready">'
			+ '<span class="zpvp-ready__n is-error">' + d.counts.error + '<small>need attention</small></span>'
			+ '<span class="zpvp-ready__n is-warn">' + d.counts.warn + '<small>to check</small></span>'
			+ '<span class="zpvp-ready__n is-ok">' + ( d.counts.ok + d.counts.info ) + '<small>fine</small></span></div>'
			+ '<ul class="zpvp-checks">' + list.map( ( c ) => '<li class="zpvp-line is-' + c.level + '"><button type="button" class="button-link" data-goto="' + c.step + '">' + esc( titleFor[ c.step ] || '' ) + '</button> ' + esc( c.text ) + '</li>' ).join( '' ) + '</ul>'
			+ ( d.counts.error ? '<p class="zpvp-line is-error"><strong>Publishing is blocked</strong> until the items that need attention are fixed. You can still save a draft.</p>' : '' );
	}

	/* ---------------- Connectors ---------------- */

	function renderConnectorList() {
		const el = $( '#zpvp-conn-list' );
		if ( ! el ) {
			return;
		}
		const list = S.data.connectors || [];
		if ( ! list.length ) {
			el.innerHTML = '<p class="zpvp-muted">Choose a form in step 2 and its fields show up here.</p>';
			return;
		}
		el.innerHTML = list.map( ( c ) => {
			const open = S.openForm === c.id;
			return '<div class="zpvp-conn__item' + ( open ? ' is-open' : '' ) + '" data-conn="' + c.id + '">'
				+ '<button type="button" class="zpvp-conn__head" data-toggle-form="' + c.id + '" aria-expanded="' + open + '">'
				+ '<span class="zpvp-dot is-' + c.state + '">' + STATE_WORD[ c.state ] + '</span>'
				+ '<strong>' + esc( c.title ) + '</strong>'
				+ '<span class="zpvp-muted">' + ( { standard: 'Exhibitor form', annual: 'Annual form', sponsorship: 'Sponsorship form' }[ c.kind ] || '' ) + ' · ' + c.connected + ' of ' + c.total + ' connected</span>'
				+ '<span class="zpvp-conn__chev" aria-hidden="true"></span></button>'
				+ '<div class="zpvp-conn__body" id="zpvp-conn-body-' + c.id + '"' + ( open ? '' : ' hidden' ) + '>' + ( open ? connectorTable( c.id ) : '' ) + '</div></div>';
		} ).join( '' );
	}

	function connectorTable( formId ) {
		const d = S.forms[ formId ];
		if ( ! d ) {
			return '<p class="zpvp-muted">Loading fields…</p>';
		}
		if ( ! d.form.exists ) {
			return '<p class="zpvp-line is-error">This form no longer exists in Gravity Forms.</p>';
		}
		const draft = S.formDraft[ formId ] || {};
		const rows = d.rows.map( ( r ) => {
			const val = draft[ r.key ] !== undefined ? draft[ r.key ] : ( r.state === 'suggest' ? r.suggestion : r.value );
			const opts = '<option value="">' + ( r.required ? '— Choose a field —' : '— Not on this form —' ) + '</option>'
				+ d.options.map( ( o ) => '<option value="' + esc( o.value ) + '"' + ( o.value === val ? ' selected' : '' ) + '>' + esc( o.label ) + '</option>' ).join( '' );
			const changedRow = draft[ r.key ] !== undefined;
			const state = changedRow ? 'changed' : r.state;
			const status = changedRow ? 'Changed. Save connectors to use it' : r.status;
			return '<tr class="is-' + state + '">'
				+ '<th scope="row"><label for="zpvp-c-' + formId + '-' + r.key + '">' + esc( r.label ) + ( r.required ? ' <span class="zpvp-req" aria-label="required">*</span>' : '' ) + '</label><small>' + esc( r.hint ) + '</small></th>'
				+ '<td class="zpvp-wire" aria-hidden="true"><span></span></td>'
				+ '<td><select id="zpvp-c-' + formId + '-' + r.key + '" data-conn-field="' + formId + ':' + r.key + '">' + opts + '</select>'
				+ '<small class="zpvp-conn__status">' + esc( status ) + ( r.default && r.default !== val ? ' · Template default: field ' + esc( r.default ) : '' ) + '</small></td></tr>';
		} ).join( '' );
		return '<table class="zpvp-conn__table"><thead><tr><th>Portal field</th><th aria-hidden="true"></th><th>Form field</th></tr></thead><tbody>' + rows + '</tbody></table>'
			+ '<div class="zpvp-conn__actions">'
			+ '<button type="button" class="button button-primary" data-save-form="' + formId + '">Save connectors</button>'
			+ '<button type="button" class="button" data-test-form="' + formId + '">Test with last entry</button>'
			+ '<a class="button-link" href="' + esc( d.form.edit ) + '">Edit form in Gravity Forms</a>'
			+ '</div><div class="zpvp-test" id="zpvp-test-' + formId + '" aria-live="polite"></div>';
	}

	async function openConnector( formId ) {
		S.openForm = S.openForm === formId ? 0 : formId;
		renderConnectorList();
		if ( S.openForm && ! S.forms[ formId ] ) {
			try {
				S.forms[ formId ] = await api( '/form/' + formId );
			} catch ( e ) {
				toast( e.message, true );
			}
			renderConnectorList();
		}
	}

	async function saveConnectors( formId ) {
		const d = S.forms[ formId ];
		const map = {};
		d.rows.forEach( ( r ) => {
			const sel = $( '[data-conn-field="' + formId + ':' + r.key + '"]' );
			map[ r.key ] = sel ? sel.value : r.value;
		} );
		try {
			S.forms[ formId ] = await api( '/form/' + formId, { method: 'POST', body: { map } } );
			delete S.formDraft[ formId ];
			toast( 'Connectors saved for ' + d.form.title + '. New submissions use them right away.' );
			await check();
		} catch ( e ) {
			toast( e.message, true );
		}
	}

	async function testConnectors( formId ) {
		const box = $( '#zpvp-test-' + formId );
		if ( S.formDraft[ formId ] ) {
			box.innerHTML = '<p class="zpvp-line is-warn">Save the connectors first. The test reads the saved ones.</p>';
			return;
		}
		box.innerHTML = '<p class="zpvp-muted">Reading the last entry…</p>';
		try {
			const t = await api( '/form/' + formId + '/test' );
			if ( ! t.found ) {
				box.innerHTML = '<p class="zpvp-line is-info">' + esc( t.message ) + '</p>';
				return;
			}
			const rows = [
				[ 'Company', t.company ], [ 'Contact', t.contact ], [ 'Email', t.email ],
				[ 'Payment', [ t.payment_method, t.payment_status ].filter( ( x ) => x && x !== '—' ).join( ' · ' ) ],
				[ 'PO number', t.po_number ], [ 'Total', money( t.total ) ],
			];
			if ( t.booths !== undefined ) {
				rows.push( [ 'Booths', t.booths ], [ 'Included badges', t.included_badges ], [ 'Paid extra badges', t.paid_badges ] );
				rows.push( [ 'Booth staff', t.people.length ? t.people.join( '; ' ) : 'None listed' ] );
			}
			if ( t.bookings && t.bookings.length ) {
				rows.push( [ 'Conferences booked', t.bookings.map( ( b ) => b.conference + ': ' + b.choice + ' (' + money( b.amount ) + ')' ).join( '; ' ) ] );
			}
			// Only required values are a problem when empty (a card payment has no PO number, for example).
			const must = [ 'Company', 'Email', 'Total' ];
			const empty = rows.filter( ( r ) => must.includes( r[ 0 ] ) && ( r[ 1 ] === '' || r[ 1 ] === undefined || r[ 1 ] === money( 0 ) ) ).map( ( r ) => r[ 0 ] );
			box.innerHTML = '<p class="zpvp-test__head">Entry #' + t.entry_id + ' from ' + esc( t.date ) + ' would be recorded as:</p>'
				+ '<dl class="zpvp-test__dl">' + rows.map( ( r ) => '<div><dt>' + esc( r[ 0 ] ) + '</dt><dd>' + ( r[ 1 ] === '' || r[ 1 ] === undefined ? '<span class="zpvp-muted">empty</span>' : esc( r[ 1 ] ) ) + '</dd></div>' ).join( '' ) + '</dl>'
				+ ( empty.length ? '<p class="zpvp-line is-warn">Empty: ' + esc( empty.join( ', ' ) ) + '. If the vendor filled these in, check their connectors.</p>' : '<p class="zpvp-line is-ok">Company, email and total came through. Empty optional fields are fine if the vendor left them blank.</p>' );
		} catch ( e ) {
			box.innerHTML = '<p class="zpvp-line is-error">' + esc( e.message ) + '</p>';
		}
	}

	/* ---------------- Saving ---------------- */

	async function save( status ) {
		const btns = $$( '[data-act="save"], [data-act="publish"]' );
		btns.forEach( ( b ) => ( b.disabled = true ) );
		try {
			const data = await api( '/' + S.id, { method: 'POST', body: { values: S.values, status } } );
			setData( data, true );
			const ser = S.boot.series.find( ( x ) => x.id === data.values.series );
			if ( ser ) {
				ser.forms = data.values.forms.slice();
				ser.sponsorship_forms = data.values.sponsorship_forms.slice();
				ser.annual_product_field = data.values.annual_product_field;
			}
			const item = S.boot.conferences.find( ( c ) => c.id === S.id );
			if ( item ) {
				item.title = data.title;
				item.published = data.published;
				item.status = data.statusText;
				item.year = data.values.year;
			}
			renderAll();
			toast( status === 'publish' ? 'Published. Vendors can see it on My Conferences.' : ( status === 'draft' ? 'Saved as a draft. Vendors no longer see it.' : 'Saved.' ) );
		} catch ( e ) {
			toast( e.message, true );
			btns.forEach( ( b ) => ( b.disabled = false ) );
			renderLive();
		}
	}

	/* ---------------- New conference ---------------- */

	function openNew() {
		let m = $( '#zpvp-b-new' );
		if ( m ) {
			m.remove();
		}
		m = document.createElement( 'div' );
		m.id = 'zpvp-b-new';
		m.className = 'zpvp-b__modal';
		m.innerHTML = '<div class="zpvp-b__scrim" data-close></div><div class="zpvp-b__dialog" role="dialog" aria-modal="true" aria-labelledby="zpvp-b-new-t">'
			+ '<h2 id="zpvp-b-new-t">Add conference</h2>'
			+ '<form id="zpvp-b-new-f">'
			+ '<div class="zpvp-f"><label for="zpvp-n-series">Event</label><select id="zpvp-n-series"><option value="0">New event…</option>'
			+ S.boot.series.map( ( s ) => '<option value="' + s.id + '">' + esc( s.title ) + '</option>' ).join( '' ) + '</select></div>'
			+ '<div class="zpvp-f" id="zpvp-n-name-w"><label for="zpvp-n-name">New event name (without the year)</label><input id="zpvp-n-name" type="text"></div>'
			+ '<div class="zpvp-f"><label for="zpvp-n-year">Year</label><input id="zpvp-n-year" type="number" min="2015" max="2100" value="' + esc( S.boot.nextYear ) + '"></div>'
			+ '<p class="zpvp-f__help">If the event has an earlier year, its details are copied with the dates moved forward. It starts as a draft.</p>'
			+ '<p class="zpvp-b__dialog-actions"><button type="button" class="button" data-close>Cancel</button> <button type="submit" class="button button-primary">Create draft</button></p>'
			+ '</form></div>';
		root.appendChild( m );
		const sel = $( '#zpvp-n-series', m );
		const nameW = $( '#zpvp-n-name-w', m );
		if ( S.boot.series.length ) {
			sel.value = String( S.values ? S.values.series : S.boot.series[ 0 ].id );
		}
		const sync = () => ( nameW.hidden = sel.value !== '0' );
		sel.addEventListener( 'change', sync );
		sync();
		sel.focus();
		m.addEventListener( 'click', ( e ) => {
			if ( e.target.closest( '[data-close]' ) ) {
				m.remove();
			}
		} );
		m.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Escape' ) {
				m.remove();
			}
		} );
		$( '#zpvp-b-new-f', m ).addEventListener( 'submit', async ( e ) => {
			e.preventDefault();
			try {
				const r = await api( '/new', { method: 'POST', body: { series: Number( sel.value ), name: $( '#zpvp-n-name', m ).value, year: Number( $( '#zpvp-n-year', m ).value ) } } );
				m.remove();
				S.boot = await api( '' );
				await load( r.id );
				toast( r.created ? ( r.copied ? 'Draft created from last year. Check the dates.' : 'Draft created.' ) : 'That conference already exists, so it was opened.' );
			} catch ( err ) {
				toast( err.message, true );
			}
		} );
	}

	/* ---------------- Media ---------------- */

	let frame;
	function pickFloorPlan() {
		if ( ! window.wp || ! wp.media ) {
			toast( 'The media library is not available here.', true );
			return;
		}
		if ( ! frame ) {
			frame = wp.media( { title: 'Choose the floor plan', button: { text: 'Use this file' }, multiple: false } );
			frame.on( 'select', () => {
				const a = frame.state().get( 'selection' ).first().toJSON();
				S.values.floor_plan = a.id;
				S.data.floorPlan = { id: a.id, url: a.url, name: a.filename };
				renderStep( 'resources' );
				changed();
			} );
		}
		frame.open();
	}

	let docFrame;
	function pickDocuments() {
		if ( ! window.wp || ! wp.media ) {
			toast( 'The media library is not available here.', true );
			return;
		}
		if ( ! docFrame ) {
			docFrame = wp.media( { title: 'Add documents for vendors', button: { text: 'Add' }, multiple: true } );
			docFrame.on( 'select', () => {
				docFrame.state().get( 'selection' ).toJSON().forEach( ( a ) => {
					S.values.resource_files.push( { file: a.id, label: a.title || '' } );
					S.data.files.push( { id: a.id, url: a.url, name: a.filename } );
				} );
				renderStep( 'resources' );
				changed();
			} );
		}
		docFrame.open();
	}

	/* ---------------- Events ---------------- */

	function readField( el ) {
		const key = el.dataset.field;
		let val = el.value;
		if ( el.dataset.num ) {
			val = Number( val ) || 0;
		}
		S.values[ key ] = val;
		if ( key === 'series' ) {
			// The forms belong to the series: switch to the new series' forms.
			const s = S.boot.series.find( ( x ) => x.id === val );
			S.values.forms = s ? s.forms.slice() : [];
			S.values.sponsorship_forms = s ? s.sponsorship_forms.slice() : [];
			S.values.annual_product_field = s ? s.annual_product_field : '';
			renderStep( 'forms' );
		}
		changed();
	}

	root.addEventListener( 'input', ( e ) => {
		if ( e.target.matches( '[data-field]' ) && e.target.tagName !== 'SELECT' ) {
			readField( e.target );
		} else if ( e.target.matches( '[data-doc-label]' ) ) {
			S.values.resource_files[ Number( e.target.dataset.docLabel ) ].label = e.target.value;
			changed();
		}
	} );

	root.addEventListener( 'change', ( e ) => {
		const t = e.target;
		if ( t.id === 'zpvp-b-pick' ) {
			const id = Number( t.value );
			if ( S.dirty ) {
				t.value = String( S.id );
				S.pendingSwitch = id;
				const bar = $( '#zpvp-b-switch' );
				bar.hidden = false;
				bar.innerHTML = '<span>You have unsaved changes on this conference.</span> <button type="button" class="button" data-act="switch-stay">Keep editing</button> <button type="button" class="button zpvp-danger" data-act="switch-go">Discard and switch</button>';
				return;
			}
			load( id );
		} else if ( t.matches( 'select[data-field]' ) ) {
			readField( t );
		} else if ( t.matches( '[data-add-form]' ) && t.value ) {
			const key = t.dataset.addForm;
			S.values[ key ] = ( S.values[ key ] || [] ).concat( Number( t.value ) );
			renderStep( 'forms' );
			changed();
		} else if ( t.matches( '[data-conn-field]' ) ) {
			const [ formId, key ] = t.dataset.connField.split( ':' );
			S.formDraft[ formId ] = S.formDraft[ formId ] || {};
			S.formDraft[ formId ][ key ] = t.value;
			const body = $( '#zpvp-conn-body-' + formId );
			body.innerHTML = connectorTable( Number( formId ) );
			const again = $( '[data-conn-field="' + formId + ':' + key + '"]' );
			if ( again ) {
				again.focus();
			}
		}
	} );

	root.addEventListener( 'click', ( e ) => {
		const t = e.target.closest( 'button, a' );
		if ( ! t || ! root.contains( t ) ) {
			return;
		}
		if ( t.dataset.goto ) {
			const step = t.dataset.goto === 'outputs' ? 'connectors' : t.dataset.goto;
			const el = $( '#zpvp-step-' + step );
			if ( el ) {
				el.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				el.classList.add( 'is-flash' );
				setTimeout( () => el.classList.remove( 'is-flash' ), 1200 );
			}
			return;
		}
		if ( t.dataset.tab ) {
			S.tab = t.dataset.tab;
			renderPreview();
			return;
		}
		if ( t.dataset.removeForm ) {
			const [ key, id ] = t.dataset.removeForm.split( ':' );
			S.values[ key ] = ( S.values[ key ] || [] ).filter( ( x ) => x !== Number( id ) );
			renderStep( 'forms' );
			changed();
			return;
		}
		if ( t.dataset.dpClear ) {
			clearPicker( t.dataset.dpClear );
			return;
		}
		if ( t.dataset.docRemove ) {
			const i = Number( t.dataset.docRemove );
			S.values.resource_files.splice( i, 1 );
			S.data.files.splice( i, 1 );
			renderStep( 'resources' );
			changed();
			return;
		}
		if ( t.dataset.docMove ) {
			const [ i, d ] = t.dataset.docMove.split( ':' ).map( Number );
			[ S.values.resource_files, S.data.files ].forEach( ( list ) => {
				const [ row ] = list.splice( i, 1 );
				list.splice( i + d, 0, row );
			} );
			renderStep( 'resources' );
			changed();
			return;
		}
		if ( t.dataset.toggleForm ) {
			openConnector( Number( t.dataset.toggleForm ) );
			return;
		}
		if ( t.dataset.saveForm ) {
			saveConnectors( Number( t.dataset.saveForm ) );
			return;
		}
		if ( t.dataset.testForm ) {
			testConnectors( Number( t.dataset.testForm ) );
			return;
		}
		switch ( t.dataset.act ) {
			case 'new':
				openNew();
				break;
			case 'save':
				save( S.data && S.data.published ? 'draft' : '' );
				break;
			case 'publish':
				save( 'publish' );
				break;
			case 'floor-pick':
				pickFloorPlan();
				break;
			case 'doc-add':
				pickDocuments();
				break;
			case 'floor-remove':
				S.values.floor_plan = 0;
				S.data.floorPlan = { id: 0, url: '', name: '' };
				renderStep( 'resources' );
				changed();
				break;
			case 'switch-stay':
				$( '#zpvp-b-switch' ).hidden = true;
				break;
			case 'switch-go':
				$( '#zpvp-b-switch' ).hidden = true;
				S.dirty = false;
				load( S.pendingSwitch );
				break;
		}
	} );

	window.addEventListener( 'beforeunload', ( e ) => {
		if ( S.dirty ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	start();
}() );
