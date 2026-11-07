<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Link;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class SettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'settings.title',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 60)],
            ])
            ->add('accent', ColorType::class, ['label' => 'settings.accent'])
            ->add('logo', FileType::class, [
                'label' => 'settings.logo',
                'required' => false,
                'help' => 'settings.logo_help',
                'constraints' => [new Assert\File(maxSize: '256k', mimeTypes: ['image/svg+xml', 'image/png', 'image/webp'])],
            ])
            ->add('removeLogo', CheckboxType::class, ['label' => 'settings.remove_logo', 'required' => false])
            ->add('codeLength', IntegerType::class, [
                'label' => 'settings.code_length',
                'constraints' => [new Assert\Range(min: 4, max: 16)],
            ])
            ->add('defaultRedirect', ChoiceType::class, [
                'label' => 'settings.default_redirect',
                'choices' => array_combine(array_map(static fn (int $t): string => 'link.redirect.'.$t, Link::REDIRECT_TYPES), Link::REDIRECT_TYPES),
            ])
            ->add('fetchTitle', CheckboxType::class, ['label' => 'settings.fetch_title', 'required' => false, 'help' => 'settings.fetch_title_help'])
            ->add('tracking', CheckboxType::class, ['label' => 'settings.tracking', 'required' => false, 'help' => 'settings.tracking_help'])
            ->add('trackCountry', CheckboxType::class, ['label' => 'settings.track_country', 'required' => false, 'help' => 'settings.track_country_help'])
            ->add('require2faAdmins', CheckboxType::class, ['label' => 'settings.require_2fa_admins', 'required' => false, 'help' => 'settings.require_2fa_admins_help'])
            ->add('agentAccess', CheckboxType::class, ['label' => 'settings.agent_access', 'required' => false, 'help' => 'settings.agent_access_help']);
    }
}
