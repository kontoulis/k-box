<?php

namespace Tests\Feature;

use KBox\User;
use KBox\Option;
use Tests\TestCase;
use KBox\Capability;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use KBox\Notifications\UserCreatedNotification;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Tests the UserAdministrationController::create and store
*/
class UserCreationTest extends TestCase
{
    use DatabaseTransactions, WithoutMiddleware;

    private function validParams($params)
    {
        return array_merge([
            'email' => 'test@k-link.technology',
            'name' => 'Test User',
            'password' => '',
            'send_password' => '1',
            'capabilities' => Capability::$PARTNER,
        ], $params);
    }
    
    public function test_user_is_created()
    {
        Notification::fake();

        $this->withoutExceptionHandling();

        $user = factory(User::class)->create();

        $response = $this->actingAs($user)
                    ->from(route('administration.users.create'))
                    ->post(route('administration.users.store'), $this->validParams([]));
        
        $response->assertRedirect(route('administration.users.index'));

        $user_created = User::where('email', 'test@k-link.technology')->first();

        $this->assertNotNull($user_created);
        $this->assertEquals('test@k-link.technology', $user_created->email);
        $this->assertEquals('Test User', $user_created->name);
        $this->assertNotEmpty($user_created->password);
        $this->assertTrue($user_created->can_all_capabilities(['receive_share']));

        Notification::assertSentTo(
            [$user_created],
            UserCreatedNotification::class
        );
    }
    
    public function test_user_is_created_with_given_password()
    {
        Notification::fake();

        $user = factory(User::class)->create();

        $password = 'a-secure-password';

        $response = $this->actingAs($user)
                    ->from(route('administration.users.create'))
                    ->post(route('administration.users.store'), $this->validParams([
                        'password' => $password
                    ]));

        $response->assertRedirect(route('administration.users.index'));

        $user_created = User::where('email', 'test@k-link.technology')->first();

        $this->assertNotNull($user_created);
        $this->assertEquals('test@k-link.technology', $user_created->email);
        $this->assertEquals('Test User', $user_created->name);
        $this->assertTrue(Hash::check($password, $user_created->password), "Password not equals to the one specified");
        $this->assertTrue($user_created->can_all_capabilities(['receive_share']));

        Notification::assertSentTo(
            [$user_created],
            UserCreatedNotification::class
        );
    }

    public function test_user_is_created_with_custom_password_and_password_not_sent()
    {
        Notification::fake();
        
        $user = factory(User::class)->create();

        $password = 'a-secure-password';

        $response = $this->actingAs($user)
                    ->from(route('administration.users.create'))
                    ->post(route('administration.users.store'), $this->validParams([
                        'password' => $password,
                        'send_password' => false,
                    ]));

        $response->assertRedirect(route('administration.users.index'));

        $user_created = User::where('email', 'test@k-link.technology')->first();

        $this->assertNotNull($user_created);
        $this->assertEquals('test@k-link.technology', $user_created->email);
        $this->assertEquals('Test User', $user_created->name);
        $this->assertTrue(Hash::check($password, $user_created->password), "Password not equals to the one specified");
        $this->assertTrue($user_created->can_all_capabilities(['receive_share']));

        Notification::assertNotSentTo(
            [$user_created],
            UserCreatedNotification::class
        );
    }

    public function test_user_is_not_created_if_password_not_send_is_checked_and_password_is_autogenerated()
    {
        Notification::fake();

        $user = factory(User::class)->create();

        config(['mail.driver' => 'smtp']);

        Option::put('mail.host', 'value');
        Option::put('mail.port', 'value');
        Option::put('mail.from.address', 'value');
        Option::put('mail.from.name', 'value');

        $response = $this->actingAs($user)
                    ->from(route('administration.users.create'))
                    ->post(route('administration.users.store'), $this->validParams([
                        'password' => null,
                        'send_password' => false,
                    ]));

        $response->assertSessionHasErrors('send_password');
    }

    public function test_wrong_email_is_rejected()
    {
        $user = factory(User::class)->create();

        $response = $this->actingAs($user)
                    ->from('/administration/users/create')
                    ->post(route('administration.users.store'), $this->validParams([
                        'email' => 'testk-link.technology'
                    ]));

        $response->assertRedirect('/administration/users/create');
        $response->assertSessionHasErrors('email');
        $this->assertNull(User::where('email', 'test@k-link.technology')->first());
    }

    public function test_empty_user_name_is_rejected()
    {
        $user = factory(User::class)->create();

        $response = $this->actingAs($user)
                    ->from('/administration/users/create')
                    ->post(route('administration.users.store'), $this->validParams([
                        'name' => ''
                    ]));

        $response->assertRedirect('/administration/users/create');
        $response->assertSessionHasErrors('name');
        $this->assertNull(User::where('email', 'test@k-link.technology')->first());
    }

    public function test_empty_capability_is_rejected()
    {
        $user = factory(User::class)->create();

        $response = $this->actingAs($user)
                    ->from('/administration/users/create')
                    ->post(route('administration.users.store'), $this->validParams([
                        'capabilities' => []
                    ]));

        $response->assertRedirect('/administration/users/create');
        $response->assertSessionHasErrors('capabilities');
        $this->assertNull(User::where('email', 'test@k-link.technology')->first());
    }

    public function test_user_cannot_be_created_without_minimum_capabilities()
    {
        $user = factory(User::class)->create();

        $response = $this->actingAs($user)
                    ->from('/administration/users/create')
                    ->post(route('administration.users.store'), $this->validParams([
                        'capabilities' => [
                            Capability::MAKE_SEARCH,
                            Capability::UPLOAD_DOCUMENTS
                        ]
                    ]));

        $response->assertRedirect('/administration/users/create');
        $response->assertSessionHasErrors('capabilities');
        $this->assertNull(User::where('email', 'test@k-link.technology')->first());
    }
}
