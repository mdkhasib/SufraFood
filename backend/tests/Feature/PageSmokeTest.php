<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;

/** Opens every web page (GET, no parameters) and every list API as each main role: nothing may crash. */
class PageSmokeTest extends GroupOpsTestCase
{
    protected function pages(): array
    {
        $skip = ['logout', 'download/app', 'lang/{lang}', 'up', 'storage/{path}', 'sanctum/csrf-cookie'];

        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods()) && ! str_contains($r->uri(), '{') && ! in_array($r->uri(), $skip) && ! str_starts_with($r->uri(), '_') && ! str_starts_with($r->uri(), 'api/'))
            ->map(fn ($r) => '/'.ltrim($r->uri(), '/'))->unique()->values()->all();
    }

    public function test_every_page_opens_for_every_main_role(): void
    {
        $this->setting('attendance.test_mode', 1);
        $pages = $this->pages();
        $this->assertGreaterThan(40, count($pages));
        $bad = [];
        foreach (['SA-0001', 'E-1001', 'E-1002', 'E-1020', 'E-1010', 'E-1030', 'E-1040', 'E-1050', 'E-1070', 'E-2001', 'E-2004', 'E-2008', 'E-2011'] as $code) {
            $this->actingAs($this->u($code));
            foreach ($pages as $p) {
                $s = $this->get($p)->status();
                if ($s >= 500) {
                    $bad[] = "$code $p → $s";
                }
            }
        }
        $this->assertSame([], $bad, "Pages that crashed:\n".implode("\n", $bad));
    }

    public function test_every_list_api_answers_for_app_users(): void
    {
        $apis = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods()) && str_starts_with($r->uri(), 'api/') && ! str_contains($r->uri(), '{'))
            ->map(fn ($r) => '/'.$r->uri())->unique()->values()->all();
        $bad = [];
        foreach (['E-1001', 'E-2001', 'E-2004', 'E-2008', 'E-1031', 'E-1060', 'E-1040'] as $code) {
            $this->api($code);
            foreach ($apis as $p) {
                $s = $this->getJson($p)->status();
                if ($s >= 500) {
                    $bad[] = "$code $p → $s";
                }
            }
        }
        $this->assertSame([], $bad, "APIs that crashed:\n".implode("\n", $bad));
    }
}
