<?php
/*
Plugin Name: VDZ Monobank
Plugin URI:  http://online-services.org.ua
Description: Курс валют от Монобанка, для отображения используйте шорткод [vdz_monobank_shortcode currencies="USD,EUR"]
Text Domain: vdz-monobank
Domain Path: /languages/
Version:     1.2
Author:      VadimZ
Author URI:  http://online-services.org.ua#vdz-monobank
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VDZ_MB_API', 'vdz_info_monobank' );

class VDZ_MONOBANK_SETTINGS {
	const API_LINK                    = 'https://api.monobank.ua/bank/currency';
	const DATA_NAME                   = '_monobank_data_';
	const DEFAULT_ISO_CODE            = 'UAH';
	const DEFAULT_CURRENCIES_ISO_CODE = 'USD,EUR';
	const DEFAULT_ISO_NUMERIC_CODE    = 980;
}
require_once 'api.php';
require_once 'updated_plugin_admin_notices.php';

// Код активации плагина
register_activation_hook( __FILE__, 'vdz_monobank_activate_plugin' );
function vdz_monobank_activate_plugin() {
	global $wp_version;
	if ( version_compare( $wp_version, '3.8', '<' ) ) {
		// Деактивируем плагин
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( 'This plugin required WordPress version 3.8 or higher' );
	}
	add_option( 'vdz_monobank_admin_show', 1 );
	add_option( 'vdz_monobank_admin_currencies', VDZ_MONOBANK_SETTINGS::DEFAULT_CURRENCIES_ISO_CODE );

	do_action( VDZ_MB_API, 'on', plugin_basename( __FILE__ ) );
}

// Код деактивации плагина
register_deactivation_hook(
	__FILE__,
	function () {
		$plugin_name = preg_replace( '|\/(.*)|', '', plugin_basename( __FILE__ ) );
		$response    = wp_remote_get( "http://api.online-services.org.ua/off/{$plugin_name}" );
		if ( ! is_wp_error( $response ) && isset( $response['body'] ) && ( json_decode( $response['body'] ) !== null ) ) {
			// TODO Вывод сообщения для пользователя
		}
	}
);
// Сообщение при отключении плагина
add_action(
	'admin_init',
	function () {
		if ( is_admin() ) {
			$plugin_data     = get_plugin_data( __FILE__ );
			$plugin_name     = isset( $plugin_data['Name'] ) ? $plugin_data['Name'] : ' us';
			$plugin_dir_name = preg_replace( '|\/(.*)|', '', plugin_basename( __FILE__ ) );
			$handle          = 'admin_' . $plugin_dir_name;
			wp_register_script( $handle, '', null, time(), true );
			wp_enqueue_script( $handle );
			$msg = '';
			if ( function_exists( 'get_locale' ) && in_array( get_locale(), array( 'uk', 'ru_RU' ), true ) ) {
				$msg .= "Спасибо, что были с нами! ({$plugin_name}) Хорошего дня!";
			} else {
				$msg .= "Thanks for your time with us! ({$plugin_name}) Have a nice day!";
			}
			wp_add_inline_script( $handle, "document.getElementById('deactivate-" . esc_attr( $plugin_dir_name ) . "').onclick=function (e){alert('" . esc_attr( $msg ) . "');}" );
		}
	}
);

// Регистрация виджета консоли
add_action( 'wp_dashboard_setup', 'vdz_monobank_add_dashboard_widgets' );

// Используется в хуке
function vdz_monobank_add_dashboard_widgets() {
	$vdz_monobank_admin_show = (int) get_option( 'vdz_monobank_admin_show' );
	if ( empty( $vdz_monobank_admin_show ) ) {
		return;
	}
	wp_add_dashboard_widget( 'vdz_monobank_dashboard_widget', __( 'Monobank exchange rate', 'vdz-monobank' ), 'vdz_monobank_dashboard_widget', null, null, 'side', 'high' );
}

// Выводит контент
function vdz_monobank_dashboard_widget( $post, $callback_args ) {
	echo do_shortcode( '[vdz_monobank_shortcode currencies="' . get_option( 'vdz_monobank_admin_currencies', VDZ_MONOBANK_SETTINGS::DEFAULT_CURRENCIES_ISO_CODE ) . '"]' );
}

function vdz_monobank_get_exchange_rate() {
	$data = get_transient( VDZ_MONOBANK_SETTINGS::DATA_NAME );
	$data = json_decode( $data );
	if ( ! $data ) {
		$response = wp_remote_get( VDZ_MONOBANK_SETTINGS::API_LINK );
		if ( is_array( $response ) && ! is_wp_error( $response ) ) {
			$headers = $response['headers']; // array of http header lines
			$body    = $response['body']; // use the content
			set_transient( VDZ_MONOBANK_SETTINGS::DATA_NAME, $body, 10 * 60 );
			$data = json_decode( $body );
			if ( ! $data ) {
				return;
			}
		}
	}
	if ( is_array( $data ) ) {
		$new_data_currency_iso_numeric_in_keys = array();
		foreach ( $data as $data_obj ) {
			if ( isset( $data_obj->currencyCodeA ) && isset( $data_obj->currencyCodeB ) && ( $data_obj->currencyCodeB === (int) VDZ_MONOBANK_SETTINGS::DEFAULT_ISO_NUMERIC_CODE ) ) {
				$new_data_currency_iso_numeric_in_keys[ (int) $data_obj->currencyCodeA ] = get_object_vars( $data_obj );
			}
		}
		return $new_data_currency_iso_numeric_in_keys;
	}
	return $data;
}
function vdz_monobank_get_exchange_rate_by_iso_numeric_code_in_key( $iso_numeric_code ) {
	$iso_numeric_code = (int) $iso_numeric_code;
	$all_data         = vdz_monobank_get_exchange_rate();
	return isset( $all_data[ $iso_numeric_code ] ) ? $all_data[ $iso_numeric_code ] : false;
}
function vdz_monobank_get_all_currency_data() {
	$filename = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'iso_4217.json';
	if ( function_exists( 'get_locale' ) && in_array( get_locale(), array( 'uk', 'uk_UA', 'ru_RU' ), true ) && file_exists( $filename ) ) {
		$filename = str_replace( '4217', '4217_' . get_locale(), $filename );
	}
	if ( ! file_exists( $filename ) ) {
		return;
	}

	$all_data = @file_get_contents( $filename );
	if ( ! $all_data ) {
		return;
	}
	$all_data = json_decode( $all_data );
	if ( ! $all_data ) {
		return;
	}

	return $all_data;
}
function vdz_monobank_get_all_currency_data_iso_code_in_keys() {
	$all_data = vdz_monobank_get_all_currency_data();
	if ( ! is_array( $all_data ) ) {
		return;
	}
	$all_data_iso_code_in_key = array();
	foreach ( $all_data as $obj ) {
		if ( ! isset( $obj->iso_code ) ) {
			return;
		}
		$all_data_iso_code_in_key[ $obj->iso_code ] = get_object_vars( $obj );
	}
	return $all_data_iso_code_in_key;
}
function vdz_monobank_get_all_currency_data_iso_numeric_code_in_keys() {
	$all_data = vdz_monobank_get_all_currency_data();
	if ( ! is_array( $all_data ) ) {
		return;
	}
	$all_data_iso_code_in_key = array();
	foreach ( $all_data as $obj ) {
		if ( ! isset( $obj->iso_numeric_code ) ) {
			return;
		}
		$all_data_iso_code_in_key[ $obj->iso_numeric_code ] = get_object_vars( $obj );
	}
	return $all_data_iso_code_in_key;
}

function vdz_monobank_get_currency_data_by_iso_code( $str = '' ) {
	if ( empty( $str ) ) {
		return;
	}
	$all_data = vdz_monobank_get_all_currency_data_iso_code_in_keys();
	return isset( $all_data[ $str ] ) ? $all_data[ $str ] : false;
}
function vdz_monobank_get_currency_data_by_iso_numeric_code( $number = '' ) {
	if ( empty( $number ) ) {
		return;
	}
	$number  .= '';// Приводим к строке
	$all_data = vdz_monobank_get_all_currency_data_iso_numeric_code_in_keys();
	return isset( $all_data[ $number ] ) ? $all_data[ $number ] : false;
}

function vdz_monobank_get_allow_currencies_iso_code() {
	$iso_codes = array();
	$all_data  = vdz_monobank_get_exchange_rate();
	if ( is_array( $all_data ) ) {
		$iso_numeric_codes = array_keys( $all_data );
		$all_data_info     = vdz_monobank_get_all_currency_data_iso_numeric_code_in_keys();
		foreach ( $iso_numeric_codes as $numeric_code ) {
			if ( isset( $all_data_info[ (int) $numeric_code ]['iso_code'] ) ) {
				array_push( $iso_codes, $all_data_info[ $numeric_code ]['iso_code'] );
			}
		}
	}
	return implode( ', ', $iso_codes );
}

// Add shorccode for more text Plugin
add_shortcode( 'vdz_monobank_shortcode', 'vdz_monobank_shortcode' );
function vdz_monobank_shortcode( $atts, $content ) {
	// Add defaults params and extract variables
	$attributes = shortcode_atts(
		array(
			'currencies' => VDZ_MONOBANK_SETTINGS::DEFAULT_CURRENCIES_ISO_CODE,
		),
		$atts
	);
	$currencies = explode( ',', trim( $attributes['currencies'], ',' ) );
	if ( ! is_array( $currencies ) ) {
		return '<!-не выбрали валюты для вывода->';
	}
	// print_r( $attributes['currencies']);
	$currencies = array_map( 'trim', $currencies );

	$currencies_data = array();
	foreach ( $currencies as $currency ) {
		$c_data = vdz_monobank_get_currency_data_by_iso_code( $currency );
		if ( is_array( $c_data ) && isset( $c_data['iso_numeric_code'] ) ) {
			$exchange = vdz_monobank_get_exchange_rate_by_iso_numeric_code_in_key( $c_data['iso_numeric_code'] );
			if ( is_array( $exchange ) ) {
				$c_data = array_merge( $c_data, $exchange );
			}
		}
		$currencies_data[ $currency ] = $c_data;
	}
	// print_r( $currencies_data);
	$main_currency = vdz_monobank_get_currency_data_by_iso_code( VDZ_MONOBANK_SETTINGS::DEFAULT_ISO_CODE );
	ob_start();
	?>
	<style>
		#vdz_monobank_dashboard_widget .vdz_monobank_info{
			margin: 0 auto;
		}
		.vdz_monobank_info{
			/*border-collapse: collapse;*/
		}
        .vdz_monobank_info th{
        }
		.vdz_monobank_info td,
		.vdz_monobank_info th{
            text-align: center;
			padding: 5px 8px;
			border: 1px solid rgba(0,0,0,0.3);
		}
		.vdz_monobank_info_small{
			font-size: 0.8em;
			text-align: center;
		}
		.vdz_monobank_info_main{
			text-align: center;
		}
		.vdz_monobank_info_main span,
		.vdz_monobank_info_currency{
			font-size: 1em;
			font-weight: bold;
			text-align: center;
		}
	</style>
	<table class="vdz_monobank_info">
		<tr>
			<th rowspan="2"><?php echo esc_attr__( 'Currencies', 'vdz-monobank' ); ?></th>
			<th colspan="2" class="vdz_monobank_info_main"><?php echo esc_attr( $main_currency['translate_name'] ); ?> <span>(<?php echo esc_attr( $main_currency['iso_code'] ); ?>)</span></th>
		</tr>
		<tr class="vdz_monobank_info_small">
			<td><?php echo esc_attr__( 'Buy', 'vdz-monobank' ); ?></td>
			<td><?php echo esc_attr__( 'Sell', 'vdz-monobank' ); ?></td>
		</tr>
		<?php foreach ( $currencies_data as $code => $currency ) : ?>
		<tr>
			<td class="vdz_monobank_info_currency" title="<?php echo isset( $currency['translate_name'] ) ? esc_attr( $currency['translate_name'] ) : ''; ?>"><?php echo esc_attr( $code ); ?></td>
			<?php if ( ! isset( $currency['rateBuy'] ) && ! isset( $currency['rateSell'] ) && isset( $currency['rateCross'] ) ) : ?>
				<td colspan="2" title="<?php echo esc_attr( $main_currency['translate_name'] ); ?>***"><?php echo esc_attr( round( $currency['rateCross'], 3 ) ); ?></td>
			<?php else : ?>
			<td title="<?php echo esc_attr( $main_currency['translate_name'] ); ?>"><?php echo isset( $currency['rateBuy'] ) ? esc_attr( round( $currency['rateBuy'], 3 ) ) : ''; ?></td>
			<td title="<?php echo esc_attr( $main_currency['translate_name'] ); ?>"><?php echo isset( $currency['rateSell'] ) ? esc_attr( round( $currency['rateSell'], 3 ) ) : ''; ?></td>
			<?php endif ?>
		</tr>
		<?php endforeach; ?>
	</table>
	<?php
	// print_r($currencies_data);
	// print_r($main_currency);
	// template
	$vdz_monobank_html = ob_get_contents();
	ob_end_clean();
	// do inside shortcode
	return $vdz_monobank_html;
}


/*Добавляем новые поля для в настройках шаблона шаблона для верификации сайта*/
function vdz_monobank_theme_customizer( $wp_customize ) {

	if ( ! class_exists( 'WP_Customize_Control' ) ) {
		exit;
	}

	// Добавляем секцию для идетнтификатора YS
	$wp_customize->add_section(
		'vdz_monobank_section',
		array(
			'title'    => __( 'VDZ Monobank' ),
			'priority' => 10,
		// 'description' => __( 'Monobank code on your site' ),
		)
	);
	// Добавляем настройки
	$wp_customize->add_setting(
		'vdz_monobank_admin_show',
		array(
			'type'              => 'option',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	// Добавляем настройки
	$wp_customize->add_setting(
		'vdz_monobank_admin_currencies',
		array(
			'type'              => 'option',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => VDZ_MONOBANK_SETTINGS::DEFAULT_CURRENCIES_ISO_CODE,
		)
	);

	// if( class_exists( 'WP_Customize_Color_Control' ) ){
	// $wp_customize->add_setting( 'vdz_monobank_widget_color', array(
	// 'type' => 'option',
	// 'sanitize_callback'    => 'sanitize_hex_color',
	// 'default' => VDZ_SU_SETTINGS::ARROW_COLOR,
	// ));
	// Add Controls
	// $wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'vdz_monobank_widget_color', array(
	// 'label' => 'Widget Color',
	// 'section' => 'vdz_monobank_section',
	// 'settings' => 'vdz_monobank_widget_color',
	// )));
	// }
	//
	$wp_customize->add_control(
		new WP_Customize_Control(
			$wp_customize,
			'vdz_monobank_admin_currencies',
			array(
				'label'       => __( 'Currencies', 'vdz-monobank' ),
				'description' => __( 'Example:', 'vdz-monobank' ) . ' <strong>USD,EUR</strong>' . '<br/><strong>' . __( 'Allow: ' ) . '</strong>' . vdz_monobank_get_allow_currencies_iso_code(),
				'section'     => 'vdz_monobank_section',
				'settings'    => 'vdz_monobank_admin_currencies',
				'type'        => 'text',
				'input_attrs' => array(
					'placeholder' => VDZ_MONOBANK_SETTINGS::DEFAULT_CURRENCIES_ISO_CODE,
				),
			)
		)
	);

	// Show/Hide
	$wp_customize->add_control(
		new WP_Customize_Control(
			$wp_customize,
			'vdz_monobank_admin_show',
			array(
				'label'       => __( 'VDZ Monobank' ),
				'section'     => 'vdz_monobank_section',
				'settings'    => 'vdz_monobank_admin_show',
				'type'        => 'select',
				'description' => __( 'ON/OFF widget in admin panel' ),
				'choices'     => array(
					1 => __( 'Show' ),
					0 => __( 'Hide' ),
				),
			)
		)
	);

	// Добавляем ссылку на сайт
	$wp_customize->add_setting(
		'vdz_monobank_link',
		array(
			'type' => 'option',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Control(
			$wp_customize,
			'vdz_monobank_link',
			array(
				// 'label'    => __( 'Link' ),
									'section' => 'vdz_monobank_section',
				'settings'                    => 'vdz_monobank_link',
				'type'                        => 'hidden',
				'description'                 => '<br/><a href="//online-services.org.ua#vdz-monobank" target="_blank">VadimZ</a>',
			)
		)
	);
}
add_action( 'customize_register', 'vdz_monobank_theme_customizer', 1 );


// Добавляем допалнительную ссылку настроек на страницу всех плагинов
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'customize.php?autofocus[section]=vdz_monobank_section' ) ) . '">' . esc_html__( 'Settings' ) . '</a>';
		array_unshift( $links, $settings_link );
		array_walk( $links, 'wp_kses_post' );
		return $links;
	}
);


