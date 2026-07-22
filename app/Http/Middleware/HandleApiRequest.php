<?php

namespace App\Http\Middleware;

use App\Enums\ClientApiLogResult;
use App\Events\ClientApiEvent;
use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-TOKEN');

        if (! $token || ! str_contains($token, ':')) {
            return response()->json(['error' => 'X-TOKEN header missing or malformed'], 401);
        }

        [$clientId, $clientSecret] = explode(':', $token, 2);

        $client = Client::where('client_id', $clientId)->where('status', 1)->first();

        if (! $client || ! hash_equals($client->client_secret, $clientSecret)) {
            $this->logEvent($request, $client, ['error' => 'Invalid client credentials']);

            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        $request->merge(['clientDbId' => $client->id]);
        $request->attributes->set('client', $client);

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function logEvent(Request $request, ?Client $client, array $responseData): void
    {
        ClientApiEvent::dispatch(
            $client?->id,
            $request->route()?->getName() ?? $request->path(),
            ClientApiLogResult::ERROR,
            [],
            $responseData,
            $request->ip(),
        );
    }
}
