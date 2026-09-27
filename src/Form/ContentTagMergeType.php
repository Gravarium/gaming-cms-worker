<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ContentTag;
use App\Repository\ContentTagRepository;
use Doctrine\ORM\QueryBuilder;
use LogicException;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<array<string, mixed>> */
final class ContentTagMergeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ContentTag $sourceTag */
        $sourceTag = $options['source_tag'];
        $sourceTagId = $sourceTag->getId();
        if ($sourceTagId === null) {
            throw new LogicException('A persisted source tag is required.');
        }

        $builder->add('target', EntityType::class, [
            'class' => ContentTag::class,
            'choice_label' => 'name',
            'placeholder' => 'Ziel-Tag auswählen',
            'label' => 'Ziel-Tag',
            'required' => true,
            'mapped' => false,
            'query_builder' => static function (ContentTagRepository $repository) use ($sourceTagId): QueryBuilder {
                return $repository->createQueryBuilder('tag')
                    ->andWhere('tag.id != :sourceTagId')
                    ->setParameter('sourceTagId', $sourceTagId)
                    ->orderBy('tag.name', 'ASC');
            },
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
        ]);
        $resolver->setRequired('source_tag');
        $resolver->setAllowedTypes('source_tag', ContentTag::class);
    }
}
