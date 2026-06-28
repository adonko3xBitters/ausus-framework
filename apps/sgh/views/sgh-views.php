<?php

declare(strict_types=1);

use Ausus\View\PageDefinition;
use Ausus\View\SectionDefinition;
use Ausus\View\ViewDefinition;
use Ausus\View\ViewRegistry;

// SGH (Hospital) views — pure presentation metadata over existing capabilities.
$registry = new ViewRegistry();

$registry->register(new ViewDefinition('dashboard', 'Dashboard', [
    new PageDefinition('dashboard', 'Dashboard', [
        SectionDefinition::projection('Appointments', 'appointment', 'board'),
        SectionDefinition::projection('Admissions', 'admission', 'board'),
        SectionDefinition::projection('Beds', 'bed', 'board'),
    ]),
]));

$registry->register(new ViewDefinition('patients', 'Patients', [
    new PageDefinition('patients', 'Patients', [
        SectionDefinition::projection('Patients Board', 'patient', 'board'),
        SectionDefinition::action('Register Patient', 'patient', 'create'),
        SectionDefinition::action('Book Appointment', 'appointment', 'create'),
    ]),
]));

$registry->register(new ViewDefinition('consultations', 'Consultations', [
    new PageDefinition('consultations', 'Consultations', [
        SectionDefinition::projection('Consultations Board', 'consultation', 'board'),
        SectionDefinition::action('Open Consultation', 'consultation', 'create'),
        SectionDefinition::action('Prescribe', 'prescription', 'create'),
    ]),
]));

$registry->register(new ViewDefinition('admissions', 'Admissions', [
    new PageDefinition('admissions', 'Admissions', [
        SectionDefinition::projection('Admissions Board', 'admission', 'board'),
        SectionDefinition::projection('Beds', 'bed', 'board'),
        SectionDefinition::action('Admit Patient', 'admission', 'create'),
    ]),
]));

$registry->register(new ViewDefinition('billing', 'Billing', [
    new PageDefinition('billing', 'Billing', [
        SectionDefinition::projection('Invoices', 'invoice', 'board'),
        SectionDefinition::projection('Payments', 'payment', 'board'),
        SectionDefinition::action('Register Payment', 'payment', 'register'),
    ]),
]));

$registry->register(new ViewDefinition('administration', 'Administration', [
    new PageDefinition('administration', 'Administration', [
        SectionDefinition::projection('Users', 'user', 'board'),
        SectionDefinition::projection('Departments', 'department', 'board'),
        SectionDefinition::projection('Doctors', 'doctor', 'board'),
    ]),
]));

return $registry;
