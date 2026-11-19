<?php

declare(strict_types=1);

namespace Liberu\Shipping\FedEx;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Liberu\Shipping\Contracts\Contracts\AddressValidationContract;
use Liberu\Shipping\Contracts\Contracts\RateQuotingContract;
use Liberu\Shipping\Contracts\Contracts\ShipmentCreationContract;
use Liberu\Shipping\Contracts\Contracts\ShippingProviderAdapterContract;
use Liberu\Shipping\Contracts\Contracts\TrackingContract;
use Liberu\Shipping\Contracts\DTOs\AddressDto;
use Liberu\Shipping\Contracts\DTOs\AddressValidationResultDto;
use Liberu\Shipping\Contracts\DTOs\CancelShipmentCommand;
use Liberu\Shipping\Contracts\DTOs\CancelShipmentResultDto;
use Liberu\Shipping\Contracts\DTOs\ConnectionHealthDto;
use Liberu\Shipping\Contracts\DTOs\CreateShipmentCommand;
use Liberu\Shipping\Contracts\DTOs\GetRatesCommand;
use Liberu\Shipping\Contracts\DTOs\GetTrackingCommand;
use Liberu\Shipping\Contracts\DTOs\MoneyAmount;
use Liberu\Shipping\Contracts\DTOs\ParcelDto;
use Liberu\Shipping\Contracts\DTOs\ShipmentResultDto;
use Liberu\Shipping\Contracts\DTOs\ShippingAccountContextDto;
use Liberu\Shipping\Contracts\DTOs\ShippingCapabilityDescriptorDto;
use Liberu\Shipping\Contracts\DTOs\ShippingRateDto;
use Liberu\Shipping\Contracts\DTOs\TrackingEventDto;
use Liberu\Shipping\Contracts\DTOs\TrackingResultDto;
use Liberu\Shipping\Contracts\DTOs\ValidateAddressCommand;
use Liberu\Shipping\Contracts\Enums\ShipmentLifecycleState;
use RuntimeException;

final readonly class FedExShippingAdapter implements AddressValidationContract, RateQuotingContract, ShipmentCreationContract, ShippingProviderAdapterContract, TrackingContract
{
    private const PROD_URL = 'https://apis.fedex.com';

    private const SANDBOX_URL = 'https://apis-sandbox.fedex.com';

    public function providerKey(): string
    {
        return 'fedex';
    }

    public function descriptor(): ShippingCapabilityDescriptorDto
    {
        return new ShippingCapabilityDescriptorDto(
            providerKey: 'fedex',
            displayName: 'FedEx',
            supportedCarriers: ['FedEx'],
            supportedCountries: ['US', 'CA', 'GB', 'EU', 'AU', 'JP'],
            isAggregator: false,
            supportsRateQuoting: true,
            supportsLabelPurchase: true,
            supportsTracking: true,
            supportsAddressValidation: true,
            supportsWebhooks: false,
            supportsPickupScheduling: true,
        );
    }

    public function testConnection(ShippingAccountContextDto $account): ConnectionHealthDto
    {
        $token = $this->resolveAccessToken($account);

        return new ConnectionHealthDto(
            providerKey: 'fedex',
            healthy: $token !== '',
            message: $token !== '' ? 'FedEx OAuth credentials verified.' : 'FedEx credentials missing or invalid.',
            details: ['sandbox' => $account->sandbox],
        );
    }

    public function getRates(GetRatesCommand $command): array
    {
        $token = $this->resolveAccessToken($command->account);
        if ($token === '') {
            return [];
        }

        $response = $this->client($command->account, $token)->post(
            $this->baseUrl($command->account).'/rate/v1/rates/quotes',
            [
                'accountNumber' => ['value' => (string) ($command->account->credentials['account_number'] ?? '')],
                'requestedShipment' => [
                    'shipper' => ['address' => $this->formatAddress($command->from)],
                    'recipient' => ['address' => $this->formatAddress($command->to)],
                    'pickupType' => 'DROPOFF_AT_FEDEX_LOCATION',
                    'rateRequestType' => ['ACCOUNT', 'LIST'],
                    'requestedPackageLineItems' => [$this->formatPackage($command->parcel)],
                ],
            ]
        );

        if (! $response->successful()) {
            return [];
        }

        $details = $response->json('output.rateReplyDetails', []);
        if (! is_array($details)) {
            return [];
        }

        $mapped = [];
        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }
            $rated = $detail['ratedShipmentDetails'][0] ?? null;
            if (! is_array($rated) || ! isset($rated['totalNetCharge'])) {
                continue;
            }
            $serviceType = (string) ($detail['serviceType'] ?? 'FEDEX_GROUND');
            $serviceName = (string) ($detail['serviceName'] ?? $serviceType);
            $currency = (string) ($rated['currency'] ?? 'USD');

            $mapped[] = new ShippingRateDto(
                providerKey: 'fedex',
                carrier: 'FedEx',
                service: $serviceName,
                amount: MoneyAmount::fromMajor($rated['totalNetCharge'], $currency),
                deliveryDays: isset($detail['commit']['transitDays']['minimumTransitTime'])
                    ? (int) $detail['commit']['transitDays']['minimumTransitTime']
                    : null,
                rateId: "fedex_{$serviceType}",
                estimatedDeliveryDate: isset($detail['commit']['dateDetail']['dayFormat'])
                    ? (string) $detail['commit']['dateDetail']['dayFormat']
                    : null,
            );
        }

        return $mapped;
    }

    public function createShipment(CreateShipmentCommand $command): ShipmentResultDto
    {
        $token = $this->resolveAccessToken($command->account);
        if ($token === '') {
            throw new RuntimeException('FedEx credentials missing.');
        }

        $serviceType = $command->service ?? 'FEDEX_GROUND';
        $response = $this->client($command->account, $token)->post(
            $this->baseUrl($command->account).'/ship/v1/shipments',
            [
                'labelResponseOptions' => 'URL_ONLY',
                'accountNumber' => ['value' => (string) ($command->account->credentials['account_number'] ?? '')],
                'requestedShipment' => [
                    'shipper' => [
                        'contact' => ['personName' => $command->from->name ?? 'Shipper', 'phoneNumber' => $command->from->phone ?? '0000000000'],
                        'address' => $this->formatAddress($command->from),
                    ],
                    'recipients' => [[
                        'contact' => ['personName' => $command->to->name ?? 'Recipient', 'phoneNumber' => $command->to->phone ?? '0000000000'],
                        'address' => $this->formatAddress($command->to),
                    ]],
                    'pickupType' => 'DROPOFF_AT_FEDEX_LOCATION',
                    'serviceType' => $serviceType,
                    'packagingType' => 'YOUR_PACKAGING',
                    'shippingChargesPayment' => ['paymentType' => 'SENDER'],
                    'labelSpecification' => ['imageType' => strtoupper($command->labelFormat)],
                    'requestedPackageLineItems' => [$this->formatPackage($command->parcel)],
                ],
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException('FedEx shipment creation failed: '.$response->status());
        }

        $tx = $response->json('output.transactionShipments.0', []);
        $piece = $tx['pieceResponses'][0] ?? [];
        $tracking = (string) ($tx['masterTrackingNumber'] ?? $piece['trackingNumber'] ?? '');
        $doc = $piece['packageDocuments'][0] ?? [];

        return new ShipmentResultDto(
            providerKey: 'fedex',
            providerShipmentId: $tracking !== '' ? $tracking : $command->idempotencyKey,
            state: ShipmentLifecycleState::LabelPurchased,
            carrier: 'FedEx',
            service: (string) ($tx['serviceName'] ?? $serviceType),
            trackingNumber: $tracking !== '' ? $tracking : null,
            labelUrl: isset($doc['url']) ? (string) $doc['url'] : null,
            labelFormat: strtoupper($command->labelFormat),
        );
    }

    public function cancelShipment(CancelShipmentCommand $command): CancelShipmentResultDto
    {
        $token = $this->resolveAccessToken($command->account);
        if ($token === '') {
            throw new RuntimeException('FedEx credentials missing.');
        }

        $response = $this->client($command->account, $token)->put(
            $this->baseUrl($command->account).'/ship/v1/shipments/cancel',
            [
                'accountNumber' => ['value' => (string) ($command->account->credentials['account_number'] ?? '')],
                'trackingNumber' => $command->providerShipmentId,
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException('FedEx shipment cancellation failed: '.$response->status());
        }

        $cancelled = (bool) ($response->json('output.cancelledShipment') ?? true);

        return new CancelShipmentResultDto(
            providerKey: 'fedex',
            providerShipmentId: $command->providerShipmentId,
            state: $cancelled ? ShipmentLifecycleState::Cancelled : ShipmentLifecycleState::Failed,
            refunded: $cancelled,
            message: (string) ($response->json('output.message') ?? 'FedEx shipment cancelled.'),
        );
    }

    public function getTracking(GetTrackingCommand $command): TrackingResultDto
    {
        $token = $this->resolveAccessToken($command->account);
        if ($token === '') {
            throw new RuntimeException('FedEx credentials missing.');
        }

        $response = $this->client($command->account, $token)->post(
            $this->baseUrl($command->account).'/track/v1/trackingnumbers',
            [
                'includeDetailedScans' => true,
                'trackingInfo' => [
                    ['trackingNumberInfo' => ['trackingNumber' => $command->trackingNumber]],
                ],
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException('FedEx tracking lookup failed: '.$response->status());
        }

        $track = $response->json('output.completeTrackResults.0.trackResults.0', []);
        $latestCode = (string) ($track['latestStatusDetail']['code'] ?? '');
        $events = [];
        foreach ((array) ($track['scanEvents'] ?? []) as $scan) {
            if (! is_array($scan)) {
                continue;
            }
            $loc = is_array($scan['scanLocation'] ?? null)
                ? trim(implode(', ', array_filter([
                    $scan['scanLocation']['city'] ?? null,
                    $scan['scanLocation']['stateOrProvinceCode'] ?? null,
                    $scan['scanLocation']['countryCode'] ?? null,
                ])))
                : null;
            $events[] = new TrackingEventDto(
                description: (string) ($scan['eventDescription'] ?? ''),
                state: $this->mapStatusCode((string) ($scan['eventType'] ?? '')),
                occurredAtIso8601: isset($scan['date']) ? (string) $scan['date'] : null,
                location: $loc !== '' ? $loc : null,
                rawStatus: isset($scan['eventType']) ? (string) $scan['eventType'] : null,
            );
        }

        return new TrackingResultDto(
            providerKey: 'fedex',
            trackingNumber: $command->trackingNumber,
            state: $this->mapStatusCode($latestCode),
            carrier: 'FedEx',
            events: $events,
        );
    }

    public function validateAddress(ValidateAddressCommand $command): AddressValidationResultDto
    {
        $token = $this->resolveAccessToken($command->account);
        if ($token === '') {
            return new AddressValidationResultDto('fedex', false, null, ['FedEx credentials missing.']);
        }

        $response = $this->client($command->account, $token)->post(
            $this->baseUrl($command->account).'/address/v1/addresses/resolve',
            [
                'addressesToValidate' => [
                    ['address' => $this->formatAddress($command->address)],
                ],
            ]
        );

        if (! $response->successful()) {
            return new AddressValidationResultDto('fedex', false, null, ['FedEx address validation failed.']);
        }

        $resolved = $response->json('output.resolvedAddresses.0', []);
        $valid = (bool) ($resolved['attributes']['Resolved'] ?? false);

        return new AddressValidationResultDto(
            providerKey: 'fedex',
            valid: $valid,
            normalizedAddress: AddressDto::fromArray([
                'street1' => $resolved['streetLinesToken'][0] ?? $command->address->street1,
                'city' => $resolved['city'] ?? $command->address->city,
                'state' => $resolved['stateOrProvinceCode'] ?? $command->address->state,
                'postal_code' => $resolved['postalCode'] ?? $command->address->postalCode,
                'country' => $resolved['countryCode'] ?? $command->address->country,
            ]),
        );
    }

    private function baseUrl(ShippingAccountContextDto $account): string
    {
        return $account->sandbox ? self::SANDBOX_URL : self::PROD_URL;
    }

    private function resolveAccessToken(ShippingAccountContextDto $account): string
    {
        $direct = (string) ($account->credentials['access_token'] ?? '');
        if ($direct !== '') {
            return $direct;
        }

        $clientId = (string) ($account->credentials['client_id'] ?? '');
        $clientSecret = (string) ($account->credentials['client_secret'] ?? '');
        if ($clientId === '' || $clientSecret === '') {
            return '';
        }

        $response = Http::asForm()
            ->timeout($account->timeoutSeconds)
            ->post($this->baseUrl($account).'/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        return $response->successful() ? (string) ($response->json('access_token') ?? '') : '';
    }

    private function client(ShippingAccountContextDto $account, string $token): PendingRequest
    {
        return Http::withToken($token)
            ->timeout($account->timeoutSeconds)
            ->acceptJson();
    }

    /** @return array<string, mixed> */
    private function formatAddress(AddressDto $address): array
    {
        return array_filter([
            'streetLines' => array_values(array_filter([$address->street1, $address->street2])),
            'city' => $address->city,
            'stateOrProvinceCode' => $address->state,
            'postalCode' => $address->postalCode,
            'countryCode' => $address->country,
        ], static fn ($v): bool => $v !== null && $v !== []);
    }

    /** @return array<string, mixed> */
    private function formatPackage(ParcelDto $parcel): array
    {
        $pkg = [
            'weight' => [
                'units' => 'LB',
                'value' => max(0.1, $parcel->weightInPounds()),
            ],
        ];
        if ($parcel->length !== null && $parcel->width !== null && $parcel->height !== null) {
            $pkg['dimensions'] = [
                'length' => (int) ceil($parcel->length),
                'width' => (int) ceil($parcel->width),
                'height' => (int) ceil($parcel->height),
                'units' => $parcel->dimensionUnit === 'cm' ? 'CM' : 'IN',
            ];
        }

        return $pkg;
    }

    private function mapStatusCode(string $code): ShipmentLifecycleState
    {
        return match (strtoupper($code)) {
            'OC' => ShipmentLifecycleState::PreTransit,
            'PU', 'IT', 'AR', 'DP' => ShipmentLifecycleState::InTransit,
            'OD' => ShipmentLifecycleState::OutForDelivery,
            'DL' => ShipmentLifecycleState::Delivered,
            'RS' => ShipmentLifecycleState::ReturnToSender,
            'DE', 'SE', 'CD' => ShipmentLifecycleState::Exception,
            'CA' => ShipmentLifecycleState::Cancelled,
            default => ShipmentLifecycleState::Unknown,
        };
    }
}
