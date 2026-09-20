<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ContentEntry;
use App\Entity\MenuItem;
use App\Repository\ContentEntryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<MenuItem> */
final class MenuItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', null, ['label' => 'Beschriftung'])
            ->add('page', EntityType::class, [
                'label' => 'Veröffentlichte Seite',
                'class' => ContentEntry::class,
                'choice_label' => 'title',
                'required' => false,
                'placeholder' => 'Keine Seite ausgewählt',
                'query_builder' => static fn (ContentEntryRepository $repository) => $repository
                    ->createQueryBuilder('entry')
                    ->andWhere('entry.type = :type')
                    ->andWhere('entry.status = :status')
                    ->setParameter('type', ContentEntry::TYPE_PAGE)
                    ->setParameter('status', ContentEntry::STATUS_PUBLISHED)
                    ->orderBy('entry.title', 'ASC'),
            ])
            ->add('url', null, [
                'label' => 'Externe Adresse',
                'required' => false,
                'help' => 'Nur verwenden, wenn keine Seite ausgewählt ist.',
            ])
            ->add('position', null, ['label' => 'Reihenfolge'])
            ->add('enabled', CheckboxType::class, ['label' => 'Im Menü anzeigen', 'required' => false])
            ->add('openNewWindow', CheckboxType::class, ['label' => 'In neuem Fenster öffnen', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MenuItem::class]);
    }
}
