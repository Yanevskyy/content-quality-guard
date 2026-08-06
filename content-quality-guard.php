<?php
/**
 * Plugin Name:       Content Quality Guard
 * Plugin URI:        https://github.com/Yanevskyy/content-quality-guard
 * Description:       Checks content for accessibility and search problems as it is written, and measures real page speed through the PageSpeed Insights API.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            ClarityWeb
 * Author URI:        https://clarityweb.ie
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       content-quality-guard
 *
 * @package ContentQualityGuard
 */

declare(strict_types=1);

namespace ClarityWeb\ContentQualityGuard;

if (!defined('ABSPATH')) {
    exit;
}

const VERSION     = '0.1.0';
const PLUGIN_FILE = __FILE__;

define('CQG_DIR', plugin_dir_path(__FILE__));
define('CQG_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = CQG_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_readable($path)) {
        require_once $path;
    }
});

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});
