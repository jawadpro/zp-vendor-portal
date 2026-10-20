/* global ZPVP_APP */
( function () {
	'use strict';

	const C = window.ZPVP_APP;
	const root = document.getElementById( 'zpvp-app' );
	if ( ! C || ! root ) {
		return;
	}

	/* ---------------- Helpers ---------------- */

	const $ = ( sel, el = root ) => el.querySelector( sel );
	const $$ = ( sel, el = root ) => Array.from( el.querySelectorAll( sel ) );
	const esc = ( v ) => String( v ?? '' ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
	const money = ( n ) => '$' + Number( n || 0 ).toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	const unpaid = [ 'awaiting', 'pending', 'failed' ];

	const reads = new Map();
	async function latestRead( key, path ) {
		reads.get( key )?.abort();
		const controller = new AbortController();
		reads.set( key, controller );
		const result = await api( path, { signal: controller.signal } );
		if ( reads.get( key ) !== controller ) {
			throw new DOMException( 'Superseded request', 'AbortError' );
		}
		return result;
	}


	// Reads show progress where the content is (dimmed rows and totals);
	// only saves get the floating note. Both wait a moment so quick responses don't flicker.
	const BUSY_DELAY = 200;
	let pendingRequests = 0;
	let savingRequests = 0;
	let activityTimer = 0;
	const busyRegions = new Map();
	const activity = document.createElement( 'div' );
	activity.className = 'zpvp-activity';
	activity.setAttribute( 'role', 'status' );
	activity.setAttribute( 'aria-live', 'polite' );
	activity.hidden = true;
	root.before( activity );

	function regionFor( path ) {
		if ( /^\/summary(\?|$)/.test( path ) ) { return $( '#zpvp-kpis' ); }
		if ( /^\/registrations(\?|$)/.test( path ) ) { return $( '#zpvp-reg-table' ).parentElement; }
		if ( /^\/badges(\?|$)/.test( path ) ) { return $( '#zpvp-badge-table' ).parentElement; }
		return null;
	}

	function beginRequest( path, method = 'GET' ) {
		const isSave = ! [ 'GET', 'HEAD' ].includes( method.toUpperCase() );
		const region = isSave ? null : regionFor( path );
		pendingRequests++;
		root.setAttribute( 'aria-busy', 'true' );
		if ( isSave && ++savingRequests === 1 ) {
			activityTimer = setTimeout( () => {
				activity.textContent = 'Saving changes…';
				activity.hidden = false;
			}, 300 );
		}
		if ( region ) {
			const entry = busyRegions.get( region ) || { count: 0, timer: 0 };
			if ( ++entry.count === 1 ) {
				entry.timer = setTimeout( () => region.classList.add( 'is-busy' ), BUSY_DELAY );
			}
			busyRegions.set( region, entry );
			region.setAttribute( 'aria-busy', 'true' );
		}
		return () => {
			if ( region ) {
				const entry = busyRegions.get( region );
				if ( --entry.count === 0 ) {
					clearTimeout( entry.timer );
					busyRegions.delete( region );
					region.classList.remove( 'is-busy' );
					region.setAttribute( 'aria-busy', 'false' );
				}
			}
			if ( isSave && --savingRequests === 0 ) {
				clearTimeout( activityTimer );
				activity.hidden = true;
				activity.textContent = '';
			}
			if ( --pendingRequests === 0 ) {
				root.setAttribute( 'aria-busy', 'false' );
			}
		};
	}

	/* Placeholders shown the first time a section loads, shaped like the real content. */
	const skel = ( width, size = '' ) => `<span class="zpvp-skel${ size ? ' zpvp-skel--' + size : '' }" style="width:${ width }"></span>`;
	const kpiSkeleton = () => `<div class="zpvp-kpi zpvp-kpi--wide is-skeleton" aria-hidden="true">${ skel( '30%' ) }${ skel( '18%', 'lg' ) }</div>`
		+ [ '55%', '35%', '60%', '70%' ].map( ( w ) => `<div class="zpvp-kpi is-skeleton" aria-hidden="true">${ skel( w ) }${ skel( '45%', 'lg' ) }</div>` ).join( '' );
	const REG_HEAD = '<thead><tr><th>Company</th><th>Booths</th><th>Booth #</th><th>Staff</th><th class="zpvp-num">Amount</th><th>Payment</th><th><span class="screen-reader-text">Actions</span></th></tr></thead>';
	const badgeHead = ( all ) => `<thead><tr>
					<th class="zpvp-col-check"><input type="checkbox" data-check-all ${ all ? 'checked' : '' } aria-label="Select all badges"></th>
					<th>Name</th><th>Company</th>${ state.edition ? '' : '<th>Conference</th>' }<th>Type</th><th>Status</th><th>Printed</th>
				</tr></thead>`;
	function tableSkeleton( head, label ) {
		const cols = ( head.match( /<th[\s>]/g ) || [] ).length;
		const widths = [ '70%', '40%', '55%', '35%', '60%', '45%', '50%' ];
		const row = ( i ) => '<tr>' + Array.from( { length: cols }, ( _, c ) => c === 0
			? `<td class="zpvp-col-company">${ skel( [ '60%', '45%', '70%', '50%', '55%' ][ i ] ) }${ skel( '35%', 'sm' ) }</td>`
			: `<td>${ skel( widths[ ( c + i ) % widths.length ] ) }</td>` ).join( '' ) + '</tr>';
		return `${ head }<tbody class="zpvp-skel-rows" aria-hidden="true">${ [ 0, 1, 2, 3, 4 ].map( row ).join( '' ) }</tbody>
			<caption class="screen-reader-text">${ esc( label ) }</caption>`;
	}
	const isSkeleton = ( el ) => ! el.firstElementChild || !! el.querySelector( '.zpvp-skel-rows, .is-skeleton' );
	const loadError = ( cols, what ) => `<tbody><tr class="zpvp-empty-row"><td colspan="${ cols }">Couldn't load ${ what }. Refresh the page to try again.</td></tr></tbody>`;

	async function api( path, opts = {} ) {
		const finish = beginRequest( path, opts.method );
		try {
		// With plain permalinks the REST root already has a query string (?rest_route=/).
		const url = C.rest.includes( '?' ) ? C.rest + path.replace( '?', '&' ) : C.rest + path;
		const res = await fetch( url, {
			method: opts.method || 'GET',
			signal: opts.signal,
			headers: { 'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: opts.body ? JSON.stringify( opts.body ) : undefined,
		} );
		const data = await res.json().catch( () => ( {} ) );
		if ( ! res.ok ) {
			throw new Error( data.message || 'Something went wrong. Please try again.' );
		}
		return data;
		} finally {
			finish();
		}
	}

	function query( obj ) {
		const p = new URLSearchParams();
		Object.entries( obj ).forEach( ( [ k, v ] ) => {
			if ( v !== '' && v !== 0 && v != null ) {
				p.set( k, v );
			}
		} );
		const s = p.toString();
		return s ? '?' + s : '';
	}

	let toastTimer;
	function toast( msg, isError = false ) {
		const t = $( '#zpvp-toast' );
		t.textContent = msg;
		t.classList.toggle( 'is-error', isError );
		t.classList.add( 'is-visible' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( () => t.classList.remove( 'is-visible' ), 3200 );
	}

	function debounce( fn, ms ) {
		let t;
		return ( ...a ) => {
			clearTimeout( t );
			t = setTimeout( () => fn( ...a ), ms );
		};
	}

	function boothBadge( value ) {
		const label = String( value ?? '' ).trim();
		return label
			? '<span class="zpvp-booth-badge"><span class="zpvp-booth-badge__icon" aria-hidden="true">#</span>' + esc( label ) + '</span>'
			: '<span class="zpvp-booth-badge zpvp-booth-badge--empty">Not assigned</span>';
	}

	function pill( status, label ) {
		return `<span class="zpvp-pill zpvp-pill--${ esc( status ) }">${ esc( label ) }</span>`;
	}

	/* ---------------- State ---------------- */

	const url = new URLSearchParams( location.search );
	const state = {
		edition: url.has( 'edition' ) ? parseInt( url.get( 'edition' ), 10 ) || 0 : C.default,
		tab: url.get( 'tab' ) === 'badges' ? 'badges' : 'registrations',
		payment: '',
		search: '',
		category: 0,
		page: 1,
		badgeStatus: 'queued',
		selected: new Set(),
		rows: [],
	};

	function syncUrl() {
		const p = new URLSearchParams( location.search );
		p.set( 'page', 'zpvp' );
		state.edition ? p.set( 'edition', state.edition ) : p.delete( 'edition' );
		state.tab === 'badges' ? p.set( 'tab', 'badges' ) : p.delete( 'tab' );
		p.delete( 'compose' );
		history.replaceState( null, '', location.pathname + '?' + p.toString() );
	}

	/* ---------------- Conference switcher + totals ---------------- */

	function fillEditionSelect( select, withAll ) {
		select.innerHTML = ( withAll ? '<option value="0">All conferences</option>' : '' ) +
			C.editions.map( ( e ) => `<option value="${ e.id }">${ esc( e.title ) }${ e.dates ? ' (' + esc( e.dates ) + ')' : '' }</option>` ).join( '' );
	}

	const editionSelect = $( '#zpvp-edition' );
	fillEditionSelect( editionSelect, true );
	editionSelect.value = String( state.edition );
	editionSelect.addEventListener( 'change', () => {
		state.edition = parseInt( editionSelect.value, 10 ) || 0;
		state.page = 1;
		state.selected.clear();
		syncUrl();
		refreshAll();
	} );

	$( '#zpvp-new-conf' ).href = C.newConf;

	async function loadSummary() {
		const e = C.editions.find( ( x ) => x.id === state.edition );
		$( '#zpvp-edition-status' ).innerHTML = e ? pill( e.status === 'open' ? 'paid' : e.status === 'sold_out' ? 'failed' : 'neutral', e.label ) : '';
		const kpis = $( '#zpvp-kpis' );
		if ( ! kpis.firstElementChild ) { kpis.innerHTML = kpiSkeleton(); }
		let s;
		try {
			s = await latestRead( 'summary', '/summary' + query( { edition: state.edition } ) );
		} catch ( e ) {
			if ( e.name === 'AbortError' ) { return; }
			if ( kpis.querySelector( '.is-skeleton' ) ) { kpis.innerHTML = '<p class="zpvp-kpis__error">Couldn\'t load the totals. Refresh the page to try again.</p>'; }
			throw e;
		}
		const cap = s.capacity;
		const pct = cap ? Math.min( 100, Math.round( ( 100 * s.booths ) / cap ) ) : null;
		$( '#zpvp-kpis' ).innerHTML = `
			<div class="zpvp-kpi zpvp-kpi--wide">
				<span class="zpvp-kpi__label">Booths sold</span>
				<strong>${ s.booths }${ cap ? ' <small>of ' + cap + '</small>' : '' }</strong>
				${ pct !== null ? `<span class="zpvp-meter" role="img" aria-label="${ pct }% of booths sold"><span style="width:${ pct }%"${ pct >= 100 ? ' class="is-full"' : '' }></span></span>` : '' }
			</div>
			<div class="zpvp-kpi"><span class="zpvp-kpi__label">Registrations</span><strong>${ s.registrations }</strong></div>
			<div class="zpvp-kpi"><span class="zpvp-kpi__label">Paid</span><strong>${ money( s.paid ) }</strong></div>
			<button type="button" class="zpvp-kpi zpvp-kpi--link${ s.outstanding > 0 ? ' is-alert' : '' }" data-goto="outstanding"><span class="zpvp-kpi__label">Outstanding</span><strong>${ money( s.outstanding ) }</strong></button>
			<button type="button" class="zpvp-kpi zpvp-kpi--link" data-goto="badges"><span class="zpvp-kpi__label">Badges to print</span><strong>${ s.badges_queued }</strong>${ s.badges_owed ? `<small class="zpvp-kpi__note">${ s.badges_owed } need payment</small>` : '' }</button>`;
		$( '[data-count="badges"]' ).textContent = s.badges_queued ? s.badges_queued : '';
	}

	$( '#zpvp-kpis' ).addEventListener( 'click', ( ev ) => {
		const b = ev.target.closest( '[data-goto]' );
		if ( ! b ) {
			return;
		}
		if ( b.dataset.goto === 'badges' ) {
			state.badgeStatus = 'queued';
			selectTab( 'badges' );
		} else {
			state.payment = 'outstanding';
			state.page = 1;
			selectTab( 'registrations' );
		}
	} );

	/* ---------------- Tabs ---------------- */

	function selectTab( name ) {
		state.tab = name;
		$$( '[role="tab"]' ).forEach( ( t ) => {
			const on = t.dataset.tab === name;
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.tabIndex = on ? 0 : -1;
		} );
		$( '#zpvp-panel-registrations' ).hidden = name !== 'registrations';
		$( '#zpvp-panel-badges' ).hidden = name !== 'badges';
		syncUrl();
		name === 'badges' ? loadBadges() : loadRegistrations();
	}

	$$( '[role="tab"]' ).forEach( ( t ) => {
		t.addEventListener( 'click', () => selectTab( t.dataset.tab ) );
		t.addEventListener( 'keydown', ( ev ) => {
			if ( ev.key === 'ArrowRight' || ev.key === 'ArrowLeft' ) {
				const next = t.dataset.tab === 'registrations' ? 'badges' : 'registrations';
				selectTab( next );
				$( `[data-tab="${ next }"]` ).focus();
			}
		} );
	} );

	/* ---------------- Registrations ---------------- */

	const payChips = [
		[ '', 'All', 'all' ],
		[ 'outstanding', 'Outstanding', 'outstanding' ],
		[ 'paid', 'Paid', 'paid' ],
		[ 'failed', 'Failed', 'failed' ],
	];

	function renderPayChips( counts = {} ) {
		$( '#zpvp-pay-chips' ).innerHTML = payChips.map( ( [ val, label, key ] ) =>
			`<button type="button" class="zpvp-chip${ state.payment === val ? ' is-on' : '' }" aria-pressed="${ state.payment === val }" data-pay="${ val }">${ label }${ counts[ key ] !== undefined ? ` <span>${ counts[ key ] }</span>` : '' }</button>`
		).join( '' );
	}

	$( '#zpvp-pay-chips' ).addEventListener( 'click', ( ev ) => {
		const b = ev.target.closest( '[data-pay]' );
		if ( b ) {
			state.payment = b.dataset.pay;
			state.page = 1;
			loadRegistrations();
		}
	} );

	const categorySelect = $( '#zpvp-category' );
	if ( categorySelect ) {
		categorySelect.addEventListener( 'change', () => {
			state.category = parseInt( categorySelect.value, 10 ) || 0;
			state.page = 1;
			loadRegistrations();
		} );
	}

	$( '#zpvp-search' ).addEventListener( 'input', debounce( ( ev ) => {
		state.search = ev.target.value.trim();
		state.page = 1;
		loadRegistrations();
	}, 250 ) );

	function updateExportLink() {
		$( '#zpvp-export' ).href = C.exportUrl + '&' + new URLSearchParams( {
			edition: state.edition || '',
			payment: state.payment,
			s: state.search,
			category: state.category || '',
		} ).toString();
	}

	function regRow( r ) {
		const showConf = ! state.edition;
		return `<tr data-id="${ r.id }" tabindex="0" aria-label="Open ${ esc( r.company ) }">
			<td class="zpvp-col-company"><strong>${ esc( r.company || '(no company)' ) }</strong>
				<span class="zpvp-sub">${ esc( [ r.contact, r.email ].filter( Boolean ).join( ', ' ) ) }</span>
				${ showConf ? `<span class="zpvp-sub">${ esc( r.conference ) }</span>` : '' }
				${ r.categories && r.categories.length ? `<span class="zpvp-sub">${ esc( r.categories.join( ', ' ) ) }</span>` : '' }
				${ r.has_account ? '' : '<span class="zpvp-sub zpvp-warn">No vendor account linked</span>' }</td>
			<td>${ r.booths }</td>
			<td>${ boothBadge( r.booth_number ) }</td>
			<td>${ r.people }</td>
			<td class="zpvp-num">${ money( r.amount ) }</td>
			<td>${ pill( r.payment_status, r.payment_label ) }<span class="zpvp-sub">${ esc( r.method ) }${ r.po ? ', PO ' + esc( r.po ) : '' }</span></td>
			<td class="zpvp-col-actions">${ unpaid.includes( r.payment_status ) ? `<button type="button" class="button button-small" data-paid="${ r.id }">Mark paid</button>` : '' }
				<button type="button" class="button button-small" data-open="${ r.id }">Open</button></td>
		</tr>`;
	}

	let loadRegistrationsVersion = 0;
	async function loadRegistrations() {
		const version = ++loadRegistrationsVersion;
		const table = $( '#zpvp-reg-table' );
		if ( isSkeleton( table ) ) { table.innerHTML = tableSkeleton( REG_HEAD, 'Loading registrations' ); }
		updateExportLink();
		try {
			const d = await latestRead( 'registrations', '/registrations' + query( { edition: state.edition, payment: state.payment, search: state.search, category: state.category, page: state.page } ) );
			state.rows = d.rows;
			renderPayChips( d.counts );
			$( '[data-count="registrations"]' ).textContent = d.counts.all || '';
			table.innerHTML = `${ REG_HEAD }
				<tbody>${ d.rows.length ? d.rows.map( regRow ).join( '' ) : `<tr class="zpvp-empty-row"><td colspan="7">${ state.search || state.payment ? 'No registrations match these filters.' : 'No registrations for this conference yet. They appear here as vendors submit the registration form.' }</td></tr>` }</tbody>`;
			$( '#zpvp-pager' ).innerHTML = d.pages > 1
				? `<button type="button" class="button" data-page="${ d.page - 1 }" ${ d.page <= 1 ? 'disabled' : '' }>Previous</button>
				   <span>Page ${ d.page } of ${ d.pages } (${ d.total } registrations)</span>
				   <button type="button" class="button" data-page="${ d.page + 1 }" ${ d.page >= d.pages ? 'disabled' : '' }>Next</button>`
				: `<span>${ d.total } registration${ d.total === 1 ? '' : 's' }</span>`;
		} catch ( e ) {
			if ( e.name !== 'AbortError' ) {
				toast( e.message, true );
				if ( version === loadRegistrationsVersion && isSkeleton( table ) ) { table.innerHTML = loadError( 7, 'registrations' ); }
			}
		}
	}

	$( '#zpvp-pager' ).addEventListener( 'click', ( ev ) => {
		const b = ev.target.closest( '[data-page]' );
		if ( b && ! b.disabled ) {
			state.page = parseInt( b.dataset.page, 10 );
			loadRegistrations();
		}
	} );

	const regTable = $( '#zpvp-reg-table' );

	regTable.addEventListener( 'click', async ( ev ) => {
		const paid = ev.target.closest( '[data-paid]' );
		if ( paid ) {
			ev.stopPropagation();
			paid.disabled = true;
			try {
				await api( `/registrations/${ paid.dataset.paid }/paid`, { method: 'POST' } );
				toast( 'Marked paid. Receipt notifications follow your email settings.' );
				loadRegistrations();
				loadSummary();
			} catch ( e ) {
				paid.disabled = false;
				toast( e.message, true );
			}
			return;
		}
		if ( ev.target.closest( 'input, a' ) ) {
			return;
		}
		const tr = ev.target.closest( 'tr[data-id]' );
		if ( tr ) {
			openDrawer( parseInt( tr.dataset.id, 10 ), tr );
		}
	} );

	regTable.addEventListener( 'keydown', ( ev ) => {
		if ( ev.key === 'Enter' && ev.target.matches( 'tr[data-id]' ) ) {
			openDrawer( parseInt( ev.target.dataset.id, 10 ), ev.target );
		}
	} );

	/* ---------------- Side panel ---------------- */

	const drawer = $( '#zpvp-drawer' );
	let lastFocus = null;
	let current = null;

	function staffRow( a = {} ) {
		return `<div class="zpvp-rep__row" data-attendee="${ a.id || '' }">
			<input type="text" aria-label="Name" placeholder="Name" value="${ esc( a.name ) }" data-f="name" autocomplete="off" data-1p-ignore data-lpignore="true">
			<input type="text" aria-label="Title" placeholder="Title" value="${ esc( a.title ) }" data-f="title" autocomplete="off" data-1p-ignore data-lpignore="true">
			<input type="email" aria-label="Email" placeholder="Email (optional)" value="${ esc( a.email ) }" data-f="email" autocomplete="off" data-1p-ignore data-lpignore="true">
			<button type="button" class="zpvp-x" data-remove aria-label="Remove ${ esc( a.name || 'this person' ) }">&times;</button>
		</div>`;
	}

	function renderDrawer( r ) {
		current = r;
		const statusOptions = C.statuses.map( ( s ) => `<option value="${ s.value }"${ s.value === r.payment_status ? ' selected' : '' }>${ esc( s.label ) }</option>` ).join( '' );
		$( '#zpvp-drawer-body' ).innerHTML = `
			<div class="zpvp-drawer__head">
				<div>
					<h2 id="zpvp-drawer-title">${ esc( r.company || '(no company)' ) }</h2>
					<p>${ esc( r.conference ) }</p>
				</div>
				<button type="button" class="zpvp-x" data-close aria-label="Close panel">&times;</button>
			</div>

			<dl class="zpvp-facts">
				<div><dt>Contact</dt><dd>${ esc( r.contact ) }<br><a href="mailto:${ esc( r.email ) }">${ esc( r.email ) }</a></dd></div>
				<div><dt>Vendor account</dt><dd>${ r.vendor_url ? `<a href="${ esc( r.vendor_url ) }">View account</a>` : 'None linked' }${ r.company_url ? `<br><a href="${ esc( r.company_url ) }">Company</a>${ r.categories && r.categories.length ? ' <span class="zpvp-sub">' + esc( r.categories.join( ', ' ) ) + '</span>' : '' }` : '' }</dd></div>
				<div><dt>Registration</dt><dd>${ esc( r.type ) }, ${ r.booths } booth${ r.booths === 1 ? '' : 's' }, ${ esc( r.created ) }</dd></div>
				<div><dt>Amount</dt><dd>${ money( r.amount ) }<br><span class="zpvp-sub">${ esc( r.method ) }${ r.po ? ', PO ' + esc( r.po ) : '' }</span></dd></div>
			</dl>
			<p class="zpvp-drawer__links">
				<a class="button" href="${ esc( r.document_url ) }" target="_blank" rel="noopener">${ esc( r.document_label ) }</a>
				<a class="button" href="${ esc( r.entry_url ) }">Form entry</a>
				${ r.badges_owed ? `<a class="button" href="${ esc( r.badge_invoice_url ) }" target="_blank" rel="noopener">Extra badges invoice (${ r.badges_owed })</a>` : '' }
			</p>

			<form class="zpvp-form zpvp-drawer__section" id="zpvp-reg-form">
				<h3>Registration</h3>
				<div class="zpvp-grid2">
					<p><label for="zpvp-f-booth">Booth number</label><input id="zpvp-f-booth" name="booth_number" type="text" value="${ esc( r.booth_number ) }" placeholder="e.g. 14, 15"></p>
					<p><label for="zpvp-f-status">Payment status</label><select id="zpvp-f-status" name="payment_status">${ statusOptions }</select></p>
					<p><label for="zpvp-f-inc">Badges included</label><input id="zpvp-f-inc" name="included_badges" type="number" min="0" value="${ r.included_badges }"></p>
					<p><label for="zpvp-f-extra">Extra badges purchased</label><input id="zpvp-f-extra" name="paid_badges" type="number" min="0" value="${ r.paid_badges }"></p>
				</div>
				<p><label for="zpvp-f-notes">Staff notes <span class="zpvp-sub">(not visible to the vendor)</span></label><textarea id="zpvp-f-notes" name="notes" rows="4">${ esc( r.notes ) }</textarea></p>
				<p><button type="submit" class="button button-primary">Save changes</button></p>
			</form>

			<div class="zpvp-drawer__section">
				<h3>Booth staff <span class="zpvp-count">${ r.attendees.length }</span></h3>
				<p class="zpvp-sub">${ esc( r.badge_summary ) }${ r.deadline ? ' Vendor can edit until ' + esc( r.deadline ) + '.' : '' }${ r.badge_orders ? ' ' + r.badge_orders + ' badge order' + ( r.badge_orders === 1 ? '' : 's' ) + ' linked.' : '' }</p>
				<div class="zpvp-rep" id="zpvp-rep">
					<div class="zpvp-rep__head" aria-hidden="true"><span>Name</span><span>Title</span><span>Email</span><span></span></div>
					${ r.attendees.map( staffRow ).join( '' ) }
				</div>
				<p class="zpvp-rep__actions">
					<button type="button" class="button" data-add>+ Add person</button>
					<button type="button" class="button button-primary" data-save-staff>Save booth staff</button>
				</p>
			</div>`;
		if ( ! r.attendees.length ) {
			$( '#zpvp-rep' ).insertAdjacentHTML( 'beforeend', staffRow() );
		}
	}

	let drawerVersion = 0;
	async function openDrawer( id, from ) {
		const version = ++drawerVersion;
		lastFocus = from || document.activeElement;
		drawer.hidden = false;
		document.body.classList.add( 'zpvp-noscroll' );
		$( '#zpvp-drawer-body' ).innerHTML = `<div class="zpvp-drawer-skel" role="status">
				<span class="screen-reader-text">Loading registration details…</span>
				${ skel( '55%', 'xl' ) }${ skel( '35%' ) }
				<div class="zpvp-drawer-skel__grid">${ [ 1, 2, 3, 4 ].map( () => `<div>${ skel( '50%', 'sm' ) }${ skel( '75%' ) }</div>` ).join( '' ) }</div>
				${ skel( '30%', 'lg' ) }${ [ '90%', '80%', '85%' ].map( ( w ) => skel( w ) ).join( '' ) }
			</div>`;
		try {
			const registration = await latestRead( 'drawer', `/registrations/${ id }` );
			if ( version !== drawerVersion || drawer.hidden ) { return; }
			renderDrawer( registration );
			$( '.zpvp-drawer__panel' ).focus();
		} catch ( e ) {
			if ( e.name !== 'AbortError' && version === drawerVersion ) {
				toast( e.message, true );
				closeDrawer();
			}
		}
	}

	function closeDrawer() {
		++drawerVersion;
		reads.get( 'drawer' )?.abort();
		drawer.hidden = true;
		document.body.classList.remove( 'zpvp-noscroll' );
		current = null;
		if ( lastFocus && document.contains( lastFocus ) ) {
			lastFocus.focus();
		}
	}

	drawer.addEventListener( 'click', async ( ev ) => {
		if ( ev.target.closest( '[data-close]' ) ) {
			closeDrawer();
			return;
		}
		if ( ev.target.closest( '[data-add]' ) ) {
			$( '#zpvp-rep' ).insertAdjacentHTML( 'beforeend', staffRow() );
			$$( '.zpvp-rep__row', drawer ).pop().querySelector( 'input' ).focus();
			return;
		}
		const rm = ev.target.closest( '[data-remove]' );
		if ( rm ) {
			const row = rm.closest( '.zpvp-rep__row' );
			const next = row.nextElementSibling || row.previousElementSibling;
			row.remove();
			if ( next && next.querySelector ) {
				const f = next.querySelector( 'input' );
				if ( f ) {
					f.focus();
				}
			}
			return;
		}
		const save = ev.target.closest( '[data-save-staff]' );
		if ( save && current ) {
			const attendees = $$( '.zpvp-rep__row', drawer ).map( ( row ) => ( {
				id: parseInt( row.dataset.attendee, 10 ) || 0,
				name: row.querySelector( '[data-f="name"]' ).value.trim(),
				title: row.querySelector( '[data-f="title"]' ).value.trim(),
				email: row.querySelector( '[data-f="email"]' ).value.trim(),
			} ) ).filter( ( a ) => a.id || a.name );
			const missing = attendees.find( ( a ) => a.id && ! a.name );
			if ( missing ) {
				toast( 'Every person needs a name. Use × to remove someone instead.', true );
				return;
			}
			save.disabled = true;
			try {
				const d = await api( `/registrations/${ current.id }/attendees`, { method: 'POST', body: { attendees } } );
				renderDrawer( d.registration );
				toast( d.errors.length ? d.errors.join( ' ' ) : 'Booth staff saved. Badges updated.', d.errors.length > 0 );
				loadRegistrations();
				loadSummary();
			} catch ( e ) {
				toast( e.message, true );
			} finally {
				save.disabled = false;
			}
		}
	} );

	drawer.addEventListener( 'submit', async ( ev ) => {
		if ( ev.target.id !== 'zpvp-reg-form' || ! current ) {
			return;
		}
		ev.preventDefault();
		const f = new FormData( ev.target );
		const btn = ev.target.querySelector( '[type="submit"]' );
		btn.disabled = true;
		try {
			const d = await api( `/registrations/${ current.id }`, { method: 'POST', body: Object.fromEntries( f.entries() ) } );
			renderDrawer( d );
			toast( 'Registration saved.' );
			loadRegistrations();
			loadSummary();
		} catch ( e ) {
			toast( e.message, true );
		} finally {
			btn.disabled = false;
		}
	} );

	/* ---------------- Badges ---------------- */

	const badgeChips = [ [ 'queued', 'Ready to print' ], [ 'printed', 'Printed' ], [ 'owed', 'Needs payment' ], [ 'all', 'All' ] ];

	function renderBadgeChips() {
		$( '#zpvp-badge-chips' ).innerHTML = badgeChips.map( ( [ v, l ] ) =>
			`<button type="button" class="zpvp-chip${ state.badgeStatus === v ? ' is-on' : '' }" aria-pressed="${ state.badgeStatus === v }" data-badge-status="${ v }">${ l }</button>`
		).join( '' );
	}

	$( '#zpvp-badge-chips' ).addEventListener( 'click', ( ev ) => {
		const b = ev.target.closest( '[data-badge-status]' );
		if ( b ) {
			state.badgeStatus = b.dataset.badgeStatus;
			state.selected.clear();
			loadBadges();
		}
	} );

	function updateBulkbar() {
		const n = state.selected.size;
		$$( '[data-bulk]' ).forEach( ( b ) => { b.disabled = n === 0; } );
		$( '#zpvp-bulkbar' ).classList.toggle( 'is-empty', n === 0 );
		$( '#zpvp-selected' ).textContent = n ? `${ n } selected` : 'Tick badges to print or update them';
	}

	let loadBadgesVersion = 0;
	async function loadBadges() {
		const version = ++loadBadgesVersion;
		renderBadgeChips();
		const table = $( '#zpvp-badge-table' );
		if ( isSkeleton( table ) ) { table.innerHTML = tableSkeleton( badgeHead( false ), 'Loading badges' ); }
		try {
			const d = await latestRead( 'badges', '/badges' + query( { edition: state.edition, status: state.badgeStatus } ) );
			const ids = new Set( d.rows.map( ( b ) => b.id ) );
			state.selected.forEach( ( id ) => ids.has( id ) || state.selected.delete( id ) );
			const all = d.rows.length && d.rows.every( ( b ) => state.selected.has( b.id ) );
			table.innerHTML = `${ badgeHead( all ) }
				<tbody>${ d.rows.length ? d.rows.map( ( b ) => `<tr>
					<td class="zpvp-col-check"><input type="checkbox" data-badge="${ b.id }" ${ state.selected.has( b.id ) ? 'checked' : '' } aria-label="Select ${ esc( b.name ) }"></td>
					<td><strong>${ esc( b.name ) }</strong><span class="zpvp-sub">${ esc( b.title ) }</span></td>
					<td>${ esc( b.company ) }</td>
					${ state.edition ? '' : `<td>${ esc( b.conference ) }</td>` }
					<td>${ esc( b.type ) }${ b.needs_payment ? '<span class="zpvp-sub zpvp-warn">Needs payment</span>' : '' }</td>
					<td>${ pill( b.status === 'queued' ? 'pending' : b.status === 'printed' ? 'paid' : 'neutral', b.status_label ) }${ b.reprint ? '<span class="zpvp-sub">Reprint</span>' : '' }</td>
					<td>${ esc( b.printed ) || '—' }</td>
				</tr>` ).join( '' ) : `<tr class="zpvp-empty-row"><td colspan="7">${ state.badgeStatus === 'queued' ? 'Nothing waiting to print.' : 'No badges match this filter.' }</td></tr>` }</tbody>`;
			updateBulkbar();
		} catch ( e ) {
			if ( e.name !== 'AbortError' ) {
				toast( e.message, true );
				if ( version === loadBadgesVersion && isSkeleton( table ) ) { table.innerHTML = loadError( 7, 'badges' ); }
			}
		}
	}

	$( '#zpvp-badge-table' ).addEventListener( 'change', ( ev ) => {
		if ( ev.target.matches( '[data-check-all]' ) ) {
			$$( '[data-badge]' ).forEach( ( c ) => {
				c.checked = ev.target.checked;
				ev.target.checked ? state.selected.add( parseInt( c.dataset.badge, 10 ) ) : state.selected.delete( parseInt( c.dataset.badge, 10 ) );
			} );
		} else if ( ev.target.matches( '[data-badge]' ) ) {
			const id = parseInt( ev.target.dataset.badge, 10 );
			ev.target.checked ? state.selected.add( id ) : state.selected.delete( id );
		}
		updateBulkbar();
	} );

	$( '#zpvp-bulkbar' ).addEventListener( 'click', async ( ev ) => {
		const b = ev.target.closest( '[data-bulk]' );
		if ( ! b || ! state.selected.size ) {
			return;
		}
		const ids = Array.from( state.selected );
		if ( b.dataset.bulk === 'print' ) {
			const form = document.createElement( 'form' );
			form.method = 'post';
			form.action = C.postUrl;
			form.target = '_blank';
			form.innerHTML = `<input type="hidden" name="action" value="zpvp_badges_bulk"><input type="hidden" name="bulk" value="print"><input type="hidden" name="_wpnonce" value="${ esc( C.printNonce ) }">` +
				ids.map( ( id ) => `<input type="hidden" name="ids[]" value="${ id }">` ).join( '' );
			document.body.appendChild( form );
			form.submit();
			form.remove();
			toast( 'The print sheet opened in a new tab. Mark the badges printed there when done.' );
			return;
		}
		if ( b.dataset.bulk === 'void' && ! window.confirm( `Void ${ ids.length } badge(s)? They will not be printed.` ) ) {
			return;
		}
		b.disabled = true;
		try {
			const d = await api( '/badges/bulk', { method: 'POST', body: { ids, action: b.dataset.bulk } } );
			toast( `${ d.updated } badge(s) updated.` );
			state.selected.clear();
			loadBadges();
			loadSummary();
		} catch ( e ) {
			toast( e.message, true );
		} finally {
			b.disabled = false;
		}
	} );

	// Coming back from the print tab: refresh so printed badges move on.
	window.addEventListener( 'focus', debounce( () => {
		if ( state.tab === 'badges' ) {
			loadBadges();
			loadSummary();
		}
	}, 300 ) );

	/* ---------------- Email vendors ---------------- */

	const modal = $( '#zpvp-modal' );
	const composeEdition = $( '#zpvp-c-edition' );
	fillEditionSelect( composeEdition, false );
	composeEdition.insertAdjacentHTML( 'beforeend', '<option value="0">Every vendor company (not one conference)</option>' );

	// "Sends to N" under the form, refreshed whenever the audience changes.
	const updateAudience = debounce( async () => {
		const form = $( '#zpvp-compose' );
		const out = $( '#zpvp-c-count' );
		const data = Object.fromEntries( new FormData( form ).entries() );
		const company = data.edition === '0';
		$( '#zpvp-c-aud' ).disabled = company;
		try {
			const d = await latestRead( 'audience', '/messages/audience' + query( { edition: data.edition, audience: data.audience, category: data.category } ) );
			out.textContent = d.label;
		} catch ( e ) {
			if ( e.name !== 'AbortError' ) { out.textContent = ''; }
		}
	}, 200 );
	// Swap the starter text when switching between one conference and every company, unless it was edited.
	const TEMPLATE_CONF = 'Hi {first_name},\n\nWe have an update about {conference} ({dates}):\n\n[Describe the change here.]\n\nYou can review your registration and booth staff at {portal_link}\n\nThank you,\nZacPubs';
	const TEMPLATE_ALL = 'Hi {first_name},\n\n[Write your message here.]\n\nYou can see your conferences at {portal_link}\n\nThank you,\nZacPubs';
	composeEdition.addEventListener( 'change', () => {
		const body = $( '#zpvp-c-body' );
		const all = composeEdition.value === '0';
		if ( body.value === ( all ? TEMPLATE_CONF : TEMPLATE_ALL ) ) {
			body.value = all ? TEMPLATE_ALL : TEMPLATE_CONF;
		}
		const subject = $( '#zpvp-c-subject' );
		const e = C.editions.find( ( x ) => x.id === parseInt( composeEdition.value, 10 ) );
		if ( subject.value.startsWith( 'Update: ' ) || ! subject.value ) {
			subject.value = e ? 'Update: ' + e.title : '';
		}
	} );
	[ '#zpvp-c-edition', '#zpvp-c-aud', '#zpvp-c-cat' ].forEach( ( sel ) => {
		const el = $( sel );
		if ( el ) {
			el.addEventListener( 'change', updateAudience );
		}
	} );

	function openCompose() {
		lastFocus = document.activeElement;
		const id = parseInt( state.edition, 10 ) || ( C.editions[ 0 ] && C.editions[ 0 ].id );
		composeEdition.value = String( id || '' );
		const e = C.editions.find( ( x ) => x.id === parseInt( composeEdition.value, 10 ) );
		const subject = $( '#zpvp-c-subject' );
		const body = $( '#zpvp-c-body' );
		if ( ! subject.value && e ) {
			subject.value = 'Update: ' + e.title;
		}
		if ( ! body.value ) {
			body.value = TEMPLATE_CONF;
		}
		modal.hidden = false;
		updateAudience();
		document.body.classList.add( 'zpvp-noscroll' );
		$( '.zpvp-modal__box' ).focus();
	}

	function closeCompose() {
		modal.hidden = true;
		document.body.classList.remove( 'zpvp-noscroll' );
		if ( lastFocus ) {
			lastFocus.focus();
		}
	}

	$( '[data-action="compose"]' ).addEventListener( 'click', openCompose );
	modal.addEventListener( 'click', async ( ev ) => {
		if ( ev.target.closest( '[data-close]' ) ) {
			closeCompose();
			return;
		}
		const b = ev.target.closest( '[data-send]' );
		if ( ! b ) {
			return;
		}
		const form = $( '#zpvp-compose' );
		if ( ! form.reportValidity() ) {
			return;
		}
		if ( b.dataset.send === 'send' && ! window.confirm( 'Send this message to every selected vendor?' ) ) {
			return;
		}
		b.disabled = true;
		try {
			const d = await api( '/messages', { method: 'POST', body: { ...Object.fromEntries( new FormData( form ).entries() ), mode: b.dataset.send } } );
			toast( d.message );
			if ( b.dataset.send === 'send' ) {
				form.reset();
				closeCompose();
			}
		} catch ( e ) {
			toast( e.message, true );
		} finally {
			b.disabled = false;
		}
	} );

	document.addEventListener( 'keydown', ( ev ) => {
		if ( ev.key !== 'Escape' ) {
			return;
		}
		if ( ! modal.hidden ) {
			closeCompose();
		} else if ( ! drawer.hidden ) {
			closeDrawer();
		}
	} );

	/* ---------------- Start ---------------- */

	function refreshAll() {
		loadSummary().catch( ( e ) => toast( e.message, true ) );
		state.tab === 'badges' ? loadBadges() : loadRegistrations();
	}

	renderPayChips();
	selectTab( state.tab );
	loadSummary().catch( ( e ) => toast( e.message, true ) );
	if ( url.has( 'compose' ) ) {
		openCompose();
	}
}() );
