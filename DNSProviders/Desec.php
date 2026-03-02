<?php

namespace App\Vito\Plugins\Arifnd\VitoDnsDesec\DNSProviders;

use App\Models\DNSProvider as DNSProviderModel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\DNSProviders\AbstractDNSProvider;
use Throwable;

class Desec extends AbstractDNSProvider
{
    private const string API_BASE_URL = 'https://desec.io/api/v1/';

    public function __construct(DNSProviderModel $dnsProvider)
    {
        parent::__construct($dnsProvider);
    }

    public static function id(): string
    {
        return 'desec';
    }

    private function getClient(): PendingRequest
    {
        return Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Token '.$this->dnsProvider->credentials['token'],
        ])->baseUrl(self::API_BASE_URL);
    }

    public function validationRules(array $input): array
    {
        return [
            'token' => 'required|string',
        ];
    }

    public function credentialData(array $input): array
    {
        return [
            'token' => $input['token'],
        ];
    }

    public function connect(array $credentials): bool
    {
        try {
            // Use /zones endpoint to verify token works for both user-scoped and account-scoped tokens
            // This also verifies the token has Zone:Read permissions which we need
            $response = Http::withHeaders([
                'Authorization' => 'Token '.$credentials['token'],
                'Content-Type' => 'application/json',
            ])
                ->baseUrl(self::API_BASE_URL)
                ->get('domains/');

            if ($response->successful()) {
                return true;
            }

            Log::error('deSEC connection failed', ['response' => $response->json()]);

            return false;
        } catch (Throwable $e) {
            Log::error('deSEC connection exception', ['error' => $e]);

            return false;
        }
    }

    public function getDomains(): array
    {
        try {
            $response = $this->getClient()->post('domain/listAll', [
                'apikey' => $this->dnsProvider->credentials['apikey'],
                'secretapikey' => $this->dnsProvider->credentials['secretapikey'],
            ]);

            if (! $response->successful()) {
                Log::error('Failed to fetch deSEC domains', ['response' => $response->json()]);

                return [];
            }

            return collect($response->json('domains'))->map(function (array $zone) {
                return [
                    'id' => $zone['domain'],
                    'name' => $zone['domain'],
                    'status' => $zone['status'],
                    'created_on' => $zone['createDate'],
                    'modified_on' => $zone['expireDate'],
                ];
            })->toArray();
        } catch (Throwable $e) {
            Log::error('deSEC getDomains exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function getDomain(string $domainId): array
    {
        try {
            $response = $this->getClient()->post('domain/listAll', [
                'apikey' => $this->dnsProvider->credentials['apikey'],
                'secretapikey' => $this->dnsProvider->credentials['secretapikey'],
            ]);

            if (! $response->successful()) {
                Log::error('Failed to fetch deSEC domain', ['domainId' => $domainId, 'response' => $response->json()]);

                return [];
            }

            $zone = collect($response->json('domains'))->where('domain', $domainId)->first();

            return [
                'id' => $zone['domain'],
                'name' => $zone['domain'],
                'status' => $zone['status'],
                'created_on' => $zone['createDate'],
                'modified_on' => $zone['expireDate'],
            ];
        } catch (Throwable $e) {
            Log::error('deSEC getDomain exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function getRecords(string $domainId): array
    {
        try {
            $response = $this->getClient()->post("dns/retrieve/{$domainId}", [
                'apikey' => $this->dnsProvider->credentials['apikey'],
                'secretapikey' => $this->dnsProvider->credentials['secretapikey'],
            ]);

            if (! $response->successful() || $response->json('status') === 'ERROR') {
                Log::error('Failed to fetch deSEC DNS records', ['domainId' => $domainId, 'response' => $response->json()]);

                return [];
            }

            return collect($response->json('records'))->map(function (array $record) {
                return [
                    'id' => $record['id'],
                    'type' => $record['type'],
                    'name' => $record['name'],
                    'content' => $record['content'],
                    'ttl' => $record['ttl'],
                    'proxied' => false,
                    'created_on' => null,
                    'modified_on' => null,
                ];
            })->toArray();
        } catch (Throwable $e) {
            Log::error('deSEC getRecords exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function createRecord(string $domainId, array $input): array
    {
        try {
            $response = $this->getClient()->post("dns/create/{$domainId}", [
                'apikey' => $this->dnsProvider->credentials['apikey'],
                'secretapikey' => $this->dnsProvider->credentials['secretapikey'],
                'type' => $input['type'],
                'name' => $input['name'],
                'content' => $input['content'],
                'ttl' => $input['ttl'] ?? 600, // TODO: set minimum ttl to 600
            ]);

            if (! $response->successful()) {
                Log::error('Failed to create deSEC DNS record', ['domainId' => $domainId, 'input' => $input, 'response' => $response->json()]);
                throw ValidationException::withMessages(['record' => 'Failed to create DNS record: '.($response->json('errors')[0]['message'] ?? 'Unknown error')]);
            }

            $id = $response->json('id');

            return [
                'id' => $id,
                'type' => $input['type'],
                'name' => $input['name'],
                'content' => $input['content'],
                'ttl' => $input['ttl'],
                'proxied' => false,
                'created_on' => now(),
                'modified_on' => null,
            ];
        } catch (Throwable $e) {
            Log::error('deSEC createRecord exception', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages(['record' => 'Failed to create DNS record: '.$e->getMessage()]);
        }
    }

    public function updateRecord(string $domainId, string $recordId, array $input): array
    {
        try {
            $response = $this->getClient()->post("dns/edit/{$domainId}/{$recordId}", [
                'apikey' => $this->dnsProvider->credentials['apikey'],
                'secretapikey' => $this->dnsProvider->credentials['secretapikey'],
                'type' => $input['type'],
                'name' => $input['name'],
                'content' => $input['content'],
                'ttl' => $input['ttl'] ?? 600, // TODO: set minimum ttl to 600
            ]);

            if (! $response->successful()) {
                Log::error('Failed to update deSEC DNS record', ['domainId' => $domainId, 'recordId' => $recordId, 'input' => $input, 'response' => $response->json()]);
                throw ValidationException::withMessages(['record' => 'Failed to update DNS record: '.($response->json('errors')[0]['message'] ?? 'Unknown error')]);
            }

            return [
                'id' => $recordId,
                'type' => $input['type'],
                'name' => $input['name'],
                'content' => $input['content'],
                'ttl' => $input['ttl'],
                'proxied' => false,
                'created_on' => now(), // TODO: get real created data
                'modified_on' => now(),
            ];
        } catch (Throwable $e) {
            Log::error('deSEC updateRecord exception', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages(['record' => 'Failed to update DNS record: '.$e->getMessage()]);
        }
    }

    public function deleteRecord(string $domainId, string $recordId): bool
    {
        try {
            $response = $this->getClient()->post("dns/delete/{$domainId}/{$recordId}", [
                'apikey' => $this->dnsProvider->credentials['apikey'],
                'secretapikey' => $this->dnsProvider->credentials['secretapikey'],
            ]);

            if (! $response->successful()) {
                Log::error('Failed to delete deSEC DNS record', ['domainId' => $domainId, 'recordId' => $recordId, 'response' => $response->json()]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::error('deSEC deleteRecord exception', ['error' => $e->getMessage()]);

            return false;
        }
    }
}