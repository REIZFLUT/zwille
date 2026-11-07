<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ApiToken;
use App\Entity\Domain;
use App\Security\ApiScope;
use App\Validator\IpOrCidrValidator;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Issues an agent token: name, lifetime, scopes and the optional limits to domains, target
 * hosts and client addresses. The domain choices are the user's own assigned domains -
 * an agent token never acts as admin, so other domains would be useless to it.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class AgentTokenType extends AbstractType
{
    public const int MAX_LIST_ENTRIES = 50;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'api.token_name',
                'attr' => ['maxlength' => 100, 'placeholder' => 'agent.name_placeholder'],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
            ])
            ->add('days', ChoiceType::class, [
                'label' => 'api.token_expires',
                'choices' => array_combine(
                    array_map(static fn (int $days): string => 'agent.days_'.$days, ApiToken::AGENT_LIFETIMES),
                    ApiToken::AGENT_LIFETIMES,
                ),
                'constraints' => [new Assert\NotNull(), new Assert\Choice(choices: ApiToken::AGENT_LIFETIMES)],
            ])
            ->add('scopes', ChoiceType::class, [
                'label' => 'agent.scopes',
                'expanded' => true,
                'multiple' => true,
                'choices' => array_combine(array_map(static fn (string $scope): string => 'agent.scope.'.$scope, ApiScope::ALL), ApiScope::ALL),
                'constraints' => [new Assert\Count(min: 1, minMessage: 'agent.scopes_required')],
            ])
            ->add('domains', EntityType::class, [
                'label' => 'agent.domains',
                'help' => 'agent.domains_help',
                'class' => Domain::class,
                'choices' => $options['domains'],
                'choice_label' => static fn (Domain $domain): string => $domain->getHostname(),
                'expanded' => true,
                'multiple' => true,
                'required' => false,
            ])
            ->add('targetHosts', TextareaType::class, [
                'label' => 'agent.target_hosts',
                'help' => 'agent.target_hosts_help',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => "example.com\nshop.example.com"],
                'constraints' => [new Assert\Callback(static function (?string $value, ExecutionContextInterface $context): void {
                    $entries = self::lines($value);
                    if (\count($entries) > self::MAX_LIST_ENTRIES) {
                        $context->buildViolation('agent.too_many_entries')->setParameter('%max%', (string) self::MAX_LIST_ENTRIES)->addViolation();
                    }
                    foreach ($entries as $entry) {
                        if (!ApiToken::isValidHostPattern($entry)) {
                            $context->buildViolation('agent.invalid_host')->setParameter('%value%', $entry)->addViolation();
                        }
                    }
                })],
            ])
            ->add('allowedIps', TextareaType::class, [
                'label' => 'agent.allowed_ips',
                'help' => 'agent.allowed_ips_help',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => "203.0.113.7\n2001:db8::/48"],
                'constraints' => [new Assert\Callback(static function (?string $value, ExecutionContextInterface $context): void {
                    $entries = self::lines($value);
                    if (\count($entries) > self::MAX_LIST_ENTRIES) {
                        $context->buildViolation('agent.too_many_entries')->setParameter('%max%', (string) self::MAX_LIST_ENTRIES)->addViolation();
                    }
                    foreach ($entries as $entry) {
                        if (!IpOrCidrValidator::isValid($entry)) {
                            $context->buildViolation('agent.invalid_ip')->setParameter('%value%', $entry)->addViolation();
                        }
                    }
                })],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('domains');
        $resolver->setAllowedTypes('domains', 'array');
        $resolver->setDefault('csrf_token_id', 'agent-token-create');
    }

    /**
     * One entry per line (commas and semicolons separate too), blanks dropped. Spaces do not
     * separate: "not a host" must fail as a whole instead of turning into three hosts.
     *
     * @return list<string>
     */
    public static function lines(?string $value): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[\r\n,;]+/', (string) $value) ?: []),
            static fn (string $entry): bool => '' !== $entry,
        )));
    }
}
