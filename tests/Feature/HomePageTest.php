<?php

declare(strict_types=1);

it('renders the SANAD home page', function () {
    $response = $this->get('/');

    $response->assertOk();
});

it('shows the SANAD branding and tagline', function () {
    $response = $this->get('/');

    $response->assertSee('سَنَد', escape: false);
    $response->assertSee('SANAD', escape: false);
    $response->assertSee('مساعدك الذكي الذي يفهم، يتذكّر وينفّذ.', escape: false);
});

it('shows a development notice and system status', function () {
    $response = $this->get('/');

    $response->assertSee('قيد التطوير', escape: false);
    $response->assertSee('حالة النظام', escape: false);
    $response->assertSee('PostgreSQL', escape: false);
    $response->assertSee('Redis', escape: false);
});

it('renders the page as Arabic RTL', function () {
    $response = $this->get('/');

    $response->assertSee('lang="ar"', escape: false);
    $response->assertSee('dir="rtl"', escape: false);
});
