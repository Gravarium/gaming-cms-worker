<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\SiteSettings;
use App\Internationalization\LocalePolicy;
use App\Theme\ThemeRegistry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Constraints\Length;

/** @extends AbstractType<SiteSettings> */
final class SiteSettingsType extends AbstractType
{
    public function __construct(private readonly ThemeRegistry $themes)
    {
    }
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('siteName', null, ['label' => 'Website-Name'])
            ->add('description', TextareaType::class, ['label' => 'Beschreibung', 'required' => false, 'attr' => ['rows' => 3]])
            ->add('homeTitle', null, [
                'label' => 'Überschrift der Startseite',
                'attr' => ['maxlength' => 180],
                'constraints' => [new Length(max: 180)],
            ])
            ->add('homeText', TextareaType::class, ['label' => 'Text der Startseite', 'attr' => ['rows' => 5]])
            ->add('primaryColor', ColorType::class, ['label' => 'Grundfarbe'])
            ->add('colorScheme', ChoiceType::class, [
                'label' => 'Farbdarstellung',
                'choices' => ['Dunkel' => 'dark', 'Hell' => 'light', 'Systemeinstellung' => 'system'],
            ])
            ->add('themeKey', ChoiceType::class, [
                'label' => 'Design-Paket',
                'choices' => $this->themes->choices(),
                'help' => 'Nur mit dieser CMS-Version kompatible Pakete werden angeboten.',
            ])
            ->add('defaultLocale', ChoiceType::class, [
                'label' => 'Standardsprache',
                'choices' => array_flip(LocalePolicy::SUPPORTED),
            ])
            ->add('enabledLocales', ChoiceType::class, [
                'label' => 'Aktive Sprachen',
                'choices' => array_flip(LocalePolicy::SUPPORTED),
                'multiple' => true,
                'expanded' => true,
                'help' => 'Die Standardsprache muss ebenfalls aktiviert sein.',
            ])
            ->add('logoFile', FileType::class, [
                'label' => 'Logo hochladen',
                'mapped' => false,
                'required' => false,
                'help' => 'Das Speicherziel des Branding-Moduls wird automatisch verwendet.',
                'constraints' => [new Image(maxSize: '3M', mimeTypes: ['image/png', 'image/jpeg', 'image/webp'])],
            ])
            ->add('logoUrl', UrlType::class, [
                'label' => 'Alternativ: vorhandene externe Logo-URL',
                'mapped' => false,
                'required' => false,
                'constraints' => [new Url(requireTld: true)],
            ])
            ->add('faviconFile', FileType::class, [
                'label' => 'Favicon hochladen',
                'mapped' => false,
                'required' => false,
                'help' => 'Das Speicherziel des Branding-Moduls wird automatisch verwendet.',
                'constraints' => [new File(maxSize: '1M', mimeTypes: ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'])],
            ])
            ->add('faviconUrl', UrlType::class, [
                'label' => 'Alternativ: vorhandene externe Favicon-URL',
                'mapped' => false,
                'required' => false,
                'constraints' => [new Url(requireTld: true)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SiteSettings::class]);
    }
}
