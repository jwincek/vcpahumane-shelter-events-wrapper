/**
 * Editor script for the Shelter Event List block.
 *
 * Provides Inspector Controls so editors can pick a program, count,
 * layout, and toggle venue/cost display right in the block sidebar.
 */

/* global wp */
const { registerBlockType } = wp.blocks;
const { InspectorControls, useBlockProps } = wp.blockEditor;
const { PanelBody, SelectControl, RangeControl, ToggleControl, Placeholder, Spinner } = wp.components;
const { useEffect, useState } = wp.element;
const { __ } = wp.i18n;
const apiFetch = wp.apiFetch;

registerBlockType( 'shelter-events/event-list', {
	edit( { attributes, setAttributes } ) {
		const { program, count, showCost, showVenue, layout } = attributes;
		const blockProps = useBlockProps();
		const [ programs, setPrograms ] = useState( [] );
		const [ loading, setLoading ] = useState( true );

		// Fetch available programs from our REST endpoint.
		useEffect( () => {
			apiFetch( { path: '/shelter-events/v1/programs' } )
				.then( ( data ) => {
					setPrograms( data || [] );
					setLoading( false );
				} )
				.catch( () => setLoading( false ) );
		}, [] );

		const programOptions = [
			{ label: __( '— All Programs —', 'shelter-events' ), value: '' },
			...programs.map( ( p ) => ( {
				label: `${ p.title } (${ p.days.map( d => d.charAt(0).toUpperCase() + d.slice(1) ).join( ', ' ) })`,
				value: p.slug,
			} ) ),
		];

		return (
			<div { ...blockProps }>
				<InspectorControls>
					<PanelBody title={ __( 'Event Settings', 'shelter-events' ) }>
						{ loading ? (
							<Spinner />
						) : (
							<SelectControl
								label={ __( 'Program', 'shelter-events' ) }
								value={ program }
								options={ programOptions }
								onChange={ ( val ) => setAttributes( { program: val } ) }
							/>
						) }

						<RangeControl
							label={ __( 'Number of Events', 'shelter-events' ) }
							value={ count }
							onChange={ ( val ) => setAttributes( { count: val } ) }
							min={ 1 }
							max={ 20 }
						/>

						<SelectControl
							label={ __( 'Layout', 'shelter-events' ) }
							value={ layout }
							options={ [
								{ label: __( 'List', 'shelter-events' ), value: 'list' },
								{ label: __( 'Card Grid', 'shelter-events' ), value: 'card' },
								{ label: __( 'Compact', 'shelter-events' ), value: 'compact' },
							] }
							onChange={ ( val ) => setAttributes( { layout: val } ) }
						/>
					</PanelBody>

					<PanelBody title={ __( 'Display Options', 'shelter-events' ) } initialOpen={ false }>
						<ToggleControl
							label={ __( 'Show Cost', 'shelter-events' ) }
							checked={ showCost }
							onChange={ ( val ) => setAttributes( { showCost: val } ) }
						/>
						<ToggleControl
							label={ __( 'Show Venue', 'shelter-events' ) }
							checked={ showVenue }
							onChange={ ( val ) => setAttributes( { showVenue: val } ) }
						/>
					</PanelBody>
				</InspectorControls>

				<Placeholder
					icon="calendar-alt"
					label={ __( 'Shelter Event List', 'shelter-events' ) }
					instructions={
						program
							? __( 'Showing upcoming events for: ', 'shelter-events' ) + program
							: __( 'Showing all upcoming shelter events.', 'shelter-events' )
					}
				>
					<p>{ __( 'This block renders on the front end.', 'shelter-events' ) }</p>
				</Placeholder>
			</div>
		);
	},

	save() {
		// Server-rendered block — no save output.
		return null;
	},
} );
