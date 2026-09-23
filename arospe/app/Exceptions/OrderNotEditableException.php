<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Story 0048 (D-5) -- the hard block's refusal. Thrown as a direct `throw`
 * inside App\Actions\Orders\AddOrderItem / RemoveOrderItem /
 * UpdateOrderItemQuantity when the order's own `status` is Shipped or
 * Delivered -- deliberately never a Gate ability and never an
 * App\Policies\OrderPolicy method, because Gate::before's Super Admin
 * bypass would make a Gate-mediated check inert against exactly the actor
 * most likely to try (docs/security/authorization-patterns/ability-coverage-and-guards.md#a-rule-that-must-bind-a-super-admin-actor-must-be-a-direct-throw-not-a-gate-check).
 *
 * Renders as a 409 Conflict, RoleInUseException's own precedent -- not 403:
 * the request is well-formed and the actor IS authorized, the order simply
 * cannot be edited in its current state. This is also why D-6 requires the
 * permission check to run strictly BEFORE this guard: an actor who lacks
 * `orders.edit` must receive an AuthorizationException, never this
 * exception, or the order's existence and shipped/delivered state would be
 * disclosed to someone with no permission to read either.
 *
 * The message is a CONSTANT, never interpolated with the order's id,
 * number or status -- the same rule PasswordConfirmationRequiredException
 * established (story 0015a): an interpolated message here would leak
 * exactly what D-6's ordering rule is meant to keep hidden.
 */
class OrderNotEditableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('orders.errors.order_not_editable'));
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
