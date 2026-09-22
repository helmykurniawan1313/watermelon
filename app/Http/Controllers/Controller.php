<?php

namespace App\Http\Controllers;

use App\Models\Ledger;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

abstract class Controller
{
    /**
     * Abort with 403 unless the current user belongs to the given ledger.
     */
    protected function authorizeLedger(Ledger $ledger): void
    {
        if (!$ledger->users->contains(Auth::id())) {
            throw new HttpException(403, 'Unauthorized access.');
        }
    }
}