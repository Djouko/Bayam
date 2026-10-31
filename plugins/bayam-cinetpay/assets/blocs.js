// « Mobile Money » (CinetPay) dans la commande en blocs : titre et explication, le paiement se fait chez CinetPay.
( function () {
	const reglages = window.wc.wcSettings.getSetting( 'bayam_cinetpay_data', {} );
	const decoder = window.wp.htmlEntities.decodeEntities;
	const titre = decoder( reglages.title || 'Mobile Money' );
	const Explication = () => window.wp.element.createElement( 'p', null, decoder( reglages.description || '' ) );
	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'bayam_cinetpay',
		label: titre,
		ariaLabel: titre,
		content: window.wp.element.createElement( Explication ),
		edit: window.wp.element.createElement( Explication ),
		canMakePayment: () => true,
		supports: { features: reglages.supports || [ 'products' ] },
	} );
} )();
