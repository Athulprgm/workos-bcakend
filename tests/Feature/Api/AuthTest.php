<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validRegistration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ], $overrides);
    }

    public function test_guest_can_register_and_receives_a_working_token(): void
    {
        $response = $this->postJson('/api/auth/register', $this->validRegistration());

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']])
            ->assertJsonPath('data.user.email', 'ada@example.com');

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);

        // The stored password must be hashed, never plain text.
        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertNotSame('correct-horse-battery', $user->password);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));

        // The returned token must actually authenticate.
        $this->withToken($response->json('data.token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'ada@example.com');
    }

    public function test_registration_creates_a_developer_account(): void
    {
        $this->postJson('/api/auth/register', $this->validRegistration())
            ->assertCreated()
            ->assertJsonPath('data.user.role', 'developer');

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'role' => 'developer',
        ]);
    }

    public function test_registration_never_returns_the_password_hash(): void
    {
        $response = $this->postJson('/api/auth/register', $this->validRegistration());

        $this->assertStringNotContainsString('$2y$', $response->getContent());
        $this->assertArrayNotHasKey('password', $response->json('data.user'));
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/auth/register', $this->validRegistration())
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_missing_and_mismatched_fields(): void
    {
        $this->postJson('/api/auth/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->postJson('/api/auth/register', $this->validRegistration([
            'password_confirmation' => 'something-else',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $this->postJson('/api/auth/register', $this->validRegistration([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_registration_normalizes_email_case(): void
    {
        $this->postJson('/api/auth/register', $this->validRegistration([
            'email' => '  Ada@Example.COM  ',
        ]))->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $response->assertOk()->assertJsonStructure(['data' => ['user', 'token']]);

        $this->withToken($response->json('data.token'))
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_login_rejects_invalid_credentials_without_revealing_which_field_failed(): void
    {
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'correct-horse-battery']);

        $wrongPassword = $this->postJson('/api/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'not-the-password',
        ]);

        $unknownEmail = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'not-the-password',
        ]);

        $wrongPassword->assertStatus(422)->assertJsonValidationErrors('email');
        $unknownEmail->assertStatus(422)->assertJsonValidationErrors('email');

        // Identical message for both cases.
        $this->assertSame(
            $wrongPassword->json('errors.email'),
            $unknownEmail->json('errors.email'),
        );
    }

    public function test_login_is_rate_limited_per_email_and_ip(): void
    {
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'correct-horse-battery']);

        $payload = ['email' => 'ada@example.com', 'password' => 'wrong-password'];

        // The limit is 5 attempts per minute.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/login', $payload)->assertStatus(422);
        }

        $this->postJson('/api/auth/login', $payload)->assertStatus(429);
    }

    public function test_login_rate_limit_does_not_leak_to_another_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'correct-horse-battery']);

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'ada@example.com',
                'password' => 'wrong-password',
            ]);
        }

        // A different account from the same IP is unaffected.
        $this->postJson('/api/auth/login', [
            'email' => 'grace@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_rejects_an_invalid_token(): void
    {
        $this->withToken('1|totally-invalid-token')->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $token = $this->postJson('/api/auth/register', $this->validRegistration())
            ->json('data.token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Laravel memoizes the resolved guard user for the lifetime of the
        // application instance, so a second request within the same test would
        // still appear authenticated. Forgetting the guards simulates the fresh
        // application instance that a real HTTP request gets.
        $this->app['auth']->forgetGuards();

        // The revoked token can no longer authenticate.
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }

    public function test_logout_only_revokes_the_token_that_was_used(): void
    {
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'correct-horse-battery']);

        $first = $this->postJson('/api/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
        ])->json('data.token');

        $second = $this->postJson('/api/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
        ])->json('data.token');

        $this->withToken($first)->postJson('/api/auth/logout')->assertOk();

        // Without forgetting the guards, the memoized user from the logout
        // request would make the assertion below pass regardless of whether the
        // second token is still valid.
        $this->app['auth']->forgetGuards();

        // The other session stays valid.
        $this->withToken($second)->getJson('/api/auth/me')->assertOk();
    }

    public function test_rate_limiting_runs_before_authentication_on_protected_routes(): void
    {
        // The limiter has to execute even when the request is rejected with a
        // 401. Laravel's default middleware priority puts Authenticate ahead of
        // ThrottleRequests, which would short-circuit it entirely; bootstrap/
        // app.php swaps those two entries.
        $response = $this->getJson('/api/auth/me');

        $response->assertUnauthorized();
        $this->assertSame('120', $response->headers->get('X-RateLimit-Limit'));
    }

    public function test_api_routes_are_rate_limited_per_ip(): void
    {
        $limit = 120;

        for ($attempt = 0; $attempt < $limit; $attempt++) {
            $this->getJson('/api/auth/me')->assertUnauthorized();
        }

        $this->getJson('/api/auth/me')->assertStatus(429);
    }
}
