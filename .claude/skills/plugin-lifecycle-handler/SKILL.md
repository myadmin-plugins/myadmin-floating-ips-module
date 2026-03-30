---
name: plugin-lifecycle-handler
description: Implements the enable/reactivate/disable/terminate closure chain in loadProcessing for `src/Plugin.php`. Use when adding or modifying service lifecycle behavior (provisioning, suspension, reactivation, termination). Triggered by: 'add lifecycle handler', 'implement enable', 'handle termination', 'modify reactivate', 'add disable logic'. Do NOT use for adding new event hooks to getHooks() — use event-hook-registration instead.
---
# plugin-lifecycle-handler

## Critical

- All four closures (`setEnable`, `setReactivate`, `setDisable`, `setTerminate`) must be chained and terminated with `->register()` — omitting `register()` silently skips all lifecycle handling.
- Always guard the closure body with a service-type check: `if ($serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_type'] == get_service_define('FLOATING_IPS'))` before any DB or switch operation.
- Never use PDO. Always use `$db = get_module_db(self::$module)` and pass `__LINE__, __FILE__` to every `$db->query()` call.
- Never interpolate raw user/external input into queries — escape with `$db->real_escape()` or use trusted internal values only.

## Instructions

1. **Open `src/Plugin.php`** and locate `loadProcessing(GenericEvent $event)`. The chain starts at `$service->setModule(self::$module)`.

2. **Bootstrap each closure identically** — fetch the same four locals at the top:
   ```php
   $serviceInfo = $service->getServiceInfo();
   $settings    = get_module_settings(self::$module);
   $serviceTypes = run_event('get_service_types', false, self::$module);
   $db          = get_module_db(self::$module);
   ```
   Verify `$settings['PREFIX']` and `$settings['TABLE']` are available before using them.

3. **`setEnable` — allocate IP from pool, add switch route, mark active:**
   - First try to reuse an existing pool entry: `SELECT * FROM floating_ip_pool WHERE pool_order={$id} LIMIT 1`
   - Fallback: `SELECT * FROM floating_ip_pool WHERE pool_usable=1 AND pool_used=0 AND pool_type='{$ipType}' LIMIT 1`
   - On pool miss: `myadmin_log(self::$module, 'error', 'no free ips in pool', __LINE__, __FILE__)` and return.
   - Look up switch: see Step 5.
   - Add route commands: `['config t', 'ip route '.$ip.'/32 '.$targetIp, 'end', 'copy run st']`
   - After `Sshwitch::run()`: update pool (`pool_used=1, pool_order={$id}`), update service row (`_ip`, `_status='active'`, `_server_status='active'`), add history entry.

4. **`setReactivate` — same IP allocation + route add as enable**, plus:
   - Instantiate the ORM class: `$class = '\\MyAdmin\\Orm\\'.get_orm_class_from_table($settings['TABLE']); $serviceClass = new $class(); $serviceClass->load_real($id);`
   - After switch ops, send admin email via `\MyAdmin\Mail()->adminMail($subject, $email, false, 'admin/backup_reactivated.tpl')`.

5. **Switch lookup pattern (shared by all closures):**
   ```php
   $db->query("select name,ip from switchports left join switchmanager on switchmanager.id=switch where find_in_set((select ips_vlan from ips where ips_ip='{$targetIp}'), vlans) group by ip", __LINE__, __FILE__);
   if ($db->num_rows() > 0) {
       $db->next_record(MYSQL_ASSOC);
       $switchIp   = $db->Record['ip'];
       $switchName = $db->Record['name'];
   } else {
       myadmin_log('myadmin', 'error', 'no ip found on switches for '.$targetIp, __LINE__, __FILE__);
   }
   ```
   Verify `$db->num_rows() > 0` before calling `$db->next_record()`.

6. **After every `Sshwitch::run()` call**, log three lines in this exact order:
   ```php
   myadmin_log('myadmin', 'info', 'Running on Switch '.$switchName.': '.json_encode($cmds), __LINE__, __FILE__);
   $output = Sshwitch::run($switchIp, $cmds);
   myadmin_log('myadmin', 'info', 'Output from Switch '.$switchName.': '.json_encode($output), __LINE__, __FILE__);
   myadmin_log('myadmin', 'info', 'Raw Output from Switch '.$switchName.': '.json_encode(Sshwitch::$output), __LINE__, __FILE__);
   ```

7. **`setDisable` — remove route, add history:**
   - Route commands: `['config t', 'no ip route '.$ip.'/32 '.$targetIp, 'end', 'copy run st']`
   - History: `$GLOBALS['tf']->history->add(self::$module, $serviceInfo[$settings['PREFIX'].'_id'], 'disable', '', $serviceInfo[$settings['PREFIX'].'_custid'])`
   - Do NOT free the pool entry on disable (only on terminate).

8. **`setTerminate` — remove route, free pool, mark deleted:**
   - Free pool first: `UPDATE floating_ip_pool SET pool_used=0, pool_order=null WHERE pool_ip='{$ip}'`
   - Remove route (same `no ip route` commands as disable).
   - Mark deleted: `$serviceClass->setServerStatus('deleted')->save()`
   - History action: `'change_server_status'` with value `'deleted'`.

9. **End the chain with `->register()`** — no semicolons between chained calls.

10. **Run tests:** `vendor/bin/phpunit` — all tests must pass before committing.

## Examples

**User says:** "Add terminate handling to release the pool IP and remove the switch route."

**Actions taken:**
- Read `src/Plugin.php`, locate `setTerminate` closure inside `loadProcessing`.
- Add ORM load, pool free query, switch lookup, `no ip route` commands, `Sshwitch::run()` with three log lines, `setServerStatus('deleted')->save()`, history entry.
- Verify chain still ends with `->register()`.
- Run `vendor/bin/phpunit`.

**Result:**
```php
->setTerminate(function ($service) {
    $serviceInfo  = $service->getServiceInfo();
    $settings     = get_module_settings(self::$module);
    $serviceTypes = run_event('get_service_types', false, self::$module);
    if ($serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_type'] == get_service_define('FLOATING_IPS')) {
        $class = '\\MyAdmin\\Orm\\'.get_orm_class_from_table($settings['TABLE']);
        $serviceClass = new $class();
        $serviceClass->load_real($serviceInfo[$settings['PREFIX'].'_id']);
        $db        = get_module_db(self::$module);
        $ip        = $serviceInfo[$settings['PREFIX'].'_ip'];
        $targetIp  = $serviceInfo[$settings['PREFIX'].'_target_ip'];
        $db->query("select name,ip from switchports left join switchmanager on switchmanager.id=switch where find_in_set((select ips_vlan from ips where ips_ip='{$targetIp}'), vlans) group by ip", __LINE__, __FILE__);
        if ($db->num_rows() > 0) {
            $db->next_record(MYSQL_ASSOC);
            $switchIp   = $db->Record['ip'];
            $switchName = $db->Record['name'];
            $db->query("update floating_ip_pool set pool_used=0, pool_order=null where pool_ip='{$ip}'", __LINE__, __FILE__);
            $cmds = ['config t', 'no ip route '.$ip.'/32 '.$targetIp, 'end', 'copy run st'];
            myadmin_log('myadmin', 'info', 'Running on Switch '.$switchName.': '.json_encode($cmds), __LINE__, __FILE__);
            $output = Sshwitch::run($switchIp, $cmds);
            myadmin_log('myadmin', 'info', 'Output from Switch '.$switchName.': '.json_encode($output), __LINE__, __FILE__);
            myadmin_log('myadmin', 'info', 'Raw Output from Switch '.$switchName.': '.json_encode(Sshwitch::$output), __LINE__, __FILE__);
            $serviceClass->setServerStatus('deleted')->save();
            $GLOBALS['tf']->history->add($settings['TABLE'], 'change_server_status', 'deleted', $serviceInfo[$settings['PREFIX'].'_id'], $serviceInfo[$settings['PREFIX'].'_custid']);
        } else {
            myadmin_log('myadmin', 'error', 'no ip found on switches for '.$targetIp, __LINE__, __FILE__);
        }
    }
})->register();
```

## Common Issues

- **Lifecycle never fires:** Missing `->register()` at the end of the chain. Check that the last closure ends with `})->register();` not `});`.
- **`Undefined variable: $serviceTypes` inside disable closure:** `run_event('get_service_types', ...)` was not called inside that closure. Each closure has its own scope — redeclare all four locals at the top of each one.
- **Pool IP never freed after terminate:** `pool_used=0, pool_order=null` update must use `pool_order=null` (not `pool_order=0`); otherwise the next `pool_order={$id}` lookup finds stale data.
- **Switch lookup returns no rows:** The target IP has no VLAN entry in the `ips` table (`ips_vlan` is null). Log `'no ip found on switches for '.$targetIp` at `error` level and return without calling `Sshwitch::run()`.
- **`Call to undefined method Sshwitch::run()`:** Missing `use Detain\Sshwitch\Sshwitch;` at the top of `Plugin.php`.
- **Test failure `Class 'TFSmarty' not found`:** Only `setReactivate` uses `TFSmarty`; ensure `tests/bootstrap.php` stubs or autoloads it, or guard with `if (class_exists('TFSmarty'))`.