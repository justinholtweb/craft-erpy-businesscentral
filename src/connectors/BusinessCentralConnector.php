<?php

namespace justinholtweb\erpybusinesscentral\connectors;

use Craft;
use DateTime;
use DateTimeInterface;
use justinholtweb\erpy\auth\OAuth2ClientCredentials;
use justinholtweb\erpy\base\AuthInterface;
use justinholtweb\erpy\base\Capabilities;
use justinholtweb\erpy\base\Connector;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\FetchCriteria;
use justinholtweb\erpy\base\Field;
use justinholtweb\erpy\base\HealthResult;
use justinholtweb\erpy\base\Page;
use justinholtweb\erpy\base\PushResult;
use justinholtweb\erpy\base\Transport;
use justinholtweb\erpy\models\canonical\ErpAddress;
use justinholtweb\erpy\models\canonical\ErpCredit;
use justinholtweb\erpy\models\canonical\ErpCustomer;
use justinholtweb\erpy\models\canonical\ErpInvoice;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\canonical\ErpOrderStatus;
use justinholtweb\erpy\models\canonical\ErpPrice;
use justinholtweb\erpy\models\canonical\ErpProduct;
use justinholtweb\erpy\models\canonical\ErpShipment;
use justinholtweb\erpy\models\canonical\ErpStock;

/**
 * Microsoft Dynamics 365 Business Central, through the standard API v2.0.
 *
 * Authentication is client credentials against Entra ID, which is the grant Microsoft now steers
 * integrations towards: no user sits in front of a nightly stock sync, so there is nobody to
 * bounce through a consent screen.
 *
 * Two things are worth knowing before setting this up. Business Central's standard API covers
 * items, customers, orders, invoices and financials but **not** sales prices or posted shipment
 * tracking numbers — those live on pages Microsoft has never surfaced. Both are therefore
 * optional endpoint settings here, and both say so plainly rather than silently returning
 * nothing.
 *
 * A posted shipment page is delta-synced on `lastModifiedDateTime` (the record's
 * `SystemModifiedAt`), not `postingDate`. Posting is when a shipment is born, but the tracking
 * number is routinely added afterwards with *Update Document*, which leaves the posting date alone
 * — a filter on it would never see the one change the sync exists for.
 *
 * Credit limit is on the standard `customers` page as `creditLimit`; `customerFinancialDetails`
 * carries the balance and overdue amount but no limit, which is why credit reads the customer and
 * expands its financial detail rather than the other way round. A limit of 0 is Business Central
 * for "no limit", not "no credit".
 *
 * There is no webhook support. Business Central's change notifications are API subscriptions
 * that open with a `validationToken` handshake and carry their secret as `clientState` in the
 * body, and Erpy's webhook endpoint answers neither — offering a webhook secret here would be a
 * setting that can never work.
 */
class BusinessCentralConnector extends Connector
{
    /** Resolved once per connection and reused for every request in a run. */
    private ?string $companyId = null;

    public static function handle(): string
    {
        return 'business-central';
    }

    public static function displayName(): string
    {
        return 'Dynamics 365 Business Central';
    }

    public static function vendor(): string
    {
        return 'Microsoft';
    }

    public static function description(): string
    {
        return 'Microsoft’s mid-market ERP, through the standard API v2.0 and an Entra ID app registration.';
    }

    public static function setupUrl(): ?string
    {
        return 'https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/administration/automation-apis-using-s2s-authentication';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            // Business Central's OData filters make a modified-since query cheap, which is what
            // makes a nightly sync of a large item master viable at all.
            ->supports(Entity::CUSTOMER, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::PRODUCT, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::PRICE, Direction::PULL, delta: false, pageSize: 100)
            ->supports(Entity::INVENTORY, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::ORDER, Direction::PUSH)
            ->supports(Entity::ORDER_STATUS, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::SHIPMENT, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::INVOICE, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::CREDIT, Direction::PULL, pageSize: 100)
            ->withMultiCompany()
            ->withSandbox();
    }

    public static function settingsFields(): array
    {
        return [
            Field::heading(
                Craft::t('erpy', 'Entra ID application'),
                Craft::t('erpy', 'Register an application in Entra ID, grant it the Business Central API permission, then grant the resulting application access inside Business Central itself under Microsoft Entra Applications.'),
            ),
            Field::text('tenantId', Craft::t('erpy', 'Tenant ID'), [
                'required' => true,
                'instructions' => Craft::t('erpy', 'The GUID of your Microsoft 365 tenant.'),
            ]),
            Field::text('clientId', Craft::t('erpy', 'Application (client) ID'), [
                'required' => true,
            ]),
            Field::secret('clientSecret', Craft::t('erpy', 'Client secret'), [
                'required' => true,
            ]),

            Field::heading(Craft::t('erpy', 'Environment')),
            Field::text('environment', Craft::t('erpy', 'Environment name'), [
                'required' => true,
                'default' => 'Production',
                'instructions' => Craft::t('erpy', 'As it appears in the Business Central admin centre — “Production”, “Sandbox”, or whatever yours is called.'),
            ]),
            Field::text('companyName', Craft::t('erpy', 'Company'), [
                'required' => true,
                'instructions' => Craft::t('erpy', 'The Business Central company to read and write. Leave the display name exactly as it appears in the company list.'),
            ]),

            Field::heading(
                Craft::t('erpy', 'Optional endpoints'),
                Craft::t('erpy', 'Business Central’s standard API does not expose sales prices or posted shipment tracking numbers. If you need either, publish a page as an API in your own extension and name it here; leave blank and Erpy will simply not sync that entity.'),
            ),
            Field::text('pricesEndpoint', Craft::t('erpy', 'Sales prices endpoint'), [
                'placeholder' => 'salesPrices',
                'instructions' => Craft::t('erpy', 'Relative to the company. Must expose itemNo, unitPrice, and optionally customerNo, customerPriceGroup, minimumQuantity, startingDate and endingDate.'),
            ]),
            Field::text('shipmentsEndpoint', Craft::t('erpy', 'Posted shipments endpoint'), [
                'placeholder' => 'salesShipments',
                'instructions' => Craft::t('erpy', 'Relative to the company. Must expose orderNo, no and lastModifiedDateTime (the record’s SystemModifiedAt, which delta syncs filter on), and optionally externalDocumentNo, packageTrackingNo, shippingAgentCode and postingDate.'),
            ]),
        ];
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new OAuth2ClientCredentials(
            tokenUrl: fn($connection) => sprintf(
                'https://login.microsoftonline.com/%s/oauth2/v2.0/token',
                $connection->getSetting('tenantId'),
            ),
            scope: 'https://api.businesscentral.dynamics.com/.default',
        );
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri(sprintf(
                'https://api.businesscentral.dynamics.com/v2.0/%s/%s/api/v2.0',
                $this->setting('tenantId'),
                rawurlencode((string)$this->setting('environment', 'Production')),
            ))
            ->setDefaultHeaders(['Accept' => 'application/json'])
            // Business Central throttles per environment and answers 429 with a Retry-After it
            // means; the transport honours it, so this is only a courtesy ceiling.
            ->setRateLimit(8)
            ->setTimeout(60);
    }

    protected function probe(): HealthResult
    {
        $response = $this->transport()->get('companies', ['$select' => 'id,name,systemVersion']);

        if (!$response->ok()) {
            return HealthResult::fail($response->errorMessage(), $this->hintsFor($response->status));
        }

        $companies = $response->at('value', []);
        $names = array_column($companies, 'name');
        $wanted = (string)$this->setting('companyName');

        if ($wanted !== '' && !in_array($wanted, $names, true)) {
            return HealthResult::fail(
                Craft::t('erpy', 'Signed in, but this environment has no company called “{name}”.', ['name' => $wanted]),
                [Craft::t('erpy', 'Companies here: {list}', ['list' => implode(', ', $names)])],
            );
        }

        return HealthResult::pass(
            Craft::t('erpy', 'Connected to Business Central.'),
            [
                Craft::t('erpy', 'Company') => $wanted ?: (string)($names[0] ?? '—'),
                Craft::t('erpy', 'Version') => (string)($companies[0]['systemVersion'] ?? '—'),
                Craft::t('erpy', 'Companies available') => (string)count($companies),
            ],
        );
    }

    private function hintsFor(int $status): array
    {
        return match (true) {
            $status === 401 => [Craft::t('erpy', 'Check the client secret has not expired, and that the application has been granted access inside Business Central under Microsoft Entra Applications — registering it in Entra ID alone is not enough.')],
            $status === 403 => [Craft::t('erpy', 'The application exists but has no permission set in Business Central. Give it D365 BUS FULL ACCESS, or a narrower set that covers the entities you are syncing.')],
            $status === 404 => [Craft::t('erpy', 'Check the environment name — it is case-sensitive and is the one from the admin centre, not the company name.')],
            default => [],
        };
    }

    // ---------------------------------------------------------------------------------------
    // Pull
    // ---------------------------------------------------------------------------------------

    protected function fetchProducts(FetchCriteria $criteria): Page
    {
        return $this->page('items', $criteria, Entity::PRODUCT, function(array $row): ErpProduct {
            return new ErpProduct([
                'sku' => (string)($row['number'] ?? ''),
                'name' => (string)($row['displayName'] ?? ''),
                'enabled' => empty($row['blocked']),
                'blocked' => !empty($row['blocked']),
                'category' => $row['itemCategoryCode'] ?? null,
                'unitOfMeasure' => $row['baseUnitOfMeasureCode'] ?? null,
                'price' => isset($row['unitPrice']) ? (float)$row['unitPrice'] : null,
                'cost' => isset($row['unitCost']) ? (float)$row['unitCost'] : null,
                'barcode' => $row['gtin'] ?: null,
                'taxCategory' => $row['taxGroupCode'] ?? null,
                // Business Central's `type` is Inventory, Service or Non-Inventory; only the
                // first has stock worth syncing.
                'tracksInventory' => ($row['type'] ?? 'Inventory') === 'Inventory',
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => (string)($row['number'] ?? ''),
                'modifiedAt' => $this->date($row['lastModifiedDateTime'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchInventory(FetchCriteria $criteria): Page
    {
        // The item record carries its own company-wide inventory figure, so stock costs no extra
        // request. Per-location stock would mean item ledger entries, which is a different and
        // far more expensive query — offered through the mapping screen's warehouse options only
        // when a merchant actually needs it.
        return $this->page('items', $criteria, Entity::INVENTORY, function(array $row): ErpStock {
            return new ErpStock([
                'sku' => (string)($row['number'] ?? ''),
                'onHand' => (float)($row['inventory'] ?? 0),
                'remoteId' => (string)($row['id'] ?? ''),
                'modifiedAt' => $this->date($row['lastModifiedDateTime'] ?? null),
                'raw' => $row,
            ]);
        }, select: 'id,number,inventory,lastModifiedDateTime,type');
    }

    protected function fetchCustomers(FetchCriteria $criteria): Page
    {
        return $this->page('customers', $criteria, Entity::CUSTOMER, function(array $row): ErpCustomer {
            $address = new ErpAddress([
                'type' => ErpAddress::TYPE_BILLING,
                'fullName' => (string)($row['displayName'] ?? ''),
                'addressLine1' => $row['addressLine1'] ?? null,
                'addressLine2' => $row['addressLine2'] ?? null,
                'locality' => $row['city'] ?? null,
                'administrativeArea' => $row['state'] ?? null,
                'postalCode' => $row['postalCode'] ?? null,
                'countryCode' => $row['country'] ?? null,
                'phone' => $row['phoneNumber'] ?? null,
                'isDefault' => true,
            ]);

            return new ErpCustomer([
                'code' => (string)($row['number'] ?? ''),
                'name' => (string)($row['displayName'] ?? ''),
                'email' => $row['email'] ?: null,
                'phone' => $row['phoneNumber'] ?: null,
                'website' => $row['website'] ?: null,
                // `blocked` is an enum here rather than a boolean: blank means not blocked, and
                // Ship, Invoice or All each mean a different degree of stop.
                'enabled' => in_array((string)($row['blocked'] ?? ''), ['', ' '], true),
                'onHold' => !in_array((string)($row['blocked'] ?? ''), ['', ' '], true),
                'taxId' => $row['taxRegistrationNumber'] ?: null,
                'taxExempt' => isset($row['taxLiable']) && $row['taxLiable'] === false,
                'currency' => $row['currencyCode'] ?: null,
                'paymentTermsCode' => $row['paymentTermsId'] ?: null,
                'shippingMethodCode' => $row['shipmentMethodId'] ?: null,
                'balance' => isset($row['balanceDue']) ? (float)$row['balanceDue'] : null,
                'creditLimit' => $this->creditLimit($row),
                'addresses' => $address->isEmpty() ? [] : [$address],
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => (string)($row['number'] ?? ''),
                'modifiedAt' => $this->date($row['lastModifiedDateTime'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchCredit(FetchCriteria $criteria): Page
    {
        // The limit lives on the customer and the overdue figure on its financial detail, so this
        // reads the one and expands the other. No delta: a payment moves the balance without
        // touching the customer's lastModifiedDateTime, so a modified-since read would miss it.
        return $this->page('customers', $criteria, Entity::CREDIT, function(array $row): ErpCredit {
            $financial = is_array($row['customerFinancialDetail'] ?? null) ? $row['customerFinancialDetail'] : [];
            $blocked = (string)($row['blocked'] ?? '');

            return new ErpCredit([
                'customerCode' => (string)($row['number'] ?? ''),
                'currency' => (string)(($row['currencyCode'] ?? '') ?: 'USD'),
                'creditLimit' => $this->creditLimit($row),
                'balance' => (float)($financial['balance'] ?? $row['balanceDue'] ?? 0),
                'overdueAmount' => (float)($financial['overdueAmount'] ?? 0),
                'onHold' => !in_array($blocked, ['', ' '], true),
                'remoteId' => (string)($row['id'] ?? ''),
                'raw' => $row,
            ]);
        }, deltaField: null, select: 'id,number,currencyCode,balanceDue,creditLimit,blocked', expand: 'customerFinancialDetail');
    }

    /**
     * Business Central's `creditLimit`, with its 0 read as what it means there: no limit set.
     * Treating it as a limit of zero would refuse every on-account order from every customer who
     * was never given one.
     */
    private function creditLimit(array $row): ?float
    {
        $limit = isset($row['creditLimit']) ? (float)$row['creditLimit'] : null;

        return $limit !== null && $limit > 0 ? $limit : null;
    }

    protected function fetchPrices(FetchCriteria $criteria): Page
    {
        $endpoint = trim((string)$this->setting('pricesEndpoint', ''));

        if ($endpoint === '') {
            $this->note(Craft::t('erpy', 'No sales prices endpoint is configured, so no prices were read. Business Central’s standard API does not expose sales prices; publishing a page as an API is the only way to reach them.'));

            return Page::empty();
        }

        return $this->page($endpoint, $criteria, Entity::PRICE, function(array $row): ErpPrice {
            return new ErpPrice([
                'sku' => (string)($row['itemNo'] ?? $row['itemNumber'] ?? ''),
                'customerCode' => $row['customerNo'] ?? $row['customerNumber'] ?? null,
                'customerGroupCode' => $row['customerPriceGroup'] ?? null,
                'priceListCode' => $row['priceListCode'] ?? null,
                'currency' => $row['currencyCode'] ?: null,
                'unitPrice' => (float)($row['unitPrice'] ?? 0),
                'minQuantity' => (float)($row['minimumQuantity'] ?? 1),
                'unitOfMeasure' => $row['unitOfMeasureCode'] ?? null,
                'priceIncludesTax' => (bool)($row['priceIncludesTax'] ?? false),
                'startsAt' => $this->date($row['startingDate'] ?? null),
                'endsAt' => $this->date($row['endingDate'] ?? null),
                'remoteId' => (string)($row['id'] ?? ''),
                'raw' => $row,
            ]);
        }, deltaField: null);
    }

    protected function fetchOrderStatuses(FetchCriteria $criteria): Page
    {
        return $this->page('salesOrders', $criteria, Entity::ORDER_STATUS, function(array $row): ErpOrderStatus {
            $status = (string)($row['status'] ?? '');

            return new ErpOrderStatus([
                // The Commerce order number went out as the external document number, which is
                // how a status finds its way home.
                'orderNumber' => (string)($row['externalDocumentNumber'] ?? ''),
                'status' => $status,
                'statusCode' => $status,
                'isShipped' => !empty($row['fullyShipped']),
                'isPartiallyShipped' => empty($row['fullyShipped']) && $status === 'Released',
                'isPicking' => $status === 'Released',
                'isOnHold' => in_array($status, ['Pending Approval', 'Pending Prepayment'], true),
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => (string)($row['number'] ?? ''),
                'modifiedAt' => $this->date($row['lastModifiedDateTime'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchShipments(FetchCriteria $criteria): Page
    {
        $endpoint = trim((string)$this->setting('shipmentsEndpoint', ''));

        if ($endpoint === '') {
            $this->note(Craft::t('erpy', 'No posted shipments endpoint is configured, so no tracking numbers were read. Business Central’s standard API does not expose posted sales shipments.'));

            return Page::empty();
        }

        return $this->page($endpoint, $criteria, Entity::SHIPMENT, function(array $row): ErpShipment {
            return new ErpShipment([
                'orderNumber' => (string)($row['externalDocumentNo'] ?? $row['orderNo'] ?? ''),
                'shipmentNumber' => (string)($row['no'] ?? $row['number'] ?? ''),
                'trackingNumber' => $row['packageTrackingNo'] ?? null,
                'carrier' => $row['shippingAgentCode'] ?? null,
                'service' => $row['shippingAgentServiceCode'] ?? null,
                'shippedAt' => $this->date($row['postingDate'] ?? null),
                'remoteId' => (string)($row['id'] ?? ''),
                'modifiedAt' => $this->date($row['lastModifiedDateTime'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchInvoices(FetchCriteria $criteria): Page
    {
        return $this->page('salesInvoices', $criteria, Entity::INVOICE, function(array $row): ErpInvoice {
            $total = (float)($row['totalAmountIncludingTax'] ?? 0);
            $remaining = isset($row['remainingAmount']) ? (float)$row['remainingAmount'] : null;

            return new ErpInvoice([
                'invoiceNumber' => (string)($row['number'] ?? ''),
                'orderNumber' => (string)($row['externalDocumentNumber'] ?? ''),
                'customerCode' => (string)($row['customerNumber'] ?? ''),
                'issuedAt' => $this->date($row['invoiceDate'] ?? $row['postingDate'] ?? null),
                'dueAt' => $this->date($row['dueDate'] ?? null),
                'currency' => (string)($row['currencyCode'] ?: 'USD'),
                'subtotal' => (float)($row['totalAmountExcludingTax'] ?? 0),
                'taxTotal' => (float)($row['totalTaxAmount'] ?? 0),
                'total' => $total,
                'balance' => $remaining ?? $total,
                'amountPaid' => $remaining !== null ? $total - $remaining : 0.0,
                'isPaid' => $remaining !== null && abs($remaining) < 0.005,
                'status' => (string)($row['status'] ?? ''),
                'remoteId' => (string)($row['id'] ?? ''),
                'modifiedAt' => $this->date($row['lastModifiedDateTime'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    // ---------------------------------------------------------------------------------------
    // Push
    // ---------------------------------------------------------------------------------------

    protected function pushOrder(ErpOrder $document, ?string $remoteId = null): PushResult
    {
        $company = $this->company();

        if ($company === null) {
            // A company that cannot be resolved is a wrong setting, not a bad afternoon. Marking
            // it retryable would have the queue resend the order until it gave up.
            return PushResult::rejected(Craft::t('erpy', 'Could not resolve the Business Central company “{name}”. Check the company name and the environment on this connection.', [
                'name' => (string)$this->setting('companyName'),
            ]));
        }

        // Business Central has no idempotency key, so the external document number does the job:
        // it is indexed, it is what the merchant will search for, and asking first is far cheaper
        // than explaining a duplicate order to a warehouse.
        $existing = $this->findOrderByExternalNumber($this->externalDocumentNumber($document->orderNumber));

        if ($existing !== null && $remoteId === null) {
            return PushResult::alreadyExists((string)($existing['id'] ?? ''), (string)($existing['number'] ?? ''));
        }

        if ($document->customerCode === null || $document->customerCode === '') {
            return PushResult::rejected(Craft::t('erpy', 'Business Central needs a customer number. Set a guest customer code on the order mapping, or link this customer to an ERP account.'));
        }

        $payload = array_filter([
            'customerNumber' => $document->customerCode,
            'externalDocumentNumber' => $this->externalDocumentNumber($document->orderNumber),
            'orderDate' => $document->orderedAt?->format('Y-m-d'),
            'currencyCode' => $document->currency,
            'billToName' => $document->billingAddress?->fullName,
            'email' => $document->email,
            'phoneNumber' => $document->phone,
            'pricesIncludeTax' => $document->pricesIncludeTax,
        ], static fn($value) => $value !== null && $value !== '');

        if ($document->shippingAddress && !$document->shippingAddress->isEmpty()) {
            $payload['shipToName'] = $document->shippingAddress->fullName;
            $payload['shippingPostalAddress'] = $this->postalAddress($document->shippingAddress);
        }

        if ($document->billingAddress && !$document->billingAddress->isEmpty()) {
            $payload['sellingPostalAddress'] = $this->postalAddress($document->billingAddress);
        }

        foreach ($document->customFields as $field => $value) {
            $payload[$field] = $value;
        }

        $response = $this->transport()->post("companies($company)/salesOrders", $payload);

        if (!$response->ok()) {
            return $this->rejectionOrFailure($response);
        }

        $orderId = (string)$response->at('id', '');

        if ($orderId === '') {
            return PushResult::failed(Craft::t('erpy', 'Business Central accepted the order but returned no id.'));
        }

        // Lines are separate requests. A line that fails leaves a header behind, so the failure
        // names the line rather than the order — the merchant has to go and finish it in BC, and
        // needs to know which one.
        foreach ($document->lines as $line) {
            $lineResponse = $this->transport()->post("companies($company)/salesOrders($orderId)/salesOrderLines", array_filter([
                // Shipping goes out as an item line too — that is what the mapping screen's
                // shipping SKU is for, and Business Central has no shipping field of its own on
                // a sales order header.
                'lineType' => 'Item',
                'lineObjectNumber' => $line->sku,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unitPrice' => $line->unitPrice,
                'discountAmount' => $line->discount ?: null,
                'shipmentDate' => $document->requestedDeliveryAt?->format('Y-m-d'),
            ], static fn($value) => $value !== null && $value !== ''));

            if (!$lineResponse->ok()) {
                return PushResult::rejected(Craft::t('erpy', 'The order header was created as {number}, but line {line} ({sku}) was refused: {error}', [
                    'number' => (string)$response->at('number', $orderId),
                    'line' => $line->lineNumber,
                    'sku' => $line->sku,
                    'error' => $lineResponse->errorMessage(),
                ]), ['salesOrderId' => $orderId]);
            }
        }

        return PushResult::ok($orderId, (string)$response->at('number', ''), $response->json_());
    }

    private function postalAddress(ErpAddress $address): array
    {
        return array_filter([
            'street' => trim($address->addressLine1 . "\n" . (string)$address->addressLine2),
            'city' => $address->locality,
            'state' => $address->administrativeArea,
            'countryLetterCode' => $address->countryCode,
            'postalCode' => $address->postalCode,
        ], static fn($value) => $value !== null && $value !== '');
    }

    /**
     * The value written to `externalDocumentNumber`, looked up by a retry and compared against
     * what comes back: the Commerce number, cut to the field's 35 characters.
     */
    private function externalDocumentNumber(string $orderNumber): string
    {
        return mb_substr($orderNumber, 0, 35);
    }

    /**
     * The sales order already carrying this external document number, or null. Every returned
     * row is compared as well as filtered for: an API page that ignores or mis-applies `$filter`
     * must not turn every order after the first into a duplicate of whatever it returned.
     */
    private function findOrderByExternalNumber(string $number): ?array
    {
        $company = $this->company();

        if ($company === null || $number === '') {
            return null;
        }

        $response = $this->transport()->get("companies($company)/salesOrders", [
            '$filter' => sprintf("externalDocumentNumber eq '%s'", $this->escape($number)),
            '$select' => 'id,number,externalDocumentNumber',
            '$top' => 20,
        ]);

        $rows = $response->ok() ? $response->at('value', []) : [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && (string)($row['externalDocumentNumber'] ?? '') === $number) {
                return $row;
            }
        }

        return null;
    }

    private function rejectionOrFailure($response): PushResult
    {
        // 4xx means Business Central understood us and said no; retrying an identical request
        // will get an identical no. 5xx and network failures are worth another go.
        return $response->status >= 400 && $response->status < 500
            ? PushResult::rejected($response->errorMessage(), $response->json_())
            : PushResult::failed($response->errorMessage(), $response->json_());
    }

    // ---------------------------------------------------------------------------------------
    // Plumbing
    // ---------------------------------------------------------------------------------------

    /**
     * One page of an OData collection, turned into canonical documents.
     */
    private function page(
        string $collection,
        FetchCriteria $criteria,
        string $entity,
        callable $make,
        ?string $deltaField = 'lastModifiedDateTime',
        ?string $select = null,
        ?string $expand = null,
    ): Page {
        $company = $this->company();

        if ($company === null) {
            return Page::empty();
        }

        // `@odata.nextLink` is an absolute URL Business Central has already built, filters and
        // all. Following it verbatim is both cheaper and more correct than rebuilding the query.
        if ($criteria->cursor !== null) {
            $response = $this->transport()->get($criteria->cursor);
        } else {
            $query = ['$top' => $this->pageSize($entity, $criteria)];

            if ($select !== null) {
                $query['$select'] = $select;
            }

            if ($expand !== null) {
                $query['$expand'] = $expand;
            }

            $filters = [];

            if ($deltaField !== null && $criteria->since instanceof DateTimeInterface) {
                $filters[] = sprintf('%s gt %s', $deltaField, $criteria->since->format('Y-m-d\TH:i:s\Z'));
            }

            if ($criteria->ids !== []) {
                $quoted = array_map(fn(string $id) => sprintf("id eq %s", $this->escape($id)), $criteria->ids);
                $filters[] = '(' . implode(' or ', $quoted) . ')';
            }

            foreach ($criteria->filters as $field => $value) {
                $filters[] = sprintf("%s eq '%s'", $field, $this->escape((string)$value));
            }

            if ($filters !== []) {
                $query['$filter'] = implode(' and ', $filters);
            }

            $response = $this->transport()->get("companies($company)/$collection", $query);
        }

        if (!$response->ok()) {
            throw new \RuntimeException(sprintf(
                'Business Central refused to read %s: %s',
                $collection,
                $response->errorMessage(),
            ));
        }

        $items = [];

        foreach ($response->at('value', []) as $row) {
            if (is_array($row)) {
                $items[] = $make($row);
            }
        }

        return new Page($items, $response->at('@odata.nextLink'));
    }

    /**
     * The company GUID, resolved from its display name once and reused.
     */
    private function company(): ?string
    {
        if ($this->companyId !== null) {
            return $this->companyId;
        }

        $name = (string)$this->setting('companyName', '');
        $response = $this->transport()->get('companies', ['$select' => 'id,name']);

        if (!$response->ok()) {
            return null;
        }

        foreach ($response->at('value', []) as $company) {
            if ($name === '' || ($company['name'] ?? null) === $name) {
                return $this->companyId = (string)$company['id'];
            }
        }

        return null;
    }

    /**
     * OData quotes strings with single quotes and escapes an embedded one by doubling it. Getting
     * this wrong on a customer called O'Brien is the kind of bug that only shows up in Ireland.
     */
    private function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function date(mixed $value): ?DateTime
    {
        if (!is_string($value) || trim($value) === '' || str_starts_with($value, '0001-01-01')) {
            return null;
        }

        try {
            return new DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
