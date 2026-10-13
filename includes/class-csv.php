<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * CSV downloads that open cleanly in Excel (UTF-8 BOM) and cannot inject
 * spreadsheet formulas.
 */
final class Csv {

	public static function download( string $filename, array $headers, array $rows ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $filename ) );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, $headers );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'safe' ), (array) $row ) );
		}
		fclose( $out );
		exit;
	}

	public static function safe( $value ): string {
		$value = (string) $value;
		return ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $value : $value;
	}
}
