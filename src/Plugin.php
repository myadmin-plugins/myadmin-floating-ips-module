<?php

namespace Detain\MyAdminFloatingIps;

use Symfony\Component\EventDispatcher\GenericEvent;
use Detain\Sshwitch\Sshwitch;

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
     * Service type of the Floating IP + Path.net package: the only type whose ip
     * carries Path.net filters and firewall rules.
     */
    public const PATHNET_SERVICE_TYPE = 11461;

    /**
     * Builds the Path.net API client for cleanupPathNet(); tests swap in a double.
     * @var callable|null fn(): object with rules(), getFilter(), deleteRule(), deleteFilter()
     */
    public static $pathNetFactory = null;

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
        $settings = get_module_settings(self::$module);
        myadmin_log(self::$module, 'info', self::$name.' Deactivation', __LINE__, __FILE__, self::$module, $serviceClass->getId());
        if ($serviceTypes[$serviceClass->getType()]['services_type'] == get_service_define('FLOATING_IPS')) {
            $db = get_module_db(self::$module);
            $ip = $serviceClass->getIp();
            $targetIp = $serviceClass->getTargetIp();
            // get switch from ip
            $db->query("select name,ip from switchports left join switchmanager on switchmanager.id=switch where find_in_set((select ips_vlan from ips where ips_ip='{$targetIp}'), vlans) group by ip", __LINE__, __FILE__);
            if ($db->num_rows() > 0) {
                $db->next_record(MYSQL_ASSOC);
                $switchIp = $db->Record['ip'];
                $switchName = $db->Record['name'];
                // assign ip
                // add ip route
                $cmds = ['config t', 'no ip route '.$ip.'/32 '.$targetIp, 'end', 'copy run st'];
                myadmin_log('myadmin', 'info', 'Running on Switch '.$switchName.': '.json_encode($cmds), __LINE__, __FILE__);
                $output = Sshwitch::run($switchIp, $cmds);
                myadmin_log('myadmin', 'info', 'Output from Switch '.$switchName.': '.json_encode($output), __LINE__, __FILE__);
                myadmin_log('myadmin', 'info', 'Raw Output from Switch '.$switchName.': '.json_encode(Sshwitch::$output), __LINE__, __FILE__);
                \MyAdmin\App::history()->add(self::$module, $serviceClass->getId(), 'disable', '', $serviceClass->getCustid());
            } else {
                myadmin_log('myadmin', 'error', 'no ip found on switches for '.$targetIp, __LINE__, __FILE__);
            }
            if ($serviceClass->getType() == self::PATHNET_SERVICE_TYPE) {
                // the ip goes back to the pool: its next owner must not inherit this customer's Path.net rules
                self::cleanupPathNet($serviceClass->getId(), $ip);
            }
        }
    }

    /**
     * Deletes every Path.net firewall rule and filter for a canceled floating ip.
     *
     * Runs from getDeactivate(), once per cancel. It never throws and never stops
     * the cancel: a failure is logged and posted to chat for staff to clean up by
     * hand. The rules and filters are listed for the ip and deleted one by one
     * through the same per-id calls the client page uses (rules whose destination
     * is not this ip are left alone). PathNet::deleteRuleByIp() is not used: it
     * sends DELETE /rules, a route the Path.net API does not define (its bulk
     * delete is DELETE /rules/), and an empty ip in either bulk delete would be
     * an account-wide filter.
     *
     * @param int|string $serviceId floating ip service id (for the logs)
     * @param string     $ip        the floating ip
     * @return bool true when everything listed was deleted
     */
    public static function cleanupPathNet($serviceId, $ip)
    {
        $ip = is_string($ip) ? trim($ip) : '';
        $label = 'Floating IP '.$serviceId.' ('.$ip.')';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            myadmin_log(self::$module, 'error', 'Path.net cleanup skipped for floating ip '.$serviceId.': invalid ip '.json_encode($ip), __LINE__, __FILE__, self::$module, $serviceId);
            chatNotify($label.' canceled but its Path.net rules/filters were not cleaned up: invalid ip', 'notifications');
            return false;
        }
        $deleted = 0;
        $failed = [];
        try {
            if (self::$pathNetFactory !== null) {
                $pathNet = call_user_func(self::$pathNetFactory);
            } else {
                function_requirements('class.PathNet');
                $pathNet = new \PathNet();
            }
            $rules = $pathNet->rules($ip);
            $filters = $pathNet->getFilter($ip);
            if (!isset($rules['rules']) || !is_array($rules['rules']) || !isset($filters['filters']) || !is_array($filters['filters'])) {
                $failed[] = 'listing failed (rules: '.json_encode($rules).' filters: '.json_encode($filters).')';
            } else {
                foreach ($rules['rules'] as $rule) {
                    $ruleId = is_array($rule) ? trim((string) ($rule['id'] ?? '')) : '';
                    $destination = is_array($rule) ? (string) ($rule['destination'] ?? '') : '';
                    if ($ruleId === '' || ($destination !== $ip && $destination !== $ip.'/32')) {
                        continue;
                    }
                    $response = $pathNet->deleteRule($ruleId);
                    if (isset($response['acknowledged'])) {
                        $deleted++;
                    } else {
                        $failed[] = 'rule '.$ruleId.': '.json_encode($response);
                    }
                }
                foreach ($filters['filters'] as $filter) {
                    $filterId = is_array($filter) ? trim((string) ($filter['id'] ?? '')) : '';
                    $filterName = is_array($filter) ? trim((string) ($filter['name'] ?? '')) : '';
                    if ($filterId === '' || $filterName === '') {
                        continue;
                    }
                    $response = $pathNet->deleteFilter($filterName, $filterId);
                    if (isset($response['acknowledged'])) {
                        $deleted++;
                    } else {
                        $failed[] = 'filter '.$filterName.'/'.$filterId.': '.json_encode($response);
                    }
                }
            }
        } catch (\Throwable $e) {
            $failed[] = get_class($e).': '.$e->getMessage();
        }
        if (empty($failed)) {
            myadmin_log(self::$module, 'info', 'Path.net cleanup for floating ip '.$serviceId.' ('.$ip.') removed '.$deleted.' rule(s)/filter(s)', __LINE__, __FILE__, self::$module, $serviceId);
            return true;
        }
        myadmin_log(self::$module, 'error', 'Path.net cleanup for floating ip '.$serviceId.' ('.$ip.') removed '.$deleted.', failed: '.implode('; ', $failed), __LINE__, __FILE__, self::$module, $serviceId);
        chatNotify($label.' canceled but its Path.net cleanup did not finish ('.$deleted.' removed; '.implode('; ', $failed).') - please remove what is left by hand', 'notifications');
        return false;
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
                $db = get_module_db(self::$module);
                $ipType = $serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_field1'] == 'path' ? 1 : 0;
                if ($serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_type'] == get_service_define('FLOATING_IPS')) {
                    // pick an ip from ip pool
                    $ip = false;
                    $db->query("select * from floating_ip_pool where pool_order={$serviceInfo[$settings['PREFIX'].'_id']} limit 1");
                    if ($db->num_rows() > 0) {
                        $db->next_record(MYSQL_ASSOC);
                        $ip = $db->Record['pool_ip'];
                    } else {
                        $db->query("select * from floating_ip_pool where pool_usable=1 and pool_used=0 and pool_type='{$ipType}' limit 1");
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
                            myadmin_log('myadmin', 'info', 'Running on Switch '.$switchName.': '.json_encode($cmds), __LINE__, __FILE__);
                            $output = Sshwitch::run($switchIp, $cmds);
                            myadmin_log('myadmin', 'info', 'Output from Switch '.$switchName.': '.json_encode($output), __LINE__, __FILE__);
                            myadmin_log('myadmin', 'info', 'Raw Output from Switch '.$switchName.': '.json_encode(Sshwitch::$output), __LINE__, __FILE__);
                            $db->query("update floating_ip_pool set pool_used=1, pool_order={$serviceInfo[$settings['PREFIX'].'_id']} where pool_ip='{$ip}'", __LINE__, __FILE__);
                            $db->query("UPDATE {$settings['TABLE']} SET {$settings['PREFIX']}_ip='{$ip}', {$settings['PREFIX']}_status='active', {$settings['PREFIX']}_server_status='active' WHERE {$settings['PREFIX']}_id='".$serviceInfo[$settings['PREFIX'].'_id']."'", __LINE__, __FILE__);
                            \MyAdmin\App::history()->add($settings['TABLE'], 'change_status', 'active', $serviceInfo[$settings['PREFIX'].'_id'], $serviceInfo[$settings['PREFIX'].'_custid']);
                        } else {
                            myadmin_log('myadmin', 'error', 'no ip found on switches for '.$targetIp, __LINE__, __FILE__);
                            chatNotify('Failed [Floating IP '.$serviceInfo[$settings['PREFIX'].'_id'].'](https://my.interserver.net/admin/view_floating_ip?id='.$serviceInfo[$settings['PREFIX'].'_id'].') Activation - assigned pool IP '.$ip.' but no switch found in switchports for target IP '.$targetIp.' (cannot add route)', 'notifications');
                        }
                    } else {
                        myadmin_log('myadmin', 'error', 'no free ips in pool', __LINE__, __FILE__);
                        chatNotify('Failed [Floating IP '.$serviceInfo[$settings['PREFIX'].'_id'].'](https://my.interserver.net/admin/view_floating_ip?id='.$serviceInfo[$settings['PREFIX'].'_id'].') Activation - no free IPs in floating_ip_pool (type='.$ipType.', target='.$targetIp.')', 'notifications');
                    }
                }
            })->setReactivate(function ($service) {
                $serviceTypes = run_event('get_service_types', false, self::$module);
                $serviceInfo = $service->getServiceInfo();
                $settings = get_module_settings(self::$module);
                $db = get_module_db(self::$module);
                $ipType = $serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_field1'] == 'path' ? 1 : 0;
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
                        $db->query("select * from floating_ip_pool where pool_usable=1 and pool_used=0 and pool_type='{$ipType}' limit 1");
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
                            myadmin_log('myadmin', 'info', 'Running on Switch '.$switchName.': '.json_encode($cmds), __LINE__, __FILE__);
                            $output = Sshwitch::run($switchIp, $cmds);
                            myadmin_log('myadmin', 'info', 'Output from Switch '.$switchName.': '.json_encode($output), __LINE__, __FILE__);
                            myadmin_log('myadmin', 'info', 'Raw Output from Switch '.$switchName.': '.json_encode(Sshwitch::$output), __LINE__, __FILE__);
                            $db->query("update floating_ip_pool set pool_used=1, pool_order={$serviceInfo[$settings['PREFIX'].'_id']} where pool_ip='{$ip}'", __LINE__, __FILE__);
                            $db->query("UPDATE {$settings['TABLE']} SET {$settings['PREFIX']}_ip='{$ip}', {$settings['PREFIX']}_status='active', {$settings['PREFIX']}_server_status='active' WHERE {$settings['PREFIX']}_id='".$serviceInfo[$settings['PREFIX'].'_id']."'", __LINE__, __FILE__);
                            \MyAdmin\App::history()->add($settings['TABLE'], 'change_status', 'active', $serviceInfo[$settings['PREFIX'].'_id'], $serviceInfo[$settings['PREFIX'].'_custid']);
                        } else {
                            myadmin_log('myadmin', 'error', 'no ip found on switches for '.$targetIp, __LINE__, __FILE__);
                            chatNotify('Failed [Floating IP '.$serviceInfo[$settings['PREFIX'].'_id'].'](https://my.interserver.net/admin/view_floating_ip?id='.$serviceInfo[$settings['PREFIX'].'_id'].') Reactivation - assigned pool IP '.$ip.' but no switch found in switchports for target IP '.$targetIp.' (cannot add route)', 'notifications');
                        }
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
                        myadmin_log('myadmin', 'info', 'Running on Switch '.$switchName.': '.json_encode($cmds), __LINE__, __FILE__);
                        $output = Sshwitch::run($switchIp, $cmds);
                        myadmin_log('myadmin', 'info', 'Output from Switch '.$switchName.': '.json_encode($output), __LINE__, __FILE__);
                        myadmin_log('myadmin', 'info', 'Raw Output from Switch '.$switchName.': '.json_encode(Sshwitch::$output), __LINE__, __FILE__);
                        \MyAdmin\App::history()->add(self::$module, $serviceInfo[$settings['PREFIX'].'_id'], 'disable', '', $serviceInfo[$settings['PREFIX'].'_custid']);
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
                        myadmin_log('myadmin', 'info', 'Running on Switch '.$switchName.': '.json_encode($cmds), __LINE__, __FILE__);
                        $output = Sshwitch::run($switchIp, $cmds);
                        myadmin_log('myadmin', 'info', 'Output from Switch '.$switchName.': '.json_encode($output), __LINE__, __FILE__);
                        myadmin_log('myadmin', 'info', 'Raw Output from Switch '.$switchName.': '.json_encode(Sshwitch::$output), __LINE__, __FILE__);
                        $serviceClass->setServerStatus('deleted')->save();
                        \MyAdmin\App::history()->add($settings['TABLE'], 'change_server_status', 'deleted', $serviceInfo[$settings['PREFIX'].'_id'], $serviceInfo[$settings['PREFIX'].'_custid']);
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
        $settings->add_text_setting(self::$module, _('Path.Net'), 'pathnet_username', _('Path.Net Username'), _('Path.Net Username'), $settings->get_setting('PATHNET_USERNAME'));
        $settings->add_password_setting(self::$module, _('Path.Net'), 'pathnet_password', _('Path.Net Password'), _('Path.Net Password'), $settings->get_setting('PATHNET_PASSWORD'));
    }
}
