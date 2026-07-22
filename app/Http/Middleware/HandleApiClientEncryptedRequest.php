<?php

namespace App\Http\Middleware;

use App\Classes\Encryption;
use App\Enums\ClientApiLogResult;
use App\Events\ClientApiEvent;
use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleApiClientEncryptedRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Retrieve the signature provided by the client
        $clientId = $request->input('clientId');
        if (! $clientId) {
            return response()->json(['error' => 'Client ID missing'], 401);
        }

        $encryptedData = $request->input('data');
        if (! $encryptedData) {
            return response()->json(['error' => 'Data missing'], 401);
        }

        // 2. Fetch the shared secret key from db
        $client = Client::where('client_id', $clientId)->where('status', 1)->first();
        if (! $client) {
            return response()->json(['error' => 'Invalid Client'], 401);
        }

        $secretKey = $client->client_secret;

        try {
            $decryptedData = Encryption::decrypt((string) $encryptedData, $secretKey);
        } catch (\Throwable) {
            $this->logEvent($request, $client, ClientApiLogResult::ERROR, ['error' => 'Invalid Data']);

            return response()->json(['error' => 'Invalid Data'], 401);
        }

        $decryptedData = json_decode($decryptedData, true);

        if (! $decryptedData) {
            $this->logEvent($request, $client, ClientApiLogResult::ERROR, ['error' => 'Malformed Data']);

            return response()->json(['error' => 'Malformed Data'], 401);
        }

        $request->merge(['decryptedData' => $decryptedData, 'clientDbId' => $client->id]);

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @param  array<string, mixed>|null  $requestData
     */
    private function logEvent(Request $request, Client $client, ClientApiLogResult $result, array $responseData, ?array $requestData = null): void
    {
        ClientApiEvent::dispatch(
            $client->id,
            $request->route()?->getName() ?? $request->path(),
            $result,
            $requestData ?? [],
            $responseData,
            $request->ip(),
        );
    }
}
