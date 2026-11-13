<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_openapi_spec_documents_every_endpoint(): void
    {
        // Scramble only serves docs in "local" by default; open the gate for this test.
        Gate::define('viewApiDocs', fn (?User $user = null) => true);

        $paths = $this->getJson('/docs/api.json')
            ->assertOk()
            ->json('paths');

        foreach ([
            '/login',
            '/me',
            '/logout',
            '/requests',
            '/requests/{serviceRequest}',
            '/requests/{serviceRequest}/submit',
            '/requests/{serviceRequest}/decision',
            '/requests/{serviceRequest}/assign',
            '/requests/{serviceRequest}/status',
            '/requests/{serviceRequest}/comments',
            '/notifications',
        ] as $path) {
            $this->assertArrayHasKey($path, $paths, "Missing from the API docs: {$path}");
        }
    }
}
