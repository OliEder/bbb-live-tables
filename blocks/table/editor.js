/**
 * BBB League Table – Gutenberg Block (Editor) v1.3
 *
 * Features:
 * - Liga-Auswahl via REST API
 * - Getrennte Spaltenkonfiguration Desktop/Mobil
 * - Team-Anzeige-Modus (Name, Kurzname, Logo, etc.)
 * - Live-Preview via ServerSideRender
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
    var ToggleControl        = wp.components.ToggleControl;
    var RangeControl         = wp.components.RangeControl;
    var CheckboxControl      = wp.components.CheckboxControl;
    var SelectControl        = wp.components.SelectControl;
    var ComboboxControl      = wp.components.ComboboxControl;
    var Placeholder          = wp.components.Placeholder;
    var Spinner              = wp.components.Spinner;
    var Notice               = wp.components.Notice;
    var Button               = wp.components.Button;
    var ServerSideRender     = wp.serverSideRender;
    var __                   = wp.i18n.__;
    var apiFetch             = wp.apiFetch;

    // ─────────────────────────────────────────
    // Spalten (Reihenfolge = Anzeige-Reihenfolge)
    // Feldnamen müssen mit PHP COLUMN_MAP übereinstimmen
    // ─────────────────────────────────────────
    var KNOWN_COLUMNS = [
        { key: 'platz',            label: '#',      desc: 'Tabellenplatz' },
        { key: 'teamname',         label: 'Team',   desc: 'Mannschaft' },
        { key: 'anzSpiele',        label: 'Sp',     desc: 'Anzahl Spiele' },
        { key: 'anzGewinnpunkte',  label: 'GP',     desc: 'Gewinnpunkte' },
        { key: 'anzVerlustpunkte', label: 'VP',     desc: 'Verlustpunkte' },
        { key: 's',                label: 'S',      desc: 'Siege' },
        { key: 'n',                label: 'N',      desc: 'Niederlagen' },
        { key: 'gb',               label: 'GB',     desc: 'Games Behind (Rückstand)' },
        { key: 'koerbe',           label: 'Körbe',  desc: 'Erzielte Körbe' },
        { key: 'gegenKoerbe',      label: 'Geg.',   desc: 'Gegnerische Körbe' },
        { key: 'korbdiff',         label: '+/−',    desc: 'Korbdifferenz' },
    ];

    var DEFAULT_DESKTOP = 'platz,teamname,anzSpiele,anzGewinnpunkte,anzVerlustpunkte,s,n,koerbe,gegenKoerbe,korbdiff';
    var DEFAULT_MOBILE  = 'platz,teamname,s,n,korbdiff';

    // Team-Anzeige-Optionen
    var TEAM_DISPLAY_OPTIONS = [
        { value: 'full',      label: '🏷 Logo + Name' },
        { value: 'short',     label: '🏷 Logo + Kurzname (z.B. "FRE")' },
        { value: 'logo',      label: '🖼 Nur Logo' },
        { value: 'nameShort', label: '✏️ Nur Kurzname (kein Logo)' },
    ];

    // ─────────────────────────────────────────
    // Block-Icon
    // ─────────────────────────────────────────
    var blockIcon = el( 'svg', {
        xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24,
    }, el( 'path', {
        d: 'M3 3h18v2H3V3zm0 4h18v2H3V7zm0 4h18v2H3v-2zm0 4h18v2H3v-2zm0 4h18v2H3v-2z',
        fill: 'currentColor',
    }) );

    // ─────────────────────────────────────────
    // Hilfsfunktionen
    // ─────────────────────────────────────────
    function parseColumns( str ) {
        if ( ! str ) return [];
        return str.split( ',' ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
    }

    function serializeColumns( arr ) {
        return arr.join( ',' );
    }

    function toggleColumn( currentStr, key, defaultStr ) {
        var cols = parseColumns( currentStr || defaultStr );
        var idx = cols.indexOf( key );
        if ( idx >= 0 ) {
            if ( key === 'platz' || key === 'teamname' ) return currentStr || defaultStr;
            cols.splice( idx, 1 );
        } else {
            var insertIdx = 0;
            for ( var i = 0; i < KNOWN_COLUMNS.length; i++ ) {
                if ( KNOWN_COLUMNS[i].key === key ) break;
                if ( cols.indexOf( KNOWN_COLUMNS[i].key ) >= 0 ) insertIdx++;
            }
            cols.splice( insertIdx, 0, key );
        }
        return serializeColumns( cols );
    }

    // ─────────────────────────────────────────
    // Spalten-Konfiguration Komponente
    // ─────────────────────────────────────────
    function ColumnPicker( props ) {
        var activeCols = parseColumns( props.value || props.defaultValue );

        return el( 'div', { className: 'bbb-column-picker', style: { marginBottom: '12px' } },
            el( 'div', {
                style: { fontWeight: 600, fontSize: '11px', textTransform: 'uppercase', letterSpacing: '.05em', color: '#757575', marginBottom: '6px' },
            }, props.label ),

            KNOWN_COLUMNS.map( function ( col ) {
                var isActive = activeCols.indexOf( col.key ) >= 0;
                var isLocked = col.key === 'platz' || col.key === 'teamname';

                return el( CheckboxControl, {
                    key: col.key,
                    label: col.label + '  –  ' + col.desc,
                    checked: isActive,
                    disabled: isLocked,
                    onChange: function () {
                        props.onChange( toggleColumn( props.value || props.defaultValue, col.key, props.defaultValue ) );
                    },
                    __nextHasNoMarginBottom: true,
                });
            }),

            el( Button, {
                variant: 'link', isDestructive: true,
                onClick: function () { props.onChange( '' ); },
                style: { marginTop: '4px', fontSize: '12px' },
            }, __( 'Auf Standard zurücksetzen', 'bbb-live-tables' ) ),
        );
    }

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
            return { value: String( league.liga_id ), label: league.label };
        });
        var currentInList = leagues.some( function ( l ) { return l.liga_id === ligaId; } );

        if ( isLoading ) {
            return el( 'div', { style: { display: 'flex', alignItems: 'center', gap: '8px' } },
                el( Spinner ), el( 'span', null, __( 'Ligen werden geladen…', 'bbb-live-tables' ) ) );
        }

        return el( wp.element.Fragment, null,
            leagues.length > 0 && ! showManual && el( ComboboxControl, {
                label: __( 'Liga', 'bbb-live-tables' ),
                help: ligaId
                    ? ( currentInList ? 'Liga-ID: ' + ligaId : 'Liga-ID ' + ligaId + ' – manuell gesetzt.' )
                    : __( 'Wähle eine Liga mit Tabelle aus.', 'bbb-live-tables' ),
                value: ligaId ? String( ligaId ) : '',
                options: options,
                onChange: function ( val ) { onChange( parseInt( val, 10 ) || 0 ); },
                onFilterValueChange: function () {},
            }),

            ( showManual || leagues.length === 0 ) && el( TextControl, {
                label: __( 'Liga-ID (manuell)', 'bbb-live-tables' ),
                help: __( 'BBB Liga-ID (z.B. 47976).', 'bbb-live-tables' ),
                type: 'number',
                value: ligaId || '',
                onChange: function ( val ) { onChange( parseInt( val, 10 ) || 0 ); },
            }),

            leagues.length > 0 && el( Button, {
                variant: 'link',
                onClick: function () { setShowManual( ! showManual ); },
                style: { marginTop: '-8px', fontSize: '12px' },
            }, showManual ? '← Zurück zur Liga-Auswahl' : 'Liga-ID manuell eingeben →' ),

            ligaId && ! currentInList && ! showManual && leagues.length > 0 && el( Notice, {
                status: 'info', isDismissible: false, style: { marginTop: '8px' },
            }, __( 'Die Liga-ID ist nicht in den gesyncten Ligen. Die Tabelle wird trotzdem direkt von der BBB-API geladen.', 'bbb-live-tables' ) ),
        );
    }

    // ─────────────────────────────────────────
    // Edit-Komponente
    // ─────────────────────────────────────────
    function TableEdit( props ) {
        var a = props.attributes;
        var setA = props.setAttributes;
        var blockProps = useBlockProps();

        // Liga-Liste
        var stateL = useState( [] ), leagues = stateL[0], setLeagues = stateL[1];
        var stateLoading = useState( true ), isLoading = stateLoading[0], setLoading = stateLoading[1];
        var stateManual = useState( false ), showManual = stateManual[0], setShowManual = stateManual[1];

        useEffect( function () {
            apiFetch( { path: '/bbb/v1/leagues?type=league' } )
                .then( function ( data ) { setLeagues( data || [] ); setLoading( false ); })
                .catch( function () { setLeagues( [] ); setLoading( false ); });
        }, [] );

        var inspectorControls = el( InspectorControls, null,

            // Datenquelle
            el( PanelBody, { title: __( 'Datenquelle', 'bbb-live-tables' ), initialOpen: true },
                el( LigaSelector, {
                    ligaId: a.ligaId, onChange: function ( v ) { setA( { ligaId: v } ); },
                    leagues: leagues, isLoading: isLoading, showManual: showManual, setShowManual: setShowManual,
                }),
                el( TextControl, {
                    label: __( 'Titel', 'bbb-live-tables' ),
                    help: __( 'Leer = Liga-Name aus API.', 'bbb-live-tables' ),
                    value: a.title, onChange: function ( v ) { setA( { title: v } ); },
                }),
            ),

            // Team-Anzeige
            el( PanelBody, { title: __( '👥 Team-Anzeige', 'bbb-live-tables' ), initialOpen: false },
                el( SelectControl, {
                    label: __( '🖥 Desktop', 'bbb-live-tables' ),
                    value: a.teamDisplayDesktop || 'full',
                    options: TEAM_DISPLAY_OPTIONS,
                    onChange: function ( v ) { setA( { teamDisplayDesktop: v } ); },
                }),
                el( SelectControl, {
                    label: __( '📱 Mobil', 'bbb-live-tables' ),
                    value: a.teamDisplayMobile || 'short',
                    options: TEAM_DISPLAY_OPTIONS,
                    onChange: function ( v ) { setA( { teamDisplayMobile: v } ); },
                }),
                el( ToggleControl, {
                    label: __( 'Team-Logos laden', 'bbb-live-tables' ),
                    help: __( 'Deaktivieren spart Ladezeit.', 'bbb-live-tables' ),
                    checked: a.showLogos,
                    onChange: function ( v ) { setA( { showLogos: v } ); },
                }),
            ),

            // Spalten Desktop
            el( PanelBody, { title: __( '🖥 Spalten Desktop', 'bbb-live-tables' ), initialOpen: false },
                el( ColumnPicker, {
                    label: __( 'Sichtbare Spalten am Desktop', 'bbb-live-tables' ),
                    value: a.columnsDesktop, defaultValue: DEFAULT_DESKTOP,
                    onChange: function ( v ) { setA( { columnsDesktop: v } ); },
                }),
            ),

            // Spalten Mobil
            el( PanelBody, { title: __( '📱 Spalten Mobil', 'bbb-live-tables' ), initialOpen: false },
                el( ColumnPicker, {
                    label: __( 'Sichtbare Spalten am Smartphone', 'bbb-live-tables' ),
                    value: a.columnsMobile, defaultValue: DEFAULT_MOBILE,
                    onChange: function ( v ) { setA( { columnsMobile: v } ); },
                }),
                el( Notice, { status: 'info', isDismissible: false, style: { marginTop: '8px' } },
                    __( 'Auf schmalen Bildschirmen (< 600px). Tipp: Weniger ist mehr!', 'bbb-live-tables' ) ),
            ),

            // Erweitert
            el( PanelBody, { title: __( 'Erweitert', 'bbb-live-tables' ), initialOpen: false },
                el( ToggleControl, {
                    label: __( 'Eigenes Team hervorheben', 'bbb-live-tables' ),
                    help: a.highlightOwn !== false
                        ? __( 'Der eigene Verein wird farblich hervorgehoben (Club-ID aus Plugin-Einstellungen).', 'bbb-live-tables' )
                        : __( 'Kein Team wird hervorgehoben.', 'bbb-live-tables' ),
                    checked: a.highlightOwn !== false,
                    onChange: function ( v ) { setA( { highlightOwn: v } ); },
                }),
                el( ToggleControl, {
                    label: __( 'Games Behind (GB) anzeigen', 'bbb-live-tables' ),
                    help: a.showGb
                        ? __( 'GB-Spalte wird angezeigt (R\u00fcckstand zum Tabellenersten).', 'bbb-live-tables' )
                        : __( 'Standard DBB-Rangfolge ohne GB.', 'bbb-live-tables' ),
                    checked: !!a.showGb,
                    onChange: function ( v ) { setA( { showGb: v } ); },
                }),
                el( RangeControl, {
                    label: __( 'Cache-Dauer (Sekunden)', 'bbb-live-tables' ),
                    help: 'Standard: 900 (15 Min).',
                    value: a.cache, min: 0, max: 7200, step: 60,
                    onChange: function ( v ) { setA( { cache: v } ); },
                }),
                el( Notice, { status: 'success', isDismissible: false, style: { marginTop: '12px' } },
                    el( 'strong', null, '🔒 DSGVO-Info: ' ),
                    __( 'Tabellendaten werden live von basketball-bund.net geladen und nur kurzzeitig gecached.', 'bbb-live-tables' ) ),
            ),
        );

        // Hauptbereich
        var content;
        if ( ! a.ligaId ) {
            content = el( Placeholder, {
                icon: blockIcon,
                label: __( 'Liga-Tabelle (Live)', 'bbb-live-tables' ),
                instructions: leagues.length > 0 ? 'Wähle eine Liga aus der Liste.' : 'Gib eine BBB Liga-ID ein.',
            }, el( 'div', { style: { width: '100%', maxWidth: '400px' } },
                el( LigaSelector, {
                    ligaId: a.ligaId, onChange: function ( v ) { setA( { ligaId: v } ); },
                    leagues: leagues, isLoading: isLoading, showManual: showManual, setShowManual: setShowManual,
                }) ) );
        } else {
            content = el( ServerSideRender, {
                block: 'bbb/league-table', attributes: a,
                LoadingResponsePlaceholder: function () {
                    return el( Placeholder, { icon: blockIcon, label: 'Liga-Tabelle' },
                        el( Spinner ), el( 'p', null, 'Lade Tabellen-Vorschau…' ) );
                },
                ErrorResponsePlaceholder: function () {
                    return el( Placeholder, { icon: blockIcon, label: 'Liga-Tabelle' },
                        el( Notice, { status: 'error', isDismissible: false }, 'Fehler beim Laden. Prüfe die Liga-ID.' ) );
                },
            });
        }

        return el( 'div', blockProps, inspectorControls, content );
    }

    registerBlockType( 'bbb/league-table', {
        edit: TableEdit,
        save: function () { return null; },
    });
} )();
