<?php

namespace App\Http\Controllers\API\V1;

use App\DTO\SubscriptionChargeRequestDTO;
use App\DTO\SubscriptionManageDTO;
use App\DTO\SubscriptionRequestDTO;
use App\Enums\ClientApiLogResult;
use App\Events\ClientApiEvent;
use App\Exceptions\SubscriptionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\ValidateSubscriptionChargeRequest;
use App\Http\Requests\V1\ValidateSubscriptionManageRequest;
use App\Http\Requests\V1\ValidateSubscriptionRequest;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    public function initiateSubscription(ValidateSubscriptionRequest $request): RedirectResponse|JsonResponse
    {
        try {
            $subscriptionRequestDto = SubscriptionRequestDTO::from($request->validated());
            $result = app(SubscriptionService::class)->initiateSubscription($subscriptionRequestDto);

            if (! $result['self_redirect']) {
                return response()->json([
                    'subscription_url' => $result['url'],
                    'payment_url' => $result['url'],
                    'status' => 'success',
                    'status_code' => 0,
                ]);
            }

            return redirect()->away($result['url']);
        } catch (\Throwable $e) {
            $this->logFailure($request, $e);

            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    private function logFailure(ValidateSubscriptionRequest $request, \Throwable $e): void
    {
        ClientApiEvent::dispatch(
            $request->input('clientDbId'),
            $request->route()?->getName() ?? $request->path(),
            ClientApiLogResult::ERROR,
            $request->input('decryptedData', []),
            ['error' => $e->getMessage()],
            $request->ip(),
        );
    }

    public function handleSubscriptionResponse(Request $request, string $pgClass): RedirectResponse|JsonResponse
    {
        try {
            $response = $request->all();
            Log::debug('pgClass: {pgClass} Subscription Response: {response}', ['pgClass' => $pgClass, 'response' => $response]);

            return app(SubscriptionService::class)->handleSubscriptionResponse($response, $pgClass);
        } catch (\Throwable $e) {
            Log::error('pgClass: {pgClass} Error handling received subscription response: {response} with {error}', [
                'pgClass' => $pgClass,
                'response' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function show(Request $request, string $referenceId): JsonResponse
    {
        try {
            $refresh = $request->boolean('refresh', false);
            $dto = app(SubscriptionService::class)->getSubscription(
                (string) $request->input('clientDbId'),
                $referenceId,
                $refresh
            );

            return response()->json($dto->toArray());
        } catch (SubscriptionException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $subscriptions = app(SubscriptionService::class)->listSubscriptions(
                (string) $request->input('clientDbId'),
                $request->query('status') !== null ? (string) $request->query('status') : null,
                (int) $request->query('per_page', 20)
            );

            return response()->json([
                'data' => $subscriptions->getCollection()->map(fn ($dto) => $dto->toArray())->all(),
                'meta' => [
                    'current_page' => $subscriptions->currentPage(),
                    'last_page' => $subscriptions->lastPage(),
                    'per_page' => $subscriptions->perPage(),
                    'total' => $subscriptions->total(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function manage(ValidateSubscriptionManageRequest $request, string $referenceId): JsonResponse
    {
        try {
            $manageDto = SubscriptionManageDTO::from($request->validated());
            $dto = app(SubscriptionService::class)->manageSubscription(
                (string) $request->input('clientDbId'),
                $referenceId,
                $manageDto
            );

            return response()->json($dto->toArray());
        } catch (SubscriptionException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function charge(ValidateSubscriptionChargeRequest $request, string $referenceId): JsonResponse
    {
        try {
            $clientDbId = (string) $request->input('clientDbId');
            $subscription = Subscription::where('client_id', $clientDbId)
                ->where('site_reference_id', $referenceId)
                ->first();

            if (! $subscription) {
                return response()->json(['error' => 'Subscription not found.'], 404);
            }

            $chargeDto = new SubscriptionChargeRequestDTO(
                site_reference_id: $referenceId,
                charge_reference_id: (string) $request->input('charge_reference_id'),
                amount: $request->input('amount'),
                currency: (string) $subscription->currency,
                remarks: $request->input('remarks')
            );

            $dto = app(SubscriptionService::class)->chargeSubscription(
                $clientDbId,
                $referenceId,
                $chargeDto
            );

            return response()->json($dto->toArray());
        } catch (SubscriptionException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function charges(Request $request, string $referenceId): JsonResponse
    {
        try {
            $charges = app(SubscriptionService::class)->listCharges(
                (string) $request->input('clientDbId'),
                $referenceId,
                (int) $request->query('per_page', 20)
            );

            return response()->json([
                'data' => $charges->getCollection()->map(fn ($dto) => $dto->toArray())->all(),
                'meta' => [
                    'current_page' => $charges->currentPage(),
                    'last_page' => $charges->lastPage(),
                    'per_page' => $charges->perPage(),
                    'total' => $charges->total(),
                ],
            ]);
        } catch (SubscriptionException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function chargeDetails(Request $request, string $referenceId, string $chargeReferenceId): JsonResponse
    {
        try {
            $refresh = $request->boolean('refresh', false);
            $dto = app(SubscriptionService::class)->getCharge(
                (string) $request->input('clientDbId'),
                $referenceId,
                $chargeReferenceId,
                $refresh
            );

            return response()->json($dto->toArray());
        } catch (SubscriptionException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
