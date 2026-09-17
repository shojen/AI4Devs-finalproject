<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Thrown by `App\Actions\Orders\TransitionOrderStatus` when a caller asks to
 * move an order's status backward without passing `confirmed: true` (story
 * 0049, PRD §3.2's "moving an order's status backward requires explicit
 * confirmation" -- and its "it is not flatly forbidden" clause).
 *
 * Renders as a 409, not a 403 and not a 423 (D-2): the actor is
 * authenticated, authorized and recent -- nothing about this is a
 * credential-freshness problem (see
 * App\Exceptions\PasswordConfirmationRequiredException, which owns 423) or
 * an authorization failure (the actor may perform the transition, and will,
 * on the very next call). The request is simply in conflict with the
 * order's current state until the caller says they meant it. Follows
 * App\Exceptions\RoleInUseException exactly in shape.
 *
 * The message is a constant resolved from lang/{en,es}/orders.php's
 * `transitions.requires_confirmation` key, never interpolated with the
 * order's number or either status -- the message-is-a-constant rule from
 * docs/architecture/authorization.md.
 */
class OrderStatusRegressionRequiresConfirmationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('orders.transitions.requires_confirmation'));
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
