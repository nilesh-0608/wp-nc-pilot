/**
 * NC-Pilot setup page.
 *
 * Builds the Claude Code command and Claude Desktop JSON from the username and
 * the Application Password the user created on their profile screen. Runs
 * entirely in the browser: the password is never sent to the server.
 */
( function () {
	'use strict';

	var settings = window.ncpilotSetup || {};

	function base64Utf8( text ) {
		var bytes = new TextEncoder().encode( text );
		var binary = '';
		bytes.forEach( function ( byte ) {
			binary += String.fromCharCode( byte );
		} );
		return window.btoa( binary );
	}

	// Single-quote a value for safe pasting into a POSIX shell.
	function shellArgument( value ) {
		return "'" + String( value ).replace( /'/g, "'\\''" ) + "'";
	}

	function buildClaudeCodeCommand( mcpUrl, token ) {
		return 'claude mcp add --transport http nc-pilot \\\n' +
			'  ' + shellArgument( mcpUrl ) + ' \\\n' +
			'  --header ' + shellArgument( 'Authorization: Basic ' + token );
	}

	function buildDesktopJson( mcpUrl, token ) {
		var config = {
			mcpServers: {
				'nc-pilot': {
					type: 'http',
					url: mcpUrl,
					headers: { Authorization: 'Basic ' + token }
				}
			}
		};
		return JSON.stringify( config, null, 4 );
	}

	function showError( errorBox, message ) {
		errorBox.querySelector( 'p' ).textContent = message;
		errorBox.hidden = false;
	}

	function handleSubmit( event ) {
		event.preventDefault();

		var username = document.getElementById( 'ncpilot-username' ).value.trim();
		// WordPress ignores the spaces in application passwords, so send the
		// compact form.
		var password = document.getElementById( 'ncpilot-app-password' ).value.replace( /\s+/g, '' );
		var errorBox = document.getElementById( 'ncpilot-setup-error' );
		var output = document.getElementById( 'ncpilot-setup-output' );

		if ( ! username || ! password ) {
			output.hidden = true;
			showError( errorBox, settings.missingFields );
			return;
		}
		errorBox.hidden = true;

		var token = base64Utf8( username + ':' + password );
		document.getElementById( 'ncpilot-cc' ).value = buildClaudeCodeCommand( settings.mcpUrl, token );
		document.getElementById( 'ncpilot-desktop' ).value = buildDesktopJson( settings.mcpUrl, token );
		output.hidden = false;
	}

	function copyFromTarget( button ) {
		var source = document.getElementById( button.getAttribute( 'data-target' ) );
		if ( ! source ) {
			return;
		}
		var originalLabel = button.textContent;
		var markCopied = function () {
			button.textContent = settings.copied;
			window.setTimeout( function () {
				button.textContent = originalLabel;
			}, 1500 );
		};

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( source.value ).then( markCopied );
			return;
		}
		source.select();
		document.execCommand( 'copy' );
		markCopied();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.getElementById( 'ncpilot-setup-form' );
		if ( form ) {
			form.addEventListener( 'submit', handleSubmit );
		}
		document.querySelectorAll( '.ncpilot-copy' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				copyFromTarget( button );
			} );
		} );
	} );
}() );
