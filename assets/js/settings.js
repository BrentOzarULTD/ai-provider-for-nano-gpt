( function () {
	'use strict';

	function initializeModelTable() {
		const table = document.querySelector( '.nanogpt-model-table' );
		if ( ! table ) {
			return;
		}

		const tbody = table.tBodies[ 0 ];
		const rows = Array.from(
			tbody.querySelectorAll( '[data-nanogpt-model-row]' )
		);
		const filters = Array.from(
			document.querySelectorAll( '[data-nanogpt-filter]' )
		);
		const count = document.querySelector( '.nanogpt-model-count' );
		const reset = document.querySelector( '[data-nanogpt-reset-filters]' );
		let sortKey = '';
		let sortDirection = 1;

		function filterRows() {
			const values = {};
			filters.forEach( ( filter ) => {
				values[ filter.dataset.nanogptFilter ] = filter.value
					.trim()
					.toLowerCase();
			} );

			let visible = 0;
			rows.forEach( ( row ) => {
				const minimumContext = Number( values.context || 0 );
				const categories = ( row.dataset.category || '' ).split( ' ' );
				const matches =
					( ! values.model ||
						( row.dataset.model || '' ).includes( values.model ) ) &&
					( values.free === '' || row.dataset.free === values.free ) &&
					( ! minimumContext ||
						Number( row.dataset.context || 0 ) >= minimumContext ) &&
					( ! values.family ||
						( row.dataset.family || '' ).toLowerCase() ===
							values.family ) &&
					( ! values.release ||
						row.dataset.release === values.release ) &&
					( ! values.category ||
						categories.includes( values.category ) );

				row.hidden = ! matches;
				if ( matches ) {
					visible++;
				}
			} );

			if ( count ) {
				count.textContent = `${ visible } of ${ rows.length } models shown`;
			}
		}

		function sortRows( key ) {
			if ( sortKey === key ) {
				sortDirection *= -1;
			} else {
				sortKey = key;
				sortDirection = 1;
			}

			rows.sort( ( first, second ) => {
				const firstValue = first.dataset[ key ] || '';
				const secondValue = second.dataset[ key ] || '';
				let comparison;

				if ( key === 'free' || key === 'context' ) {
					comparison = Number( firstValue ) - Number( secondValue );
				} else {
					comparison = firstValue.localeCompare( secondValue, undefined, {
						numeric: true,
						sensitivity: 'base',
					} );
				}

				return comparison * sortDirection;
			} );

			rows.forEach( ( row ) => tbody.appendChild( row ) );
			table.querySelectorAll( 'th[aria-sort]' ).forEach( ( heading ) => {
				heading.setAttribute( 'aria-sort', 'none' );
			} );
			const activeButton = table.querySelector(
				`[data-nanogpt-sort="${ key }"]`
			);
			if ( activeButton ) {
				activeButton
					.closest( 'th' )
					.setAttribute(
						'aria-sort',
						sortDirection === 1 ? 'ascending' : 'descending'
					);
			}
		}

		filters.forEach( ( filter ) => {
			filter.addEventListener( 'input', filterRows );
			filter.addEventListener( 'change', filterRows );
		} );

		table.querySelectorAll( '[data-nanogpt-sort]' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				sortRows( button.dataset.nanogptSort );
			} );
		} );

		if ( reset ) {
			reset.addEventListener( 'click', () => {
				filters.forEach( ( filter ) => {
					filter.value = filter.dataset.nanogptFilter === 'context' ? '0' : '';
				} );
				filterRows();
			} );
		}

		filterRows();
	}

	function initializeConfirmations() {
		document.querySelectorAll( '[data-nanogpt-confirm]' ).forEach( ( button ) => {
			button.addEventListener( 'click', ( event ) => {
				if ( ! window.confirm( button.dataset.nanogptConfirm ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => {
			initializeModelTable();
			initializeConfirmations();
		} );
	} else {
		initializeModelTable();
		initializeConfirmations();
	}
}() );
