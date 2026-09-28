<?php

namespace App\Http\Controllers\Api\V1\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BalanceResource;
use App\Models\InstructorBalance;
use Illuminate\Http\Request;

class BalanceController extends Controller
{
    /**
     * What the instructor has earned, has in flight, has been paid, and is owed.
     */
    public function show(Request $request): BalanceResource
    {
        $balance = InstructorBalance::firstWhere('instructor_id', $request->user()->id)
            ?? new InstructorBalance(['instructor_id' => $request->user()->id]);

        return new BalanceResource($balance);
    }
}
