<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Category;
use App\Entity\ContentEntry;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ContentEntry> */
final class ContentEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Inhaltstyp',
                'choices' => [
                    'News-Beitrag' => ContentEntry::TYPE_NEWS,
                    'Seite' => ContentEntry::TYPE_PAGE,
                ],
            ])
            ->add('title', null, ['label' => 'Titel'])
            ->add('category', EntityType::class, [
                'label' => 'Kategorie',
                'class' => Category::class,
                'choice_label' => 'name',
                'placeholder' => 'Keine Kategorie',
                'required' => false,
            ])
            ->add('excerpt', TextareaType::class, [
                'label' => 'Kurztext',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 500],
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Inhalt',
                'attr' => ['rows' => 18],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Entwurf' => ContentEntry::STATUS_DRAFT,
                    'Zur Freigabe' => ContentEntry::STATUS_REVIEW,
                    'Geplant' => ContentEntry::STATUS_SCHEDULED,
                    'Veröffentlicht' => ContentEntry::STATUS_PUBLISHED,
                    'Archiviert' => ContentEntry::STATUS_ARCHIVED,
                ],
            ])
            ->add('scheduledAt', DateTimeType::class, [
                'label' => 'Geplanter Veröffentlichungszeitpunkt',
                'required' => false,
                'widget' => 'single_text',
                'help' => 'Nur beim Status „Geplant“ erforderlich.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ContentEntry::class]);
    }
}
