/**
 * WP Site Doctor - Admin Dashboard Controller
 *
 * Initializes the dashboard page, binds event handlers,
 * and manages tab switching for scan result categories.
 *
 * @package WPSiteDoctor
 */

/* global wpsdData, WPSDGauge, WPSDScanner */

( function() {
	'use strict';

	/**
	 * Initialize on DOM ready.
	 */
	document.addEventListener( 'DOMContentLoaded', function() {
		initGauge();
		bindScanButton();
		bindTabs();
		bindFixButtons();
	} );

	/**
	 * Initialize the health gauge with the last known score.
	 */
	function initGauge() {
		if ( typeof wpsdData === 'undefined' || ! window.WPSDGauge ) {
			return;
		}

		if ( wpsdData.lastScore !== null && wpsdData.lastScore !== undefined ) {
			WPSDGauge.init( parseInt( wpsdData.lastScore, 10 ) );
		}
	}

	/**
	 * Bind click handler to the "Run Full Scan" button.
	 */
	function bindScanButton() {
		var btn = document.getElementById( 'wpsd-run-scan' );
		if ( ! btn || typeof WPSDScanner === 'undefined' ) {
			return;
		}

		btn.addEventListener( 'click', function( e ) {
			e.preventDefault();
			if ( ! btn.disabled ) {
				WPSDScanner.start();
			}
		} );
	}

	/**
	 * Bind tab switching for category result panels.
	 */
	function bindTabs() {
		var tabs = document.querySelectorAll( '.wpsd-tab' );
		if ( ! tabs.length ) {
			return;
		}

		tabs.forEach( function( tab ) {
			tab.addEventListener( 'click', function() {
				var scannerId = this.getAttribute( 'data-scanner' );
				if ( ! scannerId ) {
					return;
				}

				// Deactivate all tabs.
				tabs.forEach( function( t ) {
					t.classList.remove( 'wpsd-tab-active' );
					t.setAttribute( 'aria-selected', 'false' );
					t.setAttribute( 'tabindex', '-1' );
				} );

				// Activate clicked tab.
				this.classList.add( 'wpsd-tab-active' );
				this.setAttribute( 'aria-selected', 'true' );
				this.setAttribute( 'tabindex', '0' );

				// Hide all panels.
				var panels = document.querySelectorAll( '.wpsd-panel' );
				panels.forEach( function( panel ) {
					panel.classList.remove( 'wpsd-panel-active' );
					panel.setAttribute( 'hidden', '' );
				} );

				// Show target panel.
				var targetPanel = document.getElementById( 'wpsd-panel-' + scannerId );
				if ( targetPanel ) {
					targetPanel.classList.add( 'wpsd-panel-active' );
					targetPanel.removeAttribute( 'hidden' );
				}
			} );

			// Keyboard support for tabs.
			tab.addEventListener( 'keydown', function( e ) {
				var index = Array.from( tabs ).indexOf( this );
				var nextIndex;

				if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) {
					e.preventDefault();
					nextIndex = ( index + 1 ) % tabs.length;
					tabs[ nextIndex ].focus();
					tabs[ nextIndex ].click();
				} else if ( e.key === 'ArrowLeft' || e.key === 'ArrowUp' ) {
					e.preventDefault();
					nextIndex = ( index - 1 + tabs.length ) % tabs.length;
					tabs[ nextIndex ].focus();
					tabs[ nextIndex ].click();
				}
			} );
		} );
	}

	/**
	 * Bind click handlers for inline "Fix Now" buttons.
	 */
	function bindFixButtons() {
		document.addEventListener( 'click', function( e ) {
			var btn = e.target.closest( '.wpsd-fix-btn' );
			if ( ! btn ) {
				return;
			}

			e.preventDefault();

			var actionId = btn.getAttribute( 'data-action-id' );
			var actionLabel = btn.getAttribute( 'data-action-label' );

			if ( ! actionId ) {
				return;
			}

			// Confirm before running repair.
			if ( typeof wpsdData !== 'undefined' && wpsdData.i18n.confirmRepair ) {
				if ( ! window.confirm( wpsdData.i18n.confirmRepair + '\n\n' + actionLabel ) ) {
					return;
				}
			}

			btn.disabled = true;
			btn.textContent = wpsdData.i18n.repairing || 'Repairing...';

			var formData = new FormData();
			formData.append( 'action', 'wpsd_run_repair' );
			formData.append( 'nonce', wpsdData.nonce );
			formData.append( 'action_id', actionId );
			formData.append( 'session_id', '' ); // Latest session.

			fetch( wpsdData.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin'
			} )
				.then( function( response ) {
					return response.json();
				} )
				.then( function( response ) {
					if ( response.success ) {
						btn.textContent = wpsdData.i18n.repairComplete || 'Fixed!';
						btn.classList.add( 'button-primary' );

						// Remove the parent issue card after a brief delay.
						setTimeout( function() {
							var issue = btn.closest( '.wpsd-issue' );
							if ( issue ) {
								issue.style.opacity = '0.5';
							}
						}, 1000 );
					} else {
						btn.textContent = wpsdData.i18n.repairFailed || 'Failed';
						btn.disabled = false;
						window.alert( response.data.message || 'Repair failed.' );
					}
				} )
				.catch( function() {
					btn.textContent = wpsdData.i18n.repairFailed || 'Failed';
					btn.disabled = false;
				} );
		} );
	}

} )();
