<?php

namespace App\Vito\Plugins\Arifnd\VitoDnsDesec\DNSProviders;

use App\DNSProviders\AbstractDNSProvider;
use App\Models\DNSProvider as DNSProviderModel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
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
            Log::error('deSEC connection exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function getDomains(): array
    {
        try {
            $response = $this->getClient()->get('domains/');

            if (! $response->successful()) {
                Log::error('Failed to fetch deSEC domains', ['response' => $response->json()]);

                return [];
            }

            return collect($response->json())->map(function (array $zone) {
                return [
                    'id' => $zone['name'],
                    'name' => $zone['name'],
                    'status' => 'ACTIVE',
                    'created_on' => $zone['created'],
                    'modified_on' => $zone['touched'],
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
            $response = $this->getClient()->get('domains/');

            if (! $response->successful()) {
                Log::error('Failed to fetch deSEC domain', ['domainId' => $domainId, 'response' => $response->json()]);

                return [];
            }

            $zone = collect($response->json())->where('name', $domainId)->first();

            return [
                'id' => $zone['name'],
                'name' => $zone['name'],
                'status' => 'ACTIVE',
                'created_on' => $zone['created'],
                'modified_on' => $zone['touched'],
            ];
        } catch (Throwable $e) {
            Log::error('deSEC getDomain exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function getRecords(string $domainId): array
    {
        try {
            $response = $this->getClient()->get("domains/{$domainId}/rrsets/");

            if (! $response->successful()) {
                Log::error('Failed to fetch deSEC DNS records', ['domainId' => $domainId, 'response' => $response->json()]);

                return [];
            }

            return collect($response->json())->map(function (array $record) {
                return [
                    'id' => $record['subname'],
                    'type' => $record['type'],
                    'name' => $record['subname'],
                    'content' => $record['records'][0],
                    'ttl' => $record['ttl'],
                    'proxied' => false,
                    'created_on' => $record['created'],
                    'modified_on' => $record['touched'],
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
            $response = $this->getClient()->post("domains/{$domainId}/rrsets/", [
                'type' => $input['type'],
                'subname' => $input['name'],
                'records' => [$input['content']],
                'ttl' => max($input['ttl'], 3600),
            ]);

            if (! $response->successful()) {
                Log::error('Failed to create deSEC DNS record', ['domainId' => $domainId, 'input' => $input, 'response' => $response->json()]);
                throw ValidationException::withMessages(['record' => 'Failed to create DNS record: '.($response->json('errors')[0]['message'] ?? 'Unknown error')]);
            }

            $id = $response->json('id');

            return [
                'id' => $subname,
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
            $response = $this->getClient()->put("domains/{$domainId}/rrsets/{$recordId}/{$input['type']}/", [
                'type' => $input['type'],
                'subname' => $input['name'],
                'records' => [$input['content']],
                'ttl' => max($input['ttl'], 3600),
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
                'created_on' => null,
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
