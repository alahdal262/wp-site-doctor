/**
 * WP Site Doctor - Scan History Chart
 *
 * Lightweight SVG-based chart for displaying health score trends
 * over the last 10 scans. No external dependencies.
 *
 * @package WPSiteDoctor
 */

( function() {
	'use strict';

	var WPSDChart = {

		/**
		 * Render a line chart in the given container.
		 *
		 * @param {string} containerId DOM element ID.
		 * @param {Array}  data        Array of { date: string, score: number }.
		 */
		render: function( containerId, data ) {
			var container = document.getElementById( containerId );
			if ( ! container || ! data || data.length < 2 ) {
				return;
			}

			var width = container.offsetWidth || 560;
			var height = 200;
			var padding = { top: 20, right: 20, bottom: 30, left: 40 };
			var chartW = width - padding.left - padding.right;
			var chartH = height - padding.top - padding.bottom;

			// Build SVG.
			var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + width + ' ' + height + '" style="width:100%; height:auto;">';

			// Y-axis grid lines and labels.
			var ySteps = [ 0, 25, 50, 75, 100 ];
			ySteps.forEach( function( val ) {
				var y = padding.top + chartH - ( val / 100 ) * chartH;
				svg += '<line x1="' + padding.left + '" y1="' + y + '" x2="' + ( width - padding.right ) + '" y2="' + y + '" stroke="#f0f0f1" stroke-width="1" />';
				svg += '<text x="' + ( padding.left - 5 ) + '" y="' + ( y + 4 ) + '" text-anchor="end" font-size="11" fill="#646970">' + val + '</text>';
			} );

			// Plot points and line.
			var points = [];
			var len = data.length;

			data.forEach( function( item, i ) {
				var x = padding.left + ( i / ( len - 1 ) ) * chartW;
				var y = padding.top + chartH - ( item.score / 100 ) * chartH;
				points.push( { x: x, y: y, score: item.score, date: item.date } );
			} );

			// Line path.
			var pathD = 'M';
			points.forEach( function( p, i ) {
				pathD += ( i === 0 ? '' : 'L' ) + p.x.toFixed( 1 ) + ',' + p.y.toFixed( 1 );
			} );

			svg += '<path d="' + pathD + '" fill="none" stroke="#2271b1" stroke-width="2" stroke-linejoin="round" />';

			// Gradient area fill.
			var areaD = pathD + 'L' + points[ points.length - 1 ].x.toFixed( 1 ) + ',' + ( padding.top + chartH );
			areaD += 'L' + points[ 0 ].x.toFixed( 1 ) + ',' + ( padding.top + chartH ) + 'Z';

			svg += '<defs><linearGradient id="wpsd-area-grad" x1="0" y1="0" x2="0" y2="1">';
			svg += '<stop offset="0%" stop-color="#2271b1" stop-opacity="0.2" />';
			svg += '<stop offset="100%" stop-color="#2271b1" stop-opacity="0.02" />';
			svg += '</linearGradient></defs>';
			svg += '<path d="' + areaD + '" fill="url(#wpsd-area-grad)" />';

			// Data points.
			points.forEach( function( p ) {
				var color = WPSDChart.getColor( p.score );
				svg += '<circle cx="' + p.x.toFixed( 1 ) + '" cy="' + p.y.toFixed( 1 ) + '" r="4" fill="' + color + '" stroke="#fff" stroke-width="2" />';
				svg += '<title>' + p.date + ': ' + p.score + '</title>';
			} );

			// X-axis labels (show first, middle, last).
			var labelIndices = [ 0, Math.floor( len / 2 ), len - 1 ];
			if ( len <= 3 ) {
				labelIndices = [];
				for ( var li = 0; li < len; li++ ) {
					labelIndices.push( li );
				}
			}

			labelIndices.forEach( function( idx ) {
				if ( points[ idx ] ) {
					svg += '<text x="' + points[ idx ].x.toFixed( 1 ) + '" y="' + ( height - 5 ) + '" text-anchor="middle" font-size="10" fill="#646970">';
					svg += WPSDChart.escapeHtml( points[ idx ].date ) + '</text>';
				}
			} );

			svg += '</svg>';

			container.innerHTML = svg;
		},

		/**
		 * Get color for a score value.
		 *
		 * @param {number} score 0-100.
		 * @return {string} Hex color.
		 */
		getColor: function( score ) {
			if ( score >= 90 ) {
				return '#00a32a';
			} else if ( score >= 70 ) {
				return '#2271b1';
			} else if ( score >= 50 ) {
				return '#dba617';
			}
			return '#d63638';
		},

		/**
		 * Escape HTML.
		 *
		 * @param {string} text Input.
		 * @return {string} Escaped text.
		 */
		escapeHtml: function( text ) {
			return text.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
		}
	};

	window.WPSDChart = WPSDChart;

} )();
