/**
 * Botão "Anúncio" na aba Texto do editor clássico.
 *
 * O botão só existe onde a API de quicktags existe — ou seja, no editor
 * clássico. No editor de blocos, use o bloco de shortcode.
 */
( function () {
	'use strict';

	if ( 'undefined' === typeof window.QTags || 'undefined' === typeof window.ebrAdsQuicktags ) {
		return;
	}

	var config = window.ebrAdsQuicktags;

	QTags.addButton( 'ebr_ad', config.button, function () {
		if ( ! config.ads || ! config.ads.length ) {
			window.alert( config.none );
			return;
		}

		var options = config.ads.map( function ( ad ) {
			return ad.id + ' = ' + ad.label;
		} ).join( '\n' );

		var answer = window.prompt( config.prompt + '\n\n' + options, String( config.ads[0].id ) );
		if ( null === answer ) {
			return;
		}

		var id = parseInt( answer, 10 );

		// Só aceita um id que corresponda a um anúncio realmente configurado.
		var valid = config.ads.some( function ( ad ) {
			return ad.id === id;
		} );

		if ( ! valid ) {
			return;
		}

		QTags.insertContent( '[ebr_ad id="' + id + '"]' );
	}, null, config.title );
}() );
