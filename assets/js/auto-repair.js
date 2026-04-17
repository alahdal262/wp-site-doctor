/**
 * WP Site Doctor - Auto-Repair Controller
 *
 * Manages the repair confirmation page: sequential AJAX execution
 * of checked repairs with progress tracking and before/after comparison.
 *
 * @package WPSiteDoctor
 */

/* global wpsdData */

( function() {
	'use strict';

	var WPSDRepair = {

		selectedActions: [],
		currentIndex: 0,
		isRunning: false,
		completedCount: 0,
		failedCount: 0,

		/**
		 * Initialize: bind form submit and confirmation checkbox.
		 */
		init: function() {
			var form = document.getElementById( 'wpsd-repair-form' );
			if ( ! form ) {
				return;
			}

			form.addEventListener( 'submit', function( e ) {
				e.preventDefault();
				WPSDRepair.start();
			} );
		},

		/**
		 * Start the repair process.
		 */
		start: function() {
			if ( this.isRunning ) {
				return;
			}

			// Gather checked actions.
			var checkboxes = document.querySelectorAll( 'input[name="repair_actions[]"]:checked' );
			this.selectedActions = [];

			checkboxes.forEach( function( cb ) {
				WPSDRepair.selectedActions.push( {
					id: cb.value,
					label: cb.closest( '.wpsd-repair-item' ).querySelector( '.wpsd-repair-desc' ).textContent.trim()
				} );
			} );

			if ( this.selectedActions.length === 0 ) {
				return;
			}

			// Final confirmation.
			var confirmMsg = wpsdData.i18n.confirmRepair || 'Are you sure you want to run the selected repairs?';
			confirmMsg += '\n\n' + this.selectedActions.length + ' action(s) selected:\n';
			this.selectedActions.forEach( function( a ) {
				confirmMsg += '- ' + a.label + '\n';
			} );

			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}

			this.isRunning = true;
			this.currentIndex = 0;
			this.completedCount = 0;
			this.failedCount = 0;

			this.showProgress();
			this.disableForm();
			this.runNext();
		},

		/**
		 * Run the next repair action in the queue.
		 */
		runNext: function() {
			if ( ! this.isRunning || this.currentIndex >= this.selectedActions.length ) {
				this.finalize();
				return;
			}

			var action = this.selectedActions[ this.currentIndex ];
			var progress = Math.round( ( this.currentIndex / this.selectedActions.length ) * 100 );

			this.updateProgress(
				progress,
				( wpsdData.i18n.repairing || 'Repairing' ) + ': ' + action.label + '...'
			);

			var self = this;

			this.ajaxPost( 'wpsd_run_repair', {
				action_id: action.id,
				session_id: ''
			} )
				.then( function( response ) {
					if ( response.success ) {
						self.completedCount++;
						self.appendResult( action, true, response.data.message );
					} else {
						self.failedCount++;
						self.appendResult( action, false, response.data.message );
					}

					self.currentIndex++;
					self.runNext();
				} )
				.catch( function( error ) {
					self.failedCount++;
					self.appendResult( action, false, error.message || 'Request failed.' );
					self.currentIndex++;
					self.runNext();
				} );
		},

		/**
		 * Finalize repairs: show summary.
		 */
		finalize: function() {
			this.isRunning = false;

			var total = this.selectedActions.length;
			var statusText = ( wpsdData.i18n.repairComplete || 'Repair Complete' ) +
				': ' + this.completedCount + '/' + total + ' succeeded';

			if ( this.failedCount > 0 ) {
				statusText += ', ' + this.failedCount + ' failed';
			}

			this.updateProgress( 100, statusText );

			// Show "Run new scan" suggestion.
			var container = document.getElementById( 'wpsd-repair-results' );
			if ( container ) {
				var note = document.createElement( 'div' );
				note.className = 'notice notice-info inline';
				note.style.marginTop = '15px';
				note.innerHTML = '<p><strong>' +
					'Run a new scan to see the updated health score.' +
					'</strong> <a href="' + ( wpsdData.dashboardUrl || 'admin.php?page=wp-site-doctor' ) +
					'" class="button">' + 'Go to Dashboard' + '</a></p>';
				container.appendChild( note );
			}
		},

		/**
		 * Append a result row to the results area.
		 *
		 * @param {Object}  action  Action info { id, label }.
		 * @param {boolean} success Whether the action succeeded.
		 * @param {string}  message Result message.
		 */
		appendResult: function( action, success, message ) {
			var container = document.getElementById( 'wpsd-repair-results' );
			if ( ! container ) {
				// Create results container.
				var progress = document.getElementById( 'wpsd-repair-progress' );
				if ( progress ) {
					container = document.createElement( 'div' );
					container.id = 'wpsd-repair-results';
					container.style.marginTop = '15px';
					progress.parentNode.insertBefore( container, progress.nextSibling );
				} else {
					return;
				}
			}

			var row = document.createElement( 'div' );
			row.className = 'wpsd-issue wpsd-issue-' + ( success ? 'pass' : 'warning' );
			row.style.marginBottom = '8px';

			var badge = success ? 'pass' : 'warning';
			var badgeText = success ? 'Done' : 'Failed';

			row.innerHTML = '<div class="wpsd-issue-header">' +
				'<span class="wpsd-severity-badge wpsd-severity-' + badge + '">' + badgeText + '</span>' +
				'<strong class="wpsd-issue-title">' + this.escapeHtml( action.label ) + '</strong>' +
				'</div>' +
				( message ? '<p class="wpsd-issue-recommendation">' + this.escapeHtml( message ) + '</p>' : '' );

			container.appendChild( row );
		},

		/**
		 * Send an AJAX POST request.
		 *
		 * @param {string} action AJAX action name.
		 * @param {Object} data   Additional POST data.
		 * @return {Promise}
		 */
		ajaxPost: function( action, data ) {
			var formData = new FormData();
			formData.append( 'action', action );
			formData.append( 'nonce', wpsdData.nonce );

			for ( var key in data ) {
				if ( data.hasOwnProperty( key ) ) {
					formData.append( key, data[ key ] );
				}
			}

			return fetch( wpsdData.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin'
			} ).then( function( response ) {
				return response.json();
			} );
		},

		/**
		 * Update the progress bar.
		 *
		 * @param {number} percent 0-100.
		 * @param {string} status  Status text.
		 */
		updateProgress: function( percent, status ) {
			var fill = document.getElementById( 'wpsd-repair-progress-fill' );
			var statusEl = document.getElementById( 'wpsd-repair-status' );

			if ( fill ) {
				fill.style.width = percent + '%';
			}
			if ( statusEl ) {
				statusEl.textContent = status;
			}
		},

		/**
		 * Show the progress area.
		 */
		showProgress: function() {
			var el = document.getElementById( 'wpsd-repair-progress' );
			if ( el ) {
				el.style.display = 'block';
			}
		},

		/**
		 * Disable the form during repair.
		 */
		disableForm: function() {
			var btn = document.getElementById( 'wpsd-run-repairs' );
			if ( btn ) {
				btn.disabled = true;
			}

			document.querySelectorAll( '#wpsd-repair-form input[type="checkbox"]' ).forEach( function( cb ) {
				cb.disabled = true;
			} );
		},

		/**
		 * Escape HTML entities.
		 *
		 * @param {string} text Raw text.
		 * @return {string} Escaped text.
		 */
		escapeHtml: function( text ) {
			var div = document.createElement( 'div' );
			div.appendChild( document.createTextNode( text ) );
			return div.innerHTML;
		}
	};

	// Initialize on DOM ready.
	document.addEventListener( 'DOMContentLoaded', function() {
		WPSDRepair.init();
	} );

	window.WPSDRepair = WPSDRepair;

} )();
