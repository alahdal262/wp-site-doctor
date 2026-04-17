/**
 * WP Site Doctor - Health Gauge Renderer
 *
 * Renders and animates the circular health score gauge.
 * Supports conic-gradient (modern) and SVG fallback.
 *
 * @package WPSiteDoctor
 */

/* global wpsdData */

( function() {
	'use strict';

	var WPSDGauge = {

		/**
		 * Initialize the gauge with the given score.
		 *
		 * On page load we set the value synchronously (no animation) so the gauge
		 * renders instantly and correctly even if requestAnimationFrame is throttled.
		 * Animation is only used on scan completion from a fresh state.
		 *
		 * @param {number|null} score Score 0-100 or null for no data.
		 */
		init: function( score ) {
			var gauge = document.querySelector( '.wpsd-gauge' );
			if ( ! gauge ) {
				return;
			}

			if ( score === null || score === undefined ) {
				return;
			}

			this.setScore( score, false );
		},

		/**
		 * Set the gauge to a specific score with optional animation.
		 *
		 * @param {number}  score   Score 0-100.
		 * @param {boolean} animate Whether to animate the transition.
		 */
		setScore: function( score, animate ) {
			var gauge = document.querySelector( '.wpsd-gauge' );
			if ( ! gauge ) {
				return;
			}

			score = Math.max( 0, Math.min( 100, parseInt( score, 10 ) || 0 ) );

			var gradeClass = this.getGradeClass( score );
			var angle = ( score / 100 ) * 360;

			// Set data attribute for CSS styling.
			gauge.setAttribute( 'data-grade', gradeClass );
			gauge.setAttribute( 'data-score', score );

			// Update conic-gradient gauge. Always set the final value synchronously
			// so the correct state is visible even if requestAnimationFrame is throttled
			// (e.g. in a backgrounded tab). Animation is a progressive enhancement.
			var circle = gauge.querySelector( '.wpsd-gauge-circle' );
			if ( circle ) {
				circle.style.setProperty( '--wpsd-gauge-angle', angle + 'deg' );
				if ( animate ) {
					this.animateAngle( circle, 0, angle, 1000 );
				}
			}

			// Update SVG fallback gauge.
			var svgArc = gauge.querySelector( '.wpsd-gauge-svg-arc' );
			if ( svgArc ) {
				var circumference = 2 * Math.PI * 90; // r=90
				var offset = circumference - ( score / 100 ) * circumference;

				if ( animate ) {
					svgArc.style.transition = 'stroke-dashoffset 1s ease-out';
				}
				svgArc.style.strokeDasharray = circumference;
				svgArc.style.strokeDashoffset = offset;
			}

			// Update score text. Always set the final value synchronously.
			var scoreEl = gauge.querySelector( '.wpsd-gauge-score' );
			if ( scoreEl ) {
				scoreEl.textContent = score;
				if ( animate ) {
					this.animateNumber( scoreEl, 0, score, 1000 );
				}
			}

			// Update label.
			var labelEl = gauge.querySelector( '.wpsd-gauge-label' );
			if ( labelEl ) {
				labelEl.textContent = this.getGradeLabel( score );
			}
		},

		/**
		 * Animate the conic-gradient angle from start to end.
		 *
		 * @param {Element} element   The gauge circle element.
		 * @param {number}  start     Start angle in degrees.
		 * @param {number}  end       End angle in degrees.
		 * @param {number}  duration  Animation duration in ms.
		 */
		animateAngle: function( element, start, end, duration ) {
			var startTime = null;

			function step( timestamp ) {
				if ( ! startTime ) {
					startTime = timestamp;
				}

				var progress = Math.min( ( timestamp - startTime ) / duration, 1 );
				var eased = 1 - Math.pow( 1 - progress, 3 ); // Ease-out cubic.
				var current = start + ( end - start ) * eased;

				element.style.setProperty( '--wpsd-gauge-angle', current + 'deg' );

				if ( progress < 1 ) {
					window.requestAnimationFrame( step );
				}
			}

			window.requestAnimationFrame( step );
		},

		/**
		 * Animate a number counting up.
		 *
		 * @param {Element} element  The element to update.
		 * @param {number}  start    Start number.
		 * @param {number}  end      End number.
		 * @param {number}  duration Animation duration in ms.
		 */
		animateNumber: function( element, start, end, duration ) {
			var startTime = null;

			function step( timestamp ) {
				if ( ! startTime ) {
					startTime = timestamp;
				}

				var progress = Math.min( ( timestamp - startTime ) / duration, 1 );
				var eased = 1 - Math.pow( 1 - progress, 3 );
				var current = Math.round( start + ( end - start ) * eased );

				element.textContent = current;

				if ( progress < 1 ) {
					window.requestAnimationFrame( step );
				} else {
					element.classList.add( 'wpsd-animate' );
					setTimeout( function() {
						element.classList.remove( 'wpsd-animate' );
					}, 300 );
				}
			}

			window.requestAnimationFrame( step );
		},

		/**
		 * Set the gauge to scanning state.
		 */
		setScanning: function() {
			var gauge = document.querySelector( '.wpsd-gauge' );
			if ( gauge ) {
				gauge.classList.add( 'wpsd-scanning' );
				var scoreEl = gauge.querySelector( '.wpsd-gauge-score' );
				if ( scoreEl && typeof wpsdData !== 'undefined' ) {
					scoreEl.textContent = wpsdData.i18n.scanning || 'Scanning...';
				}
			}
		},

		/**
		 * Remove scanning state.
		 */
		clearScanning: function() {
			var gauge = document.querySelector( '.wpsd-gauge' );
			if ( gauge ) {
				gauge.classList.remove( 'wpsd-scanning' );
			}
		},

		/**
		 * Get the grade CSS class for a score.
		 *
		 * @param {number} score Score 0-100.
		 * @return {string} Grade class name.
		 */
		getGradeClass: function( score ) {
			if ( score >= 90 ) {
				return 'excellent';
			} else if ( score >= 70 ) {
				return 'good';
			} else if ( score >= 50 ) {
				return 'warning';
			}
			return 'critical';
		},

		/**
		 * Get the human-readable grade label.
		 *
		 * @param {number} score Score 0-100.
		 * @return {string} Grade label.
		 */
		getGradeLabel: function( score ) {
			if ( score >= 90 ) {
				return 'Excellent';
			} else if ( score >= 70 ) {
				return 'Good';
			} else if ( score >= 50 ) {
				return 'Needs Attention';
			}
			return 'Critical';
		}
	};

	// Expose globally for other scripts.
	window.WPSDGauge = WPSDGauge;

} )();
