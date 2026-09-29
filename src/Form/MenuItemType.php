<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ContentEntry;
use App\Entity\MenuItem;
use App\Repository\ContentEntryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/** @extends AbstractType<MenuItem> */
final class MenuItemType extends AbstractType
{
    private const MAX_URL_CHARACTERS = 500;
    private const MAX_URL_BYTES = 2000;

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
                'attr' => ['maxlength' => self::MAX_URL_CHARACTERS],
                'constraints' => [
                    new Length(
                        max: self::MAX_URL_CHARACTERS,
                        maxMessage: 'Die externe Adresse darf höchstens {{ limit }} Zeichen lang sein.',
                    ),
                ],
                'invalid_message' => 'Die externe Adresse ist zu lang oder enthält ungültige Daten.',
            ])
            ->add('position', null, ['label' => 'Reihenfolge'])
            ->add('enabled', CheckboxType::class, ['label' => 'Im Menü anzeigen', 'required' => false])
            ->add('openNewWindow', CheckboxType::class, ['label' => 'In neuem Fenster öffnen', 'required' => false]);

        $builder->get('url')->addModelTransformer(new CallbackTransformer(
            static fn (mixed $url): mixed => $url,
            static function (mixed $url): mixed {
                if ($url === null) {
                    return null;
                }
                if (!is_string($url)) {
                    throw new TransformationFailedException('Die externe Adresse muss Text sein.');
                }
                if (strlen($url) > self::MAX_URL_BYTES) {
                    throw new TransformationFailedException('Die externe Adresse überschreitet 2000 UTF-8-Bytes.');
                }
                if (str_contains($url, "\0") || !mb_check_encoding($url, 'UTF-8')) {
                    throw new TransformationFailedException('Die externe Adresse muss gültiges UTF-8 ohne NUL-Zeichen enthalten.');
                }
                if (mb_strlen($url, 'UTF-8') > self::MAX_URL_CHARACTERS) {
                    throw new TransformationFailedException('Die externe Adresse darf höchstens 500 Zeichen enthalten.');
                }

                return $url;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MenuItem::class]);
    }
}
