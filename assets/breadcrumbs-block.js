/**
 * TGD Site Kit breadcrumbs block, editor side. Plain script, no build step:
 * the trail is rendered on the server (ServerSideRender), so the editor shows
 * exactly what the page will.
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'tgd-site-kit/breadcrumbs', {
		apiVersion: 3,
		title: __( 'Breadcrumbs (TGD Site Kit)', 'tgd-site-kit' ),
		category: 'theme',
		icon: 'arrow-right-alt2',
		attributes: {
			separator: { type: 'string', default: '' },
			current: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			var set = function ( key ) {
				return function ( value ) {
					var next = {};
					next[ key ] = value;
					props.setAttributes( next );
				};
			};
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Trail', 'tgd-site-kit' ) },
						el( TextControl, { label: __( 'Separator (empty uses the site setting)', 'tgd-site-kit' ), value: a.separator, onChange: set( 'separator' ) } ),
						el( SelectControl, {
							label: __( 'Current page', 'tgd-site-kit' ),
							value: a.current,
							options: [
								{ label: __( 'Site setting', 'tgd-site-kit' ), value: '' },
								{ label: __( 'Show', 'tgd-site-kit' ), value: 'yes' },
								{ label: __( 'Hide', 'tgd-site-kit' ), value: 'no' }
							],
							onChange: set( 'current' )
						} )
					)
				),
				el( ServerSideRender, { block: 'tgd-site-kit/breadcrumbs', attributes: a } )
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );
