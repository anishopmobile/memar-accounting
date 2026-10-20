<?php
/**
 * payment_models.php — Payment module domain models.
 *
 * Plain hydration value objects mapping database rows to typed objects.
 * No database access lives inside these classes.
 */
declare(strict_types=1);

final class PaymentForm
{
    public int $id = 0;
    public string $title = '';
    public string $slug = '';
    public ?string $description = null;
    /** @var 'fixed'|'custom'|'selectable' */
    public string $amountMode = 'fixed';
    public int $fixedAmount = 0;
    public int $minAmount = 0;
    public int $maxAmount = 0;
    public ?string $purpose = null;
    public bool $isActive = true;
    public ?string $expiresAt = null;
    public ?string $successMessage = null;
    public ?string $redirectUrl = null;
    public int $redirectDelaySeconds = 0;
    public bool $notifyCustomer = false;
    public bool $notifyAdmin = false;
    public string $theme = 'default';

    /** @var PaymentFormField[] */
    public array $fields = [];

    /** @var int[] */
    public array $amountOptions = [];

    public static function fromRow(array $row): self
    {
        $f = new self();
        $f->id = (int) $row['id'];
        $f->title = (string) $row['title'];
        $f->slug = (string) $row['slug'];
        $f->description = $row['description'] !== null ? (string) $row['description'] : null;
        $f->amountMode = (string) $row['amount_mode'];
        $f->fixedAmount = (int) $row['fixed_amount'];
        $f->minAmount = (int) $row['min_amount'];
        $f->maxAmount = (int) $row['max_amount'];
        $f->purpose = $row['purpose'] !== null ? (string) $row['purpose'] : null;
        $f->isActive = (bool) $row['is_active'];
        $f->expiresAt = $row['expires_at'] !== null ? (string) $row['expires_at'] : null;
        $f->successMessage = $row['success_message'] !== null ? (string) $row['success_message'] : null;
        $f->redirectUrl = $row['redirect_url'] !== null ? (string) $row['redirect_url'] : null;
        $f->redirectDelaySeconds = (int) $row['redirect_delay_seconds'];
        $f->notifyCustomer = (bool) $row['notify_customer'];
        $f->notifyAdmin = (bool) $row['notify_admin'];
        $f->theme = (string) $row['theme'];
        return $f;
    }

    public function isFixed(): bool
    {
        return $this->amountMode === 'fixed';
    }

    public function isCustom(): bool
    {
        return $this->amountMode === 'custom';
    }

    public function isSelectable(): bool
    {
        return $this->amountMode === 'selectable';
    }

    public function isExpired(\DateTimeInterface $now = null): bool
    {
        if ($this->expiresAt === null || $this->expiresAt === '') {
            return false;
        }
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
        try {
            $expires = new DateTimeImmutable($this->expiresAt, new DateTimeZone('Asia/Tehran'));
        } catch (\Exception $e) {
            return false;
        }
        return $now >= $expires;
    }

    /** Authoritative server amount for a fixed form. */
    public function authoritativeAmount(): int
    {
        return $this->fixedAmount;
    }
}

final class PaymentFormField
{
    public int $id = 0;
    public string $key = '';
    public string $label = '';
    /** @var 'name'|'mobile'|'email'|'description'|'text'|'textarea'|'number'|'select'|'radio'|'checkbox' */
    public string $type = 'text';
    public bool $required = false;
    /** @var string[] */
    public array $options = [];
    public ?string $placeholder = null;
    public ?string $defaultValue = null;
    /** @var array<string,mixed> */
    public array $validation = [];

    public static function fromRow(array $row): self
    {
        $f = new self();
        $f->id = (int) $row['id'];
        $f->key = (string) $row['field_key'];
        $f->label = (string) $row['label'];
        $f->type = (string) $row['type'];
        $f->required = (bool) $row['required'];
        $f->options = $row['options'] ? (array) json_decode((string) $row['options'], true) : [];
        $f->placeholder = $row['placeholder'] !== null ? (string) $row['placeholder'] : null;
        $f->defaultValue = $row['default_value'] !== null ? (string) $row['default_value'] : null;
        $f->validation = $row['validation'] !== null ? (array) json_decode((string) $row['validation'], true) : [];
        return $f;
    }
}

final class PaymentTransaction
{
    public int $id = 0;
    public int $formId = 0;
    public ?int $customerId = null;
    public string $number = '';
    public int $amount = 0;
    public string $currency = 'IRR';
    public string $status = 'created';
    public string $gateway = 'zarinpal';
    public ?string $authority = null;
    public ?string $refId = null;
    public ?string $gatewayResponse = null;
    public ?string $failureReason = null;
    public ?string $customerName = null;
    public ?string $customerMobile = null;
    public ?string $customerEmail = null;
    /** @var array<string,mixed> */
    public array $submittedData = [];
    public ?string $ipAddress = null;
    public ?string $userAgent = null;
    public ?string $createdAt = null;
    public ?string $callbackAt = null;
    public ?string $verifiedAt = null;

    public static function fromRow(array $row): self
    {
        $t = new self();
        $t->id = (int) $row['id'];
        $t->formId = (int) $row['form_id'];
        $t->customerId = $row['customer_id'] !== null ? (int) $row['customer_id'] : null;
        $t->number = (string) $row['transaction_number'];
        $t->amount = (int) $row['amount'];
        $t->currency = (string) $row['currency'];
        $t->status = (string) $row['status'];
        $t->gateway = (string) $row['gateway'];
        $t->authority = $row['authority'] !== null ? (string) $row['authority'] : null;
        $t->refId = $row['ref_id'] !== null ? (string) $row['ref_id'] : null;
        $t->gatewayResponse = $row['gateway_response'] !== null ? (string) $row['gateway_response'] : null;
        $t->failureReason = $row['failure_reason'] !== null ? (string) $row['failure_reason'] : null;
        $t->customerName = $row['customer_name'] !== null ? (string) $row['customer_name'] : null;
        $t->customerMobile = $row['customer_mobile'] !== null ? (string) $row['customer_mobile'] : null;
        $t->customerEmail = $row['customer_email'] !== null ? (string) $row['customer_email'] : null;
        $t->submittedData = $row['submitted_data'] !== null ? (array) json_decode((string) $row['submitted_data'], true) : [];
        $t->ipAddress = $row['ip_address'] !== null ? (string) $row['ip_address'] : null;
        $t->userAgent = $row['user_agent'] !== null ? (string) $row['user_agent'] : null;
        $t->createdAt = $row['created_at'] !== null ? (string) $row['created_at'] : null;
        $t->callbackAt = $row['callback_at'] !== null ? (string) $row['callback_at'] : null;
        $t->verifiedAt = $row['verified_at'] !== null ? (string) $row['verified_at'] : null;
        return $t;
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['paid', 'failed', 'cancelled', 'expired'], true);
    }
}