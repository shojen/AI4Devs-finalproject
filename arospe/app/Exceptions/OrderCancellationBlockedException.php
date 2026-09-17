<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Thrown by `App\Actions\Orders\CancelOrder` as a direct throw -- never a
 * Gate check -- when an order's current state does not permit manual
 * cancellation (story 0050, PRD §3.2's "manual cancellation is blocked" in
 * Shipped/Delivered/PartiallyRefunded, with no confirmation path around
 * it).
 *
 * Renders as a 409, `App\Exceptions\RoleInUseException`'s own precedent --
 * deliberately not 403: the actor holds both `orders.edit` and
 * `orders.refund` and may cancel a different order this instant, so
 * nothing about this is an authorization failure. It is also deliberately
 * NOT the same exception as `OrderStatusRegressionRequiresConfirmationException`,
 * even though both render 409 -- that one is retryable (`confirmed: true`),
 * this one is terminal (`CancelOrder` takes no confirmation parameter at
 * all), and a caller must be able to tell the two apart.
 *
 * The message is a CONSTANT, resolved from `lang/{en,es}/orders.php`'s
 * `cancellation.blocked` key, never interpolated with the order's number
 * or either status -- the same rule `OrderNotEditableException` follows.
 */
class OrderCancellationBlockedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('orders.cancellation.blocked'));
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render(Request $request): SymfonyResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $this->getMessage()], SymfonyResponse::HTTP_CONFLICT);
        }

        return new Response($this->getMessage(), SymfonyResponse::HTTP_CONFLICT);
    }
}
