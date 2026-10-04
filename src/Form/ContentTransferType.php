<?php

declare(strict_types=1);

namespace App\Form;

use App\ContentTransfer\ContentTransferUpload;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

/** @extends AbstractType<ContentTransferUpload> */
final class ContentTransferType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FileType::class, [
            'label' => 'CMS-Content-Bundle (JSON)',
            'required' => true,
            'help' => 'Nur JSON-Bundles bis 8 MB werden akzeptiert. Importierte Inhalte werden immer als Entwürfe angelegt.',
            'constraints' => [new File(
                maxSize: '8M',
                mimeTypes: ['application/json', 'text/json', 'text/plain', 'application/octet-stream'],
                mimeTypesMessage: 'Bitte wähle ein JSON-Content-Bundle.',
            )],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ContentTransferUpload::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'content-transfer-import',
            'method' => 'POST',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'content_transfer';
    }
}
