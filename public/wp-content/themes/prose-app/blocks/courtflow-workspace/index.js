/* CourtFlow Workspace block — editor registration (no build step). */
( function ( wp ) {
	if ( ! wp || ! wp.blocks ) {
		return;
	}
	var el = wp.element.createElement;
	var useBlockProps = wp.blockEditor && wp.blockEditor.useBlockProps;

	wp.blocks.registerBlockType( 'courtflow/workspace', {
		edit: function () {
			var blockProps = useBlockProps
				? useBlockProps( { className: 'courtflow-workspace-editor' } )
				: { className: 'courtflow-workspace-editor' };
			return el(
				'div',
				blockProps,
				el( 'p', null, el( 'strong', null, 'CourtFlow Workspace' ) ),
				el( 'p', { style: { color: '#666' } }, 'Three-pane guided procedural workspace will render on the front end.' )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
