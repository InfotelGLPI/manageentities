<?php

/**
 * Minimal stubs for GLPI core base classes.
 *
 * GLPI core is not a Composer package, so the psalm-github-security-scan
 * container (which only runs `composer install`) has no class storage for the
 * classes the plugin extends. When Psalm scans a plugin class such as CriType
 * (extends CommonDropdown) and analyses a dynamic `->fields` access, it walks
 * the parent chain looking for #[AllowDynamicProperties]; reaching the unknown
 * parent throws "Could not get class storage for commondropdown" and Psalm
 * crashes.
 *
 * These empty stubs give Psalm the storage it needs to resolve every plugin
 * parent chain. #[AllowDynamicProperties] + a public $fields property keep the
 * dynamic property access (`$obj->fields[...]`) clean instead of erroring.
 *
 * Only classes that plugin classes directly extend are declared here — that is
 * enough to stop the crash. Other GLPI classes referenced from method bodies
 * remain unknown (UndefinedClass, suppressed in psalm.xml) but never crash.
 */

#[\AllowDynamicProperties]
class CommonGLPI
{
}

#[\AllowDynamicProperties]
class CommonDBTM extends CommonGLPI
{
    /** @var array<string, mixed> */
    public $fields = [];
}

#[\AllowDynamicProperties]
class CommonDropdown extends CommonDBTM
{
}

#[\AllowDynamicProperties]
class CommonDBChild extends CommonDBTM
{
}

#[\AllowDynamicProperties]
class CommonDBRelation extends CommonDBTM
{
}

#[\AllowDynamicProperties]
class NotificationTarget extends CommonDBChild
{
}

#[\AllowDynamicProperties]
class Dropdown extends CommonDBTM
{
}

#[\AllowDynamicProperties]
class Profile extends CommonDBTM
{
}

#[\AllowDynamicProperties]
class TCPDF
{
}

// Provided by the optional "datainjection" plugin, absent from the scan container.
interface PluginDatainjectionInjectionInterface
{
}
