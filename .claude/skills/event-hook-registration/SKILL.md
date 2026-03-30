---
name: event-hook-registration
description: Adds new Symfony EventDispatcher hooks to Plugin::getHooks() and implements GenericEvent handler static methods in src/Plugin.php. Use when registering new event listeners or adding handler statics. Triggered by: 'add hook', 'register event', 'new event handler', 'GenericEvent'. Do NOT use for modifying existing lifecycle closures inside loadProcessing().
---
# event-hook-registration

## Critical

- All handler methods MUST be `public static` and accept exactly one `GenericEvent $event` parameter — the test suite in `tests/PluginTest.php` verifies this via reflection and will fail otherwise.
- Hook keys MUST be prefixed with `self::$module.'.'` (e.g. `floating_ips.my_event`) — never hard-code the module name string.
- The `getHooks()` return array maps event name strings to `[__CLASS__, 'methodName']` two-element arrays — no closures, no instance methods.
- Every handler method MUST have a `@param \Symfony\Component\EventDispatcher\GenericEvent $event` docblock.
- Never remove or rename existing hooks — `testGetHooksReturnsThreeHooks()` counts them.

## Instructions

1. **Add the hook entry to `getHooks()`** in `src/Plugin.php`.
   Add a new key/value pair inside the returned array:
   ```php
   public static function getHooks()
   {
       return [
           self::$module.'.load_processing' => [__CLASS__, 'loadProcessing'],
           self::$module.'.settings'        => [__CLASS__, 'getSettings'],
           self::$module.'.deactivate'      => [__CLASS__, 'getDeactivate'],
           self::$module.'.your_event'      => [__CLASS__, 'yourEventHandler'],  // add here
       ];
   }
   ```
   Verify the key follows the pattern `{module}.{event_name}` before proceeding.

2. **Implement the handler method** as a `public static` method with a `GenericEvent` type-hint.
   Model after `getDeactivate()` or `getSettings()` in `src/Plugin.php` depending on what the handler does:
   ```php
   /**
    * @param \Symfony\Component\EventDispatcher\GenericEvent $event
    */
   public static function yourEventHandler(GenericEvent $event)
   {
       $serviceClass = $event->getSubject();
       $settings = get_module_settings(self::$module);
       myadmin_log(self::$module, 'info', self::$name.' YourEvent', __LINE__, __FILE__, self::$module, $serviceClass->getId());
       // handler logic
   }
   ```
   Verify `use Symfony\Component\EventDispatcher\GenericEvent;` already appears at the top of `src/Plugin.php` — it does; do not add a duplicate.

3. **Use `$event->getSubject()`** to get the payload — this returns the subject passed to `run_event()` by the caller. For service-based events it is a service class instance; for settings events it is a `\MyAdmin\Settings` instance (see `getSettings()` in `src/Plugin.php`).

4. **Log with** `myadmin_log(self::$module, 'info'|'error', $message, __LINE__, __FILE__)` for every meaningful action and every error branch.

5. **Track history** when mutating service state:
   ```php
   $GLOBALS['tf']->history->add(self::$module, $serviceClass->getId(), 'action_name', '', $serviceClass->getCustid());
   ```

6. **Run tests** to confirm the new hook is wired correctly:
   ```bash
   vendor/bin/phpunit
   ```
   The test `testGetHooksCallbacksAreValidMethodReferences()` will fail if the method name in `getHooks()` does not match the actual method name. Fix the typo if it does.

## Examples

**User says:** "Add a hook for `floating_ips.renewal` that logs the service ID."

**Actions taken:**
1. Add to `getHooks()` in `src/Plugin.php`: `self::$module.'.renewal' => [__CLASS__, 'getRenewal']`
2. Add method:
```php
/**
 * @param \Symfony\Component\EventDispatcher\GenericEvent $event
 */
public static function getRenewal(GenericEvent $event)
{
    $serviceClass = $event->getSubject();
    $settings = get_module_settings(self::$module);
    myadmin_log(self::$module, 'info', self::$name.' Renewal id='.$serviceClass->getId(), __LINE__, __FILE__, self::$module, $serviceClass->getId());
    $GLOBALS['tf']->history->add(self::$module, $serviceClass->getId(), 'renewal', '', $serviceClass->getCustid());
}
```
3. Run `vendor/bin/phpunit` — all tests pass.

**Result:** `floating_ips.renewal` is registered; `getRenewal()` fires when `run_event('floating_ips.renewal', $serviceObj, 'floating_ips')` is called.

## Common Issues

- **`testGetHooksCallbacksAreValidMethodReferences` fails with "references non-existent method: getRenewal"**: The string in `getHooks()` does not match the actual method name. Check for typos — PHP method names are case-sensitive.
- **`testAllHookHandlersAcceptGenericEvent` fails**: The new method is missing the `GenericEvent` type-hint or accepts zero/two parameters. Signature must be exactly `public static function name(GenericEvent $event)`.
- **`testGetHooksReturnsThreeHooks` fails after adding a hook**: This test hard-codes count 3. Update it in `tests/PluginTest.php`: change `$this->assertCount(3, $hooks)` to match the new total and add a corresponding `testGetHooksContainsYourEvent()` test following the pattern of `testGetHooksContainsDeactivate()` in `tests/PluginTest.php`.
- **`testPublicMethodCount` fails**: This test asserts exactly 5 public methods. Update the count assertion and add the new method name to `testExpectedPublicMethods()` in `tests/PluginTest.php`.
