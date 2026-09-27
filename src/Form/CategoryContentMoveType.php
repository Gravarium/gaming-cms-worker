<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Category;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<null> */
final class CategoryContentMoveType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Category $sourceCategory */
        $sourceCategory = $options['source_category'];

        $builder->add('targetCategory', EntityType::class, [
            'class' => Category::class,
            'choice_label' => 'displayName',
            'label' => 'Zielkategorie',
            'placeholder' => 'Zielkategorie auswählen',
            'query_builder' => static fn (EntityRepository $repository) => $repository
                ->createQueryBuilder('category')
                ->andWhere('category.id != :sourceId')
                ->setParameter('sourceId', $sourceCategory->getId())
                ->orderBy('category.name', 'ASC'),
            'mapped' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
        $resolver->setRequired('source_category');
        $resolver->setAllowedTypes('source_category', Category::class);
    }
}
