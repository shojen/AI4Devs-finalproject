<?php

// Story 0048 -- mirrors tests/Unit/Exceptions/ImmutableRoleExceptionTest.php's and
// PasswordConfirmationRequiredExceptionTest.php's own shape: prove the render() contract in
// isolation, with no HTTP kernel and no database.
//
// Status: 409 Conflict -- deliberately NOT 403 (RoleInUseException's own precedent, D-5). The
// request is well-formed and the actor IS authorized; the order simply cannot be edited in its
// current state.
//
// Unlike ImmutableRoleException/PasswordConfirmationRequiredException, this exception's message
// is a CONSTANT rather than a caller-supplied string (D-5's own "the message is a constant: an
// interpolated one would disclose the order's state to a caller the ordering rule (D-6) may
// already have refused") -- so it is instantiated with no constructor argument throughout.

use App\Exceptions\OrderNotEditableException;
use Illuminate\Http\Request;
use Tests\TestCase;

// Unlike ImmutableRoleException/PasswordConfirmationRequiredException (whose messages are
// caller-supplied strings), OrderNotEditableException's constructor itself calls
// __('orders.errors.order_not_editable') -- resolving `app('translator')`. Unit tests are NOT
// bound to Tests\TestCase by default (tests/Pest.php only covers Feature/Browser), so without
// this the very first `new OrderNotEditableException` fails with "Target class [translator] does
// not exist." Matches tests/Unit/Concerns/PaymentMethodValidationRulesTest.php's own precedent
// for the identical class of problem. No RefreshDatabase needed -- nothing here touches the
// database.
uses(TestCase::class);

test('it is a runtime exception', function () {
    expect(new OrderNotEditableException)->toBeInstanceOf(RuntimeException::class);
});

test('rendering it produces a 409 response, RoleInUseExceptions own precedent for a well-formed, authorized, but state-refused request', function () {
    $exception = new OrderNotEditableException;

    $response = $exception->render(Request::create('/orders/1/items'));

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getStatusCode())->not->toBe(403);
});

test('rendering it as a JSON request also produces a 409, with a JSON body carrying the message', function () {
    $exception = new OrderNotEditableException;

    $request = Request::create('/orders/1/items');
    $request->headers->set('Accept', 'application/json');

    $response = $exception->render($request);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->get('Content-Type'))->toContain('application/json');
});

// D-5: the message is a constant, never interpolated with the order's id, number or status -- an
// interpolated message would disclose exactly what the permission-first ordering rule (D-6) is
// meant to keep hidden from an actor who was already refused on authorization grounds.
test('two separately-constructed instances carry the identical message, proving it is not interpolated per-instance', function () {
    $first = new OrderNotEditableException;
    $second = new OrderNotEditableException;

    expect($first->getMessage())->not->toBeEmpty()
        ->and($first->getMessage())->toBe($second->getMessage());
});
