<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\LinkDeviceTarget;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<LinkDeviceTarget>
 */
final class LinkDeviceTargetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('device', ChoiceType::class, [
                'label' => 'device_targets.device',
                'choices' => [
                    'device_targets.ios' => 'ios',
                    'device_targets.android' => 'android',
                    'device_targets.desktop' => 'desktop',
                ],
            ])
            ->add('targetUrl', UrlType::class, [
                'label' => 'device_targets.target_url',
                'default_protocol' => 'https',
                'attr' => ['placeholder' => 'https://'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LinkDeviceTarget::class]);
    }
}
