<?php

declare(strict_types=1);

namespace App\Form\DownloadRelations;

use App\Entity\Download\DownloadPackage;
use App\Repository\Download\DownloadPackageRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<DownloadDependencyInput> */
final class DownloadDependencyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $sourcePackage = $options['source_package'];
        if (!$sourcePackage instanceof DownloadPackage) {
            throw new \InvalidArgumentException('A source download package is required.');
        }

        $builder
            ->add('targetPackage', EntityType::class, [
                'class' => DownloadPackage::class,
                'choice_label' => 'title',
                'label' => 'Zielpaket',
                'placeholder' => 'Paket auswählen',
                'query_builder' => static function (DownloadPackageRepository $repository) use ($sourcePackage): QueryBuilder {
                    $query = $repository->createQueryBuilder('package');
                    if ($sourcePackage->getId() !== null) {
                        $query->andWhere('package.id != :sourceId')->setParameter('sourceId', $sourcePackage->getId());
                    }

                    return $query->orderBy('package.title', 'ASC');
                },
            ])
            ->add('kind', ChoiceType::class, [
                'label' => 'Beziehung',
                'choices' => [
                    'Erforderlich' => 'requires',
                    'Optional' => 'optional',
                    'Konflikt' => 'conflicts',
                ],
            ])
            ->add('constraintExpression', TextType::class, [
                'label' => 'Versionsvorgabe',
                'required' => false,
                'empty_data' => null,
                'help' => 'Optionaler Versionshinweis, zum Beispiel >=1.2.0.',
                'attr' => ['maxlength' => 80],
            ])
            ->add('submit', SubmitType::class, ['label' => 'Abhängigkeit hinzufügen']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => DownloadDependencyInput::class,
                'csrf_protection' => true,
                'csrf_token_id' => 'download-dependency-create',
            ])
            ->setRequired('source_package')
            ->setAllowedTypes('source_package', DownloadPackage::class);
    }
}
