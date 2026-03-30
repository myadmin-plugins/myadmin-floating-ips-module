---
name: ip-pool-allocation
description: Handles floating_ip_pool availability queries, pool_used flag updates, and pool_order tracking in src/Plugin.php. Use when modifying IP allocation logic or adding pool management queries. Triggered by: 'allocate IP', 'find free IP', 'update pool', 'pool_used', 'pool_order'. Do NOT use for switch route management (Sshwitch calls) or service status updates.
---
# ip-pool-allocation

## Critical

- Always check `pool_order` first (re-use existing assigned IP) before falling back to a free pool query — never skip the `pool_order` check or you risk double-allocating IPs.
- Never build INSERT/UPDATE strings with unescaped user input. Pool IP values come from DB rows, not user input, so direct interpolation of `$ip` is acceptable only after it was read from `floating_ip_pool`.
- `pool_used=1` is set ONLY on enable/reactivate. `pool_used=0, pool_order=null` is set ONLY on terminate. On disable, the pool row is NOT touched (route is removed but IP stays reserved).
- Always pass `__LINE__, __FILE__` as the third and fourth arguments to every `$db->query()` call.

## Instructions

1. **Get the DB handle and resolve `$ipType`.**
   ```php
   $db = get_module_db(self::$module);
   $ipType = $serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_field1'] == 'path' ? 1 : 0;
   ```
   Verify `$settings` came from `get_module_settings(self::$module)` and `$serviceTypes` from `run_event('get_service_types', false, self::$module)` before proceeding.

2. **Look up an already-assigned IP by `pool_order` (service ID).** This prevents re-allocating a different IP on reactivation.
   ```php
   $ip = false;
   $db->query("select * from floating_ip_pool where pool_order={$serviceInfo[$settings['PREFIX'].'_id']} limit 1");
   if ($db->num_rows() > 0) {
       $db->next_record(MYSQL_ASSOC);
       $ip = $db->Record['pool_ip'];
   }
   ```

3. **If no existing assignment, find the next free IP from the pool.** Only runs when Step 2 returned no rows.
   ```php
   } else {
       $db->query("select * from floating_ip_pool where pool_usable=1 and pool_used=0 and pool_type='{$ipType}' limit 1");
       if ($db->num_rows() > 0) {
           $db->next_record(MYSQL_ASSOC);
           $ip = $db->Record['pool_ip'];
       }
   }
   ```
   Verify `$ip !== false` before continuing — log an error and return early if the pool is exhausted.

4. **On allocation failure, log and abort.**
   ```php
   if ($ip === false) {
       myadmin_log('myadmin', 'error', 'no free ips in pool', __LINE__, __FILE__);
       return; // or continue, depending on context
   }
   ```

5. **On enable/reactivate: mark the pool row as used and link it to the service.**
   ```php
   $db->query("update floating_ip_pool set pool_used=1, pool_order={$serviceInfo[$settings['PREFIX'].'_id']} where pool_ip='{$ip}'", __LINE__, __FILE__);
   ```
   This step uses `$ip` from Step 2 or 3 and `$serviceInfo` from the service handler.

6. **On terminate: release the pool row.**
   ```php
   $db->query("update floating_ip_pool set pool_used=0, pool_order=null where pool_ip='{$ip}'", __LINE__, __FILE__);
   ```
   `$ip` here comes from `$serviceInfo[$settings['PREFIX'].'_ip']` (the already-stored IP on the service row), NOT from a fresh pool query.

7. **After updating the pool on enable/reactivate, update the service row status.**
   ```php
   $db->query("UPDATE {$settings['TABLE']} SET {$settings['PREFIX']}_ip='{$ip}', {$settings['PREFIX']}_status='active', {$settings['PREFIX']}_server_status='active' WHERE {$settings['PREFIX']}_id='".$serviceInfo[$settings['PREFIX'].'_id']."'", __LINE__, __FILE__);
   $GLOBALS['tf']->history->add($settings['TABLE'], 'change_status', 'active', $serviceInfo[$settings['PREFIX'].'_id'], $serviceInfo[$settings['PREFIX'].'_custid']);
   ```

## Examples

**User says:** "Add logic to allocate a floating IP when a service is enabled"

**Actions taken:**
1. Get `$db`, `$settings`, `$serviceTypes`, compute `$ipType`.
2. Query `pool_order` for the service ID → if found, reuse that IP.
3. Else query `pool_usable=1 AND pool_used=0 AND pool_type='{$ipType}'` → take first result.
4. If `$ip === false` → `myadmin_log('myadmin', 'error', 'no free ips in pool', ...)` and return.
5. Run switch route commands (separate skill).
6. `UPDATE floating_ip_pool SET pool_used=1, pool_order={$id} WHERE pool_ip='{$ip}'`
7. `UPDATE floating_ips SET floating_ip_ip='{$ip}', floating_ip_status='active', floating_ip_server_status='active' WHERE floating_ip_id='{$id}'`
8. `$GLOBALS['tf']->history->add(...)` with action `'change_status'` and value `'active'`.

**Result:** IP is reserved in the pool and recorded on the service row. See `src/Plugin.php:101-145` for the full enable closure.

## Common Issues

- **Pool query returns 0 rows unexpectedly:** Check that `pool_usable=1` rows exist with `pool_used=0` and the correct `pool_type` value (`0` for standard, `1` for path). Run: `SELECT * FROM floating_ip_pool WHERE pool_usable=1 AND pool_used=0;`
- **IP gets double-allocated on reactivation:** You skipped the `pool_order` check in Step 2. Always query by `pool_order={$serviceId}` first.
- **`pool_order` not cleared after termination:** The terminate handler must explicitly set `pool_order=null` — omitting it causes Step 2 to re-find the old (freed) IP under a new service ID.
- **`$ip` is `false` inside the disable handler:** Disable reads the IP from `$serviceInfo[$settings['PREFIX'].'_ip']`, not from a pool query. If that field is empty, the service was never fully activated; log the error and skip the pool update.
- **Query missing `__LINE__, __FILE__`:** All pool queries must pass these — the DB layer uses them for error reporting. Missing arguments cause silent failures in some environments.