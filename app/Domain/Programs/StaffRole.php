<?php

namespace App\Domain\Programs;

use App\Domain\Shared\LabelledEnum;

enum StaffRole: string
{
    use LabelledEnum;

    case Director = 'director';
    case Clinician = 'clinician';
    case Supervisor = 'supervisor';
    case Coordinator = 'coordinator';
    case Other = 'other';
}
