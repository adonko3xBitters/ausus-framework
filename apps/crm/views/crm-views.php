<?php

declare(strict_types=1);

use Ausus\View\PageDefinition;
use Ausus\View\SectionDefinition;
use Ausus\View\ViewDefinition;
use Ausus\View\ViewRegistry;

// CRM views — pure presentation metadata, assembled from the existing View
// System. No React, no compile, no business logic: each section just points at
// an entity's projection or action exposed by the api-runtime.

$registry = new ViewRegistry();

$registry->register(new ViewDefinition('crm-dashboard', 'CRM Dashboard', [
    new PageDefinition('dashboard', 'Dashboard', [
        SectionDefinition::projection('Opportunities Pipeline', 'opportunity', 'pipeline'),
        SectionDefinition::projection('Tasks Board', 'task', 'board'),
    ]),
]));

$registry->register(new ViewDefinition('customers', 'Customers', [
    new PageDefinition('customers', 'Customers', [
        SectionDefinition::projection('Customers Board', 'customer', 'board'),
        SectionDefinition::action('Create Customer', 'customer', 'create'),
    ]),
]));

$registry->register(new ViewDefinition('sales', 'Sales', [
    new PageDefinition('sales', 'Sales', [
        SectionDefinition::projection('Pipeline', 'opportunity', 'pipeline'),
        SectionDefinition::action('Create Opportunity', 'opportunity', 'create'),
    ]),
]));

$registry->register(new ViewDefinition('activities', 'Activities', [
    new PageDefinition('activities', 'Activities', [
        SectionDefinition::projection('Activities Board', 'activity', 'board'),
        SectionDefinition::action('Create Activity', 'activity', 'create'),
    ]),
]));

$registry->register(new ViewDefinition('administration', 'Administration', [
    new PageDefinition('administration', 'Administration', [
        SectionDefinition::projection('Users Board', 'user', 'board'),
        SectionDefinition::action('Create User', 'user', 'create'),
    ]),
]));

return $registry;
