<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal PDF writer for simple business documents (text, lines, shaded
 * boxes, Helvetica / Helvetica-Bold, US Letter). No external libraries.
 */
final class Pdf {

	public const WIDTH  = 612;
	public const HEIGHT = 792;

	private array $pages   = array();
	private string $stream = '';

	public function add_page(): void {
		if ( '' !== $this->stream ) {
			$this->pages[] = $this->stream;
		}
		$this->stream = '';
	}

	public function text( float $x, float $y, string $s, float $size = 10, bool $bold = false, float $gray = 0 ): void {
		$font          = $bold ? 'F2' : 'F1';
		$this->stream .= sprintf( "%.3F g BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET 0 g\n", $gray, $font, $size, $x, $y, $this->escape( $s ) );
	}

	public function text_right( float $right, float $y, string $s, float $size = 10, bool $bold = false ): void {
		$this->text( $right - $this->width( $s, $size ), $y, $s, $size, $bold );
	}

	public function line( float $x1, float $y1, float $x2, float $y2, float $width = 0.5, float $gray = 0.75 ): void {
		$this->stream .= sprintf( "%.3F G %.2F w %.2F %.2F m %.2F %.2F l S 0 G\n", $gray, $width, $x1, $y1, $x2, $y2 );
	}

	public function box( float $x, float $y, float $w, float $h, float $gray = 0.95 ): void {
		$this->stream .= sprintf( "%.3F g %.2F %.2F %.2F %.2F re f 0 g\n", $gray, $x, $y, $w, $h );
	}

	/** Split text into lines that fit a width (approximate Helvetica metrics). */
	public function wrap( string $s, float $max_width, float $size ): array {
		$lines = array();
		foreach ( preg_split( '/\R/', $s ) as $paragraph ) {
			$line = '';
			foreach ( preg_split( '/\s+/', trim( $paragraph ) ) as $word ) {
				$try = '' === $line ? $word : $line . ' ' . $word;
				if ( '' !== $line && $this->width( $try, $size ) > $max_width ) {
					$lines[] = $line;
					$line    = $word;
				} else {
					$line = $try;
				}
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/** Approximate string width in points. */
	public function width( string $s, float $size ): float {
		$units = 0;
		foreach ( str_split( $this->encode( $s ) ) as $c ) {
			if ( ctype_digit( $c ) || '$' === $c ) {
				$units += 556;
			} elseif ( in_array( $c, array( ',', '.', ' ', 'i', 'l', 'j', "'", '|' ), true ) ) {
				$units += 278;
			} elseif ( in_array( $c, array( '-', '(', ')', 'f', 't', 'r' ), true ) ) {
				$units += 333;
			} elseif ( ctype_upper( $c ) || in_array( $c, array( 'm', 'w' ), true ) ) {
				$units += 690;
			} else {
				$units += 520;
			}
		}
		return $units * $size / 1000;
	}

	private function encode( string $s ): string {
		$converted = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s );
		return false === $converted ? $s : $converted;
	}

	private function escape( string $s ): string {
		// Titles from WordPress can carry HTML entities (e.g. &#8217; for an apostrophe); print the real character.
		$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return strtr( $this->encode( $s ), array( '\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ' ) );
	}

	public function output(): string {
		$pages = $this->pages;
		if ( '' !== $this->stream || ! $pages ) {
			$pages[] = $this->stream;
		}

		$objects    = array();
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

		$kids = array();
		$next = 5;
		foreach ( $pages as $content ) {
			$page_id             = $next++;
			$content_id          = $next++;
			$kids[]              = $page_id . ' 0 R';
			$objects[ $page_id ] = sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::WIDTH, self::HEIGHT, $content_id );
			$objects[ $content_id ] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
		}
		$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $kids ) . ' >>';
		ksort( $objects );

		$out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $id => $body ) {
			$offsets[ $id ] = strlen( $out );
			$out           .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $out );
		$max  = max( array_keys( $objects ) );
		$out .= "xref\n0 " . ( $max + 1 ) . "\n0000000000 65535 f \n";
		for ( $i = 1; $i <= $max; $i++ ) {
			$out .= sprintf( "%010d 00000 n \n", $offsets[ $i ] ?? 0 );
		}
		$out .= "trailer\n<< /Size " . ( $max + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
		return $out;
	}
}
