/**
 * Painel EBR Ads.
 *
 * Só conveniência de interface: habilita/desabilita o select de anúncio junto
 * com o checkbox "Assign", e mostra os campos do tipo de anúncio escolhido.
 *
 * Nada aqui é controle de segurança — um select desabilitado apenas não é
 * enviado, e o servidor revalida tudo pelo schema de qualquer forma.
 */
( function () {
	'use strict';

	function syncRow( checkbox ) {
		var row = checkbox.closest( 'tr' );
		if ( ! row ) {
			return;
		}

		var select = row.querySelector( '.ebr-ad-select' );
		if ( select ) {
			select.disabled = ! checkbox.checked;
		}

		var count = row.querySelector( '.ebr-count' );
		if ( count ) {
			count.disabled = ! checkbox.checked;
		}

		var className = row.querySelector( '.ebr-class-name' );
		if ( className ) {
			className.disabled = ! checkbox.checked;
		}
	}

	function syncType( select ) {
		var box = select.closest( '.ebr-ad-box' );
		if ( ! box ) {
			return;
		}

		var isImage = 'image' === select.value;

		box.querySelectorAll( '.ebr-row-code' ).forEach( function ( row ) {
			row.style.display = isImage ? 'none' : '';
		} );

		box.querySelectorAll( '.ebr-row-image' ).forEach( function ( row ) {
			row.style.display = isImage ? '' : 'none';
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.ebr-assign' ).forEach( function ( checkbox ) {
			syncRow( checkbox );
			checkbox.addEventListener( 'change', function () {
				syncRow( checkbox );
			} );
		} );

		document.querySelectorAll( '.ebr-type' ).forEach( function ( select ) {
			syncType( select );
			select.addEventListener( 'change', function () {
				syncType( select );
			} );
		} );
	} );
}() );
