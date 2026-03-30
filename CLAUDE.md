# MyAdmin Floating IPs Module

Composer plugin package for MyAdmin. Manages floating IP allocation from `floating_ip_pool` and network switch routes via SSH.

## Commands

```bash
composer install           # install deps
vendor/bin/phpunit         # run tests (config: phpunit.xml.dist)
```

## Architecture

**Entry**: `src/Plugin.php` · namespace `Detain\MyAdminFloatingIps\` · PSR-4 (`src/`)
**Module**: `floating_ips` · prefix `floating_ip` · table `floating_ips`
**Hooks**: `floating_ips.load_processing` · `floating_ips.settings` · `floating_ips.deactivate`
**Tests**: `tests/PluginTest.php` · bootstrap `tests/bootstrap.php` · config `phpunit.xml.dist`
**Test namespace**: `Detain\MyAdminFloatingIps\Tests\` → `tests/`
**CI/CD**: `.github/` contains workflows for automated testing and deployment pipelines

**DB Tables**: `floating_ips` · `floating_ip_pool` (`pool_ip`, `pool_used`, `pool_usable`, `pool_order`, `pool_type`) · `switchports` / `switchmanager` · `ips` (VLAN lookup)

**Key deps**: `symfony/event-dispatcher ^5.0` · `Detain\Sshwitch\Sshwitch` · `detain/myadmin-plugin-installer`

## Plugin Hook Pattern

`Plugin::getHooks()` maps event names to handlers. `loadProcessing` chains lifecycle closures:

```php
$service->setEnable(function ($service) { ... })
        ->setReactivate(function ($service) { ... })
        ->setDisable(function ($service) { ... })
        ->setTerminate(function ($service) { ... })
        ->register();
```

## DB Query Pattern

```php
$db = get_module_db(self::$module);
$db->query("SELECT * FROM floating_ip_pool WHERE pool_usable=1 AND pool_used=0 AND pool_type='{$ipType}' LIMIT 1", __LINE__, __FILE__);
if ($db->num_rows() > 0) {
    $db->next_record(MYSQL_ASSOC);
    $ip = $db->Record['pool_ip'];
}
// Update pool state
$db->query("UPDATE floating_ip_pool SET pool_used=1, pool_order={$id} WHERE pool_ip='{$ip}'", __LINE__, __FILE__);
```

## Switch Route Management

```php
// Add route (enable/reactivate)
$cmds = ['config t', 'ip route '.$ip.'/32 '.$targetIp, 'end', 'copy run st'];
// Remove route (disable/terminate)
$cmds = ['config t', 'no ip route '.$ip.'/32 '.$targetIp, 'end', 'copy run st'];
$output = Sshwitch::run($switchIp, $cmds);
myadmin_log('myadmin', 'info', 'Output from Switch '.$switchName.': '.json_encode($output), __LINE__, __FILE__);
myadmin_log('myadmin', 'info', 'Raw Output: '.json_encode(Sshwitch::$output), __LINE__, __FILE__);
```

## Switch Lookup Query

```php
$db->query("SELECT name,ip FROM switchports LEFT JOIN switchmanager ON switchmanager.id=switch WHERE find_in_set((SELECT ips_vlan FROM ips WHERE ips_ip='{$targetIp}'), vlans) GROUP BY ip", __LINE__, __FILE__);
if ($db->num_rows() > 0) {
    $db->next_record(MYSQL_ASSOC);
    $switchIp = $db->Record['ip'];
    $switchName = $db->Record['name'];
}
```

## Conventions

- Log: `myadmin_log(self::$module, 'info'|'error', $msg, __LINE__, __FILE__)`
- History: `$GLOBALS['tf']->history->add($table, $serviceId, $action, '', $custId)`
- Pool: set `pool_used=1` on enable/reactivate; `pool_used=0, pool_order=null` on terminate
- Guard with `get_service_define('FLOATING_IPS')` before acting on service type
- Never use PDO — always `get_module_db(self::$module)`
- Escape user input: `$db->real_escape($val)`
- Settings keys in `Plugin::$settings`: `SERVICE_ID_OFFSET=1100`, `PREFIX='floating_ip'`, `TABLE='floating_ips'`, `TITLE_FIELD='floating_ip_ip'`, `TITLE_FIELD2='floating_ip_target_ip'`

<!-- caliber:managed:pre-commit -->
## Before Committing

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CLAUDE.md .claude/ .cursor/ .cursorrules .github/copilot-instructions.md .github/instructions/ AGENTS.md CALIBER_LEARNINGS.md 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

If `caliber` is not found, tell the user: "This project uses Caliber for agent config sync. Run /setup-caliber to get set up."
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->
