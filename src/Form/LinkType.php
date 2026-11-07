<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Domain;
use App\Entity\Link;
use App\Entity\Tag;
use App\Link\LinkManager;
use App\Link\LinkPassword;
use App\Validator\LinkPassword as LinkPasswordRule;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @extends AbstractType<Link>
 */
final class LinkType extends AbstractType
{
    public function __construct(
        private readonly LinkManager $manager,
        private readonly LinkPassword $passwords,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('targetUrl', UrlType::class, [
                'label' => 'link.target_url',
                'default_protocol' => 'https',
                'attr' => ['placeholder' => 'https://', 'autofocus' => $options['autofocus']],
            ])
            ->add('domain', EntityType::class, [
                'label' => 'link.domain',
                'class' => Domain::class,
                'choices' => $options['domains'],
                'choice_label' => static fn (Domain $domain): string => $domain->getDisplayName(),
                'placeholder' => false,
            ])
            ->add('code', TextType::class, [
                'label' => 'link.code',
                'required' => false,
                'empty_data' => '',
                'help' => 'link.code_help',
                'attr' => ['placeholder' => 'link.code_placeholder', 'pattern' => '[a-zA-Z0-9][a-zA-Z0-9_\-]{0,63}', 'autocapitalize' => 'off', 'spellcheck' => 'false'],
            ]);

        if ($options['full']) {
            $builder
                ->add('title', TextType::class, ['label' => 'link.title', 'required' => false])
                ->add('tags', EntityType::class, [
                    'label' => 'link.tags',
                    'class' => Tag::class,
                    'choices' => $options['tags'],
                    'choice_label' => 'name',
                    'required' => false,
                    'multiple' => true,
                    'help' => 'link.tags_help',
                ])
                ->add('notes', TextareaType::class, [
                    'label' => 'link.notes',
                    'required' => false,
                    'help' => 'link.notes_help',
                    'attr' => ['rows' => 3],
                ])
                ->add('redirectType', ChoiceType::class, [
                    'label' => 'link.redirect_type',
                    'choices' => [
                        'link.redirect.302' => 302,
                        'link.redirect.301' => 301,
                        'link.redirect.307' => 307,
                        'link.redirect.308' => 308,
                    ],
                    'help' => 'link.redirect_help',
                ])
                ->add('startsAt', DateTimeType::class, [
                    'label' => 'link.starts_at',
                    'required' => false,
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'with_seconds' => false,
                    'help' => 'link.starts_at_help',
                ])
                ->add('expiresAt', DateTimeType::class, [
                    'label' => 'link.expires_at',
                    'required' => false,
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'with_seconds' => false,
                ])
                ->add('maxClicks', IntegerType::class, [
                    'label' => 'link.max_clicks',
                    'required' => false,
                    'attr' => ['min' => 1],
                    'help' => 'link.max_clicks_help',
                ])
                ->add('fallbackUrl', UrlType::class, [
                    'label' => 'link.fallback_url',
                    'required' => false,
                    'default_protocol' => 'https',
                    'help' => 'link.fallback_url_help',
                ])
                ->add('active', CheckboxType::class, ['label' => 'link.active', 'required' => false])
                ->add('password', PasswordType::class, [
                    'label' => 'link.password',
                    'mapped' => false,
                    'required' => false,
                    'help' => 'link.password_help',
                    'constraints' => [new LinkPasswordRule()],
                    'attr' => ['autocomplete' => 'new-password', 'spellcheck' => 'false'],
                ])
                ->add('removePassword', CheckboxType::class, [
                    'label' => 'link.remove_password',
                    'mapped' => false,
                    'required' => false,
                ])
                ->add('localisations', CollectionType::class, [
                    'label' => 'link.localisations',
                    'entry_type' => LinkLocalisationType::class,
                    'entry_options' => ['label' => false],
                    'allow_add' => true,
                    'allow_delete' => true,
                    'by_reference' => false,
                    'prototype' => true,
                    'help' => 'link.localisations_help',
                ])
                ->add('deviceTargets', CollectionType::class, [
                    'label' => 'device_targets.label',
                    'entry_type' => LinkDeviceTargetType::class,
                    'entry_options' => ['label' => false],
                    'allow_add' => true,
                    'allow_delete' => true,
                    'by_reference' => false,
                    'prototype' => true,
                    'help' => 'device_targets.help',
                ]);
        }

        // Apply the write-only password fields before validation: the "remove" checkbox clears
        // the hash, otherwise a non-empty value replaces it. An empty password field leaves the
        // existing protection untouched, so a link can be edited without losing its password.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $link = $event->getData();
            if (!$link instanceof Link || !$form->has('password')) {
                return;
            }
            if (true === $form->get('removePassword')->getData()) {
                $link->clearPassword();

                return;
            }
            $plain = $form->get('password')->getData();
            if (\is_string($plain) && '' !== $plain) {
                $link->setPasswordHash($this->passwords->hash($plain));
            }
        }, 20);

        // Fill in a random code before validation runs (ValidationListener has priority 0).
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $link = $event->getData();
            if ($link instanceof Link) {
                $this->manager->prepare($link);
            }
        }, 10);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Link::class,
            'full' => true,
            'autofocus' => false,
            // The current user's tags; the dashboard quick form passes none and shows no field.
            'tags' => [],
            // Cross-field check: a start in the future must precede the expiry.
            'constraints' => [new Callback([self::class, 'validateWindow'])],
        ]);
        $resolver->setRequired('domains');
        $resolver->setAllowedTypes('domains', 'array');
        $resolver->setAllowedTypes('tags', 'array');
        $resolver->setAllowedTypes('full', 'bool');
        $resolver->setAllowedTypes('autofocus', 'bool');
    }

    /**
     * Form-level check: with both a start and an expiry set, the start must come first.
     * maxClicks >= 1 and the http(s) fallback URL are enforced by constraints on Link itself.
     */
    public static function validateWindow(Link $link, ExecutionContextInterface $context): void
    {
        $startsAt = $link->getStartsAt();
        $expiresAt = $link->getExpiresAt();
        if (null !== $startsAt && null !== $expiresAt && $startsAt >= $expiresAt) {
            $context->buildViolation('link.window.invalid')->addViolation();
        }
    }
}
