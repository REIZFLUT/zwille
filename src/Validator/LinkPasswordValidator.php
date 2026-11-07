<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class LinkPasswordValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof LinkPassword) {
            throw new UnexpectedTypeException($constraint, LinkPassword::class);
        }
        if (null === $value || '' === $value) {
            return;
        }

        $password = (string) $value;

        // Characters for the minimum (so a multi-byte password is not cut short), bytes for the
        // maximum (bcrypt hashes at most 72 bytes and truncates silently beyond that).
        if (mb_strlen($password) < LinkPassword::MIN_LENGTH) {
            $this->context->buildViolation($constraint->tooShortMessage)->addViolation();

            return;
        }
        if (\strlen($password) > LinkPassword::MAX_BYTES) {
            $this->context->buildViolation($constraint->tooLongMessage)->addViolation();
        }
    }
}
