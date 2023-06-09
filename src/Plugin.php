<?php

namespace Detain\MyAdminFloatingIps;

use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Class Plugin
 *
 * @package Detain\MyAdminFloatingIps
 */
class Plugin
{
    public static $name = 'Floating IP Services';
    public static $description = 'Allows selling of Floating IP Services';
    public static $help = '';
    public static $module = 'floating_ips';
    public static $type = 'module';
    public static $settings = [
        'SERVICE_ID_OFFSET' => 1100,
        'USE_REPEAT_INVOICE' => true,
        'USE_PACKAGES' => true,
        'BILLING_DAYS_OFFSET' => 0,
        'IMGNAME' => 'e-mail.png',
        'REPEAT_BILLING_METHOD' => PRORATE_BILLING,
        'DELETE_PENDING_DAYS' => 45,
        'SUSPEND_DAYS' => 14,
        'SUSPEND_WARNING_DAYS' => 7,
        'TITLE' => 'Floating IP Services',
        'MENUNAME' => 'Floating IPs',
        'EMAIL_FROM' => 'support@interserver.net',
        'TBLNAME' => 'Floating IPs',
        'TABLE' => 'floating_ips',
        'TITLE_FIELD' => 'floating_ip_ip',
        'TITLE_FIELD2' => 'floating_ip_target_ip',
        'PREFIX' => 'floating_ip'];

    /**
     * Plugin constructor.
     */
    public function __construct()
    {
    }

    /**
     * @return array
     */
    public static function getHooks()
    {
        return [
            self::$module.'.load_processing' => [__CLASS__, 'loadProcessing'],
            self::$module.'.settings' => [__CLASS__, 'getSettings'],
            self::$module.'.deactivate' => [__CLASS__, 'getDeactivate']
        ];
    }

    /**
     * @param \Symfony\Component\EventDispatcher\GenericEvent $event
     */
    public static function getDeactivate(GenericEvent $event)
    {
        $serviceTypes = run_event('get_service_types', false, self::$module);
        $serviceClass = $event->getSubject();
        myadmin_log(self::$module, 'info', self::$name.' Deactivation', __LINE__, __FILE__, self::$module, $serviceClass->getId());
        if ($serviceTypes[$serviceClass->getType()]['services_type'] == get_service_define('FLOATING_IPS')) {
        } else {
            $GLOBALS['tf']->history->add(self::$module.'queue', $serviceClass->getId(), 'delete', '', $serviceClass->getCustid());
        }
    }

    /**
     * @param \Symfony\Component\EventDispatcher\GenericEvent $event
     */
    public static function loadProcessing(GenericEvent $event)
    {
        /**
         * @var \ServiceHandler $service
         */
        $service = $event->getSubject();
        $service->setModule(self::$module)
            ->setEnable(function ($service) {
                $serviceInfo = $service->getServiceInfo();
                $settings = get_module_settings(self::$module);
                $serviceTypes = run_event('get_service_types', false, self::$module);
                if ($serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_type'] == get_service_define('FLOATING_IPS')) {
                    // pick an ip from ip pool
                    $ip = false;
                    $db = get_module_db(self::$module);
                    $db->query("select * from floating_ip_pool where pool_order={$serviceInfo[$settings['PREFIX'].'_id']} limit 1");
                    if ($db->num_rows() > 0) {
                        $db->next_record(MYSQL_ASSOC);
                        $ip = $db->Record['pool_ip'];
                    } else {
                        $db->query("select * from floating_ip_pool where pool_usable=1 and pool_used=0 limit 1");
                        if ($db->num_rows() > 0) {
                            $db->next_record(MYSQL_ASSOC);
                            $ip = $db->Record['pool_ip'];
                        }
                    }
                    if ($ip !== false) {
                        $targetIp = $serviceInfo[$settings['PREFIX'].'_target_ip'];
                        // get switch from ip
                        $db->query("select name,ip from switchports left join switchmanager on switchmanager.id=switch where find_in_set((select ips_vlan from ips where ips_ip='{$targetIp}'), vlans) group by ip", __LINE__, __FILE__);
                        if ($db->num_rows() > 0) {
                            $db->next_record(MYSQL_ASSOC);
                            $switchIp = $db->Record['ip'];
                            $switchName = $db->Record['name'];
                            // assign ip
                            // add ip route
                            $cmds = ['config t', 'ip route '.$ip.'/32 '.$targetIp, 'end', 'copy run st'];
                            require_once INCLUDE_ROOT.'/servers/Cisco.php';
                            myadmin_log('myadmin', 'info', 'Running on Switch '.$switchName.': '.json_encode($cmds), __LINE__, __FILE__);
                            $output = \Cisco::run($switchIp, $cmds);
                            myadmin_log('myadmin', 'info', 'Output from Switch '.$switchName.': '.json_encode($output), __LINE__, __FILE__);
                            myadmin_log('myadmin', 'info', 'Raw Output from Switch '.$switchName.': '.json_encode(Cisco::$output), __LINE__, __FILE__);
                            $db->query("update floating_ip_pool set pool_used=1, pool_order={$serviceInfo[$settings['PREFIX'].'_id']} where pool_ip='{$ip}'", __LINE__, __FILE__);
                            $db->query("UPDATE {$settings['TABLE']} SET {$settings['PREFIX']}_ip='{$ip}', {$settings['PREFIX']}_status='active', {$settings['PREFIX']}_server_status='active' WHERE {$settings['PREFIX']}_id='".$serviceInfo[$settings['PREFIX'].'_id']."'", __LINE__, __FILE__);
                            $GLOBALS['tf']->history->add($settings['TABLE'], 'change_status', 'active', $serviceInfo[$settings['PREFIX'].'_id'], $serviceInfo[$settings['PREFIX'].'_custid']);
                        } else {
                            myadmin_log('myadmin', 'error', 'no ip found on switches for '.$targetIp, __LINE__, __FILE__);
                        }
                    } else {
                        myadmin_log('myadmin', 'error', 'no free ips in pool', __LINE__, __FILE__);
                    }
                }
            })->setReactivate(function ($service) {
                $serviceTypes = run_event('get_service_types', false, self::$module);
                $serviceInfo = $service->getServiceInfo();
                $settings = get_module_settings(self::$module);
                $db = get_module_db(self::$module);
                if ($serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_type'] == get_service_define('FLOATING_IPS')) {
                    $class = '\\MyAdmin\\Orm\\'.get_orm_class_from_table($settings['TABLE']);
                    /** @var \MyAdmin\Orm\Product $class **/
                    $serviceClass = new $class();
                    $serviceClass->load_real($serviceInfo[$settings['PREFIX'].'_id']);
                    // pick an ip from ip pool
                    $ip = false;
                    $db = get_module_db(self::$module);
                    $db->query("select * from floating_ip_pool where pool_order={$serviceInfo[$settings['PREFIX'].'_id']} limit 1");
                    if ($db->num_rows() > 0) {
                        $db->next_record(MYSQL_ASSOC);
                        $ip = $db->Record['pool_ip'];
                    } else {
                        $db->query("select * from floating_ip_pool where pool_usable=1 and pool_used=0 limit 1");
                        if ($db->num_rows() > 0) {
                            $db->next_record(MYSQL_ASSOC);
                            $ip = $db->Record['pool_ip'];
                        }
                    }
                    if ($ip !== false) {
                        $targetIp = $serviceInfo[$settings['PREFIX'].'_target_ip'];
                        // get switch from ip
                        $db->query("select name,ip from switchports left join switchmanager on switchmanager.id=switch where find_in_set((select ips_vlan from ips where ips_ip='{$targetIp}'), vlans) group by ip", __LINE__, __FILE__);
                        if ($db->num_rows() > 0) {
                            $db->next_record(MYSQL_ASSOC);
                            $switchIp = $db->Record['ip'];
                            $switchName = $db->Record['name'];
                            // assign ip
                            // add ip route
                            $cmds = ['config t', 'ip route '.$ip.'/32 '.$targetIp, 'end', 'copy run st'];
                            require_once INCLUDE_ROOT.'/servers/Cisco.php';
                            myadmin_log('myadmin', 'info', 'Running on Switch '.$switchName.': '.json_encode($cmds), __LINE__, __FILE__);
                            $output = \Cisco::run($switchIp, $cmds);
                            myadmin_log('myadmin', 'info', 'Output from Switch '.$switchName.': '.json_encode($output), __LINE__, __FILE__);
                            myadmin_log('myadmin', 'info', 'Raw Output from Switch '.$switchName.': '.json_encode(Cisco::$output), __LINE__, __FILE__);
                            $db->query("update floating_ip_pool set pool_used=1, pool_order={$serviceInfo[$settings['PREFIX'].'_id']} where pool_ip='{$ip}'", __LINE__, __FILE__);
                            $db->query("UPDATE {$settings['TABLE']} SET {$settings['PREFIX']}_ip='{$ip}', {$settings['PREFIX']}_status='active', {$settings['PREFIX']}_server_status='active' WHERE {$settings['PREFIX']}_id='".$serviceInfo[$settings['PREFIX'].'_id']."'", __LINE__, __FILE__);
                            $GLOBALS['tf']->history->add($settings['TABLE'], 'change_status', 'active', $serviceInfo[$settings['PREFIX'].'_id'], $serviceInfo[$settings['PREFIX'].'_custid']);
                        } else {
                            myadmin_log('myadmin', 'error', 'no ip found on switches for '.$targetIp, __LINE__, __FILE__);
                        }
                }
                $smarty = new \TFSmarty();
                $smarty->assign('backup_name', $serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_name']);
                $email = $smarty->fetch('email/admin/backup_reactivated.tpl');
                $subject = $serviceInfo[$settings['TITLE_FIELD']].' '.$serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_name'].' '.$settings['TBLNAME'].' Reactivated';
                (new \MyAdmin\Mail())->adminMail($subject, $email, false, 'admin/backup_reactivated.tpl');
            })->setDisable(function ($service) {
                $serviceInfo = $service->getServiceInfo();
                $settings = get_module_settings(self::$module);
                if ($serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_type'] == get_service_define('FLOATING_IPS')) {
                    $db = get_module_db(self::$module);
                    $ip = $serviceInfo[$settings['PREFIX'].'_ip'];
                    $targetIp = $serviceInfo[$settings['PREFIX'].'_target_ip'];
                    // get switch from ip
                    $db->query("select name,ip from switchports left join switchmanager on switchmanager.id=switch where find_in_set((select ips_vlan from ips where ips_ip='{$targetIp}'), vlans) group by ip", __LINE__, __FILE__);
                    if ($db->num_rows() > 0) {
                        $db->next_record(MYSQL_ASSOC);
                        $switchIp = $db->Record['ip'];
                        $switchName = $db->Record['name'];
                        // assign ip
                        // add ip route
                        $cmds = ['config t', 'no ip route '.$ip.'/32 '.$targetIp, 'end', 'copy run st'];
                        require_once INCLUDE_ROOT.'/servers/Cisco.php';
                        myadmin_log('myadmin', 'info', 'Running on Switch '.$switchName.': '.json_encode($cmds), __LINE__, __FILE__);
                        $output = \Cisco::run($switchIp, $cmds);
                        myadmin_log('myadmin', 'info', 'Output from Switch '.$switchName.': '.json_encode($output), __LINE__, __FILE__);
                        myadmin_log('myadmin', 'info', 'Raw Output from Switch '.$switchName.': '.json_encode(Cisco::$output), __LINE__, __FILE__);
                        $GLOBALS['tf']->history->add(self::$module, $serviceInfo[$settings['PREFIX'].'_id'], 'disable', '', $serviceInfo[$settings['PREFIX'].'_custid']);
                    } else {
                        myadmin_log('myadmin', 'error', 'no ip found on switches for '.$targetIp, __LINE__, __FILE__);
                    }
                }
            })->setTerminate(function ($service) {
                $serviceInfo = $service->getServiceInfo();
                $settings = get_module_settings(self::$module);
                $serviceTypes = run_event('get_service_types', false, self::$module);
                if ($serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_type'] == get_service_define('FLOATING_IPS')) {
                    $class = '\\MyAdmin\\Orm\\'.get_orm_class_from_table($settings['TABLE']);
                    /** @var \MyAdmin\Orm\Product $class **/
                    $serviceClass = new $class();
                    $serviceClass->load_real($serviceInfo[$settings['PREFIX'].'_id']);
                    $db = get_module_db(self::$module);
                    $ip = $serviceInfo[$settings['PREFIX'].'_ip'];
                    $targetIp = $serviceInfo[$settings['PREFIX'].'_target_ip'];
                    // get switch from ip
                    $db->query("select name,ip from switchports left join switchmanager on switchmanager.id=switch where find_in_set((select ips_vlan from ips where ips_ip='{$targetIp}'), vlans) group by ip", __LINE__, __FILE__);
                    if ($db->num_rows() > 0) {
                        $db->next_record(MYSQL_ASSOC);
                        $switchIp = $db->Record['ip'];
                        $switchName = $db->Record['name'];
                        // assign ip
                        // add ip route
                        $db->query("update floating_ip_pool set pool_used=0, pool_order=null where pool_ip='{$ip}'", __LINE__, __FILE__);
                        $cmds = ['config t', 'no ip route '.$ip.'/32 '.$targetIp, 'end', 'copy run st'];
                        require_once INCLUDE_ROOT.'/servers/Cisco.php';
                        myadmin_log('myadmin', 'info', 'Running on Switch '.$switchName.': '.json_encode($cmds), __LINE__, __FILE__);
                        $output = \Cisco::run($switchIp, $cmds);
                        myadmin_log('myadmin', 'info', 'Output from Switch '.$switchName.': '.json_encode($output), __LINE__, __FILE__);
                        myadmin_log('myadmin', 'info', 'Raw Output from Switch '.$switchName.': '.json_encode(Cisco::$output), __LINE__, __FILE__);
                        $serviceClass->setServerStatus('deleted')->save();
                        $GLOBALS['tf']->history->add($settings['TABLE'], 'change_server_status', 'deleted', $serviceInfo[$settings['PREFIX'].'_id'], $serviceInfo[$settings['PREFIX'].'_custid']);
                    } else {
                        myadmin_log('myadmin', 'error', 'no ip found on switches for '.$targetIp, __LINE__, __FILE__);
                    }
                }
            })->register();
    }

    /**
     * @param \Symfony\Component\EventDispatcher\GenericEvent $event
     */
    public static function getSettings(GenericEvent $event)
    {
        /**
         * @var \MyAdmin\Settings $settings
         **/
        $settings = $event->getSubject();
        $settings->setTarget('global');
        $settings->add_dropdown_setting(self::$module, _('General'), 'outofstock_floating_ips', _('Out Of Stock Floating IPs'), _('Enable/Disable Sales Of This Type'), $settings->get_setting('OUTOFSTOCK_FLOATING_IPS'), ['0', '1'], ['No', 'Yes']);
    }
}
