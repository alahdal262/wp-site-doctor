/**
 * WP Site Doctor - Scan Runner
 *
 * Manages sequential AJAX calls to run each scanner module.
 * Updates progress bar and results container in real-time.
 *
 * @package WPSiteDoctor
 */

/* global wpsdData, WPSDGauge */

( function() {
	'use strict';

	var WPSDScanner = {

		sessionId: null,
		scanners: [],
		currentIndex: 0,
		isRunning: false,
		abortController: null,

		/**
		 * Start a new full scan.
		 */
		start: function() {
			if ( this.isRunning ) {
				return;
			}

			this.isRunning = true;
			this.currentIndex = 0;
			this.abortController = new AbortController();

			// Update UI state.
			this.showProgress();
			this.clearResults();

			if ( window.WPSDGauge ) {
				WPSDGauge.setScanning();
			}

			var self = this;

			// Step 1: Start the scan session.
			this.ajaxPost( 'wpsd_start_scan', {} )
				.then( function( response ) {
					if ( response.success ) {
						self.sessionId = response.data.session_id;
						self.scanners = response.data.scanners;
						self.updateProgress( 0, wpsdData.i18n.scanning );
						self.runNext();
					} else {
						self.handleError( response.data.message || 'Failed to start scan.' );
					}
				} )
				.catch( function( error ) {
					self.handleError( error.message || 'Network error.' );
				} );
		},

		/**
		 * Run the next scanner in the queue.
		 */
		runNext: function() {
			if ( ! this.isRunning || this.currentIndex >= this.scanners.length ) {
				this.finalize();
				return;
			}

			var scanner = this.scanners[ this.currentIndex ];
			var self = this;
			var progress = Math.round( ( this.currentIndex / this.scanners.length ) * 100 );

			this.updateProgress(
				progress,
				wpsdData.i18n.running + ': ' + scanner.label + '...'
			);

			this.ajaxPost( 'wpsd_run_scanner', {
				scanner_id: scanner.id,
				session_id: this.sessionId
			} )
				.then( function( response ) {
					if ( response.success ) {
						self.appendResult( response.data );
					} else {
						// Scanner failed but we continue with the next one.
						self.appendError( scanner, response.data.message );
					}

					self.currentIndex++;
					self.runNext();
				} )
				.catch( function( error ) {
					if ( error.name === 'AbortError' ) {
						return; // Scan was cancelled.
					}
					self.appendError( scanner, error.message || 'Request failed.' );
					self.currentIndex++;
					self.runNext();
				} );
		},

		/**
		 * Finalize the scan: compute aggregate score.
		 */
		finalize: function() {
			var self = this;

			this.updateProgress( 95, wpsdData.i18n.scanning );

			this.ajaxPost( 'wpsd_finalize_scan', {
				session_id: this.sessionId
			} )
				.then( function( response ) {
					self.isRunning = false;

					if ( response.success ) {
						self.updateProgress( 100, wpsdData.i18n.scanComplete );

						if ( window.WPSDGauge ) {
							WPSDGauge.clearScanning();
							WPSDGauge.setScore( response.data.health_score, true );
						}

						self.updateSummary( response.data );

						// Hide progress after a delay.
						setTimeout( function() {
							self.hideProgress();
						}, 2000 );
					} else {
						self.handleError( response.data.message || 'Failed to finalize scan.' );
					}
				} )
				.catch( function( error ) {
					self.isRunning = false;
					self.handleError( error.message || 'Network error.' );
				} );
		},

		/**
		 * Cancel a running scan.
		 */
		cancel: function() {
			if ( this.abortController ) {
				this.abortController.abort();
			}
			this.isRunning = false;
			this.hideProgress();

			if ( window.WPSDGauge ) {
				WPSDGauge.clearScanning();
			}
		},

		/**
		 * Send an AJAX POST request.
		 *
		 * @param {string} action   WordPress AJAX action name.
		 * @param {Object} data     Additional data to send.
		 * @return {Promise} Fetch promise resolving to parsed JSON.
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
				credentials: 'same-origin',
				signal: this.abortController ? this.abortController.signal : undefined
			} ).then( function( response ) {
				if ( response.status === 403 ) {
					throw new Error( 'Session expired. Please reload the page.' );
				}
				return response.json();
			} );
		},

		/**
		 * Update the progress bar UI.
		 *
		 * @param {number} percent Percentage 0-100.
		 * @param {string} status  Status message text.
		 */
		updateProgress: function( percent, status ) {
			var fill = document.getElementById( 'wpsd-progress-fill' );
			var statusEl = document.getElementById( 'wpsd-progress-status' );
			var bar = document.querySelector( '.wpsd-progress-bar' );

			if ( fill ) {
				fill.style.width = percent + '%';
			}
			if ( bar ) {
				bar.setAttribute( 'aria-valuenow', percent );
			}
			if ( statusEl ) {
				statusEl.textContent = status;
			}
		},

		/**
		 * Show the progress container.
		 */
		showProgress: function() {
			var container = document.getElementById( 'wpsd-progress-container' );
			if ( container ) {
				container.style.display = 'block';
			}

			// Disable the scan button.
			var btn = document.getElementById( 'wpsd-run-scan' );
			if ( btn ) {
				btn.disabled = true;
			}
		},

		/**
		 * Hide the progress container.
		 */
		hideProgress: function() {
			var container = document.getElementById( 'wpsd-progress-container' );
			if ( container ) {
				container.style.display = 'none';
			}

			// Re-enable the scan button.
			var btn = document.getElementById( 'wpsd-run-scan' );
			if ( btn ) {
				btn.disabled = false;
			}
		},

		/**
		 * Clear the results container for a fresh scan.
		 */
		clearResults: function() {
			var container = document.getElementById( 'wpsd-scan-results' );
			if ( container ) {
				container.innerHTML = '';
			}
		},

		/**
		 * Append a successful scanner result to the results container.
		 *
		 * @param {Object} data Scanner result data.
		 */
		appendResult: function( data ) {
			var container = document.getElementById( 'wpsd-scan-results' );
			if ( ! container ) {
				return;
			}

			var card = document.createElement( 'div' );
			card.className = 'wpsd-card wpsd-results-card';

			var header = document.createElement( 'h3' );
			var label = data.category || data.scanner_id;
			label = label.replace( /_/g, ' ' );
			label = label.charAt( 0 ).toUpperCase() + label.slice( 1 );
			header.textContent = label + ' — Score: ' + data.score + '/100';
			if ( data.duration ) {
				header.textContent += ' (' + data.duration + 's)';
			}
			card.appendChild( header );

			if ( data.issues && data.issues.length > 0 ) {
				data.issues.forEach( function( issue ) {
					var issueEl = document.createElement( 'div' );
					issueEl.className = 'wpsd-issue wpsd-issue-' + ( issue.severity || 'info' );

					var issueHeader = document.createElement( 'div' );
					issueHeader.className = 'wpsd-issue-header';

					var badge = document.createElement( 'span' );
					badge.className = 'wpsd-severity-badge wpsd-severity-' + ( issue.severity || 'info' );
					badge.textContent = ( issue.severity || 'info' ).charAt( 0 ).toUpperCase() + ( issue.severity || 'info' ).slice( 1 );
					issueHeader.appendChild( badge );

					var title = document.createElement( 'strong' );
					title.className = 'wpsd-issue-title';
					title.textContent = issue.message || '';
					issueHeader.appendChild( title );

					issueEl.appendChild( issueHeader );

					if ( issue.recommendation ) {
						var rec = document.createElement( 'p' );
						rec.className = 'wpsd-issue-recommendation';
						rec.textContent = issue.recommendation;
						issueEl.appendChild( rec );
					}

					if ( issue.repair_action ) {
						var btn = document.createElement( 'button' );
						btn.type = 'button';
						btn.className = 'button wpsd-fix-btn';
						btn.setAttribute( 'data-action-id', issue.repair_action.action_id );
						btn.setAttribute( 'data-action-label', issue.repair_action.label );
						btn.innerHTML = '<span class="dashicons dashicons-admin-tools"></span> Fix Now';
						issueEl.appendChild( btn );
					}

					card.appendChild( issueEl );
				} );
			} else {
				var noIssues = document.createElement( 'p' );
				noIssues.className = 'wpsd-no-issues';
				noIssues.innerHTML = '<span class="dashicons dashicons-yes-alt"></span> No issues found.';
				card.appendChild( noIssues );
			}

			container.appendChild( card );
		},

		/**
		 * Append an error card for a failed scanner.
		 *
		 * @param {Object} scanner Scanner info object.
		 * @param {string} message Error message.
		 */
		appendError: function( scanner, message ) {
			var container = document.getElementById( 'wpsd-scan-results' );
			if ( ! container ) {
				return;
			}

			var card = document.createElement( 'div' );
			card.className = 'wpsd-card';

			var issue = document.createElement( 'div' );
			issue.className = 'wpsd-issue wpsd-issue-warning';
			issue.innerHTML = '<div class="wpsd-issue-header">' +
				'<span class="wpsd-severity-badge wpsd-severity-warning">Skipped</span>' +
				'<strong class="wpsd-issue-title">' + scanner.label + ': ' + message + '</strong>' +
				'</div>';

			card.appendChild( issue );
			container.appendChild( card );
		},

		/**
		 * Update summary stats after finalization.
		 *
		 * @param {Object} data Finalization response data.
		 */
		updateSummary: function( data ) {
			// The page will be reloaded or stats updated dynamically
			// based on the response. For now, the scan results are
			// already displayed inline.
		},

		/**
		 * Handle a fatal scan error.
		 *
		 * @param {string} message Error message.
		 */
		handleError: function( message ) {
			this.isRunning = false;
			this.updateProgress( 0, wpsdData.i18n.scanFailed + ': ' + message );

			if ( window.WPSDGauge ) {
				WPSDGauge.clearScanning();
			}

			// Re-enable the scan button.
			var btn = document.getElementById( 'wpsd-run-scan' );
			if ( btn ) {
				btn.disabled = false;
			}
		}
	};

	// Expose globally.
	window.WPSDScanner = WPSDScanner;

} )();
