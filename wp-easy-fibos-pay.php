<?php
/*
Plugin Name: 	Easy FIBOS pay
Plugin URI: 	http://go.ftqq.com/fopay
Description: 	Paid Article plugin for FIBOS on WordPress
Requires at least:	5.1
Tested up to:		5.3.2
Stable tag:		0.3
Version: 		0.3
Author: 		Easy
Author URI: 	https://weibo.com/easy
License: 		GPL3
License URI:  	https://www.gnu.org/licenses/gpl-3.0.html
 */

// 首先我们在后台添加设置菜单

// add_action( 'admin_menu' ,  'foez_pay_test' );
// if( !function_exists('foez_pay_test') )
// function foez_pay_test()
// {
//     add_options_page( '佛系支付插件 for FIBOS', '调试页面', 'manage_options' , '_foez_pay_test' , function()
//     {
//         $account = 'phpisthebest';

//         $postdata = '{
//             find_fibos_tokens_action(
//              order:"-id"
//              where:{
//                              account_to_id: "'.$account.'",
//                              contract_action:{
//                                  in:["eosio.token/transfer","eosio.token/extransfer"]
//                              }
//                          }
//             ){
//                          action{
//                              rawData
//                              transaction
//                             {
//                                 block
//                                 {
//                                     status
//                                 }
//                             }
//                          }
//              token_from{
//               token_name
//              }
//             }
//         }';
        
//         $args = array(
//             'body' => $postdata,
//             'timeout' => '10',
//             'blocking' => true,
//             'data_format' => 'body',
//             'headers' => array(
//                 'Content-Type' => 'application/graphql'
//             )
//         );
//         $url = 'http://api.fowallet.net/1.1';
    
//         $data = wp_remote_post($url, $args);
//     }  );
// }


add_action('admin_menu', 'foez_pay_menu');

if( !function_exists('foez_pay_menu') )
{
    function foez_pay_menu()
    {
        add_options_page('佛系支付插件 for FIBOS', 'Fo支付设置', 'manage_options', '_foez_pay_settings', 'foez_pay_settings_page');
    }
}



add_action('admin_init', function () {
    register_setting(
        'foez-pay-option-page',
        'foez-pay-options'
    );

    add_settings_section(
        'foez-pay-option-page-section',
        'FO 收款账户设置',
        'foez_section_title',
        'foez-pay-option-page'
    );
    
    if( !function_exists('foez_section_title') )
    {
        function foez_section_title()
        {
            echo "在下方配置您的FO账户后，即可进行收款。";
        }
    }

    add_settings_field(
        'foez_pay_account',
        "FO 收款账户",
        'foez_pay_account_render',
        'foez-pay-option-page',
        'foez-pay-option-page-section'
    );

    add_settings_field(
        'foez_pay_idstr',
        "博客唯一码",
        'foez_pay_idstr_render',
        'foez-pay-option-page',
        'foez-pay-option-page-section'
    );
});

// 然后我们在文章编辑页面添加自定义字段
// 手工添加即可，不通过程序来搞


// 显示时，根据权限过滤内容
add_filter('the_content', function ($content) {
    $post_id = get_the_ID();
    $user = wp_get_current_user();

    $price_cent = intval(foez_get_meta($post_id, 'fo-usdt-price')) ;
    
    if ($price_cent> 0) {
        $paid_uids = foez_get_meta_array($post_id, '_paid_uids');

        if ($user && $paid_uids) {
            if (in_array($user->ID, $paid_uids)) {
                $content = str_replace([ '[pay]' , '[/pay]' ], '', $content);
                return $content;
            }
        }
        
        
        // 开始进行付费控制
        $price = $price_cent/100;
        if (preg_match("/\[pay](.+?)\[\/pay]/is", $content)) {
            $content = preg_replace("/\[pay](.+?)\[\/pay]/is", foez_get_pay_notice(get_the_ID(), $price), $content);
        } else {
            //没有找到[pay]标记
            // 全部隐藏
            $content = foez_get_pay_notice(get_the_ID(), $price);
        }


        return $content;
    } else {
        $content = str_replace([ '[pay]' , '[/pay]' ], '', $content);
        return $content;
    }
});

// 然后添加 cron 每隔 30 秒检查一次到账情况
// 定义五秒间隔

add_filter('cron_schedules', function ($schedules) {
    $schedules['thirty_seconds'] = array(
        'interval' => 30,
        'display'  => esc_html__('Every Thirty Seconds'),
    );
 
    return $schedules;
});


if (! wp_next_scheduled('foez_cron_hook')) {
    wp_schedule_event(time(), 'thirty_seconds', 'foez_cron_hook');
}

add_action('foez_cron_hook', 'foez_cron_exec');

if( !function_exists('foez_cron_exec') )
{
    function foez_cron_exec()
    {
        $log = [];
        // 开始检查支付情况
        // 首先需要获得收款账号
        $option = get_option("foez-pay-options");
        if (!($option && $option['account'])) {
            return foez_logit("账户不存在");
        }
    
        $account = $option['account'];
        $memo_prefix = 'WPFO-'.$option['idstr'];
    
        // 然后构造 http 请求，获取最新的支付情况
        if (!$txs = foez_get_user_tx($account, $memo_prefix)) {
            return foez_logit("没有查询到交易");
        }
    
        $to_change = [];
    
        foreach ($txs as $tx) {
            $reg = '/^WPFO\-'. $option['idstr'] .'\-([0-9]+)\-([0-9]+)$/is';
            if (preg_match($reg, $tx['memo'], $out)) {
                list(, $post_id, $uid) = $out;
                //$post_meta = get_post_meta( $post_id );
                /**
                  Array
                    (
                        [_edit_lock] => Array
                            (
                                [0] => 1580467639:1
                            )
    
                        [fo-usdt-price] => Array
                            (
                                [0] => 1
                            )
    
                        [_edit_last] => Array
                            (
                                [0] => 1
                            )
    
                    )
                */
               
                $price_cent = foez_get_meta($post_id, 'fo-usdt-price');
    
                if ($price_cent && $price_cent>= 0) {
                    foez_logit("取到了meta里的 price ".  $price_cent);
    
                    $paid_price = explode(" ", $tx['quantity']['quantity'])[0]*100;
    
                    if ($paid_price >= $price_cent) {
                        foez_logit("支付价格为 ".$paid_price);
    
                        // 将UID加入到 post 的 _paid_uids 里边
                        $to_change[$post_id][] = $uid;
                    }
                }
                
                // foez_logit("post meta $post_id $uid ".print_r( $post_meta , 1 ));
            } else {
                foez_logit("not match", $tx['memo']);
            }
        }
    
        foez_logit("to change " . print_r($to_change, 1));
    
        if (count($to_change) > 0) {
            foreach ($to_change as $the_post_id => $the_uids) {
                foez_logit("开始更新 $the_post_id ");
    
                
                $paid_uids = foez_get_meta_array($the_post_id, '_paid_uids');
    
                foez_logit("取到旧数据" . print_r($paid_uids, 1));
    
                $old_paid_uids = $paid_uids;
    
                $paid_uids = array_merge($paid_uids, $the_uids);
                $paid_uids = array_unique($paid_uids);
    
                foez_logit("构建新数据" . print_r($paid_uids, 1));
    
    
    
                update_post_meta($the_post_id, '_paid_uids', $paid_uids, $old_paid_uids);
    
                foez_logit("updated " . print_r(foez_get_meta_array($the_post_id, '_paid_uids'), 1));
            }
        }
    
        
    
        
        return true;
    }
}


if( !function_exists('foez_logit') )
{
    function foez_logit($content)
    {
        // file_put_contents(__DIR__ . "/log.txt", $content . "\r\n", FILE_APPEND);
    }
}




if( !function_exists('foez_pay_account_render') )
{
    function foez_pay_account_render()
    {
        $options = get_option('foez-pay-options');
        if (!isset($options['account'])) {
            $options['account'] = '';
        } ?>
    <input type='text' name='foez-pay-options[account]'
        value='<?php echo $options['account']; ?>'>
    <span class="description">下载 FO 钱包即可免费生成收款账户</span>
    <?php
    }
}


if( !function_exists('foez_pay_idstr_render') )
{
    function foez_pay_idstr_render()
    {
        $options = get_option('foez-pay-options');
        if (!isset($options['idstr'])) {
            $options['idstr'] = '';
        } ?>
    <input type='text' name='foez-pay-options[idstr]'
        value='<?php echo $options['idstr']; ?>'>
    <span class="description">一串数字，建议3到6位长，用于区分支付博客</span>
    <?php
    }
}



if( !function_exists('foez_pay_settings_page') )
{
    function foez_pay_settings_page()
    {
        echo '<div class="wrap"><h2>支付配置</h2>';
        echo "<form action='options.php' method='post'>";
        settings_fields('foez-pay-option-page');
        do_settings_sections('foez-pay-option-page');
        submit_button();
        echo '</form>';
        echo '</div>';
    }
}


if( !function_exists('foez_get_pay_notice') )
{
    function foez_get_pay_notice($post_id, $price = 1)
    {
        $option = get_option("foez-pay-options");
        $account = $option && $option['account'] ? $option['account'] : 'phpisthetest';
        $idstr = $option && $option['idstr'] ? $option['idstr'] : '0';
        
        $user = wp_get_current_user();

        if ($user && $user->ID > 0) {
            $url = "https://wallet.fo/Pay?params=" . urlencode($account) . ",FOUSDT,eosio,". $price ."," . urlencode('WPFO-'.$idstr.'-'.$post_id . "-" . $user->ID);
            
            $notice = "<p>以下部分的内容需要支付后才能阅读。请<a href='https://wallet.fo' target='_blank'>下载 FO 钱包</a>，扫描二维码支付。完成后，请稍等30秒左右刷新本页面。 </p>";

            // 国外版 调用 google api
            // $notice .= '<p><a href="fowallet://' . urlencode($url) . '"><img style="margin:20px;" src="https://chart.googleapis.com/chart?chs=200x200&cht=qr&chld=H|1&chl='.urlencode($url).'" /></a></p>';

            // 国内版
            $notice .= '<p><a href="fowallet://' . urlencode($url) . '"><img style="margin:20px;max-width:160px;max-height:160px;" src="http://qr.topscan.com/api.php?text='.urlencode($url).'" /></a></p>';
        } else {
            $notice = "<p>以下部分内容需要支付后才能阅读，请先<a href='/wp-login.php'>登入</a>后进行支付</p>";
        }

        return $notice;
    }
}


if( !function_exists('foez_get_user_tx') )
{
    function foez_get_user_tx($account, $memo_prefix, $token = 'FOUSDT@eosio')
    {
        foez_logit("prefix=" . $memo_prefix);
        
        $ret = false;
    
        $postdata = '{
            find_fibos_tokens_action(
             order:"-id"
             where:{
                             account_to_id: "'.$account.'",
                             contract_action:{
                                 in:["eosio.token/transfer","eosio.token/extransfer"]
                             }
                         }
            ){
                         action{
                             rawData
                             transaction
                            {
                                block
                                {
                                    status
                                }
                            }
                         }
             token_from{
              token_name
             }
            }
        }';
    
        $args = array(
            'body' => $postdata,
            'timeout' => '10',
            'blocking' => true,
            'data_format' => 'body',
            'headers' => array(
                'Content-Type' => 'application/graphql'
            )
        );
        $url = 'http://api.fowallet.net/1.1';
    
        $data = wp_remote_post($url, $args);
    
        // foez_logit("remote " . json_encode( $data ) );
    
        if (!$data_array = json_decode(wp_remote_retrieve_body($data), true)) {
            return false;
        }
    
        // foez_logit("data array " . json_encode( $data ) );
    
        //
        foreach ($data_array['data']['find_fibos_tokens_action'] as $item) {
            // 检测token类型
            if ($item['token_from']['token_name'] == $token) {
                // 检测交易状态
                if ($item['action']['transaction']['block']['status'] == 'lightconfirm' || $item['action']['transaction']['block']['status'] == 'noreversible') {
                    // 检测订单号
                    if (strpos(trim($item['action']['rawData']['act']['data']['memo']), $memo_prefix) !== false) {
                        $ret[] = $item['action']['rawData']['act']['data'];
                    }
                    
                    
                    // print_r( $item );
                }
            }
        }
    
        return $ret;
    }

}


if( !function_exists('foez_get_meta') )
{
    function foez_get_meta($post_id, $name)
    {
        // 使用不带缓存的版本吧
        $meta_thing = get_post_meta($post_id, $name);

        return isset($meta_thing[0]) ? $meta_thing[0] : false;
        
        // if( !isset($GLOBALS['_meta_cache'][$name]) )
        // {
        //     $meta_thing = get_post_meta( $post_id , $name );

        //     if( isset( $meta_thing[0] ) )
        //         $GLOBALS['_meta_cache'][$name] = $meta_thing[0];
        //     else
        //         $GLOBALS['_meta_cache'][$name] = false;
        // }
        // return $GLOBALS['_meta_cache'][$name];
    }
}


if( !function_exists('foez_get_meta_array') )
{
    function foez_get_meta_array($post_id, $name)
    {
        $item_or_array = foez_get_meta($post_id, $name);

        if (!is_array($item_or_array)) {
            $item_or_array = [ $item_or_array ];
        }

        $ret = [];
        foreach ($item_or_array as $item) {
            if ($item) {
                $ret[] = $item;
            }
        }

        return $ret;
    }
}

