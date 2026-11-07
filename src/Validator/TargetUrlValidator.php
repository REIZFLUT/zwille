<?php

declare(strict_types=1);

namespace App\Validator;

use App\Domain\HostRegistry;
use App\Security\CurrentApiToken;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class TargetUrlValidator extends ConstraintValidator
{
    public function __construct(
        private readonly HostRegistry $hosts,
        private readonly CurrentApiToken $apiToken,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TargetUrl) {
            throw new UnexpectedTypeException($constraint, TargetUrl::class);
        }
        if (null === $value || '' === $value) {
            return;
        }

        $url = (string) $value;
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (\strlen($url) > 2048 || !\in_array($scheme, ['http', 'https'], true) || '' === $host
            || false === filter_var($url, \FILTER_VALIDATE_URL) || preg_match('/\s/', $url)) {
            $this->context->buildViolation($constraint->invalidMessage)->addViolation();

            return;
        }

        if ($this->hosts->isOwnHost($host)) {
            $this->context->buildViolation($constraint->loopMessage)->addViolation();

            return;
        }

        if (false === $this->apiToken->get()?->allowsTargetHost($host)) {
            $this->context->buildViolation($constraint->notAllowedMessage)->setParameter('{{ host }}', $host)->addViolation();
        }
    }
}
