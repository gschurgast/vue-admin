<?php

namespace App\Attribute;

use Attribute;

/**
 * Defines the menu group for a resource in the PWA navigation.
 *
 * Usage:
 *   #[MenuGroup('Catalog')]  - Groups resource under "Catalog" section
 *   #[MenuGroup('Settings')] - Groups resource under "Settings" section
 *   #[MenuGroup('hidden')]   - Hides resource from navigation menu
 */
#[Attribute(Attribute::TARGET_CLASS)]
class MenuGroup
{
    public function __construct(
        public readonly string $group
    ) {}
}
