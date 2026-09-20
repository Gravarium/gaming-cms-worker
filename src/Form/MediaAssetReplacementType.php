<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

/** @extends AbstractType<array<string, mixed>> */
final class MediaAssetReplacementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FileType::class, [
            'label' => 'Neue Datei',
            'help' => 'Die neue Datei wird zuerst vollständig geprüft und gespeichert. Erst danach werden bekannte Verwendungen umgestellt; die alte Datei bleibt als Rückfall erhalten.',
            'constraints' => [new File(maxSize: '100M')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
