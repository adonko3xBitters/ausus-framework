<?php

declare(strict_types=1);

use Ausus\View\PageDefinition;
use Ausus\View\SectionDefinition;
use Ausus\View\ViewDefinition;
use Ausus\View\ViewRegistry;

// Teranga PMS views — pure presentation metadata over existing projections/actions.
// NB: "Arrivals" / "Departures" point at the same flat boards as "Reservations":
// the read() params (date/status filters) are not applied by the runtime, so the
// three boards cannot yet be filtered apart (documented limitation, layer: Runtime).

$registry = new ViewRegistry();

$registry->register(new ViewDefinition('dashboard', 'Dashboard', [
    new PageDefinition('dashboard', 'Dashboard', [
        SectionDefinition::projection('Reservations Board', 'reservation', 'board'),
        SectionDefinition::projection('Arrivals Board', 'reservation', 'board'),
        SectionDefinition::projection('Departures Board', 'stay', 'board'),
    ]),
]));

$registry->register(new ViewDefinition('front-desk', 'Front Desk', [
    new PageDefinition('front-desk', 'Front Desk', [
        SectionDefinition::projection('Guests', 'guest', 'board'),
        SectionDefinition::projection('Reservations', 'reservation', 'board'),
        SectionDefinition::action('Check-In', 'stay', 'checkIn'),
        SectionDefinition::action('Check-Out', 'stay', 'checkOut'),
    ]),
]));

$registry->register(new ViewDefinition('housekeeping', 'Housekeeping', [
    new PageDefinition('housekeeping', 'Housekeeping', [
        SectionDefinition::projection('Rooms', 'room', 'board'),
        SectionDefinition::projection('Tasks', 'housekeepingtask', 'board'),
    ]),
]));

$registry->register(new ViewDefinition('billing', 'Billing', [
    new PageDefinition('billing', 'Billing', [
        SectionDefinition::projection('Invoices', 'invoice', 'board'),
        SectionDefinition::projection('Payments', 'payment', 'board'),
    ]),
]));

$registry->register(new ViewDefinition('administration', 'Administration', [
    new PageDefinition('administration', 'Administration', [
        SectionDefinition::projection('Users', 'user', 'board'),
        SectionDefinition::projection('Hotels', 'hotel', 'board'),
        SectionDefinition::projection('Room Types', 'roomtype', 'board'),
    ]),
]));

return $registry;
