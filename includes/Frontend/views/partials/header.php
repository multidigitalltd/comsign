<?php
/**
 * Standalone public-page header.
 *
 * @package ComSign
 *
 * @var string $page_title
 */

defined( 'ABSPATH' ) || exit;

$dir  = is_rtl() ? 'rtl' : 'ltr';
$lang = esc_attr( str_replace( '_', '-', get_locale() ) );
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" dir="<?php echo esc_attr( $dir ); ?>">
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $page_title ?? __( 'Sign document', 'comsign' ) ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( COMSIGN_PLUGIN_URL . 'assets/css/signing.css?ver=' . COMSIGN_VERSION ); ?>">
</head>
<body class="comsign-public">
<div class="comsign-shell">
	<header class="comsign-public-header">
		<span class="comsign-brand"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
	</header>
