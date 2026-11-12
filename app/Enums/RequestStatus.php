<?php

namespace App\Enums;

enum RequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
}