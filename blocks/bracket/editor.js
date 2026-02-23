/**
 * BBB Tournament Bracket – Gutenberg Block (Editor)
 *
 * Dynamischer Block: Serverseitig gerendert via PHP.
 * Editor zeigt Sidebar-Controls + Live-Preview via ServerSideRender.
 *
 * Liga-Auswahl: Lädt gesyncte Ligen via REST API (bbb/v1/leagues).
 * Fallback: Manuelle Liga-ID Eingabe für nicht-gesyncte Wettbewerbe.
 *
 * Keine Build-Toolchain nötig – nutzt WP-Globals direkt.
 */
( function () {
    'use strict';

    var registerBlockType    = wp.blocks.registerBlockType;
    var el                   = wp.element.createElement;
    var useState             = wp.element.useState;
    var useEffect            = wp.element.useEffect;
    var useBlockProps         = wp.blockEditor.useBlockProps;
    var InspectorControls    = wp.blockEditor.InspectorControls;
    var PanelBody            = wp.components.PanelBody;
    var TextControl          = wp.components.TextControl;
    var SelectControl        = wp.components.SelectControl;
    var ToggleControl        = wp.components.ToggleControl;
    var RangeControl         = wp.components.RangeControl;
    var ComboboxControl      = wp.components.ComboboxControl;
    var Placeholder          = wp.components.Placeholder;
    var Spinner              = wp.components.Spinner;
    var Notice               = wp.components.Notice;
    var Button               = wp.components.Button;
    var ServerSideRender     = wp.serverSideRender;
    var __                   = wp.i18n.__;
    var apiFetch             = wp.apiFetch;

    // ─────────────────────────────────────────
    // Block-Icon (Bracket-Struktur SVG)
    // ─────────────────────────────────────────
    var blockIcon = el( 'svg', {
        xmlns: 'http://www.w3.org/2000/svg',
        viewBox: '0 0 24 24',
        width: 24,
        height: 24,
    },
        el( 'path', {
            d: 'M2 4h6v4H2V4zm0 6h6v4H2v-4zm0 6h6v4H2v-4zm8-8h6v4h-6V8zm0 6h6v4h-6v-4zm8-2h6v4h-6v-4z',
            fill: 'currentColor',
        })
    );

    // ─────────────────────────────────────────
    // Liga-Selector Komponente
    // ─────────────────────────────────────────
    function LigaSelector( props ) {
        var ligaId       = props.ligaId;
        var onChange      = props.onChange;
        var leagues       = props.leagues;
        var isLoading     = props.isLoading;
        var showManual    = props.showManual;
        var setShowManual = props.setShowManual;

        var options = leagues.map( function ( league ) {
            return {
                value: String( league.liga_id ),
                label: league.label,
            };
        });

        var currentValue = ligaId ? String( ligaId ) : '';
        var currentInList = leagues.some( function ( l ) {
            return l.liga_id === ligaId;
        });

        var currentLabel = '';
        if ( ligaId && currentInList ) {
            var found = leagues.find( function ( l ) { return l.liga_id === ligaId; } );
            if ( found ) currentLabel = found.label;
        }

        if ( isLoading ) {
            return el( 'div', { style: { display: 'flex', alignItems: 'center', gap: '8px' } },
                el( Spinner ),
                el( 'span', null, __( 'Ligen werden geladen…', 'bbb-live-tables' ) ),
            );
        }

        return el( wp.element.Fragment, null,

            leagues.length > 0 && ! showManual && el( ComboboxControl, {
                label: __( 'Liga / Turnier', 'bbb-live-tables' ),
                help: ligaId
                    ? ( currentInList
                        ? __( 'Liga-ID: ', 'bbb-live-tables' ) + ligaId
                        : __( 'Liga-ID ' + ligaId + ' ist nicht in der Liste – manuell gesetzt.', 'bbb-live-tables' ) )
                    : __( 'Wähle ein Turnier oder eine Liga aus.', 'bbb-live-tables' ),
                value: currentValue,
                options: options,
                onChange: function ( val ) {
                    onChange( parseInt( val, 10 ) || 0 );
                },
                onFilterValueChange: function () {},
            }),

            ( showManual || leagues.length === 0 ) && el( TextControl, {
                label: __( 'Liga-ID (manuell)', 'bbb-live-tables' ),
                help: __( 'BBB Liga-ID des Turniers (z.B. 47976). Findest du in der URL auf basketball-bund.net.', 'bbb-live-tables' ),
                type: 'number',
                value: ligaId || '',
                onChange: function ( val ) {
                    onChange( parseInt( val, 10 ) || 0 );
                },
            }),

            leagues.length > 0 && el( Button, {
                variant: 'link',
                onClick: function () { setShowManual( ! showManual ); },
                style: { marginTop: '-8px', fontSize: '12px' },
            },
                showManual
                    ? __( '← Zurück zur Liga-Auswahl', 'bbb-live-tables' )
                    : __( 'Liga-ID manuell eingeben →', 'bbb-live-tables' ),
            ),

            ligaId && ! currentInList && ! showManual && leagues.length > 0 && el( Notice, {
                status: 'info',
                isDismissible: false,
                style: { marginTop: '8px' },
            },
                __( 'Die aktuelle Liga-ID ist nicht in den gesyncten Ligen. Das Bracket wird trotzdem direkt von der BBB-API geladen.', 'bbb-live-tables' ),
            ),
        );
    }

    // ─────────────────────────────────────────
    // Edit-Komponente
    // ─────────────────────────────────────────
    function BracketEdit( props ) {
        var attributes    = props.attributes;
        var setAttributes = props.setAttributes;

        var ligaId        = attributes.ligaId;
        var title         = attributes.title;
        var highlightClub = attributes.highlightClub;
        var cache         = attributes.cache;
        var showDates     = attributes.showDates;
        var showLogos     = attributes.showLogos;
        var mode          = attributes.mode;
        var bestOf        = attributes.bestOf;

        var blockProps = useBlockProps();

        // ── Liga-Liste laden ──
        var stateLeagues     = useState( [] );
        var leagues          = stateLeagues[0];
        var setLeagues       = stateLeagues[1];

        var stateLoading     = useState( true );
        var isLoadingLeagues = stateLoading[0];
        var setLoadingLeagues = stateLoading[1];

        var stateManual      = useState( false );
        var showManual       = stateManual[0];
        var setShowManual    = stateManual[1];

        useEffect( function () {
            apiFetch( { path: '/bbb/v1/leagues?type=tournament' } )
                .then( function ( data ) {
                    setLeagues( data || [] );
                    setLoadingLeagues( false );
                })
                .catch( function () {
                    setLeagues( [] );
                    setLoadingLeagues( false );
                });
        }, [] );

        // ── Sidebar Controls ──
        var inspectorControls = el( InspectorControls, null,

            el( PanelBody, {
                title: __( 'Datenquelle', 'bbb-live-tables' ),
                initialOpen: true,
            },
                el( LigaSelector, {
                    ligaId: ligaId,
                    onChange: function ( val ) { setAttributes( { ligaId: val } ); },
                    leagues: leagues,
                    isLoading: isLoadingLeagues,
                    showManual: showManual,
                    setShowManual: setShowManual,
                }),
                el( TextControl, {
                    label: __( 'Titel', 'bbb-live-tables' ),
                    help: __( 'Überschreibt den Liga-Namen aus der API. Leer = automatisch.', 'bbb-live-tables' ),
                    value: title,
                    onChange: function ( val ) {
                        setAttributes( { title: val } );
                    },
                }),
            ),

            el( PanelBody, {
                title: __( 'Turnier-Modus', 'bbb-live-tables' ),
                initialOpen: true,
            },
                el( SelectControl, {
                    label: __( 'Modus', 'bbb-live-tables' ),
                    value: mode,
                    options: [
                        { label: 'KO (Single Elimination)', value: 'ko' },
                        { label: 'Playoff (Best-of-N)', value: 'playoff' },
                    ],
                    onChange: function ( val ) {
                        var newAttrs = { mode: val };
                        if ( val === 'playoff' && bestOf <= 1 ) {
                            newAttrs.bestOf = 5;
                        }
                        if ( val === 'ko' ) {
                            newAttrs.bestOf = 1;
                        }
                        setAttributes( newAttrs );
                    },
                }),
                mode === 'playoff' && el( SelectControl, {
                    label: __( 'Best of', 'bbb-live-tables' ),
                    help: __( 'Anzahl Spiele pro Serie. Gewinner braucht Mehrheit.', 'bbb-live-tables' ),
                    value: String( bestOf ),
                    options: [
                        { label: 'Best of 3 (2 Siege)', value: '3' },
                        { label: 'Best of 5 (3 Siege)', value: '5' },
                        { label: 'Best of 7 (4 Siege)', value: '7' },
                    ],
                    onChange: function ( val ) {
                        setAttributes( { bestOf: parseInt( val, 10 ) } );
                    },
                }),
            ),

            el( PanelBody, {
                title: __( 'Darstellung', 'bbb-live-tables' ),
                initialOpen: false,
            },
                el( ToggleControl, {
                    label: __( 'Spieldaten anzeigen', 'bbb-live-tables' ),
                    checked: showDates,
                    onChange: function ( val ) {
                        setAttributes( { showDates: val } );
                    },
                }),
                el( ToggleControl, {
                    label: __( 'Team-Logos anzeigen', 'bbb-live-tables' ),
                    checked: showLogos,
                    onChange: function ( val ) {
                        setAttributes( { showLogos: val } );
                    },
                }),
            ),

            el( PanelBody, {
                title: __( 'Erweitert', 'bbb-live-tables' ),
                initialOpen: false,
            },
                el( TextControl, {
                    label: __( 'Highlight Club-ID', 'bbb-live-tables' ),
                    help: __( 'BBB Club-ID des eigenen Vereins. 0 = aus Plugin-Einstellungen.', 'bbb-live-tables' ),
                    type: 'number',
                    value: highlightClub || '',
                    onChange: function ( val ) {
                        setAttributes( { highlightClub: parseInt( val, 10 ) || 0 } );
                    },
                }),
                el( RangeControl, {
                    label: __( 'Cache-Dauer (Sekunden)', 'bbb-live-tables' ),
                    help: __( 'Wie lange API-Daten gecached werden. 0 = kein Cache.', 'bbb-live-tables' ),
                    value: cache,
                    min: 0,
                    max: 86400,
                    step: 300,
                    onChange: function ( val ) {
                        setAttributes( { cache: val } );
                    },
                }),
            ),
        );

        // ── Hauptbereich ──
        var content;

        if ( ! ligaId ) {
            content = el( Placeholder, {
                icon: blockIcon,
                label: __( 'Turnier-Bracket', 'bbb-live-tables' ),
                instructions: leagues.length > 0
                    ? __( 'Wähle ein Turnier aus der Liste oder gib eine Liga-ID manuell ein.', 'bbb-live-tables' )
                    : __( 'Gib eine BBB Liga-ID ein um das Bracket anzuzeigen.', 'bbb-live-tables' ),
            },
                el( 'div', { style: { width: '100%', maxWidth: '400px' } },
                    el( LigaSelector, {
                        ligaId: ligaId,
                        onChange: function ( val ) { setAttributes( { ligaId: val } ); },
                        leagues: leagues,
                        isLoading: isLoadingLeagues,
                        showManual: showManual,
                        setShowManual: setShowManual,
                    }),
                ),
            );
        } else {
            content = el( ServerSideRender, {
                block: 'bbb/tournament-bracket',
                attributes: attributes,
                LoadingResponsePlaceholder: function () {
                    return el( Placeholder, {
                        icon: blockIcon,
                        label: __( 'Turnier-Bracket', 'bbb-live-tables' ),
                    },
                        el( Spinner ),
                        el( 'p', null, __( 'Lade Bracket-Vorschau…', 'bbb-live-tables' ) ),
                    );
                },
                ErrorResponsePlaceholder: function () {
                    return el( Placeholder, {
                        icon: blockIcon,
                        label: __( 'Turnier-Bracket', 'bbb-live-tables' ),
                    },
                        el( Notice, {
                            status: 'error',
                            isDismissible: false,
                        },
                            __( 'Fehler beim Laden der Vorschau. Prüfe die Liga-ID.', 'bbb-live-tables' ),
                        ),
                    );
                },
            });
        }

        return el( 'div', blockProps,
            inspectorControls,
            content,
        );
    }

    // ─────────────────────────────────────────
    // Block registrieren
    // ─────────────────────────────────────────
    registerBlockType( 'bbb/tournament-bracket', {
        edit: BracketEdit,
        save: function () {
            return null;
        },
    });

} )();
