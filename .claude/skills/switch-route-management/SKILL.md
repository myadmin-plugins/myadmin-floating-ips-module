---
name: switch-route-management
description: Codifies SSH route add/remove via `Sshwitch::run()` with the 4-command sequence (`config t`, `ip route`/`no ip route`, `end`, `copy run st`), including switch lookup and 3-line log block. Use when modifying or adding switch route operations in `src/Plugin.php`. Triggered by: 'add switch route', 'remove route', 'update network switch', 'Sshwitch', 'enable ip route', 'disable ip route'. Do NOT use for IP pool queries, pool allocation, or status updates.
---
# Switch Route Management

## Critical

- **Always** look up the switch via the `switchports`/`switchmanager` JOIN before calling `Sshwitch::run()`. Never hardcode a switch IP.
- **Never** call `Sshwitch::run()` if the switch lookup returns 0 rows — log an error and bail.
- Log **three lines** after every `Sshwitch::run()` call: commands sent, `$output`, and `Sshwitch::$output`. Missing any log line is a bug.
- The `use Detain\Sshwitch\Sshwitch;` import must be present at the top of `src/Plugin.php`.

## Instructions

1. **Look up the switch for a target IP.** Run this exact query (lowercase SQL, `__LINE__`, `__FILE__` args):
   ```php
   $db->query("select name,ip from switchports left join switchmanager on switchmanager.id=switch where find_in_set((select ips_vlan from ips where ips_ip='{$targetIp}'), vlans) group by ip", __LINE__, __FILE__);
   ```
   Verify `$db->num_rows() > 0` before proceeding. On 0 rows:
   ```php
   myadmin_log('myadmin', 'error', 'no ip found on switches for '.$targetIp, __LINE__, __FILE__);
   ```
   then return/skip — do not call `Sshwitch::run()`.

2. **Extract switch credentials** (uses output of Step 1):
   ```php
   $db->next_record(MYSQL_ASSOC);
   $switchIp   = $db->Record['ip'];
   $switchName = $db->Record['name'];
   ```

3. **Build the command array.** Use one of two forms — never mix:
   ```php
   // Add route (enable / reactivate)
   $cmds = ['config t', 'ip route '.$ip.'/32 '.$targetIp, 'end', 'copy run st'];

   // Remove route (disable / terminate / deactivate)
   $cmds = ['config t', 'no ip route '.$ip.'/32 '.$targetIp, 'end', 'copy run st'];
   ```
   `$ip` is the floating IP (from pool or service record). `$targetIp` is the customer's target IP.

4. **Log commands, run, then log both output forms** (uses output of Steps 2–3):
   ```php
   myadmin_log('myadmin', 'info', 'Running on Switch '.$switchName.': '.json_encode($cmds), __LINE__, __FILE__);
   $output = Sshwitch::run($switchIp, $cmds);
   myadmin_log('myadmin', 'info', 'Output from Switch '.$switchName.': '.json_encode($output), __LINE__, __FILE__);
   myadmin_log('myadmin', 'info', 'Raw Output from Switch '.$switchName.': '.json_encode(Sshwitch::$output), __LINE__, __FILE__);
   ```
   All three log lines are required. The third uses the static `Sshwitch::$output`, not the return value.

5. **Record history** after a successful run (action string matches the lifecycle: `'disable'`, `'enable'`, etc.):
   ```php
   $GLOBALS['tf']->history->add(self::$module, $serviceId, 'disable', '', $custId);
   ```

## Examples

**User says:** "Add a remove-route step to the terminate handler."

**Actions taken:**
1. Confirm `$ip` and `$targetIp` are set from `$serviceInfo`.
2. Run switch lookup query against `$targetIp`.
3. On success, build remove-route command array and execute the full 4-step block:

```php
$db->query("select name,ip from switchports left join switchmanager on switchmanager.id=switch where find_in_set((select ips_vlan from ips where ips_ip='{$targetIp}'), vlans) group by ip", __LINE__, __FILE__);
if ($db->num_rows() > 0) {
    $db->next_record(MYSQL_ASSOC);
    $switchIp   = $db->Record['ip'];
    $switchName = $db->Record['name'];
    $cmds = ['config t', 'no ip route '.$ip.'/32 '.$targetIp, 'end', 'copy run st'];
    myadmin_log('myadmin', 'info', 'Running on Switch '.$switchName.': '.json_encode($cmds), __LINE__, __FILE__);
    $output = Sshwitch::run($switchIp, $cmds);
    myadmin_log('myadmin', 'info', 'Output from Switch '.$switchName.': '.json_encode($output), __LINE__, __FILE__);
    myadmin_log('myadmin', 'info', 'Raw Output from Switch '.$switchName.': '.json_encode(Sshwitch::$output), __LINE__, __FILE__);
    $GLOBALS['tf']->history->add(self::$module, $serviceInfo[$settings['PREFIX'].'_id'], 'disable', '', $serviceInfo[$settings['PREFIX'].'_custid']);
} else {
    myadmin_log('myadmin', 'error', 'no ip found on switches for '.$targetIp, __LINE__, __FILE__);
}
```

**Result:** Route removed from switch; all three log lines written; history entry recorded.

## Common Issues

- **`Class 'Detain\Sshwitch\Sshwitch' not found`**: `use Detain\Sshwitch\Sshwitch;` is missing at the top of `src/Plugin.php`, or `detain/sshwitch` is not in `composer.json`. Run `composer install`.
- **Switch lookup returns 0 rows**: The `$targetIp` is not in the `ips` table or has no matching VLAN in `switchmanager.vlans`. Verify with: `SELECT ips_vlan FROM ips WHERE ips_ip='<targetIp>'` then `SELECT vlans FROM switchmanager WHERE find_in_set('<vlan>', vlans)`.
- **`Sshwitch::$output` is null after run**: `$output = Sshwitch::run(...)` returned but the static property wasn't populated — confirm the `Sshwitch` package version matches `composer.json` (`symfony/event-dispatcher ^5.0` peer).
- **Route not removed after disable**: Verify the command used `'no ip route'` not `'ip route'`, and that `$ip` came from `$serviceInfo[$settings['PREFIX'].'_ip']` (the assigned IP), not the pool query.
- **History not recorded**: `$GLOBALS['tf']->history->add()` call is missing or placed outside the `if ($db->num_rows() > 0)` block — it must only run after a successful switch operation.