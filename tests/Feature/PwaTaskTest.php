<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaTaskTest extends TestCase
{
    public function test_guests_are_redirected_to_the_pwa_login(): void
    {
        $this->get(route('pwa.tasks.index'))->assertRedirect(route('pwa.login'));
    }
}
