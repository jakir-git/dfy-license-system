<?php
/**
 * Copy this file to hub-config.php (same folder) and put your DFY License Hub
 * address in it. hub-config.php is ignored by Git, so the real address is never
 * committed; build.sh includes it in the plugin zip.
 *
 * A site can also override it in wp-config.php:  define( 'DFY_LM_HUB', 'https://…/wp-json/dfy-lh/v1/' );
 */
if ( ! defined( 'ABSPATH' ) ) exit;
define( 'DFY_LM_DEFAULT_HUB', 'https://your-hub.example.com/wp-json/dfy-lh/v1/' );
