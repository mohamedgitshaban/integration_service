<?php

namespace App\Enums;

enum MockTransferOutcome: string
{
    case Success = 'success';
    case PermanentFailure = 'permanent_failure';
    case TimeoutAfterSuccess = 'timeout_after_success';
    case TimeoutBeforeSuccess = 'timeout_before_success';
}
