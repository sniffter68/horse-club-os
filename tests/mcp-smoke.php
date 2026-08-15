<?php

defined( 'ABSPATH' ) || exit( 1 );

$checks = array(
	'Abilities API available' => function_exists( 'wp_get_ability' ),
	'MCP Adapter class loaded' => class_exists( '\\WP\\MCP\\Core\\McpAdapter' ),
	'HCOS MCP integration loaded' => class_exists( 'HCOS_MCP' ),
);

foreach ( array( 'health-check', 'inspect-booking', 'inspect-client-relations', 'inspect-membership' ) as $slug ) {
	$checks[ 'Ability hcos/' . $slug . ' registered' ] = function_exists( 'wp_get_ability' ) && null !== wp_get_ability( 'hcos/' . $slug );
}

$failed = false;
foreach ( $checks as $label => $passed ) {
	if ( $passed ) {
		fwrite( STDOUT, "PASS: {$label}\n" );
	} else {
		fwrite( STDERR, "FAIL: {$label}\n" );
		$failed = true;
	}
}

if ( $failed ) {
	exit( 1 );
}

fwrite( STDOUT, "\nHorse Club OS MCP runtime smoke test passed.\n" );
