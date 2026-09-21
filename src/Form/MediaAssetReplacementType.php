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
            'help' => 'Die zentrale Upload-Policy prüft Typ, Endung, Größe und Integrität passend zum bestehenden Zielmodul. Erst danach werden bekannte Verwendungen umgestellt.',
            'constraints' => [new File(maxSize: '500M')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
