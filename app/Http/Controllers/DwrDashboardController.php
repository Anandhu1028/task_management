<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class DwrDashboardController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('dwr.index');
    }
}
