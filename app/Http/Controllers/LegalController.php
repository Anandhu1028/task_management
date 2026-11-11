<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class LegalController extends Controller
{
    /**
     * Privacy Policy page explaining data processing, OAuth handling, and ChatGPT MCP integration.
     * GET /privacy-policy
     */
    public function privacyPolicy(): View
    {
        return view('legal.privacy-policy');
    }

    /**
     * Terms of Service page.
     * GET /terms
     */
    public function terms(): View
    {
        return view('legal.terms');
    }

    /**
     * Customer and technical support page.
     * GET /support
     */
    public function support(): View
    {
        return view('legal.support');
    }

    /**
     * Data Deletion Instructions and Account Termination.
     * GET /data-deletion
     */
    public function dataDeletion(): View
    {
        return view('legal.data-deletion');
    }
}
