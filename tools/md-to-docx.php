<?php
/**
 * Minimal Markdown → DOCX generator (full RTL Arabic support).
 * Usage: php tools/md-to-docx.php <input.md> <output.docx>
 *
 * @package WC_Optic_Product
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'CLI only.' );
}

$input  = $argv[1] ?? '';
$output = $argv[2] ?? '';

if ( ! is_readable( $input ) ) {
	fwrite( STDERR, "Input not readable: {$input}\n" );
	exit( 1 );
}

$lines = file( $input, FILE_IGNORE_NEW_LINES );
if ( false === $lines ) {
	exit( 1 );
}

function xml_escape( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function run_rpr( bool $bold = false, bool $italic = false, string $size = '' ): string {
	$rpr = '<w:rPr><w:rtl/>';
	$rpr .= '<w:rFonts w:ascii="Traditional Arabic" w:hAnsi="Traditional Arabic" w:cs="Traditional Arabic" w:hint="cs"/>';
	$rpr .= '<w:lang w:val="ar-SA" w:bidi="ar-SA" w:eastAsia="ar-SA"/>';
	if ( $bold ) {
		$rpr .= '<w:b/>';
	}
	if ( $italic ) {
		$rpr .= '<w:i/>';
	}
	if ( '' !== $size ) {
		$rpr .= '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>';
	}
	$rpr .= '</w:rPr>';
	return $rpr;
}

function rtl_ppr( string $extra = '' ): string {
	return '<w:pPr><w:bidi/><w:jc w:val="right"/><w:spacing w:line="276" w:lineRule="auto"/>' . $extra . '</w:pPr>';
}

function run_text( string $text, bool $bold = false, bool $italic = false, string $size = '' ): string {
	return '<w:r>' . run_rpr( $bold, $italic, $size ) . '<w:t xml:space="preserve">' . xml_escape( $text ) . '</w:t></w:r>';
}

function runs_from_markdown( string $text ): string {
	$parts = preg_split( '/(\*\*.+?\*\*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
	if ( ! is_array( $parts ) ) {
		return run_text( $text );
	}
	$runs = '';
	foreach ( $parts as $part ) {
		if ( preg_match( '/^\*\*(.+)\*\*$/u', $part, $m ) ) {
			$runs .= run_text( $m[1], true );
		} else {
			$runs .= run_text( $part );
		}
	}
	return $runs;
}

function paragraph( string $inner, string $ppr_extra = '' ): string {
	return '<w:p>' . rtl_ppr( $ppr_extra ) . $inner . '</w:p>';
}

function heading( string $text, int $level ): string {
	$size = match ( $level ) {
		1 => '36',
		2 => '28',
		3 => '24',
		default => '22',
	};
	$ppr = rtl_ppr( '<w:spacing w:before="280" w:after="140"/>' );
	if ( 1 === $level ) {
		$ppr = str_replace( '</w:pPr>', '<w:outlineLvl w:val="0"/></w:pPr>', $ppr );
	} elseif ( 2 === $level ) {
		$ppr = str_replace( '</w:pPr>', '<w:outlineLvl w:val="1"/></w:pPr>', $ppr );
	} elseif ( 3 === $level ) {
		$ppr = str_replace( '</w:pPr>', '<w:outlineLvl w:val="2"/></w:pPr>', $ppr );
	}
	return '<w:p>' . $ppr . run_text( $text, true, false, $size ) . '</w:p>';
}

function bullet_paragraph( string $text, int $indent = 0 ): string {
	$start = 360 + ( $indent * 360 );
	$ppr   = '<w:pPr><w:bidi/><w:jc w:val="right"/>';
	$ppr  .= '<w:ind w:start="' . $start . '" w:hanging="360"/>';
	$ppr  .= '<w:numPr><w:ilvl w:val="' . min( $indent, 8 ) . '"/><w:numId w:val="1"/></w:numPr>';
	$ppr  .= '</w:pPr>';
	return '<w:p>' . $ppr . runs_from_markdown( $text ) . '</w:p>';
}

function build_table( array $rows ): string {
	if ( empty( $rows ) ) {
		return '';
	}
	$col_count = max( array_map( 'count', $rows ) );
	$width     = (int) ( 9000 / max( 1, $col_count ) );
	$tbl       = '<w:tbl><w:tblPr><w:bidiVisual/><w:jc w:val="right"/>';
	$tbl      .= '<w:tblW w:w="5000" w:type="pct"/><w:tblBorders>';
	$tbl      .= '<w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
	$tbl      .= '<w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
	$tbl      .= '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
	$tbl      .= '<w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
	$tbl      .= '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
	$tbl      .= '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
	$tbl      .= '</w:tblBorders></w:tblPr><w:tblGrid>';
	for ( $i = 0; $i < $col_count; $i++ ) {
		$tbl .= '<w:gridCol w:w="' . $width . '"/>';
	}
	$tbl .= '</w:tblGrid>';
	foreach ( $rows as $row_index => $row ) {
		$tbl .= '<w:tr>';
		for ( $i = 0; $i < $col_count; $i++ ) {
			$cell  = trim( (string) ( $row[ $i ] ?? '' ) );
			$cell  = preg_replace( '/^\*\*|\*\*$/u', '', $cell );
			$tcpr  = '<w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/><w:textDirection w:val="lrTb"/></w:tcPr>';
			$inner = ( 0 === $row_index ) ? run_text( $cell, true ) : runs_from_markdown( $cell );
			$tbl  .= '<w:tc>' . $tcpr . paragraph( $inner ) . '</w:tc>';
		}
		$tbl .= '</w:tr>';
	}
	$tbl .= '</w:tbl>';
	return $tbl;
}

$body   = '';
$table  = array();
$in_tbl = false;

foreach ( $lines as $line ) {
	$trim = trim( $line );

	if ( preg_match( '/^\|(.+)\|$/', $trim ) ) {
		if ( preg_match( '/^[\|\s\-:]+$/', $trim ) ) {
			continue;
		}
		$cells   = array_map( 'trim', explode( '|', trim( $trim, '|' ) ) );
		$table[] = $cells;
		$in_tbl  = true;
		continue;
	}

	if ( $in_tbl && ! empty( $table ) ) {
		$body  .= build_table( $table );
		$table  = array();
		$in_tbl = false;
	}

	if ( '' === $trim ) {
		continue;
	}

	if ( preg_match( '/^---$/', $trim ) ) {
		$body .= paragraph( run_text( ' ' ) );
		continue;
	}

	if ( preg_match( '/^(#{1,3})\s+(.+)$/u', $trim, $m ) ) {
		$body .= heading( $m[2], strlen( $m[1] ) );
		continue;
	}

	if ( preg_match( '/^[-*]\s+(.+)$/u', $trim, $m ) ) {
		$indent = (int) floor( ( strlen( $line ) - strlen( ltrim( $line ) ) ) / 2 );
		$body  .= bullet_paragraph( $m[1], $indent );
		continue;
	}

	if ( preg_match( '/^\*(.+)\*$/u', $trim, $m ) && ! str_starts_with( $trim, '**' ) ) {
		$body .= paragraph( run_text( $m[1], false, true ) );
		continue;
	}

	$body .= paragraph( runs_from_markdown( $trim ) );
}

if ( ! empty( $table ) ) {
	$body .= build_table( $table );
}

$sect_pr = '<w:sectPr>'
	. '<w:bidi/>'
	. '<w:rtlGutter/>'
	. '<w:pgSz w:w="11906" w:h="16838"/>'
	. '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>'
	. '<w:cols w:space="720"/>'
	. '<w:docGrid w:linePitch="360"/>'
	. '</w:sectPr>';

$document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
	. 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
	. '<w:body>' . $body . $sect_pr . '</w:body></w:document>';

$styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
	. '<w:docDefaults>'
	. '<w:rPrDefault><w:rPr>'
	. '<w:rtl/>'
	. '<w:rFonts w:ascii="Traditional Arabic" w:hAnsi="Traditional Arabic" w:cs="Traditional Arabic" w:hint="cs"/>'
	. '<w:lang w:val="ar-SA" w:bidi="ar-SA" w:eastAsia="ar-SA"/>'
	. '<w:sz w:val="24"/><w:szCs w:val="24"/>'
	. '</w:rPr></w:rPrDefault>'
	. '<w:pPrDefault><w:pPr><w:bidi/><w:jc w:val="right"/></w:pPr></w:pPrDefault>'
	. '</w:docDefaults>'
	. '<w:style w:type="paragraph" w:default="1" w:styleId="Normal">'
	. '<w:name w:val="Normal"/>'
	. '<w:qFormat/>'
	. '<w:pPr><w:bidi/><w:jc w:val="right"/></w:pPr>'
	. '<w:rPr><w:rtl/><w:lang w:val="ar-SA" w:bidi="ar-SA"/></w:rPr>'
	. '</w:style>'
	. '</w:styles>';

$settings_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
	. '<w:bidi/>'
	. '<w:themeFontLang w:val="ar-SA" w:eastAsia="ar-SA" w:bidi="ar-SA"/>'
	. '<w:defaultTabStop w:val="720"/>'
	. '<w:characterSpacingControl w:val="doNotCompress"/>'
	. '<w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat>'
	. '</w:settings>';

$numbering_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
	. '<w:abstractNum w:abstractNumId="0"><w:multiLevelType w:val="hybridMultilevel"/>'
	. '<w:lvl w:ilvl="0">'
	. '<w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val=""/>'
	. '<w:lvlJc w:val="right"/>'
	. '<w:pPr><w:bidi/><w:ind w:start="360" w:hanging="360"/></w:pPr>'
	. '<w:rPr><w:rtl/><w:rFonts w:ascii="Symbol" w:hAnsi="Symbol" w:hint="default"/></w:rPr>'
	. '</w:lvl>'
	. '<w:lvl w:ilvl="1">'
	. '<w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="o"/>'
	. '<w:lvlJc w:val="right"/>'
	. '<w:pPr><w:bidi/><w:ind w:start="720" w:hanging="360"/></w:pPr>'
	. '<w:rPr><w:rtl/><w:rFonts w:ascii="Courier New" w:hAnsi="Courier New"/></w:rPr>'
	. '</w:lvl>'
	. '</w:abstractNum>'
	. '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
	. '</w:numbering>';

$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
	. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
	. '<Default Extension="xml" ContentType="application/xml"/>'
	. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
	. '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
	. '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
	. '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
	. '</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
	. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
	. '</Relationships>';

$doc_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
	. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
	. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>'
	. '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
	. '</Relationships>';

$zip = new ZipArchive();
if ( true !== $zip->open( $output, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Cannot create: {$output}\n" );
	exit( 1 );
}

$zip->addFromString( '[Content_Types].xml', $content_types );
$zip->addFromString( '_rels/.rels', $rels );
$zip->addFromString( 'word/document.xml', $document_xml );
$zip->addFromString( 'word/_rels/document.xml.rels', $doc_rels );
$zip->addFromString( 'word/styles.xml', $styles_xml );
$zip->addFromString( 'word/settings.xml', $settings_xml );
$zip->addFromString( 'word/numbering.xml', $numbering_xml );
$zip->close();

echo "Created: {$output}\n";
