<?php
/**
 * payment_form_repository.php — PaymentForm persistence layer.
 *
 * All SQL uses prepared statements. Dynamic fields are stored as structured
 * records (payment_form_fields), never as generated SQL columns. Amounts are
 * always integers in IRR (ریال).
 */
declare(strict_types=1);

final class PaymentFormRepository
{
    public function __construct(private \mysqli $db)
    {
    }

    public function find(int $id): ?PaymentForm
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_forms WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row === null ? null : $this->hydrate(PaymentForm::fromRow($row));
    }

    public function findBySlug(string $slug): ?PaymentForm
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_forms WHERE slug = ? LIMIT 1');
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row === null ? null : $this->hydrate(PaymentForm::fromRow($row));
    }

    /** Active, non-expired form for the public payment URL. */
    public function findActiveBySlug(string $slug): ?PaymentForm
    {
        $form = $this->findBySlug($slug);
        if ($form === null || !$form->isActive || $form->isExpired()) {
            return null;
        }
        return $form;
    }

    /** @return PaymentForm[] */
    public function all(): array
    {
        $forms = [];
        $res = $this->db->query('SELECT * FROM payment_forms ORDER BY id DESC');
        if (!$res) {
            return $forms;
        }
        while ($row = $res->fetch_assoc()) {
            $forms[] = $this->hydrate(PaymentForm::fromRow($row));
        }
        $res->free();
        return $forms;
    }

    public function count(): int
    {
        $res = $this->db->query('SELECT COUNT(*) AS c FROM payment_forms');
        $row = $res ? $res->fetch_assoc() : null;
        return $row ? (int) $row['c'] : 0;
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id FROM payment_forms WHERE slug = ?';
        $types = 's';
        $params = [$slug];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $types .= 'i';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->store_result();
        $found = $stmt->num_rows > 0;
        $stmt->close();
        return $found;
    }

    /**
     * Persist a form plus its dynamic fields and selectable amount options.
     *
     * @param PaymentFormField[] $fields
     * @param int[]              $amountOptions
     */
    public function save(PaymentForm $form, array $fields = [], array $amountOptions = []): int
    {
        $isActive = $form->isActive ? 1 : 0;
        $notifyCustomer = $form->notifyCustomer ? 1 : 0;
        $notifyAdmin = $form->notifyAdmin ? 1 : 0;

        if ($form->id > 0) {
            $stmt = $this->db->prepare(
                'UPDATE payment_forms SET
                    title = ?, slug = ?, description = ?, amount_mode = ?, fixed_amount = ?,
                    min_amount = ?, max_amount = ?, purpose = ?, is_active = ?, expires_at = ?,
                    success_message = ?, redirect_url = ?, redirect_delay_seconds = ?,
                    notify_customer = ?, notify_admin = ?, theme = ?
                 WHERE id = ?'
            );
            $stmt->bind_param(
                'ssssiiisissiiissi',
                $form->title,
                $form->slug,
                $form->description,
                $form->amountMode,
                $form->fixedAmount,
                $form->minAmount,
                $form->maxAmount,
                $form->purpose,
                $isActive,
                $form->expiresAt,
                $form->successMessage,
                $form->redirectUrl,
                $form->redirectDelaySeconds,
                $notifyCustomer,
                $notifyAdmin,
                $form->theme,
                $form->id
            );
            $stmt->execute();
            $stmt->close();
            $formId = $form->id;
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO payment_forms
                    (title, slug, description, amount_mode, fixed_amount, min_amount,
                     max_amount, purpose, is_active, expires_at, success_message,
                     redirect_url, redirect_delay_seconds, notify_customer, notify_admin, theme)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'ssssiiisissiiiss',
                $form->title,
                $form->slug,
                $form->description,
                $form->amountMode,
                $form->fixedAmount,
                $form->minAmount,
                $form->maxAmount,
                $form->purpose,
                $isActive,
                $form->expiresAt,
                $form->successMessage,
                $form->redirectUrl,
                $form->redirectDelaySeconds,
                $notifyCustomer,
                $notifyAdmin,
                $form->theme
            );
            $stmt->execute();
            $stmt->close();
            $formId = (int) $this->db->insert_id;
        }

        $this->replaceFields($formId, $fields);
        $this->replaceAmounts($formId, $amountOptions);

        return $formId;
    }

    /**
     * @param PaymentFormField[] $fields
     */
    private function replaceFields(int $formId, array $fields): void
    {
        $stmt = $this->db->prepare('DELETE FROM payment_form_fields WHERE form_id = ?');
        $stmt->bind_param('i', $formId);
        $stmt->execute();
        $stmt->close();

        $insert = $this->db->prepare(
            'INSERT INTO payment_form_fields
                (form_id, field_key, label, type, required, options, placeholder, default_value, validation, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $sort = 0;
        foreach ($fields as $f) {
            $options = $f->options !== [] ? json_encode($f->options, JSON_UNESCAPED_UNICODE) : null;
            $validation = $f->validation !== [] ? json_encode($f->validation, JSON_UNESCAPED_UNICODE) : null;
            $required = $f->required ? 1 : 0;
            $insert->bind_param(
                'isssissssi',
                $formId,
                $f->key,
                $f->label,
                $f->type,
                $required,
                $options,
                $f->placeholder,
                $f->defaultValue,
                $validation,
                $sort
            );
            $insert->execute();
            $sort++;
        }
        $insert->close();
    }

    /** @param int[] $amountOptions */
    private function replaceAmounts(int $formId, array $amountOptions): void
    {
        $stmt = $this->db->prepare('DELETE FROM payment_form_amounts WHERE form_id = ?');
        $stmt->bind_param('i', $formId);
        $stmt->execute();
        $stmt->close();

        // Deduplicate + numeric-validate at the persistence layer.
        $amounts = [];
        foreach ($amountOptions as $amount) {
            $amount = (int) $amount;
            if ($amount > 0) {
                $amounts[$amount] = true;
            }
        }
        if ($amounts === []) {
            return;
        }
        $insert = $this->db->prepare(
            'INSERT INTO payment_form_amounts (form_id, amount, label, sort_order) VALUES (?, ?, NULL, ?)'
        );
        $sort = 0;
        foreach (array_keys($amounts) as $amount) {
            $insert->bind_param('iii', $formId, $amount, $sort);
            $insert->execute();
            $sort++;
        }
        $insert->close();
    }

    /** @return PaymentFormField[] */
    public function fieldsFor(int $formId): array
    {
        $fields = [];
        $stmt = $this->db->prepare(
            'SELECT * FROM payment_form_fields WHERE form_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->bind_param('i', $formId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $fields[] = PaymentFormField::fromRow($row);
        }
        $stmt->close();
        return $fields;
    }

    /** @return int[] */
    public function amountOptionsFor(int $formId): array
    {
        $amounts = [];
        $stmt = $this->db->prepare(
            'SELECT amount FROM payment_form_amounts WHERE form_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->bind_param('i', $formId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $amounts[] = (int) $row['amount'];
        }
        $stmt->close();
        return $amounts;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM payment_forms WHERE id = ?');
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    private function hydrate(PaymentForm $form): PaymentForm
    {
        $form->fields = $this->fieldsFor($form->id);
        $form->amountOptions = $this->amountOptionsFor($form->id);
        return $form;
    }
}