<?php
/**
 * Plugin Name:       NetArz AI — هوش مصنوعی نِت اَرز
 * Plugin URI:        https://netarz.ir/ai-api
 * Description:       چت پشتیبانی هوشمند، تیکتینگ حرفه‌ای، نویسندهٔ محتوا، ساخت تصویر و ابزارهای هوش مصنوعی برای وردپرس — همه با یک کلید API نِت اَرز.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NetArz
 * Author URI:        https://netarz.ir
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       netarz-ai
 * Domain Path:       /languages
 *
 * @package NetArz_AI
 */

defined( 'ABSPATH' ) || exit;

define( 'NETARZ_AI_VERSION', '1.0.0' );
define( 'NETARZ_AI_DB_VERSION', '2' );
define( 'NETARZ_AI_FILE', __FILE__ );
define( 'NETARZ_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'NETARZ_AI_URL', plugin_dir_url( __FILE__ ) );

require_once NETARZ_AI_DIR . 'includes/class-netarz-ai-settings.php';
require_once NETARZ_AI_DIR . 'includes/class-netarz-ai-api.php';
require_once NETARZ_AI_DIR . 'includes/class-netarz-ai-installer.php';
require_once NETARZ_AI_DIR . 'includes/class-netarz-ai-util.php';
require_once NETARZ_AI_DIR . 'includes/class-netarz-ai-knowledge.php';
require_once NETARZ_AI_DIR . 'includes/class-netarz-ai-chat.php';
require_once NETARZ_AI_DIR . 'includes/class-netarz-ai-chat-agent.php';
require_once NETARZ_AI_DIR . 'includes/class-netarz-ai-tickets.php';
require_once NETARZ_AI_DIR . 'includes/class-netarz-ai-ticket-agent.php';
require_once NETARZ_AI_DIR . 'includes/class-netarz-ai-writer.php';
require_once NETARZ_AI_DIR . 'includes/class-netarz-ai-privacy.php';
require_once NETARZ_AI_DIR . 'includes/class-netarz-ai-admin.php';
require_once NETARZ_AI_DIR . 'includes/class-netarz-ai-plugin.php';

register_activation_hook( __FILE__, array( 'Netarz_AI_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Netarz_AI_Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Netarz_AI_Plugin', 'instance' ) );
